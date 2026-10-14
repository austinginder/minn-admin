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
 * process (a write that carries the added plugins has them taken out again)
 * and puts back, at shutdown, every option the process wrote: a vendor's
 * start-up code runs as if it were active. Single site only (site options on
 * a network are not tracked). Run with --require yourself and no child is
 * used.
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
		$GLOBALS['minn_dbl'] = array( 'added' => array(), 'before' => array(), 'wrote' => array() );
		WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) {
			$plugins = (array) $plugins;
			foreach ( array( 'simple-history/index.php', 'wordfence/wordfence.php', 'aryo-activity-log/aryo-activity-log.php', 'sucuri-scanner/sucuri.php', 'wp-security-audit-log/wp-security-audit-log.php', 'stream/stream.php', 'better-wp-security/better-wp-security.php' ) as $file ) {
				$on = false;
				foreach ( $plugins as $p ) {
					$on = $on || 0 === strpos( (string) $p, dirname( $file ) . '/' );
				}
				if ( ! $on && defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
					$plugins[] = $file;
					if ( ! in_array( $file, $GLOBALS['minn_dbl']['added'], true ) ) {
						$GLOBALS['minn_dbl']['added'][] = $file;
					}
				}
			}
			return $plugins;
		} );
		WP_CLI::add_wp_hook( 'pre_update_option_active_plugins', function ( $value ) {
			return array_values( array_diff( (array) $value, $GLOBALS['minn_dbl']['added'] ) );
		} );
		// A plugin loaded while it is not active still runs its start-up code:
		// it schedules its cron events, whitelists the caller's address, warms
		// its transients. The cron table is never written from this process
		// (a restore can lose a race with a cron runner that saw the event and
		// rescheduled it). Every other option this process writes goes back
		// to what it was at shutdown, unless another process has written it
		// since.
		WP_CLI::add_wp_hook( 'pre_update_option_cron', function ( $value, $old ) {
			return $old;
		}, PHP_INT_MAX, 2 );
		$raw = function ( $name ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			return $row ? array( (string) $row->option_value, (string) $row->autoload ) : null;
		};
		foreach ( array( 'add_option', 'update_option', 'delete_option' ) as $hook ) {
			WP_CLI::add_wp_hook( $hook, function ( $name ) use ( $raw ) {
				if ( ! array_key_exists( $name, $GLOBALS['minn_dbl']['before'] ) ) {
					$GLOBALS['minn_dbl']['before'][ $name ] = $raw( $name );
				}
			}, 1, 1 );
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			WP_CLI::add_wp_hook( $hook, function ( $name ) use ( $raw ) {
				$GLOBALS['minn_dbl']['wrote'][ $name ] = $raw( $name );
			}, PHP_INT_MAX, 1 );
		}
		WP_CLI::add_wp_hook( 'shutdown', function () use ( $raw ) {
			global $wpdb;
			foreach ( $GLOBALS['minn_dbl']['before'] as $name => $was ) {
				$now = $raw( $name );
				if ( $now === $was ) {
					continue;
				}
				if ( ! array_key_exists( $name, $GLOBALS['minn_dbl']['wrote'] ) || $now !== $GLOBALS['minn_dbl']['wrote'][ $name ] ) {
					printf( "NOTE  option %s was changed by another process meanwhile; left as it is\n", $name );
					continue;
				}
				if ( null === $was ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
				} elseif ( null === $now ) {
					$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $was[0], 'autoload' => $was[1] ) );
				} else {
					$wpdb->update( $wpdb->options, array( 'option_value' => $was[0], 'autoload' => $was[1] ), array( 'option_name' => $name ) );
				}
				wp_cache_delete( $name, 'options' );
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}, PHP_INT_MAX );
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
	'stream'                => 'stream/stream.php',
	'better-wp-security'    => 'better-wp-security/better-wp-security.php',
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


