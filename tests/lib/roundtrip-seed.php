<?php
/**
 * Stored values for the surface round trip that a lab's ordinary data never
 * holds: a name with a %XX-shaped run, an entity and inline markup, a text
 * with line breaks, an empty name. They are what a vendor's own screen, an
 * import or another tool can leave in its store, and what a save that
 * rewrites untouched fields gets wrong (the 0.44.0 and 0.45.0 registry
 * scans found that class 23 times). With them in place, the capture pass
 * presses Save on the item and the replay checks that nothing changed.
 *
 * Where a field is stored is found, not declared: inside a transaction that
 * is rolled back, the surface's own edit route saves a marker into the
 * field, and the cell that took the marker is the field's storage. The
 * hostile value is then written into that cell directly, as the vendor's
 * own store would hold it, never through Minn's save (which is what is under
 * test). Every cell written is backed up first and put back by `restore`.
 *
 * The `answers` set does the same for form entries (the entry page's answer
 * editor, roundtrip-entries-capture.js): on the first editable entry per
 * forms plugin (lib/roundtrip-fixtures.php), a free-text answer holds
 * `Minn 50%DEAL &amp; <em>x</em>`, and a multi-choice answer holds a pick the
 * form no longer offers. That pick's cell is found by unticking it through
 * the answers route in a rolled-back transaction: the cell that held the pick
 * and changed. A checkbox input then holds the retired pick in place of its
 * own (a choice renamed since); a list of picks gets it added.
 *
 *   MINN_RT_SEED=apply   MINN_RT_SEED_SET=rich|empty|answers  MINN_RT_SEED_FILE=<state.json>
 *   MINN_RT_SEED=restore MINN_RT_SEED_FILE=<state.json>
 *   wp eval-file tests/lib/roundtrip-seed.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$mode = getenv( 'MINN_RT_SEED' ) ?: '';
$file = getenv( 'MINN_RT_SEED_FILE' ) ?: '';
if ( '' === $file || ! in_array( $mode, array( 'apply', 'restore' ), true ) ) {
	echo "usage: MINN_RT_SEED=apply|restore MINN_RT_SEED_FILE=<state.json> [MINN_RT_SEED_SET=rich|empty|answers]\n";
	exit( 2 );
}

global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

// Put every backed-up cell back, byte for byte, and say so per cell.
$restore = function ( $state ) use ( $wpdb ) {
	$bad = 0;
	foreach ( array_reverse( (array) ( $state['cells'] ?? array() ) ) as $c ) {
		if ( ! empty( $c['inserted'] ) ) {
			$wpdb->delete( $c['table'], array( $c['pk'] => $c['id'] ) );
			$gone = null === $wpdb->get_var( $wpdb->prepare( "SELECT `{$c['pk']}` FROM `{$c['table']}` WHERE `{$c['pk']}` = %s", $c['id'] ) );
			$bad += $gone ? 0 : 1;
			printf( "%s  removed the seeded row %s #%s\n", $gone ? 'OK  ' : 'FAIL', $c['table'], $c['id'] );
			continue;
		}
		$was = empty( $c['was_null'] ) ? base64_decode( $c['was'] ) : null;
		$wpdb->update( $c['table'], array( $c['column'] => $was ), array( $c['pk'] => $c['id'] ) );
		$now = $wpdb->get_var( $wpdb->prepare( "SELECT `{$c['column']}` FROM `{$c['table']}` WHERE `{$c['pk']}` = %s", $c['id'] ) );
		$ok  = null === $was ? null === $now : (string) $now === $was;
		$bad += $ok ? 0 : 1;
		printf( "%s  restored %s.%s #%s\n", $ok ? 'OK  ' : 'FAIL', $c['table'], $c['column'], $c['id'] );
	}
	if ( ! empty( $state['cells'] ) ) {
		wp_cache_flush();
	}
	return $bad;
};

if ( 'restore' === $mode ) {
	$state = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $state ) ) {
		echo "nothing to restore ({$file})\n";
		exit( 0 );
	}
	$bad = $restore( $state );
	if ( 0 === $bad ) {
		unlink( $file );
	}
	exit( $bad ? 1 : 0 );
}

if ( is_readable( $file ) ) {
	// A run that ended without restoring: put that back before seeding again.
	echo "a previous seed was never restored; restoring it first\n";
	if ( $restore( json_decode( (string) file_get_contents( $file ), true ) ) ) {
		echo "FAIL  could not restore the previous seed; not seeding\n";
		exit( 1 );
	}
	unlink( $file );
}

$set = getenv( 'MINN_RT_SEED_SET' ) ?: 'rich';
$rt  = ( require __DIR__ . '/roundtrip.php' )( 'roundtrip-seed', array() );
$call = $rt['call'];
$get  = $rt['get'];

$marker = 'minnseed' . substr( md5( (string) microtime( true ) ), 0, 10 );
/*
 * The value a field's store holds in each set. Rich: a %XX-shaped run
 * (sanitize_text_field deletes "%DE"), an entity, inline markup, quotes and
 * an accent; with line breaks for a textarea. Empty: what an unnamed item
 * holds, for a field the dialog does not require.
 */
