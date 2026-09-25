/**
 * Rank Math Redirections + 404 Monitor — redirects-family surface.
 *
 * Proves: the surface appears only while Rank Math has its modules loaded;
 * Add redirect creates a rule the LIVE site serves; the self-loop guard
 * refuses; a detail edit that changes only the type keeps target and source;
 * a real logged-out 404 lands in the 404 log view, and its Redirect… action
 * makes that address redirect for real; the status card counts both modules;
 * an Editor is refused; zero console errors.
 *
 * Fixture state: Rank Math loads NO module until the site is connected to a
 * Rank Math account or the setup step is skipped, so the suite sets
 * rank_math_registration_skip (their own skip flag) for the run and ensures
 * the 404-monitor + redirections modules are on through their
 * Helper::update_modules(). Everything is restored to what the run found.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

// PHP rides stdin (eval-file -) so the shell never expands its $vars.
const wpPhp = ( php, user = 'admin' ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=${ user } 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'rank-math-redirections' );
	const before = JSON.parse( wpPhp( `
		if ( ! function_exists( 'is_plugin_active' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		echo wp_json_encode( array(
			'active'  => is_plugin_active( 'seo-by-rank-math/rank-math.php' ),
			'skip'    => get_option( 'rank_math_registration_skip', null ),
			'modules' => get_option( 'rank_math_modules', null ),
		) );
	` ) );
	const installed = ( () => { try { execSync( `wp --path=${ JSON.stringify( WP ) } plugin is-installed seo-by-rank-math 2>/dev/null` ); return true; } catch ( e ) { return false; } } )();
	if ( ! installed ) {
		console.log( 'SKIP  seo-by-rank-math is not installed' );
		process.exit( 0 );
	}

	const cleanup = () => wpPhp( `
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}rank_math_redirections WHERE sources LIKE '%minn-rm-suite%'" );
		if ( $ids && class_exists( '\\\\RankMath\\\\Redirections\\\\DB' ) ) { \\RankMath\\Redirections\\DB::delete( array_map( 'intval', $ids ) ); }
		elseif ( $ids ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}rank_math_redirections WHERE sources LIKE '%minn-rm-suite%'" ); }
		$wpdb->query( "DELETE FROM {$wpdb->prefix}rank_math_404_logs WHERE uri LIKE '%minn-rm-suite%'" );
		echo 'ok';
	` );

	// Arm: plugin on, their registration skip, both modules.
	if ( ! before.active ) execSync( `wp --path=${ JSON.stringify( WP ) } plugin activate seo-by-rank-math 2>/dev/null` );
	wpPhp( "update_option( 'rank_math_registration_skip', 1 ); \\RankMath\\Helper::update_modules( array( '404-monitor' => 'on', 'redirections' => 'on' ) ); echo 'ok';" );
	cleanup();

	const { browser, page, errors } = await launch();
	const rest = ( path, opts = {} ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		}, q.opts ) );
		let body = null;
		try { body = await r.json(); } catch ( e ) {}
		return { status: r.status, body };
	}, { path, opts } );
	const frontRedirect = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.site.url.replace( /\/$/, '' ) + p, { redirect: 'manual', credentials: 'omit', cache: 'no-store' } );
		return { type: r.type, status: r.status };
	}, path );
	const rowWith = ( text ) => page.waitForFunction( ( s ) =>
		Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( s ) ), text, { timeout: 15000 } );
	const clickRow = ( text ) => page.evaluate( ( s ) =>
		Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( s ) ).click(), text );

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/rank-math-redirections', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-add', { timeout: 20000 } );
		t.check( 'surface renders with Add redirect', await page.evaluate( () =>
			( window.MINN.surfaces || [] ).some( ( s ) => s.id === 'rank-math-redirections' && s.family === 'redirects' ) ) );

		/* ===== Create through the modal ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="source"]', { timeout: 8000 } );
		await page.type( '[data-createfield="source"]', '/minn-rm-suite-old' );
		await page.type( '[data-createfield="url_to"]', '/minn-rm-suite-new' );
		await page.click( '#minn-surface-create' );
		await rowWith( 'minn-rm-suite-old' );
		t.check( 'created redirect appears in the list', true );
		const live = await frontRedirect( '/minn-rm-suite-old/' );
		t.check( 'the live site serves the redirect', 'opaqueredirect' === live.type || 301 === live.status, JSON.stringify( live ) );

		const loop = await rest( 'minn-admin/v1/rankmath/redirects', { method: 'POST', body: JSON.stringify( { source: '/minn-rm-suite-loop', url_to: '/minn-rm-suite-loop' } ) } );
		t.check( 'a self-redirect is refused', 400 === loop.status && 'minn_rm_loop' === ( loop.body || {} ).code, JSON.stringify( loop ) );

		/* ===== Edit only the type ===== */
		const listed = await rest( 'minn-admin/v1/rankmath/redirects?search=minn-rm-suite-old' );
		const rule = ( ( listed.body || {} ).items || [] )[ 0 ] || {};
		const edited = await rest( `minn-admin/v1/rankmath/redirects/${ rule.id }`, { method: 'POST', body: JSON.stringify( { header_code: '302' } ) } );
		const e = edited.body || {};
		t.check( 'editing only the type keeps target and source', 200 === edited.status && '302' === e.header_code && /minn-rm-suite-new/.test( e.url_to ) && /minn-rm-suite-old/.test( e.source ), JSON.stringify( e ) );

		/* ===== Imported rule: an unlisted code and a raw source survive a target-only edit ===== */
		// Their importers copy codes like 308 and sources verbatim; a second
		// pass through their sanitizer would percent-decode this one.
		const importedId = parseInt( wpPhp( `
			global $wpdb;
			$wpdb->insert( $wpdb->prefix . 'rank_math_redirections', array(
				'sources' => serialize( array( array( 'ignore' => '', 'pattern' => 'caf%C3%A9-minn-rm-suite', 'comparison' => 'exact' ) ) ),
				'url_to' => home_url( '/minn-rm-suite-imported' ), 'header_code' => 308, 'hits' => 0, 'status' => 'active',
				'created' => current_time( 'mysql' ), 'updated' => current_time( 'mysql' ),
			) );
			echo $wpdb->insert_id;
		` ), 10 );
		const imp = await rest( `minn-admin/v1/rankmath/redirects/${ importedId }`, { method: 'POST', body: JSON.stringify( { url_to: '/minn-rm-suite-imported-2', header_code: '308' } ) } );
		const impRow = JSON.parse( wpPhp( `global $wpdb; echo wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT sources, header_code, url_to FROM {$wpdb->prefix}rank_math_redirections WHERE id = %d", ${ importedId } ), ARRAY_A ) );` ) );
		t.check( 'a target-only edit keeps an imported 308 and its raw source',
			200 === imp.status && '308' === String( impRow.header_code ) && /caf%C3%A9-minn-rm-suite/.test( impRow.sources ) && /minn-rm-suite-imported-2/.test( impRow.url_to ),
			JSON.stringify( { status: imp.status, impRow } ) );
		const offList = await rest( `minn-admin/v1/rankmath/redirects/${ importedId }`, { method: 'POST', body: JSON.stringify( { header_code: '399' } ) } );
		t.check( 'a code that is neither offered nor stored is refused', 400 === offList.status && 'minn_rm_code' === ( offList.body || {} ).code, JSON.stringify( offList ) );
		// Descriptors are a boot snapshot: an import lands in the next load.
		await page.goto( BASE + '/minn-admin/rank-math-redirections', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-add', { timeout: 20000 } );
		const offered = await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'rank-math-redirections' );
			const f = s && s.collection.detail.edit.fields.find( ( x ) => x.key === 'header_code' );
			return f ? f.options.map( ( o ) => o[ 0 ] ) : [];
		} );
		t.check( 'the edit form offers the stored 308 so it seeds correctly', offered.includes( '308' ), JSON.stringify( offered ) );
		const bare = await rest( 'minn-admin/v1/rankmath/redirects', { method: 'POST', body: JSON.stringify( { source: '/', url_to: '/minn-rm-suite-x' } ) } );
		t.check( 'a source their sanitizer rejects is a clean 400', 400 === bare.status && 'minn_rm_source' === ( bare.body || {} ).code, JSON.stringify( bare ) );

		/* ===== 404 log: a real logged-out miss, then Redirect… from the view ===== */
		const miss = await page.evaluate( async ( base ) => ( await fetch( base + '/minn-rm-suite-missing/', { credentials: 'omit', cache: 'no-store' } ) ).status, BASE );
		t.check( 'a logged-out request 404s', 404 === miss, String( miss ) );
		await page.goto( BASE + '/minn-admin/rank-math-redirections', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 20000 } );
		await page.click( '[data-sview="x0"]' );
		await rowWith( 'minn-rm-suite-missing' );
		t.check( 'the 404 lands in the 404 log view', true );
		await clickRow( 'minn-rm-suite-missing' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).some( ( b ) => /Redirect…/.test( b.textContent ) ), null, { timeout: 15000 } );
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => /Redirect…/.test( b.textContent ) ).click() );
		await page.waitForSelector( '[data-actfield="url_to"]', { timeout: 8000 } );
		await page.type( '[data-actfield="url_to"]', '/minn-rm-suite-landing' );
		await page.click( '[data-actgo]' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /now redirects/.test( x.textContent ) ), null, { timeout: 15000 } );
		t.check( 'Redirect… reports the address now redirects', true );
		const fixed = await frontRedirect( '/minn-rm-suite-missing/' );
		t.check( 'the former 404 now redirects on the live site', 'opaqueredirect' === fixed.type || 301 === fixed.status, JSON.stringify( fixed ) );

		/* ===== Status card ===== */
		const status = await rest( 'minn-admin/v1/rankmath/status' );
		const labels = ( ( status.body || {} ).rows || [] ).map( ( r ) => r.label );
		t.check( 'status card counts redirects and the 404 log', [ 'Active redirects', '404s logged' ].every( ( l ) => labels.includes( l ) ), JSON.stringify( labels ) );

		/* ===== Trash + permanent delete ===== */
		const trashed = await rest( `minn-admin/v1/rankmath/redirects/${ rule.id }/status`, { method: 'POST', body: JSON.stringify( { status: 'trashed' } ) } );
		const inTrash = await rest( 'minn-admin/v1/rankmath/redirects?status=trashed&search=minn-rm-suite-old' );
		t.check( 'Move to Trash files it under the Trash tab', 200 === trashed.status && 1 === ( inTrash.body || {} ).total && '1' === inTrash.body.items[ 0 ].trashed, JSON.stringify( inTrash.body ) );
		const del = await rest( `minn-admin/v1/rankmath/redirects/${ rule.id }`, { method: 'DELETE' } );
		const gone = await rest( 'minn-admin/v1/rankmath/redirects?status=trashed&search=minn-rm-suite-old' );
		t.check( 'Delete permanently removes it', 200 === del.status && 0 === ( gone.body || {} ).total );

		/* ===== Editor is refused ===== */
		const editor = JSON.parse( wpPhp( `$r = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/rankmath/redirects' ) ); echo wp_json_encode( array( 's' => $r->get_status() ) );`, 'minn-editor' ) );
		t.check( 'an Editor gets 403', 403 === editor.s, JSON.stringify( editor ) );
	} finally {
		try { cleanup(); } catch ( e ) {}
		try {
			wpPhp( `
				$b = json_decode( base64_decode( '${ Buffer.from( JSON.stringify( before ) ).toString( 'base64' ) }' ), true );
				if ( null === $b['skip'] ) { delete_option( 'rank_math_registration_skip' ); } else { update_option( 'rank_math_registration_skip', $b['skip'] ); }
				if ( null === $b['modules'] ) { delete_option( 'rank_math_modules' ); } else { update_option( 'rank_math_modules', $b['modules'] ); }
				echo 'ok';
			` );
		} catch ( e ) {}
		if ( ! before.active ) {
			try { execSync( `wp --path=${ JSON.stringify( WP ) } plugin deactivate seo-by-rank-math 2>/dev/null` ); } catch ( e ) {}
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
