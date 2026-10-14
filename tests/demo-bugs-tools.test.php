<?php
/**
 * Regressions for the demo-site bugs in the tools surfaces (D-29..D-34):
 * 301 Redirects, FluentSnippets, Redirection, Broken Link Checker,
 * Scrutoscope and Code Snippets.
 *
 * Each section seeds what the vendor itself stores (its option, its table
 * row, its snippet), reads it back through the route the client calls and
 * checks what the client is handed, then removes what it seeded. A vendor
 * that is installed but inactive here is loaded for one child process only
 * (an active_plugins filter, nothing written), or, where loading it would
 * schedule its own cron events, stood in for by its model's public fields
 * in that child. The Code Snippets section defines DISALLOW_FILE_EDIT,
 * which cannot be undone in this process, so it runs last.
 *
 * Run: wp eval-file tests/demo-bugs-tools.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\ndemo-bugs-tools: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run as an administrator (--user=admin)\n";
	return;
}

// The boot payload's surfaces, read fresh (the constant the last section
// defines changes what Code Snippets offers).
$surfaces_ref = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
$surfaces_ref->setAccessible( true );
$surface = function ( $id ) use ( $surfaces_ref ) {
	$surfaces_ref->setValue( null, null );
	foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
		if ( $id === $s['id'] ) {
			return $s;
		}
	}
	return null;
};
$column = function ( $s, $key ) {
	foreach ( (array) ( $s['collection']['columns'] ?? array() ) as $c ) {
		if ( $key === ( $c['key'] ?? '' ) ) {
			return $c;
		}
	}
	return null;
};
$row = function ( $sections, $label ) {
	foreach ( (array) ( $sections['sections'] ?? array() ) as $g ) {
		foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
			if ( $label === ( $r['label'] ?? null ) ) {
				return $r;
			}
		}
	}
	return null;
};
$when = function ( $ts ) {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts );
};

// One child `wp eval-file`, as this user on this site, with a --require
// file that runs before WordPress loads. It prints one MINNPROBE line.
$probe_base = realpath( sys_get_temp_dir() ) . '/minn-probe-tools-' . wp_generate_password( 8, false, false );
$child      = function ( $code, $pre ) use ( $probe_base ) {
	if ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'proc_open' ) ) {
		return array( 'error' => 'needs WP-CLI and a child process' );
	}
	wp_mkdir_p( $probe_base );
	file_put_contents( $probe_base . '/child.php', "<?php\n" . $code . "\necho 'MINNPROBE ' . wp_json_encode( \$out ) . \"\\n\";\n" );
	file_put_contents( $probe_base . '/pre.php', "<?php\n" . $pre . "\n" );
	try {
		$o = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $probe_base . '/child.php' ) . ' --require=' . escapeshellarg( $probe_base . '/pre.php' ), array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) );
	} finally {
		foreach ( array( 'child.php', 'pre.php' ) as $f ) {
			if ( is_file( $probe_base . '/' . $f ) ) {
				unlink( $probe_base . '/' . $f );
			}
		}
		if ( is_dir( $probe_base ) ) {
			rmdir( $probe_base );
		}
	}
	return preg_match( '/^MINNPROBE (.+)$/m', (string) $o->stdout, $m ) ? (array) json_decode( $m[1], true ) : array( 'error' => substr( trim( $o->stderr . ' ' . $o->stdout ), 0, 300 ) );
};

global $wpdb;
$marker = 'minn-dbt-' . strtolower( wp_generate_password( 6, false, false ) );

// --- D-29 301 Redirects: the 404 log card counts the log ---------------------
// Current versions keep the 404 log in the eps_redirects_404_log option and
// write no table rows for it, so a card counting status 404 rows always read
// 0. Older versions wrote those rows, which stay the fallback.
$eps_file = WP_PLUGIN_DIR . '/eps-301-redirects/eps-301-redirects.php';
$eps_card = '$res = rest_do_request( new WP_REST_Request( \'GET\', \'/minn-admin/v1/eps301/status\' ) ); $out = array( \'status\' => $res->get_status(), \'misses\' => null );'
	. ' foreach ( (array) ( $res->get_data()[\'rows\'] ?? array() ) as $r ) { if ( \'404 log\' === ( $r[\'label\'] ?? \'\' ) ) { $out[\'misses\'] = (string) $r[\'value\']; } }';
// The log as their check_404() writes it: newest first, site-local stamps.
$eps_log = array();
for ( $i = 0; $i < 3; $i++ ) {
	$eps_log[] = array(
		'timestamp'  => current_time( 'timestamp' ) - $i * HOUR_IN_SECONDS, // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- their shape
		'url'        => '/' . $marker . '-missing-' . $i . '/',
		'user_agent' => 'Mozilla/5.0 (minn probe)',
	);
}
$eps_log_hook = function ( $log ) {
	return "WP_CLI::add_wp_hook( 'pre_option_eps_redirects_404_log', function () { return " . var_export( $log, true ) . '; } );';
};
$eps_load = "WP_CLI::add_wp_hook( 'option_active_plugins', function ( \$p ) { \$p = (array) \$p; if ( ! in_array( 'eps-301-redirects/eps-301-redirects.php', \$p, true ) ) { \$p[] = 'eps-301-redirects/eps-301-redirects.php'; } return \$p; } );";
$eps_active = class_exists( 'EPS_Redirects' );
$eps_table  = $wpdb->prefix . 'redirects';
if ( ! $eps_active && ! is_file( $eps_file ) ) {
	$skip( 'D-29 301 Redirects is not installed' );
} elseif ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $eps_table ) ) ) {
	$skip( 'D-29 301 Redirects has no table here (it creates it on activation)' );
} else {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	// Loading it writes eps_redirects_version unless that already matches.
	$eps_version = (string) ( get_plugin_data( $eps_file, false, false )['Version'] ?? '' );
	$eps_watch   = array( 'eps_redirects_version', 'eps_pointers', '301-redirects-notices', 'eps_redirects_404_log' );
	$eps_raw     = function () use ( $wpdb, $eps_watch ) {
		$out = array();
		foreach ( $eps_watch as $name ) {
			$out[ $name ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		}
		return $out;
	};
	$eps_before = $eps_raw();
	if ( ! $eps_active && $eps_version !== (string) $eps_before['eps_redirects_version'] ) {
		$skip( 'D-29 loading 301 Redirects here would update its version option; activate it once to run this' );
	} else {
		$eps_ids = array();
		try {
			$eps_404_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$eps_table} WHERE status = '404'" ); // phpcs:ignore
			if ( $eps_active ) {
				add_filter( 'pre_option_eps_redirects_404_log', function () use ( $eps_log ) {
					return $eps_log;
				} );
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/eps301/status' );
				$got = null;
				foreach ( (array) ( $d['rows'] ?? array() ) as $r ) {
					if ( '404 log' === ( $r['label'] ?? '' ) ) {
						$got = (string) $r['value'];
					}
				}
				$a = array( 'status' => $st, 'misses' => $got );
			} else {
				$a = $child( $eps_card, $eps_load . "\n" . $eps_log_hook( $eps_log ) );
			}
			$check( 'D-29 the 404 log card counts the log 301 Redirects keeps in its option', 200 === ( $a['status'] ?? 0 ) && number_format_i18n( count( $eps_log ) ) === ( $a['misses'] ?? null ), wp_json_encode( $a ) . " (table rows {$eps_404_rows})" );

			// Older versions: no option log, status 404 rows in the table.
			foreach ( array( 'a', 'b' ) as $sfx ) {
				$wpdb->insert( $eps_table, array( 'url_from' => $marker . '-old-' . $sfx, 'url_to' => '', 'status' => '404', 'type' => 'url', 'count' => 1 ) );
				$eid = (int) $wpdb->insert_id;
				if ( $eid > 1 ) {
					$eps_ids[] = $eid;
				}
			}
			$eps_404_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$eps_table} WHERE status = '404'" ); // phpcs:ignore
			if ( $eps_active ) {
				remove_all_filters( 'pre_option_eps_redirects_404_log' );
				add_filter( 'pre_option_eps_redirects_404_log', function () {
					return array();
				} );
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/eps301/status' );
				$got = null;
				foreach ( (array) ( $d['rows'] ?? array() ) as $r ) {
					if ( '404 log' === ( $r['label'] ?? '' ) ) {
						$got = (string) $r['value'];
					}
				}
				$b = array( 'status' => $st, 'misses' => $got );
				remove_all_filters( 'pre_option_eps_redirects_404_log' );
			} else {
				$b = $child( $eps_card, $eps_load . "\n" . $eps_log_hook( array() ) );
			}
			$check( 'D-29 control: with no option log, the card counts an older version\'s status 404 rows', 2 === count( $eps_ids ) && 200 === ( $b['status'] ?? 0 ) && number_format_i18n( $eps_404_rows ) === ( $b['misses'] ?? null ), wp_json_encode( $b ) . " (table rows {$eps_404_rows})" );
		} finally {
			foreach ( $eps_ids as $eid ) {
				if ( is_int( $eid ) && $eid > 1 ) {
					$wpdb->delete( $eps_table, array( 'id' => $eid, 'status' => '404' ) );
				}
			}
			$eps_after = $eps_raw();
			$check( 'D-29 cleanup: 301 Redirects\' options are as they were', $eps_after === $eps_before, wp_json_encode( array_keys( array_diff_assoc( array_map( 'strval', $eps_after ), array_map( 'strval', $eps_before ) ) ) ) );
		}
	}
}

// --- D-30 FluentSnippets: Modified reads as UTC -----------------------------
// FluentSnippets stamps updated_at with gmdate() and date() (UTC under
// WordPress), but the item carried it bare, which the client reads as site
// time: seven hours off in Los Angeles.
if ( ! function_exists( 'minn_admin_fluent_snippets_active' ) || ! minn_admin_fluent_snippets_active() || ! class_exists( 'FluentSnippets\App\Model\Snippet' ) ) {
	$skip( 'D-30 FluentSnippets inactive' );
} elseif ( ! Minn_Admin::code_edits_allowed() ) {
	$skip( 'D-30 this site disallows code edits, so the fixture snippet cannot be created' );
} else {
	$fs_file = '';
	try {
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array(
			'name'   => 'Minn tools probe ' . $marker,
			'type'   => 'css',
			'run_at' => 'wp_head',
			'code'   => '/* ' . $marker . ' */',
			'active' => false,
		) );
		$fs_file = is_array( $d ) && ! empty( $d['id'] ) ? sanitize_file_name( (string) $d['id'] ) : '';
		if ( 200 !== $st || '' === $fs_file ) {
			$check( 'D-30 fixture snippet created', false, $st . ' ' . wp_json_encode( $d ) );
		} else {
			$model = new \FluentSnippets\App\Model\Snippet();
			$full  = $model->findByFileName( $fs_file );
			$raw   = is_array( $full ) ? (string) ( $full['meta']['updated_at'] ?? '' ) : '';
			$utc   = '' !== $raw ? strtotime( $raw . ' UTC' ) : false;
			list( , $list ) = $call( 'GET', '/minn-admin/v1/fluent-snippets', null, array( 'per_page' => 100 ) );
			$item = null;
			foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
				if ( $fs_file === ( $it['id'] ?? '' ) ) {
					$item = $it;
				}
			}
			$mod = (string) ( $item['modified'] ?? '' );
			$check( 'D-30 the list\'s Modified carries its UTC offset and names the stored instant', $utc && preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $mod ) && strtotime( $mod ) === $utc, "stored {$raw}, sent {$mod}" );
			list( , $one ) = $call( 'GET', '/minn-admin/v1/fluent-snippets/' . $fs_file );
			$mod1 = (string) ( $one['modified'] ?? '' );
			$check( 'D-30 so does the detail\'s', $utc && preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $mod1 ) && strtotime( $mod1 ) === $utc, $mod1 );
		}
	} finally {
		if ( '' !== $fs_file ) {
			$call( 'DELETE', '/minn-admin/v1/fluent-snippets/' . $fs_file );
		}
	}
}

