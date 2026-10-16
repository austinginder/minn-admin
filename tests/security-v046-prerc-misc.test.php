<?php
/**
 * Pre-RC regressions: saves that rewrote a value nobody changed, in the
 * WooCommerce settings forms, the redirect, snippet, events and podcast
 * adapters, and the tiled gallery rebuild.
 *
 * Each section seeds a value the vendor's own store can hold and the old save
 * path changed (a WooCommerce field someone cleared, a percent-encoded
 * redirect, Rank Math sources in their own key order and a relative target,
 * FluentSnippets tags joined with "," and indented code, a WPCode text
 * snippet holding an embed, an event cost holding an entity, a PowerPress
 * extra holding a backslash, a tiled gallery photo with its own alt text and
 * custom link), sends what Minn's client sends for an edit of ANOTHER field,
 * and checks the stored bytes. A control in each checks that a real edit is
 * still cleaned and written.
 *
 * Database seeds (the WooCommerce test zone among them) run inside a
 * transaction that is rolled back; the FluentSnippets file and the Simple 301
 * option are put back however the section ends. Sections SKIP when their
 * plugin is inactive. 301 Redirects, Simple 301 Redirects and PowerPress rest
 * inactive on the dev site, so the swap loads them for one process.
 *
 * Rank Math builds its redirections module only on a site connected to a
 * Rank Math account or with its setup skipped, and only with the module
 * switched on, which the dev site is not. Passed to --require as well, this
 * file answers both for the one process before WordPress loads (nothing is
 * written) and runs its checks as the eval-file.
 *
 * Run: MINN_SWAP_ADD=eps-301-redirects/eps-301-redirects.php,simple-301-redirects/wp-simple-301-redirects.php,powerpress/powerpress.php \
 *      wp eval-file tests/security-v046-prerc-misc.test.php --user=admin --path=<site> \
 *      --require=tests/lib/plugin-swap.php --require=tests/security-v046-prerc-misc.test.php
 *
 * @package minn-admin
 */

