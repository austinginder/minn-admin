/**
 * Structure page context menus: right-click on Post Types and Taxonomies
 * rows opens the shared minn-ctx-menu with entries built from real flows —
 * open the definition, View items / New in Content (only for types Minn can
 * actually list), Manage terms (REST taxonomies only), and a danger-zone
 * Remove for editable definitions. The remove path is proven end to end on
 * a suite-created throwaway type; the standing CPT UI fixtures
 * (Field Reports / Case Studies) are read-only probes here.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

( async () => {
	const t = reporter( 'structure-menu' );
	const { browser, page, errors } = await launch();
	await login( page );
	await autoConfirm( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	const openStructure = async () => {
		await page.goto( BASE + '/minn-admin/posttypes', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-cpt]', { timeout: 20000 } );
	};

	// Real right-click at row coordinates (synthetic dispatch would pass even
	// when the row is unhittable); menu entries then read/click by evaluate —
	// a second real click can land after the menu re-positions.
	const rightClickRow = async ( attr, name ) => {
		const pos = await page.evaluate( ( a ) => {
			const row = [ ...document.querySelectorAll( `[data-${ a.attr }]` ) ]
				.find( ( r ) => {
					const el = r.querySelector( '.minn-row-title' );
					return el && el.textContent.trim() === a.name;
				} );
			if ( ! row ) return null;
			row.scrollIntoView( { block: 'center' } );
			const r = row.getBoundingClientRect();
			return { x: r.left + 60, y: r.top + r.height / 2 };
		}, { attr, name } );
		if ( ! pos ) return false;
		await page.mouse.click( pos.x, pos.y, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.waitForTimeout( 120 );
		return true;
	};
	const menuEntries = () => page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a, .minn-ctx-menu .minn-new-menu-label' ) ]
			.map( ( el ) => el.textContent.trim() ) );
	const clickEntry = ( label ) => page.evaluate( ( l ) => {
		const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
			.find( ( b ) => b.textContent.trim().startsWith( l ) );
		if ( btn ) btn.click();
		return !! btn;
	}, label );
	const closeMenu = async () => {
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 120 );
	};

	// The View/New entries resolve through the content types cache, which the
	// tab warms asynchronously — a right-click in the first few hundred ms
	// can beat it. Reopen until the cache-dependent entries settle.
	const settledTypeMenu = async ( name ) => {
		for ( let i = 0; i < 12; i++ ) {
			if ( ! ( await rightClickRow( 'cpt', name ) ) ) return null;
			const entries = await menuEntries();
			if ( entries.includes( 'View items' ) ) return entries;
			await closeMenu();
			await page.waitForTimeout( 400 );
		}
		return menuEntries();
	};

	const PROBE = { slug: 'minn_ctxmenu', plural: 'Ctx Probes', singular: 'Ctx Probe' };

	try {
		await openStructure();

		/* ===== Post Types tab: a REST-visible, code-editable type ===== */
		let entries = await settledTypeMenu( 'Field Reports' );
		t.check( 'right-click opens the context menu on a CPT UI row', !! entries );
		t.check( 'editable type leads with Edit post type', entries[ 0 ] === 'Edit post type', JSON.stringify( entries ) );
		t.check( 'REST-visible type offers View items', entries.includes( 'View items' ), JSON.stringify( entries ) );
		t.check( 'and a New entry named for the singular', entries.some( ( e ) => e.startsWith( 'New ' ) ), JSON.stringify( entries ) );
		t.check( 'editable type gets the danger zone', entries.includes( 'Danger zone' ) && entries.some( ( e ) => e.startsWith( 'Remove post type' ) ), JSON.stringify( entries ) );
		await closeMenu();

		/* ===== Core type: read-only definition, no remove ===== */
		t.check( 'right-click works on a core row', await rightClickRow( 'cpt', 'Posts' ) );
		entries = await menuEntries();
		t.check( 'core type reads View definition and has no remove',
			entries[ 0 ] === 'View definition' && ! entries.some( ( e ) => e.startsWith( 'Remove' ) ),
			JSON.stringify( entries ) );
		await closeMenu();

		/* ===== REST-hidden type: no View/New (Minn cannot list it) ===== */
		if ( await rightClickRow( 'cpt', 'Case Studies' ) ) {
			entries = await menuEntries();
			t.check( 'REST-hidden type offers neither View items nor New',
				! entries.includes( 'View items' ) && ! entries.some( ( e ) => e.startsWith( 'New ' ) ),
				JSON.stringify( entries ) );
			await closeMenu();
		} else {
			console.log( 'note: no REST-hidden fixture row — hidden-type check skipped' );
		}

		/* ===== View items really lands on Content, filtered ===== */
		await settledTypeMenu( 'Field Reports' );
		t.check( 'View items clicks', await clickEntry( 'View items' ) );
		await page.waitForFunction( () => location.pathname.includes( '/minn-admin/content' ), null, { timeout: 10000 } );
		await page.waitForSelector( '.minn-toolbar', { timeout: 10000 } );
		const typeControl = await page.evaluate( () => {
			const combo = document.querySelector( '[data-typecombo] .minn-ac-input' );
			if ( combo ) return combo.value || combo.placeholder;
			const tab = document.querySelector( '.minn-tab.active[data-filter]' );
			return tab ? tab.textContent.trim() : '';
		} );
		t.check( 'Content opens on the type', /field reports/i.test( typeControl ), typeControl );

		/* ===== Taxonomies tab ===== */
		await openStructure();
		await page.click( '[data-structtab="taxonomies"]' );
		await page.waitForSelector( '[data-tax]', { timeout: 20000 } );
		t.check( 'right-click opens on a taxonomy row', await rightClickRow( 'tax', 'Categories' ) );
		entries = await menuEntries();
		t.check( 'core taxonomy reads View definition with Manage terms and no remove',
			entries[ 0 ] === 'View definition' && entries.includes( 'Manage terms' ) && ! entries.some( ( e ) => e.startsWith( 'Remove' ) ),
			JSON.stringify( entries ) );
		t.check( 'Manage terms clicks', await clickEntry( 'Manage terms' ) );
		await page.waitForSelector( '#minn-terms-table', { timeout: 15000 } );
		const searchPh = await page.evaluate( () => {
			const el = document.querySelector( '#minn-term-search' );
			return el ? el.placeholder : '';
		} );
		t.check( 'Terms tab opens on that taxonomy', /categories/i.test( searchPh ), searchPh );

		/* ===== Remove via the menu, end to end on a throwaway type ===== */
		const created = await rest( 'minn-admin/v1/post-types', { method: 'POST', body: {
			plural: PROBE.plural, singular: PROBE.singular, slug: PROBE.slug, description: '',
			public: 1, has_archive: 0, hierarchical: 0, show_in_rest: 1,
			supports: [ 'title' ], taxonomies: [],
		} } );
		t.check( 'throwaway type created over REST', created.status < 300, JSON.stringify( created.body ) );
		await openStructure();
		t.check( 'throwaway row right-clicks', await rightClickRow( 'cpt', PROBE.plural ) );
		t.check( 'Remove post type clicks (confirm auto-accepted)', await clickEntry( 'Remove post type' ) );
		await page.waitForFunction( ( plural ) =>
			! [ ...document.querySelectorAll( '[data-cpt] .minn-row-title' ) ]
				.some( ( el ) => el.textContent.trim() === plural ), PROBE.plural, { timeout: 15000 } );
		const after = await rest( 'minn-admin/v1/post-types' );
		t.check( 'definition really gone over REST',
			! ( after.body.types || [] ).some( ( x ) => x.slug === PROBE.slug ) );
	} finally {
		// A failed run must not leave the throwaway definition behind.
		await rest( 'minn-admin/v1/post-types/' + PROBE.slug, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
