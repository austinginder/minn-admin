<?php
/**
 * Regressions for the v0.43.0 shipped-zip registry audit (WP Registry #2193).
 *
 * Most of this round is the same class as the last one: a Minn save that
 * rewrites vendor state the user never touched (a date the panel could not
 * read, a backslash unslashed twice, a rule the builder did not recognise),
 * plus a few gates that sat below the vendor's own and redaction gaps. Each
 * section replays what Minn's client sends through the route it actually
 * calls and checks the stored result, with a control where a fix could
 * over-block. Sections SKIP when their plugin is inactive, and the multisite
 * ones SKIP on a single site.
 *
 * Run: wp eval-file tests/security-v043-registry.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v043-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- #3 Custom CSS & JS delete, edit and switch-off take the permalink copy too ---
// CCJ's save writes a snippet twice: <id>.<lang>, which the page loads, and
// <slug>.<lang> under its Permalink slug, which nothing loads but anyone can
// fetch. Minn's delete, code edit and switch-off must take the second one
// too, must never take a file that is another snippet's or a block bundle,
// and must not leave CCJ_UPLOAD_DIR whatever the slug says.
if ( defined( 'CCJ_UPLOAD_DIR' ) && function_exists( 'minn_admin_ccj_drop_files' ) && post_type_exists( 'custom-css-js' ) ) {
	$ccj3_tree_was = get_option( 'custom-css-js-tree', array() );
	$ccj3_bundles  = array();
	foreach ( array( 'block_js.js', 'block_css.css' ) as $ccj3_f ) {
		$ccj3_bundles[ $ccj3_f ] = is_file( CCJ_UPLOAD_DIR . '/' . $ccj3_f ) ? file_get_contents( CCJ_UPLOAD_DIR . '/' . $ccj3_f ) : null;
	}
	$ccj3_made  = array();
	$ccj3_files = array();
	$ccj3_make  = function ( $args ) use ( $call, &$ccj3_made ) {
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/ccj/snippets', array_merge( array( 'language' => 'css', 'type' => 'header', 'side' => 'frontend', 'linking' => 'internal', 'priority' => 5, 'active' => true ), $args ) );
		$ccj3_made[] = (int) ( $d['id'] ?? 0 );
		return (int) ( $d['id'] ?? 0 );
	};
	// What the detail form sends: every field, active preserved.
	$ccj3_form = function ( $id, $over = array() ) use ( $call ) {
		list( , $item ) = $call( 'GET', "/minn-admin/v1/ccj/snippets/{$id}" );
		$body = array();
		foreach ( array( 'active', 'name', 'code', 'language', 'type', 'side', 'linking', 'priority' ) as $k ) {
			$body[ $k ] = $item[ $k ] ?? null;
		}
		return $call( 'PUT', "/minn-admin/v1/ccj/snippets/{$id}", array_merge( $body, $over ) );
	};
	// CCJ's save writes the permalink copy with the same bytes as <id>.<lang>.
	$ccj3_slug = function ( $id, $slug, $language = 'css' ) use ( &$ccj3_files ) {
		update_post_meta( $id, '_slug', $slug );
		$path = CCJ_UPLOAD_DIR . '/' . sanitize_file_name( $slug ) . '.' . $language;
		$own  = CCJ_UPLOAD_DIR . '/' . $id . '.' . $language;
		file_put_contents( $path, is_file( $own ) ? file_get_contents( $own ) : "/* minn-v043 slug {$id} */" );
		$ccj3_files[] = $path;
		return $path;
	};

	$ccj3_p    = $ccj3_make( array( 'name' => 'Minn v043 perma', 'code' => '.minn-v043-p { color: red; }' ) );
	$ccj3_perm = $ccj3_slug( $ccj3_p, 'minn-v043-perma' );
	$ccj3_own  = CCJ_UPLOAD_DIR . '/' . $ccj3_p . '.css';
	$check( 'CCJ #3: seed wrote the snippet and its permalink copy', is_file( $ccj3_own ) && is_file( $ccj3_perm ) );
	$ccj3_form( $ccj3_p, array( 'code' => '.minn-v043-p { color: green; }' ) );
	$ccj3_after = is_file( $ccj3_perm ) ? (string) file_get_contents( $ccj3_perm ) : '';
	$check( 'CCJ #3: a code edit leaves no pre-edit bytes at the permalink', false === strpos( $ccj3_after, 'red' ), false !== strpos( $ccj3_after, 'red' ) ? 'permalink still serves: ' . str_replace( "\n", ' ', $ccj3_after ) : '' );
	$check( 'CCJ #3: the edit reaches the snippet\'s own file (control)', false !== strpos( (string) @file_get_contents( $ccj3_own ), 'green' ) );
	// A rename leaves both files alone: the bytes have not changed.
	file_put_contents( $ccj3_perm, (string) file_get_contents( $ccj3_own ) );
	$ccj3_form( $ccj3_p, array( 'name' => 'Minn v043 perma renamed' ) );
	$check( 'CCJ #3: a rename leaves the permalink copy in place (control)', is_file( $ccj3_perm ) && file_get_contents( $ccj3_perm ) === file_get_contents( $ccj3_own ) );
	// Switching off takes the bytes from every public address; switching on
	// puts CCJ's bytes back at both.
	$ccj3_bytes = (string) file_get_contents( $ccj3_own );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_p}/active", array( 'active' => false ) );
	$check( 'CCJ #3: switching a snippet off takes its permalink copy too', ! is_file( $ccj3_perm ) && ! is_file( $ccj3_own ) );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_p}/active", array( 'active' => true ) );
	$check( 'CCJ #3: switching it back on restores the permalink copy with CCJ\'s bytes (control)', is_file( $ccj3_perm ) && $ccj3_bytes === (string) file_get_contents( $ccj3_perm ) && $ccj3_bytes === (string) @file_get_contents( $ccj3_own ) );
	// A slug changed while off: the parked name is no longer the snippet's,
	// so nothing is written under either name.
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_p}/active", array( 'active' => false ) );
	update_post_meta( $ccj3_p, '_slug', 'minn-v043-perma-moved' );
	$ccj3_files[] = CCJ_UPLOAD_DIR . '/minn-v043-perma-moved.css';
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_p}/active", array( 'active' => true ) );
	$check( 'CCJ #3: a slug changed while off is not written by the switch-on', ! is_file( $ccj3_perm ) && ! is_file( CCJ_UPLOAD_DIR . '/minn-v043-perma-moved.css' ) && is_file( $ccj3_own ) );
	$ccj3_perm = $ccj3_slug( $ccj3_p, 'minn-v043-perma' );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_p}" );
	$check( 'CCJ #3: deleting a snippet takes its permalink copy', ! is_file( $ccj3_perm ), is_file( $ccj3_perm ) ? 'still public: ' . basename( $ccj3_perm ) : '' );
	$check( 'CCJ #3: deleting a snippet takes its own file (control)', ! is_file( $ccj3_own ) );

	// Hostile slugs. A numeric slug names another snippet's own file.
	$ccj3_q     = $ccj3_make( array( 'name' => 'Minn v043 q', 'code' => '.minn-v043-q { color: red; }' ) );
	$ccj3_r     = $ccj3_make( array( 'name' => 'Minn v043 r', 'code' => '.minn-v043-r { color: red; }' ) );
	$ccj3_rfile = CCJ_UPLOAD_DIR . '/' . $ccj3_r . '.css';
	update_post_meta( $ccj3_q, '_slug', (string) $ccj3_r );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_q}/active", array( 'active' => false ) );
	$check( 'CCJ #3: switching off a snippet whose slug is another snippet\'s id leaves that snippet\'s file', is_file( $ccj3_rfile ) && false !== strpos( (string) file_get_contents( $ccj3_rfile ), 'minn-v043-r' ) );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_q}" );
	$check( 'CCJ #3: deleting it leaves that snippet\'s file too', is_file( $ccj3_rfile ) && false !== strpos( (string) file_get_contents( $ccj3_rfile ), 'minn-v043-r' ) );
	// Its own id as the slug is just its own file.
	update_post_meta( $ccj3_r, '_slug', (string) $ccj3_r );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_r}" );
	$check( 'CCJ #3: a snippet whose slug is its own id deletes cleanly', ! is_file( $ccj3_rfile ) );
	// The block-editor bundles, in either case (a case-insensitive disk is one file).
	foreach ( array( 'block_css' => 'css', 'BLOCK_JS' => 'js' ) as $ccj3_bslug => $ccj3_blang ) {
		$ccj3_bundle = CCJ_UPLOAD_DIR . '/' . strtolower( $ccj3_bslug ) . '.' . $ccj3_blang;
		file_put_contents( $ccj3_bundle, "/* minn-v043 bundle {$ccj3_bslug} */" );
		$ccj3_b = $ccj3_make( array( 'name' => 'Minn v043 bundle ' . $ccj3_bslug, 'code' => '.minn-v043-b { color: red; }' ) );
		update_post_meta( $ccj3_b, '_slug', $ccj3_bslug );
		$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_b}" );
		$check( "CCJ #3: a slug of {$ccj3_bslug} leaves the block-editor bundle", is_file( $ccj3_bundle ) && false !== strpos( (string) file_get_contents( $ccj3_bundle ), 'minn-v043 bundle' ) );
	}
	// Climbing out: sanitize_file_name strips the separators, and a filter
	// that hands one back is refused rather than followed.
	$ccj3_outside = dirname( CCJ_UPLOAD_DIR ) . '/minn-v043-outside.css';
	file_put_contents( $ccj3_outside, '/* minn-v043 outside */' );
	$ccj3_t = $ccj3_make( array( 'name' => 'Minn v043 traversal', 'code' => '.minn-v043-t { color: red; }' ) );
	update_post_meta( $ccj3_t, '_slug', '../minn-v043-outside' );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_t}/active", array( 'active' => false ) );
	$check( 'CCJ #3: a ../ slug does not reach outside the CCJ folder', is_file( $ccj3_outside ) );
	// Only this slug: other code sanitizes file names during a delete too.
	$ccj3_evil = function ( $name, $raw = '' ) {
		return 'minn-v043-evil-slug' === $raw ? '../minn-v043-outside' : $name;
	};
	update_post_meta( $ccj3_t, '_slug', 'minn-v043-evil-slug' );
	add_filter( 'sanitize_file_name', $ccj3_evil, 99, 2 );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_t}" );
	remove_filter( 'sanitize_file_name', $ccj3_evil, 99 );
	$check( 'CCJ #3: a filtered file name that climbs out is refused', is_file( $ccj3_outside ) );
	$check( 'CCJ #3: the traversal snippet is still deleted (control)', null === get_post( $ccj3_t ) );
	// A dangling link parked under the permalink name is not followed when
	// the switch-on puts the copy back.
	$ccj3_s    = $ccj3_make( array( 'name' => 'Minn v043 link', 'code' => '.minn-v043-s { color: red; }' ) );
	$ccj3_link = $ccj3_slug( $ccj3_s, 'minn-v043-link' );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_s}/active", array( 'active' => false ) );
	@symlink( $ccj3_outside . '.linked', $ccj3_link );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_s}/active", array( 'active' => true ) );
	$check( 'CCJ #3: a link at the permalink name is not followed by the switch-on', ! file_exists( $ccj3_outside . '.linked' ) && is_file( CCJ_UPLOAD_DIR . '/' . $ccj3_s . '.css' ) );
	@unlink( $ccj3_link );
	@unlink( $ccj3_outside . '.linked' );
	@unlink( $ccj3_outside );
	// Meta another component stored as an array is not a slug.
	update_post_meta( $ccj3_s, '_slug', array( 'minn-v043-array' ) );
	$ccj3_arr = CCJ_UPLOAD_DIR . '/Array.css';
	$ccj3_had = is_file( $ccj3_arr );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_s}" );
	$check( 'CCJ #3: an array-valued _slug deletes cleanly and touches no other file', null === get_post( $ccj3_s ) && $ccj3_had === is_file( $ccj3_arr ) );
	// A numeric slug that is not a snippet's id is only a permalink copy.
	$ccj3_page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Minn v043 ccj page', 'post_status' => 'draft' ) );
	$ccj3_n    = $ccj3_make( array( 'name' => 'Minn v043 numeric', 'code' => '.minn-v043-n { color: red; }' ) );
	$ccj3_npth = $ccj3_slug( $ccj3_n, (string) $ccj3_page );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_n}" );
	$check( 'CCJ #3: a numeric slug that names no snippet is deleted as a permalink copy', ! is_file( $ccj3_npth ) );
	wp_delete_post( $ccj3_page, true );
	// JavaScript: the copy is <slug>.js and cycles the same way.
	$ccj3_j    = $ccj3_make( array( 'name' => 'Minn v043 js perma', 'language' => 'js', 'code' => 'window.minnV043J = 1 && 2 > 1;' ) );
	$ccj3_jpth = $ccj3_slug( $ccj3_j, 'minn-v043-js-perma', 'js' );
	$ccj3_jraw = (string) file_get_contents( $ccj3_jpth );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_j}/active", array( 'active' => false ) );
	$ccj3_joff = ! is_file( $ccj3_jpth );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ccj3_j}/active", array( 'active' => true ) );
	$check( 'CCJ #3: a JS snippet\'s permalink copy goes off and comes back with its bytes', $ccj3_joff && $ccj3_jraw === (string) @file_get_contents( $ccj3_jpth ) );
	$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_j}" );
	$check( 'CCJ #3: deleting the JS snippet takes its permalink copy', ! is_file( $ccj3_jpth ) );

	foreach ( array_filter( $ccj3_made ) as $ccj3_id ) {
		if ( get_post( $ccj3_id ) ) {
			$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj3_id}" );
		}
		if ( get_post( $ccj3_id ) ) {
			wp_delete_post( $ccj3_id, true );
		}
		foreach ( array( 'css', 'js', 'html' ) as $ccj3_l ) {
			@unlink( CCJ_UPLOAD_DIR . '/' . $ccj3_id . '.' . $ccj3_l );
		}
	}
	foreach ( $ccj3_files as $ccj3_f ) {
		@unlink( $ccj3_f );
	}
	update_option( 'custom-css-js-tree', $ccj3_tree_was );
	foreach ( $ccj3_bundles as $ccj3_f => $ccj3_b ) {
		if ( null === $ccj3_b ) {
			@unlink( CCJ_UPLOAD_DIR . '/' . $ccj3_f );
		} else {
			file_put_contents( CCJ_UPLOAD_DIR . '/' . $ccj3_f, $ccj3_b );
		}
	}
} else {
	$skip( 'CCJ #3 permalink copy: Simple Custom CSS and JS inactive' );
}