if ( ! function_exists( 'rest_get_server' ) ) {
	if ( class_exists( 'WP_CLI' ) ) {
		// An option the site never stored is answered by its default_ hook.
		foreach ( array( 'option_rank_math_registration_skip', 'default_option_rank_math_registration_skip' ) as $hook ) {
			WP_CLI::add_wp_hook( $hook, function () {
				return 1;
			} );
		}
		WP_CLI::add_wp_hook( 'option_rank_math_modules', function ( $modules ) {
			return array_values( array_unique( array_merge( is_array( $modules ) ? $modules : array(), array( 'redirections' ) ) ) );
		} );
	}
	return;
}

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null, array $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = function ( callable $fn ) {
	$done = false;
	$run  = function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

/*
 * WooCommerce emails, payment gateways and shipping methods: a field someone
 * cleared reads back empty and stays empty when another field is saved. The
 * form read each value with WooCommerce's default as the "empty value", so a
 * cleared field was shown, posted and saved as the default text.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'minn_admin_wc_settings_email' ) ) {
		$skip( 'WooCommerce settings inactive' );
		return;
	}
	$wpdb->query( 'START TRANSACTION' );
	try {
		$email = minn_admin_wc_settings_email( 'customer_completed_order' );
		$field = $email ? (array) ( $email->get_form_fields()['additional_content'] ?? array() ) : array();
		if ( ! $email || '' === (string) ( $field['default'] ?? '' ) ) {
			$skip( 'WC email: no completed-order email with a default additional content' );
		} else {
			$key                         = $email->get_option_key();
			$opt                         = (array) get_option( $key, array() );
			$opt['additional_content']   = '';
			update_option( $key, $opt );
			$email->init_settings();
			list( , $form ) = $call( 'GET', '/minn-admin/v1/wc/emails/customer_completed_order' );
			$check( 'WC email: a cleared field reads back empty, as stored', '' === ( $form['values']['additional_content'] ?? null ), wp_json_encode( $form['values']['additional_content'] ?? null ) );
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/wc/emails/customer_completed_order', array( 'values' => array( 'heading' => 'Minn prerc heading' ) ) );
			$now = (array) get_option( $key, array() );
			$check( 'WC email: an edit of the heading answers 200 and writes it (control)', 200 === $st && 'Minn prerc heading' === ( $now['heading'] ?? null ), $st . ' ' . wp_json_encode( $now['heading'] ?? $d ) );
			$check( 'WC email: and keeps the cleared additional content empty', '' === ( $now['additional_content'] ?? null ), wp_json_encode( $now['additional_content'] ?? null ) );
		}

		$gateway = minn_admin_wc_settings_gateway( 'cheque' );
		$gfield  = $gateway ? (array) ( $gateway->get_form_fields()['description'] ?? array() ) : array();
		if ( ! $gateway || '' === (string) ( $gfield['default'] ?? '' ) ) {
			$skip( 'WC gateway: no cheque gateway with a default description' );
		} else {
			$key                = $gateway->get_option_key();
			$opt                = (array) get_option( $key, array() );
			$opt['description'] = '';
			update_option( $key, $opt );
			$gateway->init_settings();
			list( , $form ) = $call( 'GET', '/minn-admin/v1/wc/payment_gateways/cheque' );
			$check( 'WC gateway: a cleared description reads back empty, as stored', '' === ( $form['values']['description'] ?? null ), wp_json_encode( $form['values']['description'] ?? null ) );
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/wc/payment_gateways/cheque', array( 'values' => array( 'instructions' => 'Minn prerc instructions' ) ) );
			$now = (array) get_option( $key, array() );
			$check( 'WC gateway: an edit of the instructions answers 200 and writes them (control)', 200 === $st && 'Minn prerc instructions' === ( $now['instructions'] ?? null ), $st . ' ' . wp_json_encode( $now['instructions'] ?? $d ) );
			$check( 'WC gateway: and keeps the cleared description empty', '' === ( $now['description'] ?? null ), wp_json_encode( $now['description'] ?? null ) );
		}

		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			$skip( 'WC shipping: no shipping zones' );
		} else {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name( 'Minn prerc zone' );
			$zone->save();
			$instance = (int) $zone->add_shipping_method( 'flat_rate' );
			if ( ! $instance ) {
				$skip( 'WC shipping: flat rate would not attach to a test zone' );
			} else {
				$key = 'woocommerce_flat_rate_' . $instance . '_settings';
				update_option( $key, array( 'title' => '', 'tax_status' => 'taxable', 'cost' => '5' ) );
				$path = '/minn-admin/v1/wc/shipping/zones/' . (int) $zone->get_id() . '/methods/' . $instance;
				list( , $form ) = $call( 'GET', $path );
				$check( 'WC shipping: a cleared method title reads back empty, as stored', '' === ( $form['values']['title'] ?? null ), wp_json_encode( $form['values']['title'] ?? null ) );
				list( $st, $d ) = $call( 'POST', $path, array( 'values' => array( 'cost' => '7' ) ) );
				$now = (array) get_option( $key, array() );
				$check( 'WC shipping: an edit of the cost answers 200 and writes it (control)', 200 === $st && '7' === (string) ( $now['cost'] ?? '' ), $st . ' ' . wp_json_encode( $now['cost'] ?? $d ) );
				$check( 'WC shipping: and keeps the cleared title empty', '' === ( $now['title'] ?? null ), wp_json_encode( $now['title'] ?? null ) );
			}
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * 301 Redirects (WebFactory): a source and target the edit leaves alone keep
 * their stored bytes. Their CSV import and their v1 migration store them
 * undecoded, and the save decoded and cleaned both sides ('caf%C3%A9' became
 * 'café', '?q=c%2B%2B' became '?q=c++').
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'minn_admin_eps301_active' ) || ! minn_admin_eps301_active() ) {
		$skip( '301 Redirects inactive (load it with MINN_SWAP_ADD=eps-301-redirects/eps-301-redirects.php)' );
		return;
	}
	if ( ! Minn_Admin_Kernel_SQL::exists( 'redirects' ) ) {
		$skip( '301 Redirects has no table here' );
		return;
	}
	$table = $wpdb->prefix . 'redirects';
	$from  = 'minn-prerc/caf%C3%A9';
	$to    = 'https://example.com/find?q=c%2B%2B&amp;x=1';
	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->insert( $table, array( 'url_from' => $from, 'url_to' => $to, 'status' => '301', 'type' => 'url', 'count' => 0 ) );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			$check( '301 Redirects: a rule is seeded for the check', false, $wpdb->last_error );
			return;
		}
		$served = null;
		list( , $list ) = $call( 'GET', '/minn-admin/v1/eps301/redirects', null, array( 'search' => 'minn-prerc', 'per_page' => 50 ) );
		foreach ( (array) ( $list['items'] ?? array() ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				$served = $row;
			}
		}
		$check( '301 Redirects: the list serves the seeded rule', is_array( $served ), wp_json_encode( $list ) );
		$served = is_array( $served ) ? $served : minn_admin_eps301_item( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore
		$route  = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'eps301/redirects', 'edit' ) );
		$row    = function () use ( $wpdb, $table, $id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT url_from, url_to, status, type FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		};
		list( $st, $d ) = $call( 'PUT', $route, array( 'from' => $served['from'], 'to' => $served['to'], 'status' => '302' ) );
		$now = $row();
		$check( '301 Redirects: a status-only edit answers 200 and writes the status (control)', 200 === $st && '302' === $now['status'], $st . ' ' . wp_json_encode( $d ) );
		$check( '301 Redirects: and keeps the stored source byte for byte', $from === $now['url_from'], wp_json_encode( $now['url_from'] ) );
		$check( '301 Redirects: and keeps the stored target byte for byte', $to === $now['url_to'], wp_json_encode( $now['url_to'] ) );
		list( $st, $d ) = $call( 'PUT', $route, array( 'status' => 'inactive' ) );
		$now = $row();
		$check( '301 Redirects: a body naming only the status keeps the source and target as stored', 200 === $st && 'inactive' === $now['status'] && $from === $now['url_from'] && $to === $now['url_to'], $st . ' ' . wp_json_encode( $now ?: $d ) );
		list( $st ) = $call( 'PUT', $route, array( 'from' => $served['from'], 'to' => '/minn <b>new</b>%20page', 'status' => '302' ) );
		$now = $row();
		$check( '301 Redirects: a changed target is decoded and cleaned as their form does (control)', 200 === $st && '/minn new page' === $now['url_to'], $st . ' ' . wp_json_encode( $now ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * Simple 301 Redirects: a source or target the edit leaves alone keeps its
 * stored bytes. sanitize_text_field() takes percent octets out, so an
 * untouched '/caf%C3%A9' became '/caf', renaming the rule.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_s301_active' ) || ! minn_admin_s301_active() ) {
		$skip( 'Simple 301 Redirects inactive (load it with MINN_SWAP_ADD=simple-301-redirects/wp-simple-301-redirects.php)' );
		return;
	}
	$sentinel = new stdClass();
	$was      = get_option( '301_redirects', $sentinel );
	$restore  = $once( function () use ( $was, $sentinel ) {
		if ( $was === $sentinel ) {
			delete_option( '301_redirects' );
		} else {
			update_option( '301_redirects', $was );
		}
	} );
	try {
		$from = '/minn-prerc/caf%C3%A9';
		$to   = '/menu?deal=50%DEAL';
		$rows = is_array( $was ) ? $was : array();
		unset( $rows['/minn-prerc/moved'] );
		$rows[ $from ] = $to;
		update_option( '301_redirects', $rows );
		$id = minn_admin_s301_encode_id( $from );
		list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => $from, 'to' => 'https://example.com/new' ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a target-only edit answers 200 and writes the target (control)', 200 === $st && in_array( 'https://example.com/new', $now, true ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'Simple 301: and keeps the stored source as the rule key', array_key_exists( $from, $now ) && ! array_key_exists( '/minn-prerc/caf', $now ), wp_json_encode( array_keys( $now ) ) );
		$now[ $from ] = $to;
		update_option( '301_redirects', $now );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => '/minn-prerc/moved', 'to' => $to ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a source-only edit answers 200 and renames the rule (control)', 200 === $st && array_key_exists( '/minn-prerc/moved', $now ) && ! array_key_exists( $from, $now ), $st . ' ' . wp_json_encode( array_keys( $now ) ) );
		$check( 'Simple 301: and keeps the stored target byte for byte', $to === ( $now['/minn-prerc/moved'] ?? null ), wp_json_encode( $now['/minn-prerc/moved'] ?? null ) );
		list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/s301/redirects/' . minn_admin_s301_encode_id( '/minn-prerc/moved' ), array( 'to' => '/menu?deal=50%DEAL&v=2' ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a body naming only the target keeps the source as stored', 200 === $st && array_key_exists( '/minn-prerc/moved', $now ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$restore();
	}
} )();

/*
 * Rank Math redirections: a target-and-type edit writes the sources back as
 * their column holds them and a target sent back as stored keeps its bytes.
 * The save rebuilt the sources from the reader's entity-decoded copy in Minn's
 * key order, so their lookup by serialized sources (their save of every new
 * rule, their settings import) no longer found the rule, and their model made
 * a relative target absolute.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'minn_admin_rm_redirects_ready' ) || ! minn_admin_rm_redirects_ready() ) {
		$skip( 'Rank Math redirections inactive (pass this file to --require as well, see the header)' );
		return;
	}
	$table   = $wpdb->prefix . 'rank_math_redirections';
	$sources = serialize( array( array( 'ignore' => '', 'pattern' => 'minn-prerc/a&#38;b', 'comparison' => 'contains' ) ) ); // phpcs:ignore -- their storage format
	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->insert( $table, array( 'sources' => $sources, 'url_to' => '/minn-prerc-target', 'header_code' => '301', 'hits' => 0, 'status' => 'active', 'created' => current_time( 'mysql' ), 'updated' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			$check( 'Rank Math: a redirect is seeded for the check', false, $wpdb->last_error );
			return;
		}
		$route = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'rankmath/redirects', 'edit' ) );
		$item = null;
		list( , $list ) = $call( 'GET', '/minn-admin/v1/rankmath/redirects', null, array( 'search' => 'minn-prerc', 'per_page' => 50 ) );
		foreach ( (array) ( $list['items'] ?? array() ) as $r ) {
			if ( (int) ( $r['id'] ?? 0 ) === $id ) {
				$item = $r;
			}
		}
		$check( 'Rank Math: the list serves the stored target', '/minn-prerc-target' === ( $item['url_to'] ?? null ), wp_json_encode( $item ?? $list ) );
		list( $st, $d ) = $call( 'POST', $route, array( 'url_to' => $item['url_to'] ?? '/minn-prerc-target', 'header_code' => '302' ) );
		$now = $wpdb->get_row( $wpdb->prepare( "SELECT sources, url_to, header_code FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		$check( 'Rank Math: a type-only edit answers 200 and writes the type (control)', 200 === $st && '302' === (string) $now['header_code'], $st . ' ' . wp_json_encode( $d ) );
		$check( 'Rank Math: and keeps the sources column byte for byte', $sources === $now['sources'], wp_json_encode( $now['sources'] ) );
		$found = \RankMath\Redirections\DB::match_redirections_source( $sources );
		$check( 'Rank Math: so their lookup by serialized sources still finds the rule', $found && (int) ( $found[0]['id'] ?? 0 ) === $id, wp_json_encode( $found ) );
		$check( 'Rank Math: and keeps the relative target as stored', '/minn-prerc-target' === $now['url_to'], wp_json_encode( $now['url_to'] ) );
		list( $st ) = $call( 'POST', $route, array( 'url_to' => '/minn-prerc-moved', 'header_code' => '302' ) );
		$moved = (string) $wpdb->get_var( $wpdb->prepare( "SELECT url_to FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		$check( 'Rank Math: a changed target goes through their model, made absolute (control)', 200 === $st && home_url( '/minn-prerc-moved' ) === $moved, $st . ' ' . wp_json_encode( $moved ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * FluentSnippets: an edit of the priority, or the /active switch, writes the
 * code and the tags back as their file holds them. The read took every
 * whitespace character after "<?php" (the first line's indentation and any
 * blank lines) and each save wrote the shortened code back; the tags compare
 * joined the served list with ", " while their own form stores ",".
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_fluent_ready' ) || ! minn_admin_fluent_ready() ) {
		$skip( 'FluentSnippets inactive' );
		return;
	}
	// A draft, so nothing it holds ever runs; its file goes however the
	// section ends.
	list( $st, $made ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn prerc', 'desc' => 'x', 'code' => '// minn suite', 'type' => 'PHP', 'run_at' => 'all', 'active' => false ) );
	$file = is_array( $made ) ? (string) ( $made['id'] ?? $made['file'] ?? '' ) : '';
	if ( ( 201 !== $st && 200 !== $st ) || '' === $file ) {
		$check( 'FluentSnippets: a draft snippet is created for the check', false, $st . ' ' . wp_json_encode( $made ) );
		return;
	}
	$clean = $once( function () use ( $call, $file ) {
		$call( 'DELETE', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
	} );
	try {
		$model  = new \FluentSnippets\App\Model\Snippet();
		$stored = $model->findByFileName( $file );
		$code   = '<?php' . PHP_EOL . "    // minn prerc: an indented first line\n\n// and a blank line before this one";
		$meta   = (array) $stored['meta'];
		// Their own form's shape: the tag list joined with ",", the file
		// written as their status switch writes it.
		$meta['tags'] = 'alpha,beta';
		$model->updateSnippet( $file, $code, $meta );
		$read = function () use ( $model, $file ) {
			$s = $model->findByFileName( $file );
			return is_wp_error( $s ) ? array( array(), '' ) : array( (array) $s['meta'], (string) $s['code'] );
		};
		list( , $item ) = $call( 'GET', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
		$check( 'FluentSnippets: the item serves the code with its first-line indentation', "    // minn prerc: an indented first line\n\n// and a blank line before this one" === ( $item['code'] ?? null ), wp_json_encode( $item['code'] ?? null ) );
		$body = array();
		foreach ( array( 'name', 'desc', 'code', 'type', 'run_at', 'tags' ) as $k ) {
			$body[ $k ] = $item[ $k ] ?? null;
		}
		list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), $body + array( 'priority' => 15 ) );
		list( $m, $c ) = $read();
		$check( 'FluentSnippets: a priority-only edit answers 200 and writes the priority (control)', 200 === $st && 15 === (int) ( $m['priority'] ?? 0 ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'FluentSnippets: and keeps the stored code byte for byte', $code === $c, wp_json_encode( $c ) );
		$check( 'FluentSnippets: and keeps the stored tags line', 'alpha,beta' === ( $m['tags'] ?? null ), wp_json_encode( $m['tags'] ?? null ) );
		$model->updateSnippet( $file, $code, $meta );
		list( $st ) = $call( 'POST', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) . '/active', array( 'active' => false ) );
		list( , $c ) = $read();
		$check( 'FluentSnippets: the active switch answers 200 and keeps the stored code byte for byte', 200 === $st && $code === $c, $st . ' ' . wp_json_encode( $c ) );
		list( $st ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'tags' => array( 'gamma <b>x</b>' ) ) );
		list( $m ) = $read();
		$check( 'FluentSnippets: a real tag edit is cleaned and written (control)', 200 === $st && 'gamma x' === ( $m['tags'] ?? null ), $st . ' ' . wp_json_encode( $m['tags'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * WPCode: a text snippet's code the edit leaves alone is not run through
 * kses for a caller without unfiltered_html. Their own form compensates for
 * the kses it turns off only on code that is submitted; Minn's dialog always
 * sends the code, so an untouched embed an administrator wrote was stripped
 * on an edit of the priority, and so was the stored code when the type came
 * back unchanged without it.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'wpcode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
		$skip( 'WPCode inactive' );
		return;
	}
	$frame = '<iframe src="https://www.youtube.com/embed/minn" width="560" height="315"></iframe>';
	$embed = "<p>Watch:</p>\r\n" . $frame;
	$deny  = function ( $caps, $cap ) {
		return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	$wpdb->query( 'START TRANSACTION' );
	try {
		$snippet = new WPCode_Snippet( array( 'title' => 'Minn prerc text', 'code' => wp_slash( $embed ), 'code_type' => 'text', 'active' => false, 'priority' => 10 ) );
		$id      = (int) $snippet->save();
		if ( $id <= 0 || $embed !== get_post( $id )->post_content ) {
			$check( 'WPCode: a text snippet holding an embed is seeded for the check', false, wp_json_encode( $id ? get_post( $id )->post_content : $id ) );
			return;
		}
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		list( , $item ) = $call( 'GET', "/minn-admin/v1/wpcode/snippets/{$id}" );
		$body = array();
		foreach ( array( 'name', 'desc', 'code', 'code_type', 'auto_insert', 'location', 'tags' ) as $k ) {
			$body[ $k ] = $item[ $k ] ?? null;
		}
		// A textarea hands its line breaks back as \n.
		$body['code'] = str_replace( "\r\n", "\n", (string) $body['code'] );
		list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", $body + array( 'priority' => 13 ) );
		clean_post_cache( $id );
		$check( 'WPCode: a priority-only edit without unfiltered_html answers 200 and writes it (control)', 200 === $st && 13 === (int) get_post_meta( $id, '_wpcode_priority', true ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'WPCode: and keeps the stored code byte for byte, line breaks included', $embed === get_post( $id )->post_content, wp_json_encode( get_post( $id )->post_content ) );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", array( 'code_type' => 'text', 'priority' => 14 ) );
		clean_post_cache( $id );
		$check( 'WPCode: an unchanged type sent without code keeps the stored code', 200 === $st && $embed === get_post( $id )->post_content, $st . ' ' . wp_json_encode( get_post( $id )->post_content ) );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", array( 'code' => '<p>New</p>' . $frame ) );
		clean_post_cache( $id );
		$check( 'WPCode: changed code without unfiltered_html is still filtered (control)', 200 === $st && '<p>New</p>' === get_post( $id )->post_content, $st . ' ' . wp_json_encode( get_post( $id )->post_content ) );
	} finally {
		remove_filter( 'map_meta_cap', $deny, 10 );
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * The Events Calendar: an edit of the end time leaves a cost and a website
 * nobody touched as stored. saveEventMeta() runs every string through
 * tec_sanitize_string() (entity decode, then strip_tags), so the cost the
 * block editor stored as 'Under &lt;$20' came out as 'Under '.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! class_exists( 'Tribe__Events__API' ) || ! function_exists( 'minn_admin_tec_panel_values' ) ) {
		$skip( 'The Events Calendar inactive' );
		return;
	}
	$cost = 'Under &lt;$20 &amp; free for kids';
	$url  = 'https://example.com/tickets?a=1&amp;b=2';
	$wpdb->query( 'START TRANSACTION' );
	try {
		$id = (int) Tribe__Events__API::createEvent( array( 'post_title' => 'Minn prerc event', 'post_status' => 'draft', 'EventStartDate' => '2031-03-04', 'EventStartTime' => '10:00:00', 'EventEndDate' => '2031-03-04', 'EventEndTime' => '11:00:00' ) );
		if ( $id <= 0 ) {
			$check( 'TEC: an event is seeded for the check', false, (string) $id );
			return;
		}
		update_post_meta( $id, '_EventCost', wp_slash( $cost ) );
		update_post_meta( $id, '_EventURL', wp_slash( $url ) );
		$served = minn_admin_tec_panel_values( $id );
		$end    = gmdate( 'Y-m-d H:i', strtotime( (string) $served['end'] ) + HOUR_IN_SECONDS );
		list( $st, $d ) = $call( 'POST', "/wp/v2/tribe_events/{$id}", array( 'minn_tec' => array( 'end' => $end ) ) );
		$check( 'TEC: an end-time edit answers 200 and writes the end (control)', 200 === $st && 0 === strpos( (string) get_post_meta( $id, '_EventEndDate', true ), $end ), $st . ' ' . wp_json_encode( get_post_meta( $id, '_EventEndDate', true ) ?: $d ) );
		$check( 'TEC: and keeps the stored cost byte for byte', array( $cost ) === get_post_meta( $id, '_EventCost' ), wp_json_encode( get_post_meta( $id, '_EventCost' ) ) );
		$check( 'TEC: and keeps the stored website byte for byte', $url === get_post_meta( $id, '_EventURL', true ), wp_json_encode( get_post_meta( $id, '_EventURL', true ) ) );
		list( $st ) = $call( 'POST', "/wp/v2/tribe_events/{$id}", array( 'minn_tec' => array( 'cost' => 'Free <b>entry</b>' ) ) );
		$check( 'TEC: a changed cost is cleaned and written (control)', 200 === $st && 'Free entry' === get_post_meta( $id, '_EventCost', true ), $st . ' ' . wp_json_encode( get_post_meta( $id, '_EventCost', true ) ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * PowerPress: an edit of the season leaves an extra holding a backslash
 * readable. The blob went to update_post_meta() unslashed, so the backslash
 * came out of 'AC\DC live', its serialized length no longer matched, and
 * every extra (duration, chapters, hosting) stopped reading back. A file URL
 * sent back as stored is not run through esc_url_raw() either.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'minn_admin_powerpress_active' ) || ! minn_admin_powerpress_active() || ! minn_admin_powerpress_can_podcast() ) {
		$skip( 'PowerPress inactive (load it with MINN_SWAP_ADD=powerpress/powerpress.php)' );
		return;
	}
	$file   = 'https://example.com/minn prerc.mp3';
	$head   = $file . "\n12345\naudio/mpeg\n";
	$extras = array( 'duration' => '00:30:00', 'episode_title' => 'AC\\DC live', 'season' => '1' );
	$wpdb->query( 'START TRANSACTION' );
	try {
		$pid = wp_insert_post( array( 'post_title' => 'Minn prerc episode', 'post_status' => 'draft', 'post_type' => 'post' ) );
		if ( ! is_int( $pid ) || $pid <= 0 ) {
			$check( 'PowerPress: a post is seeded for the check', false, wp_json_encode( $pid ) );
			return;
		}
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $pid, 'meta_key' => 'enclosure', 'meta_value' => $head . serialize( $extras ) ) ); // phpcs:ignore -- their storage format
		wp_cache_delete( $pid, 'post_meta' );
		// The file URL rides along as stored, as a whole-panel resend has it.
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$pid}", array( 'minn_powerpress' => array( 'url' => $file, 'season' => '2' ) ) );
		$blob = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'enclosure'", $pid ) );
		$enc  = minn_admin_powerpress_parse( $blob );
		$check( 'PowerPress: a season edit answers 200 and writes the season (control)', 200 === $st && false !== strpos( $blob, 's:6:"season";s:1:"2";' ), $st . ' ' . wp_json_encode( $blob ) );
		$check( 'PowerPress: and every extra still reads back', 'AC\\DC live' === ( $enc['extras']['episode_title'] ?? null ) && '00:30:00' === ( $enc['extras']['duration'] ?? null ), wp_json_encode( $enc['extras'] ) );
		$check( 'PowerPress: and the file URL and every other extra are stored byte for byte', $head . serialize( array_merge( $extras, array( 'season' => '2' ) ) ) === $blob, wp_json_encode( $blob ) ); // phpcs:ignore
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * Jetpack tiled gallery: adding a photo rebuilds the gallery with the photos
 * it already held as it held them (alt text, link, file, a custom link) and
 * every block attribute but the layout. The rebuild re-read each photo from
 * the media library and dropped the custom link and imageCrop.
 */
( function () use ( $check, $skip, $call ) {
	if ( ! function_exists( 'minn_admin_tiled_gallery_rebuild' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
		$skip( 'tiled gallery adapter not loaded' );
		return;
	}
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
	if ( count( $ids ) < 2 ) {
		$skip( 'tiled gallery: fewer than two images in the library' );
		return;
	}
	// Without Jetpack the adapter offers nothing; a bare block of that name
	// stands in for this process so the real route answers.
	$registry = WP_Block_Type_Registry::get_instance();
	$stub     = ! $registry->get_registered( 'jetpack/tiled-gallery' );
	if ( $stub ) {
		register_block_type( 'jetpack/tiled-gallery', array() );
	}
	try {
		list( $a, $b ) = array_map( 'intval', $ids );
		$fig = '<figure class="tiled-gallery__item"><a href="https://example.com/minn?a=1&amp;b=2"><img alt="Tom\'s &quot;best&quot; &amp; co" data-height="600" data-id="' . $a . '" data-link="https://example.com/?attachment_id=' . $a . '" data-url="https://example.com/minn-a-800x600.jpg" data-width="800" src="https://example.com/minn-a-800x600.jpg" data-amp-layout="responsive"/></a></figure>';
		$raw = '<!-- wp:jetpack/tiled-gallery {"columnWidths":[["100.00000"]],"ids":[' . $a . '],"imageCrop":false,"linkTo":"custom"} -->' . "\n"
			. '<div class="wp-block-jetpack-tiled-gallery aligncenter is-style-rectangular"><div class=""><div class="tiled-gallery__gallery"><div class="tiled-gallery__row"><div class="tiled-gallery__col" style="flex-basis:100.00000%">' . $fig . '</div></div></div></div></div>' . "\n"
			. '<!-- /wp:jetpack/tiled-gallery -->';
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/image-block', array( 'block' => 'jetpack/tiled-gallery', 'ids' => array( $a, $b ), 'raw' => $raw ) );
		$markup = (string) ( $d['markup'] ?? '' );
		$attrs  = preg_match( '#^<!-- wp:jetpack/tiled-gallery (\{.*?\}) -->#', $markup, $m ) ? json_decode( $m[1], true ) : null;
		$check( 'tiled gallery: adding a photo answers 200 with both photos laid out (control)', 200 === $st && array( $a, $b ) === ( $attrs['ids'] ?? null ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'tiled gallery: and the photo it held comes back byte for byte (alt, link, file, custom link)', false !== strpos( $markup, $fig ), $markup );
		$check( 'tiled gallery: and the block keeps imageCrop and the custom link mode', is_array( $attrs ) && false === ( $attrs['imageCrop'] ?? null ) && 'custom' === ( $attrs['linkTo'] ?? null ), wp_json_encode( $attrs ) );
		$check( 'tiled gallery: and the added photo is read from the library (control)', 1 === preg_match( '#<img alt="' . preg_quote( esc_attr( (string) get_post_meta( $b, '_wp_attachment_image_alt', true ) ), '#' ) . '"[^>]* data-id="' . $b . '"#', $markup ), $markup );
	} finally {
		if ( $stub ) {
			unregister_block_type( 'jetpack/tiled-gallery' );
		}
	}
} )();

printf( "\nsecurity-v046-prerc-misc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
