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

// --- #24 Novamira routes gate on Novamira's own runtime predicate ------------
if ( ! function_exists( 'minn_admin_novamira_active' ) || ! minn_admin_novamira_active() ) {
	$skip( '#24 Novamira inactive' );
} else {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ug_nv_vendor = function () {
		return function_exists( 'novamira_current_user_can_manage' ) ? novamira_current_user_can_manage() : current_user_can( novamira_manage_capability() );
	};
	$ug_nv_cache = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
	$ug_nv_cache->setAccessible( true );
	$ug_nv_probe = function ( $uid ) use ( $ug_nv_cache ) {
		wp_set_current_user( $uid );
		$ug_nv_cache->setValue( null, null ); // the registry is built once per request
		$ids = wp_list_pluck( Minn_Admin_Surfaces::for_current_user(), 'id' );
		$ug_nv_cache->setValue( null, null );
		$get = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/novamira/status' ) )->get_status();
		$mem = minn_admin_novamira_memory_ready() ? rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/novamira/memories' ) )->get_status() : 0;
		// A save of nothing: on a route that lets the caller in, it rewrites
		// the ability rules unchanged, so a wrong answer here costs nothing.
		$w = new WP_REST_Request( 'POST', '/minn-admin/v1/novamira/abilities/context' );
		$w->set_header( 'content-type', 'application/json' );
		$w->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$w->set_body( wp_json_encode( array( 'values' => array() ) ) );
		$post = rest_do_request( $w )->get_status();
		return array( 'surface' => in_array( 'novamira', $ids, true ), 'get' => $get, 'mem' => $mem, 'post' => $post );
	};
	// Control: the site's owner (a super admin on a network) reaches it all.
	$ug_nv_owner = $admin;
	if ( is_multisite() ) {
		$ug_nv_sa    = get_user_by( 'login', (string) current( get_super_admins() ) );
		$ug_nv_owner = $ug_nv_sa ? (int) $ug_nv_sa->ID : 0;
	}
	if ( $ug_nv_owner ) {
		wp_set_current_user( $ug_nv_owner );
		$ug_nv_ok = $ug_nv_vendor();
		$ug_nv_p  = $ug_nv_probe( $ug_nv_owner );
		$check( '#24 control: the user Novamira lets manage reaches the surface, status, memories and saves', $ug_nv_ok && $ug_nv_p['surface'] && 200 === $ug_nv_p['get'] && in_array( $ug_nv_p['mem'], array( 0, 200 ), true ) && 200 === $ug_nv_p['post'], wp_json_encode( $ug_nv_p ) );
	}
	// A principal holding the capability string Novamira hands to its menu
	// (a role plugin's grant) without passing Novamira's own check: on a
	// network that is anyone short of super admin.
	$ug_nv_uid = wp_insert_user( array(
		'user_login' => 'minn_ug_nv_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'minn-ug-nv-' . wp_generate_password( 6, false, false ) . '@example.com',
		'role'       => is_multisite() ? 'administrator' : 'editor',
	) );
	if ( is_wp_error( $ug_nv_uid ) ) {
		$skip( '#24 could not create the probe user: ' . $ug_nv_uid->get_error_message() );
	} else {
		( new WP_User( $ug_nv_uid ) )->add_cap( novamira_manage_capability() );
		wp_set_current_user( $ug_nv_uid );
		$ug_nv_ok = $ug_nv_vendor();
		$ug_nv_p  = $ug_nv_probe( $ug_nv_uid );
		$ug_nv_dn = function ( $code ) use ( $ug_nv_ok ) {
			return $ug_nv_ok ? 200 === $code : in_array( $code, array( 401, 403 ), true );
		};
		$check( '#24 the surface follows Novamira\'s predicate (' . ( is_multisite() ? 'network' : 'single site' ) . ')', $ug_nv_ok === $ug_nv_p['surface'], wp_json_encode( $ug_nv_p ) );
		$check( '#24 status read follows Novamira\'s predicate', $ug_nv_dn( $ug_nv_p['get'] ), 'vendor=' . var_export( $ug_nv_ok, true ) . ' got ' . $ug_nv_p['get'] );
		$check( '#24 memories read follows Novamira\'s predicate', 0 === $ug_nv_p['mem'] || $ug_nv_dn( $ug_nv_p['mem'] ), 'got ' . $ug_nv_p['mem'] );
		$check( '#24 ability/instructions save follows Novamira\'s predicate', $ug_nv_dn( $ug_nv_p['post'] ), 'got ' . $ug_nv_p['post'] );
		wp_set_current_user( $admin );
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $ug_nv_uid );
		} else {
			wp_delete_user( $ug_nv_uid );
		}
	}
	$ug_nv_cache->setValue( null, null );
	wp_set_current_user( $admin );
}

// --- #23 Beaver Builder: a refused or unreachable activation keeps the key --
if ( ! class_exists( 'FLUpdater' ) || ! method_exists( 'FLUpdater', 'save_subscription_license' ) || ! function_exists( 'minn_admin_license_default_providers' ) ) {
	$skip( '#23 Beaver Builder inactive' );
} else {
	// The real key is snapshotted and put back exactly; it is never printed,
	// and Beaver Builder's API never hears from this section.
	$ug_bb_opt   = 'fl_themes_subscription_email';
	$ug_bb_prev  = get_site_option( $ug_bb_opt, null );
	$ug_bb_info  = get_option( '_transient_fl_get_subscription_info', null );
	$ug_bb_tinfo = get_option( '_transient_timeout_fl_get_subscription_info', null );
	$ug_bb_site  = array();
	foreach ( array( '_site_transient_update_plugins', '_site_transient_update_themes' ) as $ug_k ) {
		$ug_bb_site[ $ug_k ] = get_site_option( $ug_k, null );
	}
	$ug_bb_answer = null;
	$ug_bb_http   = function ( $pre, $args, $url ) use ( &$ug_bb_answer ) {
		if ( 'updates.wpbeaverbuilder.com' === (string) wp_parse_url( $url, PHP_URL_HOST ) && is_callable( $ug_bb_answer ) ) {
			return call_user_func( $ug_bb_answer, $url );
		}
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	$ug_bb_json = function ( $body ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $ug_bb_http, PHP_INT_MAX, 3 );
	$ug_bb_paste = function ( $secret ) use ( $call ) {
		list( $st, $body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'beaver-builder', 'action' => 'activate', 'secret' => $secret ) );
		return array( $st, is_array( $body ) ? ! empty( $body['ok'] ) : null );
	};
	$ug_bb_working = 'minntestworkingkey';
	$ug_bb_cases   = array(
		// Beaver Builder blanks the stored key when its API refuses without a code.
		'a refused key'                => function () use ( $ug_bb_json ) {
			return $ug_bb_json( array( 'error' => 'Invalid license key.' ) );
		},
		// A transport failure carries a code, so the pasted key is stored.
		'an unreachable licence server' => function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		},
		// So does any refusal the API itself tags with a code.
		'a refusal that carries a code' => function () use ( $ug_bb_json ) {
			return $ug_bb_json( array( 'error' => 'Domain limit reached.', 'code' => 'limit' ) );
		},
	);
	foreach ( $ug_bb_cases as $ug_bb_label => $ug_bb_case ) {
		update_site_option( $ug_bb_opt, $ug_bb_working );
		$ug_bb_answer        = $ug_bb_case;
		list( $ug_st, $ug_ok ) = $ug_bb_paste( 'minntestbogus' . wp_generate_password( 6, false, false ) );
		$ug_bb_now           = get_site_option( $ug_bb_opt, null );
		$check( "#23 {$ug_bb_label} keeps the working key", 200 === $ug_st && false === $ug_ok && $ug_bb_working === $ug_bb_now, $ug_st . ' ok=' . var_export( $ug_ok, true ) . ' stored=' . ( $ug_bb_working === $ug_bb_now ? 'working' : ( null === $ug_bb_now ? 'absent' : ( '' === $ug_bb_now ? 'blank' : 'the pasted key' ) ) ) );
	}
	// No key before: a refused paste leaves none (absent, not a blank row).
	delete_site_option( $ug_bb_opt );
	$ug_bb_answer        = $ug_bb_cases['a refused key'];
	list( $ug_st, $ug_ok ) = $ug_bb_paste( 'minntestbogusabsent' );
	$ug_bb_now           = get_site_option( $ug_bb_opt, null );
	$check( '#23 a refused key on a site with none leaves it absent', false === $ug_ok && null === $ug_bb_now, var_export( $ug_bb_now, true ) );
	// The vendor's own character refusal never wrote anything; still intact.
	update_site_option( $ug_bb_opt, $ug_bb_working );
	list( $ug_st, $ug_ok ) = $ug_bb_paste( 'minn test <bad>' );
	$check( '#23 a key Beaver Builder rejects on sight keeps the working key', false === $ug_ok && $ug_bb_working === get_site_option( $ug_bb_opt, null ) );
	// Control: an accepted key is stored (the provider closure the route calls;
	// the route's post-success update check would also flush vendor caches).
	$ug_bb_answer = function ( $url ) use ( $ug_bb_json ) {
		return false !== strpos( $url, 'fl-api-method=subscription_info' ) ? $ug_bb_json( array( 'active' => true ) ) : $ug_bb_json( array( 'domain' => 'ok' ) );
	};
	$ug_bb_prov = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	$ug_bb_res  = minn_admin_license_result( call_user_func( $ug_bb_prov['beaver-builder']['activate'], 'minntestgoodkey' ) );
	$check( '#23 control: an accepted key is stored and reported valid', ! empty( $ug_bb_res['ok'] ) && 'minntestgoodkey' === get_site_option( $ug_bb_opt, null ), wp_json_encode( $ug_bb_res ) );

	remove_filter( 'pre_http_request', $ug_bb_http, PHP_INT_MAX );
	if ( null === $ug_bb_prev ) {
		delete_site_option( $ug_bb_opt );
	} else {
		update_site_option( $ug_bb_opt, $ug_bb_prev );
	}
	foreach ( array( '_transient_fl_get_subscription_info' => $ug_bb_info, '_transient_timeout_fl_get_subscription_info' => $ug_bb_tinfo ) as $ug_k => $ug_v ) {
		if ( null === $ug_v ) {
			delete_option( $ug_k );
		} else {
			update_option( $ug_k, $ug_v );
		}
	}
	wp_cache_delete( 'fl_get_subscription_info', 'transient' );
	foreach ( $ug_bb_site as $ug_k => $ug_v ) {
		if ( null === $ug_v ) {
			delete_site_option( $ug_k );
		} else {
			update_site_option( $ug_k, $ug_v );
		}
	}
	$check( '#23 cleanup: the site\'s own key is back exactly as it was', get_site_option( $ug_bb_opt, null ) === $ug_bb_prev );
	wp_set_current_user( $admin );
}

