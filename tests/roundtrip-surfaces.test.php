<?php
/**
 * Round-trip harness for surface edit modals (`collection.detail.edit`).
 *
 * The modal sends every edit field it shows plus its `preserve` keys. The
 * bodies come from the real client: roundtrip-surfaces-capture.js opens each
 * surface's first item, clicks Save without touching anything and records
 * the request. Replayed here, per surface:
 *
 *   no-op        the captured body stores nothing (timestamps aside);
 *   isolation    changing one field in it leaves the item's other fields;
 *   concurrency  a key the modal carries in `preserve` (an on/off switch)
 *                and that one of the surface's actions changes keeps the
 *                action's value when a modal opened before it saves.
 *
 * Every replay runs inside a database transaction that is rolled back, so
 * the lab's items end exactly as they started.
 *
 * Run: node roundtrip-surfaces-capture.js /tmp/minn-surface-saves.json
 *      MINN_RT_CAPTURE=/tmp/minn-surface-saves.json wp eval-file tests/roundtrip-surfaces.test.php --user=admin --path=<site>
 * Minn's own pages (roundtrip-pages-capture.js) replay the same way; with no
 * detail route to read back, they get the no-op check.
 *
 * @package minn-admin
 */

$known = array(
	'settings:Content: an unchanged save stores nothing'                                           => '11-1: the Content section resends the permalinks, which rebuilds the rewrite rules',
	'order: an unchanged save stores nothing'                                                      => 'H3: an empty shipping address is saved with the billing name',
	'hfcm: a save from a modal opened before an action keeps active as the action set it'          => '04-4: the modal resends the switch it loaded',
	'wpcode: a save from a modal opened before an action keeps active as the action set it'        => '04-4: the modal resends the switch it loaded',
	'code-snippets: a save from a modal opened before an action keeps active as the action set it' => '04-4: the modal resends the switch it loaded',
);

