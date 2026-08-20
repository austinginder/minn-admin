/**
 * The Minn bar stands down for full-screen overlays.
 *
 * The bar's z-index deliberately outranks WordPress's own toolbar, which also
 * put it above image lightboxes, whose whole job is to blank the page. Since
 * lightbox z-indexes in the wild run from 200 to 999999, the bar cannot solve
 * this with a fixed value; it asks what is topmost in the middle of the
 * viewport and steps behind anything fixed that covers the screen.
 *
 * Two layers here: a SYNTHETIC overlay, so the suite proves the rule on any
 * site, and the marketing theme's real image lightbox where the page has one.
 * The decorative case is asserted too, because the failure that matters is a
 * bar that hides itself on an ordinary page.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'bar-overlay' );
	await login( page );

	// The bar renders on the front end, not inside the app.
	await page.goto( BASE + '/', { waitUntil: 'domcontentloaded' } );
	const hasBar = await page.waitForSelector( '#minn-cornerbar', { timeout: 15000 } )
		.then( () => true ).catch( () => false );
	if ( ! hasBar ) {
		t.check( 'Minn bar renders on the front end', false, 'skip — bar not shown for this user' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'Minn bar renders on the front end', true, '' );

	// What sits at the bar's own corner: the bar itself, or something over it.
	const state = () => page.evaluate( () => {
		const c = document.getElementById( 'minn-cornerbar' );
		const r = c.getBoundingClientRect();
		const top = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
		return {
			z: getComputedStyle( c ).zIndex,
			behind: c.classList.contains( 'minn-bar-behind' ),
			barOnTop: !! ( top && c.contains( top ) ),
		};
	} );

	const idle = await state();
	t.check( 'bar is on top with nothing over the page', ! idle.behind && idle.barOnTop, JSON.stringify( idle ) );

	/* ===== A synthetic overlay: the rule itself, on any site ===== */
	await page.evaluate( () => {
		const o = document.createElement( 'div' );
		o.id = 'suite-overlay';
		o.style.cssText = 'position:fixed;inset:0;z-index:200;background:rgba(0,0,0,0.85);';
		document.body.appendChild( o );
	} );
	await page.waitForFunction( () =>
		document.getElementById( 'minn-cornerbar' ).classList.contains( 'minn-bar-behind' ),
		null, { timeout: 5000 } ).catch( () => {} );
	const covered = await state();
	t.check( 'bar steps behind a full-screen overlay', covered.behind && ! covered.barOnTop, JSON.stringify( covered ) );
	t.check( 'and the overlay is what covers its corner', covered.z === '1', covered.z );

	await page.evaluate( () => document.getElementById( 'suite-overlay' ).remove() );
	await page.waitForFunction( () =>
		! document.getElementById( 'minn-cornerbar' ).classList.contains( 'minn-bar-behind' ),
		null, { timeout: 5000 } ).catch( () => {} );
	const back = await state();
	t.check( 'bar returns when the overlay goes', ! back.behind && back.barOnTop, JSON.stringify( back ) );

	/* ===== A decorative layer must NOT push the bar away ===== */
	// pointer-events:none layers are never returned by elementFromPoint, which
	// is what keeps fixed background art from hiding the bar on ordinary pages.
	await page.evaluate( () => {
		const d = document.createElement( 'div' );
		d.id = 'suite-decor';
		d.style.cssText = 'position:fixed;inset:0;z-index:9999;pointer-events:none;background:transparent;';
		document.body.appendChild( d );
	} );
	await page.waitForTimeout( 700 );
	const decor = await state();
	t.check( 'a decorative full-screen layer leaves the bar alone', ! decor.behind && decor.barOnTop, JSON.stringify( decor ) );
	await page.evaluate( () => document.getElementById( 'suite-decor' ).remove() );

	/* ===== The real thing, where the page has one ===== */
	const zoom = page.locator( '.minn-zoom' ).filter( { visible: true } ).first();
	if ( await zoom.count() ) {
		await zoom.click();
		await page.waitForFunction( () =>
			document.getElementById( 'minn-cornerbar' ).classList.contains( 'minn-bar-behind' ),
			null, { timeout: 8000 } ).catch( () => {} );
		const lit = await state();
		t.check( 'bar steps behind the image lightbox', lit.behind && ! lit.barOnTop, JSON.stringify( lit ) );

		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () =>
			! document.getElementById( 'minn-cornerbar' ).classList.contains( 'minn-bar-behind' ),
			null, { timeout: 8000 } ).catch( () => {} );
		const closed = await state();
		t.check( 'bar comes back when the lightbox closes', ! closed.behind && closed.barOnTop, JSON.stringify( closed ) );
	} else {
		console.log( 'SKIP  image lightbox checks (no zoomable image on this page)' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
