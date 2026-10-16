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
 * detail route to read back, they get the no-op check. Form entries
 * (roundtrip-entries-capture.js) get checks of their own: see $entry_checks.
 *
 * @package minn-admin
 */

$known = array();

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
$replay = function ( $c, $body ) use ( $call, $cover ) {
	$writes = ! empty( $c['writes'] ) ? $c['writes'] : array( array( 'method' => $c['method'], 'path' => $c['path'], 'query' => array(), 'body' => $c['body'] ) );
	$worst  = array( 0, '' );
	foreach ( $writes as $i => $w ) {
		$cover( $w['method'], $w['path'] );
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
		// The owner column $rows_of keys the row by.
		$names = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
		$owner = array_values( array_filter( $names, function ( $f ) {
			return '_id' === substr( $f, -3 ) && ! in_array( $f, array( 'meta_id', 'umeta_id' ), true );
		} ) )[0] ?? 'post_id';
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

/*
 * The no-op judgment: what a replay of $body changed, beyond timestamps,
 * bookkeeping and a vendor's own representation (each excused only if the
 * item reads back the same through its detail route). [ status, changes ].
 */
$unchanged = function ( $c, $body, $id ) use ( $wpdb, $changes_of, $values_around, $only_filled, $decode, $in_tx, $replay, $detail_of, $volatile, $representational, $verbose, $explain ) {
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
	return array( $st, $desc );
};

/*
 * Form entry answer editors (roundtrip-entries-capture.js). The entry page
 * sends only the answers a person changed, each with the value it loaded, so
 * each entry is held to:
 *   clean      Save with nothing changed sends nothing;
 *   as loaded  every answer sent back as loaded stores nothing: the route
 *              compares what it is sent with what is stored before any
 *              cleaning, and the page loads each answer as stored;
 *   text       a real edit of one answer changes that answer alone: every
 *              other answer reads back as it was through the entry route,
 *              the edited one reads back as typed, and every row the save
 *              changed holds the typed text or is the edit's own record
 *              ($entry_trail);
 *   pick       clicking one box of a multi-choice answer leaves every other
 *              answer, and a pick the form no longer offers (the `answers`
 *              seed) stays;
 *   action     the page's Star changes only what its body names, a second
 *              one stores nothing, and an answers save from a page opened
 *              before it keeps it.
 */
$entry_trail = array(
	// Table (prefix off) => why a real edit adds a row there.
	'gf_entry_notes' => 'the entry\'s notes say who changed which answers (Minn adds one per edit)',
);
$entry_typed = ' minnrt'; // what the capture types (roundtrip-entries-capture.js TYPED)
$entry_answers = function ( $c ) use ( $get ) {
	list( $st, $d ) = $get( str_replace( '{id}', rawurlencode( (string) $c['entry']['id'] ), $c['entry']['sectionsRoute'] ) );
	$out = array();
	foreach ( (array) ( 200 === $st && is_array( $d ) ? ( $d['edit']['fields'] ?? array() ) : array() ) as $f ) {
		foreach ( (array) $f['inputs'] as $i ) {
			$out[ (string) $i['id'] ] = $i['value'];
		}
	}
	return $out;
};
// One recorded write in the shape $replay and $unchanged take.
$entry_write = function ( $c, $w ) {
	$w += array( 'query' => array() );
	return array( 'surface' => $c['surface'], 'method' => $w['method'], 'path' => $w['path'], 'body' => $w['body'], 'writes' => array( $w ), 'route' => $c['route'], 'detailRoute' => $c['entry']['sectionsRoute'], 'preserve' => array(), 'actions' => array() );
};
// A row a text edit changed is that answer's own storage when, with the
// typed text taken out, it holds what it held: plain, or every differing
// leaf of a stored structure.
$entry_typed_only = function ( $before, $after ) use ( $decode, $entry_typed ) {
	if ( null === $before || null === $after || false === strpos( (string) $after, trim( $entry_typed ) ) ) {
		return false;
	}
	if ( str_replace( $entry_typed, '', (string) $after ) === (string) $before ) {
		return true;
	}
	$walk = function ( $x, $y ) use ( &$walk, $entry_typed ) {
		if ( is_array( $x ) && is_array( $y ) ) {
			if ( array_keys( $x ) !== array_keys( $y ) ) {
				return false;
			}
			foreach ( $x as $k => $v ) {
				if ( ! $walk( $v, $y[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $x === $y || ( is_string( $y ) && str_replace( $entry_typed, '', $y ) === (string) $x );
	};
	$a = $decode( $before );
	$b = $decode( $after );
	return is_array( $a ) && is_array( $b ) && $walk( $a, $b );
};
$entry_checks = function ( $c, $label ) use ( $wpdb, $check, $unchanged, $changes_of, $values_around, $in_tx, $replay, $checksums, $rows_of, $entry_answers, $entry_write, $entry_typed_only, $entry_trail ) {
	$id = (string) $c['entry']['id'];
	if ( ! empty( $c['seeded'] ) && $id !== (string) $c['seeded']['id'] ) {
		$check( "{$label}: the capture opened the seeded entry", false, "opened {$id}, seeded {$c['seeded']['id']}" );
		return;
	}
	$sent = function ( $writes ) {
		return implode( ' + ', array_map( function ( $w ) {
			return $w['method'] . ' ' . $w['path'] . ' ' . wp_json_encode( $w['body'] );
		}, (array) $writes ) );
	};

	// clean
	$check( "{$label}: Save with nothing changed sends nothing", empty( $c['clean'] ), $sent( $c['clean'] ?? array() ) );
	foreach ( (array) ( $c['clean'] ?? array() ) as $w ) {
		list( $st, $desc ) = $unchanged( $entry_write( $c, $w ), $w['body'], $id );
		$check( "{$label}: what the clean Save sent stores nothing", $st < 400 && ! $desc, $st . ( $desc ? ' changed: ' . implode( '; ', array_slice( $desc, 0, 6 ) ) : '' ) );
	}

	// as loaded: the route must take it (a refusal is a page that cannot
	// save what it loaded), and store nothing.
	$al  = $c['asLoaded'];
	$alw = $entry_write( $c, $al );
	list( $ast, $amsg ) = $in_tx( function () use ( $alw, $al, $replay ) {
		return $replay( $alw, $al['body'] );
	} );
	list( $st, $desc ) = $unchanged( $alw, $al['body'], $id );
	$check( "{$label}: every answer sent back as loaded stores nothing", $ast < 400 && ! $desc, $ast . ( '' !== $amsg ? " {$amsg}" : '' ) . ( $desc ? ' changed: ' . implode( '; ', array_slice( $desc, 0, 6 ) ) : '' ) );

	// text and pick
	$text = null;
	foreach ( (array) $c['edits'] as $e ) {
		if ( empty( $e['writes'] ) ) {
			$check( "{$label}: a {$e['what']} edit of {$e['input']} ({$e['label']}) sends a save", false, 'the page sent nothing' );
			continue;
		}
		$w  = $e['writes'][0];
		$wc = $entry_write( $c, $w );
		list( $was, $now, $st ) = $in_tx( function () use ( $c, $wc, $w, $replay, $entry_answers ) {
			$was = $entry_answers( $c );
			list( $st ) = $replay( $wc, $w['body'] );
			return array( $was, $entry_answers( $c ), $st );
		} );
		$others = array();
		foreach ( $was as $k => $v ) {
			if ( (string) $k !== (string) $e['input'] && wp_json_encode( $v ) !== wp_json_encode( $now[ $k ] ?? null ) ) {
				$others[] = "{$k} " . wp_json_encode( $v ) . ' → ' . wp_json_encode( $now[ $k ] ?? null );
			}
		}
		if ( 'text' === $e['what'] ) {
			$text = $e;
			list( $moved ) = $changes_of( $wc, $w['body'] );
			$stray = array();
			foreach ( $moved ? $values_around( $wc, $w['body'], $moved ) : array() as $row ) {
				list( $t, $key, $before, $after ) = $row;
				$short = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $t );
				if ( ( null === $before && isset( $entry_trail[ $short ] ) ) || $entry_typed_only( $before, $after ) ) {
					continue;
				}
				$stray[] = "{$t} {$key}";
			}
			$check( "{$label}: changing {$e['input']} ({$e['label']}) changes that answer alone", $st < 400 && ! $others && ! $stray,
				$st . ( $others ? ' other answers: ' . implode( '; ', array_slice( $others, 0, 4 ) ) : '' ) . ( $stray ? ' rows: ' . implode( '; ', array_slice( $stray, 0, 4 ) ) : '' ) );
			$check( "{$label}: the changed answer reads back as typed", $st < 400 && (string) ( $now[ $e['input'] ] ?? '' ) === (string) $e['typed'], wp_json_encode( $now[ $e['input'] ] ?? null ) . ', typed ' . wp_json_encode( $e['typed'] ) );
			continue;
		}
		$check( "{$label}: clicking a box of {$e['input']} ({$e['label']}) leaves the other answers", $st < 400 && ! $others, $st . ( $others ? ' ' . implode( '; ', array_slice( $others, 0, 4 ) ) : '' ) );
		foreach ( (array) ( $c['seeded']['retired'] ?? array() ) as $k => $pick ) {
			$v    = $now[ $k ] ?? null;
			$kept = is_array( $v ) ? in_array( (string) $pick, array_map( 'strval', $v ), true ) : (string) $v === (string) $pick;
			$check( "{$label}: a pick the form no longer offers stays ({$k})", $st < 400 && $kept, 'now ' . wp_json_encode( $v ) );
		}
	}

	// action
	foreach ( (array) $c['actions'] as $a ) {
		$w    = $a['writes'][0];
		$ac   = $entry_write( $c, $w );
		$keys = array_map( 'strval', array_keys( (array) $w['body'] ) );
		list( $moved, $st ) = $changes_of( $ac, $w['body'] );
		$beyond = array();
		foreach ( $moved as $t => $rows ) {
			foreach ( $rows as $k ) {
				$cols = preg_match( '/\((.*)\)$/', $k, $m ) ? explode( ',', $m[1] ) : array();
				if ( ! $cols || array_diff( $cols, $keys ) ) {
					$beyond[] = "{$t} {$k}";
				}
			}
		}
		$check( "{$label}: {$a['label']} changes only " . implode( ', ', $keys ), $st < 400 && $moved && ! $beyond, $st . ( $moved ? '' : ' changed nothing' ) . ( $beyond ? ' also: ' . implode( '; ', array_slice( $beyond, 0, 4 ) ) : '' ) );
		$again = $in_tx( function () use ( $ac, $w, $replay, $checksums ) {
			$replay( $ac, $w['body'] );
			$x = $checksums();
			$replay( $ac, $w['body'] );
			return array_keys( array_diff_assoc( $checksums(), $x ) );
		} );
		$check( "{$label}: a second {$a['label']} stores nothing", ! $again, implode( ', ', $again ) );
		if ( $text && $moved ) {
			$tw   = $text['writes'][0];
			$lost = $in_tx( function () use ( $c, $ac, $w, $tw, $moved, $replay, $rows_of, $entry_write ) {
				$replay( $ac, $w['body'] );
				$snap = array();
				foreach ( array_keys( $moved ) as $t ) {
					$snap[ $t ] = $rows_of( $t );
				}
				$replay( $entry_write( $c, $tw ), $tw['body'] );
				$lost = array();
				foreach ( $moved as $t => $rows ) {
					$after = $rows_of( $t );
					foreach ( $rows as $k ) {
						$key  = preg_replace( '/^[+-]|\(.*\)$/', '', $k );
						$cols = preg_match( '/\((.*)\)$/', $k, $m ) ? explode( ',', $m[1] ) : array();
						foreach ( $cols as $col ) {
							if ( ( $snap[ $t ][ $key ][ "h_{$col}" ] ?? null ) !== ( $after[ $key ][ "h_{$col}" ] ?? null ) ) {
								$lost[] = "{$t} {$key}.{$col}";
							}
						}
					}
				}
				return $lost;
			} );
			$check( "{$label}: an answers save from a page opened before {$a['label']} keeps it", ! $lost, implode( '; ', $lost ) );
		}
	}
};

foreach ( (array) $captures as $c ) {
	// A seeded run (lib/roundtrip-seed.php) is its own ratchet line.
	$label = $c['surface'] . ( ! empty( $c['seeded']['set'] ) ? " [{$c['seeded']['set']}]" : '' );
	if ( ! empty( $c['skip'] ) || empty( $c['path'] ) ) {
		printf( "SKIP  %s: %s\n", $label, $c['skip'] ?? 'no capture' );
		continue;
	}
	if ( ! empty( $c['entry'] ) ) {
		$entry_checks( $c, $label );
		continue;
	}
	$body = (array) $c['body'];
	$id   = $item_id( $c );
	if ( ! empty( $c['seeded'] ) && (string) $id !== (string) $c['seeded']['id'] ) {
		$check( "{$label}: the capture saved the seeded item", false, 'saved ' . wp_json_encode( $id ) . ', seeded ' . $c['seeded']['id'] );
		continue;
	}

	// No-op.
	list( $st, $desc ) = $unchanged( $c, $body, $id );
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
			if ( $field === $k || ! array_key_exists( $field, $body ) || ! array_key_exists( $field, $now ) || in_array( $field, $coupled[ "{$c['surface']}:{$k}" ] ?? array(), true ) ) {
				continue;
			}
			if ( ! $same( 'text', $old, $now[ $field ] ) ) {
				$leaks[] = "changing {$k} changed {$field}";
			}
		}
	}
	$check( "{$label}: changing one field leaves the others", ! $leaks, implode( '; ', array_slice( $leaks, 0, 6 ) ) );

	// Concurrency: a key one of the list's actions sets (an on/off switch)
	// keeps the action's value when a modal opened before the action saves.
	$current = $detail_of( $c, $id );
	$seen    = array();
	foreach ( (array) $c['actions'] as $a ) {
		foreach ( (array) ( is_array( $a['body'] ) ? $a['body'] : array() ) as $key => $set_to ) {
			if ( isset( $seen[ $key ] ) || ! is_array( $current ) || ! array_key_exists( $key, $current ) || $same( 'toggle', $set_to, $current[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			list( $ast, $after ) = $in_tx( function () use ( $c, $a, $body, $key, $call, $replay, $detail_of, $id ) {
				list( $ast ) = $call( $a['method'], '/' . ltrim( str_replace( '{id}', rawurlencode( (string) $id ), $a['route'] ), '/' ), $a['body'] );
				$replay( $c, $body );
				$now = $detail_of( $c, $id );
				return array( $ast, is_array( $now ) && array_key_exists( $key, $now ) ? $now[ $key ] : null );
			} );
			$check( "{$label}: a save from a modal opened before an action keeps {$key} as the action set it",
				$ast < 400 && $same( 'toggle', $set_to, $after ),
				"action {$a['label']} ({$ast}) set " . wp_json_encode( $set_to ) . ', after the stale save ' . wp_json_encode( $after ) );
		}
	}
}

$summary();