$hostile = function ( $type, $required ) use ( $set ) {
	if ( 'empty' === $set ) {
		return $required ? null : '';
	}
	return 'textarea' === $type
		? "Minn 50%DEAL &amp; é\n\nLine two <b>bold</b>, \"quoted\""
		: "Minn 50%DEAL &amp; <em>Fish</em> 'chips' é";
};
// Free text only: codes, addresses and patterns have rules of their own.
$skip_key = '/(^|[._])(code|url|uri|regex|match|email|slug|path|domain|key|token|selector|css|js|html|php)$/i';
// Stores no rolled-back save can undo, and copies a store derives itself.
$noise      = '/(yoast_indexable|yoast_seo_links|wsal_|simple_history|stream|aryo_activity|activity_log|aiowps_audit|actionscheduler_|koko_analytics|independent_analytics|burst_|statistics_|wpml_mails|fsmpt_email_logs|wpmailsmtp_|post_smtp|email_log|_sessions$|wc_sessions)/';
$derived    = '/^(post_name|guid|slug|.*_slug|.*_hash|.*_cache)$/';
$skip_rows  = function ( $table, $row ) use ( $wpdb ) {
	if ( $table === $wpdb->options ) {
		return 0 === strpos( (string) $row['option_name'], '_transient' ) || 0 === strpos( (string) $row['option_name'], '_site_transient' );
	}
	if ( $table === $wpdb->posts ) {
		return 'revision' === ( $row['post_type'] ?? '' );
	}
	return false;
};

