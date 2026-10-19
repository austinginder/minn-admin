<?php
/**
 * Regressions for the v0.46.0 shipped-zip registry scan (findings that open
 * v0.47.0).
 *
 *  - 14-1 SEO panel: /seo/fields offered the panel to a writer the SEO
 *    plugin refuses (its own edit predicate, or its metabox switched off for
 *    the type of a post not saved yet), and the minn_seo field then answered
 *    403 after WordPress had stored the post. The editor never learned the
 *    new id, so every save and autosave made another draft. A refused image
 *    also ended the save halfway through the other fields.
 *
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. The SEO section follows the
 * active provider: the resident Yoast for the metabox switch and the image
 * refusal, and Rank Math (MINN_SEO_SWAP=rank-math, tests/lib/seo-swap.php)
 * for a Contributor its own capability leaves out. Fixtures (users, posts,
 * an image, the Yoast switch) are removed at the end however the run ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v047-registry.test.php --user=admin --path=<site>
 *      MINN_SEO_SWAP=rank-math wp eval-file <same> --user=admin --path=<site> --require=<ABSOLUTE path>/tests/lib/seo-swap.php
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = static function ( $method, $route, $body = null, $query = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $query as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
$as      = static function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = static function ( callable $fn ) {
	$done = false;
	$run  = static function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};
// The minn_field_errors row for one panel field, or null.
$field_err = static function ( $data, $panel, $key ) {
	foreach ( (array) ( is_array( $data ) ? ( $data['minn_field_errors'] ?? array() ) : array() ) as $row ) {
		if ( ( $row['field'] ?? '' ) === $panel && (string) ( $row['key'] ?? '' ) === (string) $key ) {
			return $row;
		}
	}
	return null;
};

/*
 * 14-1 SEO panel. A new post's first save carries the panel's changed fields
 * beside the post; the answer must be the post (201 with its id) whatever the
 * SEO field refuses, so the next save updates it instead of making another.
 */