// --- N9 Wordfence: a sign-out and the passkey codes read as what they are ---
// Wordfence 9 also logs `logout` (fail 0), `loginPasskeyOK`, `loginFailPasskey`
// and `loginFailPasskeyRequired`. A sign-out is neither a success nor a
// failure, so it counts under neither, as on their own dashboard.
if ( ! function_exists( 'minn_admin_wordfence_active' ) || ! class_exists( 'wfUtils' ) || ! minn_admin_wordfence_active() ) {
	$skip( 'N9 Wordfence is not installed, or its login table is missing' );
} else {
	$n9_table = $wpdb->base_prefix . 'wfLogins';
	$n9_ids   = array();
	$n9_ok    = function ( $status ) {
		foreach ( (array) ( $status['rows'] ?? array() ) as $r ) {
			if ( 'Failed logins (24h)' === ( $r['label'] ?? '' ) ) {
				return preg_match( '/([\d,]+)/', (string) ( $r['hint'] ?? '' ), $m ) ? (int) str_replace( ',', '', $m[1] ) : 0;
			}
		}
		return null;
	};
	$n9_bar   = function ( $status ) {
		$points = (array) ( $status['chart']['points'] ?? array() );
		$today  = end( $points );
		return is_array( $today ) ? (int) ( $today['value'] ?? 0 ) : null;
	};
	try {
		list( , $n9_before ) = $call( 'GET', '/minn-admin/v1/wordfence/status' );
		$ip = @wfUtils::inet_pton( '203.0.113.19' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( array(
			array( 'logout', 0, $marker . 'out' ),
			array( 'loginPasskeyOK', 0, $marker . 'pk' ),
			array( 'loginFailPasskey', 1, $marker . 'pkbad' ),
			array( 'loginFailPasskeyRequired', 1, $marker . 'pkreq' ),
		) as $i => $row ) {
			$wpdb->insert( $n9_table, array( 'IP' => $ip, 'ctime' => time() - $i, 'fail' => $row[1], 'action' => $row[0], 'username' => $row[2], 'userID' => $row[1] ? 0 : $admin, 'UA' => 'Minn probe' ) );
			$id = (int) $wpdb->insert_id;
			if ( is_int( $id ) && $id > 1 ) {
				$n9_ids[ $row[0] ] = $id;
			}
		}
		list( , $n9_after ) = $call( 'GET', '/minn-admin/v1/wordfence/status' );
		$n9_list = function ( $kind ) use ( $call, $marker, $n9_ids ) {
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wordfence/logins', null, array( 'search' => $marker, 'kind' => $kind, 'per_page' => 25, 'page' => 1 ) );
			$out = array();
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				$code = array_search( (int) $it['id'], $n9_ids, true );
				if ( false !== $code ) {
					$out[ $code ] = $it;
				}
			}
			return array( $st, $out );
		};
		list( $st, $all ) = $n9_list( '' );
		if ( 4 !== count( $n9_ids ) || 200 !== $st || 4 !== count( $all ) ) {
			$check( 'N9 the seeded rows list', false, $st . ' ' . count( $all ) );
		} else {
			$out = $all['logout'];
			$check( 'N9 a sign-out\'s level and message say Signed out', 'Signed out' === $card( $out )['level'] && 0 === strpos( (string) $out['message'], 'Signed out' ), $card( $out )['level'] . ' / ' . $out['message'] );
			$check( 'N9 a sign-out\'s result is neither success nor failed', ! in_array( $out['result'], array( 'success', 'failed' ), true ), (string) $out['result'] );
			list( , $ok_tab )   = $n9_list( 'success' );
			list( , $fail_tab ) = $n9_list( 'failed' );
			$check( 'N9 the Successful and Failed tabs leave the sign-out out', ! isset( $ok_tab['logout'] ) && ! isset( $fail_tab['logout'] ) && isset( $ok_tab['loginPasskeyOK'] ), wp_json_encode( array( array_keys( $ok_tab ), array_keys( $fail_tab ) ) ) );
			$a = $n9_ok( $n9_before );
			$b = $n9_ok( $n9_after );
			$check( 'N9 the status card counts the passkey sign-in as successful and not the sign-out', null !== $a && $b === $a + 1, $a . ' → ' . $b );
			$check( 'N9 and so does today\'s Successful bar', null !== $n9_bar( $n9_before ) && $n9_bar( $n9_after ) === $n9_bar( $n9_before ) + 1, $n9_bar( $n9_before ) . ' → ' . $n9_bar( $n9_after ) );
			$check( 'N9 the passkey codes read in words', 'Signed in with a passkey' === $card( $all['loginPasskeyOK'] )['level'] && 'Failed passkey login' === $card( $all['loginFailPasskey'] )['level'] && 'Login blocked (passkey required)' === $card( $all['loginFailPasskeyRequired'] )['level'], wp_json_encode( array( $card( $all['loginPasskeyOK'] )['level'], $card( $all['loginFailPasskey'] )['level'], $card( $all['loginFailPasskeyRequired'] )['level'] ) ) );
			$check( 'N9 control: passkey results are success and failed', 'success' === $all['loginPasskeyOK']['result'] && 'failed' === $all['loginFailPasskey']['result'] && 'failed' === $all['loginFailPasskeyRequired']['result'] );
		}
	} finally {
		foreach ( $n9_ids as $id ) {
			if ( is_int( $id ) && $id > 1 ) {
				$wpdb->delete( $n9_table, array( 'id' => $id ) );
			}
		}
	}
}