// --- D-31 Redirection: Last hit to the minute, and disabled rules show ------
// Their REST sends last_access as a date with no time (date_i18n with the
// date format), so "an hour ago" read as hours older, counted from midnight.
// The time is in their table, written by visit() with MySQL NOW().
if ( ! defined( 'REDIRECTION_VERSION' ) ) {
	$skip( 'D-31 Redirection inactive' );
} elseif ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'redirection_items' ) ) ) {
	$skip( 'D-31 Redirection has not run its setup here' );
} else {
	$rd_table = $wpdb->prefix . 'redirection_items';
	$rd_ids   = array();
	try {
		foreach ( array( 'hit', 'off' ) as $sfx ) {
			$call( 'POST', '/redirection/v1/redirect', array(
				'url'         => '/' . $marker . '-' . $sfx . '/',
				'action_data' => array( 'url' => '/' . $marker . '-target/' ),
				'action_type' => 'url',
				'action_code' => 301,
				'match_type'  => 'url',
				'group_id'    => (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}redirection_groups ORDER BY id ASC LIMIT 1" ), // phpcs:ignore
				'regex'       => false,
			) );
			$rid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rd_table} WHERE url = %s", '/' . $marker . '-' . $sfx . '/' ) ); // phpcs:ignore
			if ( $rid > 1 ) {
				$rd_ids[ $sfx ] = $rid;
			}
		}
		if ( 2 !== count( $rd_ids ) ) {
			$check( 'D-31 fixture redirects created', false, wp_json_encode( $rd_ids ) );
		} else {
			// A hit 95 minutes ago, written the way their visit() writes one.
			$wpdb->query( $wpdb->prepare( "UPDATE {$rd_table} SET last_count = last_count + 1, last_access = NOW() - INTERVAL 95 MINUTE WHERE id = %d", $rd_ids['hit'] ) ); // phpcs:ignore
			$call( 'POST', '/redirection/v1/bulk/redirect/disable', null, array( 'items' => (string) $rd_ids['off'] ) );
			// The surface's list asks with minn_view=1 (its collection query).
			list( $st, $d ) = $call( 'GET', '/redirection/v1/redirect', null, array( 'filterBy' => array( 'url' => $marker ), 'per_page' => 25, 'page' => 0, 'minn_view' => 1 ) );
			$by = array();
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				$by[ (int) ( $it['id'] ?? 0 ) ] = $it;
			}
			$rs   = $surface( 'redirection' );
			$col  = null;
			foreach ( (array) ( $rs['collection']['columns'] ?? array() ) as $c ) {
				if ( 'Last hit' === ( $c['label'] ?? '' ) ) {
					$col = $c;
				}
			}
			$hit  = $by[ $rd_ids['hit'] ] ?? array();
			$sent = $col ? (string) ( $hit[ $col['key'] ] ?? '' ) : '';
			$ts   = strtotime( $sent );
			$want = time() - 95 * MINUTE_IN_SECONDS;
			$check( 'D-31 the Last hit column is handed the hit\'s full time with its offset', 200 === $st && $col && 'ago' === ( $col['format'] ?? '' ) && preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $sent ) && $ts && abs( $ts - $want ) <= 180, wp_json_encode( array( 'col' => $col, 'sent' => $sent, 'off_by' => $ts ? $ts - $want : null ) ) );
			$never = $by[ $rd_ids['off'] ] ?? array();
			$check( 'D-31 a rule never hit is handed no time', $col && '' === (string) ( $never[ $col['key'] ] ?? '' ), wp_json_encode( $col ? ( $never[ $col['key'] ] ?? null ) : null ) );
			$status = $column( $rs, 'enabled' );
			$check( 'D-31 a disabled rule shows as such: a status pill on Redirection\'s enabled flag', $status && 'pill' === ( $status['format'] ?? '' ) && false === ( $never['enabled'] ?? null ) && true === ( $hit['enabled'] ?? null ), wp_json_encode( $status ) );

			// I15: only Minn's reading of their list gains last_hit. Their
			// own screen and every other client get it as they ship it.
			$check( 'I15 the Redirection surface asks its list with minn_view=1', false !== strpos( (string) ( $rs['collection']['query'] ?? '' ), 'minn_view=1' ), wp_json_encode( $rs['collection']['query'] ?? null ) );
			list( $pst, $plain ) = $call( 'GET', '/redirection/v1/redirect', null, array( 'filterBy' => array( 'url' => $marker ), 'per_page' => 25, 'page' => 0 ) );
			$plain_keys = array();
			foreach ( (array) ( $plain['items'] ?? array() ) as $it ) {
				$plain_keys = array_merge( $plain_keys, array_keys( (array) $it ) );
			}
			$check( 'I15 a request without it gets their list unchanged, with no last_hit', 200 === $pst && $plain_keys && ! in_array( 'last_hit', $plain_keys, true ), wp_json_encode( array_values( array_unique( $plain_keys ) ) ) );
		}
	} finally {
		if ( $rd_ids ) {
			$call( 'POST', '/redirection/v1/bulk/redirect/delete', null, array( 'items' => implode( ',', array_map( 'intval', $rd_ids ) ) ) );
		}
		foreach ( $rd_ids as $rid ) {
			if ( is_int( $rid ) && $rid > 1 ) {
				$wpdb->delete( $rd_table, array( 'id' => $rid ) );
			}
		}
	}
}

