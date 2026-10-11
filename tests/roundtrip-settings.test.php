<?php
/**
 * Round-trip harness for surface settings pages (the `settings` descriptor).
 *
 * A settings form sends only the keys that changed: `{ values: { key: v } }`
 * to the tab's route, and `{ values: {} }` when nothing did. Its saves often
 * replay a vendor's own settings handler, which may rebuild a whole option
 * from what it was given. So, per tab:
 *
 *   no-op      an empty save stores nothing anywhere in the database;
 *   fidelity   a value typed into one field reads back as typed;
 *   isolation  that save leaves every other field of the tab as it was.
 *
 * These are live site settings on a shared lab, so every option is backed
 * up raw at the start and put back after each tab (and on any exit), and a
 * Gravity Forms form whose settings are tested is restored through GFAPI.
 * Keys that could lock the suites out are never typed into.
 *
 * Run: wp eval-file tests/roundtrip-settings.test.php --user=admin --path=<site> [MINN_RT_SURFACE=perfmatters]
 *
 * @package minn-admin
 */

$known = array();

$rt = ( require __DIR__ . '/lib/roundtrip.php' )( 'roundtrip-settings', $known );
extract( $rt ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

$only = getenv( 'MINN_RT_SURFACE' ) ?: '';

/*
 * Differences the vendor's own settings screen makes from the same input:
 * 'surface:key' or 'surface:type' => reason.
 */
$parity = array(
	// Deliberate: fields Perfmatters prints raw into a quoted attribute or
	// an inline script lose quotes and angle brackets on the way in
	// (adapters/perfmatters.php, the raw-output rule).
	'perfmatters:perfmatters_options:lazyload:threshold'    => 'raw-output field, quotes stripped on purpose',
	'perfmatters:perfmatters_options:fonts:cdn_url'          => 'raw-output field, quotes stripped on purpose',
	'perfmatters:perfmatters_options:cdn:cdn_url'            => 'raw-output field, quotes stripped on purpose',
	'perfmatters:perfmatters_options:analytics:tracking_id'  => 'raw-output field, quotes stripped on purpose',
);

/*
 * Keys whose change legitimately moves other fields, so they are not typed
 * into one at a time: 'surface:key' => reason.
 */
$coupled = array(
	// Picking another service reloads the tab with that service's fields.
	'gravity-smtp:primary_connector' => 'the tab shows the picked service',
	// Asset CleanUp's own settings filter answers the delivery options as
	// off while Google Fonts removal is on (filterSettings()).
	'asset-cleanup:google_fonts_combine'    => 'off while Google Fonts removal is on',
	'asset-cleanup:google_fonts_preconnect' => 'off while Google Fonts removal is on',
);

// Never typed into: the harness's own requests and every later suite
// depend on these staying as they are.
$deny = '/disable_rest_api|login|maintenance|password|secret|api_?key|token/i';

$typed = array( 'text', 'textarea', 'number', 'email', 'url', 'color', 'color_picker', 'select', 'radio', 'combobox', 'toggle', 'true_false', 'switch', 'checkbox', 'multicheck', 'checks' );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );

global $wpdb;
$filter = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name <> 'cron'";
$backup = array();
foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$filter}", ARRAY_A ) as $r ) {
	$backup[ $r['option_name'] ] = $r;
}
$restore_options = function () use ( &$backup, $wpdb, $filter ) {
	$put  = array();
	$seen = array();
	foreach ( $wpdb->get_results( "SELECT option_name, MD5(option_value) AS h FROM {$wpdb->options} WHERE {$filter}", ARRAY_A ) as $r ) {
		$n          = $r['option_name'];
		$seen[ $n ] = true;
		if ( ! isset( $backup[ $n ] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $n ) );
			$put[] = $n;
		} elseif ( md5( $backup[ $n ]['option_value'] ) !== $r['h'] ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $backup[ $n ]['option_value'], 'autoload' => $backup[ $n ]['autoload'] ), array( 'option_name' => $n ) );
			$put[] = $n;
		}
	}
	foreach ( $backup as $n => $row ) {
		if ( ! isset( $seen[ $n ] ) ) {
			$wpdb->insert( $wpdb->options, $row );
			$put[] = $n;
		}
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( $put as $n ) {
		wp_cache_delete( $n, 'options' );
	}
	return $put;
};
$gf_backup = null;
register_shutdown_function( function () use ( $restore_options, &$gf_backup ) {
	$restore_options();
	if ( $gf_backup && class_exists( 'GFAPI' ) ) {
		GFAPI::update_form( $gf_backup );
	}
} );

