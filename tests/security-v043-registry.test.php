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

// ACPT's WPAttachment.php calls set_time_limit( 5 ) at file scope, so once a
// section autoloads it every later section would have five seconds left.
// Each check and skip lifts the limit again.
$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
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
// could make the site mail any member as its admin address. On a network
// core lets only super admins edit other accounts, so there is no such role.
if ( is_multisite() ) {
	$skip( 'Delegated user email: a network has no below-administrator user manager' );
} elseif ( method_exists( 'Minn_Admin_REST', 'user_send_email' ) ) {
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

// --- #6 GF answer edits recompute calculations, the product cache and entry meta ---
// Gravity Forms' own entry edit re-saves every calculation from the edited
// answers, rebuilds a cached product summary and re-runs add-on entry meta
// (quiz/survey scores). An answer edit in Minn must leave the same record.
if ( class_exists( 'GFAPI' ) && function_exists( 'minn_admin_gf_entry_edit_block' ) ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	$gfcalc_fid  = GFAPI::add_form( array(
		'title'  => 'Minn v043 calc ' . time(),
		'fields' => array(
			array( 'id' => 1, 'type' => 'number', 'label' => 'Quantity' ),
			array( 'id' => 2, 'type' => 'number', 'label' => 'Total', 'enableCalculation' => true, 'calculationFormula' => '{Quantity:1} * 10' ),
			// A calculation reading another calculation, and an input with a modifier.
			array( 'id' => 4, 'type' => 'number', 'label' => 'Grand', 'enableCalculation' => true, 'calculationFormula' => '{Total:2} + {Quantity:1:value}' ),
			array( 'id' => 3, 'type' => 'text', 'label' => 'Note' ),
			array( 'id' => 5, 'type' => 'product', 'inputType' => 'singleproduct', 'label' => 'Widget', 'basePrice' => '$10.00', 'inputs' => array( array( 'id' => '5.1', 'label' => 'Name' ), array( 'id' => '5.2', 'label' => 'Price' ), array( 'id' => '5.3', 'label' => 'Quantity' ) ) ),
		),
	) );
	// An add-on score kept as entry meta (how the quiz/survey/poll add-ons register theirs).
	$gfcalc_meta = function ( $meta, $form_id ) use ( $gfcalc_fid ) {
		if ( (int) $form_id === (int) $gfcalc_fid ) {
			$meta['minn_gfcalc_score'] = array(
				'label'                      => 'Score',
				'is_numeric'                 => true,
				'update_entry_meta_callback' => function ( $key, $entry, $form ) {
					return (int) rgar( $entry, '1' ) * 2;
				},
			);
		}
		return $meta;
	};
	add_filter( 'gform_entry_meta', $gfcalc_meta, 10, 2 );
	$gfcalc_eid = GFAPI::add_entry( array( 'form_id' => $gfcalc_fid, '1' => '3', '2' => '30', '4' => '33', '3' => 'first', '5.1' => 'Widget', '5.2' => '$10.00', '5.3' => '1' ) );
	gform_update_meta( $gfcalc_eid, 'minn_gfcalc_score', 6 );
	gform_update_meta( $gfcalc_eid, 'gform_product_info__', array( 'products' => array( 'minn_stale' => array( 'name' => 'minn_stale', 'price' => '$1.00', 'quantity' => 1 ) ), 'shipping' => array() ) );
	$gfcalc_login = 'minn-v043-gfcalc-' . wp_rand();
	$gfcalc_user  = wp_insert_user( array( 'user_login' => $gfcalc_login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $gfcalc_login . '@example.com', 'role' => 'subscriber' ) );
	$gfcalc_u     = new WP_User( $gfcalc_user );
	$gfcalc_u->add_cap( 'gravityforms_view_entries' );
	$gfcalc_u->add_cap( 'gravityforms_edit_entries' );
	$gfcalc_read = function () use ( $gfcalc_eid ) {
		$e = GFAPI::get_entry( $gfcalc_eid );
		return array( (string) rgar( $e, '1' ), (string) rgar( $e, '2' ), (string) rgar( $e, '4' ), (string) gform_get_meta( $gfcalc_eid, 'minn_gfcalc_score' ) );
	};

	wp_set_current_user( $gfcalc_user );
	// What the entry page sends: the changed inputs and their loaded values.
	list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$gfcalc_eid}/answers", array( 'values' => array( '1' => '5' ), 'original' => array( '1' => '3' ) ) );
	$gfcalc_now = $gfcalc_read();
	$check( 'GF answer edit: 200 for an edit-entries user', 200 === $st, "status {$st}" );
	$check( 'GF answer edit: Quantity 3→5 recomputes Total to 50', '50' === $gfcalc_now[1], wp_json_encode( $gfcalc_now ) );
	$check( 'GF answer edit: a calculation reading a calculation follows (Grand 55)', '55' === $gfcalc_now[2], wp_json_encode( $gfcalc_now ) );
	$check( 'GF answer edit: add-on entry meta re-runs (score 10)', '10' === $gfcalc_now[3], wp_json_encode( $gfcalc_now ) );
	$gfcalc_pc = gform_get_meta( $gfcalc_eid, 'gform_product_info__' );
	$check( 'GF answer edit: the cached product summary is rebuilt from the entry', false === strpos( (string) wp_json_encode( $gfcalc_pc ), 'minn_stale' ) && 'Widget' === ( $gfcalc_pc['products'][5]['name'] ?? '' ), wp_json_encode( $gfcalc_pc ) );

	// Clearing the input: GF's calculation reads it as zero.
	list( $st ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$gfcalc_eid}/answers", array( 'values' => array( '1' => '' ), 'original' => array( '1' => '5' ) ) );
	$gfcalc_now = $gfcalc_read();
	$check( 'GF answer edit: an emptied input recomputes to 0', 200 === $st && '0' === $gfcalc_now[1] && '0' === $gfcalc_now[2], "status {$st} " . wp_json_encode( $gfcalc_now ) );

	// Controls: the calculation itself stays uneditable, an unreferenced
	// answer still saves, and an unchanged save writes nothing.
	$gfcalc_was     = $gfcalc_read()[1];
	list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$gfcalc_eid}/answers", array( 'values' => array( '2' => '999' ), 'original' => array( '2' => $gfcalc_was ) ) );
	$check( 'GF answer edit: a calculated answer is still refused (control)', 400 === $st && $gfcalc_was === $gfcalc_read()[1], "status {$st}" );
	list( $st ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$gfcalc_eid}/answers", array( 'values' => array( '3' => 'second' ), 'original' => array( '3' => 'first' ) ) );
	$check( 'GF answer edit: an answer no calculation reads still saves (control)', 200 === $st && 'second' === (string) rgar( GFAPI::get_entry( $gfcalc_eid ), '3' ), "status {$st}" );
	list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$gfcalc_eid}/answers", array( 'values' => array( '3' => 'second' ), 'original' => array( '3' => 'second' ) ) );
	$check( 'GF answer edit: an unchanged save changes nothing (control)', 200 === $st && array() === ( $d['changed'] ?? null ), "status {$st}" );

	wp_set_current_user( $admin );
	remove_filter( 'gform_entry_meta', $gfcalc_meta, 10 );
	unset( $GLOBALS['_entry_meta'][ $gfcalc_fid ] );
	GFAPI::delete_form( $gfcalc_fid );
	wp_delete_user( $gfcalc_user );
} else {
	$skip( 'GF answer edit recompute: Gravity Forms inactive' );
}

// --- #7 GF notification and confirmation rules keep Gravity Forms' operators ---
// The pages send the whole notification back on every save, so a rename
// must not turn a rule's >=, in, like (or an add-on's operator) into "is".
if ( class_exists( 'GFAPI' ) && function_exists( 'minn_admin_gfn_build' ) && function_exists( 'minn_admin_gfc_build' ) ) {
	// An add-on's operator, validated through Gravity Forms' own filter.
	$gfops_valid = function ( $is_valid, $operator ) {
		return 'minn_between' === $operator ? true : $is_valid;
	};
	add_filter( 'gform_is_valid_conditional_logic_operator', $gfops_valid, 10, 2 );
	// 'minn_gone' stands for an operator whose add-on is not loaded here.
	$gfops_ops   = array( '>=', '<=', '<>', 'in', 'not in', 'like', 'minn_between', 'minn_gone' );
	$gfops_rules = function ( $with_email ) use ( $gfops_ops ) {
		$out = array();
		foreach ( $gfops_ops as $i => $op ) {
			$r = array( 'fieldId' => '1', 'operator' => $op, 'value' => (string) ( 10 + $i ) );
			$out[] = $with_email ? array( 'email' => "minn-route{$i}@example.com" ) + $r : $r;
		}
		return $out;
	};
	$gfops_logic = array( 'actionType' => 'show', 'logicType' => 'any', 'rules' => $gfops_rules( false ) );
	$gfops_fid   = GFAPI::add_form( array(
		'title'         => 'Minn v043 ops ' . time(),
		'fields'        => array( array( 'id' => 1, 'type' => 'number', 'label' => 'Age' ), array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ) ),
		'notifications' => array(
			'mgfops1' => array( 'id' => 'mgfops1', 'name' => 'Routed', 'event' => 'form_submission', 'toType' => 'routing', 'to' => '', 'routing' => $gfops_rules( true ), 'subject' => 'New', 'message' => '{all_fields}', 'isActive' => true, 'conditionalLogic' => $gfops_logic ),
		),
		'confirmations' => array(
			'mgfopsd' => array( 'id' => 'mgfopsd', 'name' => 'Default Confirmation', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks' ),
			'mgfopsc' => array( 'id' => 'mgfopsc', 'name' => 'Grown-ups', 'isDefault' => false, 'type' => 'message', 'message' => 'Hello', 'conditionalLogic' => $gfops_logic ),
		),
	) );
	$gfops_got = function ( $rules ) {
		return array_map( function ( $r ) {
			return (string) rgar( $r, 'operator' );
		}, (array) $rules );
	};

	// The notification page: load, rename, save what the client holds.
	list( , $d ) = $call( 'GET', "/minn-admin/v1/gf/notifications/{$gfops_fid}:mgfops1/full" );
	$gfops_n         = $d['notification'];
	$gfops_n['name'] = 'Routed (renamed)';
	list( $st )      = $call( 'POST', "/minn-admin/v1/gf/notifications/{$gfops_fid}:mgfops1/full", $gfops_n );
	$gfops_saved     = GFAPI::get_form( $gfops_fid )['notifications']['mgfops1'];
	$check( 'GF notification rename: every routing operator kept', 200 === $st && $gfops_ops === $gfops_got( $gfops_saved['routing'] ), "status {$st} " . wp_json_encode( $gfops_got( $gfops_saved['routing'] ) ) );
	$check( 'GF notification rename: every condition operator kept', $gfops_ops === $gfops_got( rgars( $gfops_saved, 'conditionalLogic/rules' ) ), wp_json_encode( $gfops_got( rgars( $gfops_saved, 'conditionalLogic/rules' ) ) ) );
	$check( 'GF notification rename: the rename itself saved (control)', 'Routed (renamed)' === $gfops_saved['name'] );

	// Changing one rule to one of the page's operators still saves (control);
	// a new operator Gravity Forms does not know becomes "is", as in their save.
	$gfops_n['routing'][0]['operator']                 = 'isnot';
	$gfops_n['conditionalLogic']['rules'][1]['operator'] = 'minn_unheard';
	list( $st )  = $call( 'POST', "/minn-admin/v1/gf/notifications/{$gfops_fid}:mgfops1/full", $gfops_n );
	$gfops_saved = GFAPI::get_form( $gfops_fid )['notifications']['mgfops1'];
	$check( 'GF notification: a changed rule operator saves (control)', 200 === $st && 'isnot' === $gfops_saved['routing'][0]['operator'], "status {$st}" );
	$check( 'GF notification: a new unknown operator becomes "is" as in Gravity Forms\' own save', 'is' === $gfops_saved['conditionalLogic']['rules'][1]['operator'], (string) $gfops_saved['conditionalLogic']['rules'][1]['operator'] );
	// Hostile shapes: an operator that is not a string, and their check's case folding.
	$gfops_n['conditionalLogic']['rules'][2]['operator'] = array( 'in' );
	$gfops_n['routing'][3]['operator']                 = 'IN';
	list( $st )  = $call( 'POST', "/minn-admin/v1/gf/notifications/{$gfops_fid}:mgfops1/full", $gfops_n );
	$gfops_saved = GFAPI::get_form( $gfops_fid )['notifications']['mgfops1'];
	$check( 'GF notification: a non-string operator saves as "is" without an error', 200 === $st && 'is' === $gfops_saved['conditionalLogic']['rules'][2]['operator'], "status {$st}" );
	$check( 'GF notification: an operator their check accepts in any case is kept as sent', 'IN' === $gfops_saved['routing'][3]['operator'], (string) $gfops_saved['routing'][3]['operator'] );

	// The confirmation page: same round trip.
	list( , $d ) = $call( 'GET', "/minn-admin/v1/gf/confirmations/{$gfops_fid}:mgfopsc/full" );
	$gfops_c         = $d['confirmation'];
	$gfops_c['name'] = 'Grown-ups (renamed)';
	list( $st )      = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$gfops_fid}:mgfopsc/full", $gfops_c );
	$gfops_csaved    = GFAPI::get_form( $gfops_fid )['confirmations']['mgfopsc'];
	$check( 'GF confirmation rename: every condition operator kept', 200 === $st && $gfops_ops === $gfops_got( rgars( $gfops_csaved, 'conditionalLogic/rules' ) ), "status {$st} " . wp_json_encode( $gfops_got( rgars( $gfops_csaved, 'conditionalLogic/rules' ) ) ) );
	$check( 'GF confirmation rename: the rename itself saved (control)', 'Grown-ups (renamed)' === $gfops_csaved['name'] );

	remove_filter( 'gform_is_valid_conditional_logic_operator', $gfops_valid, 10 );
	GFAPI::delete_form( $gfops_fid );
} else {
	$skip( 'GF rule operators: Gravity Forms inactive' );
}

// --- #22 GF trash and restore need Gravity Forms' delete-entries capability ---
// Their entries screen and ajax move entries in or out of Trash only for
// gravityforms_delete_entries; spam, read and star are edit-entries work.
if ( class_exists( 'GFAPI' ) ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	$gftr_fid  = GFAPI::add_form( array( 'title' => 'Minn v043 trash ' . time(), 'fields' => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ) ) ) );
	$gftr_live = GFAPI::add_entry( array( 'form_id' => $gftr_fid, '1' => 'Live' ) );
	$gftr_bin  = GFAPI::add_entry( array( 'form_id' => $gftr_fid, '1' => 'Binned', 'status' => 'trash' ) );
	$gftr_mk   = function ( $caps ) {
		$login = 'minn-v043-gftr-' . wp_rand();
		$id    = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => 'subscriber' ) );
		$u     = new WP_User( $id );
		foreach ( $caps as $cap ) {
			$u->add_cap( $cap );
		}
		return $id;
	};
	$gftr_edit = $gftr_mk( array( 'gravityforms_view_entries', 'gravityforms_edit_entries' ) );
	$gftr_del  = $gftr_mk( array( 'gravityforms_view_entries', 'gravityforms_edit_entries', 'gravityforms_delete_entries' ) );
	$gftr_put  = function ( $id, $body ) use ( $call ) {
		return $call( 'PUT', "/minn-admin/v1/gf/entries/{$id}/properties", $body );
	};
	$gftr_get  = function ( $id, $key = 'status' ) {
		return (string) rgar( GFAPI::get_entry( $id ), $key );
	};

	wp_set_current_user( $gftr_edit );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'trash' ) );
	$check( 'GF properties: an edit-only user cannot trash an entry', 403 === $st && 'active' === $gftr_get( $gftr_live ), "status {$st}, now " . $gftr_get( $gftr_live ) );
	list( $st ) = $gftr_put( $gftr_bin, array( 'status' => 'active' ) );
	$check( 'GF properties: an edit-only user cannot restore a trashed entry', 403 === $st && 'trash' === $gftr_get( $gftr_bin ), "status {$st}, now " . $gftr_get( $gftr_bin ) );
	list( $st ) = $gftr_put( $gftr_bin, array( 'status' => 'spam' ) );
	$check( 'GF properties: nor move it out of Trash by way of spam', 403 === $st && 'trash' === $gftr_get( $gftr_bin ), "status {$st}, now " . $gftr_get( $gftr_bin ) );
	list( $st ) = $gftr_put( $gftr_bin, array( 'status' => null ) );
	$check( 'GF properties: an empty status on a trashed entry is refused too', 403 === $st && 'trash' === $gftr_get( $gftr_bin ), "status {$st}, now " . $gftr_get( $gftr_bin ) );
	list( $st ) = $gftr_put( $gftr_live, array( 'is_read' => 1, 'status' => 'trash' ) );
	$check( 'GF properties: a refused trash writes none of the request', 403 === $st && '0' === $gftr_get( $gftr_live, 'is_read' ) && 'active' === $gftr_get( $gftr_live ), "status {$st}" );
	$gftr_was   = $gftr_get( $gftr_live );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'Trash' ) );
	$check( 'GF properties: a status in another case is refused', 400 === $st && $gftr_was === $gftr_get( $gftr_live ), "status {$st}" );
	// Controls: what Gravity Forms gives edit-entries.
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'spam' ) );
	$check( 'GF properties: an edit-only user still marks spam (control)', 200 === $st && 'spam' === $gftr_get( $gftr_live ), "status {$st}" );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'active' ) );
	$check( 'GF properties: an edit-only user still marks not spam (control)', 200 === $st && 'active' === $gftr_get( $gftr_live ), "status {$st}" );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'active' ) );
	$check( 'GF properties: active on an active entry is not a restore (control)', 200 === $st && 'active' === $gftr_get( $gftr_live ), "status {$st}" );
	list( $st ) = $gftr_put( $gftr_bin, array( 'is_read' => 1, 'is_starred' => 1 ) );
	$check( 'GF properties: an edit-only user still marks read and starred (control)', 200 === $st && '1' === $gftr_get( $gftr_bin, 'is_read' ) && '1' === $gftr_get( $gftr_bin, 'is_starred' ), "status {$st}" );

	wp_set_current_user( $gftr_del );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'trash' ) );
	$check( 'GF properties: a delete-entries user still trashes (control)', 200 === $st && 'trash' === $gftr_get( $gftr_live ), "status {$st}" );
	list( $st ) = $gftr_put( $gftr_live, array( 'status' => 'active' ) );
	$check( 'GF properties: a delete-entries user still restores (control)', 200 === $st && 'active' === $gftr_get( $gftr_live ), "status {$st}" );

	wp_set_current_user( $admin );
	list( $st ) = $gftr_put( $gftr_bin, array( 'status' => 'active' ) );
	$check( 'GF properties: an administrator still restores (control)', 200 === $st && 'active' === $gftr_get( $gftr_bin ), "status {$st}" );

	GFAPI::delete_form( $gftr_fid );
	wp_delete_user( $gftr_edit );
	wp_delete_user( $gftr_del );
} else {
	$skip( 'GF trash capability: Gravity Forms inactive' );
}