// --- #2 ACF panel and options saves keep backslashes in every field ---------
if ( function_exists( 'acf_add_local_field_group' ) && function_exists( 'acf_add_options_page' ) && function_exists( 'minn_admin_acf_write_values' ) ) {
	// What ACF's own metabox would have stored: it hands update_field() the
	// slashed $_POST, so these are the values a site really holds.
	$acf43s_path = 'C:\\Users\\minn\\file.txt';
	$acf43s_json = '{"a":"x\\"y","re":"\\\\d+","q":"O\'Brien"}';
	$acf43s_rx   = '^\\d+$';
	$acf43s_body = '<p>keep \\n and \\\\ here</p>';
	$acf43s_flex = 'two\\\\slashes';
	acf_add_local_field_group( array(
		'key'      => 'group_minn43s',
		'title'    => 'Minn v043 slash probe',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		'fields'   => array(
			array( 'key' => 'field_minn43s_path', 'name' => 'minn43s_path', 'label' => 'Path', 'type' => 'text' ),
			array( 'key' => 'field_minn43s_json', 'name' => 'minn43s_json', 'label' => 'JSON', 'type' => 'textarea' ),
			array( 'key' => 'field_minn43s_title', 'name' => 'minn43s_title', 'label' => 'Title', 'type' => 'text' ),
			array(
				'key'        => 'field_minn43s_rows',
				'name'       => 'minn43s_rows',
				'label'      => 'Rows',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_minn43s_rx', 'name' => 'rx', 'label' => 'Pattern', 'type' => 'text' ),
					// wysiwyg has no seat in the row cards: an unmapped sub the
					// row merge carries through from the stored row.
					array( 'key' => 'field_minn43s_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg' ),
					// A group sub flattens into the row and is stored nested.
					array( 'key' => 'field_minn43s_meta', 'name' => 'meta', 'label' => 'Meta', 'type' => 'group', 'sub_fields' => array(
						array( 'key' => 'field_minn43s_mk', 'name' => 'mk', 'label' => 'Key', 'type' => 'text' ),
					) ),
				),
			),
			array(
				'key'     => 'field_minn43s_flex',
				'name'    => 'minn43s_flex',
				'label'   => 'Sections',
				'type'    => 'flexible_content',
				'layouts' => array(
					'layout_minn43s' => array(
						'key'        => 'layout_minn43s',
						'name'       => 'para',
						'label'      => 'Para',
						'display'    => 'block',
						'sub_fields' => array(
							array( 'key' => 'field_minn43s_ptext', 'name' => 'ptext', 'label' => 'Text', 'type' => 'text' ),
						),
					),
				),
			),
		),
	) );
	$acf43s_post = wp_insert_post( array( 'post_title' => 'Minn v043 slash probe', 'post_status' => 'draft', 'post_author' => $admin ) );
	update_field( 'field_minn43s_path', wp_slash( $acf43s_path ), $acf43s_post );
	update_field( 'field_minn43s_json', wp_slash( $acf43s_json ), $acf43s_post );
	update_field( 'field_minn43s_title', 'Before', $acf43s_post );
	update_field( 'field_minn43s_rows', wp_slash( array( array( 'field_minn43s_rx' => $acf43s_rx, 'field_minn43s_body' => $acf43s_body, 'field_minn43s_meta' => array( 'field_minn43s_mk' => $acf43s_path ) ) ) ), $acf43s_post );
	update_field( 'field_minn43s_flex', wp_slash( array( array( 'acf_fc_layout' => 'para', 'field_minn43s_ptext' => $acf43s_flex ) ) ), $acf43s_post );
	$check( 'ACF slash probe: seeded values are stored as typed', $acf43s_path === get_post_meta( $acf43s_post, 'minn43s_path', true ) && $acf43s_rx === get_post_meta( $acf43s_post, 'minn43s_rows_0_rx', true ), get_post_meta( $acf43s_post, 'minn43s_path', true ) );

	// The client: load the post, seed the panel from minn_acf, edit ONE field,
	// send the whole panel back (app.js sends every value on a dirty panel).
	list( , $acf43s_got ) = $call( 'GET', '/wp/v2/posts/' . $acf43s_post, null, array( 'context' => 'edit' ) );
	$acf43s_vals          = json_decode( wp_json_encode( $acf43s_got['minn_acf'] ?? array() ), true );
	$acf43s_vals['minn43s_title'] = 'Edited \\o/';
	list( $acf43s_st )    = $call( 'POST', '/wp/v2/posts/' . $acf43s_post, array( 'minn_acf' => $acf43s_vals ) );
	wp_cache_delete( $acf43s_post, 'post_meta' );
	$acf43s_meta = function ( $k ) use ( $acf43s_post ) {
		return get_post_meta( $acf43s_post, $k, true );
	};
	$check( 'ACF panel save: an untouched text field keeps its backslashes', 200 === $acf43s_st && $acf43s_path === $acf43s_meta( 'minn43s_path' ), $acf43s_meta( 'minn43s_path' ) );
	$check( 'ACF panel save: an untouched textarea keeps escaped quotes and \\\\d', $acf43s_json === $acf43s_meta( 'minn43s_json' ), $acf43s_meta( 'minn43s_json' ) );
	$check( 'ACF panel save: an untouched repeater sub keeps its backslashes', $acf43s_rx === $acf43s_meta( 'minn43s_rows_0_rx' ), $acf43s_meta( 'minn43s_rows_0_rx' ) );
	$check( 'ACF panel save: a repeater sub the rows cannot show keeps its backslashes', $acf43s_body === $acf43s_meta( 'minn43s_rows_0_body' ), $acf43s_meta( 'minn43s_rows_0_body' ) );
	$check( 'ACF panel save: an untouched group sub inside a repeater row keeps its backslashes', $acf43s_path === $acf43s_meta( 'minn43s_rows_0_meta_mk' ), $acf43s_meta( 'minn43s_rows_0_meta_mk' ) );
	$check( 'ACF panel save: an untouched flexible-content sub keeps its backslashes', $acf43s_flex === $acf43s_meta( 'minn43s_flex_0_ptext' ), $acf43s_meta( 'minn43s_flex_0_ptext' ) );
	$check( 'CONTROL ACF panel save: the edited field stores what was typed, backslash included', 'Edited \\o/' === $acf43s_meta( 'minn43s_title' ), $acf43s_meta( 'minn43s_title' ) );
	// A second untouched save changes nothing at all.
	list( , $acf43s_got2 ) = $call( 'GET', '/wp/v2/posts/' . $acf43s_post, null, array( 'context' => 'edit' ) );
	$call( 'POST', '/wp/v2/posts/' . $acf43s_post, array( 'minn_acf' => json_decode( wp_json_encode( $acf43s_got2['minn_acf'] ?? array() ), true ) ) );
	wp_cache_delete( $acf43s_post, 'post_meta' );
	$check( 'ACF panel save: a second round trip is stable', $acf43s_json === $acf43s_meta( 'minn43s_json' ) && $acf43s_rx === $acf43s_meta( 'minn43s_rows_0_rx' ) && 'Edited \\o/' === $acf43s_meta( 'minn43s_title' ), $acf43s_meta( 'minn43s_json' ) );
	// Without unfiltered_html the markup filter still has the last word: what
	// is stored is exactly what it returned, backslashes and all.
	$acf43s_nofilter = function ( $caps ) {
		$caps['unfiltered_html'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $acf43s_nofilter, 99 );
	$acf43s_hostile = '<b>x\\y</b><script>alert(1)</script><a href="#" onclick="z()">l\\"</a>';
	$acf43s_vals['minn43s_title'] = $acf43s_hostile;
	$call( 'POST', '/wp/v2/posts/' . $acf43s_post, array( 'minn_acf' => $acf43s_vals ) );
	$acf43s_want = wp_kses_post( $acf43s_hostile );
	remove_filter( 'user_has_cap', $acf43s_nofilter, 99 );
	wp_cache_delete( $acf43s_post, 'post_meta' );
	$check( 'ACF panel save without unfiltered_html: the filtered value is stored exactly, no script', $acf43s_want === $acf43s_meta( 'minn43s_title' ) && false === stripos( $acf43s_meta( 'minn43s_title' ), '<script' ) && false === stripos( $acf43s_meta( 'minn43s_title' ), 'onclick' ), $acf43s_meta( 'minn43s_title' ) );
	wp_delete_post( $acf43s_post, true );

	// Options page: the client sends only the fields the user changed, but a
	// group sub rewrites its whole group and a repeater its whole rows.
	acf_add_options_page( array( 'page_title' => 'Minn v043 slash probe', 'menu_slug' => 'minn43s-options', 'post_id' => 'options', 'capability' => 'manage_options', 'redirect' => false ) );
	acf_add_local_field_group( array(
		'key'      => 'group_minn43so',
		'title'    => 'Minn v043 slash options',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'minn43s-options' ) ) ),
		'fields'   => array(
			array( 'key' => 'field_minn43so_text', 'name' => 'minn43so_text', 'label' => 'Text', 'type' => 'text' ),
			array(
				'key'        => 'field_minn43so_grp',
				'name'       => 'minn43so_grp',
				'label'      => 'Group',
				'type'       => 'group',
				'sub_fields' => array(
					array( 'key' => 'field_minn43so_ga', 'name' => 'ga', 'label' => 'A', 'type' => 'text' ),
					array( 'key' => 'field_minn43so_gb', 'name' => 'gb', 'label' => 'B', 'type' => 'text' ),
				),
			),
			array(
				'key'        => 'field_minn43so_rows',
				'name'       => 'minn43so_rows',
				'label'      => 'Rows',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_minn43so_r1', 'name' => 'r1', 'label' => 'R1', 'type' => 'text' ),
					array( 'key' => 'field_minn43so_r2', 'name' => 'r2', 'label' => 'R2', 'type' => 'text' ),
				),
			),
		),
	) );
	update_field( 'field_minn43so_text', 'plain', 'options' );
	update_field( 'field_minn43so_grp', wp_slash( array( 'field_minn43so_ga' => 'a', 'field_minn43so_gb' => $acf43s_path ) ), 'options' );
	update_field( 'field_minn43so_rows', wp_slash( array( array( 'field_minn43so_r1' => 'one', 'field_minn43so_r2' => $acf43s_rx ) ) ), 'options' );
	list( $acf43s_ost, $acf43s_tab ) = $call( 'GET', '/minn-admin/v1/acf/options/minn43s-options/tab-0' );
	$acf43s_rows = json_decode( wp_json_encode( $acf43s_tab['values']['field_minn43so_rows'] ?? array() ), true );
	if ( isset( $acf43s_rows[0]['values'] ) ) {
		$acf43s_rows[0]['values']['r1'] = 'one\\edited';
	}
	list( $acf43s_ost2 ) = $call( 'POST', '/minn-admin/v1/acf/options/minn43s-options/tab-0', array( 'values' => array(
		'field_minn43so_text' => 'typed \\d',
		'field_minn43so_ga'   => 'a\\b',
		'field_minn43so_rows' => $acf43s_rows,
	) ) );
	wp_cache_delete( 'alloptions', 'options' );
	$check( 'ACF options save: a typed backslash is stored', 200 === $acf43s_ost && 200 === $acf43s_ost2 && 'typed \\d' === get_option( 'options_minn43so_text' ), (string) get_option( 'options_minn43so_text' ) );
	$check( 'ACF options save: the edited group sub keeps its backslash', 'a\\b' === get_option( 'options_minn43so_grp_ga' ), (string) get_option( 'options_minn43so_grp_ga' ) );
	$check( 'ACF options save: an untouched sibling in the same group keeps its backslashes', $acf43s_path === get_option( 'options_minn43so_grp_gb' ), (string) get_option( 'options_minn43so_grp_gb' ) );
	$check( 'ACF options save: an untouched sub in an edited repeater row keeps its backslashes', $acf43s_rx === get_option( 'options_minn43so_rows_0_r2' ) && 'one\\edited' === get_option( 'options_minn43so_rows_0_r1' ), get_option( 'options_minn43so_rows_0_r2' ) . ' / ' . get_option( 'options_minn43so_rows_0_r1' ) );
	foreach ( array( 'minn43so_text', 'minn43so_grp', 'minn43so_rows' ) as $acf43s_n ) {
		delete_field( $acf43s_n, 'options' );
	}
	foreach ( array( 'minn43so_grp_ga', 'minn43so_grp_gb', 'minn43so_rows_0_r1', 'minn43so_rows_0_r2' ) as $acf43s_n ) {
		delete_option( 'options_' . $acf43s_n );
		delete_option( '_options_' . $acf43s_n );
	}
	acf_remove_local_field_group( 'group_minn43s' );
	acf_remove_local_field_group( 'group_minn43so' );
} else {
	$skip( 'ACF Pro inactive' );
}

