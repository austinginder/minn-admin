<?php
/**
 * Shared pieces of the round-trip harnesses (roundtrip-*.test.php).
 *
 * A harness replays what the client really sends for a kind of write surface
 * and checks what got stored: a value typed reads back as typed, a save that
 * changes nothing stores nothing, and a change to one field leaves the rest.
 * Each harness `require`s this file and gets back an array of closures.
 *
 * @package minn-admin
 */

if ( ! defined( 'REST_REQUEST' ) ) {
	// Vendors branch on this (Otter, ACF): answer as a real REST save would.
	define( 'REST_REQUEST', true );
}

return ( function ( $name, $known ) {
	$results = array();

	/*
	 * The ratchet: checks that fail today because of an open finding report
	 * KNOWN and do not fail the run; one that starts passing fails it until
	 * its entry is removed, so the list only shrinks. A failure not listed
	 * is a regression.
	 */
	$check = function ( $label, $ok, $detail = '' ) use ( &$results, &$known ) {
		set_time_limit( 0 );
		if ( isset( $known[ $label ] ) ) {
			unset( $known[ $label ] );
			$results[] = ! $ok;
			printf( "%s  %s%s\n", $ok ? 'FAIL' : 'KNOWN', $label, $ok ? ' — passes now: remove it from $known' : " — {$detail}" );
			return;
		}
		$results[] = $ok;
		printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
	};

	$summary = function () use ( &$results, &$known, $name ) {
		foreach ( $known as $label => $why ) {
			printf( "NOTE  known entry never ran on this site: %s\n", $label );
		}
		printf( "\n%s: %d/%d passed\n", $name, count( array_filter( $results ) ), count( $results ) );
		exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
	};

	// What the client gets is JSON: objects arrive as plain maps, so the
	// harness reads and resends exactly that shape.
	$call = function ( $method, $route, $body = null, $params = array() ) {
		$r = new WP_REST_Request( $method, $route );
		// The client sends the REST nonce on every request, and some write
		// routes require it (an application password must not reach them).
		$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
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

	// GET a route written with a query string, as descriptors write them.
	$get = function ( $route ) use ( $call ) {
		$parts  = explode( '?', ltrim( $route, '/' ), 2 );
		$params = array();
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $params );
		}
		return $call( 'GET', '/' . $parts[0], null, $params );
	};

	$on = function ( $v ) {
		return ! empty( $v ) && 'false' !== $v && '0' !== (string) $v && 'off' !== $v && 'no' !== $v;
	};

	$same = function ( $type, $a, $b ) use ( $on ) {
		if ( in_array( $type, array( 'true_false', 'toggle', 'switch', 'checkbox' ), true ) ) {
			return $on( $a ) === $on( $b );
		}
		if ( in_array( $type, array( 'number', 'integer' ), true ) && is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}
		if ( in_array( $type, array( 'multicheck', 'checks', 'tags' ), true ) ) {
			$a = array_map( 'strval', (array) $a );
			$b = array_map( 'strval', (array) $b );
			sort( $a );
			sort( $b );
			return $a === $b;
		}
		if ( ( null === $a || '' === $a || false === $a || array() === $a ) && ( null === $b || '' === $b || false === $b || array() === $b ) ) {
			return true;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			$norm = function ( $v ) use ( $type ) {
				$v = str_replace( "\r\n", "\n", (string) $v );
				return 'wysiwyg' === $type ? trim( $v ) : $v;
			};
			return $norm( $a ) === $norm( $b );
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	};

	/*
	 * An awkward but legitimate value for a field: backslashes, accents, a
	 * decimal, quotes and line breaks, which no store should alter. The
	 * markup-shaped ones (<3, &, %41) only go in raw, where the rule is that
	 * a save leaves them as stored. Null when the field takes no typed value.
	 */
	$value_for = function ( $type, $choices, $variant, $current ) use ( $on ) {
		$choices = is_array( $choices ) ? array_keys( $choices ) : array();
		switch ( $type ) {
			case 'text':
				return 1 === $variant ? 'Back\\slash é 3.50 \'apos\'' : 'C:\\files\\new ü';
			case 'textarea':
				return 1 === $variant ? "Line one \\d+ é\nLine two" : "Second\nset of lines";
			case 'wysiwyg':
				return 1 === $variant ? '<p>Para with <strong>bold</strong> and \\d+ é</p>' : '<p>Another <em>para</em></p>';
			case 'number':
				return 1 === $variant ? 1234.5 : 19.99;
			case 'integer':
				return 1 === $variant ? 1234 : 56;
			case 'email':
				return 1 === $variant ? 'first+tag@example.com' : 'second@example.org';
			case 'url':
				return 1 === $variant ? 'https://example.com/path?a=1&b=%20x' : 'https://example.org/other';
			case 'color_picker':
			case 'color':
				return 1 === $variant ? '#1a2b3c' : '#abcdef';
			case 'date':
				return 1 === $variant ? '2026-10-11' : '2027-01-31';
			case 'time':
				return 1 === $variant ? '13:45' : '08:05';
			case 'select':
			case 'radio':
			case 'combobox':
				foreach ( 1 === $variant ? $choices : array_reverse( $choices ) as $c ) {
					if ( (string) $c !== (string) $current && '' !== (string) $c ) {
						return (string) $c;
					}
				}
				return null;
			case 'multicheck':
			case 'checks':
				if ( count( $choices ) < 2 ) {
					return null;
				}
				return 1 === $variant ? array_map( 'strval', array_slice( $choices, 0, 2 ) ) : array( (string) end( $choices ) );
			case 'true_false':
			case 'toggle':
			case 'switch':
			case 'checkbox':
				return ! $on( $current );
		}
		return null;
	};

	/*
	 * The whole database as row hashes for options (named, transients left
	 * out) and checksums for every other table, so a save that touches
	 * anything shows. Tables whose rows are a log of the request itself
	 * (activity logs, analytics, the action scheduler) are left out.
	 */
	$noise_tables = '/(wsal_|simple_history|stream|aryo_activity|activity_log|aiowps_audit|actionscheduler_|koko_analytics|independent_analytics|burst_|statistics_|wpml_mails|fsmpt_email_logs|wpmailsmtp_|post_smtp|email_log|_sessions$|wc_sessions)/';
	$snapshot_db = function () use ( $noise_tables ) {
		global $wpdb;
		$out    = array();
		$tables = array();
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $t ) {
			if ( $t !== $wpdb->options && ! preg_match( $noise_tables, $t ) ) {
				$tables[] = $t;
			}
		}
		foreach ( $wpdb->get_results( 'CHECKSUM TABLE `' . implode( '`,`', $tables ) . '`', ARRAY_A ) as $r ) {
			$out[ 'table:' . preg_replace( '/^[^.]+\./', '', $r['Table'] ) ] = (string) $r['Checksum'];
		}
		$rows = $wpdb->get_results( "SELECT option_name, MD5(option_value) AS h FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name <> 'cron'", ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ 'option:' . $r['option_name'] ] = $r['h'];
		}
		return $out;
	};
	$db_changes = function ( $a, $b ) {
		$keys = array();
		foreach ( $a + $b as $k => $unused ) {
			if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) {
				$keys[] = $k;
			}
		}
		return $keys;
	};

	// A save this harness replayed and judged, for roundtrip-coverage: one
	// "METHOD /path" line per write, in the file roundtrip.sh names.
	$cover = function ( $method, $path ) {
		$file = getenv( 'MINN_RT_COVERED' );
		if ( $file ) {
			file_put_contents( $file, strtoupper( $method ) . ' /' . ltrim( (string) $path, '/' ) . "\n", FILE_APPEND );
		}
	};

	return compact( 'check', 'summary', 'call', 'get', 'on', 'same', 'value_for', 'snapshot_db', 'db_changes', 'cover' );
} );