// --- #8 Fluent Forms keeps a routing notification routed, with Pro or without ---
// Fluent Forms Pro is detected by its FLUENTFORMPRO constant. A constant
// cannot be undefined, so the with-Pro half runs in a child WP-CLI process
// that defines it, and nothing leaks into the rest of this run.
if ( function_exists( 'wpFluent' ) && function_exists( 'minn_admin_fluent_has_pro' ) && class_exists( 'WP_CLI' ) ) {
	global $wpdb;
	$ffr_p     = $wpdb->prefix;
	$ffr_src   = $wpdb->get_var( "SELECT form_fields FROM {$ffr_p}fluentform_forms ORDER BY id ASC LIMIT 1" );
	$wpdb->insert( "{$ffr_p}fluentform_forms", array( 'title' => 'Minn v043 Fluent routing', 'status' => 'published', 'type' => 'form', 'form_fields' => (string) $ffr_src, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$ffr_form  = (int) $wpdb->insert_id;
	$ffr_route = array(
		array( 'field' => 'names', 'operator' => '=', 'value' => 'Sales', 'input_value' => 'minn-sales@example.com' ),
		array( 'field' => 'names', 'operator' => '=', 'value' => 'Support', 'input_value' => 'minn-support@example.com' ),
	);
	$ffr_seed  = array( 'name' => 'Routed email', 'sendTo' => array( 'type' => 'routing', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => $ffr_route ), 'fromName' => '', 'fromEmail' => '', 'replyTo' => '', 'bcc' => '', 'subject' => 'New', 'message' => '<p>{all_data}</p>', 'enabled' => true );
	$wpdb->insert( "{$ffr_p}fluentform_form_meta", array( 'form_id' => $ffr_form, 'meta_key' => 'notifications', 'value' => wp_json_encode( $ffr_seed ) ) );
	$ffr_meta  = (int) $wpdb->insert_id;
	$ffr_row   = function () use ( $wpdb, $ffr_p, $ffr_meta ) {
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$ffr_p}fluentform_form_meta WHERE id=%d", $ffr_meta ) ), true );
	};
	// What the page sends on a subject edit: the loaded notification, changed.
	$ffr_body  = function ( $d, $subject ) {
		$x            = $d['notifications'][0];
		$x['subject'] = $subject;
		return array( 'notifications' => array( $x ) );
	};

	list( , $d ) = $call( 'GET', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails" );
	list( $st )  = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails", $ffr_body( $d, 'Edited' ) );
	$ffr_now     = $ffr_row();
	$check( 'Fluent emails (this site\'s edition): a subject edit keeps the routing', 200 === $st && 'routing' === $ffr_now['sendTo']['type'] && $ffr_route === $ffr_now['sendTo']['routing'] && 'Edited' === $ffr_now['subject'], "status {$st} " . wp_json_encode( $ffr_now['sendTo'] ) );

	if ( defined( 'FLUENTFORMPRO' ) ) {
		list( , $d ) = $call( 'GET', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails" );
		list( $st )  = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails", $ffr_body( $d, 'Edited with Pro' ) );
		$ffr_pro     = array( 'status' => $st, 'pro' => minn_admin_fluent_has_pro() );
	} else {
		$ffr_tmp = wp_tempnam( 'minn-v043-ffr' );
		file_put_contents( $ffr_tmp, '<?php
define( "FLUENTFORMPRO", "minn-test" );
$q = new WP_REST_Request( "GET", "/minn-admin/v1/fluent-forms/forms/' . $ffr_form . '/emails" );
$d = rest_do_request( $q )->get_data();
$x = $d["notifications"][0];
$x["subject"] = "Edited with Pro";
$q = new WP_REST_Request( "POST", "/minn-admin/v1/fluent-forms/forms/' . $ffr_form . '/emails" );
$q->set_header( "content-type", "application/json" );
$q->set_body( wp_json_encode( array( "notifications" => array( $x ) ) ) );
echo "\nMINNJSON" . wp_json_encode( array( "status" => rest_do_request( $q )->get_status(), "pro" => minn_admin_fluent_has_pro() ) );
' );
		$ffr_out = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $ffr_tmp ) . ' --user=' . (int) get_current_user_id(), array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) );
		unlink( $ffr_tmp );
		$ffr_pro = json_decode( (string) substr( (string) strrchr( (string) $ffr_out->stdout, 'MINNJSON' ), 8 ), true );
	}
	$ffr_now = $ffr_row();
	$check( 'Fluent emails with Pro: the child run saw Pro', ! empty( $ffr_pro['pro'] ), wp_json_encode( $ffr_pro ) );
	$check( 'Fluent emails with Pro: a subject edit keeps the routing', 200 === (int) ( $ffr_pro['status'] ?? 0 ) && 'routing' === $ffr_now['sendTo']['type'] && $ffr_route === $ffr_now['sendTo']['routing'] && 'Edited with Pro' === $ffr_now['subject'], wp_json_encode( $ffr_pro ) . ' ' . wp_json_encode( $ffr_now['sendTo'] ) );

	// Control: an email notification still switches to a form field and back.
	$wpdb->update( "{$ffr_p}fluentform_form_meta", array( 'value' => wp_json_encode( array_merge( $ffr_seed, array( 'sendTo' => array( 'type' => 'email', 'email' => 'minn-owner@example.com', 'field' => '', 'routing' => array() ) ) ) ) ), array( 'id' => $ffr_meta ) );
	list( , $d ) = $call( 'GET', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails" );
	$ffr_x            = $d['notifications'][0];
	$ffr_x['toEmail'] = 'minn-other@example.com';
	list( $st )       = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ffr_form}/emails", array( 'notifications' => array( $ffr_x ) ) );
	$ffr_now          = $ffr_row();
	$check( 'Fluent emails: an email notification still takes a new address (control)', 200 === $st && 'email' === $ffr_now['sendTo']['type'] && 'minn-other@example.com' === $ffr_now['sendTo']['email'], "status {$st} " . wp_json_encode( $ffr_now['sendTo'] ) );

	$wpdb->delete( "{$ffr_p}fluentform_form_meta", array( 'form_id' => $ffr_form ) );
	$wpdb->delete( "{$ffr_p}fluentform_forms", array( 'id' => $ffr_form ) );
} else {
	$skip( 'Fluent routing: Fluent Forms inactive' );
}

// JetEngine sections share three helpers: the surface form's client model, a
// way to reshape the fixture Content Type's fields in memory (its table keeps
// its columns; only JetEngine's in-request field list changes), and a probe
// that watches what their item handler is asked to store.
$jet_cct = ( function_exists( 'minn_admin_jet_cct_type' ) && function_exists( 'minn_admin_jet_cct_active' ) && minn_admin_jet_cct_active() )
	? minn_admin_jet_cct_type( 'minn_contact' ) : null;
// What the CCT create/edit form posts for one descriptor field, given the
// value the item read showed (app.js formNormField + comboUpgrade +
// bindFormComboboxes seeding + formControlValue).
$jet_form_value = function ( $sf, $v ) {
	$type = (string) $sf['type'];
	if ( 'radio' === $type || 'select' === $type ) {
		$opts = array();
		foreach ( (array) ( $sf['options'] ?? array() ) as $o ) {
			$opts[] = (string) $o[0];
		}
		if ( ! empty( $sf['clearable'] ) && ! in_array( '', $opts, true ) ) {
			array_unshift( $opts, '' );
		}
		$seed = is_scalar( $v ) ? (string) $v : '';
		if ( '' !== $seed && ! in_array( $seed, $opts, true ) ) {
			$opts[] = $seed; // withStoredOption
		}
		return in_array( $seed, $opts, true ) ? $seed : $opts[0];
	}
	if ( 'number' === $type ) {
		return ( is_scalar( $v ) && is_numeric( $v ) ) ? (float) $v : null; // a number input drops what it cannot show
	}
	if ( 'image' === $type ) {
		return ( is_array( $v ) && ! empty( $v['id'] ) ) ? array( 'id' => (int) $v['id'], 'url' => (string) ( $v['url'] ?? '' ) ) : null;
	}
	if ( 'gallery' === $type ) {
		return is_array( $v ) ? array_values( $v ) : array();
	}
	if ( 'true_false' === $type ) {
		return (bool) $v;
	}
	if ( 'textarea' === $type ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $v ); // a textarea's value is LF-only
	}
	if ( 'text' === $type ) {
		return str_replace( array( "\r", "\n" ), '', (string) $v ); // an <input> drops line breaks
	}
	return is_scalar( $v ) ? (string) $v : '';
};
$jet_form_body = function ( $fields, $item ) use ( $jet_form_value ) {
	$body = array();
	foreach ( $fields as $sf ) {
		$body[ $sf['key'] ] = $jet_form_value( $sf, $item[ $sf['key'] ] ?? '' );
	}
	return $body;
};
// Reshape fields by name ( name => overrides, or name => null to append one ).
$jet_reshape = function ( $factory, $changes ) {
	$saved = $factory->fields;
	foreach ( $factory->fields as $i => $fd ) {
		if ( isset( $fd['name'] ) && array_key_exists( $fd['name'], $changes ) ) {
			$factory->fields[ $i ] = array_merge( $fd, (array) $changes[ $fd['name'] ] );
			unset( $changes[ $fd['name'] ] );
		}
	}
	foreach ( $changes as $name => $def ) {
		$factory->fields[] = array_merge( array( 'title' => $name, 'name' => $name, 'object_type' => 'field', 'type' => 'text' ), (array) $def );
	}
	$cache = new ReflectionProperty( get_class( $factory ), '_formatted_fields' );
	$cache->setAccessible( true );
	$cache->setValue( $factory, null );
	return function () use ( $factory, $saved, $cache ) {
		$factory->fields = $saved;
		$cache->setValue( $factory, null );
	};
};
$jet_desc = function ( $factory ) {
	$coll = minn_admin_jet_cct_collection( 'minn_contact', $factory );
	return array( $coll['detail']['edit']['fields'], $coll['create']['fields'] );
};

// --- #11 JetEngine CCT item writes take field values from the JSON body only ---
if ( $jet_cct ) {
	global $wpdb;
	$jet11_route              = '/minn-admin/v1/jet-cct/minn_contact/items';
	list( $jet11_st, $jet11_c ) = $call( 'POST', $jet11_route, array( 'name' => 'Minn v043 cct11', 'email' => 'cct11@minn.test', 'tier' => 'lead', 'vip' => false, 'notes' => '' ) );
	$jet11_id                 = (int) ( $jet11_c['id'] ?? 0 );
	$jet11_ids                = array( $jet11_id );
	$check( 'JetEngine CCT: a JSON create inserts the item (control)', 200 === $jet11_st && $jet11_id > 0, 'status ' . $jet11_st );
	if ( $jet11_id ) {
		// A query-string key that names a field.
		$jet11_req = new WP_REST_Request( 'POST', $jet11_route . '/' . $jet11_id );
		$jet11_req->set_query_params( array( 'name' => 'From the query string' ) );
		$jet11_req->set_header( 'content-type', 'application/json' );
		$jet11_req->set_body( wp_json_encode( array( 'tier' => 'client' ) ) );
		rest_do_request( $jet11_req );
		$jet11_row = minn_admin_jet_cct_row( 'minn_contact', $jet11_id );
		$check( 'JetEngine CCT: a query-string key never reaches a field', 'Minn v043 cct11' === $jet11_row['name'], $jet11_row['name'] );
		$check( 'JetEngine CCT: the JSON body still edits (control)', 'client' === $jet11_row['tier'], $jet11_row['tier'] );
		// A form-encoded body is not the JSON body either.
		list( $jet11_fst ) = $call( 'POST', $jet11_route . '/' . $jet11_id, null, array( 'name' => 'From a form body' ) );
		$check( 'JetEngine CCT: a form-encoded key never reaches a field', 'Minn v043 cct11' === minn_admin_jet_cct_row( 'minn_contact', $jet11_id )['name'], minn_admin_jet_cct_row( 'minn_contact', $jet11_id )['name'] );
		$check( 'JetEngine CCT: an edit with no JSON body is refused, not a silent 200', 400 === $jet11_fst, 'status ' . $jet11_fst );

		// A type whose fields are named after the route's own segments.
		$jet11_restore = $jet_reshape( $jet_cct, array( 'slug' => array( 'title' => 'Slug' ), 'id' => array( 'title' => 'Id' ) ) );
		$jet11_seen    = array();
		$jet11_probe   = function ( $item ) use ( &$jet11_seen ) {
			$jet11_seen[] = array( 'slug' => $item['slug'] ?? null, 'id' => $item['id'] ?? null );
			unset( $item['slug'], $item['id'] ); // the table has no such columns: the probe only watches
			return $item;
		};
		add_filter( 'jet-engine/custom-content-types/item-to-update', $jet11_probe );
		try {
			$call( 'POST', $jet11_route . '/' . $jet11_id, array( 'tier' => 'lead' ) );
			list( , $jet11_c2 ) = $call( 'POST', $jet11_route, array( 'name' => 'Minn v043 cct11 b' ) );
			$jet11_ids[]        = (int) ( $jet11_c2['id'] ?? 0 );
		} finally {
			remove_filter( 'jet-engine/custom-content-types/item-to-update', $jet11_probe );
			$jet11_restore();
		}
		$jet11_u = $jet11_seen[0] ?? array();
		$jet11_n = $jet11_seen[1] ?? array();
		$check( 'JetEngine CCT: a partial update never writes the route\'s slug and id into fields named slug / id', 2 === count( $jet11_seen ) && 'minn_contact' !== ( $jet11_u['slug'] ?? '' ) && (string) $jet11_id !== (string) ( $jet11_u['id'] ?? '' ), wp_json_encode( $jet11_seen ) );
		$check( 'JetEngine CCT: a create never writes the route\'s slug into a field named slug', 'minn_contact' !== ( $jet11_n['slug'] ?? '' ), wp_json_encode( $jet11_n ) );
	}
	foreach ( array_filter( $jet11_ids ) as $jet11_del ) {
		$wpdb->delete( minn_admin_jet_cct_table( 'minn_contact' ), array( '_ID' => $jet11_del ) );
	}
} else {
	$skip( 'JetEngine Content Types (minn_contact) inactive' );
}

