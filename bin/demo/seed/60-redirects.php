<?php
/**
 * Redirects and link health. Larkspur & Fern moved from a hand-built site
 * and an older shop to WordPress, so every redirect plugin the demo runs
 * carries part of that move: Redirection (grouped rules with hit counts, a
 * regex rule, a 410 for a discontinued plant, a redirect log and a 404 log),
 * Rank Math's redirections and 404 monitor, Safe Redirect Manager, Simple 301
 * Redirects and 301 Redirects. Broken Link Checker reports what it found in
 * the journal: broken, warning, redirected and dismissed links.
 *
 * Storage facts that matter on a rerun:
 * - Redirection has no tables until its setup runs; this part runs the same
 *   installer its wizard drives. Log `created` is site-local, a redirect's
 *   last_access is UTC, and its daily cron drops logs older than
 *   expire_redirect / expire_404 days (set to 30 here).
 * - Rank Math loads no module until registration is skipped, and creates
 *   the redirection and 404 tables when Helper::update_modules() switches a
 *   module on. Its dates are site-local; sources are stored without the
 *   leading slash.
 * - 301 Redirects keeps url_from without the leading slash; its 404 log is
 *   the eps_redirects_404_log option, not rows of its redirects table.
 * - Broken Link Checker deletes instances whose container has no blc_synch
 *   row and re-parses containers marked unsynched (dropping seeded rows), so
 *   every container that carries a seeded link gets a synched row. Broken
 *   links get check_count 3 and a recent last_check_attempt, so the checker
 *   does not fetch them again for its 72 hour threshold.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$p     = $wpdb->prefix;
	$home  = untrailingslashit( home_url() );
	$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
	$table = function ( $name ) use ( $wpdb, $p ) {
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $name ) );
	};
	// A Unix time this many minutes before the run.
	$mins = function ( $minutes ) use ( $demo ) {
		return $demo->now - (int) round( $minutes * MINUTE_IN_SECONDS );
	};
	$ids = function ( $key ) use ( $demo ) {
		$list = $demo->id( 'redirects', $key );
		return array_values( array_filter( array_map( 'intval', is_array( $list ) ? $list : array() ) ) );
	};
	$in = function ( array $list ) {
		return implode( ',', array_map( 'intval', $list ) );
	};

	// Clear what an earlier run made.
	if ( class_exists( 'Red_Item' ) ) {
		foreach ( $ids( 'redirection_items' ) as $id ) {
			$item = Red_Item::get_by_id( $id );
			if ( $item ) {
				$item->delete();
			}
		}
		foreach ( $ids( 'redirection_groups' ) as $id ) {
			$group = Red_Group::get( $id );
			if ( $group ) {
				$group->delete();
			}
		}
	}
	foreach ( array( 'redirection_logs' => 'redirection_logs', 'redirection_404' => 'redirection_404', 'eps' => 'redirects', 'blc_instances' => 'blc_instances', 'blc_links' => 'blc_links' ) as $key => $name ) {
		$list = $ids( $key );
		if ( $list && $table( $name ) ) {
			$col = 'blc_instances' === $name ? 'instance_id' : ( 'blc_links' === $name ? 'link_id' : 'id' );
			$wpdb->query( "DELETE FROM {$p}{$name} WHERE {$col} IN (" . $in( $list ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
	if ( class_exists( '\RankMath\Redirections\DB' ) && $ids( 'rankmath' ) && $table( 'rank_math_redirections' ) ) {
		\RankMath\Redirections\DB::delete( $ids( 'rankmath' ) );
	}
	if ( class_exists( '\RankMath\Monitor\DB' ) && $ids( 'rankmath_404' ) && $table( 'rank_math_404_logs' ) ) {
		\RankMath\Monitor\DB::delete_log( $ids( 'rankmath_404' ) );
	}
	foreach ( $ids( 'srm' ) as $id ) {
		if ( 'redirect_rule' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	$s301_old = $demo->id( 'redirects', 's301' );
	if ( is_array( $s301_old ) && $s301_old ) {
		$rules = (array) get_option( '301_redirects', array() );
		foreach ( $s301_old as $from ) {
			unset( $rules[ $from ] );
		}
		update_option( '301_redirects', $rules );
	}
	// 301 Redirects' log entries are told apart by url and time (see below).
	$eps_404_old = $demo->id( 'redirects', 'eps_404' );
	$eps_404_old = is_array( $eps_404_old ) ? $eps_404_old : array();
	$demo->forget( 'redirects' );
	$demo->as_user( 'june' );

	// Plain browser user agents for the logs.
	$agents = array(
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
		'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
		'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
	);
	$referrers = array( '', '', '', 'https://www.example.com/search?q=portland+plant+shop', 'https://larkspur.example/newsletter/october', 'https://www.example.org/best-plant-shops-portland', $home . '/' );
	$pick      = function ( array $list ) {
		return $list[ mt_rand( 0, count( $list ) - 1 ) ];
	};
	// Masked like Redirection stores them with IP logging set to anonymize.
	$ip = function () {
		$nets = array( '192.0.2', '198.51.100', '203.0.113' );
		return $nets[ mt_rand( 0, 2 ) ] . '.0';
	};

	/*
	 * Redirection: grouped rules from the old shop and the old blog, with
	 * the redirects it served and the misses it logged over two weeks.
	 */
	if ( class_exists( '\Redirection\Database\Status' ) && class_exists( 'Red_Item' ) ) {
		$status = new \Redirection\Database\Status();
		if ( $status->needs_installing() ) {
			$result = \Redirection\Database\Database::get_latest_database()->install();
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( 'Redirection setup failed: ' . $result->get_error_message() );
			} else {
				$status->finish();
			}
		}
	}
	if ( class_exists( 'Red_Item' ) && $table( 'redirection_items' ) ) {
		$default  = (int) $wpdb->get_var( "SELECT id FROM {$p}redirection_groups WHERE module_id = 1 ORDER BY id LIMIT 1" );
		$modified = (int) $wpdb->get_var( "SELECT id FROM {$p}redirection_groups WHERE name = 'Modified Posts' ORDER BY id LIMIT 1" );
		red_set_options(
			array(
				'monitor_post'    => $modified ? $modified : $default,
				'monitor_types'   => array( 'post', 'page' ),
				'expire_redirect' => 30,
				'expire_404'      => 30,
				'ip_logging'      => 2,
			)
		);
		$groups = array( 'default' => $default, 'modified' => $modified ? $modified : $default );
		$made   = array();
		foreach ( array( 'shop' => 'Old shop URLs', 'journal' => 'Old blog' ) as $key => $name ) {
			$group = Red_Group::create( $name, 1 );
			if ( $group ) {
				$groups[ $key ] = (int) $group->get_id();
				$made[]         = (int) $group->get_id();
			}
		}
		$demo->remember( 'redirects', 'redirection_groups', $made );

		// source, target, code, group, hits, last hit (minutes ago), regex, enabled, title
		$rules = array(
			array( '/store/', '/shop/', 301, 'shop', 412, 95, false, true, '' ),
			array( '/store/houseplants/', '/product-category/houseplants/', 301, 'shop', 96, 600, false, true, '' ),
			array( '/plants/anthurium-red/', '/product/anthurium/', 301, 'shop', 57, 320, false, true, '' ),
			array( '/plants/peace-lily-large/', '/product/peace-lily/', 301, 'shop', 31, 2900, false, true, '' ),
			array( '^/plants/(.*)-seeds?/$', '/product/$1-seed/', 301, 'shop', 18, 4300, true, true, 'Seed packets from the old shop' ),
			array( '/plants/fiddle-leaf-fig/', '', 410, 'shop', 9, 7400, false, true, 'Discontinued, we stopped stocking fiddle leaf figs' ),
			array( '/workshops-2025/', '/workshops/', 301, 'default', 143, 50, false, true, '' ),
			array( '/wholesale-application/', '/wholesale/', 301, 'default', 12, 8700, false, true, '' ),
			array( '/newsletter/', '/plant-club/', 302, 'default', 38, 1500, false, true, 'Until the newsletter page is back' ),
			array( '/gift-cards/', '/shop/', 302, 'default', 64, 61000, false, false, 'Holiday gift card landing page' ),
			array( '/blog/how-often-to-water-cactus/', '/how-often-should-you-really-water-a-cactus/', 301, 'journal', 64, 210, false, true, '' ),
			array( '/blog/peat-free/', '/why-we-switched-to-peat-free-potting-mix/', 301, 'journal', 22, 5100, false, true, '' ),
			array( '/spring-workshops-are-open/', '/the-spring-workshop-calendar-is-open/', 301, 'modified', 5, 19000, false, true, '' ),
		);
		$items = array();
		foreach ( $rules as $r ) {
			list( $from, $to, $code, $group, $hits, $ago, $regex, $enabled, $title ) = $r;
			$details = array(
				'url'         => $from,
				'title'       => $title,
				'match_type'  => 'url',
				'action_type' => 410 === $code ? 'error' : 'url',
				'action_code' => $code,
				'action_data' => array( 'url' => $to ),
				'group_id'    => $groups[ $group ],
				'regex'       => $regex ? 1 : 0,
				'hits'        => $hits,
				'last_access' => $demo->gmt( $mins( $ago ) ),
				'enabled'     => $enabled,
			);
			$item = Red_Item::create( $details );
			if ( is_wp_error( $item ) ) {
				WP_CLI::warning( "Redirection would not take {$from}: " . $item->get_error_message() );
				continue;
			}
			$items[] = array( (int) $item->get_id(), $from, $to, $code, $hits, $ago, $enabled );
		}
		$demo->remember( 'redirects', 'redirection_items', wp_list_pluck( $items, 0 ) );

		// The redirect log: the busiest rules, each hit spread over two weeks,
		// the latest one at the rule's last hit.
		$log_ids = array();
		foreach ( $items as $it ) {
			list( $id, $from, $to, $code, $hits, $ago, $enabled ) = $it;
			if ( ! $enabled || $ago > 14 * 1440 ) {
				continue;
			}
			$count = max( 1, min( 14, (int) round( $hits / 12 ) ) );
			for ( $i = 0; $i < $count; $i++ ) {
				$when = 0 === $i ? $mins( $ago ) : $mins( $ago + mt_rand( 60, 14 * 1440 - $ago ) );
				$url  = $from;
				if ( 0 === strpos( $from, '^' ) ) {
					$url = '/plants/' . $pick( array( 'sunflower', 'poppy', 'sunflower' ) ) . '-seeds/';
				}
				$target = 410 === $code ? '' : home_url( preg_replace( '/\$1/', 'sunflower', $to ) );
				$log    = Red_Redirect_Log::create(
					$host,
					$url,
					$ip(),
					array(
						'agent'          => $pick( $agents ),
						'referrer'       => $pick( $referrers ),
						'request_method' => 'GET',
						'http_code'      => $code,
						'redirect_id'    => $id,
						'target'         => $target,
						'redirect_by'    => 'redirection',
					)
				);
				if ( $log ) {
					$wpdb->update( "{$p}redirection_logs", array( 'created' => $demo->local( $when ) ), array( 'id' => (int) $log ) );
					$log_ids[] = (int) $log;
				}
			}
		}
		$demo->remember( 'redirects', 'redirection_logs', $log_ids );

		// The 404 log: old links and typos that have no rule yet, and the odd probe.
		$misses = array(
			array( '/plants/monstera-thai-constellation/', 6 ),
			array( '/product/string-of-pearls/', 5 ),
			array( '/workshops/terrarium-december/', 4 ),
			array( '/wp-content/uploads/2024/05/fern-care-sheet.pdf', 3 ),
			array( '/shop/page/4/', 3 ),
			array( '/plants/pothos-marble-queen/', 2 ),
			array( '/apple-touch-icon.png', 4 ),
			array( '/.env', 2 ),
			array( '/old-site/contact.html', 2 ),
			array( '/product/terrarium-kit-large/', 3 ),
			array( '/plant-club/members/', 1 ),
		);
		$miss_ids = array();
		foreach ( $misses as $m ) {
			for ( $i = 0; $i < $m[1]; $i++ ) {
				$id = Red_404_Log::create(
					$host,
					$m[0],
					$ip(),
					array(
						'agent'          => $pick( $agents ),
						'referrer'       => $pick( $referrers ),
						'request_method' => 'GET',
						'http_code'      => 404,
					)
				);
				if ( $id ) {
					$wpdb->update( "{$p}redirection_404", array( 'created' => $demo->local( $mins( mt_rand( 20, 14 * 1440 ) ) ) ), array( 'id' => (int) $id ) );
					$miss_ids[] = (int) $id;
				}
			}
		}
		$demo->remember( 'redirects', 'redirection_404', $miss_ids );
		$demo->log( count( $items ) . ' Redirection rules, ' . count( $log_ids ) . ' redirects and ' . count( $miss_ids ) . ' 404s logged' );
	}

	/*
	 * Rank Math: its redirections module and the 404 monitor, switched on the
	 * way its Modules screen does once registration is skipped.
	 */
	if ( class_exists( '\RankMath\Helper' ) && class_exists( '\RankMath\Redirections\Redirection' ) ) {
		if ( ! get_option( 'rank_math_registration_skip' ) ) {
			update_option( 'rank_math_registration_skip', 1 );
		}
		\RankMath\Helper::update_modules( array( 'redirections' => 'on', '404-monitor' => 'on' ) );

		// sources (pattern => comparison), target, code, status, hits, last hit (minutes ago), created (days ago)
		$rules = array(
			array( array( '/about-us/' => 'exact' ), '/about/', 301, 'active', 88, 240, 140 ),
			array( array( '/seeds/' => 'exact', '/seed-packets/' => 'exact' ), '/product-category/bulbs-seeds/', 301, 'active', 77, 130, 120 ),
			array( array( '/plants/bird-of-paradise/' => 'exact' ), '/product-category/houseplants/', 301, 'active', 21, 3000, 96 ),
			array( array( '/workshop-terrarium/' => 'exact' ), '/product/workshop/', 302, 'active', 15, 1300, 44 ),
			array( array( '^prints/([a-z-]+)' => 'regex' ), '/product/print-$1/', 301, 'active', 13, 2200, 63 ),
			array( array( '/product/terracotta-pot-large/' => 'exact' ), '', 410, 'active', 6, 9800, 52 ),
			array( array( '/holiday-hours/' => 'exact' ), '/visit-the-shop/', 307, 'inactive', 40, 38000, 120 ),
			array( array( '/summer-sale/' => 'exact' ), '/shop/', 302, 'trashed', 29, 26000, 80 ),
		);
		$made = array();
		foreach ( $rules as $r ) {
			list( $sources, $to, $code, $status, $hits, $ago, $created ) = $r;
			$list = array();
			foreach ( $sources as $pattern => $comparison ) {
				$list[] = array( 'pattern' => $pattern, 'comparison' => $comparison, 'ignore' => '' );
			}
			$redirect = \RankMath\Redirections\Redirection::from(
				array(
					'sources'     => $list,
					'url_to'      => $to,
					'header_code' => $code,
					'status'      => 'active',
				)
			);
			$id = (int) $redirect->save();
			if ( ! $id ) {
				continue;
			}
			$made[] = $id;
			$wpdb->update(
				"{$p}rank_math_redirections",
				array(
					'status'        => $status,
					'hits'          => $hits,
					'created'       => $demo->local( $demo->ago( $created, 11, 20 ) ),
					'updated'       => $demo->local( $demo->ago( 'trashed' === $status ? 12 : $created, 11, 20 ) ),
					'last_accessed' => $demo->local( $mins( $ago ) ),
				),
				array( 'id' => $id )
			);
		}
		$demo->remember( 'redirects', 'rankmath', $made );

		$logged = array();
		if ( class_exists( '\RankMath\Monitor\DB' ) && $table( 'rank_math_404_logs' ) ) {
			// uri, times hit, last hit (minutes ago), referrer
			$misses = array(
				array( 'plants/monstera-albo', 14, 35, 'https://www.example.com/search?q=monstera+albo+portland' ),
				array( 'workshops/kokedama-spring', 9, 300, $home . '/the-spring-workshop-calendar-is-open/' ),
				array( 'product/kokedama-kit', 7, 520, $home . '/workshops/' ),
				array( 'wp-content/uploads/2025/03/price-list-wholesale.pdf', 5, 2600, $home . '/wholesale/' ),
				array( 'shop/gift-card', 4, 4100, '' ),
				array( 'plants/calathea-medallion', 3, 6200, 'https://www.example.org/best-plant-shops-portland' ),
				array( 'journal/repotting-guide', 3, 9000, '' ),
				array( 'product/print-delphinium-large', 2, 11000, $home . '/botanical-prints-are-back-in-stock/' ),
				array( 'plant-club/login', 2, 15000, '' ),
				array( 'wp-login.php.bak', 1, 19000, '' ),
			);
			foreach ( $misses as $m ) {
				\RankMath\Monitor\DB::add(
					array(
						'uri'            => $m[0],
						'accessed'       => $demo->local( $mins( $m[2] ) ),
						'times_accessed' => $m[1],
						'referer'        => $m[3],
						'user_agent'     => $pick( $agents ),
					)
				);
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}rank_math_404_logs WHERE uri = %s ORDER BY id DESC LIMIT 1", $m[0] ) );
				if ( $id ) {
					$logged[] = $id;
				}
			}
		}
		$demo->remember( 'redirects', 'rankmath_404', $logged );
		$demo->log( count( $made ) . ' Rank Math redirections, ' . count( $logged ) . ' 404s in its monitor' );
	}

	/*
	 * Safe Redirect Manager: Mateo's quick fixes for addresses printed on
	 * flyers and in the shop window. SRM keeps no hit counts.
	 */
	if ( function_exists( 'srm_create_redirect' ) ) {
		// from, to, code, regex, notes, days ago
		$rules = array(
			array( '/visit/', '/visit-the-shop/', 301, false, 'Printed on the autumn flyer', 70 ),
			array( '/directions/', '/visit-the-shop/', 302, false, '', 66 ),
			array( '/workshops/terrarium/', '/product/workshop/', 301, false, 'QR code on the workshop poster', 31 ),
			array( '/kids-workshop/?.*', '/product/workshop-kids/', 301, true, 'Catches /kids-workshop and anything after it', 24 ),
			array( '/faq/delivery/', '/faq/shipping-returns/', 301, false, '', 18 ),
			array( '/care-guides/', '/category/plant-care/', 301, false, 'Shop window sign', 9 ),
			array( '/plant-club/join/', '/plant-club/', 302, false, 'Until the sign-up form has its own page', 2 ),
		);
		$made  = array();
		$mateo = $demo->id( 'users', 'mateo' );
		foreach ( $rules as $i => $r ) {
			$id = srm_create_redirect( $r[0], $r[1], $r[2], $r[3], 'publish', $i, $r[4], $mateo ? $mateo : 1 );
			if ( is_wp_error( $id ) || ! $id ) {
				WP_CLI::warning( "Safe Redirect Manager would not take {$r[0]}" );
				continue;
			}
			$ts = $demo->ago( $r[5], 14, 10 + $i * 3 );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'         => $demo->local( $ts ),
					'post_date_gmt'     => $demo->gmt( $ts ),
					'post_modified'     => $demo->local( $ts ),
					'post_modified_gmt' => $demo->gmt( $ts ),
				),
				array( 'ID' => (int) $id )
			);
			clean_post_cache( (int) $id );
			$made[] = (int) $id;
		}
		if ( function_exists( 'srm_flush_cache' ) ) {
			srm_flush_cache();
		}
		$demo->remember( 'redirects', 'srm', $made );
		$demo->log( count( $made ) . ' Safe Redirect Manager rules' );
	}

	/*
	 * Simple 301 Redirects: the pages of the shop's first, hand-built site.
	 */
	if ( class_exists( 'Simple301Redirects' ) ) {
		$old_site = array(
			'/index.html'            => '/',
			'/about-us.html'         => '/about/',
			'/contact.html'          => '/contact/',
			'/wholesale.html'        => '/wholesale/',
			'/workshops.html'        => '/workshops/',
			'/plant-club.html'       => '/plant-club/',
			'/products/*'            => '/product/*',
		);
		$rules = (array) get_option( '301_redirects', array() );
		update_option( '301_redirects', array_merge( $rules, $old_site ) );
		update_option( '301_redirects_wildcard', 'true' );
		$demo->remember( 'redirects', 's301', array_keys( $old_site ) );
		$demo->log( count( $old_site ) . ' Simple 301 Redirects rules' );
	}

	/*
	 * 301 Redirects: campaign links and a few old product addresses. Its 404
	 * log is the eps_redirects_404_log option (newest first, 50 kept), which
	 * its own 404 handler appends to; the seeded entries are told apart from
	 * live ones by url and timestamp.
	 */
	if ( class_exists( 'EPS_Redirects' ) && $table( 'redirects' ) ) {
		$anthurium      = $demo->id( 'products', 'anthurium' );
		$anthurium_post = $demo->id( 'posts', 'meet-the-anthurium-a-tropical-that-blooms-all-year' );
		// from (no leading slash), to (a post id makes a type "post" rule), status, hits
		$rules = array(
			array( 'specials', '/shop/', '301', 34 ),
			array( 'product/anthurium-plant', $anthurium ? (string) $anthurium : '/product/anthurium/', '301', 19 ),
			array( 'care-sheets/anthurium.pdf', $anthurium_post ? (string) $anthurium_post : '/category/plant-care/', '301', 23 ),
			array( 'news', '/category/shop-news/', '301', 11 ),
			array( 'workshops/kokedama', '/product/workshop/', '302', 8 ),
			array( 'gift-guide-2025', '/shop/', 'inactive', 52 ),
		);
		$made = array();
		foreach ( $rules as $r ) {
			$ok = $wpdb->insert(
				"{$p}redirects",
				array(
					'url_from' => $r[0],
					'url_to'   => $r[1],
					'status'   => $r[2],
					'type'     => is_numeric( $r[1] ) ? 'post' : 'url',
					'count'    => $r[3],
				)
			);
			if ( $ok ) {
				$made[] = (int) $wpdb->insert_id;
			}
		}
		$demo->remember( 'redirects', 'eps', $made );

		// Its own timestamps are current_time( 'timestamp' ): site-local seconds.
		$offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$seeded = array();
		foreach ( array(
			array( '/plants/monstera-albo/', 40 ),
			array( '/wp-content/uploads/2023/price-list.pdf', 260 ),
			array( '/shop/gift-card/', 900 ),
			array( '/plants/monstera-albo/', 1700 ),
			array( '/workshop/kokedama-spring/', 2400 ),
			array( '/journal/feed/', 4100 ),
			array( '/plants/monstera-albo/', 6800 ),
		) as $m ) {
			$seeded[] = array(
				'timestamp'  => $mins( $m[1] ) + $offset,
				'url'        => $m[0],
				'user_agent' => $pick( $agents ),
			);
		}
		$log = get_option( 'eps_redirects_404_log', array() );
		$log = is_array( $log ) ? $log : array();
		$log = array_values(
			array_filter(
				$log,
				function ( $e ) use ( $eps_404_old ) {
					return ! in_array( ( $e['url'] ?? '' ) . '@' . ( $e['timestamp'] ?? '' ), $eps_404_old, true );
				}
			)
		);
		$log = array_merge( $log, $seeded );
		usort(
			$log,
			function ( $a, $b ) {
				return (int) $b['timestamp'] - (int) $a['timestamp'];
			}
		);
		update_option( 'eps_redirects_404_log', array_slice( $log, 0, 50 ) );
		$demo->remember(
			'redirects',
			'eps_404',
			array_map(
				function ( $e ) {
					return $e['url'] . '@' . $e['timestamp'];
				},
				$seeded
			)
		);
		$demo->log( count( $made ) . ' 301 Redirects rules, ' . count( $seeded ) . ' 404s in its log' );
	}

	/*
	 * Broken Link Checker (local engine): links in the journal and the shop's
	 * pages, as its checker left them.
	 */
	if ( $table( 'blc_links' ) && $table( 'blc_instances' ) && $table( 'blc_synch' ) ) {
		$post = function ( $group, $slug ) use ( $demo ) {
			return (int) $demo->id( $group, $slug );
		};
		$head = function ( $code, $text, array $lines ) {
			return "=== HTTP code : {$code} ===\n\nResponse headers\n================\nHTTP/1.1 {$code} {$text}\r\n" . implode( "\r\n", $lines ) . "\r\n\r\n";
		};
		// url, where (group, slug), link text, state, checked (minutes ago), extras
		$links = array(
			array(
				'url'   => 'https://www.example.com/guides/peat-alternatives.pdf',
				'where' => array( 'posts', 'why-we-switched-to-peat-free-potting-mix' ),
				'text'  => 'a longer guide to peat alternatives',
				'state' => 'broken',
				'ago'   => 180,
				'since' => 9,
				'code'  => 404,
				'log'   => $head( 404, 'Not Found', array( 'content-type: text/html; charset=UTF-8', 'content-length: 1256' ) ) . 'Link is broken.',
			),
			array(
				'url'   => 'https://portland-seed-swap.example/spring/',
				'where' => array( 'posts', 'sowing-sunflowers-with-kids' ),
				'text'  => 'the spring seed swap',
				'state' => 'broken',
				'ago'   => 420,
				'since' => 21,
				'code'  => 0,
				'status_code' => 'error',
				'status_text' => 'Server Not Found',
				'log'   => "Could not resolve host: portland-seed-swap.example\n\nLink is broken.",
			),
			array(
				'url'   => $home . '/wp-content/uploads/2025/03/kokedama-care-sheet.pdf',
				'where' => array( 'pages', 'faq' ),
				'text'  => 'kokedama care sheet',
				'state' => 'broken',
				'ago'   => 95,
				'since' => 4,
				'code'  => 404,
				'log'   => $head( 404, 'Not Found', array( 'content-type: text/html; charset=UTF-8', 'server: Caddy' ) ) . 'Link is broken.',
			),
			array(
				'url'     => 'https://plantclinic.example.net/ask',
				'where'   => array( 'pages', 'plant-club' ),
				'text'    => 'ask the plant clinic',
				'state'   => 'broken',
				'ago'     => 610,
				'since'   => 13,
				'code'    => 0,
				'timeout' => 1,
				'log'     => "Request timed out after 30 seconds.\n\nLink is broken.",
			),
			array(
				'url'       => 'https://www.example.org/pollinators/native-bees',
				'where'     => array( 'posts', 'repotting-without-the-mess' ),
				'text'      => 'native bees',
				'state'     => 'broken',
				'dismissed' => 1,
				'ago'       => 1500,
				'since'     => 33,
				'code'      => 410,
				'log'       => $head( 410, 'Gone', array( 'content-type: text/html' ) ) . 'Link is broken.',
			),
			array(
				'url'   => 'https://www.example.net/weather/portland-frost-dates',
				'where' => array( 'pages', 'visit-the-shop' ),
				'text'  => 'local frost dates',
				'state' => 'warning',
				'ago'   => 75,
				'since' => 1,
				'code'  => 503,
				'log'   => $head( 503, 'Service Unavailable', array( 'retry-after: 120', 'content-type: text/html' ) ) . "The server is temporarily unavailable. This could be a temporary glitch; it will be checked again.",
			),
			array(
				'url'   => $home . '/plants/anthurium-red/',
				'final' => $home . '/product/anthurium/',
				'where' => array( 'pages', 'faq' ),
				'text'  => 'red anthuriums',
				'state' => 'redirect',
				'ago'   => 340,
				'code'  => 200,
				'log'   => $head( 301, 'Moved Permanently', array( 'location: ' . $home . '/product/anthurium/', 'x-redirect-by: redirection' ) ) . $head( 200, 'OK', array( 'content-type: text/html; charset=UTF-8' ) ) . 'Link is valid.',
			),
			array(
				'url'   => $home . '/store/',
				'final' => $home . '/shop/',
				'where' => array( 'pages', 'shipping-returns' ),
				'text'  => 'our shop',
				'state' => 'redirect',
				'ago'   => 1100,
				'code'  => 200,
				'log'   => $head( 301, 'Moved Permanently', array( 'location: ' . $home . '/shop/', 'x-redirect-by: redirection' ) ) . $head( 200, 'OK', array( 'content-type: text/html; charset=UTF-8' ) ) . 'Link is valid.',
			),
			array(
				'url'   => 'http://www.example.com/wholesale-terms',
				'final' => 'https://www.example.com/wholesale-terms/',
				'where' => array( 'pages', 'wholesale' ),
				'text'  => 'standard wholesale terms',
				'state' => 'redirect',
				'ago'   => 2300,
				'code'  => 200,
				'log'   => $head( 301, 'Moved Permanently', array( 'location: https://www.example.com/wholesale-terms/' ) ) . $head( 200, 'OK', array( 'content-type: text/html; charset=UTF-8' ) ) . 'Link is valid.',
			),
			array(
				'url'   => $home . '/product/meadow-mix/',
				'where' => array( 'posts', 'sowing-sunflowers-with-kids' ),
				'text'  => 'wildflower meadow mix',
				'state' => 'ok',
				'ago'   => 2800,
				'code'  => 200,
				'log'   => $head( 200, 'OK', array( 'content-type: text/html; charset=UTF-8' ) ) . 'Link is valid.',
			),
		);
		$link_ids     = array();
		$instance_ids = array();
		$containers   = array();
		foreach ( $links as $l ) {
			$container = $post( $l['where'][0], $l['where'][1] );
			if ( ! $container ) {
				continue;
			}
			$checked = $mins( $l['ago'] );
			$broken  = 'broken' === $l['state'];
			$final   = isset( $l['final'] ) ? $l['final'] : $l['url'];
			$row     = array(
				'url'                => $l['url'],
				'url_hash'           => md5( $l['url'] ),
				'first_failure'      => $broken || 'warning' === $l['state'] ? $demo->gmt( $demo->ago( $l['since'], 3, 12 ) ) : '0000-00-00 00:00:00',
				'last_check'         => $demo->gmt( $checked ),
				'last_success'       => $broken || 'warning' === $l['state'] ? $demo->gmt( $demo->ago( $l['since'] + 3, 3, 12 ) ) : $demo->gmt( $checked ),
				'last_check_attempt' => $demo->gmt( $checked ),
				'check_count'        => $broken ? 3 : ( 'warning' === $l['state'] ? 1 : 0 ),
				'final_url'          => $final,
				'redirect_count'     => 'redirect' === $l['state'] ? 1 : 0,
				'log'                => $l['log'],
				'http_code'          => $l['code'],
				'status_code'        => isset( $l['status_code'] ) ? $l['status_code'] : '',
				'status_text'        => isset( $l['status_text'] ) ? $l['status_text'] : '',
				'request_duration'   => ! empty( $l['timeout'] ) ? 30.0 : mt_rand( 80, 1400 ) / 1000,
				'timeout'            => ! empty( $l['timeout'] ) ? 1 : 0,
				'broken'             => $broken ? 1 : 0,
				'warning'            => 'warning' === $l['state'] ? 1 : 0,
				'may_recheck'        => 1,
				'being_checked'      => 0,
				'result_hash'        => implode( '|', array( $l['code'], $broken ? 1 : 0, ! empty( $l['timeout'] ) ? 1 : 0, $final ) ),
				'false_positive'     => 0,
				'dismissed'          => ! empty( $l['dismissed'] ) ? 1 : 0,
			);
			// The same address may already be known to the checker from a real scan.
			$wpdb->delete( "{$p}blc_links", array( 'url_hash' => $row['url_hash'] ) );
			if ( ! $wpdb->insert( "{$p}blc_links", $row ) ) {
				continue;
			}
			$link_id    = (int) $wpdb->insert_id;
			$link_ids[] = $link_id;
			$type       = get_post_type( $container );
			$wpdb->insert(
				"{$p}blc_instances",
				array(
					'link_id'         => $link_id,
					'container_id'    => $container,
					'container_type'  => $type,
					'link_text'       => $l['text'],
					'parser_type'     => 'link',
					'container_field' => 'post_content',
					'link_context'    => '',
					'raw_url'         => $l['url'],
				)
			);
			$instance_ids[]                    = (int) $wpdb->insert_id;
			$containers[ $type . ':' . $container ] = array( $type, $container );
		}
		foreach ( $containers as $c ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}blc_synch (container_id, container_type, synched, last_synch) VALUES (%d, %s, 1, %s) ON DUPLICATE KEY UPDATE synched = 1", $c[1], $c[0], $demo->local( $demo->ago( 1, 3, 10 ) ) ) );
		}
		$demo->remember( 'redirects', 'blc_links', $link_ids );
		$demo->remember( 'redirects', 'blc_instances', $instance_ids );
		$demo->log( count( $link_ids ) . ' links in Broken Link Checker' );
	}
};