// --- #18 ACF dates ACF accepts survive an unrelated panel save --------------
if ( function_exists( 'acf_add_local_field_group' ) && function_exists( 'acf_add_options_page' ) && function_exists( 'minn_admin_acf_write_values' ) ) {
	// Shapes ACF itself reads (acf_format_date takes a unix timestamp or any
	// strtotime() string; the time picker shows whatever is stored), which
	// Minn's controls cannot display.
	$acf43d_seed = array(
		'minn43d_ts'   => '1793404800',          // date_picker, unix timestamp
		'minn43d_str'  => '2026-10-31 14:00',    // date_picker, strtotime string
		'minn43d_dt'   => '2026-10-31 09:15:30', // date_time_picker, canonical with seconds
		'minn43d_dtts' => '1793404800',          // date_time_picker, unix timestamp
		'minn43d_t'    => '09:15:30',            // time_picker, canonical with seconds
		'minn43d_t12'  => '9:15 am',             // time_picker, imported 12-hour text
		'minn43d_ok'   => '20261031',            // date_picker, canonical (the control)
		'minn43d_tok'  => '18:30:00',            // time_picker, canonical (the control)
	);
	$acf43d_types = array(
		'minn43d_ts'   => 'date_picker',
		'minn43d_str'  => 'date_picker',
		'minn43d_dt'   => 'date_time_picker',
		'minn43d_dtts' => 'date_time_picker',
		'minn43d_t'    => 'time_picker',
		'minn43d_t12'  => 'time_picker',
		'minn43d_ok'   => 'date_picker',
		'minn43d_tok'  => 'time_picker',
	);
	$acf43d_fields = array( array( 'key' => 'field_minn43d_note', 'name' => 'minn43d_note', 'label' => 'Note', 'type' => 'text' ) );
	foreach ( $acf43d_types as $acf43d_n => $acf43d_t ) {
		$acf43d_fields[] = array( 'key' => 'field_' . $acf43d_n, 'name' => $acf43d_n, 'label' => $acf43d_n, 'type' => $acf43d_t, 'display_format' => 'time_picker' === $acf43d_t ? 'H:i:s' : 'd/m/Y' );
	}
	$acf43d_fields[] = array(
		'key'        => 'field_minn43d_rows',
		'name'       => 'minn43d_rows',
		'label'      => 'Schedule',
		'type'       => 'repeater',
		'sub_fields' => array(
			array( 'key' => 'field_minn43d_when', 'name' => 'when', 'label' => 'When', 'type' => 'date_picker' ),
			array( 'key' => 'field_minn43d_at', 'name' => 'at', 'label' => 'At', 'type' => 'time_picker' ),
			array( 'key' => 'field_minn43d_what', 'name' => 'what', 'label' => 'What', 'type' => 'text' ),
		),
	);
	acf_add_local_field_group( array(
		'key'      => 'group_minn43d',
		'title'    => 'Minn v043 date probe',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		'fields'   => $acf43d_fields,
	) );
	$acf43d_post = wp_insert_post( array( 'post_title' => 'Minn v043 date probe', 'post_status' => 'draft', 'post_author' => $admin ) );
	foreach ( $acf43d_seed as $acf43d_n => $acf43d_v ) {
		update_field( 'field_' . $acf43d_n, $acf43d_v, $acf43d_post );
	}
	update_field( 'field_minn43d_note', 'before', $acf43d_post );
	update_field( 'field_minn43d_rows', array( array( 'field_minn43d_when' => '1793404800', 'field_minn43d_at' => '18:30:45', 'field_minn43d_what' => 'gig' ) ), $acf43d_post );

	list( , $acf43d_got ) = $call( 'GET', '/wp/v2/posts/' . $acf43d_post, null, array( 'context' => 'edit' ) );
	$acf43d_vals          = json_decode( wp_json_encode( $acf43d_got['minn_acf'] ?? array() ), true );
	$acf43d_vals['minn43d_note'] = 'after';
	if ( isset( $acf43d_vals['minn43d_rows'][0]['values'] ) ) {
		$acf43d_vals['minn43d_rows'][0]['values']['what'] = 'gig (moved)';
	}
	list( $acf43d_st ) = $call( 'POST', '/wp/v2/posts/' . $acf43d_post, array( 'minn_acf' => $acf43d_vals ) );
	wp_cache_delete( $acf43d_post, 'post_meta' );
	$acf43d_meta = function ( $k ) use ( $acf43d_post ) {
		return (string) get_post_meta( $acf43d_post, $k, true );
	};
	foreach ( array( 'minn43d_ts' => 'a date stored as a timestamp', 'minn43d_str' => 'a date stored as a strtotime string', 'minn43d_dtts' => 'a date-time stored as a timestamp', 'minn43d_t12' => 'a time stored as 12-hour text' ) as $acf43d_n => $acf43d_why ) {
		$check( "ACF panel save: {$acf43d_why} is not cleared by an unrelated edit", 200 === $acf43d_st && $acf43d_seed[ $acf43d_n ] === $acf43d_meta( $acf43d_n ), var_export( $acf43d_meta( $acf43d_n ), true ) );
	}
	$check( 'ACF panel save: a date-time keeps its seconds', '2026-10-31 09:15:30' === $acf43d_meta( 'minn43d_dt' ), $acf43d_meta( 'minn43d_dt' ) );
	$check( 'ACF panel save: a time keeps its seconds', '09:15:30' === $acf43d_meta( 'minn43d_t' ), $acf43d_meta( 'minn43d_t' ) );
	$check( 'ACF panel save: a repeater date sub stored as a timestamp survives a row edit', '1793404800' === $acf43d_meta( 'minn43d_rows_0_when' ), var_export( $acf43d_meta( 'minn43d_rows_0_when' ), true ) );
	$check( 'ACF panel save: a repeater time sub keeps its seconds', '18:30:45' === $acf43d_meta( 'minn43d_rows_0_at' ), $acf43d_meta( 'minn43d_rows_0_at' ) );
	$check( 'CONTROL ACF panel save: the edited fields stored', 'after' === $acf43d_meta( 'minn43d_note' ) && 'gig (moved)' === $acf43d_meta( 'minn43d_rows_0_what' ), $acf43d_meta( 'minn43d_note' ) . ' / ' . $acf43d_meta( 'minn43d_rows_0_what' ) );
	$check( 'CONTROL ACF panel save: an untouched canonical date and time are unchanged', '20261031' === $acf43d_meta( 'minn43d_ok' ) && '18:30:00' === $acf43d_meta( 'minn43d_tok' ), $acf43d_meta( 'minn43d_ok' ) . ' ' . $acf43d_meta( 'minn43d_tok' ) );

	// Real edits still land: a picked date, a typed time, a cleared date, a
	// new date over a timestamp the control could not show, and a new row.
	list( , $acf43d_got2 ) = $call( 'GET', '/wp/v2/posts/' . $acf43d_post, null, array( 'context' => 'edit' ) );
	$acf43d_vals2          = json_decode( wp_json_encode( $acf43d_got2['minn_acf'] ?? array() ), true );
	$acf43d_vals2['minn43d_ok']  = '2026-11-05';
	$acf43d_vals2['minn43d_tok'] = '10:45';
	$acf43d_vals2['minn43d_dt']  = '';
	$acf43d_vals2['minn43d_ts']  = '2027-01-02';
	$acf43d_vals2['minn43d_t']   = '09:20';
	$acf43d_vals2['minn43d_rows'][] = array( 'values' => array( 'when' => '2026-12-24', 'at' => '', 'what' => 'eve' ) );
	$acf43d_vals2['minn43d_str']  = null;               // the client's empty-value sentinel
	$acf43d_vals2['minn43d_dtts'] = array( 'x' => 1 );  // not a date at all
	$call( 'POST', '/wp/v2/posts/' . $acf43d_post, array( 'minn_acf' => $acf43d_vals2 ) );
	wp_cache_delete( $acf43d_post, 'post_meta' );
	$check( 'CONTROL ACF panel save: a picked date and a typed time are stored', '20261105' === $acf43d_meta( 'minn43d_ok' ) && '10:45:00' === $acf43d_meta( 'minn43d_tok' ) && '09:20:00' === $acf43d_meta( 'minn43d_t' ), $acf43d_meta( 'minn43d_ok' ) . ' ' . $acf43d_meta( 'minn43d_tok' ) . ' ' . $acf43d_meta( 'minn43d_t' ) );
	$check( 'CONTROL ACF panel save: clearing a date-time the control showed still clears it', '' === $acf43d_meta( 'minn43d_dt' ), var_export( $acf43d_meta( 'minn43d_dt' ), true ) );
	$check( 'CONTROL ACF panel save: a date picked over a timestamp replaces it', '20270102' === $acf43d_meta( 'minn43d_ts' ), $acf43d_meta( 'minn43d_ts' ) );
	$check( 'ACF panel save: null or a non-date over a value the control could not show keeps it', '2026-10-31 14:00' === $acf43d_meta( 'minn43d_str' ) && '1793404800' === $acf43d_meta( 'minn43d_dtts' ) && '9:15 am' === $acf43d_meta( 'minn43d_t12' ), $acf43d_meta( 'minn43d_str' ) . ' / ' . $acf43d_meta( 'minn43d_dtts' ) );
	$check( 'CONTROL ACF panel save: a new repeater row stores its date', '20261224' === $acf43d_meta( 'minn43d_rows_1_when' ) && '1793404800' === $acf43d_meta( 'minn43d_rows_0_when' ), $acf43d_meta( 'minn43d_rows_1_when' ) . ' / ' . $acf43d_meta( 'minn43d_rows_0_when' ) );
	wp_delete_post( $acf43d_post, true );

	// Options page: a dirty repeater resends every sub of every row.
	acf_add_options_page( array( 'page_title' => 'Minn v043 date probe', 'menu_slug' => 'minn43d-options', 'post_id' => 'options', 'capability' => 'manage_options', 'redirect' => false ) );
	acf_add_local_field_group( array(
		'key'      => 'group_minn43do',
		'title'    => 'Minn v043 date options',
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'minn43d-options' ) ) ),
		'fields'   => array(
			array(
				'key'        => 'field_minn43do_rows',
				'name'       => 'minn43do_rows',
				'label'      => 'Dates',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_minn43do_when', 'name' => 'when', 'label' => 'When', 'type' => 'date_time_picker' ),
					array( 'key' => 'field_minn43do_what', 'name' => 'what', 'label' => 'What', 'type' => 'text' ),
				),
			),
		),
	) );
	update_field( 'field_minn43do_rows', array( array( 'field_minn43do_when' => '1793404800', 'field_minn43do_what' => 'launch' ) ), 'options' );
	list( , $acf43d_tab ) = $call( 'GET', '/minn-admin/v1/acf/options/minn43d-options/tab-0' );
	$acf43d_orows         = json_decode( wp_json_encode( $acf43d_tab['values']['field_minn43do_rows'] ?? array() ), true );
	if ( isset( $acf43d_orows[0]['values'] ) ) {
		$acf43d_orows[0]['values']['what'] = 'launch day';
	}
	$call( 'POST', '/minn-admin/v1/acf/options/minn43d-options/tab-0', array( 'values' => array( 'field_minn43do_rows' => $acf43d_orows ) ) );
	wp_cache_delete( 'alloptions', 'options' );
	$check( 'ACF options save: a repeater date-time stored as a timestamp survives a row edit', '1793404800' === (string) get_option( 'options_minn43do_rows_0_when' ) && 'launch day' === get_option( 'options_minn43do_rows_0_what' ), var_export( get_option( 'options_minn43do_rows_0_when' ), true ) );
	delete_field( 'minn43do_rows', 'options' );
	foreach ( array( 'minn43do_rows_0_when', 'minn43do_rows_0_what' ) as $acf43d_n ) {
		delete_option( 'options_' . $acf43d_n );
		delete_option( '_options_' . $acf43d_n );
	}
	acf_remove_local_field_group( 'group_minn43d' );
	acf_remove_local_field_group( 'group_minn43do' );
} else {
	$skip( 'ACF Pro inactive' );
}

