<?php
/**
 * Round-trip harness for the editor's field panels.
 *
 * Every panel shares one contract: a fields route describes the post's
 * fields, the post's REST response carries their values under `valuesKey`,
 * and the client writes the whole values object back under `writeKey` when
 * any one of them changes. So the panels can be checked generically, the way
 * the client really saves, for the properties a save must keep:
 *
 *   fidelity   a value typed into a field reads back as typed;
 *   no-op      saving the values exactly as read changes nothing stored,
 *              and saving them again changes nothing either;
 *   isolation  changing one field leaves every other field as it was;
 *   partial    a save carrying only some fields leaves the absent ones;
 *   concurrent a field someone else changed after the page loaded keeps
 *              that change when the page saves a different field (or the
 *              save answers 409 so the person reloads).
 *
 * Values are deliberately awkward but legitimate (backslashes, %XX octets,
 * a literal <3, decimals, line breaks), the shapes past findings were made of.
 * A difference a vendor's own form makes too can be listed in $parity with
 * the reason; anything else fails.
 *
 * Run: [MINN_RT_PANEL=minn_acf] wp eval-file tests/roundtrip-panels.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

if ( ! defined( 'REST_REQUEST' ) ) {
	// Vendors branch on this (Otter, ACF): answer as a real REST save would.
	define( 'REST_REQUEST', true );
}

$only    = isset( $assoc_args['panel'] ) ? (string) $assoc_args['panel'] : ( getenv( 'MINN_RT_PANEL' ) ?: '' );
/*
 * The ratchet: checks that fail today because of an open finding. Such a
 * check reports KNOWN and does not fail the run; one that starts passing
 * fails it until its line here is removed, so the list only shrinks. A
 * failure not listed here is a regression.
 */
$known = array(
);
$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results, &$known ) {
	set_time_limit( 0 );
	$listed = isset( $known[ $label ] );
	if ( $listed ) {
		unset( $known[ $label ] );
		$results[] = ! $ok;
		printf( "%s  %s%s\n", $ok ? 'FAIL' : 'KNOWN', $label, $ok ? ' — passes now: remove it from $known' : " — {$detail}" );
		return;
	}
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
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
	// What the client gets is JSON: objects arrive as plain maps, so the
	// harness reads and resends exactly that shape.
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};

/*
 * Differences the vendor's own form makes from the same input, so they are
 * not Minn's: 'valuesKey:type' or 'valuesKey:field' => reason.
 */
$parity = array(
	// ACPT's own save unslashes what its form posts, so a backslash typed
	// there is lost too (known and accepted as parity in v0.44.0).
	'minn_acpt:text' => 'ACPT strips backslashes on its own save',
);
if ( defined( 'WPSEO_VERSION' ) ) {
	// Yoast saves every SEO text through its own sanitize_text_field, which
	// turns any run of whitespace, line breaks included, into one space
	// (wordpress-seo/inc/class-wpseo-utils.php, sanitize_text_field).
	foreach ( array( 'description', 'facebook_description', 'twitter_description' ) as $k ) {
		$parity[ "minn_seo:{$k}" ] = 'Yoast keeps descriptions on one line';
	}
}

/*
 * Field types the harness types into. Media, relations, repeaters and
 * flexible content keep whatever the post holds and still take part in the
 * no-op and isolation checks.
 */
/*
 * Fields the descriptor types as text but whose store wants a shape: the
 * value typed into them is one their own form would accept.
 */
$hints = array(
	'minn_tec:start'            => array( '2026-10-11 09:00', '2026-10-12 10:30' ),
	'minn_tec:end'              => array( '2026-10-11 17:00', '2026-10-12 18:15' ),
	'minn_wpjm:_job_expires'    => array( '2026-12-31', '2027-01-15' ),
	'minn_wpjm:_application'    => array( 'apply+jobs@example.com', 'https://example.com/apply?a=1&b=2' ),
	'minn_wpjm:_company_website' => array( 'https://example.com/co?x=1&y=2', 'https://example.org/' ),
	'minn_ssp:duration'         => array( '01:02:03', '00:45:10' ),
	'minn_ssp:date_recorded'    => array( '2026-10-11', '2026-09-30' ),
	'minn_ssp:itunes_episode_number' => array( 12, 7 ),
	'minn_ssp:itunes_season_number'  => array( 3, 2 ),
	'minn_seo:canonical'        => array( 'https://example.com/canon?a=1&b=2', 'https://example.org/other/' ),
);

