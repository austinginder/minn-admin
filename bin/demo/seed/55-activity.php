<?php
/**
 * Activity and security logs: the shop's last month as Simple History, WP
 * Activity Log, Activity Log (Aryo), Stream, All-In-One Security, Wordfence,
 * Limit Login Attempts Reloaded, Solid Security and Sucuri record it. Staff
 * sign in and publish the journal posts that exist, Mateo works the orders
 * and the stock, plugins and WordPress update, June makes Mateo shop manager,
 * Theo forgets his password, and three brute-force waves from documentation
 * IPs try admin and friends, the last one still locked out.
 *
 * One timeline is built first, then each plugin gets the part of it that it
 * would have recorded, in its own storage:
 * - Simple History through its loggers, with `_date`, `_initiator` and
 *   `_user_*` in the context (it honours all three). In WP-CLI it also stamps
 *   every event with the shell user and any AI agent it detects in the
 *   environment (`_cli_*`, `_initiator_ai_*`), so those keys are filtered out.
 * - WP Activity Log through Alert_Manager::log(). Its database logger drops
 *   the event's Timestamp before reading it, so the time arrives through the
 *   `wsal_database_timestamp_value` filter instead.
 * - Aryo's hist_time is a site-local epoch, Stream's created is GMT, AIOS and
 *   Wordfence store UTC epochs (Wordfence IPs as 16-byte binary), Solid keeps
 *   both local and GMT columns, LLA-R lives in limit_login_* options and
 *   Sucuri in uploads/sucuri/sucuri-auditqueue.php ("Level: user, ip; text").
 *
 * The plugins log the seed run itself as it happens ("now", from WP-CLI, as
 * user 0 or admin). This part empties their log tables first, and anything
 * logged later in the same run (later parts, 99-finish) is swept again at
 * shutdown: nothing seeded here is newer than the run's start, so every row
 * at or after it is the seed run. Nothing here locks out user 1 or the local
 * addresses, and no firewall, two-factor or hide-login setting is touched.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb, $wp_version;

	$now    = $demo->now;
	$latest = $now - 90;
	$oldest = $now - (int) ( 29.5 * DAY_IN_SECONDS ); // Stream keeps 30 days.
	$cut    = $now - 60;
	$offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	$server = '192.0.2.1'; // The web server itself, as cron and auto-updates see it.

	// The people, with where they usually sign in from.
	$agents = array(
		'mac'     => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
		'firefox' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
		'ipad'    => 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'chrome'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
		'iphone'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'old'     => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.150 Safari/537.36',
		'script'  => 'python-requests/2.31.0',
		'linux'   => 'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0',
	);
	$where = array(
		'june'  => array( '192.0.2.100', 'mac' ),
		'theo'  => array( '192.0.2.117', 'firefox' ),
		'priya' => array( '192.0.2.121', 'ipad' ),
		'mateo' => array( '192.0.2.100', 'chrome' ),
		'hana'  => array( '192.0.2.126', 'chrome' ),
	);
	$labels = array(
		'administrator' => 'Administrator',
		'editor'        => 'Editor',
		'author'        => 'Author',
		'contributor'   => 'Contributor',
		'shop_manager'  => 'Shop manager',
		'customer'      => 'Customer',
	);
	$people = array();
	foreach ( Minn_Demo_Seed::cast() as $p ) {
		$user = null === $p['role'] ? false : get_userdata( $demo->id( 'users', $p['slug'] ) );
		if ( ! $user ) {
			continue;
		}
		$at                    = isset( $where[ $p['slug'] ] ) ? $where[ $p['slug'] ] : array( '', 'mac' );
		$people[ $p['slug'] ] = array(
			'id'       => (int) $user->ID,
			'login'    => $user->user_login,
			'email'    => $user->user_email,
			'name'     => $user->display_name,
			'nicename' => $user->user_nicename,
			'first'    => $p['first'],
			'last'     => $p['last'],
			'role'     => $p['role'],
			'ip'       => $at[0],
			'ua'       => $agents[ $at[1] ],
		);
	}
	if ( ! isset( $people['june'], $people['theo'], $people['priya'], $people['mateo'], $people['hana'] ) ) {
		WP_CLI::warning( 'The staff accounts are missing: run the site part first.' );
		return;
	}
	$role_change = $demo->ago( 26, 10, 5 ); // June makes Mateo shop manager.
	$role_at     = function ( $slug, $ts ) use ( $people, $role_change ) {
		return 'mateo' === $slug && $ts < $role_change ? 'author' : $people[ $slug ]['role'];
	};

	// The timeline. Every event is a time, a type and who did it.
	$events = array();
	$add    = function ( $ts, $type, array $data = array() ) use ( &$events, $latest, $oldest ) {
		if ( $ts <= $latest && $ts >= $oldest ) {
			$events[] = array( 'ts' => (int) $ts, 'type' => $type ) + $data + array( 'who' => null, 'ip' => '', 'ua' => '' );
		}
	};
	$staff = function ( $slug, array $data = array() ) use ( $people ) {
		return array( 'who' => $slug, 'ip' => $people[ $slug ]['ip'], 'ua' => $people[ $slug ]['ua'] ) + $data;
	};

	// The journal: what was published, submitted, drafted and scheduled.
	$author_slug = array_flip( array_map( 'intval', $demo->ids( 'users' ) ) );
	foreach ( $demo->ids( 'posts' ) as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! isset( $author_slug[ (int) $post->post_author ] ) ) {
			continue;
		}
		$author = $author_slug[ (int) $post->post_author ];
		$posted = strtotime( $post->post_date . ' ' . wp_timezone_string() );
		$item   = array( 'post' => (int) $post->ID, 'title' => $post->post_title, 'post_type' => 'post' );
		if ( 'publish' === $post->post_status ) {
			if ( 'contributor' === $people[ $author ]['role'] ) {
				// A contributor submits, the editor publishes.
				$add( $posted - 18 * HOUR_IN_SECONDS + 7 * MINUTE_IN_SECONDS, 'post', $staff( $author, $item + array( 'from' => 'new', 'to' => 'pending' ) ) );
				$add( $posted, 'post', $staff( 'theo', $item + array( 'from' => 'pending', 'to' => 'publish' ) ) );
			} else {
				$add( $posted, 'post', $staff( $author, $item + array( 'from' => 'draft', 'to' => 'publish' ) ) );
			}
		} elseif ( 'pending' === $post->post_status ) {
			$add( $posted, 'post', $staff( $author, $item + array( 'from' => 'new', 'to' => 'pending' ) ) );
		} elseif ( 'draft' === $post->post_status ) {
			$add( $posted, 'post', $staff( $author, $item + array( 'from' => 'new', 'to' => 'draft' ) ) );
		} elseif ( 'future' === $post->post_status ) {
			$add( $demo->ago( 1, 16, 20 ), 'post', $staff( $author, $item + array( 'from' => 'draft', 'to' => 'future', 'when' => $post->post_date ) ) );
		}
	}
	$anthurium_post = get_post( $demo->id( 'posts', 'meet-the-anthurium-a-tropical-that-blooms-all-year' ) );
	if ( $anthurium_post ) {
		$add( $demo->ago( 17, 14, 5 ), 'post', $staff( 'theo', array( 'post' => (int) $anthurium_post->ID, 'title' => $anthurium_post->post_title, 'post_type' => 'post', 'from' => 'publish', 'to' => 'publish' ) ) );
	}
	$workshops_page = get_post( $demo->id( 'pages', 'workshops' ) );
	if ( $workshops_page ) {
		$add( $demo->ago( 5, 18, 47 ), 'post', $staff( 'priya', array( 'post' => (int) $workshops_page->ID, 'title' => $workshops_page->post_title, 'post_type' => 'page', 'from' => 'publish', 'to' => 'publish' ) ) );
	}

	// The shop: the card and PayPal gateways mark payments, Mateo ships and
	// completes orders a day and three hours after they come in (the time the
	// shop and mail parts use too), June refunds Grace's broken impatiens the
	// day after, and Mateo cancels Ethan's hibiscus, which had run out.
	$sold    = array(); // product id => [ [ ts, qty ] ] of orders that took stock.
	$home    = array(); // customer => the address of their latest order.
	foreach ( $demo->ids( 'orders' ) as $number => $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! $order->get_date_created() ) {
			continue;
		}
		$created = $order->get_date_created()->getTimestamp();
		$status  = $order->get_status();
		foreach ( $order->get_items() as $item ) {
			if ( in_array( $status, array( 'processing', 'completed', 'on-hold', 'refunded' ), true ) ) {
				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$sold[ $product_id ][] = array( $created, (int) $item->get_quantity() );
			}
		}
		$customer = '';
		foreach ( $people as $slug => $p ) {
			if ( $p['id'] === (int) $order->get_customer_id() ) {
				$customer = $slug;
			}
		}
		$o = array(
			'order'    => (int) $order->get_id(),
			'number'   => $order->get_order_number(),
			'title'    => '#' . $order->get_order_number() . ' ' . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer' => $customer,
			'total'    => (float) $order->get_total(),
			'created'  => $created,
		);
		$buyer = $customer ? $customer : null;
		if ( $customer && ( ! isset( $home[ $customer ] ) || $created > $home[ $customer ][1] ) ) {
			$home[ $customer ] = array( $order->get_customer_ip_address(), $created );
		}
		$add( $created, 'order_placed', $o + array( 'who' => $buyer, 'ip' => $order->get_customer_ip_address(), 'ua' => $agents['mac'] ) );
		if ( $customer && $created > $now - 2 * DAY_IN_SECONDS ) {
			// Recent buyers signed in at checkout.
			$add( $created - 3 * MINUTE_IN_SECONDS, 'login', array( 'who' => $customer, 'ip' => $order->get_customer_ip_address(), 'ua' => $agents['mac'] ) );
		}
		if ( in_array( $status, array( 'processing', 'completed', 'refunded' ), true ) ) {
			$add( $created + 60, 'order_status', $o + array( 'from' => 'pending', 'to' => 'processing' ) );
		}
		if ( 'failed' === $status ) {
			$add( $created + 95, 'order_status', $o + array( 'from' => 'pending', 'to' => 'failed' ) );
			// June notes the outage in Simple History (a custom entry, as its REST API adds them).
			$add(
				$created + 52 * MINUTE_IN_SECONDS,
				'note',
				$staff(
					'june',
					array(
						'level'   => 'error',
						'message' => 'Card payments failing at checkout',
						'note'    => 'The card gateway timed out for about twenty minutes this afternoon and one order failed. Emailed the customer to try PayPal; card payments are working again.',
					)
				)
			);
		}
		$completed = $created + DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS;
		if ( in_array( $status, array( 'completed', 'refunded' ), true ) ) {
			$add( $completed, 'order_status', $staff( 'mateo', $o + array( 'from' => 'processing', 'to' => 'completed' ) ) );
		}
		if ( 'refunded' === $status ) {
			$refund = $order->get_refunds();
			$refund = $refund ? reset( $refund ) : null;
			$at     = $completed + 26 * HOUR_IN_SECONDS + 20 * MINUTE_IN_SECONDS;
			$add( $at, 'refund', $staff( 'june', $o + array( 'amount' => $refund ? (float) $refund->get_amount() : $o['total'], 'reason' => $refund ? $refund->get_reason() : '', 'refund' => $refund ? $refund->get_id() : 0 ) ) );
			$add( $at + 2, 'order_status', $staff( 'june', $o + array( 'from' => 'completed', 'to' => 'refunded' ) ) );
		}
		if ( 'cancelled' === $status ) {
			$add( $created + 3 * HOUR_IN_SECONDS + 6 * MINUTE_IN_SECONDS, 'order_status', $staff( 'mateo', $o + array( 'from' => 'pending', 'to' => 'cancelled' ) ) );
		}
	}

	// Restocks: the new level is today's stock plus whatever sold since.
	$restocks = array(
		array( 'columbine', $demo->ago( 12, 8, 26 ), 15, 'chrome' ),
		array( 'anthurium', $demo->ago( 7, 8, 14 ), 6, 'iphone' ),
		array( 'peace-lily', $demo->ago( 5, 8, 19 ), 12, 'iphone' ),
	);
	foreach ( $restocks as $r ) {
		$product = wc_get_product( $demo->id( 'products', $r[0] ) );
		if ( ! $product || null === $product->get_stock_quantity() ) {
			continue;
		}
		$level = (int) $product->get_stock_quantity();
		foreach ( isset( $sold[ $product->get_id() ] ) ? $sold[ $product->get_id() ] : array() as $s ) {
			$level += $s[0] > $r[1] ? $s[1] : 0;
		}
		$via = 'iphone' === $r[3] ? array( 'ip' => '192.0.2.130', 'ua' => $agents['iphone'], 'app' => "Mateo's phone" ) : array();
		$add( $r[1], 'stock', $via + $staff( 'mateo', array( 'product' => $product->get_id(), 'old' => max( 0, $level - $r[2] ), 'new' => $level ) ) );
	}

	// People's own account events.
	$add( $role_change, 'role', $staff( 'june', array( 'target' => 'mateo', 'old' => 'author', 'new' => 'shop_manager' ) ) );
	$add( $demo->ago( 15, 8, 52 ), 'setting', $staff( 'june', array( 'label' => 'Low stock threshold', 'option' => 'woocommerce_notify_low_stock_amount' ) ) );
	$add( $demo->ago( 16, 7, 38 ), 'failed', $staff( 'theo', array( 'user' => 'theo', 'user_id' => $people['theo']['id'] ) ) );
	$add( $demo->ago( 16, 7, 38 ) + 41, 'failed', $staff( 'theo', array( 'user' => 'theo', 'user_id' => $people['theo']['id'] ) ) );
	$add( $demo->ago( 16, 7, 40 ), 'reset_request', $staff( 'theo' ) );
	$add( $demo->ago( 16, 7, 42 ), 'reset', $staff( 'theo' ) );
	$add( $demo->ago( 16, 7, 43 ), 'login', $staff( 'theo' ) );
	$shopper = function ( $slug ) use ( $home, $agents ) {
		return array( 'who' => $slug, 'ip' => isset( $home[ $slug ] ) ? $home[ $slug ][0] : '192.0.2.200', 'ua' => $agents['mac'] );
	};
	$add( $demo->ago( 5, 21, 15 ), 'reset_request', $shopper( 'daniel' ) );
	$add( $demo->ago( 5, 21, 17 ), 'reset', $shopper( 'daniel' ) );
	$add( $demo->ago( 5, 21, 18 ), 'login', $shopper( 'daniel' ) );

	// Updates: June updates by hand, the rest update themselves overnight.
	$plugins = get_plugins();
	$older   = function ( $version ) {
		$parts = explode( '.', $version );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			if ( ctype_digit( $parts[ $i ] ) && (int) $parts[ $i ] > 0 ) {
				$parts[ $i ] = (string) ( (int) $parts[ $i ] - 1 );
				return implode( '.', $parts );
			}
		}
		return $version;
	};
	$updates = array(
		array( 'woocommerce/woocommerce.php', $demo->ago( 29, 9, 5 ), 'june' ),
		array( 'wordpress-seo/wp-seo.php', $demo->ago( 12, 3, 14 ), null ),
		array( 'wordfence/wordfence.php', $demo->ago( 12, 3, 15 ), null ),
		array( 'the-events-calendar/the-events-calendar.php', $demo->ago( 8, 9, 12 ), 'june' ),
		array( 'updraftplus/updraftplus.php', $demo->ago( 4, 3, 20 ), null ),
	);
	foreach ( $updates as $u ) {
		if ( ! isset( $plugins[ $u[0] ] ) ) {
			continue;
		}
		$data = array(
			'file' => $u[0],
			'name' => $plugins[ $u[0] ]['Name'],
			'new'  => $plugins[ $u[0] ]['Version'],
			'old'  => $older( $plugins[ $u[0] ]['Version'] ),
			'url'  => $plugins[ $u[0] ]['PluginURI'],
		);
		$add( $u[1] - 21 * HOUR_IN_SECONDS - 13 * MINUTE_IN_SECONDS, 'update_available', $data + array( 'ip' => $server ) );
		$add( $u[1], 'plugin_update', $u[2] ? $staff( $u[2], $data ) : $data + array( 'ip' => $server ) );
	}
	$add( $demo->ago( 12, 3, 12 ), 'core_update', array( 'ip' => $server, 'old' => $older( $wp_version ), 'new' => $wp_version ) );

	// Brute force. Each wave is bursts of attempts; LLA-R locks an address
	// out after every fourth and Solid after a burst. The last wave came in
	// a few hours before the run and its second lockout lasts a day.
	$waves = array(
		array(
			'ip'      => '203.0.113.42',
			'ua'      => $agents['old'],
			'gateway' => 'wp_login',
			'bursts'  => array(
				array( $demo->ago( 19, 2, 14 ), array( 'admin', 'admin', 'admin', 'admin' ) ),
				array( $demo->ago( 19, 2, 35 ), array( 'admin', 'admin', 'admin', 'admin' ) ),
			),
		),
		array(
			'ip'      => '198.51.100.48',
			'ua'      => $agents['script'],
			'gateway' => 'xmlrpc',
			'ban'     => true,
			'bursts'  => array(
				array( $demo->ago( 6, 3, 51 ), array( 'administrator', 'larkspur', 'admin', 'administrator' ) ),
				array( $demo->ago( 6, 4, 13 ), array( 'shop', 'larkspurfern', 'admin', 'test' ) ),
				array( $demo->ago( 6, 4, 40 ), array( 'admin', 'administrator', 'wp', 'admin' ) ),
			),
		),
		array(
			'ip'       => $shopper( 'olivia' )['ip'],
			'ua'       => $agents['mac'],
			'gateway'  => 'wp_woo_login',
			'unlocked' => $demo->ago( 12, 20, 58 ),
			'bursts'   => array(
				array( $demo->ago( 12, 20, 41 ), array_fill( 0, 4, $people['olivia']['email'] ) ),
			),
		),
		array(
			'ip'      => '203.0.113.9',
			'ua'      => $agents['linux'],
			'gateway' => 'wp_login',
			'active'  => true,
			'bursts'  => array(
				array( $now - 4 * HOUR_IN_SECONDS - 40 * MINUTE_IN_SECONDS, array( 'admin', 'admin', 'wpadmin', 'admin' ) ),
				array( $now - 4 * HOUR_IN_SECONDS - 18 * MINUTE_IN_SECONDS, array( 'admin', 'larkspur', 'admin', 'shopadmin' ) ),
			),
		),
	);
	foreach ( $waves as $w => $wave ) {
		foreach ( $wave['bursts'] as $b => $burst ) {
			$ts = $burst[0];
			foreach ( $burst[1] as $i => $name ) {
				$ts   = $burst[0] + $i * ( 7 + ( $i * 3 + $w ) % 6 );
				$user = is_email( $name ) ? get_user_by( 'email', $name ) : get_user_by( 'login', $name );
				$add( $ts, 'failed', array( 'user' => $name, 'user_id' => $user ? (int) $user->ID : 0, 'ip' => $wave['ip'], 'ua' => $wave['ua'], 'wave' => $w, 'gateway' => $wave['gateway'] ) );
			}
			$last  = count( $wave['bursts'] ) - 1 === $b;
			$until = $ts + 2 + ( ! empty( $wave['active'] ) && $last ? DAY_IN_SECONDS : 20 * MINUTE_IN_SECONDS );
			$add(
				$ts + 2,
				'lockout',
				array(
					'ip'       => $wave['ip'],
					'ua'       => $wave['ua'],
					'user'     => end( $burst[1] ),
					'wave'     => $w,
					'burst'    => $b,
					'until'    => $until,
					'active'   => ! empty( $wave['active'] ) && $last,
					'ban'      => ! empty( $wave['ban'] ) && $last,
					'unlocked' => isset( $wave['unlocked'] ) ? $wave['unlocked'] : 0,
					'gateway'  => $wave['gateway'],
				)
			);
		}
	}
	$add( $demo->ago( 12, 21, 1 ), 'login', $shopper( 'olivia' ) );

	// Requests the AIOS firewall turned away.
	$add( $demo->ago( 9, 23, 12 ), 'blocked', array( 'ip' => '198.51.100.77', 'ua' => $agents['script'], 'rule' => 'Completely block XMLRPC', 'family' => 'General', 'uri' => '/xmlrpc.php' ) );
	$add( $demo->ago( 9, 23, 12 ) + 6, 'blocked', array( 'ip' => '198.51.100.77', 'ua' => $agents['script'], 'rule' => 'Completely block XMLRPC', 'family' => 'General', 'uri' => '/xmlrpc.php' ) );
	$add( $demo->ago( 2, 14, 37 ), 'blocked', array( 'ip' => '203.0.113.77', 'ua' => $agents['old'], 'rule' => 'Block query strings', 'family' => '6G', 'uri' => '/?s=../../wp-config.php' ) );
	$add( $demo->ago( 2, 14, 38 ), 'blocked', array( 'ip' => '203.0.113.77', 'ua' => $agents['old'], 'rule' => 'Block request strings', 'family' => '6G', 'uri' => '/wp-config.php.bak' ) );

	// Staff sign in a few minutes before the first thing they do each day,
	// and on a few quiet days besides.
	$first = array();
	foreach ( $events as $e ) {
		if ( $e['who'] && isset( $where[ $e['who'] ] ) && 'failed' !== $e['type'] ) {
			$key = $e['who'] . '|' . wp_date( 'Y-m-d', $e['ts'] );
			if ( ! isset( $first[ $key ] ) || $e['ts'] < $first[ $key ]['ts'] ) {
				$first[ $key ] = $e;
			}
		}
	}
	foreach ( $first as $e ) {
		if ( 'login' !== $e['type'] && ! ( 'theo' === $e['who'] && 'reset_request' === $e['type'] ) ) {
			$add( $e['ts'] - ( 6 + $e['ts'] % 9 ) * MINUTE_IN_SECONDS, 'login', array( 'who' => $e['who'], 'ip' => $e['ip'], 'ua' => $e['ua'] ) );
		}
	}
	foreach ( array( array( 'june', 20, 9, 15 ), array( 'june', 3, 11, 2 ), array( 'mateo', 24, 8, 4 ), array( 'mateo', 19, 8, 2 ), array( 'mateo', 2, 8, 6 ), array( 'hana', 6, 16, 40 ), array( 'priya', 9, 19, 12 ) ) as $s ) {
		$add( $demo->ago( $s[1], $s[2], $s[3] ), 'login', $staff( $s[0] ) );
	}
	$add( $now - 6 * HOUR_IN_SECONDS - 50 * MINUTE_IN_SECONDS, 'login', $staff( 'mateo' ) );
	$add( $now - 3 * HOUR_IN_SECONDS - 10 * MINUTE_IN_SECONDS, 'login', $staff( 'june' ) );
	foreach ( array( array( 'june', 26, 10, 21 ), array( 'theo', 16, 8, 30 ), array( 'mateo', 19, 16, 45 ), array( 'priya', 9, 19, 48 ), array( 'hana', 2, 10, 31 ) ) as $s ) {
		$add( $demo->ago( $s[1], $s[2], $s[3] ), 'logout', $staff( $s[0] ) );
	}

	usort(
		$events,
		function ( $a, $b ) {
			return $a['ts'] - $b['ts'];
		}
	);

	// Small helpers shared by the writers.
	$post_link  = function ( $id ) {
		return admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	};
	$order_link = function ( $id ) {
		return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $id );
	};
	$person     = function ( $slug ) use ( $people ) {
		return $slug && isset( $people[ $slug ] ) ? $people[ $slug ] : null;
	};
	$status_name = function ( $status ) {
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : ucfirst( $status );
	};
	$anon = function ( $ip ) {
		return preg_replace( '/\.\d+$/', '.0', $ip );
	};

	/*
	 * Settings and side tables first: writing them may itself be logged by
	 * the activity plugins, and their tables are emptied after this.
	 */

	// Limit Login Attempts Reloaded: the lockout log, active lockouts and stats.
	if ( class_exists( 'LLAR\\Core\\Config' ) ) {
		$logged = array();
		$active = array();
		$stats  = array();
		$total  = 0;
		foreach ( $events as $e ) {
			if ( 'failed' === $e['type'] && isset( $e['wave'] ) && $e['ts'] > $now - 8 * DAY_IN_SECONDS ) {
				$hour           = $e['ts'] - $e['ts'] % HOUR_IN_SECONDS;
				$stats[ $hour ] = isset( $stats[ $hour ] ) ? $stats[ $hour ] + 1 : 1;
			}
			if ( 'lockout' !== $e['type'] ) {
				continue;
			}
			++$total;
			$row = isset( $logged[ $e['ip'] ][ $e['user'] ] ) ? $logged[ $e['ip'] ][ $e['user'] ] : array( 'counter' => 0 );
			++$row['counter'];
			$row['date']    = $e['ts'];
			$row['gateway'] = $e['gateway'];
			if ( $e['unlocked'] ) {
				$row['unlocked'] = true;
			}
			$logged[ $e['ip'] ][ $e['user'] ] = $row;
			if ( $e['active'] ) {
				$active[ $e['ip'] ] = $e['until'];
			}
		}
		LLAR\Core\Config::update( 'logged', $logged );
		LLAR\Core\Config::update( 'lockouts', $active );
		LLAR\Core\Config::update( 'lockouts_total', $total );
		LLAR\Core\Config::update( 'retries_stats', $stats );
		LLAR\Core\Config::update( 'retries', array() );
		LLAR\Core\Config::update( 'retries_valid', array() );
		$demo->log( 'Limit Login Attempts: ' . $total . ' lockouts, ' . count( $active ) . ' still locked' );
	}

	// WP Activity Log: WooCommerce bumps wc_last_active every few minutes
	// while someone browses, and WSAL logs each bump as a user field change,
	// which would top the log during a recording. Excluding the field is
	// WSAL's own setting (the "exclude this custom field" link on the event).
	if ( class_exists( 'WSAL\\Helpers\\Settings_Helper' ) ) {
		$excluded = WSAL\Helpers\Settings_Helper::get_excluded_user_meta_fields();
		if ( ! in_array( 'wc_last_active', $excluded, true ) ) {
			$excluded[] = 'wc_last_active';
			WSAL\Helpers\Settings_Helper::set_excluded_user_meta_fields( $excluded );
		}
	}

	// Wordfence: the last scheduled scan ran a few hours ago and came back clean.
	if ( class_exists( 'wfConfig' ) ) {
		wfConfig::set( 'scanTime', $now - 6 * HOUR_IN_SECONDS - 20 * MINUTE_IN_SECONDS );
		wfConfig::set( 'lastScanCompleted', 'ok' );
		wfConfig::set( 'lastScanFailureType', '' );
	}

	// Sucuri: the audit queue (kept on the site without their API service)
	// and the last-logins list.
	$sucuri = array();
	if ( class_exists( 'SucuriScanCache' ) && class_exists( 'SucuriScan' ) ) {
		$line = function ( $level, $e, $text ) use ( $person ) {
			$p    = $person( $e['who'] );
			$user = $p && 'failed' !== $e['type'] && 'reset_request' !== $e['type'] ? ' ' . $p['login'] . ',' : '';
			return array( $e['ts'], sprintf( '%s:%s %s; %s', $level, $user, $e['ip'], $text ) );
		};
		$logins = array();
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$sucuri[] = $line( 'Notice', $e, 'User authentication succeeded: ' . $p['login'] );
					$logins[] = array(
						'user_id'         => $p['id'],
						'user_login'      => $p['login'],
						'user_remoteaddr' => $e['ip'],
						'user_hostname'   => $e['ip'],
						'user_lastlogin'  => wp_date( 'Y-m-d H:i:s', $e['ts'] ),
					);
					break;
				case 'failed':
					$sucuri[] = $line( 'Error', $e, 'User authentication failed: ' . sanitize_user( $e['user'], true ) );
					break;
				case 'reset_request':
					$sucuri[] = $line( 'Error', $e, 'Password retrieval attempt: ' . $p['login'] );
					break;
				case 'post':
					if ( $e['from'] !== $e['to'] ) {
						$sucuri[] = $line( 'Notice', $e, sprintf( '%s status has been changed; details: ID: %d,Old status: %s,New status: %s,Title: %s', ucwords( $e['post_type'] ), $e['post'], $e['from'], $e['to'], $e['title'] ) );
					}
					break;
				case 'plugin_update':
					$sucuri[] = $line( 'Warning', $e, sprintf( 'Plugin updated: %s (v%s; %s)', $e['name'], $e['new'], $e['file'] ) );
					break;
				case 'core_update':
					$sucuri[] = $line( 'Critical', $e, 'WordPress updated to version: ' . $e['new'] );
					$sucuri[] = array( $e['ts'] + 130, sprintf( 'Info: %s; WordPress version detected %s', $e['ip'], $e['new'] ) );
					break;
				case 'role':
					$t        = $people[ $e['target'] ];
					$sucuri[] = $line( 'Warning', $e, sprintf( 'User account edited; ID: %d; name: %s; old_name: %s; email: %s; old_email: %s; roles: %s; old_roles: %s', $t['id'], $t['login'], $t['login'], $t['email'], $t['email'], $e['new'], $e['old'] ) );
					break;
			}
		}
		$cache = new SucuriScanCache( 'auditqueue', false );
		$cache->flush();
		$cache = new SucuriScanCache( 'auditqueue' );
		foreach ( $sucuri as $i => $s ) {
			$cache->add( $s[0] . '_' . ( 1000 + ( $i * 7919 ) % 9000 ), $s[1] );
		}
		$lines = '';
		foreach ( $logins as $l ) {
			$lines .= wp_json_encode( $l ) . "\n";
		}
		file_put_contents( SucuriScan::dataStorePath( 'sucuri-lastlogins.php' ), "<?php exit(0); ?>\n" . $lines ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		// Sucuri sends a fresh login to its last-logins report, which cuts off
		// the recorder's own login on its way to Minn.
		if ( class_exists( 'SucuriScanOption' ) ) {
			SucuriScanOption::updateOption( ':lastlogin_redirection', 'disabled' );
		}
		$demo->log( 'Sucuri: ' . count( $sucuri ) . ' audit events' );
	}

	/*
	 * The log tables. Everything in them is the seed run logging itself, so
	 * they start empty; sweep() runs again at shutdown for later parts.
	 */
	$t = array(
		'sh'       => $wpdb->prefix . 'simple_history',
		'sh_ctx'   => $wpdb->prefix . 'simple_history_contexts',
		'wsal'     => $wpdb->base_prefix . 'wsal_occurrences',
		'wsal_m'   => $wpdb->base_prefix . 'wsal_metadata',
		'aryo'     => $wpdb->prefix . 'aryo_activity_log',
		'stream'   => $wpdb->base_prefix . 'stream',
		'stream_m' => $wpdb->base_prefix . 'stream_meta',
		'aios'     => $wpdb->base_prefix . 'aiowps_audit_log',
		'aios_l'   => $wpdb->base_prefix . 'aiowps_login_lockdown',
		'aios_p'   => $wpdb->base_prefix . 'aiowps_permanent_block',
		'wf'       => $wpdb->base_prefix . 'wfLogins',
		'wf_hits'  => $wpdb->base_prefix . 'wfHits',
		'itsec_lo' => $wpdb->base_prefix . 'itsec_lockouts',
		'itsec_b'  => $wpdb->base_prefix . 'itsec_bans',
		'itsec_l'  => $wpdb->base_prefix . 'itsec_logs',
	);
	$have = array();
	foreach ( $t as $key => $table ) {
		$found        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$have[ $key ] = $found && 0 === strcasecmp( (string) $found, $table );
		if ( $have[ $key ] ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
	$sweep = function () use ( $t, $have, $cut, $offset, $sucuri ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gmt = gmdate( 'Y-m-d H:i:s', $cut );
		if ( $have['sh'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE c FROM {$t['sh_ctx']} c JOIN {$t['sh']} h ON h.id = c.history_id WHERE h.date >= %s", $gmt ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['sh']} WHERE date >= %s", $gmt ) );
		}
		if ( $have['wsal'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE m FROM {$t['wsal_m']} m JOIN {$t['wsal']} o ON o.id = m.occurrence_id WHERE o.created_on >= %d", $cut ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['wsal']} WHERE created_on >= %d", $cut ) );
		}
		if ( $have['aryo'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['aryo']} WHERE hist_time >= %d", $cut + $offset ) );
		}
		if ( $have['stream'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE m FROM {$t['stream_m']} m JOIN {$t['stream']} s ON s.ID = m.record_id WHERE s.created >= %s", $gmt ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['stream']} WHERE created >= %s", $gmt ) );
		}
		if ( $have['aios'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['aios']} WHERE created >= %d", $cut ) );
		}
		if ( $have['wf'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['wf']} WHERE ctime >= %d", $cut ) );
		}
		if ( $have['wf_hits'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['wf_hits']} WHERE ctime >= %d", $cut ) );
		}
		if ( $have['itsec_l'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['itsec_l']} WHERE timestamp >= %s", $gmt ) );
		}
		// phpcs:enable
		if ( $sucuri && class_exists( 'SucuriScanCache' ) ) {
			$cache = new SucuriScanCache( 'auditqueue', false );
			$queued = $cache->getAll();
			if ( ! is_array( $queued ) || count( $queued ) !== count( $sucuri ) ) {
				$cache->flush();
				$cache = new SucuriScanCache( 'auditqueue' );
				foreach ( $sucuri as $i => $s ) {
					$cache->add( $s[0] . '_' . ( 1000 + ( $i * 7919 ) % 9000 ), $s[1] );
				}
			}
		}
	};

	// Simple History, through its own loggers.
	$sh_count = 0;
	if ( $have['sh'] && class_exists( 'Simple_History\\Simple_History' ) ) {
		$sh     = Simple_History\Simple_History::get_instance();
		$logger = function ( $slug ) use ( $sh ) {
			return $sh->get_instantiated_logger_by_slug( $slug );
		};
		$strip  = function ( $context ) {
			foreach ( array_keys( (array) $context ) as $key ) {
				if ( 0 === strpos( $key, '_cli_' ) || 0 === strpos( $key, '_initiator_ai_' ) ) {
					unset( $context[ $key ] );
				}
			}
			return $context;
		};
		add_filter( 'simple_history/log_insert_context', $strip, PHP_INT_MAX );
		$sh_log = function ( $slug, $level, $key, $e, array $context ) use ( $logger, $person, $role_at, $anon, &$sh_count ) {
			$l = $logger( $slug );
			if ( ! $l ) {
				return;
			}
			$p        = $person( $e['who'] );
			$context += array(
				'_date'               => gmdate( 'Y-m-d H:i:s', $e['ts'] ),
				'_server_remote_addr' => $anon( $e['ip'] ),
			);
			if ( ! isset( $context['_initiator'] ) ) {
				$context['_initiator'] = $p ? 'wp_user' : 'wp';
			}
			if ( $p && 'wp_user' === $context['_initiator'] ) {
				$context += array(
					'_user_id'    => $p['id'],
					'_user_login' => $p['login'],
					'_user_email' => $p['email'],
					'_user_role'  => $role_at( $e['who'], $e['ts'] ),
				);
			}
			call_user_func( array( $l, $level . '_message' ), $key, $context );
			++$sh_count;
		};
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$sh_log( 'SimpleUserLogger', 'info', 'user_logged_in', $e, array( 'user_id' => $p['id'], 'user_email' => $p['email'], 'user_login' => $p['login'], 'server_http_user_agent' => $e['ua'] ) );
					break;
				case 'logout':
					$sh_log( 'SimpleUserLogger', 'info', 'user_logged_out', $e, array() );
					break;
				case 'failed':
					$user = ! empty( $e['user_id'] ) ? get_userdata( $e['user_id'] ) : false;
					$ctx  = array( '_initiator' => 'web_user', 'server_http_user_agent' => $e['ua'], '_occasionsID' => 'Simple_History\\Loggers\\User_Logger/failed_user_login' );
					if ( $user ) {
						$sh_log( 'SimpleUserLogger', 'warning', 'user_login_failed', array( 'who' => null ) + $e, $ctx + array( 'login_id' => $user->ID, 'login_email' => $user->user_email, 'login' => $user->user_login ) );
					} else {
						$sh_log( 'SimpleUserLogger', 'warning', 'user_unknown_login_failed', array( 'who' => null ) + $e, $ctx + array( 'failed_username' => $e['user'] ) );
					}
					break;
				case 'note':
					$sh_log( 'CustomEntryLogger', $e['level'], 'custom_entry_added', $e, array( 'message' => $e['message'], 'note' => $e['note'] ) );
					break;
				case 'reset_request':
					$sh_log( 'SimpleUserLogger', 'notice', 'user_requested_password_reset_link', array( 'who' => null ) + $e, array( '_initiator' => 'web_user', 'user_login' => $p['login'], 'user_email' => $p['email'] ) );
					break;
				case 'reset':
					$sh_log( 'SimpleUserLogger', 'info', 'user_password_reseted', $e, array() );
					break;
				case 'role':
					$target = $people[ $e['target'] ];
					$sh_log( 'SimpleUserLogger', 'notice', 'user_role_updated', $e, array( 'edited_user_id' => $target['id'], 'edited_user_email' => $target['email'], 'edited_user_login' => $target['login'], 'new_role' => $e['new'], 'old_role' => $e['old'] ) );
					break;
				case 'post':
					$key = 'new' === $e['from'] ? 'post_created' : 'post_updated';
					$sh_log( 'SimplePostLogger', 'info', $key, $e, array( 'post_id' => $e['post'], 'post_type' => $e['post_type'], 'post_title' => $e['title'], 'post_prev_status' => $e['from'], 'post_new_status' => $e['to'] ) );
					break;
				case 'stock':
					$product = wc_get_product( $e['product'] );
					$sh_log( 'SimplePostLogger', 'info', 'post_updated', $e, array( 'post_id' => $e['product'], 'post_type' => 'product', 'post_title' => $product->get_name(), 'post_prev_status' => 'publish', 'post_new_status' => 'publish' ) );
					break;
				case 'update_available':
					$sh_log( 'AvailableUpdatesLogger', 'notice', 'plugin_update_available', $e, array( '_initiator' => 'wp', 'plugin_name' => $e['name'], 'plugin_slug' => dirname( $e['file'] ), 'plugin_current_version' => $e['old'], 'plugin_new_version' => $e['new'] ) );
					break;
				case 'plugin_update':
					$sh_log( 'SimplePluginLogger', 'info', 'plugin_updated', $e, array( 'plugin_name' => $e['name'], 'plugin_slug' => dirname( $e['file'] ), 'plugin_version' => $e['new'], 'plugin_prev_version' => $e['old'], 'plugin_url' => $e['url'] ) + ( $p ? array() : array( '_initiator' => 'wp', '_wp_cron_running' => true ) ) );
					break;
				case 'core_update':
					$sh_log( 'SimpleCoreUpdatesLogger', 'info', 'core_auto_updated', $e, array( '_initiator' => 'wp', '_wp_cron_running' => true, 'new_version' => $e['new'], 'prev_version' => $e['old'] ) );
					break;
			}
		}
		remove_filter( 'simple_history/log_insert_context', $strip, PHP_INT_MAX );
		$demo->log( 'Simple History: ' . $sh_count . ' events' );
	}

	// WP Activity Log, through its own alert manager.
	$wsal_count = 0;
	if ( $have['wsal'] && class_exists( 'WSAL\\Controllers\\Alert_Manager' ) ) {
		$wsal_ts   = 0;
		$wsal_time = function () use ( &$wsal_ts ) {
			return $wsal_ts;
		};
		add_filter( 'wsal_database_timestamp_value', $wsal_time, PHP_INT_MAX );
		$wsal = function ( $id, $e, array $data ) use ( &$wsal_ts, &$wsal_count, $person, $role_at ) {
			$p       = $person( $e['who'] );
			$wsal_ts = $e['ts'] + ( $e['ts'] % 1000 ) / 1000;
			$data   += array(
				'ClientIP'  => $e['ip'],
				'UserAgent' => $e['ua'],
			);
			if ( $p && ! isset( $data['CurrentUserID'] ) ) {
				$data += array(
					'Username'         => $p['login'],
					'CurrentUserID'    => $p['id'],
					'CurrentUserRoles' => array( $role_at( $e['who'], $e['ts'] ) ),
				);
			} elseif ( ! isset( $data['CurrentUserID'] ) ) {
				$data['CurrentUserID'] = 0;
			}
			WSAL\Controllers\Alert_Manager::log( $id, $data );
			++$wsal_count;
		};
		// Failed logins: one event per address, day and username, with a count.
		$tries = array();
		foreach ( $events as $e ) {
			if ( 'failed' !== $e['type'] ) {
				continue;
			}
			$user = ! empty( $e['user_id'] ) ? get_userdata( $e['user_id'] ) : false;
			$key  = $e['ip'] . '|' . wp_date( 'Y-m-d', $e['ts'] ) . '|' . ( $user ? $user->user_login : '' );
			if ( ! isset( $tries[ $key ] ) ) {
				$tries[ $key ] = array( 'e' => $e, 'user' => $user, 'n' => 0, 'names' => array() );
			}
			++$tries[ $key ]['n'];
			$tries[ $key ]['names'][ $e['user'] ] = true;
		}
		$failed = array();
		foreach ( $tries as $try ) {
			$failed[ $try['e']['ts'] . '|' . $try['e']['ip'] . '|' . ( $try['user'] ? $try['user']->user_login : '' ) ] = $try;
		}
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$wsal( 1000, $e, array( 'LoginPageURL' => 'customer' === $p['role'] ? home_url( '/my-account/' ) : wp_login_url() ) );
					break;
				case 'logout':
					$wsal( 1001, $e, array() );
					break;
				case 'failed':
					foreach ( $failed as $key => $try ) {
						if ( 0 === strpos( $key, $e['ts'] . '|' . $e['ip'] . '|' ) ) {
							$common = array( 'Attempts' => $try['n'], 'LogFileText' => '', 'LoginPageURL' => 'wp_woo_login' === ( isset( $e['gateway'] ) ? $e['gateway'] : '' ) ? home_url( '/my-account/' ) : wp_login_url() );
							if ( $try['user'] ) {
								$wsal( 1002, array( 'who' => null ) + $e, $common + array( 'Username' => $try['user']->user_login, 'CurrentUserID' => $try['user']->ID, 'CurrentUserRoles' => $try['user']->roles ) );
							} else {
								$wsal( 1003, array( 'who' => null ) + $e, $common + array( 'Users' => implode( ', ', array_keys( $try['names'] ) ) ) );
							}
						}
					}
					break;
				case 'reset_request':
					$wsal( 1010, $e, array() );
					break;
				case 'reset':
					$wsal( 4003, $e, array( 'TargetUserData' => (object) array( 'Username' => $p['login'], 'FirstName' => $p['first'], 'LastName' => $p['last'], 'Email' => $p['email'], 'Roles' => $p['role'] ) ) );
					break;
				case 'role':
					$target = $people[ $e['target'] ];
					$wsal(
						4002,
						$e,
						array(
							'TargetUserID'   => $target['id'],
							'TargetUsername' => $target['login'],
							'OldRole'        => $labels[ $e['old'] ],
							'NewRole'        => $labels[ $e['new'] ],
							'FirstName'      => $target['first'],
							'LastName'       => $target['last'],
							'EditUserLink'   => admin_url( 'user-edit.php?user_id=' . $target['id'] ),
							'TargetUserData' => (object) array( 'Username' => $target['login'], 'FirstName' => $target['first'], 'LastName' => $target['last'], 'Email' => $target['email'], 'Roles' => $labels[ $e['new'] ] ),
						)
					);
					break;
				case 'post':
					$post = get_post( $e['post'] );
					$data = array(
						'PostID'         => $e['post'],
						'PostType'       => $e['post_type'],
						'PostTitle'      => $e['title'],
						'PostStatus'     => $e['to'],
						'PostDate'       => $post ? $post->post_date : '',
						'PostUrl'        => get_permalink( $e['post'] ),
						'EditorLinkPost' => $post_link( $e['post'] ),
					);
					if ( 'publish' === $e['to'] && 'publish' !== $e['from'] ) {
						$wsal( 2001, $e, $data );
					} elseif ( 'pending' === $e['to'] ) {
						$wsal( 2073, $e, $data );
					} elseif ( 'draft' === $e['to'] ) {
						$wsal( 2000, $e, $data );
					} elseif ( 'future' === $e['to'] ) {
						$wsal( 2074, $e, $data + array( 'PublishingDate' => $e['when'] ) );
					} else {
						$wsal( 2065, $e, $data );
					}
					break;
				case 'stock':
					$product = wc_get_product( $e['product'] );
					$wsal( 9019, $e, array( 'PostID' => $e['product'], 'SKU' => $product->get_sku(), 'ProductStatus' => 'publish', 'ProductTitle' => $product->get_name(), 'OldValue' => $e['old'], 'NewValue' => $e['new'], 'PostType' => 'product', 'EditorLinkProduct' => $post_link( $e['product'] ) ) );
					break;
				case 'plugin_update':
					$wsal( 5004, $e, array( 'PluginFile' => WP_PLUGIN_DIR . '/' . $e['file'], 'OldVersion' => $e['old'], 'PluginData' => (object) array( 'Name' => $e['name'], 'PluginURI' => $e['url'], 'Version' => $e['new'], 'Network' => false, 'Slug' => dirname( $e['file'] ), 'Title' => $e['name'], 'plugin_dir_path' => WP_PLUGIN_DIR . '/' . $e['file'] ) ) );
					break;
				case 'core_update':
					$wsal( 6004, $e, array( 'OldVersion' => $e['old'], 'NewVersion' => $e['new'] . ' (auto update)' ) );
					break;
				case 'order_placed':
					if ( $e['created'] > $now - 10 * DAY_IN_SECONDS ) {
						$wsal( 9035, $e, $p ? array( 'OrderID' => $e['order'], 'OrderTitle' => $e['title'], 'EditorLinkOrder' => $order_link( $e['order'] ) ) : array( 'OrderID' => $e['order'], 'OrderTitle' => $e['title'], 'EditorLinkOrder' => $order_link( $e['order'] ), 'Username' => 'WooCommerce System', 'CurrentUserID' => 0 ) );
					}
					break;
				case 'order_status':
					$data = array( 'OrderID' => $e['order'], 'OrderTitle' => $e['title'], 'OrderStatus' => $status_name( $e['to'] ), 'OrderStatusSlug' => $e['to'], 'PostStatus' => 'wc-' . $e['to'], 'EditorLinkOrder' => $order_link( $e['order'] ) );
					if ( 'refunded' === $e['to'] ) {
						break; // The refund event below says it.
					}
					$wsal( 9036, $e, $p ? $data : $data + array( 'Username' => 'WooCommerce System', 'CurrentUserID' => 0 ) );
					break;
				case 'refund':
					$customer = $person( $e['customer'] );
					$wsal( 9041, $e, array( 'OrderID' => $e['order'], 'RefundID' => $e['refund'], 'CustomerUser' => $customer ? $customer['login'] : 'Guest', 'RefundedAmount' => '$' . number_format( $e['amount'], 2 ), 'Reason' => $e['reason'], 'OrderDate' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['created'] ), 'OrderTitle' => $e['title'], 'OrderStatus' => $status_name( 'refunded' ), 'OrderStatusSlug' => 'refunded', 'PostStatus' => 'wc-refunded', 'EditorLinkOrder' => $order_link( $e['order'] ) ) );
					break;
			}
		}
		remove_filter( 'wsal_database_timestamp_value', $wsal_time, PHP_INT_MAX );
		$demo->log( 'WP Activity Log: ' . $wsal_count . ' events' );
	}

	// Activity Log (Aryo): one flat table, hist_time in site-local epoch.
	$aryo_count = 0;
	if ( $have['aryo'] ) {
		$has_source = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$t['aryo']} LIKE %s", 'request_source' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$aryo       = function ( $e, $action, $type, $subtype, $name, $object_id, $source = '' ) use ( $wpdb, $t, $person, $role_at, $offset, $has_source, &$aryo_count ) {
			$p   = $person( $e['who'] );
			$row = array(
				'user_caps'      => $p ? $role_at( $e['who'], $e['ts'] ) : 'guest',
				'action'         => $action,
				'object_type'    => $type,
				'object_subtype' => $subtype,
				'object_name'    => $name,
				'object_id'      => (int) $object_id,
				'user_id'        => $p ? $p['id'] : 0,
				'hist_ip'        => $e['ip'],
				'hist_time'      => $e['ts'] + $offset,
			);
			if ( $has_source ) {
				$row['request_source'] = $source;
			}
			$wpdb->insert( $t['aryo'], $row );
			++$aryo_count;
		};
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$aryo( $e, 'logged_in', 'Users', 'Session', $p['nicename'], $p['id'] );
					break;
				case 'logout':
					$aryo( $e, 'logged_out', 'Users', 'Session', $p['nicename'], $p['id'] );
					break;
				case 'failed':
					$aryo( array( 'who' => null ) + $e, 'failed_login', 'Users', 'Session', $e['user'], 0 );
					break;
				case 'reset':
					$aryo( $e, 'updated', 'Users', 'Profile', $p['nicename'], $p['id'] );
					break;
				case 'role':
					$aryo( $e, 'updated', 'Users', 'Profile', $people[ $e['target'] ]['nicename'], $people[ $e['target'] ]['id'] );
					break;
				case 'post':
					$aryo( $e, 'new' === $e['from'] ? 'created' : 'updated', 'Posts', $e['post_type'], $e['title'], $e['post'], 'rest' );
					break;
				case 'stock':
					$product = wc_get_product( $e['product'] );
					$aryo( $e, 'updated', 'Posts', 'product', $product->get_name(), $e['product'], isset( $e['app'] ) ? 'rest|app:' . $e['app'] : '' );
					break;
				case 'plugin_update':
					$aryo( $e, 'updated', 'Plugins', $e['new'], $e['name'], 0, $p ? '' : 'cron' );
					break;
				case 'core_update':
					$aryo( $e, 'updated', 'Core', '', 'WordPress Auto Updated', 0, 'cron' );
					break;
			}
		}
		$demo->log( 'Activity Log (Aryo): ' . $aryo_count . ' events' );
	}

	// Stream: records plus a little meta, created in GMT.
	$stream_count = 0;
	if ( $have['stream'] ) {
		$stream = function ( $e, $summary, $connector, $context, $action, $object_id, array $meta = array() ) use ( $wpdb, $t, $person, $role_at, $labels, &$stream_count ) {
			$p = $person( $e['who'] );
			$wpdb->insert(
				$t['stream'],
				array(
					'site_id'   => 1,
					'blog_id'   => get_current_blog_id(),
					'object_id' => $object_id ? (int) $object_id : null,
					'user_id'   => $p ? $p['id'] : 0,
					'user_role' => $p ? $role_at( $e['who'], $e['ts'] ) : '',
					'summary'   => $summary,
					'created'   => gmdate( 'Y-m-d H:i:s', $e['ts'] ),
					'connector' => $connector,
					'context'   => $context,
					'action'    => $action,
					'ip'        => $e['ip'],
				)
			);
			$record = (int) $wpdb->insert_id;
			if ( $p ) {
				$meta['user_meta'] = array( $p['email'], $p['name'], $p['login'], $labels[ $role_at( $e['who'], $e['ts'] ) ], $e['ua'] );
			}
			foreach ( $meta as $key => $values ) {
				foreach ( (array) $values as $value ) {
					$wpdb->insert( $t['stream_m'], array( 'record_id' => $record, 'meta_key' => $key, 'meta_value' => (string) $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				}
			}
			++$stream_count;
		};
		$said = array(
			'publish' => '"%1$s" %2$s published',
			'pending' => '"%1$s" %2$s pending review',
			'draft'   => '"%1$s" %2$s drafted',
			'future'  => '"%1$s" %2$s scheduled for %3$s',
		);
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$stream( $e, sprintf( '%s logged in', $p['name'] ), 'users', 'sessions', 'login', $p['id'], array( 'display_name' => $p['name'] ) );
					break;
				case 'logout':
					$stream( $e, sprintf( '%s logged out', $p['name'] ), 'users', 'sessions', 'logout', $p['id'], array( 'display_name' => $p['name'] ) );
					break;
				case 'reset_request':
					$stream( array( 'who' => null ) + $e, sprintf( '%s\'s password was requested to be reset', $p['name'] ), 'users', 'sessions', 'forgot-password', $p['id'], array( 'display_name' => $p['name'] ) );
					break;
				case 'reset':
					$stream( $e, sprintf( '%s\'s password was reset', $p['name'] ), 'users', 'profiles', 'password-reset', $p['id'], array( 'display_name' => $p['name'] ) );
					break;
				case 'role':
					$target = $people[ $e['target'] ];
					$stream( $e, sprintf( '%1$s\'s role was changed from %2$s to %3$s', $target['name'], $labels[ $e['old'] ], $labels[ $e['new'] ] ), 'users', 'profiles', 'updated', $target['id'], array( 'display_name' => $target['name'], 'old_role' => $labels[ $e['old'] ], 'new_role' => $labels[ $e['new'] ] ) );
					break;
				case 'post':
					$template = $e['from'] === $e['to'] ? '"%1$s" %2$s updated' : $said[ $e['to'] ];
					$stream( $e, sprintf( $template, $e['title'], $e['post_type'], isset( $e['when'] ) ? $e['when'] : '' ), 'posts', $e['post_type'], 'new' === $e['from'] ? 'created' : 'updated', $e['post'], array( 'post_title' => $e['title'], 'singular_name' => $e['post_type'], 'new_status' => $e['to'], 'old_status' => $e['from'] ) );
					break;
				case 'stock':
					$product = wc_get_product( $e['product'] );
					$stream( $e, sprintf( '"%s" product updated', $product->get_name() ), 'posts', 'product', 'updated', $e['product'], array( 'post_title' => $product->get_name(), 'singular_name' => 'product', 'new_status' => 'publish', 'old_status' => 'publish' ) );
					break;
				case 'plugin_update':
					$stream( $e, sprintf( 'Updated plugin: %1$s %2$s', $e['name'], $e['new'] ), 'installer', 'plugins', 'updated', 0, array( 'type' => 'plugin', 'name' => $e['name'], 'version' => $e['new'], 'old_version' => $e['old'], 'slug' => $e['file'] ) );
					break;
				case 'core_update':
					$stream( $e, sprintf( 'WordPress auto-updated to %s', $e['new'] ), 'installer', 'wordpress', 'updated', 0, array( 'new_version' => $e['new'], 'old_version' => $e['old'], 'auto_updated' => 1 ) );
					break;
				case 'order_status':
					if ( $p ) {
						$stream( $e, sprintf( 'Order number %1$s status changed from %2$s to %3$s', $e['number'], $status_name( $e['from'] ), $status_name( $e['to'] ) ), 'woocommerce', 'shop_order', 'updated', $e['order'], array( 'post_title' => 'Order number ' . $e['number'], 'old_status_name' => $status_name( $e['from'] ), 'new_status_name' => $status_name( $e['to'] ), 'singular_name' => 'order', 'new_status' => $e['to'], 'old_status' => $e['from'] ) );
					}
					break;
				case 'setting':
					$stream( $e, sprintf( '"%s" setting updated', $e['label'] ), 'woocommerce', 'products', 'updated', 0, array( 'option' => $e['option'], 'old_value' => 2, 'value' => 4 ) );
					break;
			}
		}
		$demo->log( 'Stream: ' . $stream_count . ' records' );
	}

	// All-In-One Security: the audit log, lockdowns and one permanent block.
	if ( $have['aios'] ) {
		$aios_count = 0;
		$aios       = function ( $e, $username, $level, $type, array $details, $trace = '' ) use ( $wpdb, $t, &$aios_count ) {
			$wpdb->insert(
				$t['aios'],
				array(
					'network_id'   => 1,
					'site_id'      => get_current_blog_id(),
					'username'     => $username,
					'ip'           => $e['ip'],
					'level'        => $level,
					'event_type'   => $type,
					'details'      => wp_json_encode( $details ),
					'stacktrace'   => $trace,
					'created'      => $e['ts'],
					'country_code' => '',
				)
			);
			++$aios_count;
		};
		foreach ( $events as $e ) {
			$p = $person( $e['who'] );
			switch ( $e['type'] ) {
				case 'login':
					$aios( $e, $p['login'], 'info', 'successful_login', array( 'successful_login' => array( 'username' => $p['login'] ) ) );
					break;
				case 'logout':
					$aios( $e, $p['login'], 'info', 'successful_logout', array( 'successful_logout' => array( 'username' => $p['login'], 'force_logout' => '' ) ) );
					break;
				case 'failed':
					$aios( $e, $e['user'], 'warning', 'failed_login', array( 'failed_login' => array( 'imported' => false, 'username' => $e['user'], 'known' => ! empty( $e['user_id'] ) ) ) );
					break;
				case 'reset':
					$aios( $e, '', 'warning', 'password_reset', array( 'password_reset' => array( 'user_login' => $p['login'] ) ) );
					break;
				case 'plugin_update':
					$aios( $e, $p ? $p['login'] : '', 'info', 'plugin_updated', array( 'plugin' => array( 'name' => $e['name'], 'version' => $e['new'], 'action' => 'updated', 'network' => '' ) ) );
					break;
				case 'core_update':
					$aios( $e, '', 'info', 'core_updated', array( 'core_updated' => array( 'old_version' => $e['old'], 'new_version' => $e['new'] ) ) );
					break;
				case 'blocked':
					// Firewall events keep the request where other events keep a trace.
					$request = array( 'ip' => $e['ip'], 'method' => '/xmlrpc.php' === $e['uri'] ? 'POST' : 'GET', 'uri' => $e['uri'], 'user_agent' => $e['ua'] );
					$aios( $e, '', 'warning', 'rule_triggered', array( 'firewall_event' => array( 'event' => 'triggered', 'rule_name' => $e['rule'], 'rule_family' => $e['family'] ) ), print_r( $request, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					break;
				case 'lockout':
					if ( $have['aios_l'] ) {
						$released = $e['unlocked'] ? $e['unlocked'] : ( $e['active'] ? $e['until'] : $e['ts'] + HOUR_IN_SECONDS );
						$user     = is_email( $e['user'] ) ? get_user_by( 'email', $e['user'] ) : get_user_by( 'login', $e['user'] );
						$wpdb->insert(
							$t['aios_l'],
							array(
								'user_id'               => $user ? $user->ID : 0,
								'user_login'            => $e['user'],
								'lockdown_date'         => gmdate( 'Y-m-d H:i:s', $e['ts'] ),
								'created'               => $e['ts'],
								'release_date'          => gmdate( 'Y-m-d H:i:s', $released ),
								'released'              => $released,
								'failed_login_ip'       => $e['ip'],
								'lock_reason'           => 'login_fail',
								'unlock_key'            => '',
								'is_lockout_email_sent' => 1,
								'backtrace_log'         => '',
							)
						);
					}
					break;
			}
		}
		// The address behind last night's spam comment, blocked for good.
		$spam = $wpdb->get_row( "SELECT comment_author_IP AS ip, comment_date_gmt AS gmt FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_author_IP <> '' ORDER BY comment_date_gmt DESC LIMIT 1" );
		if ( $spam && $have['aios_p'] ) {
			$ts = strtotime( $spam->gmt . ' UTC' ) + 60;
			$wpdb->insert( $t['aios_p'], array( 'blocked_ip' => $spam->ip, 'block_reason' => 'spam', 'country_origin' => '', 'blocked_date' => gmdate( 'Y-m-d H:i:s', $ts ), 'created' => $ts, 'unblock' => 0 ) );
		}
		$demo->log( 'All-In-One Security: ' . $aios_count . ' audit events' );
	}

	// Wordfence: the login attempts table.
	if ( $have['wf'] && class_exists( 'wfUtils' ) ) {
		$wf_count = 0;
		foreach ( $events as $e ) {
			if ( 'login' !== $e['type'] && 'failed' !== $e['type'] ) {
				continue;
			}
			$p = $person( $e['who'] );
			if ( 'login' === $e['type'] ) {
				$row = array( 0, 'loginOK', $p['login'], $p['id'] );
			} else {
				$id  = ! empty( $e['user_id'] ) ? $e['user_id'] : 0;
				$row = array( 1, $id ? 'loginFailValidUsername' : 'loginFailInvalidUsername', $e['user'], $id );
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$t['wf']} (hitID, ctime, fail, action, username, userID, IP, UA) VALUES (0, %f, %d, %s, %s, %d, UNHEX(%s), %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$e['ts'] + ( $e['ts'] % 997 ) / 1000,
					$row[0],
					$row[1],
					$row[2],
					$row[3],
					bin2hex( wfUtils::inet_pton( $e['ip'] ) ),
					$e['ua']
				)
			);
			++$wf_count;
		}
		$demo->log( 'Wordfence: ' . $wf_count . ' login attempts' );
	}

	// Solid Security: host lockouts (never a user lockout, never a local
	// address) and the ban that followed the third lockout in one night.
	if ( $have['itsec_lo'] ) {
		$solid = 0;
		foreach ( $events as $e ) {
			if ( 'lockout' !== $e['type'] ) {
				continue;
			}
			$expire = $e['active'] ? $e['until'] : $e['ts'] + 15 * MINUTE_IN_SECONDS;
			$wpdb->insert(
				$t['itsec_lo'],
				array(
					'lockout_type'       => 'brute_force',
					'lockout_start'      => wp_date( 'Y-m-d H:i:s', $e['ts'] ),
					'lockout_start_gmt'  => gmdate( 'Y-m-d H:i:s', $e['ts'] ),
					'lockout_expire'     => wp_date( 'Y-m-d H:i:s', $expire ),
					'lockout_expire_gmt' => gmdate( 'Y-m-d H:i:s', $expire ),
					'lockout_host'       => $e['ip'],
					'lockout_active'     => $e['unlocked'] ? 0 : 1,
				)
			);
			++$solid;
			if ( $e['ban'] && $have['itsec_b'] ) {
				$wpdb->insert( $t['itsec_b'], array( 'host' => $e['ip'], 'type' => 'ip', 'created_at' => gmdate( 'Y-m-d H:i:s', $e['ts'] + 1 ), 'actor_type' => 'lockout_module', 'actor_id' => 'brute_force', 'comment' => '' ) );
			}
		}
		$demo->log( 'Solid Security: ' . $solid . ' lockouts' );
	}

	$sweep();
	// Later parts and 99-finish are logged too: sweep again when the run ends.
	register_shutdown_function( $sweep );
	$demo->log( count( $events ) . ' events on the timeline' );
};
