/**
 * Extensions cards: wp.org plugins wear their real directory icon (from the
 * update_plugins transient — zero extra HTTP) and the icon links to their
 * wp.org page; non-wp.org plugins keep the letter tile with no link.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'extensions' );
	await login( page );

	/* ===== Endpoint ===== */
	const meta = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugin-meta', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return { status: r.status, body: await r.json() };
	} );
	const entries = Object.values( meta.body || {} );
	t.check( 'plugin-meta serves icons + urls from the transient', meta.status === 200 && entries.length > 5 && entries.every( ( e ) => e.slug && e.url ), String( entries.length ) );

	/* ===== Cards ===== */
	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-plugin', { timeout: 15000 } );
	await page.waitForTimeout( 800 ); // icon loads
	const cards = await page.evaluate( () => {
		const all = [ ...document.querySelectorAll( '.minn-plugin' ) ];
		const withIcon = all.filter( ( c ) => c.querySelector( '.minn-plugin-icon img' ) );
		const linked = all.filter( ( c ) => c.querySelector( '.minn-plugin-icon-link' ) );
		const orgHrefs = linked.map( ( c ) => c.querySelector( '.minn-plugin-icon-link' ).href ).filter( ( h ) => /wordpress\.org\/plugins\//.test( h ) );
		const minn = all.find( ( c ) => c.dataset.plugin === 'minn-admin/minn-admin' );
		return {
			total: all.length,
			withIcon: withIcon.length,
			linked: linked.length,
			orgLinks: orgHrefs.length,
			minnLetterTile: !! ( minn && ! minn.querySelector( '.minn-plugin-icon img' ) && minn.querySelector( '.minn-plugin-icon' ).textContent.trim() === 'M' ),
		};
	} );
	t.check( 'wp.org plugins wear real icons', cards.withIcon > 5, JSON.stringify( cards ) );
	t.check( 'icons link to the wp.org directory', cards.orgLinks > 5 && cards.linked >= cards.orgLinks, JSON.stringify( cards ) );
	t.check( 'non-wp.org plugins keep the letter tile', cards.minnLetterTile, '' );

	/* ===== Author lines: linked when a URI exists, no duplicated cite ===== */
	const authors = await page.evaluate( () => {
		const rows = [ ...document.querySelectorAll( '.minn-plugin-author' ) ];
		return {
			count: rows.length,
			linked: rows.filter( ( r ) => r.querySelector( 'a[href]' ) ).length,
			dupCite: [ ...document.querySelectorAll( '.minn-plugin' ) ].some( ( c ) => {
				const d = c.querySelector( '.minn-plugin-desc' );
				const a = c.querySelector( '.minn-plugin-author' );
				return d && a && a.textContent.replace( 'by ', '' ).trim()
					&& d.textContent.includes( 'By ' + a.textContent.replace( 'by ', '' ).trim() );
			} ),
		};
	} );
	t.check( 'author lines render, mostly linked, without duplicating the cite', authors.count > 10 && authors.linked > 5 && ! authors.dupCite, JSON.stringify( authors ) );

	/* ===== Toggling a plugin keeps the scroll position ===== */
	// Use an inactive, inert fixture: hello.php (Hello Dolly) or any inactive
	// non-minn plugin near the bottom of the list.
	const target = await page.evaluate( () => {
		const c = [ ...document.querySelectorAll( '.minn-plugin' ) ]
			.find( ( el ) => el.dataset.plugin.startsWith( 'hello' ) && el.querySelector( '.minn-switch:not(.on)' ) );
		return c ? c.dataset.plugin : null;
	} );
	t.check( 'inactive Hello Dolly available as toggle fixture', !! target, String( target ) );
	if ( target ) {
		await page.evaluate( ( pl ) => {
			document.querySelector( `.minn-plugin[data-plugin="${ pl }"]` ).scrollIntoView( { block: 'center' } );
		}, target );
		const before = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
		await page.click( `.minn-plugin[data-plugin="${ target }"] .minn-switch` );
		await page.waitForFunction( ( pl ) => {
			const c = document.querySelector( `.minn-plugin[data-plugin="${ pl }"]` );
			return c && c.querySelector( '.minn-switch.on' );
		}, target, { timeout: 15000 } );
		const afterOn = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
		t.check( 'activate keeps the scroll position', before > 100 && Math.abs( afterOn - before ) < 60, `before=${ before } after=${ afterOn }` );
		// Revert the fixture.
		await page.click( `.minn-plugin[data-plugin="${ target }"] .minn-switch` );
		await page.waitForFunction( ( pl ) => {
			const c = document.querySelector( `.minn-plugin[data-plugin="${ pl }"]` );
			return c && c.querySelector( '.minn-switch:not(.on)' );
		}, target, { timeout: 15000 } );
		const afterOff = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
		t.check( 'deactivate keeps it too', Math.abs( afterOff - before ) < 60, `before=${ before } after=${ afterOff }` );
	}

	/* ===== Right-click menu on plugin cards ===== */
	// Menu verbs share the switch/delete handlers; open via contextmenu and
	// read labels (do not drive real delete/deactivate from the menu here).
	const pluginMenu = await page.evaluate( () => {
		const card = document.querySelector( '.minn-plugin[data-plugin="minn-admin/minn-admin"]' )
			|| document.querySelector( '.minn-plugin' );
		if ( ! card ) return { ok: false, reason: 'no card' };
		card.dispatchEvent( new MouseEvent( 'contextmenu', {
			bubbles: true, cancelable: true, clientX: 220, clientY: 220,
		} ) );
		const menu = document.querySelector( '.minn-ctx-menu' );
		if ( ! menu ) return { ok: false, reason: 'no menu' };
		const labels = [ ...menu.querySelectorAll( 'button, a, .minn-new-menu-label' ) ]
			.map( ( el ) => el.textContent.trim() );
		const hasAct = labels.some( ( l ) => /^(Activate|Deactivate)$/.test( l ) );
		const hasCopy = labels.some( ( l ) => /Copy plugin file/.test( l ) );
		// Minn Admin's Plugin URI is GitHub — menu should name the hub.
		const hasGithub = labels.some( ( l ) => /Open on GitHub/.test( l ) );
		const hasLink = labels.some( ( l ) => /↗|WordPress\.org|GitHub|Plugin website|Author/.test( l ) )
			|| labels.includes( 'Links' );
		// Dismiss without running anything.
		document.body.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		return { ok: true, labels, hasAct, hasCopy, hasLink, hasGithub, n: labels.length };
	} );
	t.check( 'plugin right-click opens a context menu', pluginMenu.ok, JSON.stringify( pluginMenu ) );
	t.check( 'plugin menu has activate/deactivate + copy file', pluginMenu.hasAct && pluginMenu.hasCopy, JSON.stringify( pluginMenu ) );
	t.check( 'plugin menu offers at least one link or Links section', pluginMenu.hasLink || pluginMenu.n >= 2, JSON.stringify( pluginMenu ) );
	t.check( 'Minn Admin menu says Open on GitHub (Plugin URI)', pluginMenu.hasGithub, JSON.stringify( pluginMenu ) );

	// A wp.org plugin should offer Open on WordPress.org (meta.url and/or Plugin URI).
	const orgMenu = await page.evaluate( () => {
		const card = [ ...document.querySelectorAll( '.minn-plugin' ) ]
			.find( ( c ) => c.querySelector( '.minn-plugin-icon-link' ) );
		if ( ! card ) return { ok: false, reason: 'no org card' };
		card.dispatchEvent( new MouseEvent( 'contextmenu', {
			bubbles: true, cancelable: true, clientX: 260, clientY: 260,
		} ) );
		const menu = document.querySelector( '.minn-ctx-menu' );
		if ( ! menu ) return { ok: false, reason: 'no menu' };
		const labels = [ ...menu.querySelectorAll( 'button, a, .minn-new-menu-label' ) ]
			.map( ( el ) => el.textContent.trim() );
		const hasOrg = labels.some( ( l ) => /Open on WordPress\.org/.test( l ) );
		const orgHref = ( [ ...menu.querySelectorAll( 'a' ) ]
			.find( ( a ) => /wordpress\.org\/plugins\//i.test( a.href ) ) || {} ).href || '';
		document.body.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		return { ok: true, labels, hasOrg, orgHref };
	} );
	t.check( 'wp.org plugin menu says Open on WordPress.org', orgMenu.ok && orgMenu.hasOrg, JSON.stringify( orgMenu ) );

	/* ===== Themes: card parity + menu (right-click and ⋯) ===== */
	await page.click( '[data-xtab="themes"]' );
	await page.waitForSelector( '.minn-theme', { timeout: 15000 } );
	const themeCards = await page.evaluate( () => {
		const all = [ ...document.querySelectorAll( '.minn-theme' ) ];
		const withMore = all.filter( ( c ) => c.querySelector( '[data-theme-more]' ) ).length;
		const linkedShot = all.filter( ( c ) => c.querySelector( 'a.minn-theme-shot-link' ) ).length;
		const linkedAuthor = all.filter( ( c ) => c.querySelector( 'a.minn-theme-author' ) ).length;
		const orgShot = all.some( ( c ) => {
			const a = c.querySelector( 'a.minn-theme-shot-link' );
			return a && /wordpress\.org\/themes\//i.test( a.href );
		} );
		const ghShot = all.some( ( c ) => {
			const a = c.querySelector( 'a.minn-theme-shot-link' );
			return a && /github\.com\//i.test( a.href );
		} );
		return { total: all.length, withMore, linkedShot, linkedAuthor, orgShot, ghShot };
	} );
	t.check( 'theme cards have a ⋯ actions button', themeCards.withMore === themeCards.total && themeCards.total > 0, JSON.stringify( themeCards ) );
	t.check( 'some theme screenshots link to WordPress.org or GitHub', themeCards.linkedShot > 0 && ( themeCards.orgShot || themeCards.ghShot ), JSON.stringify( themeCards ) );
	t.check( 'theme authors are linked when AuthorURI exists', themeCards.linkedAuthor > 0, JSON.stringify( themeCards ) );

	const themeMenu = await page.evaluate( () => {
		const card = document.querySelector( '.minn-theme' );
		if ( ! card ) return { ok: false, reason: 'no card' };
		card.dispatchEvent( new MouseEvent( 'contextmenu', {
			bubbles: true, cancelable: true, clientX: 240, clientY: 240,
		} ) );
		const menu = document.querySelector( '.minn-ctx-menu' );
		if ( ! menu ) return { ok: false, reason: 'no menu' };
		const labels = [ ...menu.querySelectorAll( 'button, a, .minn-new-menu-label' ) ]
			.map( ( el ) => el.textContent.trim() );
		const hasCopy = labels.some( ( l ) => /Copy stylesheet/.test( l ) );
		const hasHub = labels.some( ( l ) => /Open on (GitHub|WordPress\.org)/.test( l ) );
		// Active theme may only have links + copy; inactive has Activate.
		const sensible = hasCopy && labels.length >= 1;
		document.body.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		return { ok: true, labels, hasCopy, hasHub, sensible };
	} );
	t.check( 'theme right-click opens a context menu', themeMenu.ok && themeMenu.sensible, JSON.stringify( themeMenu ) );

	// ⋯ on a wp.org theme (Twenty Twenty-Five etc.) → Open on WordPress.org.
	const themeMore = await page.evaluate( () => {
		const card = [ ...document.querySelectorAll( '.minn-theme' ) ].find( ( c ) => {
			const a = c.querySelector( 'a.minn-theme-shot-link' );
			return a && /wordpress\.org\/themes\//i.test( a.href );
		} ) || document.querySelector( '.minn-theme' );
		if ( ! card ) return { ok: false, reason: 'no card' };
		const btn = card.querySelector( '[data-theme-more]' );
		if ( ! btn ) return { ok: false, reason: 'no more btn' };
		btn.click();
		const menu = document.querySelector( '.minn-ctx-menu' );
		if ( ! menu ) return { ok: false, reason: 'no menu' };
		const labels = [ ...menu.querySelectorAll( 'button, a, .minn-new-menu-label' ) ]
			.map( ( el ) => el.textContent.trim() );
		const hasOrg = labels.some( ( l ) => /Open on WordPress\.org/.test( l ) );
		const hasGh = labels.some( ( l ) => /Open on GitHub/.test( l ) );
		const hasCopy = labels.some( ( l ) => /Copy stylesheet/.test( l ) );
		document.body.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		return { ok: true, labels, hasOrg, hasGh, hasCopy };
	} );
	t.check( 'theme ⋯ menu opens with hub link and copy', themeMore.ok && themeMore.hasCopy && ( themeMore.hasOrg || themeMore.hasGh ), JSON.stringify( themeMore ) );

	/* ===== Themes REST carries author/theme URIs for the menu ===== */
	const themesApi = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/themes', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const body = await r.json();
		const list = ( body && body.themes ) || [];
		const sample = list[ 0 ] || {};
		return {
			status: r.status,
			n: list.length,
			hasKeys: !!( sample && 'author_uri' in sample && 'theme_uri' in sample && 'on_wporg' in sample ),
		};
	} );
	t.check( 'themes API includes author_uri, theme_uri, on_wporg', themesApi.status === 200 && themesApi.n > 0 && themesApi.hasKeys, JSON.stringify( themesApi ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