// --- #14 Custom CSS & JS off, rename, on keeps the plugin's own bytes -----
// A snippet CCJ's editor saved for an author without unfiltered_html holds a
// kses-encoded post_content and the raw bytes in its file, with options
// straight from $_POST (priority the string "5"). Switching it off parks the
// file; an edit that cannot change the bytes (name, priority, header/footer)
// must not stop the switch-on from putting them back, while one that can
// (the code, the linking, the language) must.
if ( defined( 'CCJ_UPLOAD_DIR' ) && function_exists( 'minn_admin_ccj_park_file' ) && post_type_exists( 'custom-css-js' ) ) {
	$ccj14_tree_was = get_option( 'custom-css-js-tree', array() );
	$ccj14_made     = array();
	$ccj14_seed     = function ( $linking, $body = '.minn-v043-k &gt; p { color: red; }' ) use ( &$ccj14_made ) {
		$id = wp_insert_post( array(
			'post_type'    => 'custom-css-js',
			'post_title'   => 'Minn v043 kses ' . $linking,
			'post_content' => $body,
			'post_status'  => 'publish',
		) );
		$ccj14_made[] = (int) $id;
		update_post_meta( $id, 'options', array( 'type' => 'header', 'linking' => $linking, 'priority' => '5', 'side' => 'frontend', 'language' => 'css' ) );
		update_post_meta( $id, '_active', 'yes' );
		$raw = 'internal' === $linking
			? "<!-- start Simple Custom CSS and JS -->\n<style type=\"text/css\">\n.minn-v043-k > p { color: red; }</style>\n<!-- end Simple Custom CSS and JS -->\n"
			: "/******* Do not edit this file *******\nSimple Custom CSS and JS - by Silkypress.com\nSaved: Oct 09 2026 | 12:00:00 */\n.minn-v043-k > p { color: red; }";
		file_put_contents( CCJ_UPLOAD_DIR . '/' . $id . '.css', $raw );
		return array( (int) $id, $raw );
	};
	$ccj14_form = function ( $id, $over = array() ) use ( $call ) {
		list( , $item ) = $call( 'GET', "/minn-admin/v1/ccj/snippets/{$id}" );
		$body = array();
		foreach ( array( 'active', 'name', 'code', 'language', 'type', 'side', 'linking', 'priority' ) as $k ) {
			$body[ $k ] = $item[ $k ] ?? null;
		}
		return $call( 'PUT', "/minn-admin/v1/ccj/snippets/{$id}", array_merge( $body, $over ) );
	};
	$ccj14_file = function ( $id ) {
		return (string) @file_get_contents( CCJ_UPLOAD_DIR . '/' . $id . '.css' );
	};
	$ccj14_why  = function ( $id, $want ) use ( $ccj14_file ) {
		return $want === $ccj14_file( $id ) ? '' : 'file now: ' . str_replace( "\n", ' ', $ccj14_file( $id ) );
	};
	$ccj14_off  = function ( $id ) use ( $call ) {
		$call( 'POST', "/minn-admin/v1/ccj/snippets/{$id}/active", array( 'active' => false ) );
	};
	$ccj14_on   = function ( $id ) use ( $call ) {
		$call( 'POST', "/minn-admin/v1/ccj/snippets/{$id}/active", array( 'active' => true ) );
	};

	// Row switch off, detail-form rename, row switch on.
	list( $ccj14_a, $ccj14_raw ) = $ccj14_seed( 'internal' );
	$ccj14_off( $ccj14_a );
	$check( 'CCJ #14: switching off removes the file (control)', ! is_file( CCJ_UPLOAD_DIR . '/' . $ccj14_a . '.css' ) );
	$ccj14_form( $ccj14_a, array( 'name' => 'Minn v043 kses renamed' ) );
	$ccj14_on( $ccj14_a );
	$check( 'CCJ #14: off, rename, on brings CCJ\'s bytes back, not the kses copy', $ccj14_raw === $ccj14_file( $ccj14_a ), $ccj14_why( $ccj14_a, $ccj14_raw ) );
	// Off, priority change, on.
	$ccj14_off( $ccj14_a );
	$ccj14_form( $ccj14_a, array( 'priority' => 10 ) );
	$ccj14_on( $ccj14_a );
	$check( 'CCJ #14: off, priority change, on brings CCJ\'s bytes back', $ccj14_raw === $ccj14_file( $ccj14_a ), $ccj14_why( $ccj14_a, $ccj14_raw ) );
	// Off, header to footer, on (the form's "Where").
	$ccj14_off( $ccj14_a );
	$ccj14_form( $ccj14_a, array( 'type' => 'footer' ) );
	$ccj14_on( $ccj14_a );
	$check( 'CCJ #14: off, header to footer, on brings CCJ\'s bytes back', $ccj14_raw === $ccj14_file( $ccj14_a ), $ccj14_why( $ccj14_a, $ccj14_raw ) );
	// Off, then the form's own switch-on with a rename in the same save.
	$ccj14_off( $ccj14_a );
	$ccj14_form( $ccj14_a, array( 'name' => 'Minn v043 kses again', 'active' => true ) );
	$check( 'CCJ #14: off, then rename and switch on in one save, brings CCJ\'s bytes back', $ccj14_raw === $ccj14_file( $ccj14_a ), $ccj14_why( $ccj14_a, $ccj14_raw ) );
	// A designer (no unfiltered_html) on an external front-end CSS snippet.
	list( $ccj14_e, $ccj14_eraw ) = $ccj14_seed( 'external' );
	$ccj14_nouf = function ( $caps ) {
		$caps['unfiltered_html'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $ccj14_nouf );
	kses_init();
	$ccj14_off( $ccj14_e );
	$ccj14_form( $ccj14_e, array( 'name' => 'Minn v043 kses external renamed', 'priority' => 7 ) );
	$ccj14_on( $ccj14_e );
	remove_filter( 'user_has_cap', $ccj14_nouf );
	kses_init();
	$check( 'CCJ #14: a designer\'s off, rename, on keeps CCJ\'s bytes for external CSS', $ccj14_eraw === $ccj14_file( $ccj14_e ), $ccj14_why( $ccj14_e, $ccj14_eraw ) );
	clean_post_cache( $ccj14_e );
	$check( 'CCJ #14: the designer\'s rename left the body as stored (control)', '.minn-v043-k &gt; p { color: red; }' === (string) get_post_field( 'post_content', $ccj14_e ) );
	// CCJ's editor stores CRLF and the form sends LF back: still not an edit.
	list( $ccj14_c, $ccj14_craw ) = $ccj14_seed( 'internal', ".minn-v043-k &gt; p {\r\n\tcolor: red;\r\n}" );
	$ccj14_off( $ccj14_c );
	$ccj14_form( $ccj14_c, array( 'name' => 'Minn v043 kses crlf', 'code' => ".minn-v043-k &gt; p {\n\tcolor: red;\n}" ) );
	$ccj14_on( $ccj14_c );
	$check( 'CCJ #14: a CRLF body resent as LF while off still brings CCJ\'s bytes back', $ccj14_craw === $ccj14_file( $ccj14_c ), $ccj14_why( $ccj14_c, $ccj14_craw ) );
	// Controls: what does determine the bytes still retires them.
	$ccj14_off( $ccj14_a );
	$ccj14_form( $ccj14_a, array( 'code' => '.minn-v043-k p { color: green; }' ) );
	$ccj14_on( $ccj14_a );
	$check( 'CCJ #14: code edited while off is what comes back on (control)', false !== strpos( $ccj14_file( $ccj14_a ), 'green' ) && false === strpos( $ccj14_file( $ccj14_a ), 'red' ) );
	// The linking changed behind Minn's back (not through its form): bytes
	// parked for <style>-wrapped internal output must not land in an
	// external file.
	list( $ccj14_l, $ccj14_lraw ) = $ccj14_seed( 'internal' );
	$ccj14_off( $ccj14_l );
	update_post_meta( $ccj14_l, 'options', array( 'type' => 'header', 'linking' => 'external', 'priority' => '5', 'side' => 'frontend', 'language' => 'css' ) );
	$ccj14_on( $ccj14_l );
	$check( 'CCJ #14: bytes parked for internal linking are not restored once it is external (control)', false === strpos( $ccj14_file( $ccj14_l ), '<style' ) );
	// Bytes parked by the previous release (keyed on the whole options
	// array) still come back when nothing changed.
	list( $ccj14_v, $ccj14_vraw ) = $ccj14_seed( 'internal' );
	$ccj14_vpost = get_post( $ccj14_v );
	update_post_meta( $ccj14_v, '_minn_ccj_parked', wp_slash( array(
		'bytes' => $ccj14_vraw,
		'for'   => md5( $ccj14_vpost->post_content . "\0" . wp_json_encode( minn_admin_ccj_get_options( $ccj14_v ) ) ),
	) ) );
	minn_admin_ccj_update_post_meta_only( array( 'ID' => $ccj14_v, 'post_status' => 'draft' ) );
	update_post_meta( $ccj14_v, '_active', 'no' );
	@unlink( CCJ_UPLOAD_DIR . '/' . $ccj14_v . '.css' );
	$ccj14_on( $ccj14_v );
	$check( 'CCJ #14: bytes parked by the previous release still come back', $ccj14_vraw === $ccj14_file( $ccj14_v ), $ccj14_why( $ccj14_v, $ccj14_vraw ) );

	foreach ( array_filter( $ccj14_made ) as $ccj14_id ) {
		$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$ccj14_id}" );
		if ( get_post( $ccj14_id ) ) {
			wp_delete_post( $ccj14_id, true );
		}
		foreach ( array( 'css', 'js', 'html' ) as $ccj14_x ) {
			@unlink( CCJ_UPLOAD_DIR . '/' . $ccj14_id . '.' . $ccj14_x );
		}
	}
	update_option( 'custom-css-js-tree', $ccj14_tree_was );
} else {
	$skip( 'CCJ #14 park/restore: Simple Custom CSS and JS inactive' );
}

