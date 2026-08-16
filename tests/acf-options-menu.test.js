/**
 * ACF options parent menus: a "Site Options" parent with child pages is ONE
 * sidebar surface (named for the parent), its children merged into the tab
 * strip — a single-tab child's tab takes the page's name — and every tab's
 * GET/POST delegates to the member page that owns it.
 *
 * Fixture: the mu-plugin registers redirect parent "Minn Site Options" with
 * children Header Bits / Footer Bits (DB groups group_minn_oplab_header /
 * _footer). ACF rewrites children onto the FIRST child's slug at runtime
 * regardless of how they were registered, and the floating parent's
 * menu_slug POINTS at that anchor — the label rule this suite pins.
 * mula.localhost is the live real-site check of the same shape.
 */
const { launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-options-menu' );
	const { browser, page, errors } = await launch();
	await login( page );

	await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } );

	const surfaces = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).filter( ( s ) => s.id && /^acf-options/.test( s.id ) )
			.map( ( s ) => ( {
				id: s.id, label: s.label,
				tabs: ( s.settings && s.settings.tabs || [] ).map( ( x ) => x.label ),
				tabIds: ( s.settings && s.settings.tabs || [] ),
			} ) ) );
	const menu = surfaces[ 0 ];
	if ( ! menu ) {
		console.log( 'SKIP: no ACF options page on this site' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}
	// EVERY options page merges into one surface, not one per page and not
	// one per parent menu: options pages were bleeding into top-level
	// navigation several items at a time.
	t.check( 'options pages register exactly one surface', surfaces.length === 1,
		JSON.stringify( surfaces.map( ( s ) => s.id ) ) );
	t.check( 'a parent menu\'s children are tabs on it',
		menu.tabs.includes( 'Header Bits' ) && menu.tabs.includes( 'Footer Bits' ), JSON.stringify( menu.tabs ) );
	t.check( 'no page registers a surface of its own',
		! surfaces.some( ( s ) => /Header Bits|Footer Bits/.test( s.label ) ), JSON.stringify( surfaces.map( ( s ) => s.label ) ) );

	// One sidebar item, whatever the site registered.
	const navCount = await page.evaluate( () =>
		[ ...document.querySelectorAll( '#minn-navgrp-tools .minn-nav-btn' ) ]
			.filter( ( b ) => /Site Options|Options Lab|Header Bits|Footer Bits/.test( b.textContent ) ).length );
	t.check( 'the sidebar shows one options item', navCount === 1, String( navCount ) );

	/* ===== Cross-page delegation: write on each tab, verify, restore ===== */
	await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/' + menu.id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-settings [data-sset]', { timeout: 15000 } );

	const surface = await page.evaluate( ( id ) =>
		( window.MINN.surfaces || [] ).find( ( s ) => s.id === id ), menu.id );
	const drive = async ( tabId, key, probe ) => {
		await page.click( `[data-ssettab="${ tabId }"]` );
		await page.waitForSelector( `.minn-surface-settings [data-sset="${ key }"]`, { timeout: 15000 } );
		const route = surface.settings.route.replace( '{tab}', tabId );
		const readBack = () => page.evaluate( async ( args ) => {
			const r = await fetch( window.MINN.restUrl + args.route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( ( await r.json() ).values || {} )[ args.key ];
		}, { route, key } );
		const save = async () => {
			const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST'
				&& res.url().includes( route ), { timeout: 20000 } );
			await page.click( '#minn-sset-save' );
			await wait;
			await page.waitForTimeout( 600 );
		};
		const el = await page.$( `.minn-surface-settings [data-sset="${ key }"]` );
		const orig = await el.inputValue();
		await el.fill( probe );
		await save();
		const got = await readBack();
		// Restore through the same UI (the save re-rendered the view).
		const again = await page.$( `.minn-surface-settings [data-sset="${ key }"]` );
		await again.fill( orig );
		await save();
		return { got, restored: await readBack() === orig };
	};

	// Resolve tab ids by LABEL: positions shift as a site registers more
	// options pages into the shared strip.
	const tabIdFor = ( label ) => ( menu.tabIds.find( ( x ) => x.label === label ) || {} ).id;
	const h = await drive( tabIdFor( 'Header Bits' ), 'field_minn_oplab_h_title', 'Menu probe H' );
	t.check( 'tab-0 write delegates to the first member page', h.got === 'Menu probe H' && h.restored, JSON.stringify( h ) );
	const f = await drive( tabIdFor( 'Footer Bits' ), 'field_minn_oplab_f_note', 'Menu probe F' );
	t.check( 'tab-1 write delegates to the second member page', f.got === 'Menu probe F' && f.restored, JSON.stringify( f ) );

	await t.done( browser, errors );
} )();