// --- #10 Multisite: ACF user fields resolve only this site's members --------
if ( ! is_multisite() ) {
	$skip( 'ACF user fields across the network: single site' );
} elseif ( ! function_exists( 'minn_admin_acf_relation_entry' ) || ! function_exists( 'minn_admin_acf_relation_id_in' ) ) {
	$skip( 'ACF adapter not loaded' );
} else {
	// The resolver needs no ACF code for a user field, so this runs where ACF
	// is not installed: the field array is the shape acf_get_field() returns.
	$acf43u_blog  = get_current_blog_id();
	$acf43u_field = array( 'type' => 'user', 'role' => '', 'multiple' => 0 );
	$acf43u_sa    = null; // an administrator of THIS site only
	$acf43u_mem   = null; // another member of this site
	foreach ( get_users( array( 'blog_id' => $acf43u_blog ) ) as $acf43u_u ) {
		if ( is_super_admin( $acf43u_u->ID ) ) {
			continue;
		}
		if ( ! $acf43u_sa && in_array( 'administrator', (array) $acf43u_u->roles, true ) ) {
			$acf43u_sa = $acf43u_u;
		} elseif ( ! $acf43u_mem ) {
			$acf43u_mem = $acf43u_u;
		}
	}
	$acf43u_out = null; // an account elsewhere on the network, nothing published here
	foreach ( get_users( array( 'blog_id' => 0, 'number' => 500 ) ) as $acf43u_u ) {
		if ( ! is_super_admin( $acf43u_u->ID ) && ! is_user_member_of_blog( $acf43u_u->ID, $acf43u_blog ) && ! count_user_posts( $acf43u_u->ID ) ) {
			$acf43u_out = $acf43u_u;
			break;
		}
	}
	if ( ! $acf43u_sa || ! $acf43u_mem || ! $acf43u_out ) {
		$skip( 'ACF user fields across the network: this site needs a non-super administrator, another member and a non-member account (run with --url=<subsite>)' );
	} else {
		wp_set_current_user( $acf43u_sa->ID );
		$acf43u_read  = minn_admin_acf_relation_entry( 'user', $acf43u_out->ID );
		$acf43u_write = minn_admin_acf_relation_id_in( $acf43u_field, (string) $acf43u_out->ID );
		$check( 'Multisite ACF user field: a subsite administrator holds list_users (the premise)', current_user_can( 'list_users' ) && ! current_user_can( 'manage_network_users' ) );
		$check( 'Multisite ACF user field: an account from another site does not resolve to a name for a subsite administrator', null === $acf43u_read, wp_json_encode( $acf43u_read ) );
		$check( 'Multisite ACF user field: an account from another site cannot be stored by id', null === $acf43u_write, var_export( $acf43u_write, true ) );
		$acf43u_m = minn_admin_acf_relation_entry( 'user', $acf43u_mem->ID );
		$check( 'CONTROL Multisite ACF user field: a member of this site still resolves and stores', is_array( $acf43u_m ) && (string) $acf43u_mem->ID === $acf43u_m['value'] && (string) $acf43u_mem->ID === minn_admin_acf_relation_id_in( $acf43u_field, (string) $acf43u_mem->ID ) );
		$check( 'CONTROL Multisite ACF user field: your own account resolves', is_array( minn_admin_acf_relation_entry( 'user', $acf43u_sa->ID ) ) );
		// Someone this site credits publicly stays resolvable after they leave
		// it: the attribution rule below list_users.
		$acf43u_pid = wp_insert_post( array( 'post_title' => 'Minn v043 attribution probe', 'post_status' => 'publish', 'post_author' => $acf43u_out->ID ) );
		$check( 'CONTROL Multisite ACF user field: a non-member credited on this site resolves', is_array( minn_admin_acf_relation_entry( 'user', $acf43u_out->ID ) ) );
		wp_delete_post( $acf43u_pid, true );
		wp_set_current_user( $admin );
		$acf43u_super = get_super_admins();
		$acf43u_super = $acf43u_super ? get_user_by( 'login', reset( $acf43u_super ) ) : null;
		if ( $acf43u_super ) {
			wp_set_current_user( $acf43u_super->ID );
			$check( 'CONTROL Multisite ACF user field: a network administrator still resolves any account', is_array( minn_admin_acf_relation_entry( 'user', $acf43u_out->ID ) ) && null !== minn_admin_acf_relation_id_in( $acf43u_field, (string) $acf43u_out->ID ) );
			wp_set_current_user( $admin );
		}
	}
}