// --- #19 JetEngine panel and CCT edit leave untouched fields as stored ------
if ( function_exists( 'minn_admin_jet_write_values' ) && minn_admin_jet_fields_active() ) {
	global $wpdb;
	$jet19_post = wp_insert_post( array( 'post_title' => 'Minn v043 jet19', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
	$jet19_att  = wp_insert_attachment( array( 'post_title' => 'minn v043 jet19', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'minn-v043-jet19.png' );
	$jet19_dead = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 500000; // never an attachment
	// Two more field shapes on the fixture box, in memory only: a gallery of
	// ids and a picture stored by url.
	$jet19_data  = jet_engine()->meta_boxes->data;
	$jet19_data->get_raw();
	$jet19_saved = $jet19_data->raw;
	foreach ( (array) $jet19_data->raw as $jet19_k => $jet19_box ) {
		if ( 'Minn Post Details' === ( $jet19_box['args']['name'] ?? '' ) ) {
			$jet19_data->raw[ $jet19_k ]['meta_fields'][] = array( 'title' => 'Gallery', 'name' => 'minn_v043_gallery', 'object_type' => 'field', 'type' => 'gallery', 'value_format' => 'id' );
			$jet19_data->raw[ $jet19_k ]['meta_fields'][] = array( 'title' => 'Logo', 'name' => 'minn_v043_logo', 'object_type' => 'field', 'type' => 'media', 'value_format' => 'url' );
		}
	}
	try {
		$jet19_stored = array(
			'minn_subtitle'     => 'https://ex.com/b?n=Jo%20Ann <b>bold</b>',
			'minn_priority'     => '1.50',
			'minn_cover'        => (string) $jet19_dead,
			'minn_v043_gallery' => $jet19_att . ',' . $jet19_dead,
			'minn_v043_logo'    => 'https://cdn.example.com/elsewhere.png',
		);
		foreach ( $jet19_stored as $jet19_key => $jet19_val ) {
			update_post_meta( $jet19_post, $jet19_key, $jet19_val );
		}
		// The panel: seeded from the read, one field edited, the whole object sent.
		list( , $jet19_read ) = $call( 'GET', '/wp/v2/posts/' . $jet19_post, null, array( 'context' => 'edit' ) );
		$jet19_vals           = (array) ( $jet19_read['minn_jet'] ?? array() );
		$jet19_vals['minn_tier'] = 'gold';
		$call( 'POST', '/wp/v2/posts/' . $jet19_post, array( 'minn_jet' => $jet19_vals ) );
		$check( 'JetEngine panel: the edited field saves (control)', 'gold' === get_post_meta( $jet19_post, 'minn_tier', true ) );
		foreach ( $jet19_stored as $jet19_key => $jet19_val ) {
			$jet19_now = get_post_meta( $jet19_post, $jet19_key, true );
			$check( "JetEngine panel: untouched {$jet19_key} is left as stored", $jet19_val === $jet19_now, wp_json_encode( $jet19_now ) );
		}
		// A typed text field is cleaned the way JetEngine's own meta box cleans it.
		$jet19_vals['minn_subtitle'] = 'https://ex.com/?q=Jo%20Ann <em>kept</em><script>x()</script>';
		$jet19_vals['minn_icon']     = '<b>fa-star</b>';
		$call( 'POST', '/wp/v2/posts/' . $jet19_post, array( 'minn_jet' => $jet19_vals ) );
		$jet19_sub = get_post_meta( $jet19_post, 'minn_subtitle', true );
		$check( 'JetEngine panel: a typed text field keeps %XX and safe markup', false !== strpos( $jet19_sub, 'Jo%20Ann' ) && false !== strpos( $jet19_sub, '<em>kept</em>' ), $jet19_sub );
		$check( 'JetEngine panel: a typed text field still loses a script tag (control)', false === stripos( $jet19_sub, '<script' ), $jet19_sub );
		$check( 'JetEngine panel: an icon field still strips every tag like the vendor (control)', 'fa-star' === get_post_meta( $jet19_post, 'minn_icon', true ), get_post_meta( $jet19_post, 'minn_icon', true ) );
		// Deliberate clears still clear.
		update_post_meta( $jet19_post, 'minn_cover', (string) $jet19_att );
		list( , $jet19_read ) = $call( 'GET', '/wp/v2/posts/' . $jet19_post, null, array( 'context' => 'edit' ) );
		$jet19_vals           = (array) ( $jet19_read['minn_jet'] ?? array() );
		$jet19_vals['minn_cover']        = null;
		$jet19_vals['minn_v043_gallery'] = array();
		$jet19_vals['minn_priority']     = '3';
		$call( 'POST', '/wp/v2/posts/' . $jet19_post, array( 'minn_jet' => $jet19_vals ) );
		$check( 'JetEngine panel: clearing a picture that resolves still clears it (control)', '' === get_post_meta( $jet19_post, 'minn_cover', true ), wp_json_encode( get_post_meta( $jet19_post, 'minn_cover', true ) ) );
		$check( 'JetEngine panel: emptying a gallery still clears it (control)', '' === get_post_meta( $jet19_post, 'minn_v043_gallery', true ), wp_json_encode( get_post_meta( $jet19_post, 'minn_v043_gallery', true ) ) );
		$check( 'JetEngine panel: a changed number saves (control)', '3' === get_post_meta( $jet19_post, 'minn_priority', true ), get_post_meta( $jet19_post, 'minn_priority', true ) );
		$jet19_vals['minn_subtitle'] = '';
		$call( 'POST', '/wp/v2/posts/' . $jet19_post, array( 'minn_jet' => $jet19_vals ) );
		$check( 'JetEngine panel: emptying a text field still clears it (control)', '' === get_post_meta( $jet19_post, 'minn_subtitle', true ), wp_json_encode( get_post_meta( $jet19_post, 'minn_subtitle', true ) ) );
	} finally {
		$jet19_data->raw = $jet19_saved;
		wp_delete_post( $jet19_post, true );
		wp_delete_attachment( $jet19_att, true );
	}

	// The CCT edit modal posts every field it shows.
	if ( $jet_cct ) {
		$jet19_t                 = minn_admin_jet_cct_table( 'minn_contact' );
		list( , $jet19_c )       = $call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items', array( 'name' => 'Minn v043 cct19', 'email' => 'cct19@minn.test', 'tier' => 'lead', 'vip' => true, 'notes' => 'x' ) );
		$jet19_id                = (int) ( $jet19_c['id'] ?? 0 );
		$jet19_raw               = array( 'name' => 'Jo%20Ann <b>x</b> & co', 'notes' => "first\r\nsecond %41 <i>i</i>", 'email' => (string) $jet19_dead );
		// The email column reads as a picture for this probe: it holds an id
		// whose attachment is gone.
		$jet19_restore = $jet_reshape( $jet_cct, array( 'email' => array( 'type' => 'media', 'value_format' => 'id' ) ) );
		try {
			$wpdb->update( $jet19_t, $jet19_raw, array( '_ID' => $jet19_id ) );
			$jet19_item              = minn_admin_jet_cct_item( $jet_cct, minn_admin_jet_cct_row( 'minn_contact', $jet19_id ) );
			list( $jet19_edit_fields ) = $jet_desc( $jet_cct );
			$jet19_body              = $jet_form_body( $jet19_edit_fields, $jet19_item );
			$jet19_body['tier']      = 'client';
			$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet19_id, $jet19_body );
			$jet19_row = minn_admin_jet_cct_row( 'minn_contact', $jet19_id );
			$check( 'JetEngine CCT edit: the edited field saves (control)', 'client' === $jet19_row['tier'], $jet19_row['tier'] );
			foreach ( $jet19_raw as $jet19_col => $jet19_val ) {
				$check( "JetEngine CCT edit: untouched {$jet19_col} is left byte for byte", $jet19_val === $jet19_row[ $jet19_col ], wp_json_encode( $jet19_row[ $jet19_col ] ) );
			}
		} finally {
			$jet19_restore();
		}
		// A number column: a stored '1.50' comes back from the form as 1.5, and a
		// value a number input cannot show comes back empty.
		foreach ( array( '1.50', '12 units' ) as $jet19_num ) {
			$jet19_restore = $jet_reshape( $jet_cct, array( 'email' => array( 'type' => 'number' ) ) );
			try {
				$wpdb->update( $jet19_t, array( 'email' => $jet19_num ), array( '_ID' => $jet19_id ) );
				$jet19_item              = minn_admin_jet_cct_item( $jet_cct, minn_admin_jet_cct_row( 'minn_contact', $jet19_id ) );
				list( $jet19_edit_fields ) = $jet_desc( $jet_cct );
				$jet19_body              = $jet_form_body( $jet19_edit_fields, $jet19_item );
				$jet19_body['tier']      = 'lead';
				$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet19_id, $jet19_body );
				$jet19_now = minn_admin_jet_cct_row( 'minn_contact', $jet19_id )['email'];
				$check( "JetEngine CCT edit: an untouched number '{$jet19_num}' is left as stored", $jet19_num === $jet19_now, wp_json_encode( $jet19_now ) );
			} finally {
				$jet19_restore();
			}
		}
		// A typed text column goes to their handler as typed (it stores a text
		// field as given and escapes on output).
		$jet19_item        = minn_admin_jet_cct_item( $jet_cct, minn_admin_jet_cct_row( 'minn_contact', $jet19_id ) );
		list( $jet19_edit_fields ) = $jet_desc( $jet_cct );
		$jet19_body        = $jet_form_body( $jet19_edit_fields, $jet19_item );
		$jet19_body['name'] = 'Jo%20Ann <i>typed</i> & co';
		$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet19_id, $jet19_body );
		$jet19_now = minn_admin_jet_cct_row( 'minn_contact', $jet19_id )['name'];
		$check( 'JetEngine CCT edit: a typed text field is stored as their own screen stores it', 'Jo%20Ann <i>typed</i> & co' === $jet19_now, $jet19_now );
		$wpdb->delete( $jet19_t, array( '_ID' => $jet19_id ) );
	}
} else {
	$skip( 'JetEngine meta boxes inactive' );
}

// --- #20 JetEngine CCT edit leaves an empty radio empty ---------------------
if ( $jet_cct ) {
	global $wpdb;
	$jet20_t           = minn_admin_jet_cct_table( 'minn_contact' );
	list( , $jet20_c ) = $call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items', array( 'name' => 'Minn v043 cct20', 'email' => 'cct20@minn.test', 'tier' => 'lead', 'vip' => false, 'notes' => '' ) );
	$jet20_id          = (int) ( $jet20_c['id'] ?? 0 );
	// The tier column as a JetEngine radio.
	$jet20_restore = $jet_reshape( $jet_cct, array( 'tier' => array( 'type' => 'radio' ) ) );
	try {
		$wpdb->update( $jet20_t, array( 'tier' => '' ), array( '_ID' => $jet20_id ) );
		list( $jet20_edit_fields, $jet20_create_fields ) = $jet_desc( $jet_cct );
		$jet20_item         = minn_admin_jet_cct_item( $jet_cct, minn_admin_jet_cct_row( 'minn_contact', $jet20_id ) );
		$jet20_body         = $jet_form_body( $jet20_edit_fields, $jet20_item );
		$jet20_body['name'] = 'Minn v043 cct20 renamed';
		$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet20_id, $jet20_body );
		$jet20_row = minn_admin_jet_cct_row( 'minn_contact', $jet20_id );
		$check( 'JetEngine CCT edit: renaming an item leaves its empty radio empty', '' === $jet20_row['tier'], wp_json_encode( $jet20_row['tier'] ) );
		$check( 'JetEngine CCT edit: the rename saves (control)', 'Minn v043 cct20 renamed' === $jet20_row['name'], $jet20_row['name'] );
		$jet20_body         = $jet_form_body( $jet20_edit_fields, minn_admin_jet_cct_item( $jet_cct, $jet20_row ) );
		$jet20_body['tier'] = 'client';
		$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet20_id, $jet20_body );
		$check( 'JetEngine CCT edit: picking a radio choice saves it (control)', 'client' === minn_admin_jet_cct_row( 'minn_contact', $jet20_id )['tier'] );
		$jet20_new = $jet_form_body( $jet20_create_fields, array() );
		$check( 'JetEngine CCT create: a fresh form leaves the radio unpicked', '' === ( $jet20_new['tier'] ?? null ), wp_json_encode( $jet20_new['tier'] ?? null ) );
		// A stored choice the options no longer list survives an unrelated edit.
		$wpdb->update( $jet20_t, array( 'tier' => 'legacy' ), array( '_ID' => $jet20_id ) );
		$jet20_body         = $jet_form_body( $jet20_edit_fields, minn_admin_jet_cct_item( $jet_cct, minn_admin_jet_cct_row( 'minn_contact', $jet20_id ) ) );
		$jet20_body['name'] = 'Minn v043 cct20 again';
		$call( 'POST', '/minn-admin/v1/jet-cct/minn_contact/items/' . $jet20_id, $jet20_body );
		$check( 'JetEngine CCT edit: a radio value outside the choices is left as stored', 'legacy' === minn_admin_jet_cct_row( 'minn_contact', $jet20_id )['tier'], minn_admin_jet_cct_row( 'minn_contact', $jet20_id )['tier'] );
	} finally {
		$jet20_restore();
		$wpdb->delete( $jet20_t, array( '_ID' => $jet20_id ) );
	}
} else {
	$skip( 'JetEngine Content Types (minn_contact) inactive' );
}
// The same radio on an options page draws with its empty row.
if ( function_exists( 'minn_admin_jet_options_shape' ) && minn_admin_jet_options_active() && isset( jet_engine()->options_pages->registered_pages['minn-site-options'] ) ) {
	$jet20_page  = jet_engine()->options_pages->registered_pages['minn-site-options'];
	$jet20_saved = $jet20_page->meta_box;
	$jet20_page->meta_box[] = array( 'title' => 'Tier', 'name' => 'minn_v043_tier', 'object_type' => 'field', 'type' => 'radio', 'options' => array( array( 'key' => 'gold', 'value' => 'Gold' ), array( 'key' => 'silver', 'value' => 'Silver' ) ) );
	try {
		$jet20_shape = minn_admin_jet_options_shape( 'minn-site-options' );
		$jet20_field = null;
		foreach ( $jet20_shape['groups'][0]['fields'] as $jet20_f ) {
			if ( 'minn_v043_tier' === $jet20_f['name'] ) {
				$jet20_field = $jet20_f;
			}
		}
		$check( 'JetEngine options: a radio carries the empty row', ! empty( $jet20_field['clearable'] ), wp_json_encode( $jet20_field ) );
	} finally {
		$jet20_page->meta_box = $jet20_saved;
	}
}

// --- #21 JetEngine options page keeps backslashes ---------------------------
if ( function_exists( 'minn_admin_jet_options_save' ) && minn_admin_jet_options_active() && isset( jet_engine()->options_pages->registered_pages['minn-site-options'] ) ) {
	$jet21_before = get_option( 'minn-site-options' );
	$jet21_page   = jet_engine()->options_pages->registered_pages['minn-site-options'];
	$jet21_route  = '/minn-admin/v1/jet-engine/options/minn-site-options/tab-0';
	try {
		$jet21_val            = 'C:\\files \\d{3}-\\d{4} O\'Brien';
		list( $jet21_st, $jet21_res ) = $call( 'POST', $jet21_route, array( 'values' => array( 'company_name' => $jet21_val ) ) );
		$check( 'JetEngine options: a saved backslash reads back in Minn', 200 === $jet21_st && $jet21_val === ( $jet21_res['values']['company_name'] ?? null ), wp_json_encode( $jet21_res['values']['company_name'] ?? $jet21_st ) );
		$jet21_page->options = null;
		$check( 'JetEngine options: JetEngine\'s own get() returns it intact', $jet21_val === $jet21_page->get( 'company_name' ), wp_json_encode( $jet21_page->get( 'company_name' ) ) );
		$jet21_stored = get_option( 'minn-site-options' );
		$check( 'JetEngine options: untouched fields keep their value (control)', ( $jet21_before['support_email'] ?? null ) === ( $jet21_stored['support_email'] ?? null ) && ( $jet21_before['plan'] ?? null ) === ( $jet21_stored['plan'] ?? null ), wp_json_encode( $jet21_stored ) );
		// A caller that sends the whole page back as read changes nothing.
		$call( 'POST', $jet21_route, array( 'values' => $jet21_res['values'] ) );
		$check( 'JetEngine options: a whole-page resend leaves the stored array byte for byte', get_option( 'minn-site-options' ) === $jet21_stored, wp_json_encode( get_option( 'minn-site-options' ) ) );
		// Separate storage (one option per field) unslashes on read the same way.
		$jet21_page->storage_type = 'separate';
		$jet21_page->options      = null;
		try {
			list( , $jet21_sep ) = $call( 'POST', $jet21_route, array( 'values' => array( 'company_name' => 'D:\\sep\\x' ) ) );
			$jet21_page->options = null;
			$check( 'JetEngine options: separate storage keeps the backslashes too', 'D:\\sep\\x' === $jet21_page->get( 'company_name' ), wp_json_encode( $jet21_page->get( 'company_name' ) ) );
		} finally {
			delete_option( $jet21_page->get_separate_option_name( 'company_name' ) );
			$jet21_page->storage_type = 'default';
			$jet21_page->options      = null;
		}
		// A form-encoded save carries no JSON body: refused, nothing written.
		$jet21_was          = get_option( 'minn-site-options' );
		list( $jet21_fst )  = $call( 'POST', $jet21_route, null, array( 'values' => array( 'company_name' => 'From a form body' ) ) );
		$check( 'JetEngine options: a save with no JSON body is refused, not a silent 200', 400 === $jet21_fst && get_option( 'minn-site-options' ) === $jet21_was, 'status ' . $jet21_fst );
		list( , $jet21_res ) = $call( 'POST', $jet21_route, array( 'values' => array( 'company_name' => 'Plain Co', 'weekends' => false ) ) );
		$check( 'JetEngine options: a plain value and a switch save as typed (control)', 'Plain Co' === ( $jet21_res['values']['company_name'] ?? null ) && false === ( $jet21_res['values']['weekends'] ?? null ), wp_json_encode( $jet21_res['values'] ?? null ) );
	} finally {
		update_option( 'minn-site-options', $jet21_before );
		$jet21_page->options = null;
	}
} else {
	$skip( 'JetEngine options pages inactive' );
}

