/**
 * Broken Link Checker (local engine) — the Broken links item under Tools.
 *
 * Proves: a real 404 link found by their own checker lists under Broken with
 * where it appears; the status card counts it; Dismiss moves it to Dismissed
 * and back; Edit URL (through the row's detail) rewrites the link inside the
 * post; Unlink removes the <a> and keeps the text; an Author is refused; zero
 * console errors.
 *
 * Broken Link Checker rests installed-inactive on minnadmin. The suite
 * activates it, lets one page load finish their table install, publishes a
 * fixture post, and runs their own blc_cron_check_links cron job (the CLI
 * holds no web worker, so their HTTP checks against the site are safe). The
 * post, its link rows and the plugin state are restored in finally.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null`, { timeout: 150000 } ).toString().trim();
const wpPhp = ( php, user = 'admin' ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=${ user } 2>/dev/null`, { input: '<?php ' + php, timeout: 150000 } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'broken-link-checker' );
	try { wp( 'plugin is-installed broken-link-checker' ); } catch ( e ) {
		console.log( 'SKIP  broken-link-checker is not installed' );
		process.exit( 0 );
	}
	let wasActive = false;
	try { wp( 'plugin is-active broken-link-checker' ); wasActive = true; } catch ( e ) {}
	if ( ! wasActive ) wp( 'plugin activate broken-link-checker' );
	// Their legacy engine installs its tables on the first web load.
	execSync( `curl -sk -o /dev/null -m 60 ${ JSON.stringify( BASE + '/' ) }` );
	const missing = BASE + '/minn-blc-suite-missing-' + Date.now() + '/';
	const postId = parseInt( wp( `post create --post_type=post --post_status=publish --post_title="Minn BLC suite" --post_content=${ JSON.stringify( `<p>Read <a href="${ missing }">the old guide</a> and <a href="${ BASE }/">home</a>.</p>` ) } --porcelain` ), 10 );
	for ( let i = 0; i < 3; i++ ) {
		try { wp( 'cron event run blc_cron_check_links' ); } catch ( e ) {}
	}

	const { browser, page, errors } = await launch();
	page.on( 'dialog', ( d ) => d.accept() );
	const api = ( path, opts = {} ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( { headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }, q.opts ) );
		return { status: r.status, body: await r.json() };
	}, { path, opts } );
	const content = () => wpPhp( `echo get_post( ${ postId } )->post_content;` );

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/broken-links', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		t.check( 'Broken links sits under Tools', await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'broken-links' );
			return !! s && 'tools' === s.group;
		} ) );
		await page.waitForFunction( ( m ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( m ) ), missing.replace( BASE, '' ).replace( /\/$/, '' ), { timeout: 20000 } );
		const row = await page.evaluate( ( m ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( m ) ).textContent.replace( /\s+/g, ' ' ), missing.replace( BASE, '' ).replace( /\/$/, '' ) );
		t.check( 'their checker\'s 404 lists under Broken with where it was found', /Not Found/.test( row ) && /Minn BLC suite/.test( row ), row.slice( 0, 200 ) );

		const list = await api( 'minn-admin/v1/blc/links?search=' + encodeURIComponent( 'minn-blc-suite-missing' ) );
		const link = ( list.body.items || [] )[ 0 ] || {};
		const st = await api( 'minn-admin/v1/blc/status' );
		const brokenRow = ( st.body.rows || [] ).find( ( r ) => 'Broken links' === r.label );
		t.check( 'status card counts the broken link', !! brokenRow && parseInt( brokenRow.value, 10 ) >= 1, JSON.stringify( st.body.rows ) );

		/* Dismiss and back. */
		await api( `minn-admin/v1/blc/links/${ link.id }/dismiss`, { method: 'POST', body: JSON.stringify( { dismissed: true } ) } );
		const dis = await api( 'minn-admin/v1/blc/links?filter=dismissed&search=' + encodeURIComponent( 'minn-blc-suite-missing' ) );
		const brk = await api( 'minn-admin/v1/blc/links?search=' + encodeURIComponent( 'minn-blc-suite-missing' ) );
		t.check( 'Dismiss moves it from Broken to Dismissed', 1 === dis.body.total && 0 === brk.body.total, JSON.stringify( { dismissed: dis.body.total, broken: brk.body.total } ) );
		await api( `minn-admin/v1/blc/links/${ link.id }/dismiss`, { method: 'POST', body: JSON.stringify( { dismissed: false } ) } );

		/* Edit URL through the row's detail (the parameterized action). */
		await page.goto( BASE + '/minn-admin/broken-links', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( ( m ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( m ) ), 'minn-blc-suite-missing', { timeout: 20000 } );
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => /minn-blc-suite-missing/.test( r.textContent ) ).click() );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).some( ( b ) => /Edit URL/.test( b.textContent ) ), null, { timeout: 15000 } );
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => /Edit URL/.test( b.textContent ) ).click() );
		await page.waitForSelector( '[data-actfield="new_url"]', { timeout: 8000 } );
		await page.type( '[data-actfield="new_url"]', BASE + '/minn-blc-suite-fixed/' );
		await page.click( '[data-actgo]' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Changed in/.test( x.textContent ) ), null, { timeout: 20000 } );
		const afterEdit = content();
		t.check( 'Edit URL rewrites the link inside the post', afterEdit.includes( '/minn-blc-suite-fixed/' ) && ! afterEdit.includes( 'minn-blc-suite-missing' ), afterEdit );

		/* Unlink the working home link: text stays, anchor goes. */
		const all = await api( 'minn-admin/v1/blc/links?filter=all&search=' + encodeURIComponent( 'minn-blc-suite-fixed' ) );
		const fixedId = ( ( all.body.items || [] )[ 0 ] || {} ).id;
		const un = await api( `minn-admin/v1/blc/links/${ fixedId }/unlink`, { method: 'POST' } );
		const afterUnlink = content();
		t.check( 'Unlink removes the link and keeps its text', 200 === un.status && /the old guide/.test( afterUnlink ) && ! /minn-blc-suite-fixed/.test( afterUnlink ), JSON.stringify( { un: un.body, afterUnlink } ) );

		// Their links screen follows the "Show the dashboard widget for"
		// setting; raised to manage_options, an Editor must lose Minn too.
		const editorDefault = wpPhp( `echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/blc/links' ) )->get_status();`, 'minn-editor' );
		const widgetBefore = wpPhp( `echo (string) blc_get_configuration()->get( 'dashboard_widget_capability' );` );
		wpPhp( `$c = blc_get_configuration(); $c->set( 'dashboard_widget_capability', 'manage_options' ); $c->save_options(); echo 1;` );
		const editorRaised = wpPhp( `echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/blc/links' ) )->get_status();`, 'minn-editor' );
		wpPhp( `$c = blc_get_configuration(); $c->set( 'dashboard_widget_capability', ${ JSON.stringify( widgetBefore ) } ); $c->save_options(); echo 1;` );
		t.check( 'their screen\'s capability setting gates Minn too (Editor in by default, out when raised)', '200' === editorDefault && '403' === editorRaised, JSON.stringify( { editorDefault, editorRaised } ) );

		const open = ( ( st.body.actions || [] ).find( ( a ) => /Open Broken Link Checker/.test( a.label ) ) || {} ).href;
		const openStatus = open ? await page.evaluate( async ( h ) => ( await fetch( h, { credentials: 'same-origin' } ) ).status, open ) : 0;
		t.check( 'the status card opens their links screen', 200 === openStatus && /page=blc_local|page=view-broken-links/.test( open ), JSON.stringify( { open, openStatus } ) );

		const author = wpPhp( `echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/blc/links' ) )->get_status();`, 'minn-author' );
		t.check( 'an Author is refused', '403' === author, author );
	} finally {
		try {
			wpPhp( `
				global $wpdb; $p = $wpdb->prefix;
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT link_id FROM {$p}blc_instances WHERE container_id = %d", ${ postId } ) );
				$wpdb->delete( $p . 'blc_instances', array( 'container_id' => ${ postId } ) );
				foreach ( $ids as $id ) { if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}blc_instances WHERE link_id = %d", $id ) ) ) { $wpdb->delete( $p . 'blc_links', array( 'link_id' => (int) $id ) ); } }
				$wpdb->delete( $p . 'blc_synch', array( 'container_id' => ${ postId } ) );
				wp_delete_post( ${ postId }, true );
				echo 1;
			` );
		} catch ( e ) {}
		if ( ! wasActive ) {
			try { wp( 'plugin deactivate broken-link-checker' ); } catch ( e ) {}
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