// --- #23 sibling: a refused Bricks activation keeps the working key ---------
if ( ! class_exists( '\Bricks\License' ) || ! function_exists( 'minn_admin_license_default_providers' ) ) {
	$skip( '#23 Bricks inactive' );
} else {
	// The real key and status are snapshotted and put back exactly; the key is
	// never printed, and the Bricks licence server never hears from this section.
	$brx_prev   = get_option( 'bricks_license_key', null );
	$brx_status = get_option( '_transient_bricks_license_status', null );
	$brx_tout   = get_option( '_transient_timeout_bricks_license_status', null );
	$brx_answer = null;
	$brx_http   = function ( $pre, $args, $url ) use ( &$brx_answer ) {
		if ( 'my.bricksbuilder.io' === (string) wp_parse_url( $url, PHP_URL_HOST ) && is_callable( $brx_answer ) ) {
			return call_user_func( $brx_answer, $url );
		}
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	$brx_json   = function ( $body, $code = 200 ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $brx_http, PHP_INT_MAX, 3 );
	$brx_paste   = function ( $secret ) use ( $call ) {
		list( $st, $body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'activate', 'secret' => $secret ) );
		return array( $st, is_array( $body ) ? ! empty( $body['ok'] ) : null );
	};
	$brx_working = 'minntestbricksworkingkey';
	$brx_cases   = array(
		// Bricks stores the pasted key for any answer that carries a status
		// and is not tagged as an error: a refused or expired key included.
		'a key the server answers "invalid" for' => function () use ( $brx_json ) {
			return $brx_json( array( 'status' => 'invalid' ) );
		},
		'an expired key'                         => function () use ( $brx_json ) {
			return $brx_json( array( 'status' => 'expired' ) );
		},
		// An outage stores no key, but marks the stored one as unverified.
		'an unreachable licence server'          => function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		},
	);
	foreach ( $brx_cases as $brx_label => $brx_case ) {
		update_option( 'bricks_license_key', $brx_working );
		set_transient( 'bricks_license_status', 'active', HOUR_IN_SECONDS );
		$brx_answer            = $brx_case;
		list( $brx_st, $brx_ok ) = $brx_paste( 'minntestbricksbogus' . wp_generate_password( 6, false, false ) );
		$brx_now               = get_option( 'bricks_license_key', null );
		$brx_stat              = get_transient( 'bricks_license_status' );
		$check( "#23 Bricks: {$brx_label} keeps the working key and its status", 200 === $brx_st && false === $brx_ok && $brx_working === $brx_now && 'active' === $brx_stat, $brx_st . ' ok=' . var_export( $brx_ok, true ) . ' stored=' . ( $brx_working === $brx_now ? 'working' : ( null === $brx_now ? 'absent' : 'the pasted key' ) ) . ' status=' . var_export( $brx_stat, true ) );
	}
	// No key before: a refused paste leaves none.
	delete_option( 'bricks_license_key' );
	delete_transient( 'bricks_license_status' );
	$brx_answer            = $brx_cases['a key the server answers "invalid" for'];
	list( $brx_st, $brx_ok ) = $brx_paste( 'minntestbricksabsent' );
	$check( '#23 Bricks: a refused key on a site with none leaves it absent', false === $brx_ok && null === get_option( 'bricks_license_key', null ) && false === get_transient( 'bricks_license_status' ), var_export( get_transient( 'bricks_license_status' ), true ) );
	// Control: an accepted key is stored (the provider closure the route calls,
	// as the Beaver Builder section does).
	$brx_answer = function () use ( $brx_json ) {
		return $brx_json( array( 'status' => 'active' ) );
	};
	$brx_prov = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	$brx_res  = minn_admin_license_result( call_user_func( $brx_prov['bricks']['activate'], 'minntestbricksgoodkey' ) );
	$check( '#23 Bricks control: an accepted key is stored and reported valid', ! empty( $brx_res['ok'] ) && 'minntestbricksgoodkey' === get_option( 'bricks_license_key', null ), wp_json_encode( $brx_res ) );

	remove_filter( 'pre_http_request', $brx_http, PHP_INT_MAX );
	if ( null === $brx_prev ) {
		delete_option( 'bricks_license_key' );
	} else {
		update_option( 'bricks_license_key', $brx_prev );
	}
	foreach ( array( '_transient_bricks_license_status' => $brx_status, '_transient_timeout_bricks_license_status' => $brx_tout ) as $brx_k => $brx_v ) {
		if ( null === $brx_v ) {
			delete_option( $brx_k );
		} else {
			update_option( $brx_k, $brx_v, false );
		}
	}
	\Bricks\License::$license_key = $brx_prev;
}