// --- #12 / #13 DB browser redaction helpers --------------------------------
// The value cell of the row named exactly $name, as /db/rows renders it to the
// grid. Details only ever print the cell's SHAPE: a real key that is already
// stored on the site must not land in test output.
$dbr_find  = function ( $table, $keycol, $valcol, $name ) use ( $call ) {
	list( $st, $res ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $table, 'page' => 1, 'per_page' => 50, 'fcol' => $keycol, 'fq' => $name ) );
	$cols = wp_list_pluck( (array) ( $res['columns'] ?? array() ), 'name' );
	$ki   = array_search( $keycol, $cols, true );
	$vi   = array_search( $valcol, $cols, true );
	foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
		if ( false !== $ki && false !== $vi && isset( $row[ $ki ] ) && $name === $row[ $ki ] ) {
			return array( $st, $row[ $vi ] );
		}
	}
	return array( $st, null );
};
$dbr_shape = function ( $cell ) {
	if ( is_array( $cell ) && ! empty( $cell['redacted'] ) ) {
		return 'redacted';
	}
	return null === $cell ? 'row missing' : 'RAW ' . strlen( is_array( $cell ) ? wp_json_encode( $cell ) : (string) $cell ) . ' bytes';
};
$dbr_red   = function ( $cell ) {
	return is_array( $cell ) && ! empty( $cell['redacted'] );
};

// --- #12 DB browser redacts Connector keys, the WPMU DEV key and Post SMTP's token
if ( class_exists( 'Minn_Admin_DB' ) ) {
	global $wpdb;
	$dbr_tag   = 'mv43dbr' . wp_rand( 100000, 999999 );
	// A connector that names its own row: only the live registry knows it.
	$dbr_reg   = class_exists( 'WP_Connector_Registry' ) ? WP_Connector_Registry::get_instance() : null;
	$dbr_probe = 'minnv043dbrprobe';
	if ( $dbr_reg && ! $dbr_reg->is_registered( $dbr_probe ) ) {
		$dbr_reg->register(
			$dbr_probe,
			array(
				'name'           => 'Minn probe',
				'type'           => 'spam_filtering',
				'authentication' => array( 'method' => 'api_key', 'setting_name' => 'minnv043dbr_probe_service_key' ),
			)
		);
	} else {
		$dbr_reg = null;
	}
	$dbr_rows = array(
		'postman_auth_token'                                         => array( 'access_token' => $dbr_tag . 'a_postman', 'refresh_token' => $dbr_tag . 'a_postref', 'vendor_name' => 'google' ),
		'wpmudev_apikey'                                             => $dbr_tag . 'b_wpmudev',
		'wp_smush_api_auth'                                          => array( $dbr_tag . 'c_smush' => array( 'validity' => 'valid', 'timestamp' => time() ) ),
		'connectors_ai_openai_api_key'                               => $dbr_tag . 'd_openai',
		// A provider plugin deactivated (or AI support turned off) drops its
		// connector from the registry and leaves the key row behind.
		'connectors_ai_minnv043dbrgone_api_key'                      => $dbr_tag . 'e_orphan',
		'connectors_spam_filtering_minnv043dbrgone_application_password' => array( 'username' => 'minn', 'password' => $dbr_tag . 'f_apppass' ),
	);
	if ( $dbr_reg ) {
		$dbr_rows['minnv043dbr_probe_service_key'] = $dbr_tag . 'g_probe';
	}
	// Every registered connector's row that already exists is checked too,
	// by shape only.
	if ( function_exists( 'wp_get_connectors' ) ) {
		foreach ( wp_get_connectors() as $dbr_c ) {
			$dbr_sn = (string) ( $dbr_c['authentication']['setting_name'] ?? '' );
			if ( '' !== $dbr_sn && ! isset( $dbr_rows[ $dbr_sn ] ) ) {
				$dbr_rows[ $dbr_sn ] = null;
			}
		}
	}
	$dbr_seeded = array();
	foreach ( $dbr_rows as $dbr_name => $dbr_val ) {
		// A stored row's byte length (null when absent). An empty value has
		// nothing to redact and renders as an empty cell.
		$dbr_len = $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s", $dbr_name ) );
		$dbr_had = null !== $dbr_len;
		if ( $dbr_had && 0 === (int) $dbr_len ) {
			continue;
		}
		if ( ! $dbr_had && null !== $dbr_val ) {
			add_option( $dbr_name, $dbr_val, '', false );
			$dbr_seeded[ $dbr_name ] = $dbr_val;
		}
		if ( ! $dbr_had && ! isset( $dbr_seeded[ $dbr_name ] ) ) {
			continue; // a registered connector with no stored key: nothing to show
		}
		list( $dbr_st, $dbr_cell ) = $dbr_find( $wpdb->options, 'option_name', 'option_value', $dbr_name );
		$check( "DB browser: {$dbr_name} renders redacted in the grid" . ( $dbr_had ? ' (stored row)' : '' ), 200 === $dbr_st && $dbr_red( $dbr_cell ), $dbr_st . ' ' . $dbr_shape( $dbr_cell ) );
	}
	foreach ( $dbr_seeded as $dbr_name => $dbr_val ) {
		$dbr_mark = is_array( $dbr_val ) ? (string) ( $dbr_val['access_token'] ?? $dbr_val['password'] ?? array_keys( $dbr_val )[0] ) : (string) $dbr_val;
		$dbr_id   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $dbr_name ) );
		list( $dbr_st, $dbr_one ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $dbr_id ) ) ) );
		$dbr_json = wp_json_encode( $dbr_one );
		$check( "DB browser: the {$dbr_name} row detail holds no part of the secret", 200 === $dbr_st && false === strpos( $dbr_json, $dbr_tag ), $dbr_st . ' ' . ( false === strpos( $dbr_json, $dbr_tag ) ? 'clean' : 'secret present' ) );
		// The LIKE filter is the oracle: a prefix of the secret must not find the row.
		list( , $dbr_q ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_value', 'fq' => substr( $dbr_mark, 0, strlen( $dbr_tag ) + 2 ) ) );
		$check( "DB browser: a value search on a prefix of the {$dbr_name} secret finds nothing", 0 === (int) ( $dbr_q['total'] ?? -1 ) && array() === (array) ( $dbr_q['rows'] ?? array( 'missing' ) ), 'total ' . wp_json_encode( $dbr_q['total'] ?? null ) );
	}
	list( , $dbr_sort ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'orderby' => 'option_value', 'order' => 'asc' ) );
	$check( 'DB browser: option_value is never a sort key', 'option_value' !== ( $dbr_sort['orderby'] ?? 'option_value' ), (string) wp_json_encode( $dbr_sort['orderby'] ?? null ) );
	// CONTROL: ordinary rows (including a connectors_ name that is not a
	// credential shape) still render and stay searchable by value.
	$dbr_ctl = array(
		'minnv043dbr_control'                => $dbr_tag . 'x_control',
		'connectors_ai_minnv043dbr_settings' => $dbr_tag . 'y_settings',
	);
	foreach ( $dbr_ctl as $dbr_name => $dbr_val ) {
		add_option( $dbr_name, $dbr_val, '', false );
		list( , $dbr_cell ) = $dbr_find( $wpdb->options, 'option_name', 'option_value', $dbr_name );
		list( , $dbr_q )    = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_value', 'fq' => $dbr_val ) );
		$check( "DB browser control: {$dbr_name} renders and a value search finds it", $dbr_val === $dbr_cell && 1 === (int) ( $dbr_q['total'] ?? -1 ), $dbr_shape( $dbr_cell ) . ' / total ' . wp_json_encode( $dbr_q['total'] ?? null ) );
		delete_option( $dbr_name );
	}
	foreach ( array_keys( $dbr_seeded ) as $dbr_name ) {
		delete_option( $dbr_name );
	}
	// Name-shape edges: whatever the grid redacts, the value search must not
	// find (the search's SQL exclusion has to cover every redacted row).
	$dbr_edges = array( 'connectors_api_key', 'connectors_ai_minnv043dbr_api_key_old', 'gravitysmtp_', 'POSTMAN_AUTH_TOKEN', 'Connectors_AI_MINNV043DBR_API_KEY' );
	foreach ( $dbr_edges as $dbr_i => $dbr_name ) {
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $dbr_name ) ) ) {
			continue; // collides (case-insensitively) with a stored row
		}
		$dbr_val = $dbr_tag . 'z' . $dbr_i . '_edge';
		$wpdb->insert( $wpdb->options, array( 'option_name' => $dbr_name, 'option_value' => $dbr_val, 'autoload' => 'off' ) );
		list( , $dbr_cell ) = $dbr_find( $wpdb->options, 'option_name', 'option_value', $dbr_name );
		list( , $dbr_q )    = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_value', 'fq' => $dbr_val ) );
		$check( "DB browser: {$dbr_name} is either shown or unsearchable, never redacted yet searchable", ! $dbr_red( $dbr_cell ) || 0 === (int) ( $dbr_q['total'] ?? -1 ), $dbr_shape( $dbr_cell ) . ' / total ' . wp_json_encode( $dbr_q['total'] ?? null ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $dbr_name ) );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( $dbr_reg ) {
		$dbr_reg->unregister( $dbr_probe );
	}
} else {
	$skip( 'DB browser not loaded' );
}

// --- #13 DB browser redacts the same credentials in the network's sitemeta --
if ( class_exists( 'Minn_Admin_DB' ) && is_multisite() ) {
	global $wpdb;
	$dbrn_tag  = 'mv43dbrn' . wp_rand( 100000, 999999 );
	$dbrn_net  = get_current_network_id();
	$dbrn_rows = array(
		// Freemius keeps everything but the per-blog keys of fs_accounts in
		// network storage, so each user's secret_key lands here.
		'fs_accounts'       => array( 'users' => array( 7 => array( 'id' => 7, 'public_key' => 'pk_minn', 'secret_key' => $dbrn_tag . 'a_freemius' ) ) ),
		'wpmudev_apikey'    => $dbrn_tag . 'b_wpmudev',
		'wp_smush_api_auth' => array( $dbrn_tag . 'c_smush' => array( 'validity' => 'valid', 'timestamp' => time() ) ),
		// One list for both tables: Post SMTP's token and a connector key
		// shape are redacted here as well.
		'postman_auth_token' => array( 'access_token' => $dbrn_tag . 'd_postman' ),
		'connectors_ai_minnv043dbrgone_api_key' => $dbrn_tag . 'e_orphan',
	);
	$dbrn_seeded = array();
	foreach ( $dbrn_rows as $dbrn_name => $dbrn_val ) {
		$dbrn_len = $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(meta_value) FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", $dbrn_name, $dbrn_net ) );
		if ( null !== $dbrn_len ) {
			if ( 0 === (int) $dbrn_len ) {
				continue;
			}
			list( $dbrn_st, $dbrn_cell ) = $dbr_find( $wpdb->sitemeta, 'meta_key', 'meta_value', $dbrn_name );
			$check( "DB browser (network): {$dbrn_name} renders redacted in sitemeta (stored row)", 200 === $dbrn_st && $dbr_red( $dbrn_cell ), $dbrn_st . ' ' . $dbr_shape( $dbrn_cell ) );
			continue;
		}
		update_site_option( $dbrn_name, $dbrn_val );
		$dbrn_seeded[] = $dbrn_name;
		$dbrn_mark     = is_array( $dbrn_val ) ? (string) ( $dbrn_val['users'][7]['secret_key'] ?? $dbrn_val['access_token'] ?? array_keys( $dbrn_val )[0] ) : (string) $dbrn_val;
		list( $dbrn_st, $dbrn_cell ) = $dbr_find( $wpdb->sitemeta, 'meta_key', 'meta_value', $dbrn_name );
		$check( "DB browser (network): {$dbrn_name} renders redacted in sitemeta", 200 === $dbrn_st && $dbr_red( $dbrn_cell ), $dbrn_st . ' ' . $dbr_shape( $dbrn_cell ) );
		$dbrn_id = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d", $dbrn_name, $dbrn_net ) );
		list( $dbrn_st, $dbrn_one ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $wpdb->sitemeta, 'pk' => wp_json_encode( array( 'meta_id' => $dbrn_id ) ) ) );
		$dbrn_json = wp_json_encode( $dbrn_one );
		$check( "DB browser (network): the {$dbrn_name} row detail holds no part of the secret", 200 === $dbrn_st && false === strpos( $dbrn_json, $dbrn_tag ), $dbrn_st . ' ' . ( false === strpos( $dbrn_json, $dbrn_tag ) ? 'clean' : 'secret present' ) );
		list( , $dbrn_q ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->sitemeta, 'page' => 1, 'per_page' => 50, 'fcol' => 'meta_value', 'fq' => substr( $dbrn_mark, 0, strlen( $dbrn_tag ) + 2 ) ) );
		$check( "DB browser (network): a value search on a prefix of the {$dbrn_name} secret finds nothing", 0 === (int) ( $dbrn_q['total'] ?? -1 ) && array() === (array) ( $dbrn_q['rows'] ?? array( 'missing' ) ), 'total ' . wp_json_encode( $dbrn_q['total'] ?? null ) );
	}
	list( , $dbrn_sort ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->sitemeta, 'page' => 1, 'per_page' => 50, 'orderby' => 'meta_value', 'order' => 'asc' ) );
	$check( 'DB browser (network): meta_value is never a sort key', 'meta_value' !== ( $dbrn_sort['orderby'] ?? 'meta_value' ), (string) wp_json_encode( $dbrn_sort['orderby'] ?? null ) );
	// CONTROL: an ordinary network option still renders and is searchable.
	update_site_option( 'minnv043dbrn_control', $dbrn_tag . 'x_control' );
	list( , $dbrn_cell ) = $dbr_find( $wpdb->sitemeta, 'meta_key', 'meta_value', 'minnv043dbrn_control' );
	list( , $dbrn_q )    = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->sitemeta, 'page' => 1, 'per_page' => 50, 'fcol' => 'meta_value', 'fq' => $dbrn_tag . 'x_control' ) );
	$check( 'DB browser control (network): an ordinary site option renders and a value search finds it', $dbrn_tag . 'x_control' === $dbrn_cell && 1 === (int) ( $dbrn_q['total'] ?? -1 ), $dbr_shape( $dbrn_cell ) . ' / total ' . wp_json_encode( $dbrn_q['total'] ?? null ) );
	delete_site_option( 'minnv043dbrn_control' );
	foreach ( $dbrn_seeded as $dbrn_name ) {
		delete_site_option( $dbrn_name );
	}
} else {
	$skip( 'DB browser sitemeta redaction (multisite only)' );
}

