/**
 * ACF options pages (Pro) as settings-only surfaces: tab derivation from ACF
 * `tab` fields, value read through get_field, save through update_field with
 * key whitelisting, and the locked-count link-out.
 *
 * ACF free registers no options pages, so on the minnadmin dev site this
 * suite SKIPs (exit 0, the kadence-designs convention). Run it for real
 * against an ACF Pro site with an options page, e.g. the ACF Pro lab:
 *
 *   MINN_TEST_URL=https://acf-pro.localhost MINN_TEST_USER=austin \
 *   MINN_TEST_PASS=… node acf-options.test.js
 *
 * The write test targets the LAST tab's first text/textarea field, saves a
 * probe value, verifies over REST, then restores the original through the
 * same UI (options are singletons — there is no draft-copy trick).
 */
const { launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-options' );
	const { browser, page, errors } = await launch();
	await login( page );

	await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } );

	const surface = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).find( ( s ) => s.id && s.id.startsWith( 'acf-options-' ) ) || null );
	if ( ! surface ) {
		console.log( 'SKIP: no ACF options-page surface on this site (ACF Pro with an options page required)' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}

	t.check( 'surface is settings-only', ! surface.collection && !! surface.settings && !! surface.settings.route, JSON.stringify( surface.settings ) );
	t.check( 'descriptor declares tabs', Array.isArray( surface.settings.tabs ) && surface.settings.tabs.length >= 1,
		JSON.stringify( surface.settings.tabs ) );

	await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/' + surface.id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-settings, .minn-loading', { timeout: 15000 } );
	await page.waitForSelector( '.minn-surface-settings [data-sset]', { timeout: 15000 } );

	const tabCount = surface.settings.tabs.length;
	if ( tabCount > 1 ) {
		const rendered = await page.$$eval( '[data-ssettab]', ( els ) => els.map( ( e ) => e.dataset.ssettab ) );
		t.check( 'tab strip matches the descriptor', rendered.length === tabCount
			&& surface.settings.tabs.every( ( tb ) => rendered.includes( tb.id ) ), rendered.join( ',' ) );
	}

	// Last tab: fields render from the adapter's derived schema.
	const lastTab = surface.settings.tabs[ tabCount - 1 ];
	if ( tabCount > 1 ) {
		await page.click( `[data-ssettab="${ lastTab.id }"]` );
		await page.waitForSelector( '.minn-surface-settings [data-sset]', { timeout: 15000 } );
	}
	const editableSel = '.minn-surface-settings textarea[data-sset], .minn-surface-settings input[data-sset][data-ftype="text"]';
	const target = await page.$( editableSel );
	if ( ! target ) {
		console.log( 'SKIP write test: last tab has no text/textarea field' );
	} else {
		const key = await target.evaluate( ( e ) => e.dataset.sset );
		const orig = await target.inputValue();
		const readBack = () => page.evaluate( async ( args ) => {
			const r = await fetch( window.MINN.restUrl + args.route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( ( await r.json() ).values || {} )[ args.key ];
		}, { route: surface.settings.route.replace( '{tab}', lastTab.id ), key } );

		const save = async () => {
			const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST'
				&& res.url().includes( surface.settings.route.replace( '{tab}', lastTab.id ) ), { timeout: 20000 } );
			await page.click( '#minn-sset-save' );
			const res = await wait;
			await page.waitForTimeout( 600 );
			return res.status();
		};

		await target.fill( 'Minn acf-options probe' );
		t.check( 'save POST succeeds', ( await save() ) === 200 );
		t.check( 'edited value persisted via the adapter route', ( await readBack() ) === 'Minn acf-options probe' );

		// Restore through the same UI (the save re-rendered the view — the
		// old element handle is detached, re-query by key).
		const again = await page.$( `.minn-surface-settings [data-sset="${ key }"]` );
		await again.fill( orig );
		t.check( 'restore save succeeds', ( await save() ) === 200 );
		t.check( 'original value restored', ( await readBack() ) === orig );
	}

	await t.done( browser, errors );
} )();