// --- D-32 Broken Link Checker: the link page's times read as times ----------
// Their model holds Unix timestamps; the link page's rows are printed as
// text, so ISO strings reached the screen raw.
$blc_ts = array(
	'last_check'    => time() - 2 * HOUR_IN_SECONDS,
	'first_failure' => time() - 3 * DAY_IN_SECONDS,
	'last_success'  => time() - 4 * DAY_IN_SECONDS,
);
$blc_rows = '$res = rest_do_request( new WP_REST_Request( \'GET\', \'/minn-admin/v1/blc/links/4242\' ) ); $out = array( \'status\' => $res->get_status(), \'rows\' => array() );'
	. ' foreach ( (array) ( $res->get_data()[\'sections\'] ?? array() ) as $g ) { foreach ( (array) ( $g[\'rows\'] ?? array() ) as $r ) { $out[\'rows\'][ (string) ( $r[\'label\'] ?? \'\' ) ] = (string) ( $r[\'value\'] ?? \'\' ); } }';
if ( function_exists( 'blc_get_links' ) ) {
	$skip( 'D-32 Broken Link Checker is active here; the stand-in only runs where it is not' );
} elseif ( ! is_dir( WP_PLUGIN_DIR . '/broken-link-checker' ) ) {
	$skip( 'D-32 Broken Link Checker is not installed' );
} else {
	// Loading Broken Link Checker schedules its cron events, so the child
	// stands in for blcLink with the public fields the adapter reads (their
	// legacy/includes/links.php): timestamps as integers, as their model
	// holds them after load.
	$blc_pre = 'function blc_get_links( $params = array() ) { return ! empty( $params[\'count_only\'] ) ? 1 : array( new blcLink( 4242 ) ); }' . "\n"
		. 'class blcLink { public $link_id = 0; public $url = \'https://example.com/minn-probe-gone\'; public $http_code = 404; public $redirect_count = 0; public $final_url = \'\';'
		. ' public $broken = true; public $warning = false; public $dismissed = false; public $log = \'\';'
		. ' public $last_check = ' . (int) $blc_ts['last_check'] . '; public $first_failure = ' . (int) $blc_ts['first_failure'] . '; public $last_success = ' . (int) $blc_ts['last_success'] . ';'
		. ' public function __construct( $id ) { $this->link_id = (int) $id; } public function valid() { return 4242 === $this->link_id; }'
		. ' public function analyse_status() { return array( \'text\' => \'Not Found\', \'code\' => \'broken\' ); } public function get_instances() { return array(); } }';
	$b = $child( $blc_rows, $blc_pre );
	$rows = (array) ( $b['rows'] ?? array() );
	foreach ( array( 'Last checked' => 'last_check', 'Failing since' => 'first_failure', 'Last worked' => 'last_success' ) as $label => $field ) {
		$got = (string) ( $rows[ $label ] ?? '' );
		$check( "D-32 the link page's {$label} reads as a date and time in the site's zone", 200 === ( $b['status'] ?? 0 ) && $when( $blc_ts[ $field ] ) === $got && ! preg_match( '/^\d{4}-\d\d-\d\dT/', $got ), $got ? $got : wp_json_encode( $b ) );
	}
}