// --- #16 Minn Bar: the visibility fix is bound to the bar's own corner ------
// bar.js used to find its root, its menus and the one-click fix by
// document.getElementById, and the bar prints after the post content, so an
// author's <div id="minn-bar-status-fix"> (or a <label for> pointing at the
// real button) took the admin's next click. The browser half, with a real
// author post and a real click, is tests/bar-content-spoof.test.js; this is
// the server half: the corner key the client binds by, and the fix it offers.
if ( class_exists( 'Minn_Admin_Bar' ) ) {
	$bar_ref    = new ReflectionClass( 'Minn_Admin_Bar' );
	$bar_config = $bar_ref->getMethod( 'config' );
	$bar_config->setAccessible( true );
	$bar_public = get_option( 'blog_public' );
	update_option( 'blog_public', '0' );
	$bar_cfg = $bar_config->invoke( null );
	// A provider's coming-soon mode outranks search visibility, so any fix
	// counts: the point is that the administrator is still offered one.
	$check( '#16 Minn Bar: an administrator on a non-public site is still offered its fix (control)',
		is_array( $bar_cfg['fix'] ) && ! empty( $bar_cfg['fix']['kind'] ), wp_json_encode( $bar_cfg['fix'] ) );
	$bar_key = isset( $bar_cfg['key'] ) ? (string) $bar_cfg['key'] : '';
	$check( '#16 Minn Bar: the config names its own corner by an unguessable key',
		(bool) preg_match( '/^[A-Za-z0-9]{16,}$/', $bar_key ), $bar_key ? $bar_key : 'no key' );
	if ( $bar_ref->hasProperty( 'key' ) ) {
		$bar_key_prop = $bar_ref->getProperty( 'key' );
		$bar_key_prop->setAccessible( true );
		$bar_again = $bar_config->invoke( null );
		$check( '#16 Minn Bar: the key is stable within one request', $bar_again['key'] === $bar_key );
		$bar_key_prop->setValue( null, '' );
		$bar_next = $bar_config->invoke( null );
		$check( '#16 Minn Bar: a new request mints a new key', $bar_next['key'] && $bar_next['key'] !== $bar_key );
	} else {
		$check( '#16 Minn Bar: the key is minted per request', false, 'no per-request key on Minn_Admin_Bar' );
	}
	update_option( 'blog_public', $bar_public );

	// The attack surface the client fix assumes: post content from a role
	// without unfiltered_html keeps id, style, hidden and <label for>.
	$bar_author = get_users( array( 'role__in' => array( 'author', 'contributor' ), 'number' => 1 ) );
	if ( $bar_author ) {
		wp_set_current_user( $bar_author[0]->ID );
		kses_init();
		$bar_payload = '<div id="minn-bar-status-fix" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;opacity:0"></div>'
			. '<div id="minn-bar-menu-user" hidden><a href="https://example.invalid/">Sign out</a></div>'
			. '<label for="minn-bar-status-fix" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;opacity:0">.</label>';
		$bar_kept    = wp_unslash( apply_filters( 'content_save_pre', wp_slash( $bar_payload ) ) ) === $bar_payload;
		wp_set_current_user( $admin );
		kses_init();
		if ( $bar_kept ) {
			$check( '#16 Minn Bar: (precondition) author content still carries ids, fixed styles and label for', true );
		} else {
			$skip( '#16 Minn Bar: core kses no longer keeps the spoof payload for authors' );
		}
	} else {
		$skip( '#16 Minn Bar: no author or contributor to check kses as' );
	}
} else {
	$skip( '#16 Minn Bar not loaded' );
}

// --- #15 Default role and open registration (single site) ------------------
// Core's options.php and General screen let an administrator set both on a
// single site, but its picker leaves out administrator and editor (through
// default_role_dropdown_excluded_roles) unless one is already the default.
if ( ! is_multisite() ) {
	$dr_role = get_option( 'default_role' );
	$dr_reg  = get_option( 'users_can_register' );
	list( $dr_st ) = $call( 'POST', '/wp/v2/settings', array( 'default_role' => 'author', 'users_can_register' => 1 ) );
	$check( '#15 default role (single site): an administrator can pick author and open registration (control)',
		200 === $dr_st && 'author' === get_option( 'default_role' ) && 1 === (int) get_option( 'users_can_register' ),
		$dr_st . ' ' . get_option( 'default_role' ) . ' ' . get_option( 'users_can_register' ) );
	foreach ( array( 'administrator', 'editor' ) as $dr_try ) {
		$call( 'POST', '/wp/v2/settings', array( 'default_role' => $dr_try ) );
		$check( '#15 default role (single site): a new pick of "' . $dr_try . '" is refused',
			'author' === get_option( 'default_role' ), get_option( 'default_role' ) );
	}
	// Spellings get_role() does not know: core's own sanitize_option turns
	// them into subscriber before any filter runs. Never an excluded role.
	foreach ( array( 'Administrator', ' administrator', 'EDITOR', 'not_a_role' ) as $dr_try ) {
		$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'author' ) );
		$call( 'POST', '/wp/v2/settings', array( 'default_role' => $dr_try ) );
		$check( '#15 default role (single site): "' . $dr_try . '" never becomes an excluded role',
			! in_array( get_option( 'default_role' ), array( 'administrator', 'editor' ), true ), get_option( 'default_role' ) );
	}
	$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'author' ) );
	list( $dr_arr_st ) = $call( 'POST', '/wp/v2/settings', array( 'default_role' => array( 'administrator' ) ) );
	$check( '#15 default role (single site): an array value is refused by the schema',
		400 === $dr_arr_st && 'author' === get_option( 'default_role' ), $dr_arr_st . ' ' . get_option( 'default_role' ) );
	// The refusal is the REST route's, not the option's: core stores any real
	// role, and WP-CLI or a role plugin writing one is not Minn's to refuse.
	update_option( 'default_role', 'editor' );
	$check( '#15 default role (single site): update_option outside wp/v2/settings still stores an editor default (control)',
		'editor' === get_option( 'default_role' ), get_option( 'default_role' ) );
	// An already-stored excluded role survives the client's untouched save
	// (the settings form sends every field it shows).
	$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'editor', 'users_can_register' => 1 ) );
	$check( '#15 default role (single site): an untouched save keeps an editor default already stored',
		'editor' === get_option( 'default_role' ), get_option( 'default_role' ) );
	$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'subscriber' ) );
	$check( '#15 default role (single site): moving off a stored editor default still works (control)',
		'subscriber' === get_option( 'default_role' ), get_option( 'default_role' ) );
	// The exclusion is core's filter, not a hard-coded list.
	$dr_only_admin = function () {
		return array( 'administrator' );
	};
	add_filter( 'default_role_dropdown_excluded_roles', $dr_only_admin );
	$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'editor' ) );
	$dr_filtered = get_option( 'default_role' );
	$call( 'POST', '/wp/v2/settings', array( 'default_role' => 'administrator' ) );
	$dr_filtered_admin = get_option( 'default_role' );
	remove_filter( 'default_role_dropdown_excluded_roles', $dr_only_admin );
	$check( '#15 default role (single site): the exclusion follows default_role_dropdown_excluded_roles',
		'editor' === $dr_filtered && 'editor' === $dr_filtered_admin, $dr_filtered . ' / ' . $dr_filtered_admin );
	list( , $dr_get ) = $call( 'GET', '/wp/v2/settings' );
	$check( '#15 default role (single site): the settings read still carries both for the picker (control)',
		is_array( $dr_get ) && array_key_exists( 'default_role', $dr_get ) && array_key_exists( 'users_can_register', $dr_get ) );
	$dr_boot = Minn_Admin::boot_payload();
	$check( '#15 default role (single site): the boot payload names the roles the picker leaves out',
		isset( $dr_boot['defaultRoleExcluded'] ) && array( 'administrator', 'editor' ) === $dr_boot['defaultRoleExcluded'],
		wp_json_encode( $dr_boot['defaultRoleExcluded'] ?? null ) );
	update_option( 'default_role', $dr_role );
	update_option( 'users_can_register', $dr_reg );
} else {
	$skip( '#15 default role (single site): multisite' );
}

