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

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