$list = $call( 'GET', '/minn-admin/v1/surfaces' )[1];
$list = isset( $list['surfaces'] ) ? $list['surfaces'] : $list;

foreach ( (array) $list as $s ) {
	if ( empty( $s['settings']['route'] ) || ( '' !== $only && $only !== $s['id'] ) ) {
		continue;
	}
	$cfg  = $s['settings'];
	$item = '';
	if ( false !== strpos( $cfg['route'], '{id}' ) ) {
		// Item-scoped settings (a form's own settings): the first item of
		// the list the settings belong to (the manage view's, when the
		// surface has one).
		$coll = $s['manage']['route'] ?? ( $s['collection']['route'] ?? '' );
		list( , $rows ) = $coll && false === strpos( $coll, '{' ) ? $get( $coll ) : array( 0, array() );
		foreach ( array( 'items', 'rows', 'data' ) as $k ) {
			if ( is_array( $rows ) && isset( $rows[ $k ] ) && is_array( $rows[ $k ] ) ) {
				$rows = $rows[ $k ];
				break;
			}
		}
		$first = is_array( $rows ) ? reset( $rows ) : null;
		$item  = is_array( $first ) && isset( $first['id'] ) ? (string) $first['id'] : '';
		if ( '' === $item ) {
			printf( "SKIP  %s: no item to scope its settings to\n", $s['id'] );
			continue;
		}
		if ( 'gravity-forms' === $s['id'] && class_exists( 'GFAPI' ) ) {
			$gf_backup = GFAPI::get_form( (int) $item );
		}
	}
	foreach ( (array) $cfg['tabs'] as $tab ) {
		$route = str_replace( array( '{tab}', '{id}' ), array( $tab['id'], rawurlencode( $item ) ), $tab['route'] ?? $cfg['route'] );
		$label = "{$s['id']}/{$tab['id']}";
		list( $status, $form ) = $get( $route );
		if ( 200 !== $status || ! is_array( $form ) ) {
			$check( "{$label}: the form loads", false, (string) $status );
			continue;
		}
		$fields = array();
		foreach ( (array) ( $form['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				$key = $f['key'] ?? ( $f['name'] ?? null );
				if ( null === $key || ! empty( $f['readonly'] ) || ! empty( $f['locked'] ) ) {
					continue;
				}
				$choices = $f['choices'] ?? null;
				if ( ! $choices && isset( $f['options'] ) && is_array( $f['options'] ) ) {
					$choices = array();
					foreach ( $f['options'] as $o ) {
						if ( is_array( $o ) && array_key_exists( 0, $o ) ) {
							$choices[ (string) $o[0] ] = $o[1] ?? $o[0];
						}
					}
				}
				$fields[ $key ] = array( 'type' => $f['type'] ?? 'text', 'choices' => $choices );
			}
		}
		$values_of = function () use ( $get, $route ) {
			list( , $f ) = $get( $route );
			return is_array( $f ) && isset( $f['values'] ) && is_array( $f['values'] ) ? $f['values'] : array();
		};
		$save = function ( $values ) use ( $call, $route ) {
			list( $st, $d ) = $call( 'POST', '/' . $route, array( 'values' => (object) $values ) );
			return array( $st, is_array( $d ) && isset( $d['message'] ) ? $d['message'] : '' );
		};
		try {
			// No-op: what the form sends when nothing changed.
			$before = $snapshot_db();
			list( $st, $msg ) = $save( array() );
			$moved = $db_changes( $before, $snapshot_db() );
			$check( "{$label}: an empty save stores nothing", in_array( $st, array( 200, 204 ), true ) && ! $moved, $st . ' ' . $msg . ( $moved ? ' changed: ' . implode( ', ', array_slice( $moved, 0, 8 ) ) : '' ) );

			// One field at a time: it reads back as typed, the rest stay.
			$bad   = array();
			$leaks = array();
			foreach ( $fields as $key => $f ) {
				if ( ! in_array( $f['type'], $typed, true ) || preg_match( $deny, (string) $key ) || isset( $coupled[ "{$s['id']}:{$key}" ] ) ) {
					continue;
				}
				$was = $values_of();
				// Settings numbers are counts and days: type a whole one.
				$v   = $value_for( 'number' === $f['type'] ? 'integer' : $f['type'], $f['choices'], 1, $was[ $key ] ?? null );
				if ( null === $v ) {
					continue;
				}
				list( $st, $msg ) = $save( array( $key => $v ) );
				if ( ! in_array( $st, array( 200, 204 ), true ) ) {
					$bad[] = "{$key} refused ({$st} {$msg})";
					continue;
				}
				$now = $values_of();
				if ( ! isset( $parity[ "{$s['id']}:{$key}" ] ) && ! isset( $parity[ "{$s['id']}:{$f['type']}" ] ) && ! $same( $f['type'], $v, $now[ $key ] ?? null ) ) {
					$bad[] = "{$key} ({$f['type']}) sent " . wp_json_encode( $v ) . ' read ' . wp_json_encode( $now[ $key ] ?? null );
				}
				foreach ( $was as $other => $old ) {
					if ( $other !== $key && ! $same( $fields[ $other ]['type'] ?? 'text', $old, $now[ $other ] ?? null ) ) {
						$leaks[] = "saving {$key} changed {$other}: " . wp_json_encode( $old ) . ' -> ' . wp_json_encode( $now[ $other ] ?? null );
					}
				}
			}
			$check( "{$label}: typed values read back as typed", ! $bad, implode( '; ', array_slice( $bad, 0, 6 ) ) );
			$check( "{$label}: saving one field leaves the others", ! $leaks, implode( '; ', array_slice( $leaks, 0, 6 ) ) );

			if ( 'gravity-smtp/sending' === $label && isset( $fields['primary_connector'], $fields['from_email'] ) ) {
				// One save that both picks another service and edits a field
				// the tab showed for the current one.
				$was   = $values_of();
				// An API-key service: its tab shows a From address (OAuth
				// services draw a sign-in flow instead).
				$other = null;
				foreach ( array( 'brevo', 'sendgrid', 'mailgun', 'postmark', 'sparkpost' ) as $c ) {
					if ( isset( $fields['primary_connector']['choices'][ $c ] ) && $c !== ( $was['primary_connector'] ?? '' ) ) {
						$other = $c;
						break;
					}
				}
				$probe = 'switch-probe@example.com';
				if ( null === $other ) {
					$check( "{$label}: switching the service and editing a field in one save keeps the field out of the new service", true, 'no API-key service to switch to' );
				} else {
				list( $st ) = $save( array( 'primary_connector' => $other, 'from_email' => $probe ) );
				$now = $values_of();
				$check( "{$label}: switching the service and editing a field in one save keeps the field out of the new service",
					( $st >= 400 ) || ( ( $now['from_email'] ?? '' ) !== $probe ),
					"{$st}; {$other} from_email now " . wp_json_encode( $now['from_email'] ?? null ) );
				}
			}
		} finally {
			$restore_options();
			if ( $gf_backup ) {
				GFAPI::update_form( $gf_backup );
			}
		}
	}
	$gf_backup = null;
}

$summary();
