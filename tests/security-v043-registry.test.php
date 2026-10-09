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

// @sections

$summary();