// --- #15 Default role and open registration stay network-side (multisite) --
// Core lets options.php save neither on a network (both are Network Admin's
// per-site settings), so a subsite administrator must not reach them over
// wp/v2/settings either.
if ( is_multisite() ) {
	global $wpdb;
	if ( ! function_exists( 'wpmu_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	// Raw rows: on a network core filters every read of users_can_register
	// through the network's registration setting, so get_option() would
	// hide a per-site write.
	$ms_dr_rawopt = function ( $name ) use ( $wpdb ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	};
	$ms_dr_role = $ms_dr_rawopt( 'default_role' );
	$ms_dr_reg  = $ms_dr_rawopt( 'users_can_register' );
	$ms_dr_pub  = get_option( 'blog_public' );
	$ms_dr_user = wpmu_create_user( 'minnv043dr' . wp_rand( 100, 999 ), wp_generate_password(), 'minn-v043-dr-' . wp_rand() . '@example.com' );
	add_user_to_blog( get_current_blog_id(), $ms_dr_user, 'administrator' );
	wp_set_current_user( $ms_dr_user );
	list( $ms_dr_st ) = $call( 'POST', '/wp/v2/settings', array( 'default_role' => 'administrator', 'users_can_register' => 1 ) );
	$check( '#15 default role (multisite): a subsite administrator cannot set the default role',
		$ms_dr_rawopt( 'default_role' ) === $ms_dr_role, $ms_dr_st . ' ' . var_export( $ms_dr_rawopt( 'default_role' ), true ) );
	$check( '#15 default role (multisite): a subsite administrator cannot write the registration flag',
		$ms_dr_rawopt( 'users_can_register' ) === $ms_dr_reg, var_export( $ms_dr_rawopt( 'users_can_register' ), true ) );
	list( , $ms_dr_get ) = $call( 'GET', '/wp/v2/settings' );
	$check( '#15 default role (multisite): the settings read does not offer either',
		is_array( $ms_dr_get ) && ! array_key_exists( 'default_role', $ms_dr_get ) && ! array_key_exists( 'users_can_register', $ms_dr_get ) );
	$call( 'POST', '/wp/v2/settings', array( 'blog_public' => 0 ) );
	$check( '#15 default role (multisite): search visibility stays a subsite setting (control)',
		'0' === (string) get_option( 'blog_public' ), var_export( get_option( 'blog_public' ), true ) );
	wp_set_current_user( $admin );
	update_option( 'blog_public', $ms_dr_pub );
	foreach ( array( 'default_role' => $ms_dr_role, 'users_can_register' => $ms_dr_reg ) as $ms_dr_name => $ms_dr_was ) {
		if ( null === $ms_dr_was ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $ms_dr_name ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $ms_dr_was ), array( 'option_name' => $ms_dr_name ) );
		}
		wp_cache_delete( $ms_dr_name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wpmu_delete_user( $ms_dr_user );
} else {
	$skip( '#15 default role (multisite): single site' );
}

// --- #1 ACPT panel save keeps dates, prefix-sharing siblings and affixes ----
if ( function_exists( 'minn_admin_acpt_active' ) && minn_admin_acpt_active() && class_exists( '\\ACPT\\Core\\CQRS\\Command\\DeleteMetaGroupCommand' )
	&& class_exists( '\\ACPT\\Core\\CQRS\\Command\\SaveMetaGroupCommand' ) ) {
	global $wpdb;
	$acpt_sfx   = substr( md5( uniqid( '', true ) ), 0, 8 );
	$acpt_group = 'minn-v043-acpt-panel-' . $acpt_sfx;
	$acpt_box   = 'minn_v043_acpt_p' . $acpt_sfx;
	$acpt_gid   = '';
	try {
		// Box order matters for the prefix delete: a longer-named sibling
		// written BEFORE the short field is gone for good once the short
		// one's LIKE delete runs.
		$acpt_gid = ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => $acpt_group,
			'label'   => 'Minn v043 ACPT panel',
			'belongs' => array( array( 'belongsTo' => 'customPostType', 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
			'boxes'   => array( array( 'name' => $acpt_box, 'label' => 'Box', 'fields' => array(
				array( 'name' => 'event_date_end', 'type' => 'Text', 'label' => 'Ends' ),
				array( 'name' => 'event_date', 'type' => 'Date', 'label' => 'Date' ),
				array( 'name' => 'opens', 'type' => 'Time', 'label' => 'Opens' ),
				array( 'name' => 'starts_at', 'type' => 'DateTime', 'label' => 'Starts' ),
				array( 'name' => 'price_sale', 'type' => 'Text', 'label' => 'Sale price' ),
				array( 'name' => 'price', 'type' => 'Text', 'label' => 'Price' ),
				array( 'name' => 'qty', 'type' => 'Number', 'label' => 'Qty' ),
				array( 'name' => 'fee', 'type' => 'Text', 'label' => 'Fee', 'advancedOptions' => array( array( 'key' => 'before', 'value' => '$' ), array( 'key' => 'after', 'value' => ' flat' ) ) ),
				array( 'name' => 'promo', 'type' => 'Text', 'label' => 'Promo' ),
				array( 'name' => 'tagline_alt', 'type' => 'Text', 'label' => 'Alt tagline' ),
				array( 'name' => 'tagline', 'type' => 'Text', 'label' => 'Tagline' ),
				array( 'name' => 'choice', 'type' => 'Select', 'label' => 'Choice', 'options' => array( array( 'value' => 'a', 'label' => 'A', 'sort' => 1, 'isDefault' => false ), array( 'value' => 'b', 'label' => 'B', 'sort' => 2, 'isDefault' => false ) ) ),
				array( 'name' => 'venue', 'type' => 'Text', 'label' => 'Venue' ),
				array( 'name' => 'photo_credit', 'type' => 'Text', 'label' => 'Credit' ),
				array( 'name' => 'photo', 'type' => 'Image', 'label' => 'Photo' ),
				array( 'name' => 'seats', 'type' => 'Number', 'label' => 'Seats', 'advancedOptions' => array( array( 'key' => 'min', 'value' => '3' ) ) ),
			) ) ),
		) ) )->execute();
	} catch ( \Throwable $e ) {
		$skip( 'ACPT panel: could not build the fixture group (' . $e->getMessage() . ')' );
	}
	if ( $acpt_gid ) {
		$acpt_sc = 'minn_v043_acpt_sc_' . $acpt_sfx;
		add_shortcode( $acpt_sc, function () {
			return 'EXPANDED';
		} );
		$acpt_pid = wp_insert_post( array( 'post_title' => 'Minn v043 ACPT panel', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		$acpt_set = function ( $name, $v, $ctx = null ) use ( &$acpt_pid, $acpt_box ) {
			return save_acpt_meta_field_value( array_merge( $ctx ? $ctx : array( 'post_id' => $acpt_pid ), array( 'box_name' => $acpt_box, 'field_name' => $name, 'value' => $v ) ) );
		};
		$acpt_set( 'event_date_end', 'Nov 3 close' );
		$acpt_set( 'event_date', '2026-11-01' );
		$acpt_set( 'opens', '09:30:00' );
		$acpt_set( 'starts_at', '2026-11-01 09:30:00' );
		$acpt_set( 'price_sale', '9' );
		$acpt_set( 'qty', '0' );
		$acpt_set( 'fee', '10' );
		$acpt_set( 'promo', '[' . $acpt_sc . ']' );
		$acpt_set( 'tagline_alt', 'Alt line' );
		$acpt_set( 'tagline', 'Old line' );
		$acpt_set( 'choice', 'a' );
		$acpt_set( 'venue', 'Hall A' );
		$acpt_set( 'photo_credit', 'Jane' );
		$acpt_set( 'seats', '5' );
		$acpt_img = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC LIMIT 1" );
		if ( $acpt_img ) {
			$acpt_set( 'photo', $acpt_img );
		}
		// Straight from the table: ACPT's prefix delete is raw SQL, so the
		// object cache would still answer with the rows it removed. get_row,
		// not get_var, which answers null for a stored ''.
		$acpt_meta = function ( $name ) use ( $wpdb, &$acpt_pid, $acpt_box ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $acpt_pid, $acpt_box . '_' . $name ) );
			return $r ? (string) $r->meta_value : null;
		};
		$acpt_ids = array();
		foreach ( minn_admin_acpt_fields_payload( $acpt_pid, 'post', true )['lookup'] as $id => $f ) {
			if ( $f->getBox()->getName() === $acpt_box ) {
				$acpt_ids[ $f->getName() ] = $id;
			}
		}
		// What the client holds: the minn_acpt object from the edit read.
		$acpt_get = function () use ( $call, &$acpt_pid ) {
			list( , $d ) = $call( 'GET', '/wp/v2/posts/' . $acpt_pid, null, array( 'context' => 'edit' ) );
			return json_decode( wp_json_encode( $d['minn_acpt'] ?? array() ), true );
		};
		// Every field's id from ACPT itself, offered or not.
		$acpt_all = array();
		foreach ( \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $acpt_gid ) )[0]->getBoxes() as $b ) {
			foreach ( $b->getFields() as $f ) {
				$acpt_all[ $f->getName() ] = $f->getId();
			}
		}
		$acpt_served = $acpt_get();
		$acpt_blank_dates = array_filter( array( 'event_date', 'opens', 'starts_at' ), function ( $n ) use ( $acpt_served, $acpt_all ) {
			return isset( $acpt_all[ $n ] ) && array_key_exists( $acpt_all[ $n ], $acpt_served ) && '' === $acpt_served[ $acpt_all[ $n ] ];
		} );
		$check( 'ACPT panel: a stored date is never offered as an empty box', ! $acpt_blank_dates, wp_json_encode( array_values( $acpt_blank_dates ) ) );
		$acpt_box_shape = null;
		foreach ( minn_admin_acpt_fields_payload( $acpt_pid, 'post' )['groups'] as $g ) {
			if ( false !== strpos( $g['group'], 'Minn v043 ACPT panel' ) ) {
				$acpt_box_shape = $g;
			}
		}
		// Five since v0.44.0: the field holding a shortcode is locked too
		// (security-v044-rc 06-02).
		$check( 'ACPT panel: dates, times, the affixed field and the shortcode field count as locked', $acpt_box_shape && 5 === $acpt_box_shape['locked']
			&& ! array_intersect( array( $acpt_all['event_date'] ?? '', $acpt_all['opens'] ?? '', $acpt_all['starts_at'] ?? '', $acpt_all['fee'] ?? '' ), wp_list_pluck( $acpt_box_shape['fields'], 'name' ) ),
			wp_json_encode( $acpt_box_shape ? array( $acpt_box_shape['locked'], wp_list_pluck( $acpt_box_shape['fields'], 'label' ) ) : null ) );

		// When ACPT's setter refuses '' and the field model cannot name its
		// own keys (a build without those methods), the clear is refused out
		// loud rather than dropped. A stand-in model without them, pointed at
		// the stored select.
		$acpt_bare = new class() {
			public function getType() {
				return 'Select';
			}
			public function getLabelOrName() {
				return 'Choice';
			}
		};
		$acpt_refused = minn_admin_acpt_write_one( $acpt_bare, null, array( 'post_id' => $acpt_pid, 'box_name' => $acpt_box, 'field_name' => 'choice' ) );
		$check( 'ACPT panel: a clear that cannot be stored empty answers 400 and changes nothing', is_wp_error( $acpt_refused ) && 400 === ( $acpt_refused->get_error_data()['status'] ?? 0 ) && 'a' === $acpt_meta( 'choice' ),
			is_wp_error( $acpt_refused ) ? $acpt_refused->get_error_message() . ' / ' . var_export( $acpt_meta( 'choice' ), true ) : var_export( $acpt_refused, true ) );

		// Edit one field, clear two, and send the whole object back the way
		// the panel does whenever anything in it is dirty.
		$acpt_send = $acpt_served;
		if ( isset( $acpt_ids['venue'] ) ) {
			$acpt_send[ $acpt_ids['venue'] ] = 'Hall B';
		}
		if ( isset( $acpt_ids['tagline'] ) ) {
			$acpt_send[ $acpt_ids['tagline'] ] = '';
		}
		if ( isset( $acpt_ids['choice'] ) ) {
			$acpt_send[ $acpt_ids['choice'] ] = null; // the select's "—" pick
		}
		if ( $acpt_img && isset( $acpt_ids['photo'] ) ) {
			$acpt_send[ $acpt_ids['photo'] ] = null; // the picker's remove
		}
		if ( isset( $acpt_ids['seats'] ) ) {
			$acpt_send[ $acpt_ids['seats'] ] = null; // a number box emptied
		}
		// A tab opened before the update still holds the old payload: dates
		// as '', the affixed field as its formatted read.
		foreach ( array( 'event_date' => '', 'opens' => '', 'starts_at' => '', 'fee' => '$10 flat' ) as $acpt_n => $acpt_v ) {
			if ( isset( $acpt_all[ $acpt_n ] ) ) {
				$acpt_send[ $acpt_all[ $acpt_n ] ] = $acpt_v;
			}
		}
		list( $acpt_st ) = $call( 'POST', '/wp/v2/posts/' . $acpt_pid, array( 'minn_acpt' => $acpt_send ) );
		$check( 'ACPT panel: the save itself answers 200 (control)', 200 === $acpt_st, (string) $acpt_st );
		$check( 'ACPT panel: an untouched Date survives a save of another field', '2026-11-01' === $acpt_meta( 'event_date' ), var_export( $acpt_meta( 'event_date' ), true ) );
		$check( 'ACPT panel: untouched Time and DateTime survive', '09:30:00' === $acpt_meta( 'opens' ) && '2026-11-01 09:30:00' === $acpt_meta( 'starts_at' ), var_export( array( $acpt_meta( 'opens' ), $acpt_meta( 'starts_at' ) ), true ) );
		$check( 'ACPT panel: a field named after a date field (event_date_end) survives', 'Nov 3 close' === $acpt_meta( 'event_date_end' ), var_export( $acpt_meta( 'event_date_end' ), true ) );
		$check( 'ACPT panel: an empty field never takes a prefix-sharing sibling (price -> price_sale)', '9' === $acpt_meta( 'price_sale' ), var_export( $acpt_meta( 'price_sale' ), true ) );
		$check( 'ACPT panel: clearing a field never takes a prefix-sharing sibling (tagline -> tagline_alt)', 'Alt line' === $acpt_meta( 'tagline_alt' ), var_export( $acpt_meta( 'tagline_alt' ), true ) );
		$check( 'ACPT panel: a Number holding 0 (read back as empty) survives', '0' === $acpt_meta( 'qty' ), var_export( $acpt_meta( 'qty' ), true ) );
		$check( 'ACPT panel: a before/after affix is never written into the stored value', '10' === $acpt_meta( 'fee' ), var_export( $acpt_meta( 'fee' ), true ) );
		$check( 'ACPT panel: a shortcode is never stored expanded', '[' . $acpt_sc . ']' === $acpt_meta( 'promo' ), var_export( $acpt_meta( 'promo' ), true ) );
		$check( 'ACPT panel: the edited field saves (control)', 'Hall B' === $acpt_meta( 'venue' ), var_export( $acpt_meta( 'venue' ), true ) );
		$check( 'ACPT panel: a cleared text field stores empty, the way ACPT\'s own form does (control)', '' === $acpt_meta( 'tagline' ), var_export( $acpt_meta( 'tagline' ), true ) );
		$check( 'ACPT panel: a select cleared with its empty pick stores empty (control)', '' === $acpt_meta( 'choice' ), var_export( $acpt_meta( 'choice' ), true ) );
		$check( 'ACPT panel: a number with a minimum, emptied, stores empty (control)', '' === $acpt_meta( 'seats' ), var_export( $acpt_meta( 'seats' ), true ) );
		if ( $acpt_img ) {
			$check( 'ACPT panel: removing an image keeps a prefix-sharing sibling (photo -> photo_credit)', 'Jane' === $acpt_meta( 'photo_credit' ), var_export( $acpt_meta( 'photo_credit' ), true ) );
			$check( 'ACPT panel: a removed image stores empty (control)', '' === $acpt_meta( 'photo' ), var_export( $acpt_meta( 'photo' ), true ) );
			// ACPT's own edit screen reads the attachment id before the address.
			$check( 'ACPT panel: a removed image leaves no attachment id behind', in_array( $acpt_meta( 'photo_attachment_id' ), array( null, '' ), true ), var_export( $acpt_meta( 'photo_attachment_id' ), true ) );
		} else {
			$skip( 'ACPT panel: no image attachment to exercise an Image clear' );
		}

		// A second untouched round trip must be a no-op: nothing compounds.
		$call( 'POST', '/wp/v2/posts/' . $acpt_pid, array( 'minn_acpt' => $acpt_get() ) );
		$check( 'ACPT panel: a second untouched save changes nothing', '10' === $acpt_meta( 'fee' ) && '2026-11-01' === $acpt_meta( 'event_date' ) && 'Hall B' === $acpt_meta( 'venue' ) && '9' === $acpt_meta( 'price_sale' ),
			var_export( array( $acpt_meta( 'fee' ), $acpt_meta( 'event_date' ), $acpt_meta( 'venue' ), $acpt_meta( 'price_sale' ) ), true ) );

		// Option pages write through the same helper. A clear there ran the
		// same LIKE delete over wp_options. Option pages need an ACPT licence to
		// be listed, so drive the shared writer with an option-page context.
		$acpt_page = 'minn-v043-acpt-page-' . $acpt_sfx;
		$acpt_ctx  = array( 'option_page' => $acpt_page );
		$acpt_set( 'price_sale', '7', $acpt_ctx );
		$acpt_set( 'price', '5', $acpt_ctx );
		$acpt_opt = function ( $name ) use ( $wpdb, $acpt_box ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( $acpt_box . '_' . $name ) ) );
			return $r ? (string) $r->option_value : null;
		};
		$acpt_price = null;
		foreach ( minn_admin_acpt_fields_payload( $acpt_pid, 'post', true )['lookup'] as $f ) {
			if ( $f->getBox()->getName() === $acpt_box && 'price' === $f->getName() ) {
				$acpt_price = $f;
			}
		}
		if ( $acpt_price && '7' === $acpt_opt( 'price_sale' ) ) {
			minn_admin_acpt_write_one( $acpt_price, '', array_merge( $acpt_ctx, array( 'box_name' => $acpt_box, 'field_name' => 'price' ) ) );
			$check( 'ACPT option page: clearing a field keeps a prefix-sharing sibling option', '7' === $acpt_opt( 'price_sale' ), var_export( $acpt_opt( 'price_sale' ), true ) );
			$check( 'ACPT option page: the cleared field stores empty (control)', '' === $acpt_opt( 'price' ), var_export( $acpt_opt( 'price' ), true ) );
			$acpt_set( 'choice', 'b', $acpt_ctx );
			$acpt_ochoice = null;
			foreach ( minn_admin_acpt_fields_payload( $acpt_pid, 'post', true )['lookup'] as $f ) {
				if ( $f->getBox()->getName() === $acpt_box && 'choice' === $f->getName() ) {
					$acpt_ochoice = $f;
				}
			}
			if ( $acpt_ochoice && 'b' === $acpt_opt( 'choice' ) ) {
				$acpt_or = minn_admin_acpt_write_one( $acpt_ochoice, null, array_merge( $acpt_ctx, array( 'box_name' => $acpt_box, 'field_name' => 'choice' ) ) );
				$check( 'ACPT option page: a select cleared stores empty under its own option (control)', null === $acpt_or && '' === $acpt_opt( 'choice' ), var_export( $acpt_opt( 'choice' ), true ) );
			}
		} else {
			$skip( 'ACPT option page: could not seed option-page values' );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( $acpt_box ) . '%' ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_delete_post( $acpt_pid, true );
		remove_shortcode( $acpt_sc );
	}
	if ( $acpt_gid ) {
		// By id: ACPT caches its by-name lookup from before the group existed.
		( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $acpt_gid ) )->execute();
	}
} else {
	$skip( 'ACPT panel: ACPT inactive' );
}

// --- #17 ACPT repeaters are locked: a row save changes nothing stored ------
if ( function_exists( 'minn_admin_acpt_active' ) && minn_admin_acpt_active() && class_exists( '\\ACPT\\Core\\CQRS\\Command\\DeleteMetaGroupCommand' )
	&& class_exists( '\\ACPT\\Core\\CQRS\\Command\\SaveMetaGroupCommand' ) ) {
	global $wpdb;
	$acpt_rsfx   = substr( md5( uniqid( '', true ) ), 0, 8 );
	$acpt_rgroup = 'minn-v043-acpt-rows-' . $acpt_rsfx;
	$acpt_rbox   = 'minn_v043_acpt_r' . $acpt_rsfx;
	$acpt_rgid   = '';
	try {
		$acpt_rgid = ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => $acpt_rgroup,
			'label'   => 'Minn v043 ACPT rows',
			'belongs' => array( array( 'belongsTo' => 'customPostType', 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
			'boxes'   => array( array( 'name' => $acpt_rbox, 'label' => 'Box', 'fields' => array(
				array( 'name' => 'note', 'type' => 'Text', 'label' => 'Note' ),
				array( 'name' => 'links', 'type' => 'Repeater', 'label' => 'Links', 'children' => array(
					array( 'name' => 'title', 'type' => 'Text', 'label' => 'Title' ),
					array( 'name' => 'pick', 'type' => 'Select', 'label' => 'Pick', 'options' => array( array( 'value' => 'x', 'label' => 'X', 'sort' => 1, 'isDefault' => false ) ) ),
					array( 'name' => 'url', 'type' => 'Url', 'label' => 'Link' ),
					array( 'name' => 'when', 'type' => 'Date', 'label' => 'When' ),
					array( 'name' => 'tel', 'type' => 'Phone', 'label' => 'Tel' ),
				) ),
			) ) ),
		) ) )->execute();
	} catch ( \Throwable $e ) {
		$skip( 'ACPT rows: could not build the fixture group (' . $e->getMessage() . ')' );
	}
	if ( $acpt_rgid ) {
		$acpt_rpid = wp_insert_post( array( 'post_title' => 'Minn v043 ACPT rows', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		save_acpt_meta_field_value( array( 'post_id' => $acpt_rpid, 'box_name' => $acpt_rbox, 'field_name' => 'note', 'value' => 'first' ) );
		save_acpt_meta_field_value( array( 'post_id' => $acpt_rpid, 'box_name' => $acpt_rbox, 'field_name' => 'links', 'value' => array(
			array( 'title' => 'A', 'pick' => 'x', 'url' => array( 'url' => 'https://a.example/', 'label' => 'Site A' ), 'when' => '2026-11-01', 'tel' => '+15550001' ),
			array( 'title' => 'B', 'pick' => 'x', 'url' => array( 'url' => 'https://b.example/', 'label' => 'Site B' ), 'when' => '2026-12-24', 'tel' => '+15550002' ),
			array( 'title' => 'C', 'pick' => 'x', 'url' => array( 'url' => 'https://c.example/', 'label' => 'Site C' ), 'when' => '2027-01-01', 'tel' => '+15550003' ),
		) ) );
		// A backslash in a row, stored the way ACPT's own form would keep it
		// (ACPT's setter unslashes on the way in, so it is placed directly).
		$acpt_rkey = $acpt_rbox . '_links';
		$acpt_rraw = get_post_meta( $acpt_rpid, $acpt_rkey, true );
		if ( isset( $acpt_rraw['title'][1]['value'] ) ) {
			$acpt_rraw['title'][1]['value'] = 'C:\\path\\B';
			update_post_meta( $acpt_rpid, $acpt_rkey, wp_slash( $acpt_rraw ) );
		}
		// The stored bytes, straight from the table.
		$acpt_rbytes = function () use ( $wpdb, &$acpt_rpid, $acpt_rkey ) {
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $acpt_rpid, $acpt_rkey ) );
		};
		$acpt_rbefore = $acpt_rbytes();
		$acpt_rmodel  = null;
		$acpt_rnote   = '';
		foreach ( \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $acpt_rgid ) )[0]->getBoxes() as $b ) {
			foreach ( $b->getFields() as $f ) {
				if ( 'links' === $f->getName() ) {
					$acpt_rmodel = $f;
				}
				if ( 'note' === $f->getName() ) {
					$acpt_rnote = $f->getId();
				}
			}
		}
		if ( $acpt_rmodel && false !== strpos( $acpt_rbefore, 'C:\\path\\B' ) ) {
			$acpt_rshape = null;
			foreach ( minn_admin_acpt_fields_payload( $acpt_rpid, 'post' )['groups'] as $g ) {
				if ( false !== strpos( $g['group'], 'Minn v043 ACPT rows' ) ) {
					$acpt_rshape = $g;
				}
			}
			$check( 'ACPT rows: a repeater is counted as locked, not offered', $acpt_rshape && 1 === $acpt_rshape['locked'] && ! in_array( $acpt_rmodel->getId(), wp_list_pluck( $acpt_rshape['fields'], 'name' ), true ),
				wp_json_encode( $acpt_rshape ) );
			list( , $acpt_rd ) = $call( 'GET', '/wp/v2/posts/' . $acpt_rpid, null, array( 'context' => 'edit' ) );
			$acpt_rserved = json_decode( wp_json_encode( $acpt_rd['minn_acpt'] ?? array() ), true );
			$check( 'ACPT rows: the repeater is not in the panel values', ! array_key_exists( $acpt_rmodel->getId(), $acpt_rserved ), wp_json_encode( array_keys( $acpt_rserved ) ) );

			// What a tab opened before the update still sends: the rows it was
			// served, with one row edited, row A removed (the B,C,C case), a
			// select sub outside its choices, and the note edited beside it.
			$acpt_rsend = $acpt_rserved;
			$acpt_rsend[ $acpt_rnote ] = 'second';
			$acpt_rsend[ $acpt_rmodel->getId() ] = array(
				array( '__idx' => 1, 'values' => array( 'title' => 'B edited', 'pick' => 'evil', 'when' => '', 'tel' => '', 'url' => 'javascript:alert(1)' ) ),
				array( '__idx' => 2, 'values' => array( 'title' => 'C' ) ),
			);
			// A row write that throws is a failure to record, not a reason to
			// stop the run before its cleanup.
			$acpt_rst = 0;
			$acpt_rex = '';
			try {
				list( $acpt_rst ) = $call( 'POST', '/wp/v2/posts/' . $acpt_rpid, array( 'minn_acpt' => $acpt_rsend ) );
			} catch ( \Throwable $e ) {
				$acpt_rex = get_class( $e ) . ': ' . $e->getMessage();
			}
			$check( 'ACPT rows: a row save through the panel body changes nothing stored (edit, removal, backslash, bad choice)', '' === $acpt_rex && $acpt_rbefore === $acpt_rbytes(),
				'' !== $acpt_rex ? 'threw ' . $acpt_rex : ( $acpt_rbefore === $acpt_rbytes() ? 'byte-identical' : wp_json_encode( get_acpt_field( array( 'post_id' => $acpt_rpid, 'box_name' => $acpt_rbox, 'field_name' => 'links', 'format' => 'only_value', 'return' => 'raw' ) ) ) ) );
			$acpt_rnote_now = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $acpt_rpid, $acpt_rbox . '_note' ) );
			$check( 'ACPT rows: the field edited beside it still saves (control)', 200 === $acpt_rst && 'second' === $acpt_rnote_now, $acpt_rst . ' ' . var_export( $acpt_rnote_now, true ) );

			// The writer option pages share refuses a repeater too.
			$acpt_rex = '';
			try {
				minn_admin_acpt_write_one( $acpt_rmodel, array( array( 'values' => array( 'title' => 'Z' ) ) ), array( 'post_id' => $acpt_rpid, 'box_name' => $acpt_rbox, 'field_name' => 'links' ) );
			} catch ( \Throwable $e ) {
				$acpt_rex = get_class( $e ) . ': ' . $e->getMessage();
			}
			$check( 'ACPT rows: the shared writer never writes a repeater', '' === $acpt_rex && $acpt_rbefore === $acpt_rbytes(), '' !== $acpt_rex ? 'threw ' . $acpt_rex : 'post context' );
		} else {
			$skip( 'ACPT rows: fixture repeater not seeded' );
		}
		wp_delete_post( $acpt_rpid, true );
	}
	if ( $acpt_rgid ) {
		( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $acpt_rgid ) )->execute();
	}
} else {
	$skip( 'ACPT rows: ACPT inactive' );
}

