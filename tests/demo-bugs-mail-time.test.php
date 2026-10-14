<?php
/**
 * Regressions for the demo-site bugs in SureForms, SureMails and Site
 * Mailer: their times, and what their lists and details show.
 *
 * All three plugins write `created_at` with current_time( 'mysql' ), the
 * site's clock. Minn shifted it by the database server's zone instead, which
 * reads right only where the two agree, as they do on most labs. So this
 * process gives the site a zone whose offset, and where it can its calendar
 * day, differ from the database's: the timezone options are filtered here,
 * never written, since the lab is shared. Rows go straight into each
 * plugin's own table in the shape the plugin writes, carry a marker, and are
 * removed at the end (and at the start, after an interrupted run).
 *
 * The three plugins rest inactive on the lab. Swap them in for one process:
 * Run: MINN_SWAP_ADD=sureforms/sureforms.php,suremails/suremails.php,site-mailer/site-mailer.php \
 *      wp eval-file tests/demo-bugs-mail-time.test.php --user=admin --path=<site> --require=tests/lib/plugin-swap.php
 * Each section SKIPs when its plugin is not loaded.
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
	printf( "\ndemo-bugs-mail-time: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( (int) $admin[0]->ID );

global $wpdb;
$marker   = 'minn-dbmt';
$has      = function ( $table ) use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
};
$sf_table = $wpdb->prefix . 'srfm_entries';
$sm_table = $wpdb->prefix . 'suremails_email_log';
$ml_table = $wpdb->prefix . 'site_mail_logs';
$wipe     = function () use ( $wpdb, $has, $marker, $sf_table, $sm_table, $ml_table ) {
	$like = '%' . $wpdb->esc_like( $marker ) . '%';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $has( $sf_table ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sf_table} WHERE form_data LIKE %s", $like ) );
	}
	if ( $has( $sm_table ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sm_table} WHERE subject LIKE %s", $like ) );
	}
	if ( $has( $ml_table ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ml_table} WHERE subject LIKE %s", $like ) );
	}
	// phpcs:enable
};
$wipe();

// A site zone at least three hours from the database's, preferring one that
// is already on another calendar day (the 14-day charts can then only end on
// the right day by reading the site's clock). Kiritimati and Pago Pago are
// 25 hours apart, so one of them always is.
$db_off      = (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' );
$db_day      = gmdate( 'Y-m-d', time() + $db_off );
$zone        = '';
$zone_off    = 0;
$day_differs = false;
foreach ( array( true, false ) as $want_other_day ) {
	foreach ( array( 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'America/Los_Angeles', 'Asia/Tokyo' ) as $z ) {
		$z_now = new DateTimeImmutable( 'now', new DateTimeZone( $z ) );
		if ( abs( $z_now->getOffset() - $db_off ) < 3 * HOUR_IN_SECONDS || ( $want_other_day && $z_now->format( 'Y-m-d' ) === $db_day ) ) {
			continue;
		}
		$zone        = $z;
		$zone_off    = $z_now->getOffset();
		$day_differs = $z_now->format( 'Y-m-d' ) !== $db_day;
		break 2;
	}
}
if ( '' === $zone ) {
	$skip( 'no candidate zone differs from the database by three hours' );
	$summary();
}
$tz_string = function () use ( $zone ) {
	return $zone;
};
$tz_offset = function () use ( $zone_off ) {
	return $zone_off / HOUR_IN_SECONDS;
};
add_filter( 'pre_option_timezone_string', $tz_string, 99 );
add_filter( 'pre_option_gmt_offset', $tz_offset, 99 );
printf( "INFO  database %+.1fh (%s), site %s %+.1fh (%s)%s\n", $db_off / 3600, $db_day, $zone, $zone_off / 3600, wp_date( 'Y-m-d' ), $day_differs ? '' : ', same day' );

// The instant a site-local "Y-m-d H:i:s" stands for, worked out here rather
// than through the helper under test.
$instant   = function ( $local ) use ( $zone ) {
	return ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp();
};
$hours_off = function ( $iso, $want ) {
	$ts = strtotime( (string) $iso );
	return false === $ts ? 'unparsable ' . wp_json_encode( $iso ) : sprintf( '%s is %+.1fh off', $iso, ( $ts - $want ) / 3600 );
};
$today     = wp_date( 'Y-m-d' );
$yesterday = ( new DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
// Half an hour from the far edge of yesterday: a chart that moves rows by
// the site's offset minus the database's pushes this one onto a neighbour.
$edge      = $yesterday . ( $zone_off > $db_off ? ' 23:30:00' : ' 00:30:00' );
$point     = function ( $data, $day ) {
	foreach ( (array) ( $data['chart']['points'] ?? array() ) as $p ) {
		if ( ( $p['from'] ?? '' ) === $day . ' 00:00:00' ) {
			return $p;
		}
	}
	return null;
};
$last_from = function ( $data ) {
	$points = (array) ( $data['chart']['points'] ?? array() );
	$last   = $points ? end( $points ) : array();
	return (string) ( $last['from'] ?? '' );
};
$find      = function ( $items, $id ) {
	foreach ( (array) $items as $it ) {
		if ( (int) ( $it['id'] ?? 0 ) === (int) $id ) {
			return $it;
		}
	}
	return null;
};
$row_of    = function ( $sections, $label ) {
	foreach ( (array) ( $sections['sections'] ?? array() ) as $sec ) {
		foreach ( (array) ( $sec['rows'] ?? array() ) as $r ) {
			if ( ( $r['label'] ?? '' ) === $label ) {
				return $r;
			}
		}
	}
	return null;
};

try {
	// --- D-09, D-40 SureForms -------------------------------------------------
	// Entries were listed three hours early on a Los Angeles site whose
	// database ran New York time.
	if ( ! defined( 'SRFM_VER' ) || ! function_exists( 'minn_admin_sureforms_table' ) ) {
		$skip( 'SureForms inactive (swap it in)' );
	} elseif ( ! $has( $sf_table ) ) {
		$skip( 'SureForms has no entries table here' );
	} else {
		$sf_add = function ( $status, $created ) use ( $wpdb, $sf_table, $marker ) {
			$ok = $wpdb->insert( $sf_table, array(
				'form_id'         => 0,
				'user_id'         => 0,
				'form_data'       => wp_json_encode( array( 'Name' => 'Dana ' . $marker, 'Email' => $marker . '@example.com' ) ),
				'submission_info' => wp_json_encode( array() ),
				'logs'            => wp_json_encode( array() ),
				'status'          => $status,
				'type'            => '',
				'created_at'      => $created,
				'updated_at'      => $created,
			) );
			$id = $ok ? (int) $wpdb->insert_id : 0;
			return is_int( $id ) && $id > 1 ? $id : 0;
		};
		list( , $sf_before ) = $call( 'GET', '/minn-admin/v1/sureforms/status' );
		// What their form submit stores: the site's wall clock.
		$sf_now_local = current_time( 'mysql' );
		$sf_now       = $sf_add( 'unread', $sf_now_local );
		$sf_read      = $sf_add( 'read', $sf_now_local );
		$sf_edge      = $sf_add( 'unread', $edge );
		if ( ! $sf_now || ! $sf_read || ! $sf_edge ) {
			$check( 'SureForms fixture rows insert', false, $wpdb->last_error );
		} else {
			$sf_now_ts = $instant( $sf_now_local );
			list( $st, $list ) = $call( 'GET', '/minn-admin/v1/sureforms/entries', array( 'search' => $marker, 'per_page' => 100 ) );
			$item = $find( $list['items'] ?? array(), $sf_now );
			$check( 'D-09 SureForms: an entry sent just now lists at the moment it was sent', $item && strtotime( (string) $item['date'] ) === $sf_now_ts, $st . ' ' . ( $item ? $hours_off( $item['date'], $sf_now_ts ) : 'row missing' ) );

			// D-04: the entry header and the Submission panel print the
			// Submitted row as it comes, and it came as a raw ISO string.
			list( $st, $sec ) = $call( 'GET', "/minn-admin/v1/sureforms/entries/{$sf_now}" );
			$sub  = $row_of( $sec, __( 'Submitted', 'minn-admin' ) );
			$want = wp_date( 'M j, Y g:i a', $sf_now_ts );
			$check( 'D-04 SureForms: the entry header and Submission panel get the date on the site\'s clock, not a raw ISO string', $sub && $want === $sub['value'], $st . ' got ' . wp_json_encode( $sub['value'] ?? null ) . ' want ' . wp_json_encode( $want ) );

			// D-14: the list opened on Unread, so read entries stayed hidden
			// until someone picked Read.
			$surface = Minn_Admin_Surfaces::all()['sureforms'] ?? array();
			$first   = (string) ( $surface['collection']['filter']['options'][0][0] ?? '' );
			list( $st, $list ) = $call( 'GET', '/minn-admin/v1/sureforms/entries', array( 'search' => $marker, 'per_page' => 100, 'status' => $first ) );
			$items = $list['items'] ?? array();
			$check( 'D-14 SureForms: the list opens on every received entry, read ones included', $find( $items, $sf_read ) && $find( $items, $sf_now ), $st . ' default filter ' . wp_json_encode( $first ) . ', ' . count( $items ) . ' of 3 rows' );
			list( , $list ) = $call( 'GET', '/minn-admin/v1/sureforms/entries', array( 'search' => $marker, 'per_page' => 100, 'status' => 'unread' ) );
			$check( 'D-14 control: Unread still narrows to the unread ones', ! $find( $list['items'] ?? array(), $sf_read ) && $find( $list['items'] ?? array(), $sf_now ), count( $list['items'] ?? array() ) . ' rows' );

			list( $st, $sf_after ) = $call( 'GET', '/minn-admin/v1/sureforms/status' );
			$d_y = (int) ( $point( $sf_after, $yesterday )['value'] ?? 0 ) - (int) ( $point( $sf_before, $yesterday )['value'] ?? 0 );
			$d_t = (int) ( $point( $sf_after, $today )['value'] ?? 0 ) - (int) ( $point( $sf_before, $today )['value'] ?? 0 );
			$check( 'D-40 SureForms: the 14-day chart counts each entry on the site\'s day it was sent', 1 === $d_y && 2 === $d_t, "{$st} yesterday +{$d_y} (want 1), today +{$d_t} (want 2), edge row at {$edge}" );

			$bar = $point( $sf_after, $yesterday );
			list( $st, $list ) = $call( 'GET', '/minn-admin/v1/sureforms/entries', array( 'search' => $marker, 'per_page' => 100, 'after' => $bar['from'] ?? '', 'before' => $bar['to'] ?? '' ) );
			$items = $list['items'] ?? array();
			$check( 'D-40 SureForms: a clicked bar lists that day\'s entries', $bar && $find( $items, $sf_edge ) && ! $find( $items, $sf_now ), $st . ' ' . count( $items ) . ' rows for ' . ( $bar['from'] ?? '?' ) );
		}
	}

	// --- D-40 SureMails ------------------------------------------------------
	if ( ! defined( 'SUREMAILS_VERSION' ) || ! function_exists( 'minn_admin_suremails_table' ) ) {
		$skip( 'D-40 SureMails inactive (swap it in)' );
	} elseif ( ! $has( $sm_table ) ) {
		$skip( 'D-40 SureMails has no log table here' );
	} else {
		list( , $sm_before ) = $call( 'GET', '/minn-admin/v1/suremails/status' );
		// What their logger stores: the site's wall clock.
		$sm_local = current_time( 'mysql' );
		$ok       = $wpdb->insert( $sm_table, array(
			'email_from'  => 'site@example.com',
			'email_to'    => maybe_serialize( array( 'dana@example.com' ) ),
			'subject'     => $marker . ' sent',
			'body'        => '<p>body</p>',
			'headers'     => maybe_serialize( '' ),
			'attachments' => maybe_serialize( array() ),
			'status'      => 'sent',
			'response'    => maybe_serialize( '' ),
			'meta'        => wp_json_encode( array( 'retry' => 0, 'resend' => 0 ) ),
			'connection'  => 'SMTP',
			'created_at'  => $sm_local,
			'updated_at'  => $sm_local,
		) );
		$sm_id    = $ok ? (int) $wpdb->insert_id : 0;
		if ( ! ( is_int( $sm_id ) && $sm_id > 1 ) ) {
			$check( 'SureMails fixture row inserts', false, $wpdb->last_error );
		} else {
			$sm_ts = $instant( $sm_local );
			list( $st, $list ) = $call( 'GET', '/minn-admin/v1/suremails/emails', array( 'search' => $marker, 'per_page' => 100 ) );
			$item = $find( $list['items'] ?? array(), $sm_id );
			$check( 'D-40 SureMails: a send logged just now lists at the moment it was sent', $item && strtotime( (string) $item['created_at'] ) === $sm_ts, $st . ' ' . ( $item ? $hours_off( $item['created_at'], $sm_ts ) : 'row missing' ) );
			list( $st, $sec ) = $call( 'GET', "/minn-admin/v1/suremails/emails/{$sm_id}/view" );
			$date = $row_of( $sec, __( 'Date', 'minn-admin' ) );
			$check( 'D-40 SureMails: the detail\'s Date is the moment it was sent', $date && strtotime( (string) $date['value'] ) === $sm_ts, $st . ' ' . ( $date ? $hours_off( $date['value'], $sm_ts ) : 'row missing' ) );
			list( $st, $sm_after ) = $call( 'GET', '/minn-admin/v1/suremails/status' );
			$d_t = (int) ( $point( $sm_after, $today )['value'] ?? 0 ) - (int) ( $point( $sm_before, $today )['value'] ?? 0 );
			$check( 'D-40 SureMails: the 14-day chart ends on the site\'s today and counts the send there', $today . ' 00:00:00' === $last_from( $sm_after ) && 1 === $d_t, "{$st} last bar " . $last_from( $sm_after ) . ", today +{$d_t}" . ( $day_differs ? '' : ' (site and database share a day: not a repro this run)' ) );
			$bar = $point( $sm_after, $today );
			list( , $list ) = $call( 'GET', '/minn-admin/v1/suremails/emails', array( 'search' => $marker, 'per_page' => 100, 'after' => $bar['from'] ?? '', 'before' => $bar['to'] ?? '' ) );
			$check( 'D-40 SureMails: today\'s bar lists the send', $bar && $find( $list['items'] ?? array(), $sm_id ), count( $list['items'] ?? array() ) . ' rows' );
		}
	}

	// --- D-40 Site Mailer ----------------------------------------------------
	if ( ! defined( 'SITE_MAILER_VERSION' ) || ! function_exists( 'minn_admin_site_mailer_table' ) ) {
		$skip( 'Site Mailer inactive (swap it in)' );
	} elseif ( ! $has( $ml_table ) ) {
		$skip( 'Site Mailer has no log table here' );
	} else {
		$ml_add = function ( $to, $created ) use ( $wpdb, $ml_table, $marker ) {
			$ok = $wpdb->insert( $ml_table, array(
				'api_id'     => $marker . '-' . wp_generate_password( 8, false ),
				'to'         => wp_json_encode( $to ),
				'subject'    => $marker . ' delivered',
				'headers'    => wp_json_encode( '' ),
				'source'     => 'Test',
				'status'     => 'delivered',
				'created_at' => $created,
				'updated_at' => $created,
			) );
			$id = $ok ? (int) $wpdb->insert_id : 0;
			return is_int( $id ) && $id > 1 ? $id : 0;
		};
		list( , $ml_before ) = $call( 'GET', '/minn-admin/v1/site-mailer/status' );
		// What their mail handler stores: the site's wall clock.
		$ml_local = current_time( 'mysql' );
		$ml_one   = $ml_add( 'nadia.petrov@example.org', $ml_local );
		$ml_many  = $ml_add( array( 'june@example.com', 'José Ortiz <jose@example.com>' ), $ml_local );
		if ( ! $ml_one || ! $ml_many ) {
			$check( 'Site Mailer fixture rows insert', false, $wpdb->last_error );
		} else {
			$ml_ts = $instant( $ml_local );
			list( $st, $list ) = $call( 'GET', '/minn-admin/v1/site-mailer/emails', array( 'search' => $marker, 'per_page' => 100 ) );
			$one  = $find( $list['items'] ?? array(), $ml_one );
			$check( 'D-40 Site Mailer: a send logged just now lists at the moment it was sent', $one && strtotime( (string) $one['created_at'] ) === $ml_ts, $st . ' ' . ( $one ? $hours_off( $one['created_at'], $ml_ts ) : 'row missing' ) );
			list( $st, $sec ) = $call( 'GET', "/minn-admin/v1/site-mailer/emails/{$ml_one}/view" );
			$date = $row_of( $sec, __( 'Date', 'minn-admin' ) );
			$check( 'D-40 Site Mailer: the detail\'s Date is the moment it was sent', $date && strtotime( (string) $date['value'] ) === $ml_ts, $st . ' ' . ( $date ? $hours_off( $date['value'], $ml_ts ) : 'row missing' ) );

			// D-41: Site Mailer keeps `to` as wp_mail() got it, through
			// wp_json_encode(): a quoted string, or an array of addresses.
			// Minn printed it as stored. The decoded value is visitor-shaped
			// text, so it must reach the client as a text column and a plain
			// detail row, which app.js escapes.
			$many = $find( $list['items'] ?? array(), $ml_many );
			$to   = $row_of( $sec, __( 'To', 'minn-admin' ) );
			$check( 'D-41 Site Mailer: the list shows the recipient as an address, not a quoted JSON string', $one && 'nadia.petrov@example.org' === $one['to'], wp_json_encode( $one['to'] ?? null ) );
			$check( 'D-41 Site Mailer: several recipients list as a comma list', $many && 'june@example.com, José Ortiz <jose@example.com>' === $many['to'], wp_json_encode( $many['to'] ?? null ) );
			$check( 'D-41 Site Mailer: the detail\'s To is the address', $to && 'nadia.petrov@example.org' === $to['value'], $st . ' ' . wp_json_encode( $to['value'] ?? null ) );
			$cols  = ( Minn_Admin_Surfaces::all()['site-mailer']['collection']['columns'] ?? array() );
			$tocol = array_values( array_filter( $cols, function ( $c ) {
				return 'to' === ( $c['key'] ?? '' );
			} ) );
			$check( 'D-41 guard: To stays an escaped text cell and a plain detail row', $tocol && 'text' === ( $tocol[0]['format'] ?? '' ) && $to && ! isset( $to['type'] ), wp_json_encode( array( $tocol[0]['format'] ?? null, $to['type'] ?? null ) ) );

			list( $st, $ml_after ) = $call( 'GET', '/minn-admin/v1/site-mailer/status' );
			$d_t = (int) ( $point( $ml_after, $today )['value'] ?? 0 ) - (int) ( $point( $ml_before, $today )['value'] ?? 0 );
			$check( 'D-40 Site Mailer: the 14-day chart ends on the site\'s today and counts the sends there', $today . ' 00:00:00' === $last_from( $ml_after ) && 2 === $d_t, "{$st} last bar " . $last_from( $ml_after ) . ", today +{$d_t}" . ( $day_differs ? '' : ' (site and database share a day: not a repro this run)' ) );
			$bar = $point( $ml_after, $today );
			list( , $list ) = $call( 'GET', '/minn-admin/v1/site-mailer/emails', array( 'search' => $marker, 'per_page' => 100, 'after' => $bar['from'] ?? '', 'before' => $bar['to'] ?? '' ) );
			$check( 'D-40 Site Mailer: today\'s bar lists the sends', $bar && $find( $list['items'] ?? array(), $ml_one ) && $find( $list['items'] ?? array(), $ml_many ), count( $list['items'] ?? array() ) . ' rows' );

			// I2: the list now shows a recipient decoded, but search still
			// matched the stored JSON, where wp_json_encode() writes "Brontë"
			// as Brontë and a slash as \/. (The address carries neither
			// word, since the column's collation also folds accents.)
			$ml_rb = $ml_add( 'Renée Brontë, Ops/Support <rb@example.com>', $ml_local );
			foreach ( array( 'Brontë', 'Ops/Support' ) as $term ) {
				list( $st, $list ) = $call( 'GET', '/minn-admin/v1/site-mailer/emails', array( 'search' => $term, 'per_page' => 100 ) );
				$check( "I2 Site Mailer: searching \"{$term}\" finds the recipient as the list shows it", $ml_rb && $find( $list['items'] ?? array(), $ml_rb ), $st . ' ' . count( $list['items'] ?? array() ) . ' rows' );
			}
		}
	}

	// --- I1 Chart days across a DST change -----------------------------------
	// The day keys stepped back 86,400 seconds at a time, which lands an hour
	// off across a DST change: just after a spring-forward midnight the chart
	// skipped a day, and late on the day after a fall-back it repeated one.
	// The clock is pinned through core's wp_date filter, which every day key
	// is read through, at a Los Angeles moment on each side.
	foreach ( array( '2027-03-15 00:30:00', '2026-11-02 23:30:00' ) as $pin_local ) {
		$pin_zone  = new DateTimeZone( 'America/Los_Angeles' );
		$pin_at    = new DateTimeImmutable( $pin_local, $pin_zone );
		$pin_shift = $pin_at->getTimestamp() - time();
		$pin_tz    = function () {
			return 'America/Los_Angeles';
		};
		$pin_off   = function () use ( $pin_at ) {
			return $pin_at->getOffset() / HOUR_IN_SECONDS;
		};
		$pin_clock = function ( $date, $format, $timestamp, $timezone ) use ( $pin_shift ) {
			return ( new DateTimeImmutable( '@' . ( (int) $timestamp + $pin_shift ) ) )->setTimezone( $timezone )->format( $format );
		};
		$pin_want  = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$pin_want[] = $pin_at->setTime( 12, 0 )->modify( "-{$i} day" )->format( 'Y-m-d' );
		}
		$pin_days  = function ( $data ) {
			return array_map( function ( $p ) {
				return substr( (string) ( $p['from'] ?? '' ), 0, 10 );
			}, (array) ( $data['chart']['points'] ?? array() ) );
		};
		add_filter( 'pre_option_timezone_string', $pin_tz, 100 );
		add_filter( 'pre_option_gmt_offset', $pin_off, 100 );
		add_filter( 'wp_date', $pin_clock, 10, 4 );
		try {
			$got = array_keys( minn_admin_chart_days( 14 ) );
			$check( "I1 shared chart: 14 consecutive site days end on the pinned day at {$pin_local} Los Angeles", $pin_want === $got, wp_json_encode( array_values( array_diff( $pin_want, $got ) ) ) . ' missing, ' . count( array_unique( $got ) ) . ' distinct' );
			$check( "I1 shared chart: the window opens on the first bar's day at {$pin_local}", $pin_want[0] . ' 00:00:00' === minn_admin_chart_local_since( 14 ), minn_admin_chart_local_since( 14 ) );
			if ( defined( 'SRFM_VER' ) && $has( $sf_table ) ) {
				list( , $st_data ) = $call( 'GET', '/minn-admin/v1/sureforms/status' );
				$got = $pin_days( $st_data );
				$check( "I1 SureForms chart (the shared days): 14 consecutive site days at {$pin_local}", $pin_want === $got, count( array_unique( $got ) ) . ' distinct, last ' . end( $got ) );
			}
			if ( defined( 'SUREMAILS_VERSION' ) && $has( $sm_table ) ) {
				list( , $st_data ) = $call( 'GET', '/minn-admin/v1/suremails/status' );
				$got = $pin_days( $st_data );
				$check( "I1 SureMails chart: 14 consecutive site days at {$pin_local}", $pin_want === $got, count( array_unique( $got ) ) . ' distinct, last ' . end( $got ) );
			}
			if ( defined( 'SITE_MAILER_VERSION' ) && $has( $ml_table ) ) {
				list( , $st_data ) = $call( 'GET', '/minn-admin/v1/site-mailer/status' );
				$got = $pin_days( $st_data );
				$check( "I1 Site Mailer chart: 14 consecutive site days at {$pin_local}", $pin_want === $got, count( array_unique( $got ) ) . ' distinct, last ' . end( $got ) );
			}
		} finally {
			remove_filter( 'wp_date', $pin_clock, 10 );
			remove_filter( 'pre_option_timezone_string', $pin_tz, 100 );
			remove_filter( 'pre_option_gmt_offset', $pin_off, 100 );
		}
	}
} finally {
	$wipe();
	remove_filter( 'pre_option_timezone_string', $tz_string, 99 );
	remove_filter( 'pre_option_gmt_offset', $tz_offset, 99 );
}

$summary();
