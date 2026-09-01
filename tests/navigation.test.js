/**
 * Navigation manager (/minn-admin/navigation): the block-theme replacement
 * for the classic Menus screen. Covers nav gating (Navigation shows only on a
 * block theme, and Menus gives way to it), the list, usage badges derived
 * from real template markup, item counts, create / rename / delete through
 * the UI, and the Site Editor deep link.
 *
 * Runs on either kind of site: the classic phase activates a classic theme
 * when the site does not already run one (the dev site does; the bare
 * next-core site does not), then twentytwentyfive for the block-theme phases.
 * The previously active theme is restored in finally.
 */
const { launch, login, reporter, BASE, autoConfirm, activateClassicTheme } = require( './helpers' );

( async () => {
	const t = reporter( 'navigation' );
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

	// Native prompt() is the house convention for name entry (same as Menus).
	const answerPrompt = ( value ) => page.once( 'dialog', ( d ) => d.accept( value ) );

	const openNav = async () => {
		await page.goto( BASE + '/minn-admin/navigation', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-navrow], .minn-empty', { timeout: 20000 } );
		await page.waitForTimeout( 400 );
	};
	const rows = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-navrow]' ) ].map( ( r ) => ( {
		id: parseInt( r.dataset.navrow, 10 ),
		name: r.dataset.navname,
		kind: ( r.querySelector( '.minn-menu-kind' ) || {} ).textContent || '',
		meta: ( r.querySelector( '.minn-row-slug' ) || {} ).textContent || '',
	} ) ) );
	// Read the row menu's entries without activating one.
	const rowMenuHrefs = async ( id ) => {
		await page.evaluate( ( i ) => document.querySelector( `[data-navrow="${ i }"] [data-navmenu]` ).click(), id );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const hrefs = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-ctx-menu a' ) ].map( ( a ) => a.getAttribute( 'href' ) ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 150 );
		return hrefs;
	};
	const rowMenuAction = async ( id, label ) => {
		await page.evaluate( ( i ) => {
			document.querySelector( `[data-navrow="${ i }"] [data-navmenu]` ).click();
		}, id );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.waitForTimeout( 120 );
		return page.evaluate( ( l ) => {
			const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
				.find( ( b ) => b.textContent.trim().startsWith( l ) );
			if ( btn ) btn.click();
			return !! btn;
		}, label );
	};

	let prevTheme = '';
	const made = { navs: [], parts: [] };

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;

		/* ===== Classic theme: the path stays reachable and explains itself =====
		 * The bookmark-survives-a-theme-switch case. The dev site starts
		 * classic; the bare next-core site starts on a block theme, so a
		 * classic one is activated first rather than assuming the start state. */
		if ( await activateClassicTheme( page ) ) {
			await page.goto( BASE + '/minn-admin/navigation', { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-nav-to-menus', { timeout: 20000 } );
			t.check( 'classic theme explains itself instead of listing block menus',
				await page.evaluate( () => ! document.querySelector( '[data-navrow]' )
					&& /classic theme/i.test( document.querySelector( '#minn-view' ).textContent ) ) );
			await page.click( '#minn-nav-to-menus' );
			await page.waitForFunction( () => location.pathname.endsWith( '/menus' ), null, { timeout: 10000 } );
			t.check( 'its Menus button lands on the classic Menus screen', true );
		} else {
			t.check( 'no classic theme installed to check the classic path', true, 'skipped' );
			t.check( 'no classic theme installed to check the classic path', true, 'skipped' );
		}

		const act = await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );
		t.check( 'activated twentytwentyfive for the run', act.status === 200, `status ${ act.status }` );

		/* ===== Nav gating ===== */
		await openNav();
		const gate = await page.evaluate( () => ( {
			design: !! document.querySelector( '.minn-nav-btn[data-nav="templates"]' ),
			designActive: !! document.querySelector( '.minn-nav-btn[data-nav="templates"].active' ),
			menus: !! document.querySelector( '.minn-nav-btn[data-nav="menus"]' ),
			blockTheme: !! ( window.MINN.site && window.MINN.site.blockTheme ),
		} ) );
		t.check( 'boot reports a block theme', gate.blockTheme );
		// Navigation is a tab under one Design item, the way Terms sits under
		// Structure, so the sidebar stays short on a block theme.
		t.check( 'Design nav item present on a block theme', gate.design );
		t.check( 'it is the item highlighted while on Navigation', gate.designActive );
		t.check( 'Menus nav item gives way to it', ! gate.menus );
		t.check( 'route renders (not bounced to overview)',
			await page.evaluate( () => location.pathname.endsWith( '/navigation' ) && !! document.querySelector( '.minn-toolbar' ) ) );

		/* ===== Create through the UI ===== */
		const name = 'Suite Nav ' + Date.now();
		answerPrompt( name );
		await page.click( '#minn-nav-new' );
		await page.waitForFunction( ( n ) =>
			[ ...document.querySelectorAll( '[data-navrow]' ) ].some( ( r ) => r.dataset.navname === n ),
		name, { timeout: 15000 } );
		const created = ( await rows() ).find( ( r ) => r.name === name );
		t.check( 'created menu appears in the list', !! created );
		if ( created ) made.navs.push( created.id );

		// Verify it is REALLY a published wp_navigation post, not just a row.
		const saved = await rest( `wp/v2/navigation/${ created.id }?context=edit&_fields=id,title,status` );
		t.check( 'created menu is a published wp_navigation post',
			saved.status === 200 && saved.body.status === 'publish' && saved.body.title.raw === name,
			JSON.stringify( saved.body && saved.body.status ) );

		/* ===== Usage badges from real template markup ===== */
		t.check( 'a brand-new menu reads as unused', /Not used/i.test( created.kind ), created.kind );
		// Editing a menu's items is Minn's own screen now; the Site Editor
		// moved to the row menu as the escape hatch rather than the only way in.
		const hrefs = await rowMenuHrefs( created.id );
		t.check( 'the row menu still offers the Site Editor for this menu',
			hrefs.some( ( h ) => h && h.includes( 'site-editor.php' ) && h.includes( encodeURIComponent( '/wp_navigation/' + created.id ) ) ),
			JSON.stringify( hrefs ) );
		// The whole row is the affordance; there is no separate button.
		await page.evaluate( ( i ) => document.querySelector( `[data-navrow="${ i }"] .minn-menu-info` ).click(), created.id );
		await page.waitForFunction( ( i ) => location.pathname.endsWith( '/navigation/' + i ), created.id, { timeout: 10000 } );
		t.check( 'clicking the row opens the tree editor for that menu', true );
		await openNav();
		t.check( 'the row carries no redundant Edit button',
			await page.evaluate( () => ! document.querySelector( '[data-navopen]' ) ) );
		await openNav();

		// Put the menu into the theme's header by saving a template-part
		// override that references it — the same shape the Site Editor writes.
		const part = await rest( 'wp/v2/template-parts', {
			method: 'POST',
			body: {
				slug: 'header',
				theme: 'twentytwentyfive',
				area: 'header',
				content: `<!-- wp:navigation {"ref":${ created.id }} /-->`,
			},
		} );
		t.check( 'seeded a header template part referencing the menu', part.status === 200 || part.status === 201, `status ${ part.status }` );
		if ( part.body && part.body.id ) made.parts.push( part.body.id );

		await openNav();
		const used = ( await rows() ).find( ( r ) => r.id === created.id );
		t.check( 'usage badge now reads "Used in Header"', /Used in Header/i.test( used.kind ), used.kind );

		const usage = await rest( 'minn-admin/v1/navigation/usage' );
		t.check( 'usage endpoint attributes the menu to the header part',
			usage.status === 200 && ( usage.body.usage[ created.id ] || [] ).some( ( p ) => p.area === 'header' ),
			JSON.stringify( usage.body.usage[ created.id ] || null ) );

		/* ===== Item counts ===== */
		await rest( `wp/v2/navigation/${ created.id }`, {
			method: 'POST',
			body: {
				content:
					'<!-- wp:navigation-link {"label":"One","url":"https://example.com/1"} /-->\n\n' +
					'<!-- wp:navigation-submenu {"label":"Two","url":"https://example.com/2"} -->\n' +
					'<!-- wp:navigation-link {"label":"Nested","url":"https://example.com/3"} /-->\n' +
					'<!-- /wp:navigation-submenu -->',
			},
		} );
		const counted = await rest( 'minn-admin/v1/navigation/usage' );
		const c = counted.body.counts[ created.id ] || {};
		t.check( 'counts include nested items (2 links + 1 submenu = 3)',
			c.total === 3 && c.links === 2 && c.submenus === 1, JSON.stringify( c ) );
		await openNav();
		const withCount = ( await rows() ).find( ( r ) => r.id === created.id );
		t.check( 'row shows the item count', /3 items/.test( withCount.meta ), withCount.meta );

		/* ===== Rename ===== */
		const renamed = name + ' renamed';
		answerPrompt( renamed );
		t.check( 'row menu offers Rename', await rowMenuAction( created.id, 'Rename' ) );
		await page.waitForFunction( ( n ) =>
			[ ...document.querySelectorAll( '[data-navrow]' ) ].some( ( r ) => r.dataset.navname === n ),
		renamed, { timeout: 15000 } );
		const afterRename = await rest( `wp/v2/navigation/${ created.id }?context=edit&_fields=title` );
		t.check( 'rename is saved on the post', afterRename.body.title.raw === renamed, afterRename.body.title.raw );

		/* ===== Delete (warns while the theme still renders it) ===== */
		t.check( 'row menu offers Delete', await rowMenuAction( created.id, 'Delete' ) );
		await page.waitForFunction( ( i ) => ! document.querySelector( `[data-navrow="${ i }"]` ), created.id, { timeout: 15000 } );
		const gone = await rest( `wp/v2/navigation/${ created.id }` );
		t.check( 'deleted menu is gone from the server', gone.status === 404, `status ${ gone.status }` );
		if ( gone.status === 404 ) made.navs = made.navs.filter( ( n ) => n !== created.id );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const id of made.navs ) await rest( `wp/v2/navigation/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		for ( const id of made.parts ) await rest( `wp/v2/template-parts/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) {
			await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
		}
	}
	await t.done( browser, errors );
} )();
