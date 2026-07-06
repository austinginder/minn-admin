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

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
