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

	// Single image field: the { id, url } control on a settings PAGE — the
	// media picker opens right over it (no modal close dance). Find a tab
	// carrying one via the adapter route (live-robust: another site's
	// options page may not have an image field).
	let imgTab = null, imgKey = null;
	for ( const tb of surface.settings.tabs ) {
		const shape = await page.evaluate( async ( route ) => {
			const r = await fetch( window.MINN.restUrl + route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return r.ok ? await r.json() : null;
		}, surface.settings.route.replace( '{tab}', tb.id ) );
		const f = ( ( ( ( shape || {} ).groups || [] )[ 0 ] || {} ).fields || [] ).find( ( x ) => x.type === 'image' );
		if ( f ) { imgTab = tb; imgKey = f.key; break; }
	}
	if ( ! imgTab ) {
		console.log( 'SKIP image test: no single-image field on this options page' );
	} else {
		const imgRoute = surface.settings.route.replace( '{tab}', imgTab.id );
		const imgSel = `.minn-surface-settings [data-sset="${ imgKey }"][data-ftype="image"]`;
		if ( surface.settings.tabs.length > 1 ) {
			await page.click( `[data-ssettab="${ imgTab.id }"]` );
		}
		await page.waitForSelector( imgSel, { timeout: 15000 } );
		t.check( 'image field renders the image control', !! ( await page.$( imgSel ) ) );
		const saveImg = async () => {
			const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST'
				&& res.url().includes( imgRoute ), { timeout: 20000 } );
			await page.click( '#minn-sset-save' );
			const res = await wait;
			await page.waitForTimeout( 600 );
			return res.status();
		};
		const readImg = () => page.evaluate( async ( args ) => {
			const r = await fetch( window.MINN.restUrl + args.route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( ( await r.json() ).values || {} )[ args.key ];
		}, { route: imgRoute, key: imgKey } );

		await page.click( `${ imgSel } [data-img-pick]` );
		await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
		await page.click( '.minn-picker-item[data-pick]' );
		await page.waitForTimeout( 400 );
		t.check( 'image save succeeds', ( await saveImg() ) === 200 );
		let iv = await readImg();
		t.check( 'picked image persisted as { id, url }', iv && iv.id > 0 && typeof iv.url === 'string',
			JSON.stringify( iv ) );

		// The save re-rendered the view; the control now shows Remove.
		await page.waitForSelector( `${ imgSel } [data-img-clear]`, { timeout: 15000 } );
		await page.click( `${ imgSel } [data-img-clear]` );
		t.check( 'clear save succeeds', ( await saveImg() ) === 200 );
		iv = await readImg();
		t.check( 'cleared image persisted as empty', iv === '', JSON.stringify( iv ) );
	}

	// datetime field: the shared date picker with its time row (the value
	// path is the same picker commit the panel suite proves end to end).
	const lastTab2 = surface.settings.tabs[ surface.settings.tabs.length - 1 ];
	if ( surface.settings.tabs.length > 1 ) {
		await page.click( `[data-ssettab="${ lastTab2.id }"]` );
	}
	const dtEl = await page.waitForSelector( '.minn-surface-settings [data-ftype="datetime"]', { timeout: 8000 } ).catch( () => null );
	if ( ! dtEl ) {
		console.log( 'SKIP datetime check: no datetime field on this options page' );
	} else {
		await dtEl.click();
		await page.waitForSelector( '.minn-dp-pop .minn-dp-day', { timeout: 10000 } );
		t.check( 'datetime field opens the date picker with its time row',
			!! ( await page.$( '.minn-dp-pop .minn-dp-time' ) ) );
		await page.keyboard.press( 'Escape' );
	}

	// relation field: chips + append picker on a settings page.
	const rlWrap = await page.$( '.minn-surface-settings [data-ftype="relation"]' );
	if ( ! rlWrap ) {
		console.log( 'SKIP relation check: no relation field on this options page' );
	} else {
		const rlKey = await rlWrap.evaluate( ( e ) => e.dataset.sset );
		const rlSel = `.minn-surface-settings [data-sset="${ rlKey }"]`;
		const rlRoute = surface.settings.route.replace( '{tab}', lastTab2.id );
		await page.click( `${ rlSel } .minn-ac-input` );
		await page.waitForSelector( `${ rlSel } .minn-ac-item[data-acv]`, { timeout: 10000 } );
		await page.evaluate( ( sel ) => {
			document.querySelector( `${ sel } .minn-ac-item` )
				.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		}, rlSel );
		await page.waitForSelector( `${ rlSel } .minn-relation-chip`, { timeout: 5000 } );
		const saveRel = async () => {
			const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST'
				&& res.url().includes( rlRoute ), { timeout: 20000 } );
			await page.click( '#minn-sset-save' );
			await wait;
			await page.waitForTimeout( 600 );
		};
		await saveRel();
		const readRel = () => page.evaluate( async ( args ) => {
			const r = await fetch( window.MINN.restUrl + args.route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( ( await r.json() ).values || {} )[ args.key ];
		}, { route: rlRoute, key: rlKey } );
		let rl = await readRel();
		t.check( 'relation pick persisted from the settings page',
			Array.isArray( rl ) && rl.length === 1 && /^\d+$/.test( String( rl[ 0 ].value ) ), JSON.stringify( rl ) );
		// Remove the chip (the save re-rendered the view — re-query).
		await page.click( `${ rlSel } [data-reldel="0"]` );
		await saveRel();
		rl = await readRel();
		t.check( 'relation clear persisted', Array.isArray( rl ) && rl.length === 0, JSON.stringify( rl ) );
	}

	await t.done( browser, errors );
} )();
