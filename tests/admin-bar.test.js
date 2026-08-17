/**
 * Minn Bar — the opt-in front-end admin bar replacement.
 *
 * Per-user (minn_admin_appearance.frontBar, saved from Your profile): when on,
 * the public site swaps the classic admin bar for Minn's own quiet bar. The
 * properties under test:
 *   - strictly opt-in: default off leaves core's bar untouched
 *   - on: the Minn bar renders, core's bar is gone, the page is pushed down
 *   - the contextual Edit action targets the queried post's Minn editor
 *   - the search icon hands off to the app and the palette opens there
 *     (the bar claims NO global keyboard shortcut by design)
 *   - the status slot is exception-only: empty on a public site, a chip in
 *     maintenance mode, and the chip's fix really turns the mode off
 */
const { launch, login, createPost, deletePost, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'admin-bar' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// The suite navigates between the app and the front end, so REST auth is
	// captured ONCE from the SPA boot payload — the nonce stays valid from any
	// same-origin page, while window.MINN only exists inside the app.
	const auth = await page.evaluate( () => ( { rest: window.MINN.restUrl, nonce: window.MINN.nonce } ) );
	const setFrontBar = ( on ) => page.evaluate( async ( { a, v } ) => {
		const r = await fetch( a.rest + 'minn-admin/v1/me/appearance', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
			body: JSON.stringify( { frontBar: v } ),
		} );
		return ( await r.json() ).frontBar;
	}, { a: auth, v: on } );
	const setSetting = ( body ) => page.evaluate( async ( { a, b } ) => {
		const r = await fetch( a.rest + 'wp/v2/settings', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
			body: JSON.stringify( b ),
		} );
		return r.status;
	}, { a: auth, b: body } );
	const getSetting = ( key ) => page.evaluate( async ( { a, k } ) => {
		const r = await fetch( a.rest + 'wp/v2/settings', {
			headers: { 'X-WP-Nonce': a.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() )[ k ];
	}, { a: auth, k: key } );

	let postId = null;
	let draftId = null;
	try {
		// A published fixture post gives the bar a singular front-end view.
		postId = await createPost( page, {
			title: 'Minn bar suite ' + Date.now(),
			content: '<p>Front-end fixture for the Minn Bar.</p>',
			status: 'publish',
		} );
		const permalink = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?_fields=link', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).link;
		}, postId );

		// Opt-in property: default off = classic bar untouched.
		await setFrontBar( false );
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		let s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
		} ) );
		t.check( 'default off: classic admin bar untouched, no Minn bar', ! s.minn && s.core, JSON.stringify( s ) );

		const saved = await setFrontBar( true );
		t.check( 'the appearance endpoint saves the frontBar opt-in', saved === true, String( saved ) );

		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
			margin: getComputedStyle( document.documentElement ).marginTop,
			chip: !! document.querySelector( '.minn-bar-status' ),
		} ) );
		t.check( 'opted in: Minn bar renders and the classic bar is gone', s.minn && ! s.core, JSON.stringify( s ) );
		t.check( 'the page is pushed below the floating bar', s.margin === '68px', s.margin );
		t.check( 'status slot is empty on a public production site', ! s.chip, JSON.stringify( s ) );

		const editHref = await page.evaluate( () => {
			const a = document.querySelector( '.minn-bar-edit' );
			return a ? a.getAttribute( 'href' ) : '';
		} );
		t.check( 'Edit action targets the Minn editor for this post',
			editHref.includes( '/minn-admin/editor/posts/' + postId ), editHref );

		// Site menu opens and keeps the honest escape hatch.
		await page.click( '.minn-bar-site' );
		await page.waitForTimeout( 250 );
		const menu = await page.evaluate( () => {
			const m = document.getElementById( 'minn-bar-menu-site' );
			return { open: m && ! m.hidden, text: m ? m.textContent : '' };
		} );
		t.check( 'site menu opens with the classic-admin escape',
			menu.open && /Classic admin/.test( menu.text ), JSON.stringify( menu ) );
		await page.keyboard.press( 'Escape' );
		const closed = await page.evaluate( () => document.getElementById( 'minn-bar-menu-site' ).hidden );
		t.check( 'Escape closes the bar menu (scoped, nothing else claimed)', closed === true, String( closed ) );

		// Auto-hide: scrolling down yields the top edge to the theme's own
		// sticky headers; scrolling up brings the bar back.
		await page.evaluate( () => {
			const tall = document.createElement( 'div' );
			tall.style.height = '3000px';
			document.body.appendChild( tall );
		} );
		await page.evaluate( () => window.scrollTo( 0, 800 ) );
		await page.waitForFunction( () => document.getElementById( 'minn-bar' ).classList.contains( 'minn-bar-away' ), null, { timeout: 5000 } );
		await page.evaluate( () => window.scrollTo( 0, 700 ) );
		await page.waitForFunction( () => ! document.getElementById( 'minn-bar' ).classList.contains( 'minn-bar-away' ), null, { timeout: 5000 } );
		t.check( 'the bar yields on scroll down and returns on scroll up', true );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );

		// Search icon opens the FRONT-END palette in place (click only — the
		// bar claims no global shortcut, and the page never navigates).
		await page.click( '#minn-bar-search' );
		await page.waitForSelector( '#minn-bar-palette.open', { timeout: 10000 } );
		const palState = await page.evaluate( () => ( {
			focused: document.activeElement && document.activeElement.id === 'minn-bar-pal-input',
			rows: document.querySelectorAll( '.minn-bar-pal-row' ).length,
			url: location.href,
		} ) );
		t.check( 'search icon opens the front-end palette in place',
			palState.focused && palState.rows >= 4 && ! palState.url.includes( '/minn-admin' ), JSON.stringify( palState ) );

		// Content search: the fixture post is findable and Enter opens its
		// Minn editor. Real keystrokes; results are debounced + async.
		await page.keyboard.type( 'Minn bar suite' );
		await page.waitForFunction( ( pid ) => Array.from( document.querySelectorAll( '.minn-bar-pal-row' ) )
			.some( ( r ) => r.textContent.includes( 'Minn bar suite' ) ), postId, { timeout: 15000 } );
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-bar-pal-row' ) )
				.find( ( r ) => r.textContent.includes( 'Minn bar suite' ) );
			row.click();
		} );
		await page.waitForFunction( () => location.href.includes( '/minn-admin/editor/' ), null, { timeout: 20000 } );
		t.check( 'a content result opens the Minn editor for that post',
			page.url().includes( '/minn-admin/editor/posts/' + postId ), page.url() );

		// Intent handoff: + New → Post lands in the app's blank editor
		// (newContent routes to editor/posts; nothing is created until the
		// first keystroke saves).
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		await page.click( '[data-barmenu="minn-bar-menu-new"]' );
		await page.waitForSelector( '#minn-bar-menu-new:not([hidden])', { timeout: 10000 } );
		await page.click( '[data-barintent="new:posts"]' );
		await page.waitForFunction( () => /\/minn-admin\/editor\/posts\/?$/.test( location.pathname ), null, { timeout: 30000 } );
		const intentCleared = await page.evaluate( () => sessionStorage.getItem( 'minn-intent' ) );
		t.check( 'the New intent opens a blank editor in the app and is one-shot',
			intentCleared === null, JSON.stringify( { url: page.url(), intentCleared } ) );

		// Maintenance mode: the chip appears, and its fix really turns it off.
		await setSetting( { minn_admin_maintenance: true } );
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		const chip = await page.evaluate( () => {
			const c = document.querySelector( '.minn-bar-status' );
			return c ? { tone: c.dataset.tone, text: c.textContent.trim() } : null;
		} );
		t.check( 'maintenance mode raises an amber chip',
			chip && chip.tone === 'amber' && /Maintenance/.test( chip.text ), JSON.stringify( chip ) );
		await page.click( '.minn-bar-status' );
		await page.waitForSelector( '#minn-bar-status-fix', { timeout: 10000 } );
		await page.click( '#minn-bar-status-fix' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-bar-status' ), null, { timeout: 15000 } );
		// Server truth: a fresh page render decides the chip from the option.
		// (The settings GET is no oracle here: update_option stores boolean
		// false as '', which fails schema validation and reads back null.)
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		const chipAfterFix = await page.evaluate( () => !! document.querySelector( '.minn-bar-status' ) );
		const maint = await getSetting( 'minn_admin_maintenance' );
		t.check( 'the chip fix turns maintenance mode off for real',
			! chipAfterFix && ( maint === false || maint === null ), JSON.stringify( { chipAfterFix, maint } ) );

		// Hidden from search: the informational blue chip.
		await setSetting( { blog_public: 0 } );
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		const chip2 = await page.evaluate( () => {
			const c = document.querySelector( '.minn-bar-status' );
			return c ? { tone: c.dataset.tone, text: c.textContent.trim() } : null;
		} );
		t.check( 'discouraged search engines raise the blue chip',
			chip2 && chip2.tone === 'blue' && /Hidden from search/.test( chip2.text ), JSON.stringify( chip2 ) );
		await setSetting( { blog_public: 1 } );

		// Off again: everything back to core.
		await setFrontBar( false );
		await page.goto( permalink, { waitUntil: 'domcontentloaded' } );
		s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
		} ) );
		t.check( 'opting back out restores the classic bar', ! s.minn && s.core, JSON.stringify( s ) );
	} finally {
		try {
			await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
			await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
			await setSetting( { minn_admin_maintenance: false, blog_public: 1 } );
			await setFrontBar( false );
			if ( postId ) await deletePost( page, postId );
			if ( draftId ) await deletePost( page, draftId );
		} catch ( e ) {}
	}

	t.done( browser, errors );
} )();