$tables = array_values( array_filter( $wpdb->get_col( 'SHOW TABLES' ), function ( $t ) use ( $noise ) {
	return ! preg_match( $noise, $t );
} ) );
// A rollback undoes only transactional tables.
$no_undo = $wpdb->get_col( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB'" );
$checksums = function () use ( $wpdb, $tables ) {
	$out = array();
	foreach ( $wpdb->get_results( 'CHECKSUM TABLE `' . implode( '`,`', $tables ) . '`', ARRAY_A ) as $r ) {
		$out[ preg_replace( '/^[^.]+\./', '', $r['Table'] ) ] = (string) $r['Checksum'];
	}
	return $out;
};
// Small files outside the code folders: a store that keeps items as files
// (Fluent Snippets) writes there, and a rollback cannot undo a file.
$file_roots = function () {
	$skip = array( 'plugins', 'themes', 'mu-plugins', 'uploads', 'cache', 'upgrade', 'languages', 'upgrade-temp-backup' );
	$out  = array();
	foreach ( (array) glob( WP_CONTENT_DIR . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( ! in_array( basename( $d ), $skip, true ) ) {
			$out[] = $d;
		}
	}
	return $out;
};
$files_now = function () use ( $file_roots ) {
	$out = array();
	foreach ( $file_roots() as $root ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && $f->getSize() < 262144 ) {
				$out[ $f->getPathname() ] = (string) file_get_contents( $f->getPathname() );
			}
		}
	}
	return $out;
};
$pk_of = function ( $table ) use ( $wpdb ) {
	foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $c ) {
		if ( 'PRI' === $c['Key'] ) {
			return $c['Field'];
		}
	}
	return null;
};
// Every cell of the changed tables holding the marker.
$find = function ( $changed ) use ( $wpdb, $marker, $pk_of, $derived, $skip_rows ) {
	$hits = array();
	foreach ( $changed as $table ) {
		$pk = $pk_of( $table );
		if ( ! $pk ) {
			continue;
		}
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $col ) {
			if ( ! preg_match( '/char|text|blob|json/i', $col['Type'] ) || preg_match( $derived, $col['Field'] ) ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$col['Field']}` LIKE %s", '%' . $wpdb->esc_like( $marker ) . '%' ), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( ! $skip_rows( $table, $row ) ) {
					$hits[] = array( 'table' => $table, 'pk' => $pk, 'id' => (string) $row[ $pk ], 'column' => $col['Field'], 'after' => (string) $row[ $col['Field'] ], 'row' => $row );
				}
			}
		}
	}
	return $hits;
};
// The cell's stored shape: plain, PHP-serialized or JSON.
$decode = function ( $v ) {
	if ( is_serialized( $v ) ) {
		$u = @unserialize( $v, array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( false !== $u || 'b:0;' === $v ) {
			return array( 'php', $u );
		}
	}
	if ( '' !== $v && ( '{' === $v[0] || '[' === $v[0] ) ) {
		$j = json_decode( $v, true );
		if ( null !== $j ) {
			return array( 'json', $j );
		}
	}
	return array( 'plain', $v );
};
$encode = function ( $kind, $data, $like ) {
	if ( 'php' === $kind ) {
		return serialize( $data );
	}
	$flags = ( false === strpos( $like, '\\/' ) ? JSON_UNESCAPED_SLASHES : 0 ) | ( false === strpos( $like, '\\u' ) ? JSON_UNESCAPED_UNICODE : 0 );
	return wp_json_encode( $data, $flags );
};
// Paths to the marker inside a decoded structure.
$paths = function ( $data, $prefix = array() ) use ( &$paths, $marker ) {
	$out = array();
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) {
			$out = array_merge( $out, $paths( $v, array_merge( $prefix, array( $k ) ) ) );
		}
	} elseif ( is_string( $data ) && false !== strpos( $data, $marker ) ) {
		$out[] = $prefix;
	}
	return $out;
};
$set_path = function ( &$data, $path, $value ) {
	$ref = &$data;
	foreach ( $path as $k ) {
		if ( ! is_array( $ref ) || ! array_key_exists( $k, $ref ) ) {
			return false;
		}
		$ref = &$ref[ $k ];
	}
	$ref = $value;
	return true;
};
$dig = function ( $data, $key ) {
	foreach ( explode( '.', $key ) as $k ) {
		if ( ! is_array( $data ) || ! array_key_exists( $k, $data ) ) {
			return null;
		}
		$data = $data[ $k ];
	}
	return $data;
};
$nest = function ( $key, $value ) {
	$parts = explode( '.', $key );
	$out   = $value;
	foreach ( array_reverse( $parts ) as $k ) {
		$out = array( $k => $out );
	}
	return $out;
};

$list  = $call( 'GET', '/minn-admin/v1/surfaces' )[1];
$list  = $list['surfaces'] ?? $list;
$state = array( 'set' => $set, 'marker' => $marker, 'cells' => array(), 'surfaces' => array() );
$save  = function () use ( &$state, $file ) {
	file_put_contents( $file, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
};

/*
 * Where a save puts a value: each body (the marker in it) is sent through the
 * route inside a transaction that is rolled back, and the cells that took the
 * marker are its storage. Returns [ hits or null, files the save wrote (put
 * back) ]. A save that changes a table a rollback cannot undo stops the run.
 */
$locate = function ( $label, $method, $route, array $bodies ) use ( $wpdb, $call, $checksums, $find, $no_undo, $files_now, $save ) {
	$files_before = $files_now();
	$found        = null;
	foreach ( $bodies as $body ) {
		$wpdb->query( 'START TRANSACTION' );
		try {
			$a = $checksums();
			list( $wst ) = $call( $method, $route, $body );
			$changed = array_keys( array_diff_assoc( $checksums(), $a ) );
			$hits    = $wst < 400 ? $find( $changed ) : array();
			if ( array_intersect( $changed, $no_undo ) ) {
				printf( "FAIL  %s: the save changed %s, which a rollback cannot undo; stopping\n", $label, implode( ', ', array_intersect( $changed, $no_undo ) ) );
				$wpdb->query( 'ROLLBACK' );
				$save();
				exit( 1 );
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
		// A field's storage is a cell of a row the item already had, or a
		// meta row the save adds for it. Any other new row means the save
		// made something else (a create), so try the next body.
		$hits = array_values( array_filter( $hits, function ( $h ) use ( $wpdb ) {
			return preg_match( '/meta$/', $h['table'] ) || null !== $wpdb->get_var( $wpdb->prepare( "SELECT `{$h['pk']}` FROM `{$h['table']}` WHERE `{$h['pk']}` = %s", $h['id'] ) );
		} ) );
		if ( $hits ) {
			$found = $hits;
			break;
		}
	}
	// A save that wrote files: put them back, and seed nothing there.
	$files_after = $files_now();
	$wrote       = array();
	foreach ( $files_after + $files_before as $path => $unused ) {
		if ( ( $files_before[ $path ] ?? null ) !== ( $files_after[ $path ] ?? null ) ) {
			$wrote[] = $path;
			if ( isset( $files_before[ $path ] ) ) {
				file_put_contents( $path, $files_before[ $path ] );
			} else {
				unlink( $path );
			}
		}
	}
	return array( $found, $wrote );
};

// Write $value into each cell that took the marker, backing it up first.
// Returns how many cells now hold it.
$plant = function ( $sid, $key, array $found, $value ) use ( $wpdb, $marker, $decode, $encode, $paths, $set_path, &$state, $save ) {
	$n = 0;
	foreach ( $found as $h ) {
		$now_row = $wpdb->get_row( $wpdb->prepare( "SELECT `{$h['pk']}` AS p, `{$h['column']}` AS v FROM `{$h['table']}` WHERE `{$h['pk']}` = %s", $h['id'] ), ARRAY_A );
		$was     = $now_row ? $now_row['v'] : null;
		if ( ! $now_row ) {
			// A row the save adds (a meta key the item did not have yet):
			// add it holding the value, as the vendor would have.
			list( $kind ) = $decode( $h['after'] );
			if ( 'plain' !== $kind ) {
				printf( "SKIP  %s %s: the save adds a structured %s row the seed cannot write\n", $sid, $key, $h['table'] );
				continue;
			}
			$row = $h['row'];
			unset( $row[ $h['pk'] ] );
			$row[ $h['column'] ] = str_replace( $marker, $value, $h['after'] );
			if ( false === $wpdb->insert( $h['table'], $row ) ) {
				printf( "SKIP  %s %s: could not add its %s row\n", $sid, $key, $h['table'] );
				continue;
			}
			$state['cells'][] = array( 'surface' => $sid, 'field' => $key, 'table' => $h['table'], 'pk' => $h['pk'], 'id' => (string) $wpdb->insert_id, 'column' => $h['column'], 'inserted' => true );
			$save();
			$n++;
			printf( "SEED  %s %s → new %s row #%s\n", $sid, $key, $h['table'], $wpdb->insert_id );
			continue;
		}
		$was_null = null === $was;
		$was      = (string) $was;
		list( $kind, $after ) = $decode( $h['after'] );
		if ( 'plain' === $kind ) {
			$new = $h['after'] === $marker ? $value : str_replace( $marker, $value, $h['after'] );
		} else {
			list( $wkind, $data ) = $decode( $was );
			$ok = $wkind === $kind;
			foreach ( $paths( $after ) as $path ) {
				$ok = $ok && $set_path( $data, $path, $value );
			}
			if ( ! $ok ) {
				printf( "SKIP  %s %s: %s.%s holds it in a shape the seed cannot write\n", $sid, $key, $h['table'], $h['column'] );
				continue;
			}
			$new = $encode( $kind, $data, $was );
		}
		$state['cells'][] = array( 'surface' => $sid, 'field' => $key, 'table' => $h['table'], 'pk' => $h['pk'], 'id' => $h['id'], 'column' => $h['column'], 'was' => base64_encode( $was ), 'was_null' => $was_null );
		$save(); // backed up before the write, so a crash can still restore
		$wpdb->update( $h['table'], array( $h['column'] => $new ), array( $h['pk'] => $h['id'] ) );
		$n++;
		printf( "SEED  %s %s → %s.%s #%s\n", $sid, $key, $h['table'], $h['column'], $h['id'] );
	}
	return $n;
};

if ( 'answers' === $set ) {
	$retired = 'Minn retired choice';
	$text    = 'Minn 50%DEAL &amp; <em>x</em>';
	// The entries the capture opens; its own scope, so its variables stay there.
	$fx = ( function () {
		$GLOBALS['minn_rt_fixtures_return'] = true;
		return require __DIR__ . '/roundtrip-fixtures.php';
	} )();
	$sections = array();
	foreach ( (array) $list as $s ) {
		if ( ! empty( $s['collection']['detail']['sectionsRoute'] ) ) {
			$sections[ $s['id'] ] = $s['collection']['detail']['sectionsRoute'];
		}
	}
	$state['entries'] = array();
	$by_fixture       = array( 'gf_entry' => 'gravity-forms', 'elementor_entry' => 'elementor-forms', 'nf_entry' => 'ninja-forms', 'wpforms_entry' => 'wpforms' );

	/*
	 * The cells holding a pick: unticking it through the answers route,
	 * inside a rolled-back transaction, shows which tables it touches; the
	 * cells of those tables that hold the pick now and change when it is
	 * unticked again (in a second rolled-back transaction) are its storage.
	 */
	$pick_cells = function ( $label, $route, $body, $pick, $exact ) use ( $wpdb, $call, $checksums, $no_undo, $pk_of, $derived, $save ) {
		$wpdb->query( 'START TRANSACTION' );
		try {
			$a = $checksums();
			list( $st ) = $call( 'POST', $route, $body );
			$changed = array_keys( array_diff_assoc( $checksums(), $a ) );
			if ( array_intersect( $changed, $no_undo ) ) {
				printf( "FAIL  %s: unticking changed %s, which a rollback cannot undo; stopping\n", $label, implode( ', ', array_intersect( $changed, $no_undo ) ) );
				$wpdb->query( 'ROLLBACK' );
				$save();
				exit( 1 );
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
		if ( $st >= 400 ) {
			return array();
		}
		$cands = array();
		foreach ( $changed as $table ) {
			$pk = $pk_of( $table );
			if ( ! $pk ) {
				continue;
			}
			foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $col ) {
				if ( ! preg_match( '/char|text|blob|json/i', $col['Type'] ) || preg_match( $derived, $col['Field'] ) ) {
					continue;
				}
				$where = $exact ? $wpdb->prepare( "`{$col['Field']}` = %s", $pick ) : $wpdb->prepare( "`{$col['Field']}` LIKE %s", '%' . $wpdb->esc_like( $pick ) . '%' );
				foreach ( $wpdb->get_results( "SELECT `{$pk}` AS p, `{$col['Field']}` AS v FROM `{$table}` WHERE {$where} LIMIT 200", ARRAY_A ) as $r ) {
					$cands[] = array( 'table' => $table, 'pk' => $pk, 'id' => (string) $r['p'], 'column' => $col['Field'], 'was' => (string) $r['v'] );
				}
			}
		}
		if ( ! $cands ) {
			return array();
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$call( 'POST', $route, $body );
			$cells = array();
			foreach ( $cands as $c ) {
				$now = $wpdb->get_var( $wpdb->prepare( "SELECT `{$c['column']}` FROM `{$c['table']}` WHERE `{$c['pk']}` = %s", $c['id'] ) );
				if ( null === $now || (string) $now !== $c['was'] ) {
					$cells[] = $c;
				}
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
		return $cells;
	};

	foreach ( $by_fixture as $fk => $sid ) {
		if ( empty( $fx[ $fk ] ) || empty( $sections[ $sid ] ) ) {
			printf( "SKIP  %s: no editable entry on this site\n", $sid );
			continue;
		}
		$id = (string) $fx[ $fk ];
		list( $dst, $d ) = $get( str_replace( '{id}', rawurlencode( $id ), $sections[ $sid ] ) );
		$edit = 200 === $dst && is_array( $d ) && ! empty( $d['edit']['fields'] ) ? $d['edit'] : null;
		if ( ! $edit ) {
			printf( "SKIP  %s #%s: the entry route offers no edit block\n", $sid, $id );
			continue;
		}
		$route = '/' . ltrim( str_replace( '{id}', rawurlencode( $id ), $edit['route'] ), '/' );
		$rec   = array( 'id' => $id, 'inputs' => array(), 'retired' => array() );

		// A free-text answer: a single-line one or a name part first.
		$target = null;
		foreach ( array( array( 'text', 'parts' ), array( 'textarea' ) ) as $kinds ) {
			foreach ( $edit['fields'] as $f ) {
				foreach ( in_array( $f['kind'], $kinds, true ) ? $f['inputs'] : array() as $i ) {
					if ( ! $target && is_string( $i['value'] ) && '' !== $i['value'] ) {
						$target = $i;
					}
				}
			}
		}
		if ( $target ) {
			$key = (string) $target['id'];
			list( $found, $wrote ) = $locate( "{$sid} #{$id} {$key}", 'POST', $route, array( array( 'values' => array( $key => $marker ), 'original' => array( $key => $target['value'] ) ) ) );
			if ( $wrote ) {
				printf( "SKIP  %s %s: its save writes files (%s); put back, not seeded\n", $sid, $key, implode( ', ', array_map( 'basename', $wrote ) ) );
			} elseif ( ! $found ) {
				printf( "SKIP  %s %s: no database cell took the answer\n", $sid, $key );
			} elseif ( $plant( $sid, $key, $found, $text ) ) {
				$rec['inputs'][ $key ] = $text;
			}
		} else {
			printf( "SKIP  %s #%s: no free-text answer holds a value\n", $sid, $id );
		}

		// A multi-choice answer holding a pick the form no longer offers.
		$multi = null;
		foreach ( $edit['fields'] as $f ) {
			foreach ( $f['inputs'] as $i ) {
				if ( $multi ) {
					break 2;
				}
				if ( 'checks' === $f['kind'] && '' !== (string) $i['value'] ) {
					$multi = array( 'field' => $f, 'input' => $i, 'pick' => (string) $i['value'], 'body' => array( 'values' => array( $i['id'] => false ), 'original' => array( $i['id'] => $i['value'] ) ) );
				} elseif ( 'multi' === $f['kind'] && is_array( $i['value'] ) && $i['value'] ) {
					$picks = array_values( array_map( 'strval', $i['value'] ) );
					$multi = array( 'field' => $f, 'input' => $i, 'pick' => $picks[0], 'picks' => $picks, 'body' => array( 'values' => array( $i['id'] => array_slice( $picks, 1 ) ), 'original' => array( $i['id'] => $i['value'] ) ) );
				}
			}
		}
		if ( ! $multi ) {
			printf( "SKIP  %s #%s: no multi-choice answer holds a pick\n", $sid, $id );
		} else {
			$key   = (string) $multi['input']['id'];
			$cells = $pick_cells( "{$sid} #{$id} {$key}", $route, $multi['body'], $multi['pick'], 'checks' === $multi['field']['kind'] );
			$wrote = 0;
			foreach ( $cells as $c ) {
				$was = $c['was'];
				$new = null;
				if ( 'checks' === $multi['field']['kind'] ) {
					// One input per choice: it holds the retired pick in place of its own.
					$new = $was === $multi['pick'] ? $retired : null;
				} else {
					list( $kind, $data ) = $decode( $was );
					if ( 'plain' !== $kind && is_array( $data ) && in_array( $multi['pick'], array_map( 'strval', $data ), true ) && array_values( $data ) === $data ) {
						$data[] = $retired;
						$new    = $encode( $kind, $data, $was );
					} elseif ( 'plain' === $kind ) {
						// The picks joined the way this store joins them.
						foreach ( array( ', ', "\n", ',' ) as $sep ) {
							if ( implode( $sep, $multi['picks'] ) === $was ) {
								$new = $was . $sep . $retired;
								break;
							}
						}
					}
				}
				if ( null === $new ) {
					printf( "SKIP  %s %s: %s.%s holds the pick in a shape the seed cannot extend\n", $sid, $key, $c['table'], $c['column'] );
					continue;
				}
				$state['cells'][] = array( 'surface' => $sid, 'field' => $key, 'table' => $c['table'], 'pk' => $c['pk'], 'id' => $c['id'], 'column' => $c['column'], 'was' => base64_encode( $was ), 'was_null' => false );
				$save();
				$wpdb->update( $c['table'], array( $c['column'] => $new ), array( $c['pk'] => $c['id'] ) );
				$wrote++;
				printf( "SEED  %s %s → %s.%s #%s (a pick the form no longer offers)\n", $sid, $key, $c['table'], $c['column'], $c['id'] );
			}
			if ( $wrote ) {
				$rec['retired'][ $key ] = $retired;
			} elseif ( ! $cells ) {
				printf( "SKIP  %s %s: no database cell held the pick\n", $sid, $key );
			}
		}
		if ( $rec['inputs'] || $rec['retired'] ) {
			$state['entries'][ $sid ] = $rec;
		}
		$save();
	}
	wp_cache_flush();
	$save();
	printf( "\nseeded %d cell(s) on %d entr%s (set answers); state in %s\n", count( $state['cells'] ), count( $state['entries'] ), 1 === count( $state['entries'] ) ? 'y' : 'ies', $file );
	exit( 0 );
}

foreach ( (array) $list as $s ) {
	$edit = $s['collection']['detail']['edit'] ?? null;
	if ( ! $edit || empty( $edit['route'] ) || empty( $s['collection']['route'] ) ) {
		continue;
	}
	$sid  = $s['id'];
	$note = function ( $msg ) use ( &$state, $sid ) {
		$state['surfaces'][ $sid ]['note'] = $msg;
		printf( "SKIP  %s: %s\n", $sid, $msg );
	};
	list( $st, $rows ) = $get( $s['collection']['route'] );
	$items = is_array( $rows ) ? ( $rows['items'] ?? ( isset( $rows[0] ) ? $rows : array() ) ) : array();
	if ( 200 !== $st || ! $items || ! isset( $items[0]['id'] ) ) {
		$note( 'no first item' );
		continue;
	}
	$id     = (string) $items[0]['id'];
	$detail = $items[0];
	if ( ! empty( $s['collection']['detail']['detailRoute'] ) ) {
		list( $dst, $d ) = $get( str_replace( '{id}', rawurlencode( $id ), $s['collection']['detail']['detailRoute'] ) );
		if ( 200 === $dst && is_array( $d ) ) {
			$detail = $d;
		}
	}
	$route  = '/' . ltrim( str_replace( '{id}', rawurlencode( $id ), $edit['route'] ), '/' );
	$method = $edit['method'] ?? 'POST';
	$state['surfaces'][ $sid ] = array( 'id' => $id, 'fields' => array() );
	foreach ( (array) ( $edit['fields'] ?? array() ) as $f ) {
		$key  = (string) ( $f['key'] ?? '' );
		$type = (string) ( $f['type'] ?? 'text' );
		if ( '' === $key || ! in_array( $type, array( 'text', 'textarea' ), true ) || ! empty( $f['mono'] ) || preg_match( $skip_key, $key ) ) {
			continue;
		}
		$value = $hostile( $type, ! empty( $f['required'] ) );
		if ( null === $value ) {
			continue;
		}
		// Find the field's cell: a partial save first, then the whole body
		// the dialog would send.
		$whole = array();
		foreach ( (array) ( $edit['fields'] ?? array() ) as $g ) {
			$v = $dig( $detail, (string) $g['key'] );
			if ( null !== $v ) {
				$whole = array_replace_recursive( $whole, $nest( (string) $g['key'], $v ) );
			}
		}
		foreach ( (array) ( $edit['preserve'] ?? array() ) as $p ) {
			if ( null !== $dig( $detail, (string) $p ) ) {
				$whole = array_replace_recursive( $whole, $nest( (string) $p, $dig( $detail, (string) $p ) ) );
			}
		}
		$whole = array_replace_recursive( $whole, $nest( $key, $marker ) );
		list( $found, $wrote ) = $locate( "{$sid} {$key}", $method, $route, array( $nest( $key, $marker ), $whole ) );
		if ( $wrote ) {
			$note( "{$key}: its save writes files (" . implode( ', ', array_map( 'basename', $wrote ) ) . '); put back, not seeded' );
			continue 2;
		}
		if ( ! $found ) {
			printf( "SKIP  %s %s: no database cell took the field\n", $sid, $key );
			continue;
		}
		if ( $plant( $sid, $key, $found, $value ) ) {
			$state['surfaces'][ $sid ]['fields'][ $key ] = $value;
		}
	}
	$save();
}
wp_cache_flush();
$save();
printf( "\nseeded %d cell(s) on %d surface(s) (set %s); state in %s\n", count( $state['cells'] ), count( array_filter( $state['surfaces'], function ( $x ) {
	return ! empty( $x['fields'] );
} ) ), $set, $file );
exit( 0 );