$rt = ( require __DIR__ . '/lib/roundtrip.php' )( 'roundtrip-surfaces', $known );
extract( $rt ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

/*
 * Storage a vendor rewrites on every save of its own, with the same meaning:
 * accepted only when the item reads back identically through its detail
 * route. 'table.column' (options: 'wp_options.name') => reason.
 */
$representational = array(
	'redirection_items.match_data' => 'Redirection stores match flags equal to the site defaults as "use the defaults"',
	'options.redirection_options'  => 'Redirection remembers the last group a redirect was saved in (last_group_id)',
	'options.wpcode_snippets'      => 'WPCode rebuilds its cache of active snippets on every save',
);
// Fields whose change legitimately moves another: 'surface:field' => fields.
$coupled = array(
	// WPCode empties the location when auto-insert is off (class-wpcode-snippet.php save).
	'wpcode:auto_insert' => array( 'location' ),
);

$file = getenv( 'MINN_RT_CAPTURE' ) ?: sys_get_temp_dir() . '/minn-surface-saves.json';
if ( ! is_readable( $file ) ) {
	echo "SKIP  no capture at {$file}: run roundtrip-surfaces-capture.js first\n";
	return;
}
$captures = json_decode( (string) file_get_contents( $file ), true );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

global $wpdb;
$noise    = '/(yoast_indexable|yoast_seo_links|wsal_|simple_history|stream|aryo_activity|activity_log|aiowps_audit|actionscheduler_|koko_analytics|independent_analytics|burst_|statistics_|wpml_mails|fsmpt_email_logs|wpmailsmtp_|post_smtp|email_log|_sessions$|wc_sessions)/';
// Rows other code rewrites on any save, holding no value of the item:
// bookkeeping stamps and caches, and CSS other block plugins generate.
$noise_options = '/^(tribe_last_|_wpforms_transient|wpforms_dashboard_cache_generation)/';
$noise_meta    = '/^(stackable_optimized_css|_spectra_gs_jit_css|_order_version|_product_version|_edit_lock|_edit_last|_tribe_modified_fields)/';
$volatile = '/(^guid$|option_id|modified|updated|_at$|^last_|lastmod|timestamp|date_gmt|^hits$|^count$|last_count|last_access)/i';
$tables   = array_values( array_filter( $wpdb->get_col( 'SHOW TABLES' ), function ( $t ) use ( $noise ) {
	return ! preg_match( $noise, $t );
} ) );

$checksums = function () use ( $wpdb, $tables ) {
	$out = array();
	foreach ( $wpdb->get_results( 'CHECKSUM TABLE `' . implode( '`,`', $tables ) . '`', ARRAY_A ) as $r ) {
		$out[ preg_replace( '/^[^.]+\./', '', $r['Table'] ) ] = (string) $r['Checksum'];
	}
	return $out;
};
// Rows of one table, keyed by primary key (options by name), each a map of
// column => hash over the columns that are not timestamps or counters. Meta
// tables are a multiset of key + value, so a delete-and-reinsert of the same
// value is not a change.
$rows_of = function ( $table ) use ( $wpdb, $volatile, $noise_options, $noise_meta ) {
	$cols  = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
	$names = array_column( $cols, 'Field' );
	if ( preg_match( '/meta$/', $table ) && in_array( 'meta_key', $names, true ) ) {
		$owner = array_values( array_filter( $names, function ( $f ) {
			return '_id' === substr( $f, -3 ) && ! in_array( $f, array( 'meta_id', 'umeta_id' ), true );
		} ) )[0] ?? 'meta_key';
		$out = array();
		$skip_rev = $table === $wpdb->postmeta ? " WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision')" : '';
		foreach ( $wpdb->get_results( "SELECT `{$owner}` AS o, meta_key AS k, MD5(meta_value) AS h FROM `{$table}`{$skip_rev}", ARRAY_A ) as $r ) {
			if ( preg_match( $noise_meta, $r['k'] ) ) {
				continue;
			}
			$out[ "{$r['o']}:{$r['k']}" ]['meta_value'][] = $r['h'];
		}
		foreach ( $out as &$row ) {
			sort( $row['meta_value'] );
			$row['meta_value'] = implode( ',', $row['meta_value'] );
		}
		return $out;
	}
	$pk = $table === $wpdb->options ? 'option_name' : null;
	foreach ( $cols as $c ) {
		if ( null === $pk && 'PRI' === $c['Key'] ) {
			$pk = $c['Field'];
		}
	}
	$pk    = $pk ? $pk : $names[0];
	$keep  = array_values( array_filter( $names, function ( $f ) use ( $volatile ) {
		return ! preg_match( $volatile, $f );
	} ) );
	$where = $table === $wpdb->options ? " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name <> 'cron'" : '';
	if ( $table === $wpdb->posts ) {
		// Revisions are history a core update writes on its own (the first
		// update of a never-revised post always makes one).
		$where = " WHERE post_type <> 'revision'";
	}
	$sel   = implode( ', ', array_map( function ( $f ) {
		return "MD5(`{$f}`) AS `h_{$f}`";
	}, $keep ) );
	$out   = array();
	foreach ( $wpdb->get_results( "SELECT `{$pk}` AS p, {$sel} FROM `{$table}`{$where}", ARRAY_A ) as $r ) {
		$key = (string) $r['p'];
		if ( $table === $wpdb->options && preg_match( $noise_options, $key ) ) {
			continue;
		}
		unset( $r['p'] );
		$out[ $key ] = $r;
	}
	return $out;
};
$in_tx = function ( $fn ) use ( $wpdb ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		return $fn();
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
};
// A page's Save may send several requests (CF7 saves, then previews); they
// replay in order, the first carrying the body under test.
$replay = function ( $c, $body ) use ( $call ) {
	$writes = ! empty( $c['writes'] ) ? $c['writes'] : array( array( 'method' => $c['method'], 'path' => $c['path'], 'query' => array(), 'body' => $c['body'] ) );
	$worst  = array( 0, '' );
	foreach ( $writes as $i => $w ) {
		list( $st, $d ) = $call( $w['method'], '/' . ltrim( $w['path'], '/' ), 0 === $i ? $body : $w['body'], (array) ( $w['query'] ?? array() ) );
		if ( $st >= $worst[0] ) {
			$worst = array( $st, is_array( $d ) && isset( $d['message'] ) ? $d['message'] : '' );
		}
	}
	return $worst;
};
// What a save changed beyond timestamps: table => changed row keys.
$changes_of = function ( $c, $body ) use ( $in_tx, $checksums, $rows_of, $replay ) {
	$touched = $in_tx( function () use ( $c, $body, $checksums, $replay ) {
		$a = $checksums();
		$replay( $c, $body );
		$b = $checksums();
		return array_keys( array_diff_assoc( $b, $a ) );
	} );
	if ( ! $touched ) {
		return array( array(), 200 );
	}
	return $in_tx( function () use ( $c, $body, $touched, $rows_of, $replay ) {
		$before = array();
		foreach ( $touched as $t ) {
			$before[ $t ] = $rows_of( $t );
		}
		list( $st ) = $replay( $c, $body );
		$moved = array();
		foreach ( $touched as $t ) {
			$after = $rows_of( $t );
			foreach ( $before[ $t ] + $after as $k => $unused ) {
				$b = $before[ $t ][ $k ] ?? null;
				$a = $after[ $k ] ?? null;
				if ( $b === $a ) {
					continue;
				}
				if ( null === $b || null === $a ) {
					$moved[ $t ][] = ( null === $b ? '+' : '-' ) . $k;
					continue;
				}
				$cols = array();
				foreach ( $b as $col => $h ) {
					if ( ( $a[ $col ] ?? null ) !== $h ) {
						$cols[] = preg_replace( '/^h_/', '', $col );
					}
				}
				$moved[ $t ][] = $k . ( $cols ? '(' . implode( ',', $cols ) . ')' : '' );
			}
		}
		return array( $moved, $st );
	} );
};
// MINN_RT_VERBOSE=1: the stored values around the first difference of each
// changed row, for triage.
$verbose = (bool) getenv( 'MINN_RT_VERBOSE' );
$value_of = function ( $table, $key ) use ( $wpdb, $volatile ) {
	if ( $table === $wpdb->options ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	}
	if ( preg_match( '/meta$/', $table ) && false !== strpos( $key, ':' ) ) {
		list( $o, $k ) = explode( ':', $key, 2 );
		$owner = $table === $wpdb->postmeta ? 'post_id' : ( $table === $wpdb->usermeta ? 'user_id' : 'post_id' );
		$vals = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM `{$table}` WHERE `{$owner}` = %s AND meta_key = %s", $o, $k ) );
		return $vals ? implode( ' | ', $vals ) : null;
	}
	$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
	$pk   = null;
	foreach ( $cols as $c ) {
		if ( 'PRI' === $c['Key'] ) {
			$pk = $c['Field'];
			break;
		}
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `" . ( $pk ? $pk : $cols[0]['Field'] ) . '` = %s', $key ), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	foreach ( array_keys( $row ) as $col ) {
		if ( preg_match( $volatile, $col ) ) {
			unset( $row[ $col ] );
		}
	}
	return wp_json_encode( $row );
};
/*
 * A stored structure the save only filled in: nothing removed, every value
 * it held unchanged (false, 0, '' and '0' count as the same, as do true, 1
 * and '1'), keys added with the vendor's defaults. That is the vendor's own
 * editor writing its whole object, not a change to the item.
 */
$decode = function ( $v ) {
	if ( ! is_string( $v ) ) {
		return $v;
	}
	$j = json_decode( $v, true );
	if ( is_array( $j ) ) {
		return $j;
	}
	if ( is_serialized( $v ) ) {
		$u = @unserialize( $v, array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( is_array( $u ) ) {
			return $u;
		}
	}
	return $v;
};
$loose = function ( $a ) {
	if ( in_array( $a, array( false, 0, '0', '', null ), true ) ) {
		return 'falsy';
	}
	if ( in_array( $a, array( true, 1, '1' ), true ) ) {
		return 'truthy';
	}
	return is_scalar( $a ) ? (string) $a : $a;
};
$only_filled = function ( $was, $now ) use ( &$only_filled, $decode, $loose ) {
	$was = $decode( $was );
	$now = $decode( $now );
	if ( is_array( $was ) && is_array( $now ) ) {
		foreach ( $was as $k => $v ) {
			if ( ! array_key_exists( $k, $now ) || ! $only_filled( $v, $now[ $k ] ) ) {
				return false;
			}
		}
		return true;
	}
	return $loose( $was ) === $loose( $now );
};
// The changed rows' stored values, before and after one replay.
$values_around = function ( $c, $body, $moved ) use ( $in_tx, $replay, $value_of ) {
	return $in_tx( function () use ( $c, $body, $moved, $replay, $value_of ) {
		$keys = array();
		foreach ( $moved as $t => $list ) {
			foreach ( $list as $k ) {
				$keys[] = array( $t, preg_replace( '/^[+-]|\(.*\)$/', '', $k ) );
			}
		}
		$before = array();
		foreach ( $keys as $tk ) {
			$before[] = $value_of( $tk[0], $tk[1] );
		}
		$replay( $c, $body );
		$out = array();
		foreach ( $keys as $i => $tk ) {
			$out[] = array( $tk[0], $tk[1], $before[ $i ], $value_of( $tk[0], $tk[1] ) );
		}
		return $out;
	} );
};

$show_diff = function ( $a, $b ) {
	$a = (string) $a;
	$b = (string) $b;
	$i = 0;
	$n = min( strlen( $a ), strlen( $b ) );
	while ( $i < $n && $a[ $i ] === $b[ $i ] ) {
		$i++;
	}
	$from = max( 0, $i - 60 );
	return '…' . substr( $a, $from, 160 ) . "…\n        → …" . substr( $b, $from, 160 ) . '…';
};
$explain = function ( $c, $body, $moved ) use ( $in_tx, $replay, $value_of, $show_diff ) {
	$in_tx( function () use ( $c, $body, $moved, $replay, $value_of, $show_diff ) {
		$before = array();
		foreach ( $moved as $t => $keys ) {
			foreach ( $keys as $k ) {
				$key = preg_replace( '/^[+-]|\(.*\)$/', '', $k );
				$before[ $t ][ $key ] = $value_of( $t, $key );
			}
		}
		$replay( $c, $body );
		foreach ( $before as $t => $rows ) {
			foreach ( $rows as $key => $was ) {
				printf( "      %s %s: %s\n", $t, $key, $show_diff( $was, $value_of( $t, $key ) ) );
			}
		}
	} );
};

$item_id = function ( $c ) {
	$re = '#^/?' . str_replace( '\\{id\\}', '([^/]+)', preg_quote( ltrim( $c['route'], '/' ), '#' ) ) . '$#';
	return preg_match( $re, ltrim( $c['path'], '/' ), $m ) ? rawurldecode( $m[1] ) : null;
};
$detail_of = function ( $c, $id ) use ( $get ) {
	if ( empty( $c['detailRoute'] ) || null === $id ) {
		return null;
	}
	list( $st, $d ) = $get( str_replace( '{id}', rawurlencode( $id ), $c['detailRoute'] ) );
	return 200 === $st && is_array( $d ) ? $d : null;
};

foreach ( (array) $captures as $c ) {
	$label = $c['surface'];
	if ( ! empty( $c['skip'] ) || empty( $c['path'] ) ) {
		printf( "SKIP  %s: %s\n", $label, $c['skip'] ?? 'no capture' );
		continue;
	}
	$body = (array) $c['body'];
	$id   = $item_id( $c );

	// No-op.
	list( $moved, $st ) = $changes_of( $c, $body );
	$desc     = array();
	$excused  = array();
	foreach ( $moved as $t => $keys ) {
		$short = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $t );
		foreach ( $keys as $k ) {
			$cols = preg_match( '/^(.*)\((.*)\)$/', $k, $m ) ? explode( ',', $m[2] ) : array();
			$name = $m[1] ?? $k;
			$ok   = $cols && ! array_diff( array_map( function ( $col ) use ( $short, $name ) {
				return 'options' === $short ? "options.{$name}" : "{$short}.{$col}";
			}, $cols ), array_keys( $representational ) );
			if ( $ok ) {
				$excused[] = "{$t} {$k}";
			} else {
				$desc[] = "{$t} {$k}";
			}
		}
	}
	if ( $desc ) {
		// A copy a vendor derives from the item's own fields on every save
		// of its own: CF7 keeps a text copy of its properties in post_content.
		$desc = array_values( array_filter( $desc, function ( $d ) use ( $wpdb ) {
			if ( preg_match( '/^' . preg_quote( $wpdb->posts, '/' ) . ' (\d+)\(post_content\)$/', $d, $m ) && 'wpcf7_contact_form' === get_post_type( (int) $m[1] ) ) {
				return false;
			}
			return true;
		} ) );
	}
	if ( $desc ) {
		// Rows the save only filled in are the vendor's own normalization.
		$real = array();
		foreach ( $values_around( $c, $body, $moved ) as $row ) {
			list( $t, $key, $was, $now ) = $row;
			if ( null !== $was && $only_filled( $was, $now ) ) {
				$excused[] = "{$t} {$key} (filled in)";
				continue;
			}
			// A row that appears holding only an empty value reads the same
			// as the row being absent (get_post_meta answers '' or array()).
			$empty = $decode( $now );
			if ( null === $was && ( '' === $empty || array() === $empty || null === $empty ) ) {
				$excused[] = "{$t} {$key} (empty row added)";
				continue;
			}
			$real[] = "{$t} {$key}";
		}
		$desc = array_values( array_filter( $desc, function ( $d ) use ( $real ) {
			foreach ( $real as $r ) {
				if ( 0 === strpos( preg_replace( '/^([^ ]+ )[+-]/', '$1', $d ), $r ) ) {
					return true;
				}
			}
			return false;
		} ) );
	}
	if ( $excused && ! $desc ) {
		// Representational only if the item reads back the same.
		list( $was, $now ) = $in_tx( function () use ( $c, $body, $replay, $detail_of, $id ) {
			$was = $detail_of( $c, $id );
			$replay( $c, $body );
			return array( $was, $detail_of( $c, $id ) );
		} );
		$strip = function ( $d ) use ( $volatile ) {
			return is_array( $d ) ? array_filter( $d, function ( $k ) use ( $volatile ) {
				return ! preg_match( $volatile, (string) $k );
			}, ARRAY_FILTER_USE_KEY ) : $d;
		};
		if ( wp_json_encode( $strip( $was ) ) !== wp_json_encode( $strip( $now ) ) ) {
			$desc = $excused;
		}
	}
	if ( $verbose && $desc ) {
		$explain( $c, $body, $moved );
	}
	$check( "{$label}: an unchanged save stores nothing", $st < 400 && ! $desc, $st . ( $desc ? ' changed: ' . implode( '; ', array_slice( $desc, 0, 6 ) ) : '' ) );

	if ( empty( $c['detailRoute'] ) ) {
		continue;
	}
	// Isolation: each typed field changed alone, read back through the
	// surface's own detail route.
	$leaks = array();
	foreach ( $body as $k => $v ) {
		if ( in_array( $k, (array) $c['preserve'], true ) || ! is_scalar( $v ) ) {
			continue;
		}
		$type = is_bool( $v ) ? 'toggle' : ( is_int( $v ) || is_float( $v ) ? 'integer' : 'text' );
		$new  = $value_for( $type, null, 1, $v );
		if ( null === $new || in_array( $k, array( 'code', 'url', 'match_data', 'regex' ), true ) ) {
			continue; // code and match rules are not free text
		}
		$res = $in_tx( function () use ( $c, $body, $k, $new, $replay, $detail_of, $id ) {
			$was = $detail_of( $c, $id );
			$b   = $body;
			$b[ $k ] = $new;
			list( $st ) = $replay( $c, $b );
			return array( $was, $detail_of( $c, $id ), $st );
		} );
		list( $was, $now, $st ) = $res;
		if ( ! is_array( $was ) || ! is_array( $now ) ) {
			continue;
		}
		foreach ( $was as $field => $old ) {
			if ( $field === $k || ! array_key_exists( $field, $body ) || ! array_key_exists( $field, $now ) || in_array( $field, $coupled[ "{$label}:{$k}" ] ?? array(), true ) ) {
				continue;
			}
			if ( ! $same( 'text', $old, $now[ $field ] ) ) {
				$leaks[] = "changing {$k} changed {$field}";
			}
		}
	}
	$check( "{$label}: changing one field leaves the others", ! $leaks, implode( '; ', array_slice( $leaks, 0, 6 ) ) );

	// Concurrency: a preserved key an action sets.
	foreach ( (array) $c['preserve'] as $key ) {
		foreach ( (array) $c['actions'] as $a ) {
			if ( ! is_array( $a['body'] ) || ! array_key_exists( $key, $a['body'] ) || ! array_key_exists( $key, $body ) || $same( 'toggle', $a['body'][ $key ], $body[ $key ] ) ) {
				continue;
			}
			$res = $in_tx( function () use ( $c, $a, $body, $key, $call, $replay, $detail_of, $id ) {
				list( $ast ) = $call( $a['method'], '/' . ltrim( str_replace( '{id}', rawurlencode( (string) $id ), $a['route'] ), '/' ), $a['body'] );
				$replay( $c, $body );
				$now = $detail_of( $c, $id );
				return array( $ast, is_array( $now ) && array_key_exists( $key, $now ) ? $now[ $key ] : null );
			} );
			list( $ast, $after ) = $res;
			$check( "{$label}: a save from a modal opened before an action keeps {$key} as the action set it",
				$ast < 400 && $same( 'toggle', $a['body'][ $key ], $after ),
				"action {$a['label']} ({$ast}) set " . wp_json_encode( $a['body'][ $key ] ) . ', after the stale save ' . wp_json_encode( $after ) );
			break;
		}
	}
}

$summary();