// --- S10 Times from the other half of the year keep their own offset --------
// A stamp from January on a site in summer time is an hour off when it is
// shifted by today's gmt_offset. The noon picked here is in the half of the
// year whose offset is not today's.
$s10_zone  = wp_timezone();
$s10_noon  = null;
$s10_today = ( new DateTimeImmutable( 'now', $s10_zone ) )->getOffset();
foreach ( array( '-01-15 12:00:00', '-07-15 12:00:00' ) as $s10_md ) {
	$s10_try = new DateTimeImmutable( gmdate( 'Y' ) . $s10_md, $s10_zone );
	if ( $s10_try->getOffset() !== $s10_today ) {
		$s10_noon = $s10_try;
		break;
	}
}
// The event row the WP Activity Log sections write, the shape its own
// database logger stores (one occurrence row plus its meta).
$wsal_put = function ( $alert, $username, $user_id, $created, array $meta = array() ) use ( $wpdb ) {
	$wpdb->insert( $wpdb->base_prefix . 'wsal_occurrences', array(
		'site_id' => is_multisite() ? get_current_blog_id() : 0, 'alert_id' => $alert, 'created_on' => $created, 'client_ip' => '203.0.113.29',
		'severity' => 200, 'object' => 'user', 'event_type' => 'login', 'user_agent' => 'Minn probe', 'user_roles' => '', 'username' => $username,
		'user_id' => $user_id, 'session_id' => '', 'post_status' => '', 'post_type' => '', 'post_id' => 0,
	) );
	$id = (int) $wpdb->insert_id;
	if ( ! ( is_int( $id ) && $id > 1 ) ) {
		return 0;
	}
	foreach ( $meta as $name => $value ) {
		$wpdb->insert( $wpdb->base_prefix . 'wsal_metadata', array( 'occurrence_id' => $id, 'name' => $name, 'value' => $value ) );
	}
	return $id;
};
$wsal_drop = function ( $id ) use ( $wpdb ) {
	if ( is_int( $id ) && $id > 1 ) {
		$wpdb->delete( $wpdb->base_prefix . 'wsal_metadata', array( 'occurrence_id' => $id ) );
		$wpdb->delete( $wpdb->base_prefix . 'wsal_occurrences', array( 'id' => $id ) );
	}
};
if ( ! $s10_noon ) {
	$skip( 'S10 this site\'s zone keeps one offset all year' );
} else {
	$s10_utc = gmdate( 'Y-m-d\TH:i:s\Z', $s10_noon->getTimestamp() );
	// Aryo stores current_time( 'timestamp' ): the wall clock read as an epoch.
	if ( ! class_exists( 'AAL_Main' ) || $aal_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aal_table ) ) ) {
		$skip( 'S10 Activity Log (Aryo) is not installed' );
	} else {
		$s10_aal = 0;
		try {
			$wpdb->insert( $aal_table, array( 'user_caps' => 'guest', 'action' => 'failed_login', 'object_type' => 'Users', 'object_subtype' => '', 'object_name' => $marker . 'tz', 'object_id' => 0, 'user_id' => 0, 'hist_ip' => '203.0.113.7', 'hist_time' => $s10_noon->getTimestamp() + $s10_noon->getOffset() ) );
			$s10_aal = (int) $wpdb->insert_id;
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'search' => $marker . 'tz', 'per_page' => 25, 'page' => 1 ) );
			$s10_item = null;
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $s10_aal ) {
					$s10_item = $it;
				}
			}
			$check( 'S10 an Aryo row from the other half of the year is sent at its own offset', $s10_item && $s10_utc === $s10_item['date'], wp_json_encode( array( $s10_item['date'] ?? null, $s10_utc ) ) );
		} finally {
			if ( is_int( $s10_aal ) && $s10_aal > 1 ) {
				$wpdb->delete( $aal_table, array( 'histid' => $s10_aal ) );
			}
		}
	}
	// WP Activity Log's created_on is a UTC epoch; the event page's When row
	// prints it in site time.
	if ( ! defined( 'WSAL_VERSION' ) || ! function_exists( 'minn_admin_wsal_can_view' ) || ! minn_admin_wsal_can_view() ) {
		$skip( 'S10 WP Activity Log is not installed' );
	} else {
		$s10_wsal = 0;
		try {
			$s10_wsal = $wsal_put( 1000, 'admin', $admin, (float) $s10_noon->getTimestamp() );
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wsal/events/' . $s10_wsal );
			$s10_when = null;
			foreach ( (array) ( $d['sections'] ?? array() ) as $g ) {
				foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
					if ( 'When' === ( $r['label'] ?? '' ) ) {
						$s10_when = (string) $r['value'];
					}
				}
			}
			$want = wp_date( 'M j, Y g:i a', $s10_noon->getTimestamp() );
			$check( 'S10 a WP Activity Log event from the other half of the year shows its own site time', 200 === $st && $want === $s10_when, wp_json_encode( array( $s10_when, $want ) ) );
		} finally {
			$wsal_drop( $s10_wsal );
		}
	}
}