// --- #4 GF builder keeps logic rules on non-field subjects -------------------
if ( function_exists( 'minn_admin_gfb_save' ) && class_exists( 'GFAPI' ) && minn_admin_gfb_crud_handler() ) {
	global $wpdb;
	// The builder's save body for one field, as gfbRowOut() builds it in app.js.
	$gfb4_rowout = function ( $f ) {
		$row = ! empty( $f['id'] ) ? array( 'id' => $f['id'] ) : array( 'type' => $f['type'], 'tempId' => $f['tempId'] );
		foreach ( (array) $f['settings'] as $k ) {
			if ( 'subInputs' === $k ) {
				$row['subInputs'] = array();
				foreach ( (array) ( $f['inputs'] ?? array() ) as $in ) {
					$row['subInputs'][] = array( 'id' => $in['id'], 'isHidden' => ! empty( $in['isHidden'] ), 'customLabel' => (string) ( $in['customLabel'] ?? '' ) );
				}
			} elseif ( 'choices' === $k ) {
				$row['choices'] = array();
				foreach ( (array) ( $f['choices'] ?? array() ) as $c ) {
					$row['choices'][] = array( 'text' => $c['text'], 'value' => $c['value'], 'isSelected' => ! empty( $c['isSelected'] ) );
				}
				$row['enableChoiceValue'] = ! empty( $f['enableChoiceValue'] );
			} else {
				$row[ $k ] = $f[ $k ] ?? null;
			}
		}
		return $row;
	};
	// Stored conditional logic per field id, read from the table (no cache).
	// No logic reads as null whether the key is absent or '' (their save
	// fills unset field properties with '').
	$gfb4_stored = function ( $form_id ) use ( $wpdb ) {
		$meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT display_meta FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id=%d', $form_id ) ), true );
		$out  = array();
		foreach ( (array) ( $meta['fields'] ?? array() ) as $f ) {
			$out[ (int) $f['id'] ] = array( 'label' => $f['label'] ?? '', 'logic' => empty( $f['conditionalLogic'] ) ? null : $f['conditionalLogic'] );
		}
		return $out;
	};
	$gfb4_refs = function ( $logic ) {
		$refs = array();
		foreach ( (array) ( is_array( $logic ) ? ( $logic['rules'] ?? array() ) : array() ) as $r ) {
			$refs[] = (string) $r['fieldId'] . ' ' . (string) $r['operator'] . ' ' . (string) $r['value'];
		}
		return $refs;
	};
	$gfb4_logic = function ( $action, $type, $rules ) {
		return array( 'actionType' => $action, 'logicType' => $type, 'rules' => $rules );
	};
	$gfb4_rule = function ( $field, $op, $value ) {
		return array( 'fieldId' => $field, 'operator' => $op, 'value' => $value );
	};
	// Rules an add-on authored in Gravity Forms' editor: a field shown only to
	// a logged-in user (entry meta created_by) or by a quiz score (an add-on's
	// entry meta, with an operator Gravity Forms accepts and Minn's list lacks).
	$gfb4_id = GFAPI::add_form( array(
		'title'  => 'Minn v043 builder logic ' . wp_generate_password( 6, false, false ),
		'button' => array( 'type' => 'text', 'text' => 'Submit', 'imageUrl' => '' ),
		'fields' => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ),
			array( 'id' => 2, 'type' => 'text', 'label' => 'Staff notes', 'isRequired' => true, 'conditionalLogic' => $gfb4_logic( 'show', 'all', array( $gfb4_rule( 'created_by', 'is', '1' ) ) ) ),
			array( 'id' => 3, 'type' => 'text', 'label' => 'Mixed', 'conditionalLogic' => $gfb4_logic( 'show', 'any', array( $gfb4_rule( '1', 'is', 'a' ), $gfb4_rule( 'gquiz_score', '>=', '5' ) ) ) ),
			array( 'id' => 4, 'type' => 'text', 'label' => 'Goes away' ),
			array( 'id' => 5, 'type' => 'text', 'label' => 'Depends on 4', 'conditionalLogic' => $gfb4_logic( 'show', 'all', array( $gfb4_rule( '4', 'is', 'y' ), $gfb4_rule( 'created_by', 'isnot', '0' ) ) ) ),
			array( 'id' => 6, 'type' => 'text', 'label' => 'Tests the new field' ),
			array( 'id' => 7, 'type' => 'text', 'label' => 'Depends on 8', 'conditionalLogic' => $gfb4_logic( 'show', 'all', array( $gfb4_rule( '8', 'is', 'q' ), $gfb4_rule( 'created_by', 'is', '1' ) ) ) ),
			array( 'id' => 8, 'type' => 'text', 'label' => 'Temp source' ),
		),
	) );
	if ( is_wp_error( $gfb4_id ) || ! $gfb4_id ) {
		$check( 'GF builder logic seed', false, is_wp_error( $gfb4_id ) ? $gfb4_id->get_error_message() : 'no id' );
	} else {
		// An untouched save keeps every stored rule as it was.
		list( , $gfb4_p3 ) = $call( 'GET', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder" );
		$gfb4_before       = wp_json_encode( $gfb4_stored( $gfb4_id ) );
		list( $gfb4_st3 )  = $call( 'POST', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder", array(
			'stamp'  => $gfb4_p3['stamp'] ?? null,
			'known'  => array_map( function ( $f ) {
				return $f['id'];
			}, $gfb4_p3['fields'] ),
			'fields' => array_map( $gfb4_rowout, $gfb4_p3['fields'] ),
		) );
		$check( 'GF builder logic: an untouched save keeps every stored rule', 200 === $gfb4_st3 && wp_json_encode( $gfb4_stored( $gfb4_id ) ) === $gfb4_before, "status {$gfb4_st3}" );

		// The page loads, the user relabels Name, edits Mixed's field rule,
		// removes "Goes away" (the client prunes rules that tested it) and adds
		// a field that "Tests the new field" points at by its temp key.
		list( , $gfb4_p ) = $call( 'GET', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder" );
		$gfb4_proto       = null;
		foreach ( (array) ( $gfb4_p['palette'] ?? array() ) as $gfb4_grp ) {
			foreach ( $gfb4_grp['types'] as $gfb4_t ) {
				if ( 'text' === $gfb4_t['type'] ) {
					$gfb4_proto = $gfb4_t['proto'];
				}
			}
		}
		$gfb4_rows = array();
		foreach ( $gfb4_p['fields'] as $gfb4_f ) {
			if ( 4 === (int) $gfb4_f['id'] ) {
				continue;
			}
			if ( 1 === (int) $gfb4_f['id'] ) {
				$gfb4_f['label'] = 'Your name';
			}
			if ( 3 === (int) $gfb4_f['id'] ) {
				$gfb4_f['conditionalLogic']['rules'][0]['value'] = 'b';
				// Hostile: subjects that were never stored on this field.
				$gfb4_f['conditionalLogic']['rules'][] = $gfb4_rule( 'injected_meta', 'is', 'x' );
				$gfb4_f['conditionalLogic']['rules'][] = $gfb4_rule( 'CREATED_BY', 'is', '1' );
				$gfb4_f['conditionalLogic']['rules'][] = $gfb4_rule( 'new:ghost', 'is', 'x' );
				$gfb4_f['conditionalLogic']['rules'][] = $gfb4_rule( '999', 'is', 'x' );
			}
			if ( 5 === (int) $gfb4_f['id'] ) {
				$gfb4_f['conditionalLogic']['rules'] = array_values( array_filter( $gfb4_f['conditionalLogic']['rules'], function ( $r ) {
					return '4' !== strtok( (string) $r['fieldId'], '.' );
				} ) );
			}
			if ( 6 === (int) $gfb4_f['id'] ) {
				$gfb4_f['conditionalLogic'] = $gfb4_logic( 'show', 'all', array( $gfb4_rule( 'new:t1', 'is', 'x' ) ) );
			}
			$gfb4_rows[] = $gfb4_rowout( $gfb4_f );
		}
		// Hostile: a brand-new field has no stored subjects to keep.
		$gfb4_new          = array_merge( (array) $gfb4_proto, array( 'id' => 0, 'tempId' => 't1', 'label' => 'Brand new', 'conditionalLogic' => $gfb4_logic( 'show', 'all', array( $gfb4_rule( 'created_by', 'is', '1' ), $gfb4_rule( '1', 'is', 'z' ) ) ) ) );
		$gfb4_rows[]       = $gfb4_rowout( $gfb4_new );
		list( $gfb4_st, $gfb4_r ) = $call( 'POST', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder", array(
			'stamp'  => $gfb4_p['stamp'] ?? null,
			'known'  => array_map( function ( $f ) {
				return $f['id'];
			}, $gfb4_p['fields'] ),
			'fields' => $gfb4_rows,
		) );
		$gfb4_s   = $gfb4_stored( $gfb4_id );
		$gfb4_nid = 0;
		foreach ( $gfb4_s as $gfb4_fid => $gfb4_f ) {
			if ( 'Brand new' === $gfb4_f['label'] ) {
				$gfb4_nid = $gfb4_fid;
			}
		}
		$check( 'GF builder logic: the save goes through (control)', 200 === $gfb4_st && 'Your name' === ( $gfb4_s[1]['label'] ?? '' ) && ! isset( $gfb4_s[4] ), "status {$gfb4_st} " . ( is_array( $gfb4_r ) && isset( $gfb4_r['message'] ) ? $gfb4_r['message'] : '' ) );
		$check( 'GF builder logic: a field the user never touched keeps its add-on rule', array( 'created_by is 1' ) === $gfb4_refs( $gfb4_s[2]['logic'] ?? null ), wp_json_encode( $gfb4_s[2]['logic'] ?? null ) );
		$check( 'GF builder logic: editing a field rule keeps the add-on rule beside it, operator included', array( '1 is b', 'gquiz_score >= 5' ) === $gfb4_refs( $gfb4_s[3]['logic'] ?? null ), wp_json_encode( $gfb4_s[3]['logic'] ?? null ) );
		$check( 'GF builder logic: subjects never stored on the field are not written (hostile)', ! array_intersect( array( 'injected_meta is x', 'CREATED_BY is 1', 'new:ghost is x', '999 is x' ), $gfb4_refs( $gfb4_s[3]['logic'] ?? null ) ), wp_json_encode( $gfb4_s[3]['logic'] ?? null ) );
		$check( 'GF builder logic: a rule on the removed field goes, the add-on rule beside it stays', array( 'created_by isnot 0' ) === $gfb4_refs( $gfb4_s[5]['logic'] ?? null ), wp_json_encode( $gfb4_s[5]['logic'] ?? null ) );
		$check( 'GF builder logic: a rule on a new field resolves to its id (control)', $gfb4_nid && array( $gfb4_nid . ' is x' ) === $gfb4_refs( $gfb4_s[6]['logic'] ?? null ), wp_json_encode( $gfb4_s[6]['logic'] ?? null ) );
		$check( 'GF builder logic: a new field cannot be given an add-on subject (hostile)', $gfb4_nid && array( '1 is z' ) === $gfb4_refs( $gfb4_s[ $gfb4_nid ]['logic'] ?? null ), wp_json_encode( $gfb4_s[ $gfb4_nid ]['logic'] ?? null ) );

		// A client that does not prune: "Temp source" removed while "Depends
		// on 8" is sent unchanged. Gravity Forms' own delete drops the rule
		// on the removed field; the add-on rule stays. In the same save the
		// user deletes Mixed's add-on rule, which must still be possible.
		list( , $gfb4_p2 ) = $call( 'GET', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder" );
		$gfb4_rows2        = array();
		foreach ( $gfb4_p2['fields'] as $gfb4_f ) {
			if ( 3 === (int) $gfb4_f['id'] ) {
				$gfb4_f['conditionalLogic']['rules'] = array_values( array_filter( $gfb4_f['conditionalLogic']['rules'], function ( $r ) {
					return 'gquiz_score' !== $r['fieldId'];
				} ) );
			}
			if ( 8 !== (int) $gfb4_f['id'] ) {
				$gfb4_rows2[] = $gfb4_rowout( $gfb4_f );
			}
		}
		list( $gfb4_st2 ) = $call( 'POST', "/minn-admin/v1/gf/forms/{$gfb4_id}/builder", array(
			'stamp'  => $gfb4_p2['stamp'] ?? null,
			'known'  => array_map( function ( $f ) {
				return $f['id'];
			}, $gfb4_p2['fields'] ),
			'fields' => $gfb4_rows2,
		) );
		$gfb4_s2 = $gfb4_stored( $gfb4_id );
		$check( 'GF builder logic: removing a field drops rules on it from untouched fields, keeps the add-on rule', 200 === $gfb4_st2 && ! isset( $gfb4_s2[8] ) && array( 'created_by is 1' ) === $gfb4_refs( $gfb4_s2[7]['logic'] ?? null ), "status {$gfb4_st2} " . wp_json_encode( $gfb4_s2[7]['logic'] ?? null ) );
		$check( 'GF builder logic: an add-on rule the user deletes in the builder goes (control)', array( '1 is b' ) === $gfb4_refs( $gfb4_s2[3]['logic'] ?? null ), wp_json_encode( $gfb4_s2[3]['logic'] ?? null ) );

		GFAPI::delete_form( $gfb4_id );
	}
} else {
	$skip( '#4 Gravity Forms builder not available' );
}