// --- D-33 Scrutoscope: labels, callback counts, Captured, sampling wording --
if ( ! function_exists( 'minn_admin_scrutoscope_active' ) || ! minn_admin_scrutoscope_active() ) {
	$skip( 'D-33 Scrutoscope inactive' );
} else {
	$sc_table = \Scrutoscope\Profiler\Storage::table_name();
	$sc_id    = 0;
	// Captured with current_time('mysql'), site-local, as their save_profile()
	// does. Written straight to their table: save_profile() also prunes the
	// route's older profiles and records route statistics.
	$sc_ts   = time() - 10 * MINUTE_IN_SECONDS;
	$sc_data = array(
		'summary' => array( 'duration_ns' => 1500000000, 'total_exclusive_ns' => 900000000, 'callback_count' => 321, 'source_count' => 2 ),
		'sources' => array(
			array( 'type' => 'plugin', 'slug' => $marker . '-a', 'name' => 'Probe A', 'exclusive_ns' => 600000000, 'inclusive_ns' => 700000000, 'call_count' => 17, 'memory_delta' => 0, 'memory_exclusive' => 0, 'callbacks' => array() ),
			array( 'type' => 'theme', 'slug' => $marker . '-b', 'name' => 'Probe B', 'exclusive_ns' => 300000000, 'inclusive_ns' => 300000000, 'call_count' => 4, 'memory_delta' => 0, 'memory_exclusive' => 0, 'callbacks' => array() ),
		),
		'request' => array( 'url' => home_url( '/' . $marker . '/' ), 'method' => 'GET', 'route_class' => 'singular', 'user_role' => 'anonymous' ),
	);
	try {
		$wpdb->insert( $sc_table, array(
			'session_id'     => 'bg_' . $marker,
			'profile_type'   => 'on_demand',
			'request_url'    => home_url( '/' . $marker . '/' ),
			'request_method' => 'GET',
			'route_class'    => 'singular',
			'route_key'      => 'GET:/' . $marker,
			'duration_ns'    => 1500000000,
			'user_role'      => 'anonymous',
			'profile_data'   => gzcompress( wp_json_encode( $sc_data ) ),
			'captured_at'    => wp_date( 'Y-m-d H:i:s', $sc_ts ),
			'is_pinned'      => 0,
			'note'           => $marker,
			'tags'           => '',
			'response_status' => 200,
		) );
		$sc_id = (int) $wpdb->insert_id;
		if ( $sc_id <= 1 ) {
			$check( 'D-33 fixture profile stored', false, (string) $wpdb->last_error );
		} else {
			list( , $list ) = $call( 'GET', '/minn-admin/v1/scrutoscope/profiles', null, array( 'search' => $marker ) );
			$item = array();
			foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
				if ( $sc_id === (int) ( $it['id'] ?? 0 ) ) {
					$item = $it;
				}
			}
			$check( 'D-33 the Context column reads a label, not Scrutoscope\'s route class', isset( $item['context'] ) && 'singular' !== $item['context'] && '' !== $item['context'], wp_json_encode( $item['context'] ?? null ) );
			$sc_col = $column( $surface( 'scrutoscope' ), 'type_label' );
			$check( 'D-33 the Type column and the pill read without the underscore', $sc_col && isset( $item['type_label'], $item['status'] ) && false === strpos( $item['type_label'] . $item['status'], '_' ) && 'on_demand' === ( $item['type'] ?? '' ), wp_json_encode( array( $item['type_label'] ?? null, $item['status'] ?? null, $item['type'] ?? null ) ) );

			list( $st, $sec ) = $call( 'GET', '/minn-admin/v1/scrutoscope/profiles/' . $sc_id );
			$src_a = $row( $sec, $marker . '-a (plugin)' );
			$src_b = $row( $sec, $marker . '-b (theme)' );
			$check( 'D-33 each source counts its callbacks from what Scrutoscope stored', 200 === $st && $src_a && $src_b && false !== strpos( (string) $src_a['value'], '17 callbacks' ) && false !== strpos( (string) $src_b['value'], '4 callbacks' ), wp_json_encode( array( $src_a['value'] ?? null, $src_b['value'] ?? null ) ) );
			$cb = $row( $sec, 'Callbacks' );
			$check( 'D-33 so does the summary', $cb && '321' === (string) $cb['value'], wp_json_encode( $cb ) );
			$cap = $row( $sec, 'Captured' );
			$check( 'D-33 Captured reads the site-local stamp as site time, with no +00:00', $cap && $when( $sc_ts ) === (string) $cap['value'] && false === strpos( (string) $cap['value'], '+00:00' ), wp_json_encode( $cap['value'] ?? null ) . ' want ' . $when( $sc_ts ) );
		}

		// The status card's sampling hint, for each of their user scopes.
		$sc_hint = function ( $scope ) use ( $call ) {
			$on = function () {
				return '1';
			};
			$sc = function () use ( $scope ) {
				return $scope;
			};
			add_filter( 'pre_option_scrutoscope_background_profiling', $on );
			add_filter( 'pre_option_scrutoscope_user_scope', $sc );
			list( , $d ) = $call( 'GET', '/minn-admin/v1/scrutoscope/status' );
			remove_filter( 'pre_option_scrutoscope_background_profiling', $on );
			remove_filter( 'pre_option_scrutoscope_user_scope', $sc );
			foreach ( (array) ( $d['rows'] ?? array() ) as $r ) {
				if ( 'Background capture' === ( $r['label'] ?? '' ) ) {
					return (string) ( $r['hint'] ?? '' );
				}
			}
			return '';
		};
		$anon = $sc_hint( 'anonymous' );
		$all  = $sc_hint( 'all' );
		$in   = $sc_hint( 'logged_in' );
		$check( 'D-33 sampling signed-out visitors only, the card does not claim admin requests', '' !== $anon && false === stripos( $anon, 'admin' ) && false !== stripos( $anon, 'signed-out' ), $anon );
		$check( 'D-33 sampling signed-in users only, the card says so', '' !== $in && false !== stripos( $in, 'signed-in' ) && $in !== $anon, $in );
		$check( 'D-33 control: sampling everyone keeps front-end and admin', false !== stripos( $all, 'front-end and admin' ), $all );
	} finally {
		if ( is_int( $sc_id ) && $sc_id > 1 ) {
			$wpdb->delete( $sc_table, array( 'id' => $sc_id, 'note' => $marker ) );
		}
	}
}

