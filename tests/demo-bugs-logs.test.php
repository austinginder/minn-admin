<?php
/**
 * Regressions for the demo-site bugs in the activity logs (D-35..D-39):
 * Simple History, Wordfence, Activity Log (Aryo), Sucuri and WP Activity Log.
 *
 * Each section seeds what the vendor itself stores (its logger, its table
 * row, its audit queue), reads it back through the route the client calls,
 * and checks what the list cell and the event page are handed, resolving
 * keys the way the client does (a column's key then its altKey; the event
 * page's who, level, time and address chains). Then it removes what it
 * seeded. Seeded names, addresses and messages stay plain text: the client
 * escapes every one of these values.
 *
 * A vendor that is installed but inactive here is loaded for one child
 * process only. The suite runs itself again with --require pointing at this
 * same file, which, before WordPress loads, filters active_plugins for that
 * process (nothing is written; a write that carries the added plugins has
 * them taken out again). Run with --require yourself and no child is used.
 *
 * Run: wp eval-file tests/demo-bugs-logs.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// --require: before WordPress. Load the log plugins this site has installed
// but not active, for this process only.
if ( ! function_exists( 'add_filter' ) ) {
	if ( class_exists( 'WP_CLI' ) && ! defined( 'MINN_DBL_PRELOAD' ) ) {
		define( 'MINN_DBL_PRELOAD', true );
		$GLOBALS['minn_dbl_added'] = array();
		WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) {
			$plugins = (array) $plugins;
			foreach ( array( 'simple-history/index.php', 'wordfence/wordfence.php', 'aryo-activity-log/aryo-activity-log.php', 'sucuri-scanner/sucuri.php', 'wp-security-audit-log/wp-security-audit-log.php' ) as $file ) {
				$on = false;
				foreach ( $plugins as $p ) {
					$on = $on || 0 === strpos( (string) $p, dirname( $file ) . '/' );
				}
				if ( ! $on && defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
					$plugins[] = $file;
					if ( ! in_array( $file, $GLOBALS['minn_dbl_added'], true ) ) {
						$GLOBALS['minn_dbl_added'][] = $file;
					}
				}
			}
			return $plugins;
		} );
		WP_CLI::add_wp_hook( 'pre_update_option_active_plugins', function ( $value ) {
			return array_values( array_diff( (array) $value, (array) $GLOBALS['minn_dbl_added'] ) );
		} );
	}
	return;
}

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
	printf( "\ndemo-bugs-logs: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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
$admin = get_current_user_id();

// Not preloaded and a log plugin is inactive: run again in a child that loads it.
$dbl_files    = array(
	'simple-history'        => 'simple-history/index.php',
	'wordfence'             => 'wordfence/wordfence.php',
	'aryo-activity-log'     => 'aryo-activity-log/aryo-activity-log.php',
	'sucuri-scanner'        => 'sucuri-scanner/sucuri.php',
	'wp-security-audit-log' => 'wp-security-audit-log/wp-security-audit-log.php',
);
$dbl_inactive = array();
foreach ( $dbl_files as $slug => $file ) {
	$on = false;
	foreach ( (array) get_option( 'active_plugins' ) as $p ) {
		$on = $on || 0 === strpos( (string) $p, $slug . '/' );
	}
	if ( ! $on && is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
		$dbl_inactive[] = $slug;
	}
}
if ( ! defined( 'MINN_DBL_PRELOAD' ) && $dbl_inactive && class_exists( 'WP_CLI' ) && function_exists( 'proc_open' ) ) {
	echo 'Loading ' . implode( ', ', $dbl_inactive ) . " for a child process (nothing activated)\n";
	// The child inherits this run's --path, --url and --user.
	$o = WP_CLI::runcommand(
		'eval-file ' . escapeshellarg( __FILE__ ) . ' --require=' . escapeshellarg( __FILE__ ),
		array( 'launch' => true, 'return' => 'all', 'exit_error' => false )
	);
	echo (string) $o->stdout, "\n";
	if ( 0 !== (int) $o->return_code ) {
		echo 'child stderr: ', substr( trim( (string) $o->stderr ), -1500 ), "\n";
	}
	exit( (int) $o->return_code );
}

global $wpdb;
$marker = 'minndbl' . preg_replace( '/[^a-z]/', 'q', strtolower( wp_generate_password( 6, false, false ) ) );

// What the client resolves, from the same item it is handed.
$path   = function ( $item, $key ) {
	foreach ( explode( '.', (string) $key ) as $k ) {
		if ( ! is_array( $item ) || ! array_key_exists( $k, $item ) ) {
			return null;
		}
		$item = $item[ $k ];
	}
	return $item;
};
$first  = function ( $item, array $keys ) use ( $path ) {
	foreach ( $keys as $k ) {
		$v = $path( $item, $k );
		if ( null !== $v && '' !== $v && false !== $v ) {
			return $v;
		}
	}
	return '';
};
// A list cell: the column's key, then its altKey (surfaceCellRender).
$cell   = function ( $item, $col ) use ( $path ) {
	$v = $path( $item, $col['key'] ?? '' );
	if ( ( null === $v || '' === $v ) && ! empty( $col['altKey'] ) ) {
		$v = $path( $item, $col['altKey'] );
	}
	return null === $v ? '' : $v;
};
// The event page (renderActivityDetail): who, level, when, address, and the
// fields it lists from Simple History's details_data (first label wins).
$card   = function ( $it ) use ( $first ) {
	$who = $first( $it, array( 'initiator_data.user_display_name', 'initiator_data.user_login', 'username', 'who' ) );
	if ( '' === $who ) {
		$who = $first( $it, array( 'initiator' ) ) ?: 'System';
	}
	$fields = array();
	foreach ( (array) ( $it['details_data'] ?? array() ) as $group ) {
		foreach ( (array) ( $group['items'] ?? array() ) as $d ) {
			$name = (string) ( ( $d['name'] ?? '' ) ?: ( ( $group['title'] ?? '' ) ?: 'Detail' ) );
			$nv   = isset( $d['new_value'] ) ? (string) $d['new_value'] : '';
			$pv   = isset( $d['prev_value'] ) ? (string) $d['prev_value'] : '';
			if ( '' === $nv && '' === $pv ) {
				continue;
			}
			if ( ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = ( '' !== $pv && '' !== $nv && $pv !== $nv ) ? $pv . ' → ' . $nv : ( '' !== $nv ? $nv : $pv );
			}
		}
	}
	return array(
		'who'    => (string) $who,
		'level'  => (string) $first( $it, array( 'loglevel', 'severity', 'action' ) ),
		'when'   => (string) $first( $it, array( 'date_local', 'date', 'date_gmt' ) ),
		'ip'     => (string) $first( $it, array( 'ip', 'client_ip' ) ),
		'fields' => $fields,
	);
};
// A pill's text (surfacePill → statusLabel).
$pill   = function ( $v ) {
	return preg_replace_callback( '/(^|\s)\S/', function ( $m ) {
		return strtoupper( $m[0] );
	}, str_replace( '-', ' ', strtolower( (string) $v ) ) );
};
$surface = function ( $id ) {
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
	return array( 'key' => $key );
};
// The query the client sends for page 1 of a collection.
$list_params = function ( $s ) {
	$coll = $s['collection'] ?? array();
	wp_parse_str( (string) ( $coll['query'] ?? '' ), $q );
	wp_parse_str( str_replace( array( '{page}', '{page0}' ), array( '1', '0' ), (string) ( $coll['pageQuery'] ?? 'per_page=25&page={page}' ) ), $pq );
	return array_merge( (array) $q, (array) $pq );
};

// --- D-35 Simple History: who, a grouped run's count, a post's changes -------
// Their JSON leaves three things raw that their own screen renders: the
// initiator when no user acted (web_user, wp), the count of a grouped run
// ("Show 2 similar events"), and a post's field changes, which it flattens to
// one value ("status Deleted: draftAdded: future"). The post update here is
// the shape their logger writes when a scheduled post publishes.
if ( ! defined( 'SIMPLE_HISTORY_VERSION' ) || ! class_exists( 'Simple_History\\Simple_History' ) ) {
	$skip( 'D-35 Simple History is not installed' );
} else {
	$sh_ids = array();
	try {
		$sh   = Simple_History\Simple_History::get_instance();
		$user = $sh->get_instantiated_logger_by_slug( 'SimpleUserLogger' );
		$post = $sh->get_instantiated_logger_by_slug( 'SimplePostLogger' );
		if ( ! $user || ! $post ) {
			$check( 'D-35 fixtures: their user and post loggers', false );
		} else {
			for ( $i = 0; $i < 3; $i++ ) {
				$user->warning_message( 'user_unknown_login_failed', array(
					'_initiator'             => 'web_user',
					'_occasionsID'           => 'minn-dbl/' . $marker,
					'_server_remote_addr'    => '203.0.113.0',
					'failed_username'        => $marker,
					'server_http_user_agent' => 'Minn probe',
				) );
			}
			$post->info_message( 'post_updated', array(
				'_initiator'           => 'wp',
				'post_id'              => 0,
				'post_type'            => 'post',
				'post_title'           => $marker . ' post',
				'post_prev_status'     => 'draft',
				'post_new_status'      => 'future',
				'post_prev_post_title' => $marker . ' old',
				'post_new_post_title'  => $marker . ' post',
			) );
			$ctx = $wpdb->prefix . 'simple_history_contexts';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table.
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT history_id FROM {$ctx} WHERE ( `key` = 'failed_username' AND value = %s ) OR ( `key` = 'post_title' AND value = %s )", $marker, $marker . ' post' ) ) as $id ) {
				$id = (int) $id;
				if ( is_int( $id ) && $id > 1 ) {
					$sh_ids[] = $id;
				}
			}
			$s = $surface( 'simple-history' );
			if ( 4 !== count( $sh_ids ) || ! $s ) {
				$check( 'D-35 fixtures: four events and the surface', false, count( $sh_ids ) . ' ' . ( $s ? 'surface' : 'no surface' ) );
			} else {
				$label = function ( $initiator ) {
					if ( method_exists( '\\Simple_History\\Log_Initiators', 'get_initiator_label' ) ) {
						return (string) \Simple_History\Log_Initiators::get_initiator_label( $initiator );
					}
					return (string) \Simple_History\Log_Initiators::get_initiator_text_from_row( (object) array( 'initiator' => $initiator, 'context' => array() ) );
				};
				$route = '/' . ltrim( $s['collection']['route'], '/' );
				list( $st, $events ) = $call( 'GET', $route, null, $list_params( $s ) );
				list( $st_raw, $raw ) = $call( 'GET', $route, null, array( 'per_page' => 25, 'page' => 1 ) );
				$by_id = function ( $list ) use ( $sh_ids ) {
					$out = array();
					foreach ( (array) $list as $e ) {
						if ( is_array( $e ) && in_array( (int) ( $e['id'] ?? 0 ), $sh_ids, true ) ) {
							$out[ ( 'post_updated' === ( $e['message_key'] ?? '' ) ) ? 'post' : 'run' ] = $e;
						}
					}
					return $out;
				};
				$mine = $by_id( $events );
				$them = $by_id( $raw );
				$who  = $column( $s, 'initiator_data.user_login' );
				$msg  = $column( $s, 'message' );
				if ( 200 !== $st || 200 !== $st_raw || ! isset( $mine['run'], $mine['post'], $them['run'], $them['post'] ) ) {
					$check( 'D-35 the seeded events list', false, $st . ' ' . substr( wp_json_encode( $events ), 0, 200 ) );
				} else {
					$check( 'D-35 precondition: Simple History grouped the three failed logins into one row', 3 === (int) ( $them['run']['subsequent_occasions_count'] ?? 0 ), (string) ( $them['run']['subsequent_occasions_count'] ?? '' ) );
					$check( 'D-35 a failed login by no user shows who it was in their words, not web_user', $label( 'web_user' ) === $cell( $mine['run'], $who ), wp_json_encode( $cell( $mine['run'], $who ) ) );
					$check( 'D-35 and its event page says the same', $label( 'web_user' ) === $card( $mine['run'] )['who'], $card( $mine['run'] )['who'] );
					$check( 'D-35 an event WordPress ran shows WordPress, not wp', $label( 'wp' ) === $cell( $mine['post'], $who ) && $label( 'wp' ) === $card( $mine['post'] )['who'], wp_json_encode( $cell( $mine['post'], $who ) ) );
					$run_msg = (string) $cell( $mine['run'], $msg );
					$check( 'D-35 the grouped row carries its count', $run_msg !== (string) $them['run']['message'] && 0 === strpos( $run_msg, (string) $them['run']['message'] ) && false !== strpos( substr( $run_msg, strlen( (string) $them['run']['message'] ) ), number_format_i18n( 3 ) ), $run_msg );
					$check( 'D-35 control: a row that is not a run keeps its message', (string) $them['post']['message'] === (string) $cell( $mine['post'], $msg ), (string) $cell( $mine['post'], $msg ) );
					$fields = $card( $mine['post'] )['fields'];
					$status = array_search( 'draft → future', $fields, true );
					$title  = array_search( $marker . ' old → ' . $marker . ' post', $fields, true );
					$check( 'D-35 a post\'s changes are separate entries, each old → new', false !== $status && false !== $title && $status !== $title, wp_json_encode( $fields ) );
					$check( 'D-35 control: their own response, asked without Minn\'s marker, is as they ship it', ! isset( $them['run']['who'] ) && false === strpos( wp_json_encode( $them['post']['details_data'] ?? array() ), '"prev_value"' ), wp_json_encode( $them['post']['details_data'] ?? null ) );
				}
			}
		}
	} finally {
		$hist = $wpdb->prefix . 'simple_history';
		$ctx  = $wpdb->prefix . 'simple_history_contexts';
		foreach ( $sh_ids as $id ) {
			if ( is_int( $id ) && $id > 1 ) {
				$wpdb->delete( $ctx, array( 'history_id' => $id ) );
				$wpdb->delete( $hist, array( 'id' => $id ) );
			}
		}
	}
}

// --- D-36 Wordfence: the event page's level is the action in words ----------
// The page reads `action` as the level, and the route handed it their code.
if ( ! function_exists( 'minn_admin_wordfence_active' ) || ! class_exists( 'wfUtils' ) || ! minn_admin_wordfence_active() ) {
	$skip( 'D-36 Wordfence is not installed, or its login table is missing' );
} else {
	$wf_table = $wpdb->base_prefix . 'wfLogins';
	$wf_ids   = array();
	try {
		$ip = @wfUtils::inet_pton( '203.0.113.9' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( array(
			array( $marker . 'ok', 'loginOK', 0, $admin ),
			array( $marker . 'nobody', 'loginFailInvalidUsername', 1, 0 ),
		) as $i => $row ) {
			$wpdb->insert( $wf_table, array( 'IP' => $ip, 'ctime' => time() - $i, 'fail' => $row[2], 'action' => $row[1], 'username' => $row[0], 'userID' => $row[3], 'UA' => 'Minn probe' ) );
			$id = (int) $wpdb->insert_id;
			if ( is_int( $id ) && $id > 1 ) {
				$wf_ids[ $row[1] ] = $id;
			}
		}
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wordfence/logins', null, array( 'search' => $marker, 'per_page' => 25, 'page' => 1 ) );
		$items = array();
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			$items[ array_search( (int) $it['id'], $wf_ids, true ) ] = $it;
		}
		if ( 2 !== count( $wf_ids ) || 200 !== $st || ! isset( $items['loginOK'], $items['loginFailInvalidUsername'] ) ) {
			$check( 'D-36 the seeded logins list', false, $st . ' ' . substr( wp_json_encode( $d ), 0, 200 ) );
		} else {
			$ok = $card( $items['loginOK'] )['level'];
			$check( 'D-36 a sign-in\'s level is the action in words, not loginOK', 'loginOK' !== $ok && minn_admin_wordfence_action_label( 'loginOK', 0 ) === $ok, $ok );
			$no = $card( $items['loginFailInvalidUsername'] )['level'];
			$check( 'D-36 a failed login\'s level says so', minn_admin_wordfence_action_label( 'loginFailInvalidUsername', 1 ) === $no, $no );
			$check( 'D-39 control: Wordfence shows the unknown username that was tried', $marker . 'nobody' === $card( $items['loginFailInvalidUsername'] )['who'], $card( $items['loginFailInvalidUsername'] )['who'] );
		}
	} finally {
		foreach ( $wf_ids as $id ) {
			if ( is_int( $id ) && $id > 1 ) {
				$wpdb->delete( $wf_table, array( 'id' => $id ) );
			}
		}
	}
}

// --- D-37 Activity Log (Aryo): action pills match the tabs above them -------
$aal_table = $wpdb->prefix . 'aryo_activity_log';
if ( ! class_exists( 'AAL_Main' ) ) {
	$skip( 'D-37 Activity Log (Aryo) is not installed' );
} elseif ( $aal_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aal_table ) ) ) {
	$skip( 'D-37 no Aryo log table on this site' );
} else {
	$aal_id = 0;
	try {
		$wpdb->insert( $aal_table, array( 'user_caps' => 'guest', 'action' => 'failed_login', 'object_type' => 'Users', 'object_subtype' => '', 'object_name' => $marker, 'object_id' => 0, 'user_id' => 0, 'hist_ip' => '203.0.113.7', 'hist_time' => (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- their clock
		$aal_id = (int) $wpdb->insert_id;
		list( $st, $d )   = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'search' => $marker, 'per_page' => 25, 'page' => 1 ) );
		list( $tst, $td ) = $call( 'GET', '/minn-admin/v1/aryo/actions' );
		$item = null;
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $aal_id ) {
				$item = $it;
			}
		}
		$tab = null;
		foreach ( (array) $td as $t ) {
			if ( 'failed_login' === ( $t['id'] ?? '' ) ) {
				$tab = (string) $t['title'];
			}
		}
		if ( ! ( is_int( $aal_id ) && $aal_id > 1 ) || 200 !== $st || 200 !== $tst || ! $item || null === $tab ) {
			$check( 'D-37 the seeded row and its tab list', false, $st . '/' . $tst . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
		} else {
			$check( 'D-37 the action pill reads as its tab does, not Failed_login', $tab === $pill( $item['action'] ) && 'Failed_login' !== $pill( $item['action'] ), $pill( $item['action'] ) . ' vs tab ' . $tab );
			$check( 'D-37 and the event page\'s level is that action in words', $tab === $card( $item )['level'], $card( $item )['level'] );
		}
	} finally {
		if ( is_int( $aal_id ) && $aal_id > 1 ) {
			$wpdb->delete( $aal_table, array( 'histid' => $aal_id ) );
		}
	}
}

// --- D-38 Sucuri: the event page shows who, from where and when -------------
// Their queue stores the user, address and time with each event; the route
// handed them under keys the event page does not read.
if ( ! function_exists( 'minn_admin_sucuri_active' ) || ! minn_admin_sucuri_active() || ! class_exists( 'SucuriScanEvent' ) || ! class_exists( 'SucuriScanCache' ) ) {
	$skip( 'D-38 Sucuri Security is not installed' );
} else {
	$su_addr = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored below.
	try {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.88';
		SucuriScanEvent::reportWarningEvent( $marker . ' warning' );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/sucuri/events', null, array( 'search' => $marker, 'per_page' => 25, 'page' => 1 ) );
		$item = $d['items'][0] ?? null;
		if ( 200 !== $st || 1 !== (int) ( $d['total'] ?? 0 ) || ! $item ) {
			$check( 'D-38 the seeded event lists', false, $st . ' ' . substr( wp_json_encode( $d ), 0, 200 ) );
		} else {
			$c = $card( $item );
			$check( 'D-38 control: the event page names the user Sucuri stored', wp_get_current_user()->user_login === $c['who'], $c['who'] );
			$check( 'D-38 the event page shows the address Sucuri stored', '' !== $c['ip'] && (string) $item['remote_addr'] === $c['ip'], wp_json_encode( array( $c['ip'], $item['remote_addr'] ) ) );
			$ts = '' !== $c['when'] ? strtotime( $c['when'] ) : false;
			$check( 'D-38 the event page shows when it happened, as UTC', false !== $ts && abs( time() - $ts ) < 600 && 'Z' === substr( $c['when'], -1 ), $c['when'] );
			$check( 'D-38 the event page shows its level', 'warning' === $c['level'], $c['level'] );
		}
	} finally {
		if ( null === $su_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $su_addr;
		}
		$cache = new SucuriScanCache( 'auditqueue' );
		foreach ( (array) $cache->getAll() as $k => $m ) {
			if ( is_string( $m ) && false !== strpos( $m, $marker ) ) {
				$cache->delete( $k );
			}
		}
	}
}

// --- D-39 WP Activity Log: a failed login for an unknown username names it ---
// Event 1003 stores no user ("Unknown User" in the row); the username that
// was tried is the event's Users meta, which WSAL's own message prints.
$wsal_occ  = $wpdb->base_prefix . 'wsal_occurrences';
$wsal_meta = $wpdb->base_prefix . 'wsal_metadata';
if ( ! defined( 'WSAL_VERSION' ) || ! class_exists( 'WSAL\\Controllers\\Alert_Manager' ) ) {
	$skip( 'D-39 WP Activity Log is not installed' );
} elseif ( $wsal_occ !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wsal_occ ) ) ) {
	$skip( 'D-39 no WP Activity Log table on this site' );
} elseif ( ! function_exists( 'minn_admin_wsal_can_view' ) || ! minn_admin_wsal_can_view() ) {
	$skip( 'D-39 WP Activity Log does not let this administrator read its log' );
} else {
	$wsal_id = 0;
	try {
		// As their login sensor logs it: from a signed-out request.
		wp_set_current_user( 0 );
		WSAL\Controllers\Alert_Manager::log( 1003, array(
			'Attempts'      => 1,
			'Users'         => $marker,
			'LogFileText'   => '',
			'ClientIP'      => '203.0.113.9',
			'UserAgent'     => 'Minn probe',
			'error_message' => null,
			'LoginPageURL'  => wp_login_url(),
		) );
		wp_set_current_user( $admin );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived tables.
		$wsal_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.id FROM {$wsal_occ} o JOIN {$wsal_meta} m ON m.occurrence_id = o.id WHERE o.alert_id = 1003 AND m.name = 'Users' AND m.value = %s ORDER BY o.id DESC LIMIT 1", $marker ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wsal/events', null, array( 'per_page' => 100, 'page' => 1 ) );
		$item = null;
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $wsal_id ) {
				$item = $it;
			}
		}
		list( $dst, $dd ) = $call( 'GET', '/minn-admin/v1/wsal/events/' . $wsal_id );
		$user_row = null;
		foreach ( (array) ( $dd['sections'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
				if ( 'User' === ( $r['label'] ?? '' ) ) {
					$user_row = (string) $r['value'];
				}
			}
		}
		$s = $surface( 'wp-activity-log' );
		if ( ! ( is_int( $wsal_id ) && $wsal_id > 1 ) || 200 !== $st || ! $item || ! $s ) {
			$check( 'D-39 the seeded event lists', false, $wsal_id . ' ' . $st . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
		} else {
			$check( 'D-39 the Who column shows the username that was tried, not System', $marker === $cell( $item, $column( $s, 'username' ) ), (string) $cell( $item, $column( $s, 'username' ) ) );
			$check( 'D-39 and so does the event page', $marker === $card( $item )['who'], $card( $item )['who'] );
			$check( 'D-39 and the event\'s own User row', 200 === $dst && $marker === $user_row, $dst . ' ' . wp_json_encode( $user_row ) );
		}
	} finally {
		wp_set_current_user( $admin );
		if ( is_int( $wsal_id ) && $wsal_id > 1 ) {
			$wpdb->delete( $wsal_meta, array( 'occurrence_id' => $wsal_id ) );
			$wpdb->delete( $wsal_occ, array( 'id' => $wsal_id ) );
		}
	}
}

$summary();