// --- #5 GF builder refuses a stale save instead of reverting ----------------
if ( function_exists( 'minn_admin_gfb_save' ) && class_exists( 'GFAPI' ) && minn_admin_gfb_crud_handler() ) {
	global $wpdb;
	$gfb5_rowout = function ( $f ) {
		$row = array( 'id' => $f['id'] );
		foreach ( (array) $f['settings'] as $k ) {
			if ( 'subInputs' === $k ) {
				$row['subInputs'] = array();
				foreach ( (array) ( $f['inputs'] ?? array() ) as $in ) {
					$row['subInputs'][] = array( 'id' => $in['id'], 'isHidden' => ! empty( $in['isHidden'] ), 'customLabel' => (string) ( $in['customLabel'] ?? '' ) );
				}
			} elseif ( 'choices' === $k ) {
				$row['choices'] = array();
				foreach ( (array) ( $f['choices'] ?? array() ) as $c ) {
					$row['choices'][] = array( 'text' => $c['text'], 'value' => $c['value'], 'isSelected' => ! empty( $c['isSelected'] ) );
				}
				$row['enableChoiceValue'] = ! empty( $f['enableChoiceValue'] );
			} else {
				$row[ $k ] = $f[ $k ] ?? null;
			}
		}
		return $row;
	};
	// What the builder page sends after relabelling field 1: every field row,
	// the stamp it loaded, and a form property only when edited ($extra).
	$gfb5_body = function ( $p, $extra = array() ) use ( $gfb5_rowout ) {
		$rows = array();
		foreach ( $p['fields'] as $f ) {
			if ( 1 === (int) $f['id'] ) {
				$f['label'] = 'First (edited in Minn)';
			}
			$rows[] = $gfb5_rowout( $f );
		}
		return array_merge( array(
			'stamp'  => $p['stamp'] ?? null,
			'known'  => array_map( function ( $f ) {
				return $f['id'];
			}, $p['fields'] ),
			'fields' => $rows,
		), $extra );
	};
	$gfb5_state = function ( $form_id ) use ( $wpdb ) {
		$meta   = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT display_meta FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id=%d', $form_id ) ), true );
		$labels = array();
		foreach ( (array) ( $meta['fields'] ?? array() ) as $f ) {
			$labels[ (int) $f['id'] ] = $f['label'] ?? '';
		}
		return array(
			'active' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM ' . GFFormsModel::get_form_table_name() . ' WHERE id=%d', $form_id ) ),
			'title'  => $meta['title'] ?? '',
			'labels' => $labels,
		);
	};
	$gfb5_rename = function ( $form_id, $field_id, $label ) {
		$form = GFAPI::get_form( $form_id );
		foreach ( $form['fields'] as $f ) {
			if ( (int) $f->id === $field_id ) {
				$f->label = $label;
			}
		}
		GFAPI::update_form( $form );
	};
	$gfb5_title = 'Minn v043 builder stale ' . wp_generate_password( 6, false, false );
	$gfb5_id    = GFAPI::add_form( array(
		'title'  => $gfb5_title,
		'button' => array( 'type' => 'text', 'text' => 'Submit', 'imageUrl' => '' ),
		'fields' => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'First' ),
			array( 'id' => 2, 'type' => 'text', 'label' => 'Second' ),
		),
	) );
	if ( is_wp_error( $gfb5_id ) || ! $gfb5_id ) {
		$check( 'GF builder stale seed', false, is_wp_error( $gfb5_id ) ? $gfb5_id->get_error_message() : 'no id' );
	} else {
		$gfb5_route = "/minn-admin/v1/gf/forms/{$gfb5_id}/builder";

		// Tab A loads; the form is deactivated from Minn's Forms view.
		list( , $gfb5_a ) = $call( 'GET', $gfb5_route );
		$call( 'POST', "/minn-admin/v1/gf/forms/{$gfb5_id}/active", array( 'active' => false ) );
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_a ) );
		$gfb5_s          = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: a save after the form was deactivated elsewhere is refused (409)', 409 === $gfb5_st, "status {$gfb5_st}" );
		$check( 'GF builder stale: the deactivated form stays off', 0 === $gfb5_s['active'] && 'First' === ( $gfb5_s['labels'][1] ?? '' ), wp_json_encode( $gfb5_s ) );
		// Hostile: the shipped client's body, every form property sent as loaded.
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_a, array( 'title' => $gfb5_a['form']['title'], 'description' => $gfb5_a['form']['description'], 'buttonText' => $gfb5_a['form']['buttonText'], 'active' => true ) ) );
		$gfb5_s          = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: a body that re-sends active as loaded does not re-activate (hostile)', 409 === $gfb5_st && 0 === $gfb5_s['active'], "status {$gfb5_st} active {$gfb5_s['active']}" );

		// Tab B loads now; field 2 is renamed in Gravity Forms' editor.
		list( , $gfb5_b ) = $call( 'GET', $gfb5_route );
		$gfb5_rename( $gfb5_id, 2, 'Second (renamed in GF)' );
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_b ) );
		$gfb5_s          = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: a save after a field was edited in Gravity Forms is refused (409)', 409 === $gfb5_st, "status {$gfb5_st}" );
		$check( 'GF builder stale: the other editor\'s change is not reverted', 'Second (renamed in GF)' === ( $gfb5_s['labels'][2] ?? '' ) && 'First' === ( $gfb5_s['labels'][1] ?? '' ), wp_json_encode( $gfb5_s['labels'] ) );

		// Hostile: no stamp, or a stamp that is not a string.
		list( , $gfb5_c ) = $call( 'GET', $gfb5_route );
		$gfb5_nostamp     = $gfb5_body( $gfb5_c );
		unset( $gfb5_nostamp['stamp'] );
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_nostamp );
		$check( 'GF builder stale: a save without the loaded stamp is refused (hostile)', 409 === $gfb5_st, "status {$gfb5_st}" );
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_c, array( 'stamp' => array( 'x' ) ) ) );
		$check( 'GF builder stale: a non-string stamp is refused (hostile)', 409 === $gfb5_st, "status {$gfb5_st}" );

		// CONTROL: an entry arriving while the page is open does not stale it,
		// nor does a notification edited elsewhere (the builder writes
		// neither), and a save that leaves the switch alone leaves the form
		// inactive.
		$gfb5_entry       = GFAPI::add_entry( array( 'form_id' => $gfb5_id, '1' => 'Dana' ) );
		$gfb5_notes       = (array) ( GFFormsModel::get_form_meta( $gfb5_id )['notifications'] ?? array() );
		$gfb5_notes['minnv043'] = array( 'id' => 'minnv043', 'name' => 'Minn probe', 'isActive' => true, 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'Edited elsewhere', 'message' => '{all_fields}' );
		GFFormsModel::save_form_notifications( $gfb5_id, $gfb5_notes );
		list( $gfb5_st, $gfb5_r ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_c ) );
		$gfb5_s           = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: a fresh page saves while entries arrive (control)', 200 === $gfb5_st && 'First (edited in Minn)' === ( $gfb5_s['labels'][1] ?? '' ) && 'Second (renamed in GF)' === ( $gfb5_s['labels'][2] ?? '' ), "status {$gfb5_st} " . wp_json_encode( $gfb5_s['labels'] ) );
		$check( 'GF builder stale: a save that leaves the switch alone keeps the form inactive (control)', 0 === $gfb5_s['active'] && $gfb5_title === $gfb5_s['title'], wp_json_encode( $gfb5_s ) );

		// CONTROL: the next save from the same page (the stamp its last save
		// returned) turns the form on and renames it.
		list( $gfb5_st, $gfb5_r2 ) = $call( 'POST', $gfb5_route, $gfb5_body( is_array( $gfb5_r ) ? $gfb5_r : array( 'fields' => array() ), array( 'active' => true, 'title' => $gfb5_title . ' renamed' ) ) );
		$gfb5_s                    = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: the same page saves again and the switch turns the form on (control)', 200 === $gfb5_st && 1 === $gfb5_s['active'] && $gfb5_title . ' renamed' === $gfb5_s['title'], "status {$gfb5_st} " . wp_json_encode( $gfb5_s ) );

		// The form deactivated through Gravity Forms' own list.
		list( , $gfb5_d ) = $call( 'GET', $gfb5_route );
		GFFormsModel::update_form_active( $gfb5_id, 0 );
		list( $gfb5_st ) = $call( 'POST', $gfb5_route, $gfb5_body( $gfb5_d, array( 'active' => true ) ) );
		$gfb5_s          = $gfb5_state( $gfb5_id );
		$check( 'GF builder stale: deactivated in Gravity Forms\' list, the stale page cannot turn it back on', 409 === $gfb5_st && 0 === $gfb5_s['active'], "status {$gfb5_st} active {$gfb5_s['active']}" );

		if ( ! is_wp_error( $gfb5_entry ) ) {
			GFAPI::delete_entry( $gfb5_entry );
		}
		GFAPI::delete_form( $gfb5_id );
	}
} else {
	$skip( '#5 Gravity Forms builder not available' );
}