// --- S12 Stream and Solid Security name contexts and lockout types in words -
// Stream's records carry connector, context and action slugs, and its own
// screen prints each through the connector's labels. Solid Security's
// lockout_type is the lockout module ("brute_force"), whose registry carries
// the label its own screens print.
if ( ! function_exists( 'wp_stream_get_instance' ) || ! function_exists( 'minn_admin_stream_can' ) ) {
	$skip( 'S12 Stream is not installed' );
} else {
	$s12_id = 0;
	try {
		$wpdb->insert( $wpdb->base_prefix . 'stream', array( 'site_id' => get_current_network_id(), 'blog_id' => get_current_blog_id(), 'object_id' => $admin, 'user_id' => $admin, 'user_role' => 'administrator', 'summary' => $marker . ' logged in', 'created' => gmdate( 'Y-m-d H:i:s' ), 'connector' => 'users', 'context' => 'sessions', 'action' => 'login', 'ip' => '203.0.113.49' ) );
		$s12_id = (int) $wpdb->insert_id;
		// Their labels for the users connector, as their own screen reads them.
		$s12_want = null;
		$connectors = wp_stream_get_instance()->connectors;
		$all = method_exists( $connectors, 'get_all_including_admin_only' ) ? $connectors->get_all_including_admin_only() : $connectors->get_all();
		foreach ( (array) $all as $c ) {
			if ( 'users' === ( $c['slug'] ?? '' ) && isset( $c['contexts']['sessions'], $c['actions']['login'] ) ) {
				$s12_want = Minn_Admin::plain_text( $c['contexts']['sessions'] ) . ' / ' . Minn_Admin::plain_text( $c['actions']['login'] );
			}
		}
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/stream/records', null, array( 'search' => $marker, 'per_page' => 25, 'page' => 1 ) );
		$s12_item = null;
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $s12_id ) {
				$s12_item = $it;
			}
		}
		if ( ! ( is_int( $s12_id ) && $s12_id > 1 ) || 200 !== $st || ! $s12_item || null === $s12_want ) {
			$check( 'S12 the seeded Stream record lists', false, $st . ' ' . wp_json_encode( $s12_want ) . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
		} else {
			$check( 'S12 a Stream record\'s Context is in Stream\'s words, not sessions / login', $s12_want === $s12_item['context'], wp_json_encode( array( $s12_item['context'], $s12_want ) ) );
		}
	} finally {
		if ( is_int( $s12_id ) && $s12_id > 1 ) {
			$wpdb->delete( $wpdb->base_prefix . 'stream_meta', array( 'record_id' => $s12_id ) );
			$wpdb->delete( $wpdb->base_prefix . 'stream', array( 'ID' => $s12_id ) );
		}
	}
}
if ( ! function_exists( 'minn_admin_solid_security_active' ) || ! minn_admin_solid_security_active() || ! minn_admin_solid_security_can() ) {
	$skip( 'S12 Solid Security is not installed' );
} else {
	// A module this site registers (their label), and one it does not.
	global $itsec_lockout;
	$s12_modules = is_object( $itsec_lockout ) && method_exists( $itsec_lockout, 'get_lockout_modules' ) ? (array) $itsec_lockout->get_lockout_modules() : array();
	$s12_type    = null;
	foreach ( $s12_modules as $s12_slug => $s12_module ) {
		if ( is_array( $s12_module ) && ! empty( $s12_module['label'] ) ) {
			$s12_type = (string) $s12_slug;
			break;
		}
	}
	$s12_unknown = 'minn_probe_module';
	if ( null === $s12_type ) {
		$skip( 'S12 Solid Security registers no lockout module with a label here' );
	} else {
		$s12_los = array();
		try {
			foreach ( array( $s12_type => '203.0.113.59', $s12_unknown => '203.0.113.69' ) as $s12_t => $s12_host ) {
				$wpdb->insert( $wpdb->base_prefix . 'itsec_lockouts', array( 'lockout_type' => $s12_t, 'lockout_start' => current_time( 'mysql' ), 'lockout_start_gmt' => gmdate( 'Y-m-d H:i:s' ), 'lockout_expire' => current_time( 'mysql' ), 'lockout_expire_gmt' => gmdate( 'Y-m-d H:i:s' ), 'lockout_host' => $s12_host, 'lockout_active' => 0 ) );
				$id = (int) $wpdb->insert_id;
				if ( is_int( $id ) && $id > 1 ) {
					$s12_los[ $s12_t ] = $id;
				}
			}
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/solid-security/lockouts', null, array( 'search' => '203.0.113.', 'per_page' => 100, 'page' => 1 ) );
			$s12_items = array();
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				$t = array_search( (int) $it['id'], $s12_los, true );
				if ( false !== $t ) {
					$s12_items[ $t ] = $it;
				}
			}
			if ( 2 !== count( $s12_los ) || 200 !== $st || 2 !== count( $s12_items ) ) {
				$check( 'S12 the seeded Solid Security lockouts list', false, $st . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
			} else {
				$want = Minn_Admin::plain_text( $s12_modules[ $s12_type ]['label'] );
				$check( 'S12 a Solid Security lockout\'s Type is their module label, not the slug', $want === $s12_items[ $s12_type ]['type'], wp_json_encode( array( $s12_type, $s12_items[ $s12_type ]['type'], $want ) ) );
				$check( 'S12 a module they do not register here reads tidied, not as the slug', 'Minn Probe Module' === $s12_items[ $s12_unknown ]['type'], (string) $s12_items[ $s12_unknown ]['type'] );
			}
		} finally {
			foreach ( $s12_los as $id ) {
				if ( is_int( $id ) && $id > 1 ) {
					$wpdb->delete( $wpdb->base_prefix . 'itsec_lockouts', array( 'lockout_id' => $id ) );
				}
			}
		}
	}
}