/*
 * Where a field's meta key is not its name: the key its vendor stores it
 * under, so a value can be seeded the way the vendor's own form leaves it.
 */
/*
 * Raw values for fields whose vendor cleans every save of its own: what its
 * own form would store, so only Minn's handling is under test. TEC runs
 * tec_sanitize_string() on every meta string in saveEventMeta() (API.php).
 */
$raw_override = array(
	'minn_tec:cost' => 'Raw\\slash 100 dollars, two  spaces',
);

$meta_of = array(
	'minn_tec:cost' => '_EventCost',
);
if ( defined( 'WPSEO_VERSION' ) ) {
	$meta_of += array(
		'minn_seo:title'                => '_yoast_wpseo_title',
		'minn_seo:description'          => '_yoast_wpseo_metadesc',
		'minn_seo:focus_keyword'        => '_yoast_wpseo_focuskw',
		'minn_seo:facebook_title'       => '_yoast_wpseo_opengraph-title',
		'minn_seo:facebook_description' => '_yoast_wpseo_opengraph-description',
		'minn_seo:twitter_title'        => '_yoast_wpseo_twitter-title',
		'minn_seo:twitter_description'  => '_yoast_wpseo_twitter-description',
	);
}

/*
 * Fields not typed into (a switch whose meaning rewrites others), and
 * fields whose change legitimately moves another: 'valuesKey:field' =>
 * the fields it may move, with the vendor's reason.
 */
$untyped = array(
	'minn_tec:all_day' => 'an all-day event takes the whole day; TEC moves its times',
);
$coupled = array(
	'minn_tec:start' => array( 'end' ), // TEC keeps the end after the start.
	'minn_tec:end'   => array( 'start' ),
);

/*
 * The body the editor sends for a panel: only the fields that differ from
 * what it loaded (changedKeys in app.js).
 */
