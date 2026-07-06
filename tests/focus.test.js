/**
 * Focus mode: toolbar toggle fades everything but the caret block via two
 * document.body overlays (never a class/style on content), the band follows
 * the caret, typewriter scroll recenters while typing, state persists via
 * localStorage, and toggling off removes every trace.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PARAS = Array.from( { length: 14 }, ( _, i ) =>
	`<!-- wp:paragraph --><p>Paragraph number ${ i + 1 } with enough words to hold a caret and a band.</p><!-- /wp:paragraph -->` ).join( '' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'focus' );
	await login( page );

	const id = await createPost( page, { title: 'Focus probe', content: PARAS, status: 'draft' } );
	await openEditor( page, id );

	/* ===== Toggle on with the caret in a paragraph ===== */
	await page.click( '#minn-editor-body p:nth-of-type(3)' );
	await page.click( '#minn-focus-btn' );
	await page.waitForSelector( '.minn-focus-dim', { timeout: 5000 } );
	t.check( 'toggle activates the button', await page.$eval( '#minn-focus-btn', ( b ) => b.classList.contains( 'active' ) ), '' );
	const band = await page.evaluate( () => {
		const [ top, bot ] = document.querySelectorAll( '.minn-focus-dim' );
		const p = document.querySelector( '#minn-editor-body p:nth-of-type(3)' ).getBoundingClientRect();
		return {
			dims: document.querySelectorAll( '.minn-focus-dim' ).length,
			inBody: !! document.querySelector( '#minn-editor-body .minn-focus-dim' ),
			topEnds: Math.round( top.getBoundingClientRect().bottom ),
			botStarts: Math.round( bot.getBoundingClientRect().top ),
			pTop: Math.round( p.top ), pBottom: Math.round( p.bottom ),
		};
	} );
	t.check( 'two overlays, none inside the typing surface', band.dims === 2 && ! band.inBody, JSON.stringify( band ) );
	t.check( 'band wraps the caret paragraph', band.topEnds <= band.pTop && band.botStarts >= band.pBottom, JSON.stringify( band ) );

	/* ===== Zen: nav + editor sidebar collapse ===== */
	await page.waitForTimeout( 400 ); // collapse transition
	const zen = await page.evaluate( () => ( {
		cls: document.body.classList.contains( 'minn-focus-zen' ),
		nav: document.querySelector( '.minn-sidebar' ).offsetWidth,
		side: document.querySelector( '.minn-editor-side' ).getBoundingClientRect().width,
	} ) );
	t.check( 'zen collapses the nav and editor sidebar', zen.cls && zen.nav < 10 && zen.side < 10, JSON.stringify( zen ) );

	/* ===== Band follows the caret ===== */
	await page.click( '#minn-editor-body p:nth-of-type(8)' );
	await page.waitForTimeout( 350 );
	const band2 = await page.evaluate( () => {
		const [ top, bot ] = document.querySelectorAll( '.minn-focus-dim' );
		const p = document.querySelector( '#minn-editor-body p:nth-of-type(8)' ).getBoundingClientRect();
		return { topEnds: Math.round( top.getBoundingClientRect().bottom ), botStarts: Math.round( bot.getBoundingClientRect().top ), pTop: Math.round( p.top ), pBottom: Math.round( p.bottom ) };
	} );
	t.check( 'band follows a caret move', band2.topEnds <= band2.pTop && band2.botStarts >= band2.pBottom && band2.pTop !== band.pTop, JSON.stringify( band2 ) );

	/* ===== Typewriter: typing with the caret block near the viewport edge
	   recenters it. Click first (Playwright auto-centers), then displace the
	   scroller so the block sits low, and type. ===== */
	await page.click( '#minn-editor-body p:nth-of-type(14)' );
	const before = await page.$eval( '.minn-scroll', ( s ) => { s.scrollTop -= 220; return s.scrollTop; } );
	await page.keyboard.type( ' typing at the bottom of the viewport now', { delay: 15 } );
	await page.waitForTimeout( 400 );
	const after = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
	t.check( 'typewriter scroll recenters the caret block', after > before, `before=${ before } after=${ after }` );

	/* ===== Nothing decorative reaches the database ===== */
	await page.keyboard.press( process.platform === 'darwin' ? 'Meta+s' : 'Control+s' );
	await page.waitForTimeout( 1500 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', {
			headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'saved markup carries no focus chrome', ! /minn-focus|style=/.test( saved ) && saved.includes( 'typing at the bottom' ), saved.slice( 0, 90 ) );

	/* ===== Persists across editor loads ===== */
	await openEditor( page, id );
	t.check( 'focus mode persists across loads', await page.$eval( '#minn-focus-btn', ( b ) => b.classList.contains( 'active' ) ), '' );

	/* ===== Toggle off removes every trace ===== */
	await page.click( '#minn-focus-btn' );
	await page.waitForTimeout( 250 );
	const off = await page.evaluate( () => ( {
		dims: document.querySelectorAll( '.minn-focus-dim' ).length,
		active: document.querySelector( '#minn-focus-btn' ).classList.contains( 'active' ),
		stored: !! localStorage.getItem( 'minn-focus' ),
		zen: document.body.classList.contains( 'minn-focus-zen' ),
		nav: document.querySelector( '.minn-sidebar' ).offsetWidth,
		side: document.querySelector( '.minn-editor-side' ).getBoundingClientRect().width,
	} ) );
	t.check( 'toggle off removes overlays, state and storage', off.dims === 0 && ! off.active && ! off.stored, JSON.stringify( off ) );
	t.check( 'toggle off restores the nav and editor sidebar', ! off.zen && off.nav > 100 && off.side > 100, JSON.stringify( off ) );

	/* ===== Leaving the editor with zen on restores the app chrome. The nav
	   is collapsed, so the honest exit is the ⌘K palette — which doubles as
	   proof the palette stays reachable in zen (SPA nav, not a reload). ===== */
	await page.click( '#minn-focus-btn' ); // zen back on
	await page.waitForTimeout( 400 );
	await page.click( '#minn-editor-body p:nth-of-type(2)' ); // collapsed caret → ⌘K = palette
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '.minn-palette-input, [class*=palette] input', { timeout: 5000 } );
	await page.keyboard.type( 'content', { delay: 20 } );
	await page.waitForTimeout( 400 );
	await page.keyboard.press( 'Enter' );
	await page.waitForSelector( '.minn-table-row, .minn-empty', { timeout: 15000 } );
	await page.waitForTimeout( 400 ); // nav restore transition
	const away = await page.evaluate( () => ( {
		zen: document.body.classList.contains( 'minn-focus-zen' ),
		nav: document.querySelector( '.minn-sidebar' ).offsetWidth,
		dims: document.querySelectorAll( '.minn-focus-dim' ).length,
	} ) );
	t.check( 'leaving the editor restores nav and drops overlays', ! away.zen && away.nav > 100 && away.dims === 0, JSON.stringify( away ) );
	// Leave focus mode OFF for whoever runs next in this profile.
	await openEditor( page, id );
	if ( await page.$eval( '#minn-focus-btn', ( b ) => b.classList.contains( 'active' ) ) ) {
		await page.click( '#minn-focus-btn' );
		await page.waitForTimeout( 300 );
	}

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