// --- S17 WP Activity Log's lower-case "Unknown user" reads as System --------
// Its occurrence store writes "Unknown user" for a login event with neither
// a user id nor a username, and "Unknown User" elsewhere.
if ( ! defined( 'WSAL_VERSION' ) || ! function_exists( 'minn_admin_wsal_can_view' ) || ! minn_admin_wsal_can_view() ) {
	$skip( 'S17 WP Activity Log is not installed' );
} else {
	$s17_id = 0;
	try {
		$s17_id = $wsal_put( 1000, 'Unknown user', 0, microtime( true ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wsal/events', null, array( 'per_page' => 100, 'page' => 1 ) );
		$s17_item = null;
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $s17_id ) {
				$s17_item = $it;
			}
		}
		$check( 'S17 a row stored as "Unknown user" shows System', $s17_item && 'System' === $s17_item['username'], wp_json_encode( $s17_item['username'] ?? null ) );
		list( $dst, $dd ) = $call( 'GET', '/minn-admin/v1/wsal/events/' . $s17_id );
		$s17_user = null;
		foreach ( (array) ( $dd['sections'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
				if ( 'User' === ( $r['label'] ?? '' ) ) {
					$s17_user = (string) $r['value'];
				}
			}
		}
		$check( 'S17 and so does its event\'s User row', 200 === $dst && 'System' === $s17_user, $dst . ' ' . wp_json_encode( $s17_user ) );
	} finally {
		$wsal_drop( $s17_id );
	}
}

// --- I21 Search follows what the lists show ---------------------------------
// Aryo's pill says "Failed Login" for the stored failed_login, and WP Activity
// Log's Who column names the username a 1003 event tried (its Users meta).
if ( ! class_exists( 'AAL_Main' ) || $aal_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aal_table ) ) ) {
	$skip( 'I21 Activity Log (Aryo) is not installed' );
} else {
	$i21_aal = 0;
	try {
		$wpdb->insert( $aal_table, array( 'user_caps' => 'guest', 'action' => 'failed_login', 'object_type' => 'Users', 'object_subtype' => '', 'object_name' => $marker . 'srch', 'object_id' => 0, 'user_id' => 0, 'hist_ip' => '203.0.113.7', 'hist_time' => (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- their clock
		$i21_aal = (int) $wpdb->insert_id;
		$i21_find = function ( $q ) use ( $call, $i21_aal ) {
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'search' => $q, 'per_page' => 100, 'page' => 1 ) );
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $i21_aal ) {
					return array( $st, true, (int) ( $d['total'] ?? 0 ) );
				}
			}
			return array( $st, false, (int) ( $d['total'] ?? 0 ) );
		};
		$i21_words = strtolower( minn_admin_aryo_action_label( 'failed_login' ) );
		list( $st, $found ) = $i21_find( $i21_words );
		$check( 'I21 searching Aryo for the pill\'s words finds the row', 200 === $st && $found, $i21_words . ' ' . $st );
		list( $st, $found ) = $i21_find( 'ailed log' );
		$check( 'I21 and part of them', 200 === $st && $found, (string) $st );
		list( $st, $found, $n ) = $i21_find( $marker . 'nomatch' );
		$check( 'I21 control: a term nothing carries finds nothing', 200 === $st && ! $found && 0 === $n, (string) $n );
	} finally {
		if ( is_int( $i21_aal ) && $i21_aal > 1 ) {
			$wpdb->delete( $aal_table, array( 'histid' => $i21_aal ) );
		}
	}
}
if ( ! defined( 'WSAL_VERSION' ) || ! function_exists( 'minn_admin_wsal_can_view' ) || ! minn_admin_wsal_can_view() ) {
	$skip( 'I21 WP Activity Log is not installed' );
} else {
	$i21_wsal = 0;
	try {
		$i21_wsal = $wsal_put( 1003, 'Unknown User', 0, microtime( true ), array( 'Users' => $marker . 'tried', 'Attempts' => '1' ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wsal/events', null, array( 'search' => $marker . 'tri', 'per_page' => 25, 'page' => 1 ) );
		$ids = array_map( function ( $it ) {
			return (int) $it['id'];
		}, (array) ( $d['items'] ?? array() ) );
		$check( 'I21 searching WP Activity Log for a tried username finds its event', 200 === $st && in_array( $i21_wsal, $ids, true ) && 1 === (int) ( $d['total'] ?? 0 ), $st . ' ' . wp_json_encode( $ids ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wsal/events', null, array( 'search' => $marker . 'nomatch', 'per_page' => 25, 'page' => 1 ) );
		$check( 'I21 control: a term nothing carries finds nothing', 200 === $st && 0 === (int) ( $d['total'] ?? -1 ), (string) ( $d['total'] ?? '' ) );
	} finally {
		$wsal_drop( $i21_wsal );
	}
}

// --- S10 All-In-One Security: an event's When keeps its own offset ----------
// Runs last: All-In-One Security is not loaded here (its bundled task manager
// re-runs its table migration on every load of an inactive copy), so a
// stand-in AIO_WP_Security class gives this request the adapter's routes,
// which read only the audit table. The class cannot be undone in this process.
$s10_aios_table = function_exists( 'minn_admin_aios_table' ) ? minn_admin_aios_table() : '';
if ( ! $s10_noon ) {
	$skip( 'S10 (All-In-One Security) this site\'s zone keeps one offset all year' );
} elseif ( '' === $s10_aios_table || $s10_aios_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $s10_aios_table ) ) ) {
	$skip( 'S10 no All-In-One Security audit table on this site' );
} else {
	if ( ! function_exists( 'minn_admin_aios_active' ) || ! minn_admin_aios_active() ) {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in class for this request only.
		eval( 'class AIO_WP_Security {}' );
		foreach ( $GLOBALS['wp_filter']['rest_api_init']->callbacks as $aios_prio ) {
			foreach ( $aios_prio as $aios_cb ) {
				if ( $aios_cb['function'] instanceof Closure && 'all-in-one-security.php' === basename( ( new ReflectionFunction( $aios_cb['function'] ) )->getFileName() ) ) {
					call_user_func( $aios_cb['function'] );
				}
			}
		}
	}
	$s10_aios = 0;
	try {
		$wpdb->insert( $s10_aios_table, array( 'network_id' => get_current_network_id(), 'site_id' => get_current_blog_id(), 'username' => 'admin', 'ip' => '203.0.113.39', 'level' => 'info', 'event_type' => 'successful_login', 'details' => '{}', 'stacktrace' => '', 'created' => $s10_noon->getTimestamp() ) );
		$s10_aios = (int) $wpdb->insert_id;
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/aios/events/' . $s10_aios );
		$s10_when = null;
		foreach ( (array) ( $d['sections'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
				if ( 'When' === ( $r['label'] ?? '' ) ) {
					$s10_when = (string) $r['value'];
				}
			}
		}
		$want = wp_date( 'M j, Y g:i a', $s10_noon->getTimestamp() );
		$check( 'S10 an All-In-One Security event from the other half of the year shows its own site time', 200 === $st && $want === $s10_when, $st . ' ' . wp_json_encode( array( $s10_when, $want ) ) );
	} finally {
		if ( is_int( $s10_aios ) && $s10_aios > 1 ) {
			$wpdb->delete( $s10_aios_table, array( 'id' => $s10_aios ) );
		}
	}
}

$summary();
