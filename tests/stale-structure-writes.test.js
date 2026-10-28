/**
 * Three screens write a whole list back: a sidebar's widget order, a menu's
 * locations and a block-theme menu's document. Each built that list from the
 * copy the page loaded, so a change made elsewhere since (another tab, the
 * Site Editor, another administrator) was undone by the next action in Minn.
 * Each now reads what is stored first: widgets and locations apply the
 * action to the current list, and the navigation tree refuses and reloads.
 *
 * Fixtures: a menu and a navigation post made here and removed in finally;
 * the sidebar order, the primary menu location and the active theme (the
 * tree needs a block theme) are put back.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'stale-structure-writes' );
	await login( page );
	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	/* ===== Widgets: a move keeps an order changed since ===== */
	await page.goto( `${ BASE }/minn-admin/widgets`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-widget-area[data-sidebar] .minn-widget-row[data-widget]', { timeout: 30000 } );
	const sid = await page.evaluate( () => {
		const hit = [ ...document.querySelectorAll( '.minn-widget-area[data-sidebar]' ) ].find( ( a ) => a.querySelectorAll( '.minn-widget-row[data-widget] [data-wmove]' ).length >= 3 );
		return hit ? hit.dataset.sidebar : null;
	} );
	if ( ! sid ) {
		t.check( 'widgets: a sidebar with three widgets is available (skipped when absent)', true, 'skipped' );
	} else {
		const before = ( await rest( `wp/v2/sidebars/${ sid }?context=edit&_fields=widgets` ) ).body.widgets;
		const [ a, b, c ] = before;
		try {
			// Another session moves the third widget to the top after this
			// page loaded: [a, b, c] becomes [c, a, b].
			await rest( `wp/v2/sidebars/${ sid }`, { method: 'POST', body: { widgets: [ c, a, b, ...before.slice( 3 ) ] } } );
			const sel = `.minn-widget-area[data-sidebar="${ sid }"] .minn-widget-row[data-widget="${ a }"] [data-wmove="down"]`;
			await Promise.all( [
				page.waitForResponse( ( r ) => r.url().includes( `/wp/v2/sidebars/${ sid }` ) && 'POST' === r.request().method(), { timeout: 60000 } ),
				page.click( sel ),
			] );
			const now = ( await rest( `wp/v2/sidebars/${ sid }?context=edit&_fields=widgets` ) ).body.widgets;
			t.check( 'widgets: the move keeps the order changed since (the third stays on top)', now[ 0 ] === c, JSON.stringify( now ) );
			t.check( 'widgets: and still makes the move', now.indexOf( a ) === now.indexOf( b ) + 1, JSON.stringify( now ) );
		} finally {
			await rest( `wp/v2/sidebars/${ sid }`, { method: 'POST', body: { widgets: before } } ).catch( () => {} );
		}
	}

	/* ===== Menu locations: assigning one keeps a location given since ===== */
	const locs = ( await rest( 'wp/v2/menu-locations' ) ).body || {};
	if ( ! locs.primary || ! locs.footer ) {
		t.check( 'menu locations: primary and footer are registered (skipped when absent)', true, 'skipped' );
	} else {
		const primaryWas = locs.primary.menu || 0;
		const footerWas = locs.footer.menu || 0;
		const menu = ( await rest( 'wp/v2/menus', { method: 'POST', body: { name: 'Minn stale locations ' + Date.now() } } ) ).body;
		try {
			await page.goto( `${ BASE }/minn-admin/menus`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-loc-ac[data-loc="primary"] .minn-ac-input', { timeout: 30000 } );
			// Another session gives the new menu the footer location.
			await rest( `wp/v2/menus/${ menu.id }`, { method: 'POST', body: { locations: [ 'footer' ] } } );
			await page.click( '.minn-loc-ac[data-loc="primary"] .minn-ac-input' );
			await page.keyboard.type( menu.name );
			await page.waitForTimeout( 300 );
			await Promise.all( [
				page.waitForResponse( ( r ) => r.url().includes( `/wp/v2/menus/${ menu.id }` ) && 'POST' === r.request().method(), { timeout: 60000 } ),
				page.keyboard.press( 'Enter' ),
			] );
			const now = ( await rest( `wp/v2/menus/${ menu.id }?context=edit&_fields=locations` ) ).body.locations || [];
			t.check( 'menu locations: assigning primary keeps the footer location given since', now.includes( 'primary' ) && now.includes( 'footer' ), JSON.stringify( now ) );
		} finally {
			await rest( `wp/v2/menus/${ menu.id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
			if ( primaryWas ) {
				const held = ( await rest( `wp/v2/menus/${ primaryWas }?context=edit&_fields=locations` ) ).body.locations || [];
				await rest( `wp/v2/menus/${ primaryWas }`, { method: 'POST', body: { locations: [ ...new Set( [ ...held, 'primary' ] ) ] } } ).catch( () => {} );
			}
			if ( footerWas ) {
				const held = ( await rest( `wp/v2/menus/${ footerWas }?context=edit&_fields=locations` ) ).body.locations || [];
				await rest( `wp/v2/menus/${ footerWas }`, { method: 'POST', body: { locations: [ ...new Set( [ ...held, 'footer' ] ) ] } } ).catch( () => {} );
			}
		}
	}

	/* ===== Navigation tree: an action on a stale copy is refused ===== */
	let prevTheme = '';
	let nav = 0;
	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;
		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );
		const link = ( label ) => `<!-- wp:navigation-link {"label":"${ label }","url":"https://example.com/${ label.toLowerCase() }","kind":"custom"} /-->`;
		nav = ( await rest( 'wp/v2/navigation', { method: 'POST', body: { title: 'Minn stale nav ' + Date.now(), status: 'publish', content: [ link( 'Alpha' ), link( 'Beta' ) ].join( '\n\n' ) } } ) ).body.id;
		await page.goto( `${ BASE }/minn-admin/navigation/${ nav }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-navtoken="1"]', { timeout: 30000 } );
		// Another session adds a link after this page loaded.
		await rest( `wp/v2/navigation/${ nav }`, { method: 'POST', body: { content: [ link( 'Alpha' ), link( 'Beta' ), link( 'Gamma' ) ].join( '\n\n' ) } } );
		let wrote = 0;
		const count = ( r ) => {
			if ( r.url().includes( `/wp/v2/navigation/${ nav }` ) && 'POST' === r.method() ) wrote++;
		};
		page.on( 'request', count );
		await page.click( '[data-navtoken="1"] [data-navmove="up"]' );
		await page.waitForFunction( () => document.querySelectorAll( '[data-navtoken]' ).length === 3, null, { timeout: 30000 } ).catch( () => {} );
		page.off( 'request', count );
		const saved = ( await rest( `wp/v2/navigation/${ nav }?context=edit&_fields=content` ) ).body.content.raw;
		t.check( 'navigation: a stale action writes nothing', 0 === wrote, String( wrote ) );
		t.check( 'navigation: the link added elsewhere stays', saved.includes( 'Gamma' ), saved );
		t.check( 'navigation: the tree reloads with it', 3 === await page.$$eval( '[data-navtoken]', ( r ) => r.length ) );
		// Control: on the reloaded copy the same action saves.
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( `/wp/v2/navigation/${ nav }` ) && 'POST' === r.request().method(), { timeout: 60000 } ),
			page.click( '[data-navtoken="1"] [data-navmove="up"]' ),
		] );
		const after = ( await rest( `wp/v2/navigation/${ nav }?context=edit&_fields=content` ) ).body.content.raw;
		t.check( 'navigation control: the action on a fresh copy saves, the added link kept', after.indexOf( 'Beta' ) < after.indexOf( 'Alpha' ) && after.includes( 'Gamma' ), after );
	} finally {
		if ( nav ) await rest( `wp/v2/navigation/${ nav }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