( function () use ( $check, $skip, $call, $as, $once, $field_err ) {
	$plugin = function_exists( 'minn_admin_seo_plugin' ) ? minn_admin_seo_plugin() : null;
	$name   = is_array( $plugin ) ? (string) ( $plugin['name'] ?? '' ) : '';
	if ( 'Yoast SEO' !== $name && 'Rank Math' !== $name ) {
		$skip( '14-1 SEO: Yoast or Rank Math is not the active provider (' . ( '' === $name ? 'none' : $name ) . ')' );
		return;
	}
	$tag    = strtolower( wp_generate_password( 6, false ) );
	$title  = 'Minn v047 seo ' . $tag;
	$users  = array();
	$titles = get_option( 'wpseo_titles', null );
	$att    = 0;
	$clean  = $once( static function () use ( &$users, $title, $titles, &$att, $name ) {
		foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 's' => $title, 'fields' => 'ids' ) ) as $pid ) {
			wp_delete_post( $pid, true );
		}
		if ( $att ) {
			wp_delete_attachment( $att, true );
		}
		foreach ( $users as $uid ) {
			wp_delete_user( $uid );
		}
		if ( 'Yoast SEO' === $name && null !== $titles ) {
			update_option( 'wpseo_titles', $titles );
		}
	} );
	$drafts = static function () use ( $title ) {
		return count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'title' => $title, 'fields' => 'ids' ) ) );
	};
	$user = static function ( $role ) use ( $tag, &$users ) {
		$uid = wp_insert_user( array( 'user_login' => "minn047_{$role}_{$tag}", 'user_pass' => wp_generate_password( 24 ), 'user_email' => "minn047_{$role}_{$tag}@example.test", 'role' => $role ) );
		if ( is_wp_error( $uid ) ) {
			return 0;
		}
		$users[] = $uid;
		return $uid;
	};
	// The first save and two retries, as the editor makes them: create, then
	// update the id the answer gave, the panel still holding its change.
	$three = static function ( $uid, $seo ) use ( $call, $as, $title ) {
		return $as( $uid, static function () use ( $call, $title, $seo ) {
			$answers = array( $call( 'POST', '/wp/v2/posts', array( 'title' => $title, 'status' => 'draft', 'minn_seo' => $seo ) ) );
			$id      = (int) ( $answers[0][1]['id'] ?? 0 );
			for ( $i = 0; $i < 2; $i++ ) {
				$answers[] = $id ? $call( 'POST', '/wp/v2/posts/' . $id, array( 'title' => $title, 'minn_seo' => $seo ) ) : $call( 'POST', '/wp/v2/posts', array( 'title' => $title, 'status' => 'draft', 'minn_seo' => $seo ) );
			}
			return array( $id, $answers );
		} );
	};
	$groups = static function ( $uid, $query ) use ( $call, $as ) {
		list( $st, $d ) = $as( $uid, static function () use ( $call, $query ) {
			return $call( 'GET', '/minn-admin/v1/seo/fields', null, $query );
		} );
		return 200 === $st ? count( (array) ( $d['groups'] ?? array() ) ) : -1;
	};

	try {
		if ( 'Rank Math' === $name ) {
			// Rank Math gives a stock Contributor no onpage capability and no
			// SEO metabox.
			$contrib = $user( 'contributor' );
			$check( '14-1 Rank Math: a Contributor exists and holds no rank_math_onpage_general', $contrib && ! user_can( $contrib, 'rank_math_onpage_general' ) );
			$check( '14-1 Rank Math: the panel offers a Contributor no fields on a new post', 0 === $groups( $contrib, array( 'post' => 0, 'type' => 'posts' ) ) );
			list( $id, $answers ) = $three( $contrib, array( 'title' => 'Contributor SEO ' . $title ) );
			$check( '14-1 Rank Math: a Contributor\'s first save with an SEO change answers 201 with the post', 201 === $answers[0][0] && $id > 0, $answers[0][0] . ' ' . wp_json_encode( $answers[0][1]['code'] ?? '' ) );
			$check( '14-1 Rank Math: and names the SEO field it did not store', (bool) $field_err( $answers[0][1], 'minn_seo', 'title' ), wp_json_encode( $answers[0][1]['minn_field_errors'] ?? null ) );
			$check( '14-1 Rank Math: two retries make no second draft', 1 === $drafts(), 'drafts: ' . $drafts() );
			$check( '14-1 Rank Math: the retries update the same post', 200 === $answers[1][0] && 200 === $answers[2][0], wp_json_encode( array( $answers[1][0], $answers[2][0] ) ) );
			$check( '14-1 Rank Math: the refused title is not stored', $id && '' === (string) get_post_meta( $id, 'rank_math_title', true ) );
			if ( current_user_can( 'rank_math_onpage_general' ) ) {
				$check( '14-1 Rank Math control: an administrator holding the capability still gets the panel', $groups( get_current_user_id(), array( 'post' => 0, 'type' => 'posts' ) ) > 0 );
			} else {
				$skip( '14-1 Rank Math control: the suite user holds no rank_math_onpage_general' );
			}
			return;
		}

		// Yoast: the post type's SEO metabox switched off. The switch hides the
		// panel from everybody, so a new post (no type of its own yet) has to
		// name the type the editor is making.
		$author = $user( 'author' );
		WPSEO_Options::set( 'display-metabox-pt-post', false );
		$check( '14-1 Yoast: the metabox is switched off for posts', ! minn_admin_seo_shows_for_type( $plugin, 'post' ) );
		$check( '14-1 Yoast: the panel offers an Author no fields on a new post of that type', 0 === $groups( $author, array( 'post' => 0, 'type' => 'posts' ) ) );
		$check( '14-1 Yoast control: a new page still gets the panel', $groups( $author, array( 'post' => 0, 'type' => 'pages' ) ) > 0 );
		list( $id, $answers ) = $three( $author, array( 'title' => 'Author SEO ' . $title ) );
		$check( '14-1 Yoast: a first save with an SEO change on a switched-off type answers 201 with the post', 201 === $answers[0][0] && $id > 0, $answers[0][0] . ' ' . wp_json_encode( $answers[0][1]['code'] ?? '' ) );
		$check( '14-1 Yoast: and names the SEO field it did not store', (bool) $field_err( $answers[0][1], 'minn_seo', 'title' ), wp_json_encode( $answers[0][1]['minn_field_errors'] ?? null ) );
		$check( '14-1 Yoast: two retries make no second draft', 1 === $drafts(), 'drafts: ' . $drafts() );
		$check( '14-1 Yoast: the refused title is not stored', $id && '' === (string) get_post_meta( $id, '_yoast_wpseo_title', true ) );
		update_option( 'wpseo_titles', $titles );
		WPSEO_Options::clear_cache();
		$check( '14-1 Yoast control: with the switch back on, the panel returns', minn_admin_seo_shows_for_type( $plugin, 'post' ) && $groups( $author, array( 'post' => 0, 'type' => 'posts' ) ) > 0 );

		// A refused image keeps only itself from being stored: a Contributor
		// (no upload_files) on its own draft sends a new title and an image.
		$map = minn_admin_seo_field_map( $plugin, 0 );
		if ( ! isset( $map['social_image'] ) ) {
			$skip( '14-1 Yoast image: the social group is off on this site' );
			return;
		}
		$contrib = $user( 'contributor' );
		$draft   = wp_insert_post( array( 'post_title' => $title . ' image', 'post_status' => 'draft', 'post_author' => $contrib ) );
		$file    = wp_upload_bits( 'minn-v047-' . $tag . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
		$att     = empty( $file['error'] ) ? wp_insert_attachment( array( 'post_title' => $title . ' png', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file['file'] ) : 0;
		if ( $att ) {
			wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file['file'] ) );
		}
		$check( '14-1 Yoast image: a Contributor\'s draft and an image exist', $draft && $att && wp_attachment_is_image( $att ) && ! user_can( $contrib, 'upload_files' ) );
		list( $st, $d ) = $as( $contrib, static function () use ( $call, $draft, $att, $title ) {
			return $call( 'POST', '/wp/v2/posts/' . $draft, array( 'minn_seo' => array( 'title' => 'Kept ' . $title, 'social_image' => array( 'id' => $att ) ) ) );
		} );
		$check( '14-1 Yoast image: the save answers 200 with the post', 200 === $st && (int) ( $d['id'] ?? 0 ) === (int) $draft, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		$check( '14-1 Yoast image: and names the image, not the title', $field_err( $d, 'minn_seo', 'social_image' ) && ! $field_err( $d, 'minn_seo', 'title' ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$read = (array) call_user_func( $plugin['read'], $draft );
		$check( '14-1 Yoast image: the title is stored and the image is not', 'Kept ' . $title === (string) get_post_meta( $draft, '_yoast_wpseo_title', true ) && empty( $read['social_image'] ), wp_json_encode( array( get_post_meta( $draft, '_yoast_wpseo_title', true ), $read['social_image'] ?? null ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * The cut-before-the-fence class, swept. The egress fence knows a credential
 * only whole, so text cut short before a response leaves Minn shows a key that
 * runs across the cut up to the cut. Every site that shortens stored text now
 * cuts through Minn_Admin_Egress::clip(), which masks first. Two adapters
 * prove it with a key across their cut; the scan keeps the shape from coming
 * back anywhere else.
 */
( function () use ( $check, $skip, $once ) {
	if ( ! class_exists( 'Minn_Admin_Egress' ) || ! method_exists( 'Minn_Admin_Egress', 'clip' ) ) {
		$check( 'clips: Minn_Admin_Egress::clip() exists', false );
		return;
	}
	$tag     = strtolower( wp_generate_password( 6, false ) );
	$row     = 'connectors_minn047c' . $tag . '_api_key';
	$secret  = 'cLp' . $tag . 'Rw4N8' . strtolower( wp_generate_password( 12, false, false ) ) . '61Hq5Vd2';
	$head    = substr( $secret, 0, 8 );
	$forget  = static function () {
		Minn_Admin_DB::forget_credentials();
		Minn_Admin_Egress::reset();
	};
	$clean   = $once( static function () use ( $row, $forget ) {
		delete_option( $row );
		$forget();
	} );
	add_option( $row, $secret, '', 'no' );
	$forget();
	$shows = static function ( $text ) use ( $head ) {
		return false !== strpos( (string) wp_json_encode( $text ), $head );
	};
	try {
		$check( 'clips fixture: the fence counts the probe key as a credential', in_array( $secret, Minn_Admin_DB::egress_credentials(), true ) );

		if ( function_exists( 'minn_admin_aios_event_view' ) ) {
			$view = minn_admin_aios_event_view( array(
				'username'     => '',
				'event_type'   => 'minn_probe',
				'created'      => time(),
				'level'        => 'info',
				'ip'           => '',
				'country_code' => '',
				'details'      => wp_json_encode( array( 'note' => str_repeat( 'a', 190 ) . ' ' . $secret . ' tail' ) ),
			) );
			$check( 'clips: an All-In-One Security context value with a key across the 200 cut shows none of it', ! $shows( $view ), wp_json_encode( $view['sections'][1] ?? null ) );
		} else {
			$skip( 'clips: All-In-One Security adapter not loaded' );
		}
		if ( function_exists( 'minn_admin_acf_display_value' ) ) {
			$shown = minn_admin_acf_display_value( array( 'type' => 'text' ), str_repeat( 'b', 150 ) . ' ' . $secret );
			$check( 'clips: an ACF text value with a key across the 160 cut shows none of it', ! $shows( $shown ) && '…' === mb_substr( $shown, -1 ), $shown );
			$check( 'clips control: a short ACF value is shown as stored', 'plain' === minn_admin_acf_display_value( array( 'type' => 'text' ), 'plain' ) );
		} else {
			$skip( 'clips: ACF adapter not loaded' );
		}

		// Where text is still cut by hand. A cut that decides on the whole
		// value first is listed with the reason.
		$allowed = array(
			'includes/class-minn-admin-egress.php'          => 'the rule itself',
			'includes/class-minn-admin-preview.php'         => 'a merge tag as typed, not stored text',
			'includes/adapters/transients-manager.php'      => 'withholds_cell() decides on the whole value first',
			'includes/class-minn-admin-db.php'              => 'a binary cell in hex, after shown_cell() decides redaction',
			'includes/class-minn-admin-plugin-links.php'    => 'a plugin action link label',
		);
		// Cuts of values that are never stored text, by their shape.
		$fine  = array(
			'substr( md5('            => 'a hash',
			'substr( (string) $v, 0, 19 )' => 'a MySQL datetime',
		);
		$root  = dirname( __DIR__ ) . '/';
		$found = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$rel = substr( $file->getPathname(), strlen( $root ) );
			if ( isset( $allowed[ $rel ] ) ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $i => $line ) {
				foreach ( $fine as $shape => $why ) {
					if ( false !== strpos( $line, $shape ) ) {
						continue 2;
					}
				}
				// A prefix cut of 16 or more characters, with or without an
				// ellipsis: a credential the fence knows is at least that long.
				if ( preg_match( '/\\b(?:mb_)?substr\\(\\s*[^,;]+,\\s*0,\\s*(\\d+)\\s*\\)/', $line, $m ) && (int) $m[1] >= 16 ) {
					$found[] = $rel . ':' . ( $i + 1 );
				}
			}
		}
		$check( 'clips: no stored text is cut to 16 characters or more by hand outside Minn_Admin_Egress::clip()', ! $found, implode( ', ', $found ) );
	} finally {
		$clean();
	}
} )();

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