// --- D-34 Code Snippets: read-only with DISALLOW_FILE_EDIT ------------------
// Hiding Add, the editor and Activate is deliberate on a hardened site, but
// the page then showed no name, description or code at all. It shows them
// read-only now, and nothing the client can send through that view writes.
if ( ! defined( 'CODE_SNIPPETS_VERSION' ) || ! function_exists( 'Code_Snippets\\get_snippet' ) ) {
	$skip( 'D-34 Code Snippets inactive' );
} elseif ( defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT ) {
	$skip( 'D-34 DISALLOW_FILE_EDIT is defined false here' );
} else {
	$cs_id   = 0;
	$cs_name = 'Minn tools probe ' . $marker;
	$cs_desc = 'Read-only <em>probe</em> & co';
	$cs_code = "/* {$marker} <b>never runs</b> & stays */\nadd_filter( 'minn_tools_probe_never', '__return_true' );";
	try {
		list( $st, $d ) = $call( 'POST', '/code-snippets/v1/snippets', array(
			'name'     => $cs_name,
			'desc'     => $cs_desc,
			'code'     => $cs_code,
			'scope'    => 'global',
			'priority' => 10,
			'active'   => false,
		) );
		$cs_id = is_array( $d ) ? (int) ( $d['id'] ?? 0 ) : 0;
		if ( ! ( is_int( $cs_id ) && $cs_id > 1 ) ) {
			$check( 'D-34 fixture snippet created', false, $st . ' ' . wp_json_encode( $d ) );
		} else {
			$cs_stored = \Code_Snippets\get_snippet( $cs_id );

			// S11: Code Snippets stamps modified with gmdate(). An edit at 02:30
			// UTC is the evening before west of UTC; the fixture is made the
			// latest change so the status card names it.
			$s11_ts = strtotime( gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) . ' 02:30:00 UTC' );
			$wpdb->update( $wpdb->prefix . 'snippets', array( 'modified' => gmdate( 'Y-m-d H:i:s', $s11_ts ) ), array( 'id' => $cs_id ) );
			list( , $s11 ) = $call( 'GET', '/minn-admin/v1/code-snippets/status' );
			$s11_row = null;
			foreach ( (array) ( $s11['rows'] ?? array() ) as $r ) {
				if ( 'Last change' === ( $r['label'] ?? '' ) ) {
					$s11_row = $r;
				}
			}
			$s11_want = wp_date( get_option( 'date_format' ), $s11_ts );
			$check( 'S11 the status card\'s Last change is dated in the site\'s zone and format', $s11_row && $cs_name === ( $s11_row['value'] ?? '' ) && $s11_want === ( $s11_row['hint'] ?? '' ), wp_json_encode( $s11_row ) . ' want ' . $s11_want );

			if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
				define( 'DISALLOW_FILE_EDIT', true );
			}
			$check( 'D-34 the site now disallows code edits', ! Minn_Admin::code_edits_allowed() );
			$cs  = $surface( 'code-snippets' );
			$det = (array) ( $cs['collection']['detail'] ?? array() );
			$acts = array_merge( (array) ( $cs['collection']['actions'] ?? array() ), (array) ( $cs['collection']['bulk'] ?? array() ) );
			$activates = array_filter( $acts, function ( $a ) {
				return isset( $a['body']['active'] ) && true === $a['body']['active'];
			} );
			$check( 'D-34 control: still no Add, no editor and no Activate', $cs && empty( $cs['collection']['create'] ) && empty( $det['edit'] ) && ! $activates, wp_json_encode( array_keys( $det ) ) );
			$view = (string) ( $det['sectionsRoute'] ?? '' );
			$check( 'D-34 the snippet page has a read-only view', '' !== $view && false !== strpos( $view, '{id}' ), $view );
			$path = '/' . str_replace( '{id}', (string) $cs_id, $view );
			list( $vst, $vsec ) = '' !== $view ? $call( 'GET', $path ) : array( 0, array() );
			$name = $row( $vsec, 'Name' );
			$desc = $row( $vsec, 'Description' );
			$code = $row( $vsec, 'Code' );
			$check( 'D-34 it shows the name, description and code as stored', 200 === $vst && $name && $cs_name === $name['value'] && $desc && $cs_stored && Minn_Admin::plain_text( $cs_stored->desc ) === $desc['value'] && $code && 'code' === ( $code['type'] ?? '' ) && (string) $cs_stored->code === $code['value'], $vst . ' ' . wp_json_encode( array( $name, $desc, $code ) ) );
			// Every write the client could aim at the view is refused, and
			// the snippet is exactly as it was.
			$refused = array();
			if ( '' !== $view ) {
				foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $m ) {
					list( $wst ) = $call( $m, $path, array( 'name' => 'changed', 'code' => 'echo 1;', 'active' => true, 'scope' => 'global' ) );
					$refused[ $m ] = $wst;
				}
			}
			$after = \Code_Snippets\get_snippet( $cs_id );
			$check( 'D-34 a write sent to the read-only view is refused and changes nothing', $refused && ! array_filter( $refused, function ( $c ) {
				return $c < 400;
			} ) && $after && $cs_stored && $after->code === $cs_stored->code && $after->name === $cs_stored->name && ! $after->active, wp_json_encode( $refused ) );

			// N5 and S14: on a network, a site's list carries the network's
			// shared snippets under their network-table ids, which can equal a
			// site snippet's, and a route given the id alone reads the site
			// table. No lab network has Code Snippets, so the routing is
			// checked against a stand-in for their snippet routes that
			// records what Minn asks them; it answers before their own
			// permission check runs, which the real-vendor check below keeps.
			$check( 'N5 the Code Snippets surface asks its list with minn_view=1', false !== strpos( (string) ( $cs['collection']['query'] ?? '' ), 'minn_view=1' ), wp_json_encode( $cs['collection']['query'] ?? null ) );
			$n5_list = function ( $minn_view ) {
				$req = new WP_REST_Request( 'GET', '/code-snippets/v1/snippets' );
				if ( $minn_view ) {
					$req->set_param( 'minn_view', 1 );
				}
				$res = new WP_REST_Response( array(
					array( 'id' => 7, 'name' => 'Site seven', 'network' => false, 'shared_network' => false, 'active' => true ),
					array( 'id' => 7, 'name' => 'Shared seven', 'network' => true, 'shared_network' => true, 'active' => true ),
				) );
				$res = apply_filters( 'rest_request_after_callbacks', $res, array(), $req );
				return array_map( function ( $it ) {
					return $it['id'];
				}, (array) ( $res instanceof WP_REST_Response ? $res->get_data() : array() ) );
			};
			$n5_ids = $n5_list( true );
			$check( 'N5 Minn\'s list names a shared network row network:<id> and leaves the site row\'s id alone', array( 7, 'network:7' ) === $n5_ids, wp_json_encode( $n5_ids ) );
			$n5_plain = $n5_list( false );
			$check( 'N5 control: without minn_view their list is as they ship it', array( 7, 7 ) === $n5_plain, wp_json_encode( $n5_plain ) );

			$n5_seen    = array();
			$n5_standin = function ( $result, $server, $req ) use ( &$n5_seen ) {
				if ( 0 !== strpos( (string) $req->get_route(), '/code-snippets/v1/snippets/' ) ) {
					return $result;
				}
				$net         = $req->has_param( 'network' ) && $req->get_param( 'network' );
				$n5_seen[]   = array( $req->get_method(), $req->get_route(), (bool) $net, $req->get_param( 'active' ) );
				if ( 'GET' === $req->get_method() ) {
					return rest_ensure_response( array( 'id' => 7, 'name' => $net ? 'Shared seven' : 'Site seven', 'desc' => '', 'code' => $net ? '/* network */' : '/* site */', 'scope' => 'global', 'priority' => 10, 'tags' => array(), 'active' => true, 'modified' => '' ) );
				}
				return rest_ensure_response( array( 'id' => 7, 'active' => false ) );
			};
			add_filter( 'rest_pre_dispatch', $n5_standin, 10, 3 );
			try {
				$n5_last = function () use ( &$n5_seen ) {
					return $n5_seen ? end( $n5_seen ) : null;
				};
				list( $nst, $nsec ) = $call( 'GET', '/minn-admin/v1/code-snippets/network:7' );
				$nname              = $row( $nsec, 'Name' );
				$check( 'N5 the read-only view of a shared row reads the network snippet', 200 === $nst && $nname && 'Shared seven' === $nname['value'] && array( 'GET', '/code-snippets/v1/snippets/7', true, null ) === $n5_last(), $nst . ' ' . wp_json_encode( array( $nname, $n5_last() ) ) );
				list( $sst, $ssec ) = $call( 'GET', '/minn-admin/v1/code-snippets/7' );
				$sname              = $row( $ssec, 'Name' );
				$check( 'N5 control: a site row\'s view reads the site snippet', 200 === $sst && $sname && 'Site seven' === $sname['value'] && array( 'GET', '/code-snippets/v1/snippets/7', false, null ) === $n5_last(), wp_json_encode( array( $sname, $n5_last() ) ) );
				$n5_before = count( $n5_seen );
				$n5_writes = array();
				foreach ( array( 'POST', 'PUT', 'DELETE' ) as $m ) {
					list( $wst )      = $call( $m, '/minn-admin/v1/code-snippets/network:7', array( 'active' => true, 'code' => 'echo 1;' ) );
					$n5_writes[ $m ] = $wst;
				}
				$check( 'N5 control: the view stays GET-only for a shared row, and passes no write on', ! array_filter( $n5_writes, function ( $c ) {
					return $c < 400;
				} ) && count( $n5_seen ) === $n5_before, wp_json_encode( $n5_writes ) );

				list( $dst ) = $call( 'POST', '/minn-admin/v1/code-snippets/network:7/deactivate' );
				$check( 'S14 Deactivate on a shared row asks their deactivate route in the network scope', 200 === $dst && array( 'POST', '/code-snippets/v1/snippets/7/deactivate', true, null ) === $n5_last(), $dst . ' ' . wp_json_encode( $n5_last() ) );
				list( $dst2 ) = $call( 'POST', '/minn-admin/v1/code-snippets/7/deactivate' );
				$check( 'S14 control: on a site row it sends the switch-off it always sent, with no network', 200 === $dst2 && array( 'PUT', '/code-snippets/v1/snippets/7', false, false ) === $n5_last(), $dst2 . ' ' . wp_json_encode( $n5_last() ) );
			} finally {
				remove_filter( 'rest_pre_dispatch', $n5_standin, 10 );
			}
			$deact = array_values( array_filter( array_merge( (array) ( $cs['collection']['actions'] ?? array() ), (array) ( $cs['collection']['bulk'] ?? array() ) ), function ( $a ) {
				return 'Deactivate' === ( $a['label'] ?? '' );
			} ) );
			$check( 'S14 Deactivate, single and bulk, goes through that route', 2 === count( $deact ) && ! array_filter( $deact, function ( $a ) {
				return 'minn-admin/v1/code-snippets/{id}/deactivate' !== ( $a['route'] ?? '' ) || 'POST' !== ( $a['method'] ?? '' ) || isset( $a['body'] );
			} ), wp_json_encode( $deact ) );
			$check( 'N5 the hardened page reads its snippet from the view alone (their id-only detail route cannot name a shared row)', ! isset( $det['detailRoute'] ) && '' !== $view, wp_json_encode( $det ) );

			// The site path, end to end against Code Snippets itself: an active
			// fixture (inert code) is switched off through Minn's route.
			$wpdb->update( $wpdb->prefix . 'snippets', array( 'active' => 1 ), array( 'id' => $cs_id ) );
			$on_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}snippets WHERE id = %d", $cs_id ) ); // phpcs:ignore
			list( $rst ) = $call( 'POST', '/minn-admin/v1/code-snippets/' . $cs_id . '/deactivate' );
			$on_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}snippets WHERE id = %d", $cs_id ) ); // phpcs:ignore
			$code_now = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$wpdb->prefix}snippets WHERE id = %d", $cs_id ) ); // phpcs:ignore
			$check( 'S14 control: Deactivate through Minn switches a real site snippet off on a hardened site, code untouched', 1 === $on_before && 200 === $rst && 0 === $on_after && $cs_stored && $code_now === (string) $cs_stored->code, wp_json_encode( array( $on_before, $rst, $on_after ) ) );
		}
	} finally {
		if ( is_int( $cs_id ) && $cs_id > 1 && function_exists( 'Code_Snippets\\delete_snippet' ) ) {
			\Code_Snippets\delete_snippet( $cs_id );
		}
	}
}

$surfaces_ref->setValue( null, null );
$summary();