// --- #2 Meta Box panel saves keep backslashes --------------------------------
// The editor sends the whole panel back when any field in it changes. Meta Box's
// own form hands rwmb_set_meta()'s pipeline the slashed $_POST, and the storage
// write unslashes once, so an unslashed REST value lost a level of backslashes.
if ( function_exists( 'rwmb_get_registry' ) && function_exists( 'minn_admin_meta_box_write_values' ) ) {
	$fsl_mb_box = rwmb_get_registry( 'meta_box' )->make( array(
		'id'         => 'minn_v043_mb_slash',
		'title'      => 'Minn v043 slash probe',
		'post_types' => array( 'post' ),
		'fields'     => array(
			array( 'id' => 'minn_v043_pattern', 'name' => 'Pattern', 'type' => 'text' ),
			array( 'id' => 'minn_v043_path', 'name' => 'Path', 'type' => 'textarea' ),
			array( 'id' => 'minn_v043_pct', 'name' => 'Promo', 'type' => 'text' ),
			array( 'id' => 'minn_v043_note', 'name' => 'Note', 'type' => 'text' ),
			array( 'id' => 'minn_v043_pick', 'name' => 'Pick', 'type' => 'select', 'options' => array( 'plain' => 'Plain', "it's" => 'Quoted' ) ),
			array( 'id' => 'minn_v043_flag', 'name' => 'Flag', 'type' => 'checkbox' ),
		),
	) );
	// A box made after init never reaches the field registry the setter reads.
	if ( method_exists( $fsl_mb_box, 'register_fields' ) ) {
		$fsl_mb_box->register_fields();
	}
	$fsl_mb_post = wp_insert_post( array( 'post_title' => 'Minn v043 meta box slash probe', 'post_status' => 'draft' ) );
	$fsl_mb_path = "C:\\Temp\\new\n\"quoted\" \\u00e9 \\\\server\\share";
	update_post_meta( $fsl_mb_post, 'minn_v043_pattern', wp_slash( '^\d+$' ) );
	update_post_meta( $fsl_mb_post, 'minn_v043_path', wp_slash( $fsl_mb_path ) );
	update_post_meta( $fsl_mb_post, 'minn_v043_pct', 'Save 50%AB today' );
	// What the editor does: read the panel, edit one field, send it all back.
	list( , $fsl_mb_read ) = $call( 'GET', '/wp/v2/posts/' . $fsl_mb_post, null, array( 'context' => 'edit' ) );
	$fsl_mb_vals                   = (array) ( $fsl_mb_read['minn_meta_box'] ?? array() );
	$fsl_mb_vals['minn_v043_note'] = 'typed C:\\Users\\me and ^\\w+$';
	list( $fsl_mb_st ) = $call( 'POST', '/wp/v2/posts/' . $fsl_mb_post, array( 'minn_meta_box' => $fsl_mb_vals ) );
	$check( 'Meta Box: the panel save answers 200', 200 === $fsl_mb_st, 'status ' . $fsl_mb_st );
	$check( 'Meta Box: an untouched text field keeps its backslashes', '^\d+$' === get_post_meta( $fsl_mb_post, 'minn_v043_pattern', true ), get_post_meta( $fsl_mb_post, 'minn_v043_pattern', true ) );
	$check( 'Meta Box: an untouched textarea keeps its backslashes and quotes', $fsl_mb_path === get_post_meta( $fsl_mb_post, 'minn_v043_path', true ), wp_json_encode( get_post_meta( $fsl_mb_post, 'minn_v043_path', true ) ) );
	$check( 'Meta Box: an untouched text field is not re-sanitized (%AB kept)', 'Save 50%AB today' === get_post_meta( $fsl_mb_post, 'minn_v043_pct', true ), get_post_meta( $fsl_mb_post, 'minn_v043_pct', true ) );
	$check( 'Meta Box: a typed value keeps its backslashes', 'typed C:\\Users\\me and ^\\w+$' === get_post_meta( $fsl_mb_post, 'minn_v043_note', true ), get_post_meta( $fsl_mb_post, 'minn_v043_note', true ) );
	// Controls: changed values of every simple kind still write.
	list( , $fsl_mb_read ) = $call( 'GET', '/wp/v2/posts/' . $fsl_mb_post, null, array( 'context' => 'edit' ) );
	$fsl_mb_vals                      = (array) ( $fsl_mb_read['minn_meta_box'] ?? array() );
	$fsl_mb_vals['minn_v043_pattern'] = '^[a-z]+\\d{2}$';
	$fsl_mb_vals['minn_v043_pick']    = "it's";
	$fsl_mb_vals['minn_v043_flag']    = true;
	$fsl_mb_vals['minn_v043_note']    = '';
	$call( 'POST', '/wp/v2/posts/' . $fsl_mb_post, array( 'minn_meta_box' => $fsl_mb_vals ) );
	$check( 'Meta Box: a changed text field keeps its backslashes', '^[a-z]+\\d{2}$' === get_post_meta( $fsl_mb_post, 'minn_v043_pattern', true ), get_post_meta( $fsl_mb_post, 'minn_v043_pattern', true ) );
	$check( 'Meta Box: a picked choice holding a quote is written (control)', "it's" === get_post_meta( $fsl_mb_post, 'minn_v043_pick', true ), get_post_meta( $fsl_mb_post, 'minn_v043_pick', true ) );
	$check( 'Meta Box: a ticked checkbox is written (control)', '1' === (string) get_post_meta( $fsl_mb_post, 'minn_v043_flag', true ), wp_json_encode( get_post_meta( $fsl_mb_post, 'minn_v043_flag', true ) ) );
	$check( 'Meta Box: a cleared field is deleted (control)', ! metadata_exists( 'post', $fsl_mb_post, 'minn_v043_note' ) );
	$check( 'Meta Box: the untouched textarea survives a second save', $fsl_mb_path === get_post_meta( $fsl_mb_post, 'minn_v043_path', true ) );
	wp_delete_post( $fsl_mb_post, true );
} else {
	$skip( 'Meta Box inactive' );
}

// --- #2 Ninja Forms answer edits leave untouched answers alone (guard) ------
// Minn sends only the answers that changed, and the route writes only those,
// so a backslash in an untouched answer is never round-tripped. A typed answer
// is stored the way Ninja Forms' own submissions screen (its REST update)
// stores it.
if ( function_exists( 'Ninja_Forms' ) && function_exists( 'minn_admin_ninja_forms_edit_block' ) && minn_admin_ninja_forms_can_edit() ) {
	$fsl_nf_form = 0;
	$fsl_nf_text = 0;
	$fsl_nf_area = 0;
	foreach ( (array) Ninja_Forms()->form()->get_forms() as $fsl_nf_f ) {
		$fsl_nf_t = 0;
		$fsl_nf_a = 0;
		foreach ( (array) Ninja_Forms()->form( $fsl_nf_f->get_id() )->get_fields() as $fsl_nf_fl ) {
			$fsl_nf_type = (string) $fsl_nf_fl->get_setting( 'type' );
			if ( 'textbox' === $fsl_nf_type && ! $fsl_nf_t ) {
				$fsl_nf_t = (int) $fsl_nf_fl->get_id();
			} elseif ( 'textarea' === $fsl_nf_type && ! $fsl_nf_a ) {
				$fsl_nf_a = (int) $fsl_nf_fl->get_id();
			}
		}
		if ( $fsl_nf_t && $fsl_nf_a ) {
			$fsl_nf_form = (int) $fsl_nf_f->get_id();
			$fsl_nf_text = $fsl_nf_t;
			$fsl_nf_area = $fsl_nf_a;
			break;
		}
	}
	if ( $fsl_nf_form ) {
		$fsl_nf_sub = Ninja_Forms()->form( $fsl_nf_form )->sub()->get();
		$fsl_nf_sub->update_field_value( $fsl_nf_area, wp_slash( 'Path C:\\Temp\\new and ^\\d+$' ) );
		$fsl_nf_sub->update_field_value( $fsl_nf_text, 'Jordan' );
		$fsl_nf_sub->save();
		$fsl_nf_id = (int) $fsl_nf_sub->get_id();
		list( $fsl_nf_st ) = $call( 'POST', '/minn-admin/v1/ninja-forms/entries/' . $fsl_nf_id . '/answers', array(
			'values'   => array( (string) $fsl_nf_text => 'Jordan Lee' ),
			'original' => array( (string) $fsl_nf_text => 'Jordan' ),
		) );
		$check( 'Ninja Forms: the answer edit answers 200', 200 === $fsl_nf_st, 'status ' . $fsl_nf_st );
		$check( 'Ninja Forms: an untouched answer keeps its backslashes', 'Path C:\\Temp\\new and ^\\d+$' === minn_admin_ninja_forms_decode( get_post_meta( $fsl_nf_id, '_field_' . $fsl_nf_area, true ) ), get_post_meta( $fsl_nf_id, '_field_' . $fsl_nf_area, true ) );
		$check( 'Ninja Forms: the edited answer is written (control)', 'Jordan Lee' === get_post_meta( $fsl_nf_id, '_field_' . $fsl_nf_text, true ) );
		wp_delete_post( $fsl_nf_id, true );
	} else {
		$skip( 'Ninja Forms: no form with a textbox and a paragraph field' );
	}
} else {
	$skip( 'Ninja Forms inactive' );
}