$client_body = function ( $loaded, $edits ) {
	$out = array();
	foreach ( $edits as $k => $v ) {
		if ( wp_json_encode( $v ) !== wp_json_encode( $loaded[ $k ] ?? null ) ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
};

$typed = array( 'text', 'textarea', 'wysiwyg', 'number', 'email', 'url', 'color_picker', 'date', 'time', 'select', 'radio', 'multicheck', 'true_false', 'toggle' );

$value_for = function ( $f, $variant, $current, $key = '' ) use ( $hints ) {
	// Values no store should alter: backslashes, accents, a decimal, quotes
	// and line breaks. The markup-shaped ones (<3, &, %41) go in raw below,
	// where the rule is only that a save leaves them as stored.
	if ( '' !== $key && isset( $hints[ $key ] ) ) {
		return $hints[ $key ][ 1 === $variant ? 0 : 1 ];
	}
	$t = $f['type'];
	$choices = isset( $f['choices'] ) && is_array( $f['choices'] ) ? array_keys( $f['choices'] ) : array();
	switch ( $t ) {
		case 'text':
			return 1 === $variant ? 'Back\\slash é 3.50 \'apos\'' : 'C:\\files\\new ü';
		case 'textarea':
			return 1 === $variant ? "Line one \\d+ é\nLine two" : "Second\nset of lines";
		case 'wysiwyg':
			return 1 === $variant ? '<p>Para with <strong>bold</strong> and \\d+ é</p>' : '<p>Another <em>para</em></p>';
		case 'number':
			return 1 === $variant ? 1234.5 : 19.99;
		case 'email':
			return 1 === $variant ? 'first+tag@example.com' : 'second@example.org';
		case 'url':
			return 1 === $variant ? 'https://example.com/path?a=1&b=%20x' : 'https://example.org/other';
		case 'color_picker':
			return 1 === $variant ? '#1a2b3c' : '#abcdef';
		case 'date':
			return 1 === $variant ? '2026-10-11' : '2027-01-31';
		case 'time':
			return 1 === $variant ? '13:45' : '08:05';
		case 'select':
		case 'radio':
			foreach ( 1 === $variant ? $choices : array_reverse( $choices ) as $c ) {
				if ( (string) $c !== (string) $current && '' !== (string) $c ) {
					return (string) $c;
				}
			}
			return null;
		case 'multicheck':
			if ( count( $choices ) < 2 ) {
				return null;
			}
			return 1 === $variant ? array_map( 'strval', array_slice( $choices, 0, 2 ) ) : array( (string) end( $choices ) );
		case 'true_false':
		case 'toggle':
			return ! ( ! empty( $current ) && 'false' !== $current && '0' !== (string) $current );
	}
	return null;
};

// A value for a field that differs from what it holds now.
$pick = function ( $f, $current, $key ) use ( $value_for, &$same ) {
	foreach ( array( 1, 2 ) as $variant ) {
		$v = $value_for( $f, $variant, $current, $key );
		if ( null !== $v && ! $same( $f['type'], $v, $current ) ) {
			return $v;
		}
	}
	return null;
};

$same = function ( $type, $a, $b ) {
	if ( in_array( $type, array( 'true_false', 'toggle' ), true ) ) {
		$on = function ( $v ) {
			return ! empty( $v ) && 'false' !== $v && '0' !== (string) $v;
		};
		return $on( $a ) === $on( $b );
	}
	if ( 'number' === $type && is_numeric( $a ) && is_numeric( $b ) ) {
		return (float) $a === (float) $b;
	}
	if ( 'multicheck' === $type ) {
		$a = array_map( 'strval', (array) $a );
		$b = array_map( 'strval', (array) $b );
		sort( $a );
		sort( $b );
		return $a === $b;
	}
	if ( ( null === $a || '' === $a || false === $a ) && ( null === $b || '' === $b || false === $b ) ) {
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

// Each client request is a fresh PHP request: nothing cached about the post
// while answering one save may answer the next read. Only the post's own
// caches go; definitions vendors cache at boot (ACPT's field groups) stay,
// as they would at the start of a real request.
$rt_post = 0;
// The fixture post goes however the run ends: finally covers a throw, this
// covers exit(), a fatal error and (through lib/roundtrip.php's handlers)
// INT, TERM and HUP, none of which run finally.
( require __DIR__ . '/lib/roundtrip.php' );
register_shutdown_function( function () use ( &$rt_post ) {
	if ( $rt_post && get_post( $rt_post ) ) {
		wp_delete_post( $rt_post, true );
	}
} );
$fresh   = function () use ( &$rt_post ) {
	// A request starts with no global post; vendors that fall back to it
	// (ACPT's visibility rules) must not see the last request's.
	$GLOBALS['post'] = null;
	if ( $rt_post ) {
		clean_post_cache( $rt_post );
		wp_cache_delete( $rt_post, 'post_meta' );
	}
	if ( function_exists( 'acf_get_store' ) ) {
		$st = acf_get_store( 'values' );
		if ( $st && method_exists( $st, 'reset' ) ) {
			$st->reset();
		}
	}
};

$snapshot = function ( $post_id ) use ( $fresh ) {
	global $wpdb;
	$fresh();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, MD5(meta_value) AS h FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[] = $r['meta_key'] . '=' . $r['h'];
	}
	$p     = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_content, post_excerpt, post_status, post_name, post_parent, menu_order, post_password FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
	$out[] = 'post=' . md5( wp_json_encode( $p ) );
	sort( $out );
	return $out;
};
$changed_keys = function ( $a, $b ) {
	$keys = array();
	foreach ( array_merge( array_diff( $a, $b ), array_diff( $b, $a ) ) as $row ) {
		$keys[ substr( $row, 0, strrpos( $row, '=' ) ) ] = true;
	}
	return array_keys( $keys );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );

$skip_types = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'nav_menu_item' );
$panels     = apply_filters( 'minn_admin_editor_panels', array() );
$stamp      = substr( md5( (string) microtime( true ) ), 0, 6 );

foreach ( $panels as $panel ) {
	$vk = (string) $panel['valuesKey'];
	$wk = (string) $panel['writeKey'];
	if ( '' !== $only && ! in_array( $vk, explode( ',', $only ), true ) ) {
		continue;
	}
	// Panels that do not vary by type (SEO) are checked on posts and pages.
	$types = array();
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $t ) {
		if ( ! in_array( $t->name, $skip_types, true ) ) {
			$types[] = $t;
		}
	}
	$checked_types = 0;
	foreach ( $types as $pt ) {
		if ( 'minn_seo' === $vk && ! in_array( $pt->name, array( 'post', 'page' ), true ) ) {
			continue;
		}
		$route_of = function ( $post_id ) use ( $panel, $pt ) {
			return str_replace( array( '{id}', '{type}' ), array( (string) $post_id, $pt->name ), $panel['fieldsRoute'] );
		};
		$fields_of = function ( $post_id ) use ( $call, $route_of, $fresh ) {
			$fresh();
			$parts  = explode( '?', $route_of( $post_id ), 2 );
			$params = array();
			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $params );
			}
			list( $status, $d ) = $call( 'GET', '/' . $parts[0], null, $params );
			$out = array();
			if ( 200 !== $status ) {
				return $out;
			}
			// A group's `locked` counts the fields Minn leaves to the vendor's
			// screen; those are not in `fields` at all.
			foreach ( (array) ( $d['groups'] ?? array() ) as $g ) {
				foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
					if ( empty( $f['readonly'] ) && empty( $f['locked'] ) && isset( $f['name'] ) ) {
						$out[ $f['name'] ] = $f;
					}
				}
			}
			return $out;
		};
		if ( ! $fields_of( 0 ) ) {
			continue;
		}
		$base = $pt->rest_base ? $pt->rest_base : $pt->name;
		$ns   = ! empty( $pt->rest_namespace ) ? $pt->rest_namespace : 'wp/v2';
		$id   = wp_insert_post( array( 'post_type' => $pt->name, 'post_title' => 'Minn round trip ' . $stamp, 'post_status' => 'draft' ) );
		if ( ! $id || is_wp_error( $id ) ) {
			$check( "{$vk} on {$pt->name}: fixture post", false, is_wp_error( $id ) ? $id->get_error_message() : 'insert failed' );
			continue;
		}
		$checked_types++;
		$rt_post = $id;
		$label   = "{$vk} on {$pt->name}";
		$fields = $fields_of( $id );
		$read   = function () use ( $call, $ns, $base, $id, $vk, $fields, $fresh ) {
			$fresh();
			list( , $d ) = $call( 'GET', "/{$ns}/{$base}/{$id}", null, array( 'context' => 'edit' ) );
			$v = is_array( $d ) && isset( $d[ $vk ] ) && is_array( $d[ $vk ] ) ? $d[ $vk ] : array();
			// The client turns ACF's false-for-empty into null on every
			// non-boolean field before it sends anything back.
			foreach ( $fields as $name => $f ) {
				if ( ! in_array( $f['type'], array( 'true_false', 'toggle' ), true ) && array_key_exists( $name, $v ) && false === $v[ $name ] ) {
					$v[ $name ] = null;
				}
			}
			return $v;
		};
		$write  = function ( $values ) use ( $call, $ns, $base, $id, $wk, $fresh ) {
			$fresh();
			list( $status, $d ) = $call( 'POST', "/{$ns}/{$base}/{$id}", array( $wk => $values ) );
			return array( $status, is_array( $d ) && isset( $d['message'] ) ? $d['message'] : '' );
		};
		try {
			// Other plugins' save hooks write their own meta on any save;
			// two saves with no panel data settle that and name it, so it is
			// not taken for the panel's doing.
			$call( 'POST', "/{$ns}/{$base}/{$id}", array( 'title' => 'Minn round trip ' . $stamp ) );
			$s0 = $snapshot( $id );
			$call( 'POST', "/{$ns}/{$base}/{$id}", array( 'title' => 'Minn round trip ' . $stamp ) );
			// Plus meta that logs edits rather than holding a value (TEC's
			// record of which fields were modified when).
			$noise = array_merge( $changed_keys( $s0, $snapshot( $id ) ), array( '_tribe_modified_fields', '_edit_lock', '_edit_last' ) );

			// Fidelity: type an awkward value into every typed field at once.
			$values = $read();
			$typed_now = array();
			foreach ( $fields as $name => $f ) {
				if ( ! in_array( $f['type'], $typed, true ) || isset( $untyped[ "{$vk}:{$name}" ] ) ) {
					continue;
				}
				$v = $value_for( $f, 1, $values[ $name ] ?? null, "{$vk}:{$name}" );
				if ( null !== $v ) {
					$values[ $name ]    = $v;
					$typed_now[ $name ] = $v;
				}
			}
			list( $status, $msg ) = $write( $values );
			$check( "{$label}: a save with every field typed answers 200", 200 === $status, $status . ' ' . $msg );
			$after = $read();
			$bad   = array();
			foreach ( $typed_now as $name => $v ) {
				$f = $fields[ $name ];
				if ( isset( $parity[ "{$vk}:{$name}" ] ) || isset( $parity[ "{$vk}:{$f['type']}" ] ) ) {
					continue;
				}
				if ( ! $same( $f['type'], $v, $after[ $name ] ?? null ) ) {
					$bad[] = "{$name} ({$f['type']}) sent " . wp_json_encode( $v ) . ' read ' . wp_json_encode( $after[ $name ] ?? null );
				}
			}
			$check( "{$label}: typed values read back as typed", ! $bad, implode( '; ', $bad ) );

			// Untouched values as the vendor's own form leaves them. Seeding
			// through Minn's write would store only what Minn's cleaner lets
			// through, which hides a save that rewrites what it did not
			// touch. A text field whose name is its own meta key gets an
			// awkward value written straight into that key.
			$raw_text = "Raw\\slash 100%Accurate <3 & \"q\"  two  spaces";
			$seeded   = array();
			foreach ( $fields as $name => $f ) {
				if ( ! in_array( $f['type'], array( 'text', 'textarea' ), true ) || isset( $hints[ "{$vk}:{$name}" ] ) ) {
					continue;
				}
				$key = $meta_of[ "{$vk}:{$name}" ] ?? $name;
				if ( metadata_exists( 'post', $id, $key ) ) {
					update_post_meta( $id, $key, wp_slash( $raw_override[ "{$vk}:{$name}" ] ?? $raw_text ) );
					$seeded[] = $key;
				}
			}
			if ( $seeded ) {
				$before = $snapshot( $id );
				list( $status, $msg ) = $write( $read() );
				$moved = array_values( array_diff( $changed_keys( $before, $snapshot( $id ) ), $noise ) );
				$check( "{$label}: saving other fields leaves vendor-stored text as stored", 200 === $status && ! $moved, $status . ' ' . $msg . ( $moved ? ' rewrote: ' . implode( ', ', $moved ) : '' ) );
			}

			// No-op: the values exactly as read, twice.
			foreach ( array( 1, 2 ) as $round ) {
				$before = $snapshot( $id );
				list( $status, $msg ) = $write( $read() );
				$moved = array_values( array_diff( $changed_keys( $before, $snapshot( $id ) ), $noise ) );
				$check( "{$label}: saving the values as read changes nothing (round {$round})", 200 === $status && ! $moved, $status . ' ' . $msg . ( $moved ? ' changed: ' . implode( ', ', $moved ) : '' ) );
			}

			// Isolation: one field at a time; every other field stays.
			$leaks = array();
			foreach ( $fields as $name => $f ) {
				if ( ! in_array( $f['type'], $typed, true ) || isset( $untyped[ "{$vk}:{$name}" ] ) ) {
					continue;
				}
				$was = $read();
				$v   = $value_for( $f, 2, $was[ $name ] ?? null, "{$vk}:{$name}" );
				if ( null === $v ) {
					continue;
				}
				$values          = $was;
				$values[ $name ] = $v;
				$write( $values );
				$now = $read();
				foreach ( $fields as $other => $of ) {
					if ( $other === $name || in_array( $other, $coupled[ "{$vk}:{$name}" ] ?? array(), true ) ) {
						continue;
					}
					if ( ! $same( $of['type'], $was[ $other ] ?? null, $now[ $other ] ?? null ) ) {
						$leaks[] = "changing {$name} changed {$other}: " . wp_json_encode( $was[ $other ] ?? null ) . ' -> ' . wp_json_encode( $now[ $other ] ?? null );
					}
				}
			}
			$check( "{$label}: changing one field leaves the others", ! $leaks, implode( '; ', array_slice( $leaks, 0, 6 ) ) );

			$typed_names = array();
			foreach ( $fields as $name => $f ) {
				if ( in_array( $f['type'], $typed, true ) && ! isset( $untyped[ "{$vk}:{$name}" ] ) ) {
					$typed_names[] = $name;
				}
			}

			// Partial: a body naming one field leaves every absent one. Every
			// switch is on first: a full-form save (TEC's) reads an absent
			// switch as off.
			$on = $read();
			foreach ( $fields as $name => $f ) {
				if ( in_array( $f['type'], array( 'true_false', 'toggle' ), true ) ) {
					$on[ $name ] = true;
				}
			}
			$write( $on );
			$dropped = array();
			foreach ( $typed_names as $name ) {
				$was = $read();
				$v   = $pick( $fields[ $name ], $was[ $name ] ?? null, "{$vk}:{$name}" );
				if ( null === $v ) {
					continue;
				}
				$write( array( $name => $v ) );
				$now     = $read();
				$exempt  = isset( $parity[ "{$vk}:{$name}" ] ) || isset( $parity[ "{$vk}:{$fields[ $name ]['type']}" ] ) || isset( $coupled[ "{$vk}:{$name}" ] );
				if ( ! $exempt && ! $same( $fields[ $name ]['type'], $v, $now[ $name ] ?? null ) ) {
					$dropped[] = "a body with only {$name} did not store it: sent " . wp_json_encode( $v ) . ' read ' . wp_json_encode( $now[ $name ] ?? null );
				}
				foreach ( $fields as $other => $of ) {
					if ( $other === $name || in_array( $other, $coupled[ "{$vk}:{$name}" ] ?? array(), true ) ) {
						continue;
					}
					if ( ! $same( $of['type'], $was[ $other ] ?? null, $now[ $other ] ?? null ) ) {
						$dropped[] = "a body with only {$name} changed {$other}: " . wp_json_encode( $was[ $other ] ?? null ) . ' -> ' . wp_json_encode( $now[ $other ] ?? null );
					}
				}
			}
			$check( "{$label}: a save naming one field stores it and leaves the rest", ! $dropped, implode( '; ', array_slice( $dropped, 0, 6 ) ) );

			// Concurrent: this page loads, another tab saves one field, then this
			// page saves a different one the way the client does.
			$lost  = array();
			$pairs = 0;
			$n     = count( $typed_names );
			foreach ( $typed_names as $i => $theirs ) {
				$mine = $typed_names[ ( $i + 1 ) % max( 1, $n ) ];
				if ( $mine === $theirs || in_array( $theirs, $coupled[ "{$vk}:{$mine}" ] ?? array(), true ) ) {
					continue;
				}
				$page  = $read();
				$other = $read();
				$tv    = $pick( $fields[ $theirs ], $other[ $theirs ] ?? null, "{$vk}:{$theirs}" );
				$mv    = $pick( $fields[ $mine ], $page[ $mine ] ?? null, "{$vk}:{$mine}" );
				if ( null === $tv || null === $mv ) {
					continue;
				}
				$write( $client_body( $other, array( $theirs => $tv ) ) );
				// What their save stored, as the vendor normalised it.
				$stored = $read();
				if ( $same( $fields[ $theirs ]['type'], $stored[ $theirs ] ?? null, $other[ $theirs ] ?? null ) ) {
					if ( getenv( 'MINN_RT_DEBUG' ) ) {
						echo "DEBUG {$label}: {$theirs} ({$fields[ $theirs ]['type']}) sent " . wp_json_encode( $tv ) . ' stored ' . wp_json_encode( $stored[ $theirs ] ?? null ) . "\n";
					}
					continue; // their change did not take; nothing to keep
				}
				++$pairs;
				list( $status ) = $write( $client_body( $page, array( $mine => $mv ) ) );
				$now = $read();
				if ( 409 !== $status && ! $same( $fields[ $theirs ]['type'], $stored[ $theirs ] ?? null, $now[ $theirs ] ?? null ) ) {
					$lost[] = "saving {$mine} put back {$theirs}: " . wp_json_encode( $stored[ $theirs ] ?? null ) . ' -> ' . wp_json_encode( $now[ $theirs ] ?? null );
				}
			}
			$check( "{$label}: a change made elsewhere survives saving another field", ! $lost, $lost ? implode( '; ', array_slice( $lost, 0, 6 ) ) : "{$pairs} of {$n} pairs" );

		} finally {
			wp_delete_post( $id, true );
		}
	}
	if ( ! $checked_types ) {
		printf( "SKIP  %s: no post type has fields on this site\n", $vk );
	}
}

foreach ( $known as $label => $why ) {
	printf( "NOTE  known entry never ran on this site: %s\n", $label );
}
printf( "\nroundtrip-panels: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