// --- #26 A language pack for Minn installs only from Minn's own release -----
$ug_upd = null;
foreach ( (array) ( $GLOBALS['wp_filter']['upgrader_pre_download']->callbacks ?? array() ) as $ug_cbs ) {
	foreach ( $ug_cbs as $ug_cb ) {
		if ( is_array( $ug_cb['function'] ) && $ug_cb['function'][0] instanceof Minn_Admin_Updater ) {
			$ug_upd = $ug_cb['function'][0];
		}
	}
}
if ( ! $ug_upd || ! class_exists( 'ZipArchive' ) || wp_using_ext_object_cache() ) {
	$skip( '#26 updater instance, ZipArchive or option-backed transients unavailable' );
} else {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	// A stand-in pack: one inert .l10n.php, which is all core's check_package()
	// asks of a language pack before it copies the files into WP_LANG_DIR.
	$ug_dir = trailingslashit( get_temp_dir() ) . 'minn-ug-' . wp_generate_password( 8, false, false );
	wp_mkdir_p( $ug_dir );
	$ug_zip = $ug_dir . '/pack.zip';
	$ug_z   = new ZipArchive();
	$ug_z->open( $ug_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$ug_z->addFromString( 'minn-admin-zz_ZZ.l10n.php', "<?php\n// minn-admin test pack\nreturn array( 'messages' => array() );\n" );
	$ug_z->close();
	$ug_own     = 'https://github.com/austinginder/minn-admin/releases/download/v' . MINN_ADMIN_VERSION . '/minn-admin-zz_ZZ.zip';
	$ug_foreign = 'https://downloads.wordpress.org/translation/plugin/minn-admin/9.9.9/zz_ZZ.zip';
	$ug_landed  = WP_LANG_DIR . '/plugins/minn-admin-zz_ZZ.l10n.php';
	$ug_sweep   = function () {
		foreach ( (array) glob( WP_LANG_DIR . '/plugins/minn-admin-zz_ZZ*' ) as $ug_f ) {
			wp_delete_file( $ug_f );
		}
	};
	$ug_sweep();
	// Offline: both pack URLs are served the stand-in zip from here and every
	// other request fails, so nothing in this section leaves the machine.
	$ug_fetched = array();
	$ug_http    = function ( $pre, $args, $url ) use ( $ug_zip, $ug_own, $ug_foreign, &$ug_fetched ) {
		if ( $url === $ug_own || $url === $ug_foreign ) {
			$ug_fetched[] = $url;
			if ( ! empty( $args['filename'] ) ) {
				copy( $ug_zip, $args['filename'] );
			}
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		}
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $ug_http, PHP_INT_MAX, 3 );
	// The release manifest the updater judges against, held in its own cache
	// for the length of the section (raw options, restored exactly after).
	$ug_raw = array();
	foreach ( array( '_transient_minn_admin_updater', '_transient_timeout_minn_admin_updater' ) as $ug_k ) {
		$ug_raw[ $ug_k ] = get_option( $ug_k, null );
	}
	$ug_site_raw = array();
	foreach ( array( '_site_transient_update_plugins', '_site_transient_update_themes', '_site_transient_update_core' ) as $ug_k ) {
		$ug_site_raw[ $ug_k ] = get_site_option( $ug_k, null );
	}
	set_transient(
		'minn_admin_updater',
		(object) array(
			'version'      => MINN_ADMIN_VERSION,
			'download_url' => 'https://github.com/austinginder/minn-admin/releases/download/v' . MINN_ADMIN_VERSION . '/minn-admin.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'tested'       => '',
			'requires_php' => '',
			'translations' => array(
				(object) array( 'language' => 'zz_ZZ', 'version' => MINN_ADMIN_VERSION, 'updated' => gmdate( 'Y-m-d H:i:s' ), 'package' => $ug_own, 'sha256' => hash_file( 'sha256', $ug_zip ) ),
			),
		),
		HOUR_IN_SECONDS
	);
	// Core's Language_Pack_Upgrader::bulk_upgrade() hook_extra, exactly: no
	// 'plugin' key, the offer itself under language_update.
	$ug_extra = function ( $package, $slug = 'minn-admin', $type = 'plugin' ) {
		return array(
			'language_update_type' => $type,
			'language_update'      => (object) array( 'type' => $type, 'slug' => $slug, 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $package, 'autoupdate' => true ),
		);
	};
	$ug_lpu = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
	$ug_dl  = function ( $package, $extra ) use ( $ug_lpu ) {
		$r = $ug_lpu->download_package( $package, false, $extra );
		if ( is_string( $r ) && is_file( $r ) && false === strpos( $r, 'minn-ug-' ) ) {
			wp_delete_file( $r );
		}
		return $r;
	};
	$ug_code = function ( $r ) {
		return is_wp_error( $r ) ? $r->get_error_code() : ( is_string( $r ) ? 'file' : var_export( $r, true ) );
	};

	// The download gate, through core's own download_package() call.
	$ug_fetched = array();
	$ug_r       = $ug_dl( $ug_foreign, $ug_extra( $ug_foreign ) );
	$check( '#26 a same-slug pack from another source is refused before download', is_wp_error( $ug_r ) && 'minn_admin_foreign_package' === $ug_r->get_error_code(), $ug_code( $ug_r ) );
	$check( '#26 ...and the foreign URL is never fetched', ! in_array( $ug_foreign, $ug_fetched, true ), implode( ' ', $ug_fetched ) );
	// A local path in the offer: core hands an existing file straight to the
	// unzipper once the filters pass on it.
	$ug_local = $ug_dir . '/local-pack.zip';
	copy( $ug_zip, $ug_local );
	$ug_r = $ug_dl( $ug_local, $ug_extra( $ug_local ) );
	$check( '#26 a local file offered as Minn\'s pack is refused', is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	$ug_r = $ug_dl( $ug_foreign, $ug_extra( $ug_foreign, 'Minn-Admin' ) );
	$check( '#26 the slug is matched without regard to case (case-insensitive filesystems)', is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	$ug_r = $ug_dl( $ug_foreign, $ug_extra( $ug_foreign, 'minn-admin', '' ) );
	$check( '#26 an offer with no type for Minn\'s slug is refused', is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	$ug_r = $ug_dl( $ug_foreign, $ug_extra( $ug_foreign, ' minn-admin ' ) );
	$check( '#26 a padded slug is still Minn\'s', is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	foreach ( array(
		'https://github.com/austinginder/minn-admin/../../other/repo/releases/download/v1/minn-admin-zz_ZZ.zip',
		'https://github.com/austinginder/minn-admin/releases/download/%2e%2e/%2e%2e/%2e%2e/other/x.zip',
		'https://github.com/attacker/repo/raw/main/austinginder/minn-admin/releases/download/v1/x.zip',
		'http://github.com/austinginder/minn-admin/releases/download/v1/minn-admin-zz_ZZ.zip',
		'https://github.com.evil.example/austinginder/minn-admin/releases/download/v1/minn-admin-zz_ZZ.zip',
	) as $ug_bad ) {
		$ug_r = $ug_upd->verify_package( false, $ug_bad, $ug_lpu, $ug_extra( $ug_bad ) );
		$check( '#26 refused: ' . $ug_bad, is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	}
	// Another filter answering first with a file does not skip the check.
	$ug_r = $ug_upd->verify_package( $ug_local, $ug_foreign, $ug_lpu, $ug_extra( $ug_foreign ) );
	$check( '#26 a file another filter supplies for a foreign Minn pack is refused', is_wp_error( $ug_r ), $ug_code( $ug_r ) );
	// Controls: Minn's own published pack still downloads and verifies, a
	// tampered copy of it does not, and other plugins' packs pass untouched.
	$ug_r = $ug_dl( $ug_own, $ug_extra( $ug_own ) );
	$check( '#26 control: Minn\'s own published pack downloads and matches its sha256', is_string( $ug_r ), $ug_code( $ug_r ) );
	$ug_tamper = $ug_dir . '/tampered.zip';
	file_put_contents( $ug_tamper, 'not the published bytes' );
	$ug_r = $ug_upd->verify_package( $ug_tamper, $ug_own, $ug_lpu, $ug_extra( $ug_own ) );
	$check( '#26 control: a tampered copy of Minn\'s own pack fails its sha256', is_wp_error( $ug_r ) && 'minn_admin_bad_package_hash' === $ug_r->get_error_code(), $ug_code( $ug_r ) );
	$ug_other = 'https://downloads.wordpress.org/translation/plugin/akismet/5.0/zz_ZZ.zip';
	$ug_r     = $ug_upd->verify_package( false, $ug_other, $ug_lpu, $ug_extra( $ug_other, 'akismet' ) );
	$check( '#26 control: another plugin\'s language pack passes through untouched', false === $ug_r, $ug_code( $ug_r ) );
	$ug_r = $ug_upd->verify_package( false, $ug_other, $ug_lpu, array( 'plugin' => 'akismet/akismet.php' ) );
	$check( '#26 control: another plugin\'s own update passes through untouched', false === $ug_r, $ug_code( $ug_r ) );

	// The offer side: update() drops same-slug packs it would refuse.
	$ug_t               = new stdClass();
	$ug_t->checked      = array( 'minn-admin/minn-admin.php' => MINN_ADMIN_VERSION );
	$ug_t->response     = array();
	$ug_t->translations = array(
		array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $ug_foreign, 'autoupdate' => true ),
		array( 'type' => 'plugin', 'slug' => 'Minn-Admin', 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $ug_foreign, 'autoupdate' => true ),
		(object) array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $ug_local, 'autoupdate' => true ),
		array( 'type' => 'plugin', 'slug' => 'akismet', 'language' => 'zz_ZZ', 'version' => '5.0', 'updated' => '2026-10-01 00:00:00', 'package' => $ug_other, 'autoupdate' => true ),
		array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => MINN_ADMIN_VERSION, 'updated' => '2026-10-01 00:00:00', 'package' => $ug_own, 'autoupdate' => true ),
	);
	$ug_t    = $ug_upd->update( $ug_t );
	$ug_left = array();
	foreach ( (array) $ug_t->translations as $ug_e ) {
		$ug_e      = (array) $ug_e;
		$ug_left[] = $ug_e['slug'] . ' ' . $ug_e['package'];
	}
	$ug_bad_left = array_filter( $ug_left, function ( $l ) use ( $ug_own ) {
		return 0 === stripos( $l, 'minn-admin ' ) && false === strpos( $l, $ug_own );
	} );
	$check( '#26 the update transient drops same-slug packs from anywhere but the release', ! $ug_bad_left, implode( ' | ', $ug_bad_left ) );
	$check( '#26 control: other plugins\' packs and Minn\'s own stay offered', in_array( 'akismet ' . $ug_other, $ug_left, true ) && in_array( 'minn-admin ' . $ug_own, $ug_left, true ), implode( ' | ', $ug_left ) );

	// End to end: the Update Translations button (POST /translations/update,
	// Minn_Admin_Batch::run_translations with its prefetch and its own
	// pre_download), with the foreign offer injected AFTER the updater's
	// transient filter so only the download gate stands in the way.
	if ( ! current_user_can( 'update_languages' ) ) {
		$skip( '#26 end to end: this site disallows language updates' );
	} else {
		// The update data is answered from here (checked just now, so core
		// skips its own network check) with the one offer under test in it.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$ug_offer   = null;
		$ug_locales = function ( $l ) {
			$l[] = 'zz_ZZ';
			return $l;
		};
		$ug_pre_plugins = function () use ( &$ug_offer ) {
			$t               = new stdClass();
			$t->last_checked = time();
			$t->checked      = wp_list_pluck( get_plugins(), 'Version' );
			$t->response     = array();
			$t->no_update    = array();
			$t->translations = $ug_offer ? array( $ug_offer ) : array();
			return $t;
		};
		$ug_pre_themes = function () {
			$t               = new stdClass();
			$t->last_checked = time();
			$t->checked      = array();
			foreach ( wp_get_themes() as $ug_ss => $ug_th ) {
				$t->checked[ $ug_ss ] = $ug_th->get( 'Version' );
			}
			$t->response     = array();
			$t->no_update    = array();
			$t->translations = array();
			return $t;
		};
		$ug_pre_core = function () {
			return (object) array( 'last_checked' => time(), 'version_checked' => get_bloginfo( 'version' ), 'updates' => array(), 'translations' => array() );
		};
		add_filter( 'plugins_update_check_locales', $ug_locales );
		add_filter( 'pre_site_transient_update_plugins', $ug_pre_plugins );
		add_filter( 'pre_site_transient_update_themes', $ug_pre_themes );
		add_filter( 'pre_site_transient_update_core', $ug_pre_core );

		$ug_offer = array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $ug_foreign, 'autoupdate' => true );
		list( $ug_st, $ug_body ) = $call( 'POST', '/minn-admin/v1/translations/update' );
		$ug_hostile_landed = file_exists( $ug_landed );
		$ug_sweep();
		$check( '#26 Update Translations does not install a foreign pack for Minn', ! $ug_hostile_landed, $ug_st . ' ' . wp_json_encode( is_array( $ug_body ) ? array_intersect_key( $ug_body, array_flip( array( 'code', 'message', 'updated', 'failed' ) ) ) : $ug_body ) );

		$ug_offer = array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => MINN_ADMIN_VERSION, 'updated' => '2026-10-01 00:00:00', 'package' => $ug_own, 'autoupdate' => true );
		list( $ug_st, $ug_body ) = $call( 'POST', '/minn-admin/v1/translations/update' );
		$ug_own_landed = file_exists( $ug_landed );
		$ug_sweep();
		$check( '#26 control: Update Translations installs Minn\'s own verified pack', $ug_own_landed, $ug_st . ' ' . wp_json_encode( is_array( $ug_body ) ? array_intersect_key( $ug_body, array_flip( array( 'code', 'message', 'updated', 'failed' ) ) ) : $ug_body ) );

		remove_filter( 'plugins_update_check_locales', $ug_locales );
		remove_filter( 'pre_site_transient_update_plugins', $ug_pre_plugins );
		remove_filter( 'pre_site_transient_update_themes', $ug_pre_themes );
		remove_filter( 'pre_site_transient_update_core', $ug_pre_core );
	}

	foreach ( $ug_site_raw as $ug_k => $ug_v ) {
		if ( null === $ug_v ) {
			delete_site_option( $ug_k );
		} else {
			update_site_option( $ug_k, $ug_v );
		}
	}
	foreach ( $ug_raw as $ug_k => $ug_v ) {
		if ( null === $ug_v ) {
			delete_option( $ug_k );
		} else {
			update_option( $ug_k, $ug_v, false );
		}
	}
	wp_cache_delete( 'minn_admin_updater', 'transient' );
	remove_filter( 'pre_http_request', $ug_http, PHP_INT_MAX );
	$ug_sweep();
	foreach ( (array) glob( $ug_dir . '/*' ) as $ug_f ) {
		wp_delete_file( $ug_f );
	}
	@rmdir( $ug_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_set_current_user( $admin );
}

// @sections

$summary();