// --- #26 delta: an offer slugged with Minn's Update URI, and one listed late in another transient ---
// In a closure of its own: the probe bails with return.
( function () use ( $check, $skip, $call, $admin ) {
	/**
	 * Delta probe: the #26 language-pack gate. PASS = the gate holds for that
	 * input; FAIL = a non-release pack for Minn gets through (or is offered).
	 * Offline: pre_http_request answers every URL here and refuses the rest.
	 * Run: wp eval-file ../harness.php p1-updater.php --user=admin --path=<site>
	 */
	global $wpdb;
	$p1_upd = null;
	foreach ( (array) ( $GLOBALS['wp_filter']['upgrader_pre_download']->callbacks ?? array() ) as $p1_cbs ) {
		foreach ( $p1_cbs as $p1_cb ) {
			if ( is_array( $p1_cb['function'] ) && $p1_cb['function'][0] instanceof Minn_Admin_Updater ) {
				$p1_upd = $p1_cb['function'][0];
			}
		}
	}
	if ( ! $p1_upd || ! class_exists( 'ZipArchive' ) ) {
		$skip( 'p1: no updater instance or ZipArchive' );
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';

	$p1_dir = trailingslashit( get_temp_dir() ) . 'minn-delta-p1-' . wp_generate_password( 6, false, false );
	wp_mkdir_p( $p1_dir );
	$p1_zip = $p1_dir . '/pack.zip';
	$p1_z   = new ZipArchive();
	$p1_z->open( $p1_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$p1_z->addFromString( 'minn-admin-zz_ZZ.l10n.php', "<?php\n// minn-admin delta probe pack (inert)\nreturn array( 'messages' => array() );\n" );
	$p1_z->close();
	$p1_foreign = 'https://cdn.example.test/gh-updater/minn-admin/zz_ZZ.zip';
	$p1_landed  = WP_LANG_DIR . '/plugins/minn-admin-zz_ZZ.l10n.php';
	$p1_landed_themes = WP_LANG_DIR . '/themes/minn-admin-zz_ZZ.l10n.php';
	$p1_sweep   = function () use ( $p1_landed, $p1_landed_themes ) {
		foreach ( array( $p1_landed, $p1_landed_themes ) as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
	};
	$p1_pre_existing = file_exists( $p1_landed ) || file_exists( $p1_landed_themes );
	if ( $p1_pre_existing ) {
		$skip( 'p1: a zz_ZZ Minn pack already exists; refusing to touch it' );
		return;
	}

	// Raw rows for everything this probe can write, restored byte for byte.
	$p1_rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ('_site_transient_update_plugins','_site_transient_timeout_update_plugins','_transient_minn_admin_updater','_transient_timeout_minn_admin_updater')", ARRAY_A );
	$p1_had  = wp_list_pluck( $p1_rows, 'option_name' );

	$p1_api = 0;
	$p1_http = function ( $pre, $args, $url ) use ( $p1_zip, $p1_foreign, &$p1_api ) {
		if ( $url === $p1_foreign ) {
			if ( ! empty( $args['filename'] ) ) {
				copy( $p1_zip, $args['filename'] );
			}
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		}
		if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
			++$p1_api;
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return new WP_Error( 'minn_delta_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $p1_http, PHP_INT_MAX, 3 );

	// ---- A. A GitHub updater answering for Minn's Update URI host -------------
	// Minn's header says Update URI: https://github.com/austinginder/minn-admin,
	// so core asks update_plugins_github.com about Minn. An updater that answers
	// with translations but no `slug` gets slug = $update->id = the Update URI
	// (wp-includes/update.php:578-584).
	$p1_hostfilter = function ( $update, $plugin_data, $plugin_file ) use ( $p1_foreign ) {
		if ( 'minn-admin/minn-admin.php' !== $plugin_file ) {
			return $update;
		}
		return array(
			'version'      => MINN_ADMIN_VERSION,
			'url'          => 'https://github.com/austinginder/minn-admin',
			'package'      => '',
			'translations' => array(
				array( 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $p1_foreign, 'autoupdate' => true ),
			),
		);
	};
	$p1_locales = function ( $l ) {
		$l[] = 'zz_ZZ';
		return $l;
	};
	add_filter( 'update_plugins_github.com', $p1_hostfilter, 10, 3 );
	add_filter( 'plugins_update_check_locales', $p1_locales );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	remove_filter( 'update_plugins_github.com', $p1_hostfilter, 10 );
	$p1_t     = get_site_transient( 'update_plugins' ); // through Minn's update() scrub
	$p1_offer = null;
	foreach ( (array) ( $p1_t->translations ?? array() ) as $p1_e ) {
		$p1_e = (array) $p1_e;
		if ( ( $p1_e['package'] ?? '' ) === $p1_foreign ) {
			$p1_offer = $p1_e;
		}
	}
	printf( "INFO  A: core called the mocked update-check %d time(s); offer after Minn's scrub: %s\n", $p1_api, wp_json_encode( $p1_offer ) );
	$check( 'A1 the Update-URI-slugged foreign pack is scrubbed from the transient', null === $p1_offer, $p1_offer ? 'slug=' . $p1_offer['slug'] . ' sanitize_key=' . sanitize_key( $p1_offer['slug'] ) : '' );
	if ( $p1_offer ) {
		$p1_lpu   = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$p1_extra = array( 'language_update_type' => 'plugin', 'language_update' => (object) $p1_offer );
		$p1_r     = $p1_upd->verify_package( false, $p1_foreign, $p1_lpu, $p1_extra );
		$check( 'A2 verify_package refuses it', is_wp_error( $p1_r ), var_export( $p1_r, true ) );
		// End to end: core's own bulk upgrade of exactly what the transient offers.
		$p1_skin = new Automatic_Upgrader_Skin();
		$p1_lpu2 = new Language_Pack_Upgrader( $p1_skin );
		$p1_res = $p1_lpu2->bulk_upgrade( array( (object) $p1_offer ), array( 'clear_update_cache' => false ) );
		$p1_in  = file_exists( $p1_landed );
		printf( "INFO  A3 result: %s | skin: %s\n", wp_json_encode( $p1_res ), wp_json_encode( $p1_skin->get_upgrade_messages() ) );
		$check( 'A3 core\'s Language_Pack_Upgrader does not install it into WP_LANG_DIR/plugins', ! $p1_in, $p1_in ? 'landed: ' . $p1_landed : '' );
		$p1_sweep();
	}

	// ---- B. A plugin-typed Minn pack listed in the THEMES transient -----------
	// wp_get_translation_updates() merges translations from update_core,
	// update_plugins AND update_themes, and the type inside each entry decides
	// the destination; update() only filters site_transient_update_plugins.
	$p1_themes = function ( $t ) use ( $p1_foreign ) {
		if ( ! is_object( $t ) ) {
			$t = new stdClass();
		}
		$t->translations   = isset( $t->translations ) && is_array( $t->translations ) ? $t->translations : array();
		$t->translations[] = array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $p1_foreign, 'autoupdate' => true );
		return $t;
	};
	add_filter( 'site_transient_update_themes', $p1_themes, 99 );
	$p1_listed = false;
	foreach ( wp_get_translation_updates() as $p1_u ) {
		if ( ( $p1_u->package ?? '' ) === $p1_foreign && 'minn-admin' === ( $p1_u->slug ?? '' ) ) {
			$p1_listed = true;
		}
	}
	remove_filter( 'site_transient_update_themes', $p1_themes, 99 );
	$check( 'B1 a Minn pack listed via update_themes is not offered (scrub covers it)', ! $p1_listed, $p1_listed ? 'offered by wp_get_translation_updates()' : '' );
	$p1_lpu = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
	$p1_r   = $p1_upd->verify_package( false, $p1_foreign, $p1_lpu, array( 'language_update_type' => 'plugin', 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ', 'package' => $p1_foreign ) ) );
	$check( 'B2 ...but its download is refused', is_wp_error( $p1_r ), var_export( $p1_r, true ) );

	// ---- C. Slug and type variants straight at the gate ------------------------
	foreach ( array(
		'C1 slug MINN-ADMIN'                => array( 'plugin', 'MINN-ADMIN' ),
		'C2 slug with tab/newline'          => array( 'plugin', "\tminn-admin\n" ),
		'C3 slug minn-admin/ (dir style)'   => array( 'plugin', 'minn-admin/' ),
		'C4 slug minn-admin/minn-admin.php' => array( 'plugin', 'minn-admin/minn-admin.php' ),
		'C5 slug minn_admin'                => array( 'plugin', 'minn_admin' ),
		'C6 type Plugin (capital)'          => array( 'Plugin', 'minn-admin' ),
		'C7 type theme'                     => array( 'theme', 'minn-admin' ),
		'C8 type array'                     => array( array( 'plugin' ), 'minn-admin' ),
		'C9 slug = Update URI'              => array( 'plugin', 'https://github.com/austinginder/minn-admin' ),
	) as $p1_label => $p1_v ) {
		$p1_r = $p1_upd->verify_package( false, $p1_foreign, $p1_lpu, array( 'language_update' => (object) array( 'type' => $p1_v[0], 'slug' => $p1_v[1], 'language' => 'zz_ZZ', 'package' => $p1_foreign ) ) );
		// Where core would put it: 'plugin' === type -> /plugins, 'theme' -> /themes, else WP_LANG_DIR.
		$p1_dest = 'plugin' === $p1_v[0] ? 'plugins' : ( 'theme' === $p1_v[0] ? 'themes' : 'root' );
		printf( "INFO  %s -> %s (core destination: %s)\n", $p1_label, is_wp_error( $p1_r ) ? 'refused' : 'PASSES THROUGH', $p1_dest );
	}
	// Array offer instead of object in hook_extra.
	$p1_r = $p1_upd->verify_package( false, $p1_foreign, $p1_lpu, array( 'language_update' => array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ' ) ) );
	$check( 'C10 an array (not object) language_update is still Minn\'s', is_wp_error( $p1_r ) );
	// No hook_extra at all (a caller that does not pass it): only the URL decides.
	$p1_r = $p1_upd->verify_package( false, $p1_foreign, $p1_lpu, array() );
	printf( "INFO  C11 no hook_extra -> %s (gate depends on hook_extra)\n", is_wp_error( $p1_r ) ? 'refused' : 'passes through' );

	// ---- D. Filter that answered first, Batch-style prefetch order -------------
	$p1_r = $p1_upd->verify_package( $p1_zip, $p1_foreign, $p1_lpu, array( 'language_update' => (object) array( 'type' => 'plugin', 'slug' => 'minn-admin', 'language' => 'zz_ZZ' ) ) );
	$check( 'D1 a file an earlier filter supplies for a foreign Minn pack is refused', is_wp_error( $p1_r ) );
	$p1_order = array();
	foreach ( (array) ( $GLOBALS['wp_filter']['upgrader_pre_download']->callbacks[ PHP_INT_MAX ] ?? array() ) as $p1_id => $p1_cb ) {
		$p1_order[] = is_array( $p1_cb['function'] ) ? get_class( $p1_cb['function'][0] ) . '::' . $p1_cb['function'][1] : ( $p1_cb['function'] instanceof Closure ? 'closure' : (string) $p1_cb['function'] );
	}
	printf( "INFO  D2 PHP_INT_MAX upgrader_pre_download order now: %s\n", implode( ' , ', $p1_order ) );

	// ---- cleanup --------------------------------------------------------------
	remove_filter( 'plugins_update_check_locales', $p1_locales );
	remove_filter( 'pre_http_request', $p1_http, PHP_INT_MAX );
	$p1_sweep();
	foreach ( array( '_site_transient_update_plugins', '_site_transient_timeout_update_plugins', '_transient_minn_admin_updater', '_transient_timeout_minn_admin_updater' ) as $p1_k ) {
		if ( ! in_array( $p1_k, $p1_had, true ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $p1_k ) );
		}
	}
	foreach ( $p1_rows as $p1_row ) {
		$p1_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $p1_row['option_name'] ) );
		if ( $p1_exists ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $p1_row['option_value'], 'autoload' => $p1_row['autoload'] ), array( 'option_name' => $p1_row['option_name'] ) );
		} else {
			$wpdb->insert( $wpdb->options, $p1_row );
		}
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( array( '_site_transient_update_plugins', '_site_transient_timeout_update_plugins', '_transient_minn_admin_updater', '_transient_timeout_minn_admin_updater' ) as $p1_k ) {
		wp_cache_delete( $p1_k, 'options' );
	}
	foreach ( (array) glob( $p1_dir . '/*' ) as $p1_f ) {
		wp_delete_file( $p1_f );
	}
	@rmdir( $p1_dir ); // phpcs:ignore
	$p1_after = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ('_site_transient_update_plugins','_site_transient_timeout_update_plugins','_transient_minn_admin_updater','_transient_timeout_minn_admin_updater')", ARRAY_A );
	printf( "INFO  restore: rows byte-identical = %s\n", md5( serialize( $p1_rows ) ) === md5( serialize( $p1_after ) ) ? 'yes' : 'NO' );
} )();

// --- #26 delta: Minn's own Update Translations refuses the Update-URI-slugged pack ---
// In a closure of its own: the probe bails with return.
( function () use ( $check, $skip, $call, $admin ) {
	/**
	 * Delta probe: Minn's own Update Translations button (POST
	 * /minn-admin/v1/translations/update, Minn_Admin_Batch::run_translations)
	 * with the offer core builds when a github.com updater answers for Minn's
	 * Update URI without a slug. Offline; transients answered in-process only.
	 */
	if ( ! current_user_can( 'update_languages' ) || ! class_exists( 'ZipArchive' ) ) {
		$skip( 'p1b: no update_languages or ZipArchive' );
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$p1b_dir = trailingslashit( get_temp_dir() ) . 'minn-delta-p1b-' . wp_generate_password( 6, false, false );
	wp_mkdir_p( $p1b_dir );
	$p1b_zip = $p1b_dir . '/pack.zip';
	$p1b_z   = new ZipArchive();
	$p1b_z->open( $p1b_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$p1b_z->addFromString( 'minn-admin-zz_ZZ.l10n.php', "<?php\n// minn-admin delta probe pack (inert)\nreturn array( 'messages' => array() );\n" );
	$p1b_z->close();
	$p1b_foreign = 'https://cdn.example.test/gh-updater/minn-admin/zz_ZZ.zip';
	$p1b_landed  = WP_LANG_DIR . '/plugins/minn-admin-zz_ZZ.l10n.php';
	if ( file_exists( $p1b_landed ) ) {
		$skip( 'p1b: a zz_ZZ Minn pack already exists' );
		return;
	}
	$p1b_http = function ( $pre, $args, $url ) use ( $p1b_zip, $p1b_foreign ) {
		if ( $url === $p1b_foreign ) {
			if ( ! empty( $args['filename'] ) ) {
				copy( $p1b_zip, $args['filename'] );
			}
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		}
		return new WP_Error( 'minn_delta_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $p1b_http, PHP_INT_MAX, 3 );
	$p1b_offer = array( 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $p1b_foreign, 'autoupdate' => true, 'type' => 'plugin', 'slug' => 'https://github.com/austinginder/minn-admin' );
	$p1b_plugins = function () use ( $p1b_offer ) {
		$t               = new stdClass();
		$t->last_checked = time();
		$t->checked      = wp_list_pluck( get_plugins(), 'Version' );
		$t->response     = array();
		$t->no_update    = array();
		$t->translations = array( $p1b_offer );
		return $t;
	};
	$p1b_themes = function () {
		$t               = new stdClass();
		$t->last_checked = time();
		$t->checked      = array();
		foreach ( wp_get_themes() as $ss => $th ) {
			$t->checked[ $ss ] = $th->get( 'Version' );
		}
		$t->response     = array();
		$t->no_update    = array();
		$t->translations = array();
		return $t;
	};
	$p1b_core = function () {
		return (object) array( 'last_checked' => time(), 'version_checked' => get_bloginfo( 'version' ), 'updates' => array(), 'translations' => array() );
	};
	$p1b_loc = function ( $l ) {
		$l[] = 'zz_ZZ';
		return $l;
	};
	add_filter( 'plugins_update_check_locales', $p1b_loc );
	add_filter( 'pre_site_transient_update_plugins', $p1b_plugins );
	add_filter( 'pre_site_transient_update_themes', $p1b_themes );
	add_filter( 'pre_site_transient_update_core', $p1b_core );
	// Raw rows the route's clear_update_cache may touch, restored exactly.
	global $wpdb;
	$p1b_rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%update\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%update\\_%'", ARRAY_A );
	list( $p1b_st, $p1b_body ) = $call( 'POST', '/minn-admin/v1/translations/update' );
	$p1b_in = file_exists( $p1b_landed );
	$check( 'T1 Update Translations does not install the Update-URI-slugged foreign pack', ! $p1b_in, $p1b_st . ' ' . wp_json_encode( is_array( $p1b_body ) ? array_intersect_key( $p1b_body, array_flip( array( 'updated', 'failed', 'errors' ) ) ) : $p1b_body ) );
	if ( $p1b_in ) {
		wp_delete_file( $p1b_landed );
	}
	remove_filter( 'plugins_update_check_locales', $p1b_loc );
	remove_filter( 'pre_site_transient_update_plugins', $p1b_plugins );
	remove_filter( 'pre_site_transient_update_themes', $p1b_themes );
	remove_filter( 'pre_site_transient_update_core', $p1b_core );
	remove_filter( 'pre_http_request', $p1b_http, PHP_INT_MAX );
	$p1b_names = wp_list_pluck( $p1b_rows, 'option_name' );
	$p1b_now   = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%update\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%update\\_%'", ARRAY_A );
	foreach ( wp_list_pluck( $p1b_now, 'option_name' ) as $n ) {
		if ( ! in_array( $n, $p1b_names, true ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $n ) );
		}
	}
	foreach ( $p1b_rows as $r ) {
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $r['option_name'] ) ) ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $r['option_value'], 'autoload' => $r['autoload'] ), array( 'option_name' => $r['option_name'] ) );
		} else {
			$wpdb->insert( $wpdb->options, $r );
		}
	}
	wp_cache_flush();
	$p1b_after = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%update\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%update\\_%'", ARRAY_A );
	$p1b_key = function ( $rows ) {
		usort( $rows, function ( $a, $b ) {
			return strcmp( $a['option_name'], $b['option_name'] );
		} );
		return md5( serialize( $rows ) );
	};
	foreach ( (array) glob( $p1b_dir . '/*' ) as $f ) {
		wp_delete_file( $f );
	}
	@rmdir( $p1b_dir ); // phpcs:ignore
	printf( "INFO  restore: update transients identical = %s; stray pack = %s\n", $p1b_key( $p1b_rows ) === $p1b_key( $p1b_after ) ? 'yes' : 'NO', file_exists( $p1b_landed ) ? 'LEFT' : 'none' );
} )();

// --- #3 delta: a shared Custom CSS & JS slug in another language is left alone ---
// In a closure of its own: the probe bails with return.
( function () use ( $check, $skip, $call, $admin ) {
	/**
	 * Delta probe: CCJ permalink copies (#3). CCJ never makes a Permalink slug
	 * unique (admin-screens.php:1686-1691), and its own delete removes only
	 * <slug>.<the snippet's language> (1751-1757). Minn's helpers take all three
	 * languages. CCJ_UPLOAD_DIR is pointed at a scratch folder for this process
	 * when CCJ is not loaded, so no real upload is touched.
	 */
	if ( defined( 'CCJ_UPLOAD_DIR' ) ) {
		$skip( 'p6: CCJ is loaded here; not touching its real upload folder' );
		return;
	}
	$p6_dir = dirname( __FILE__ ) . '/ccj-upload';
	wp_mkdir_p( $p6_dir );
	define( 'CCJ_UPLOAD_DIR', $p6_dir );
	$p6_slug = 'brand-' . wp_generate_password( 6, false, false );
	$p6_mk   = function ( $lang ) use ( $p6_slug, $admin ) {
		$id = wp_insert_post( array( 'post_title' => 'p6 ' . $lang, 'post_type' => 'custom-css-js', 'post_status' => 'publish', 'post_author' => $admin, 'post_content' => '/* ' . $lang . ' */' ) );
		update_post_meta( $id, 'options', array( 'language' => $lang, 'type' => 'header', 'side' => 'frontend', 'linking' => 'external', 'priority' => 5 ) );
		update_post_meta( $id, '_slug', $p6_slug );
		return $id;
	};
	$p6_css = $p6_mk( 'css' ); // snippet A, writes brand-x.css
	$p6_js  = $p6_mk( 'js' );  // snippet B, same slug, writes brand-x.js
	foreach ( array( $p6_css . '.css', $p6_js . '.js', $p6_slug . '.css', $p6_slug . '.js' ) as $p6_f ) {
		file_put_contents( $p6_dir . '/' . $p6_f, 'bytes of ' . $p6_f );
	}
	minn_admin_ccj_drop_files( $p6_css ); // Minn's delete / switch-off of A
	$check( 'P1 deleting the CSS snippet leaves the JS snippet\'s permalink copy (CCJ\'s own delete would)', is_file( $p6_dir . '/' . $p6_slug . '.js' ), is_file( $p6_dir . '/' . $p6_slug . '.js' ) ? '' : $p6_slug . '.js deleted' );
	$check( 'P2 control: the CSS snippet\'s own copies go', ! is_file( $p6_dir . '/' . $p6_slug . '.css' ) && ! is_file( $p6_dir . '/' . $p6_css . '.css' ) );
	// write_file (any Minn code edit) does the same sweep.
	file_put_contents( $p6_dir . '/' . $p6_slug . '.js', 'bytes again' );
	minn_admin_ccj_write_file( $p6_css, '/* edited */' );
	$check( 'P3 editing the CSS snippet\'s code leaves the JS snippet\'s permalink copy', is_file( $p6_dir . '/' . $p6_slug . '.js' ) );

	// ---- cleanup ----
	wp_delete_post( $p6_css, true );
	wp_delete_post( $p6_js, true );
	foreach ( (array) glob( $p6_dir . '/*' ) as $p6_f ) {
		wp_delete_file( $p6_f );
	}
	@rmdir( $p6_dir ); // phpcs:ignore
	printf( "INFO  cleanup: dir gone = %s, posts gone = %s\n", is_dir( $p6_dir ) ? 'NO' : 'yes', ( get_post( $p6_css ) || get_post( $p6_js ) ) ? 'NO' : 'yes' );
} )();

// --- #3 delta: a language change takes the old permalink copy, never the new name ---
// In a closure of its own: the probe bails with return.
( function () use ( $check, $skip, $call, $admin ) {
	/**
	 * Round-2 probe for fa599fd6 (CCJ permalink copy, own language only), through
	 * Minn's REST routes. CCJ is not loaded here, so CCJ_UPLOAD_DIR points at a
	 * scratch folder and the post type is registered for this process only.
	 */
	global $wpdb;
	if ( defined( 'CCJ_UPLOAD_DIR' ) ) {
		$skip( 'r2c: CCJ loaded; not touching its upload folder' );
		return;
	}
	$dir = __DIR__ . '/ccj-upload-' . wp_generate_password( 6, false, false );
	wp_mkdir_p( $dir );
	define( 'CCJ_UPLOAD_DIR', $dir );
	if ( ! post_type_exists( 'custom-css-js' ) ) {
		register_post_type( 'custom-css-js', array( 'public' => false ) );
	}
	$tree_row = $wpdb->get_row( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = 'custom-css-js-tree'", ARRAY_A );
	$wsal     = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wsal_occurrences'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsal_occurrences" ) : null;
	$routes   = rest_get_server()->get_routes();
	if ( ! isset( $routes['/minn-admin/v1/ccj/snippets/(?P<id>\d+)'] ) ) {
		// rest_api_init already ran with CCJ inactive: run Minn's CCJ registrar now.
		foreach ( (array) ( $GLOBALS['wp_filter']['rest_api_init']->callbacks ?? array() ) as $cbs ) {
			foreach ( $cbs as $cb ) {
				if ( $cb['function'] instanceof Closure ) {
					$rf = new ReflectionFunction( $cb['function'] );
					if ( false !== strpos( (string) $rf->getFileName(), 'adapters/custom-css-js.php' ) ) {
						call_user_func( $cb['function'] );
					}
				}
			}
		}
		$routes = rest_get_server()->get_routes();
	}
	$made     = array();
	$cleanup  = function () use ( &$made, $dir, $wpdb, $tree_row ) {
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // phpcs:ignore
		if ( $tree_row ) {
			$wpdb->update( $wpdb->options, $tree_row, array( 'option_name' => 'custom-css-js-tree' ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'custom-css-js-tree' ) );
		}
		wp_cache_flush();
	};
	if ( ! isset( $routes['/minn-admin/v1/ccj/snippets/(?P<id>\d+)'] ) ) {
		$skip( 'r2c: Minn CCJ routes not registered' );
		$cleanup();
		return;
	}
	$tag = strtolower( wp_generate_password( 5, false, false ) );
	$mk  = function ( $lang, $slug, $bytes ) use ( &$made, $admin, $dir ) {
		$id = wp_insert_post( array( 'post_title' => 'r2c ' . $lang, 'post_type' => 'custom-css-js', 'post_status' => 'publish', 'post_author' => $admin, 'post_content' => '/* ' . $bytes . ' */' ) );
		update_post_meta( $id, 'options', array( 'language' => $lang, 'type' => 'header', 'side' => 'frontend', 'linking' => 'external', 'priority' => 5 ) );
		update_post_meta( $id, '_slug', $slug );
		update_post_meta( $id, '_active', 'yes' );
		// What CCJ's own save leaves on disk: <id>.<lang> and <slug>.<lang>.
		file_put_contents( $dir . '/' . $id . '.' . $lang, $bytes );
		file_put_contents( $dir . '/' . $slug . '.' . $lang, $bytes );
		$made[] = $id;
		return $id;
	};

	// L1: a snippet's language changed in Minn (js -> css). Its permalink copy
	// under the OLD name holds the pre-change code at a public URL.
	$s1 = 'r2c-a-' . $tag;
	$a  = $mk( 'js', $s1, 'A-OLD-JS' );
	list( $st ) = $call( 'PUT', '/minn-admin/v1/ccj/snippets/' . $a, array( 'language' => 'css' ) );
	$check( 'L1a control: PUT language js->css saved', 200 === $st && 'css' === minn_admin_ccj_get_options( $a )['language'], (string) $st );
	$check( 'L1b the old <slug>.js copy (pre-change code) is removed, as the handler comment promises', ! is_file( $dir . '/' . $s1 . '.js' ), is_file( $dir . '/' . $s1 . '.js' ) ? $s1 . '.js still public: ' . file_get_contents( $dir . '/' . $s1 . '.js' ) : '' );
	$check( 'L1c control: the old <id>.js went', ! is_file( $dir . '/' . $a . '.js' ) );
	list( $st ) = $call( 'DELETE', '/minn-admin/v1/ccj/snippets/' . $a );
	$check( 'L1d deleting the snippet afterwards removes every copy it published', ! is_file( $dir . '/' . $s1 . '.js' ), is_file( $dir . '/' . $s1 . '.js' ) ? 'orphaned: ' . $s1 . '.js (DELETE ' . $st . ')' : '' );

	// L2: the language change lands on a name another snippet owns (B: css, same slug).
	$s2 = 'r2c-b-' . $tag;
	$a2 = $mk( 'js', $s2, 'A2-JS' );
	$b2 = $mk( 'css', $s2, 'B2-CSS-COPY' );
	$call( 'PUT', '/minn-admin/v1/ccj/snippets/' . $a2, array( 'language' => 'css' ) );
	printf( "INFO  L2 js->css on A while B (css) owns %s.css: B's copy %s; A's old %s.js %s\n", $s2, is_file( $dir . '/' . $s2 . '.css' ) ? 'kept' : 'DELETED', $s2, is_file( $dir . '/' . $s2 . '.js' ) ? 'LEFT' : 'removed' );

	// L3: the fix's own case: code edit / delete on CSS while JS owns the same slug.
	$s3 = 'r2c-c-' . $tag;
	$a3 = $mk( 'css', $s3, 'A3-CSS' );
	$b3 = $mk( 'js', $s3, 'B3-JS-COPY' );
	$call( 'PUT', '/minn-admin/v1/ccj/snippets/' . $a3, array( 'code' => '/* edited */' ) );
	$check( 'L3a code edit on the CSS snippet drops its own <slug>.css', ! is_file( $dir . '/' . $s3 . '.css' ) );
	$check( 'L3b ...and leaves the JS snippet\'s <slug>.js', is_file( $dir . '/' . $s3 . '.js' ) && 'B3-JS-COPY' === file_get_contents( $dir . '/' . $s3 . '.js' ) );
	file_put_contents( $dir . '/' . $s3 . '.css', 'A3-CSS' );
	$call( 'POST', '/minn-admin/v1/ccj/snippets/' . $a3 . '/active', array( 'active' => false ) );
	$check( 'L3c switch-off drops <slug>.css, keeps <slug>.js', ! is_file( $dir . '/' . $s3 . '.css' ) && is_file( $dir . '/' . $s3 . '.js' ) );
	$call( 'DELETE', '/minn-admin/v1/ccj/snippets/' . $a3 );
	$check( 'L3d delete keeps the JS snippet\'s <slug>.js', is_file( $dir . '/' . $s3 . '.js' ) );

	// L4: same slug, same language (CCJ parity: its own delete does the same).
	$s4 = 'r2c-d-' . $tag;
	$a4 = $mk( 'css', $s4, 'A4' );
	$c4 = $mk( 'css', $s4, 'C4-LAST-SAVED' );
	$call( 'DELETE', '/minn-admin/v1/ccj/snippets/' . $a4 );
	printf( "INFO  L4 delete A while C (same slug, same language, saved last) owns %s.css: %s\n", $s4, is_file( $dir . '/' . $s4 . '.css' ) ? 'kept' : 'deleted (CCJ before_delete_post does the same)' );

	$cleanup();
	$wsal_after = null !== $wsal ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wsal_occurrences" ) : null;
	$left       = 0;
	foreach ( $made as $id ) {
		$left += get_post( $id ) ? 1 : 0;
	}
	printf( "INFO  cleanup: dir gone = %s, posts left = %d, tree option restored = %s, wsal rows added = %s\n", is_dir( $dir ) ? 'NO' : 'yes', $left, ( $tree_row ? $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'custom-css-js-tree'" ) === $tree_row['option_value'] : null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'custom-css-js-tree'" ) ) ? 'yes' : 'NO', null === $wsal ? 'n/a' : (string) ( $wsal_after - $wsal ) );
} )();

// @sections

// Flamingo files a contact for every user a section creates and keeps it
// after the user is deleted; the fixtures' leftovers go here.
if ( post_type_exists( 'flamingo_contact' ) ) {
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'flamingo_contact' AND post_title LIKE 'minn-%@example.com'" ) as $minn_contact ) {
		if ( ! get_user_by( 'email', $minn_contact->post_title ) ) {
			wp_delete_post( (int) $minn_contact->ID, true );
		}
	}
}

$summary();
