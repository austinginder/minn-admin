/**
 * Per-user hide, editor half (v1.0 gate G2 remainder): design sources hide
 * as `design:<id>`, slash namespaces as `slash:<ns>` (blocks + patterns +
 * commands under one ns go together). The affordance is right-click on a
 * block-picker group heading; hidden entries leave the server payloads
 * (B.designs / B.insertBlocks / patterns route), the inline slash menu's
 * live items array prunes in place, and restore lives on Your profile.
 *
 * Fixtures: minn_test_design_source arms the "Minn Test Library" source;
 * the always-on minn-test/big-schema block and minn-test/feature-box
 * pattern give the `minn-test` slash namespace.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'hide-slash-designs' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, body ) => page.evaluate( async ( [ p, b ] ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: b === undefined ? 'GET' : 'POST',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: b === undefined ? undefined : JSON.stringify( b ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, body ] );

	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const clickMenuEntry = ( text ) => page.evaluate( ( needle ) => {
		const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
			.find( ( b ) => b.textContent.includes( needle ) );
		if ( btn ) btn.click();
		return !! btn;
	}, text );

	const pickerGroups = () => page.$$eval( '.minn-bp-group h3', ( els ) => els.map( ( h ) => h.textContent.trim() ) );

	const openPicker = async ( expectDesigns = true ) => {
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+/' );
		// v0.18.0: the picker renders sync groups instantly and design/
		// pattern groups FILL IN as their fetches settle — so a stable group
		// count no longer means "all sources arrived". Wait for the async
		// kinds explicitly (generous: a cold Kadence/Stackable cloud-cache
		// refresh can take >20s; the suites' one network-dependent spot).
		await page.waitForSelector( '.minn-bp-group', { timeout: 30000 } );
		if ( expectDesigns ) {
			await page.waitForFunction( () => {
				const t = [ ...document.querySelectorAll( '.minn-bp-group h3' ) ].map( ( e ) => e.textContent );
				return t.some( ( x ) => /designs/.test( x ) ) && t.some( ( x ) => /patterns/.test( x ) );
			}, null, { timeout: 90000 } ).catch( () => {} );
		}
		// Then settle until the group count is stable (other stragglers).
		let prev = -1;
		for ( let i = 0; i < 20; i++ ) {
			const n = await page.$$eval( '.minn-bp-group', ( els ) => els.length );
			if ( n === prev ) break;
			prev = n;
			await page.waitForTimeout( 400 );
		}
	};

	let postId = null;
	try {
		t.check( 'design source fixture armed', await setOpt( 'minn_test_design_source', true ) );

		postId = await createPost( page, { title: 'Hide slash probe', content: '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' } );
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );
		await openEditor( page, postId );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );

		/* ===== Baseline: picker shows the fixture groups ===== */
		await openPicker();
		let groups = await pickerGroups();
		t.check( 'picker lists the fixture design library', groups.some( ( g ) => g.includes( 'Minn Test Library · designs' ) ), groups.join( ' | ' ) );
		t.check( 'picker lists the minn-test blocks group', groups.some( ( g ) => g.includes( 'Minn-test · blocks' ) ) );
		t.check( 'picker lists the minn-test patterns group', groups.some( ( g ) => g.includes( 'Minn-test · patterns' ) ) );
		t.check( 'Basics group offers no hide affordance', await page.evaluate( () => {
			const h = [ ...document.querySelectorAll( '.minn-bp-group h3' ) ].find( ( el ) => /^Basics/.test( el.textContent ) );
			return !! h && ! h.title;
		} ) );

		/* ===== Hide the design source from its group heading ===== */
		await page.click( '.minn-bp-group h3[title*="Minn Test Library"]', { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		t.check( 'design group heading offers Hide for you', await clickMenuEntry( 'for you' ) );
		await page.waitForFunction( () => ! [ ...document.querySelectorAll( '.minn-bp-group h3' ) ]
			.some( ( h ) => h.textContent.includes( 'Minn Test Library' ) ), null, { timeout: 10000 } );
		t.check( 'design group leaves the open picker', true );
		t.check( 'B.designs updated in place', await page.evaluate(
			() => ! ( window.MINN.designs || [] ).some( ( s ) => s.id === 'minn-test' )
				&& ( window.MINN.hidden || [] ).some( ( h ) => h.id === 'design:minn-test' )
		) );

		/* ===== Hide the slash namespace; blocks AND patterns groups go ===== */
		await page.click( '.minn-bp-group h3[title*="Minn-test"]', { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		t.check( 'namespace group heading offers Hide for you', await clickMenuEntry( 'for you' ) );
		await page.waitForFunction( () => ! [ ...document.querySelectorAll( '.minn-bp-group h3' ) ]
			.some( ( h ) => /Minn-test · (blocks|patterns)/.test( h.textContent ) ), null, { timeout: 10000 } );
		t.check( 'blocks and patterns groups leave together', true );
		t.check( 'B.insertBlocks pruned of the namespace', await page.evaluate(
			() => ! ( window.MINN.insertBlocks || [] ).some( ( b ) => b.ns === 'minn-test' )
		) );
		// Escape only reaches the picker's keydown handler while focus is
		// inside it — after the menu-driven hide it isn't; use the × button.
		await page.click( '#minn-bp-close' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-block-picker' ), null, { timeout: 5000 } );

		/* ===== Inline slash menu pruned in place (no editor re-render) ===== */
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.press( 'Delete' );
		await page.keyboard.type( '/big schema' );
		await page.waitForTimeout( 600 );
		t.check( 'slash menu no longer offers the hidden block', await page.evaluate(
			() => ! [ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => /Big Schema/i.test( el.textContent ) )
		) );
		await page.keyboard.press( 'Escape' );

		/* ===== Server filtering holds on a fresh load ===== */
		await openEditor( page, postId );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		const boot = await page.evaluate( () => ( {
			designs: ( window.MINN.designs || [] ).map( ( s ) => s.id ),
			insertNs: [ ...new Set( ( window.MINN.insertBlocks || [] ).map( ( b ) => b.ns ) ) ],
		} ) );
		t.check( 'hides survive a reload (server-filtered boot)',
			! boot.designs.includes( 'minn-test' ) && ! boot.insertNs.includes( 'minn-test' ), JSON.stringify( boot ) );
		const pats = await rest( 'minn-admin/v1/patterns' );
		t.check( 'patterns route filters the hidden namespace',
			pats.status === 200 && ! ( pats.body.patterns || [] ).some( ( p ) => p.ns === 'minn-test' ) );

		/* ===== Junk ids are refused ===== */
		const junk = await rest( 'minn-admin/v1/integrations/hide', { id: 'slash:not-a-live-namespace' } );
		t.check( 'hide refuses an unknown namespace', junk.status === 400, String( junk.status ) );

		/* ===== Restore from Your profile ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'Your profile' );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '[data-unhide]', { timeout: 15000 } );
		const rows = await page.$$eval( '.minn-session-row', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'restore list labels the design library', rows.some( ( r ) => r.includes( 'Minn Test Library' ) && r.includes( 'Design library' ) ), rows.join( ' | ' ) );
		t.check( 'restore list labels the slash namespace', rows.some( ( r ) => r.includes( 'Minn-test' ) && r.includes( 'Editor blocks and commands' ) ) );
		await page.click( '[data-unhide="design:minn-test"]' );
		await page.waitForFunction( () => ( window.MINN.designs || [] ).some( ( s ) => s.id === 'minn-test' ), null, { timeout: 10000 } );
		t.check( 'design restore repaints B.designs in one round trip', true );
		await page.click( '[data-unhide="slash:minn-test"]' );
		await page.waitForFunction( () => ( window.MINN.insertBlocks || [] ).some( ( b ) => b.ns === 'minn-test' ), null, { timeout: 10000 } );
		t.check( 'namespace restore repaints B.insertBlocks', true );
		await page.keyboard.press( 'Escape' );

		/* ===== Restored groups return on a fresh picker ===== */
		await openEditor( page, postId );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await openPicker();
		groups = await pickerGroups();
		t.check( 'restored groups return to the picker',
			groups.some( ( g ) => g.includes( 'Minn Test Library · designs' ) ) && groups.some( ( g ) => g.includes( 'Minn-test · blocks' ) ),
			groups.join( ' | ' ) );
		await page.click( '#minn-bp-close' );
	} finally {
		await rest( 'minn-admin/v1/integrations/unhide', { id: 'design:minn-test' } ).catch( () => {} );
		await rest( 'minn-admin/v1/integrations/unhide', { id: 'slash:minn-test' } ).catch( () => {} );
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		await setOpt( 'minn_test_design_source', false );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