// --- #9 An entry reply below manage_options goes From the sender ------------
// Gravity Forms lets its entry-notes cap email a note From the sender's own
// address (entry_detail.php). Minn's reply used the site's admin address for
// everyone, so that delegated cap became a From-the-domain mail primitive.
if ( class_exists( 'GFAPI' ) && method_exists( 'Minn_Admin_REST', 'entry_reply' ) ) {
	$fsl_gf_form  = 0;
	$fsl_gf_email = 0;
	foreach ( (array) GFAPI::get_forms() as $fsl_gf_f ) {
		foreach ( $fsl_gf_f['fields'] as $fsl_gf_fl ) {
			if ( 'email' === $fsl_gf_fl->type && empty( $fsl_gf_fl->emailConfirmEnabled ) ) {
				$fsl_gf_form  = (int) $fsl_gf_f['id'];
				$fsl_gf_email = (string) $fsl_gf_fl->id;
				break 2;
			}
		}
	}
	if ( $fsl_gf_form ) {
		global $wp_filter;
		$fsl_gf_to    = 'minn-v043-reply@example.com';
		$fsl_gf_entry = GFAPI::add_entry( array( 'form_id' => $fsl_gf_form, $fsl_gf_email => $fsl_gf_to ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( username_exists( 'minn_v043_notes' ) ) {
			wp_delete_user( (int) username_exists( 'minn_v043_notes' ) );
		}
		$fsl_gf_user  = wp_insert_user( array(
			'user_login'   => 'minn_v043_notes',
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'minn-v043-notes@example.com',
			'display_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'role'         => 'editor',
		) );
		$fsl_gf_u = new WP_User( $fsl_gf_user );
		$fsl_gf_u->add_cap( 'gravityforms_view_entries' );
		$fsl_gf_u->add_cap( 'gravityforms_edit_entry_notes' );
		// Capture what reaches wp_mail() without sending it or touching a mail
		// log: the loggers ride the wp_mail filter, so both hooks are parked.
		$fsl_gf_parked = array();
		foreach ( array( 'wp_mail', 'pre_wp_mail' ) as $fsl_gf_hook ) {
			$fsl_gf_parked[ $fsl_gf_hook ] = isset( $wp_filter[ $fsl_gf_hook ] ) ? $wp_filter[ $fsl_gf_hook ] : null;
			unset( $wp_filter[ $fsl_gf_hook ] );
		}
		$fsl_gf_mail = array();
		add_filter( 'pre_wp_mail', function ( $ret, $atts ) use ( &$fsl_gf_mail ) {
			$fsl_gf_mail[] = $atts;
			return true;
		}, 10, 2 );
		$fsl_gf_from = function ( $atts ) {
			foreach ( (array) ( $atts['headers'] ?? array() ) as $h ) {
				if ( 0 === stripos( (string) $h, 'From:' ) ) {
					return (string) $h;
				}
			}
			return '';
		};
		$fsl_gf_body  = array( 'surface' => 'gravity-forms', 'id' => (string) $fsl_gf_entry, 'to' => $fsl_gf_to, 'subject' => 'Re: your message', 'message' => 'Thanks, we got it.' );
		$fsl_gf_admin = (string) get_option( 'admin_email' );

		wp_set_current_user( $fsl_gf_user );
		list( $fsl_gf_st ) = $call( 'POST', '/minn-admin/v1/entries/reply', $fsl_gf_body );
		$fsl_gf_sent       = end( $fsl_gf_mail );
		$fsl_gf_hdrs       = $fsl_gf_sent ? implode( "\n", (array) $fsl_gf_sent['headers'] ) : '';
		$check( 'Entry reply: a delegated notes cap can still reply (200)', 200 === $fsl_gf_st && $fsl_gf_sent, 'status ' . $fsl_gf_st );
		$check( 'Entry reply: below manage_options it goes From the sender', $fsl_gf_sent && false !== stripos( $fsl_gf_from( $fsl_gf_sent ), 'minn-v043-notes@example.com' ), $fsl_gf_from( $fsl_gf_sent ?: array() ) );
		$check( 'Entry reply: below manage_options the site address appears in no header', $fsl_gf_sent && false === stripos( $fsl_gf_hdrs, $fsl_gf_admin ), $fsl_gf_hdrs );
		$check( 'Entry reply: the sender cannot pose as the site by display name', $fsl_gf_sent && false === strpos( $fsl_gf_hdrs, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' <' ), $fsl_gf_hdrs );
		// Hostile: no usable address of their own must not fall back to the site's.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $fsl_gf_user ) );
		clean_user_cache( $fsl_gf_user );
		wp_set_current_user( 0 );
		wp_set_current_user( $fsl_gf_user );
		$fsl_gf_count       = count( $fsl_gf_mail );
		list( $fsl_gf_st2 ) = $call( 'POST', '/minn-admin/v1/entries/reply', $fsl_gf_body );
		$check( 'Entry reply: a delegated sender with no email address sends nothing', $fsl_gf_st2 >= 400 && count( $fsl_gf_mail ) === $fsl_gf_count, 'status ' . $fsl_gf_st2 . ', sent ' . ( count( $fsl_gf_mail ) - $fsl_gf_count ) );

		// Control: an administrator's reply still goes From the site.
		wp_set_current_user( $admin );
		list( $fsl_gf_st3 ) = $call( 'POST', '/minn-admin/v1/entries/reply', $fsl_gf_body );
		$fsl_gf_sent        = end( $fsl_gf_mail );
		$check( 'Entry reply: an administrator still replies From the site address (control)', 200 === $fsl_gf_st3 && false !== stripos( $fsl_gf_from( $fsl_gf_sent ), '<' . $fsl_gf_admin . '>' ), $fsl_gf_from( $fsl_gf_sent ?: array() ) );

		remove_all_filters( 'pre_wp_mail' );
		foreach ( $fsl_gf_parked as $fsl_gf_hook => $fsl_gf_obj ) {
			if ( null !== $fsl_gf_obj ) {
				$wp_filter[ $fsl_gf_hook ] = $fsl_gf_obj;
			}
		}
		GFAPI::delete_entry( $fsl_gf_entry );
		wp_delete_user( $fsl_gf_user );
	} else {
		$skip( 'Gravity Forms: no form with an email field' );
	}
} else {
	$skip( 'Gravity Forms inactive' );
}

// --- #9 sibling: a delegated user manager's email to a user goes From them --
// The Users "Email" action used the same site-address helper. A role given
// list_users and edit_users without manage_options (a user-manager role)
// could make the site mail any member as its admin address.
if ( method_exists( 'Minn_Admin_REST', 'user_send_email' ) ) {
	global $wp_filter;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( 'minn_v043_usermgr', 'minn_v043_member' ) as $usm_login ) {
		if ( username_exists( $usm_login ) ) {
			wp_delete_user( (int) username_exists( $usm_login ) );
		}
	}
	$usm_mgr    = wp_insert_user( array( 'user_login' => 'minn_v043_usermgr', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn-v043-usermgr@example.com', 'role' => 'editor' ) );
	$usm_member = wp_insert_user( array( 'user_login' => 'minn_v043_member', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn-v043-member@example.com', 'role' => 'subscriber' ) );
	$usm_u      = new WP_User( $usm_mgr );
	$usm_u->add_cap( 'list_users' );
	$usm_u->add_cap( 'edit_users' );
	// Capture what reaches wp_mail() without sending it or touching a mail log.
	$usm_parked = array();
	foreach ( array( 'wp_mail', 'pre_wp_mail' ) as $usm_hook ) {
		$usm_parked[ $usm_hook ] = isset( $wp_filter[ $usm_hook ] ) ? $wp_filter[ $usm_hook ] : null;
		unset( $wp_filter[ $usm_hook ] );
	}
	$usm_mail = array();
	add_filter( 'pre_wp_mail', function ( $ret, $atts ) use ( &$usm_mail ) {
		$usm_mail[] = $atts;
		return true;
	}, 10, 2 );
	$usm_from  = function ( $atts ) {
		foreach ( (array) ( $atts['headers'] ?? array() ) as $h ) {
			if ( 0 === stripos( (string) $h, 'From:' ) ) {
				return (string) $h;
			}
		}
		return '';
	};
	$usm_body  = array( 'subject' => 'Account notice', 'message' => 'Please review your profile.' );
	$usm_admin = (string) get_option( 'admin_email' );

	wp_set_current_user( $usm_mgr );
	list( $usm_st ) = $call( 'POST', "/minn-admin/v1/users/{$usm_member}/email", $usm_body );
	$usm_sent       = end( $usm_mail );
	$usm_hdrs       = $usm_sent ? implode( "\n", (array) $usm_sent['headers'] ) : '';
	$check( 'User email: a delegated user manager can still email a member (200)', 200 === $usm_st && $usm_sent, 'status ' . $usm_st );
	$check( 'User email: below manage_options it goes From the sender', $usm_sent && false !== stripos( $usm_from( $usm_sent ), 'minn-v043-usermgr@example.com' ), $usm_from( $usm_sent ?: array() ) );
	$check( 'User email: below manage_options the site address appears in no header', $usm_sent && false === stripos( $usm_hdrs, $usm_admin ), $usm_hdrs );
	// Control: an administrator still emails as the site.
	wp_set_current_user( $admin );
	list( $usm_st2 ) = $call( 'POST', "/minn-admin/v1/users/{$usm_member}/email", $usm_body );
	$usm_sent        = end( $usm_mail );
	$check( 'User email: an administrator still sends From the site address (control)', 200 === $usm_st2 && false !== stripos( $usm_from( $usm_sent ), '<' . $usm_admin . '>' ), $usm_from( $usm_sent ?: array() ) );

	remove_all_filters( 'pre_wp_mail' );
	foreach ( $usm_parked as $usm_hook => $usm_obj ) {
		if ( null !== $usm_obj ) {
			$wp_filter[ $usm_hook ] = $usm_obj;
		}
	}
	wp_delete_user( $usm_mgr );
	wp_delete_user( $usm_member );
}

// --- #25 SEO panel: changed toggles and selects still save (Yoast control) --
// The panel resends every SEO value when any of them changes, and a toggle or
// select equal to what the provider reads back is now skipped like text. Yoast
// refuses to store its own defaults, so its round trip was already exact; this
// proves the skip never swallows a real change, in either direction.
if ( defined( 'WPSEO_VERSION' ) && function_exists( 'minn_admin_seo_plugin' ) ) {
	$fsl_yo_post = wp_insert_post( array( 'post_title' => 'Minn v043 seo toggle probe', 'post_status' => 'draft' ) );
	update_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-nofollow', '1' );
	update_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-adv', 'noarchive' );
	// The client's seeding: a false on a non-true_false field rides back as null.
	$fsl_yo_seed = function () use ( $call, $fsl_yo_post ) {
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $fsl_yo_post, null, array( 'context' => 'edit' ) );
		$vals           = (array) ( $read['minn_seo'] ?? array() );
		foreach ( $vals as $k => $v ) {
			if ( false === $v ) {
				$vals[ $k ] = null;
			}
		}
		return $vals;
	};
	$fsl_yo_vals                = $fsl_yo_seed();
	$fsl_yo_vals['description'] = 'Minn v043 probe description';
	$call( 'POST', '/wp/v2/posts/' . $fsl_yo_post, array( 'minn_seo' => $fsl_yo_vals ) );
	$check( 'SEO (Yoast): the edited description saves', 'Minn v043 probe description' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_metadesc', true ) );
	$check( 'SEO (Yoast): untouched robots settings stay as stored', '1' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-nofollow', true ) && 'noarchive' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-adv', true ) );
	$fsl_yo_vals                     = $fsl_yo_seed();
	$fsl_yo_vals['robots_nofollow']  = false;
	$fsl_yo_vals['robots_noarchive'] = null; // switched off: what the toggle sends
	$fsl_yo_vals['robots_nosnippet'] = true;
	$fsl_yo_vals['robots_index']     = 'noindex';
	$call( 'POST', '/wp/v2/posts/' . $fsl_yo_post, array( 'minn_seo' => $fsl_yo_vals ) );
	$check( 'SEO (Yoast): a toggle switched off is written (control)', ! metadata_exists( 'post', $fsl_yo_post, '_yoast_wpseo_meta-robots-nofollow' ) );
	$check( 'SEO (Yoast): toggles switched on and off in one save are both written (control)', 'nosnippet' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-adv', true ), wp_json_encode( get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-adv', true ) ) );
	$check( 'SEO (Yoast): a select changed is written (control)', '1' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-noindex', true ) );
	$fsl_yo_vals                    = $fsl_yo_seed();
	$fsl_yo_vals['robots_nofollow'] = true;
	$fsl_yo_vals['robots_index']    = '';
	$call( 'POST', '/wp/v2/posts/' . $fsl_yo_post, array( 'minn_seo' => $fsl_yo_vals ) );
	$check( 'SEO (Yoast): a toggle switched on is written (control)', '1' === get_post_meta( $fsl_yo_post, '_yoast_wpseo_meta-robots-nofollow', true ) );
	$check( 'SEO (Yoast): a select set back to default is written (control)', ! metadata_exists( 'post', $fsl_yo_post, '_yoast_wpseo_meta-robots-noindex' ) );
	wp_delete_post( $fsl_yo_post, true );
} else {
	$skip( 'Yoast inactive' );
}

// --- #25 SEO unchanged-value rule, by field type (every provider) ----------
if ( function_exists( 'minn_admin_seo_unchanged' ) ) {
	$fsl_uc = array(
		// type, submitted, read back, expected "unchanged"
		array( 'toggle', null, false, true ),    // the editor seeds an untouched false as null
		array( 'toggle', false, true, false ),
		array( 'toggle', true, false, false ),
		array( 'toggle', false, 'no', false ),   // a non-bool read is never folded
		array( 'number', -1, null, false ),
		array( 'number', null, '', true ),
		array( 'number', '5', 5, true ),
		array( 'number', 'abc', '', false ),
		array( 'select', null, '', true ),
		array( 'select', 'index', '', false ),
		array( 'text', 'a%ABb', 'a%ABb', true ),
		array( 'text', array( 'a' ), 'a', false ),
		array( 'image', 5, 5, false ),           // images compare by id in their own branch
		array( 'note', 'x', 'x', false ),
	);
	$fsl_uc_bad = array();
	foreach ( $fsl_uc as $fsl_uc_row ) {
		if ( minn_admin_seo_unchanged( $fsl_uc_row[0], $fsl_uc_row[1], $fsl_uc_row[2] ) !== $fsl_uc_row[3] ) {
			$fsl_uc_bad[] = wp_json_encode( $fsl_uc_row );
		}
	}
	$check( 'SEO: the unchanged-value rule compares each type the way it writes', ! $fsl_uc_bad, implode( ' ', $fsl_uc_bad ) );
} else {
	$check( 'SEO: the unchanged-value rule exists for every scalar type', false, 'minn_admin_seo_unchanged() missing' );
}

// --- #25 SEO panel keeps SureRank's explicit per-post robots 'no' -----------
// SureRank stores an unticked robots box as 'no', which puts the post in
// per-post mode (any non-empty value), so a page on a noindexed post type
// stays indexed. Minn reads 'no' as off and its toggle write deleted it,
// handing the page back to the site-wide noindex rule. SureRank sits behind
// Yoast in detection order, so this SKIPs wherever Yoast is also active.
if ( function_exists( 'minn_admin_seo_plugin' ) && 'SureRank' === ( minn_admin_seo_plugin()['name'] ?? '' ) ) {
	$fsl_sr_post = wp_insert_post( array( 'post_title' => 'Minn v043 surerank robots probe', 'post_status' => 'draft' ) );
	update_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', 'no' );
	update_post_meta( $fsl_sr_post, 'surerank_settings_post_no_follow', 'no' );
	$fsl_sr_seed = function () use ( $call, $fsl_sr_post ) {
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $fsl_sr_post, null, array( 'context' => 'edit' ) );
		$vals           = (array) ( $read['minn_seo'] ?? array() );
		foreach ( $vals as $k => $v ) {
			if ( false === $v ) {
				$vals[ $k ] = null;
			}
		}
		return $vals;
	};
	$fsl_sr_vals                = $fsl_sr_seed();
	$fsl_sr_vals['description'] = 'Minn v043 probe description';
	list( $fsl_sr_st ) = $call( 'POST', '/wp/v2/posts/' . $fsl_sr_post, array( 'minn_seo' => $fsl_sr_vals ) );
	$fsl_sr_flat = \SureRank\Inc\Functions\Get::all_post_meta( $fsl_sr_post );
	$check( 'SEO (SureRank): the edited description saves', 200 === $fsl_sr_st && 'Minn v043 probe description' === ( $fsl_sr_flat['page_description'] ?? '' ), 'status ' . $fsl_sr_st );
	$check( 'SEO (SureRank): an untouched explicit index ("no") survives', 'no' === get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ), wp_json_encode( get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ) ) );
	$check( 'SEO (SureRank): an untouched explicit follow ("no") survives', 'no' === get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_follow', true ), wp_json_encode( get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_follow', true ) ) );
	// The provider's own write keeps an explicit 'no' when asked for "off".
	$fsl_sr_prov = minn_admin_seo_plugin();
	call_user_func( $fsl_sr_prov['write'], $fsl_sr_post, 'robots_noindex', false );
	$check( 'SEO (SureRank): writing off over a stored "no" keeps it', 'no' === get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ), wp_json_encode( get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ) ) );
	// Control: a toggle switched on is written.
	$fsl_sr_vals                   = $fsl_sr_seed();
	$fsl_sr_vals['robots_noindex'] = true;
	$call( 'POST', '/wp/v2/posts/' . $fsl_sr_post, array( 'minn_seo' => $fsl_sr_vals ) );
	$check( 'SEO (SureRank): a toggle switched on is written (control)', 'yes' === get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ), wp_json_encode( get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_index', true ) ) );
	$check( 'SEO (SureRank): its untouched sibling still survives', 'no' === get_post_meta( $fsl_sr_post, 'surerank_settings_post_no_follow', true ) );
	wp_delete_post( $fsl_sr_post, true );
} else {
	$skip( 'SureRank is not the active SEO provider' );
}

// @sections

$summary();
