/**
 * Minn Bar — the opt-in front-end admin bar replacement.
 *
 * Per-user (minn_admin_appearance.frontBar, saved from Your profile): when on,
 * the public site swaps the classic admin bar for Minn's own quiet bar. The
 * properties under test:
 *   - strictly opt-in: default off leaves core's bar untouched
 *   - on: the Minn bar renders in its own corner, core's bar is gone, and the
 *     site keeps its original layout
 *   - the contextual Edit action targets the queried post's Minn editor
 *   - the search icon hands off to the app and the palette opens there
 *     (the bar claims NO global keyboard shortcut by design)
 *   - the status slot is exception-only: empty on a public site, a chip in
 *     maintenance mode, and the chip's fix really turns the mode off
 */
const { launch, login, createPost, deletePost, reporter, BASE, WP } = require( './helpers' );
const { execSync } = require( 'child_process' );

( async () => {
	const t = reporter( 'admin-bar' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
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
	const revealBar = async () => {
		await page.hover( '.minn-bar-markbtn' );
		await page.waitForTimeout( 320 );
	};

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
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		let s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
		} ) );
		t.check( 'default off: classic admin bar untouched, no Minn bar', ! s.minn && s.core, JSON.stringify( s ) );

		const saved = await setFrontBar( true );
		t.check( 'the appearance endpoint saves the frontBar opt-in', saved === true, String( saved ) );

		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
			shell: !! document.getElementById( 'minn-cornerbar' ),
			bodyClass: document.body.classList.contains( 'admin-bar' ),
			margin: getComputedStyle( document.documentElement ).marginTop,
			chip: !! document.querySelector( '.minn-bar-status' ),
		} ) );
		t.check( 'opted in: Corner Reveal owns its own shell and core is gone',
			s.minn && s.shell && ! s.bodyClass && ! s.core, JSON.stringify( s ) );
		t.check( 'desktop: Corner Reveal does not offset the site', s.margin === '0px', s.margin );
		const desktop = await page.evaluate( () => {
			const b = document.getElementById( 'minn-bar' );
			const r = b.getBoundingClientRect();
			return {
				top: r.top,
				left: r.left,
				width: r.width,
				height: r.height,
				vw: innerWidth,
				radius: getComputedStyle( b ).borderRadius,
			};
		} );
		t.check( 'desktop: Corner Reveal rests as a 46px corner control',
			desktop.top === 12 && desktop.left === 12 && desktop.width === 46
				&& desktop.height === 46 && desktop.radius === '14px',
			JSON.stringify( desktop ) );
		const siteMark = await page.evaluate( () => {
			const bar = document.getElementById( 'minn-bar' ).getBoundingClientRect();
			const r = document.querySelector( '.minn-bar-mark' ).getBoundingClientRect();
			return {
				left: r.left,
				top: r.top,
				width: r.width,
				height: r.height,
				insets: {
					top: r.top - bar.top,
					right: bar.right - r.right,
					bottom: bar.bottom - r.bottom,
					left: r.left - bar.left,
				},
			};
		} );
		t.check( 'the corner mark has equal padding on every side',
			siteMark.width === 36 && siteMark.height === 36
				&& Object.values( siteMark.insets ).every( ( inset ) => Math.abs( inset - 5 ) < 0.1 ),
			JSON.stringify( { desktop, siteMark } ) );
		await revealBar();
		const revealed = await page.evaluate( () => {
			const bar = document.getElementById( 'minn-bar' );
			const site = document.querySelector( '.minn-bar-site' );
			const actions = document.querySelector( '.minn-bar-right' );
			return {
				width: bar.getBoundingClientRect().width,
				max: innerWidth - 24,
				siteOpacity: getComputedStyle( site ).opacity,
				actionsOpacity: getComputedStyle( actions ).opacity,
			};
		} );
		t.check( 'desktop: hover reveals the complete natural-width control set',
			revealed.width > 300 && revealed.width <= revealed.max
				&& revealed.siteOpacity === '1' && revealed.actionsOpacity === '1',
			JSON.stringify( revealed ) );
		await page.mouse.move( 900, 500 );
		await page.evaluate( () => document.activeElement && document.activeElement.blur() );
		await page.waitForTimeout( 320 );
		await page.focus( '.minn-bar-markbtn' );
		await page.waitForTimeout( 320 );
		const keyboardWidth = await page.evaluate( () => document.getElementById( 'minn-bar' ).getBoundingClientRect().width );
		t.check( 'desktop: keyboard focus reveals the complete control set', keyboardWidth > 300, String( keyboardWidth ) );
		const controls = await page.evaluate( () => {
			const button = document.querySelector( '.minn-bar-iconbtn' );
			const icon = button.querySelector( 'svg' );
			const avatar = document.querySelector( '.minn-bar-avatar' );
			return {
				button: button.getBoundingClientRect().height,
				icon: icon.getBoundingClientRect().width,
				avatar: avatar.getBoundingClientRect().width,
				divider: document.querySelector( '.minn-bar-divider' ).getBoundingClientRect().height,
			};
		} );
		t.check( 'desktop: menu controls use the expanded concept proportions',
			controls.button === 38 && controls.icon === 19 && controls.avatar === 30 && controls.divider === 24,
			JSON.stringify( controls ) );

		// Theme headers often sit at z-index 99999 (Divi's #main-header,
		// the same rung as the classic admin bar). The Minn bar must paint
		// above them or the homepage looks like the bar is missing.
		await page.evaluate( () => {
			const h = document.createElement( 'header' );
			h.id = 'suite-theme-header';
			h.style.cssText = 'position:fixed;inset:0 0 auto;height:80px;z-index:99999;background:red;';
			document.body.appendChild( h );
		} );
		const aboveHeader = await page.evaluate( () => {
			const el = document.elementFromPoint( 40, 24 );
			return !!( el && el.closest && el.closest( '#minn-bar' ) );
		} );
		t.check( 'the bar sits above a theme header at z-index 99999', aboveHeader );
		await page.evaluate( () => {
			const h = document.getElementById( 'suite-theme-header' );
			if ( h ) h.remove();
		} );
		t.check( 'status slot is empty on a public production site', ! s.chip, JSON.stringify( s ) );

		const editHref = await page.evaluate( () => {
			const a = document.querySelector( '.minn-bar-edit' );
			return a ? a.getAttribute( 'href' ) : '';
		} );
		t.check( 'Edit action targets the Minn editor for this post',
			editHref.includes( '/minn-admin/editor/posts/' + postId ), editHref );

		// Site menu opens and keeps the honest escape hatch.
		await revealBar();
		await page.click( '.minn-bar-site' );
		await page.waitForTimeout( 250 );
		const menu = await page.evaluate( () => {
			const m = document.getElementById( 'minn-bar-menu-site' );
			return { open: m && ! m.hidden, text: m ? m.textContent : '' };
		} );
		t.check( 'site menu opens with the classic-admin escape',
			menu.open && /Classic admin/.test( menu.text ), JSON.stringify( menu ) );
		await page.mouse.move( 900, 500 );
		await page.evaluate( () => document.activeElement && document.activeElement.blur() );
		await page.waitForTimeout( 320 );
		const heldOpen = await page.evaluate( () => ( {
			menu: ! document.getElementById( 'minn-bar-menu-site' ).hidden,
			width: document.getElementById( 'minn-bar' ).getBoundingClientRect().width,
		} ) );
		t.check( 'an open menu keeps the reveal expanded away from the trigger',
			heldOpen.menu && heldOpen.width > 300, JSON.stringify( heldOpen ) );
		await page.keyboard.press( 'Escape' );
		const closed = await page.evaluate( () => document.getElementById( 'minn-bar-menu-site' ).hidden );
		t.check( 'Escape closes the bar menu (scoped, nothing else claimed)', closed === true, String( closed ) );

		// The corner control is persistent without moving the site around.
		await page.evaluate( () => {
			const tall = document.createElement( 'div' );
			tall.style.height = '3000px';
			document.body.appendChild( tall );
		} );
		await page.evaluate( () => window.scrollTo( 0, 800 ) );
		await page.mouse.move( 900, 500 );
		await page.waitForTimeout( 250 );
		const scrolled = await page.evaluate( () => {
			const b = document.getElementById( 'minn-bar' );
			const r = b.getBoundingClientRect();
			return { top: r.top, hidden: b.classList.contains( 'minn-bar-away' ) || b.classList.contains( 'minn-bar-yield' ) };
		} );
		t.check( 'the corner control stays put while scrolling', scrolled.top === 12 && ! scrolled.hidden, JSON.stringify( scrolled ) );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );

		// Notifications peek: rows are real — clicking one navigates into the
		// app at the thing it describes (updates land on Extensions, and so
		// on). Items vary by live site state, so an empty peek passes too.
		await revealBar();
		await page.click( '[data-barmenu="minn-bar-menu-notif"]' );
		await page.waitForFunction( () => {
			const w = document.getElementById( 'minn-bar-notif-items' );
			return w && ! /Loading/.test( w.textContent );
		}, null, { timeout: 20000 } );
		const notifRows = await page.$$( '[data-barnotif]' );
		if ( notifRows.length ) {
			await notifRows[ 0 ].click();
			await page.waitForFunction( () => location.pathname.includes( '/minn-admin' ), null, { timeout: 20000 } );
			t.check( 'a notification row navigates into the app', true, page.url() );
			await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		} else {
			t.check( 'a notification row navigates into the app', true, 'no notifications to click' );
			await page.keyboard.press( 'Escape' );
		}

		// Search icon opens the FRONT-END palette in place (click only — the
		// bar claims no global shortcut, and the page never navigates).
		await revealBar();
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
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await revealBar();
		await page.click( '[data-barmenu="minn-bar-menu-new"]' );
		await page.waitForSelector( '#minn-bar-menu-new:not([hidden])', { timeout: 10000 } );
		await page.click( '[data-barintent="new:posts"]', { noWaitAfter: true } );
		await page.waitForFunction( () => /\/minn-admin\/editor\/posts\/?$/.test( location.pathname ), null, { timeout: 60000 } );
		const intentCleared = await page.evaluate( () => sessionStorage.getItem( 'minn-intent' ) );
		t.check( 'the New intent opens a blank editor in the app and is one-shot',
			intentCleared === null, JSON.stringify( { url: page.url(), intentCleared } ) );

		// Maintenance mode: the chip appears, and its fix really turns it off.
		await setSetting( { minn_admin_maintenance: true } );
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const chip = await page.evaluate( () => {
			const c = document.querySelector( '.minn-bar-status' );
			return c ? { tone: c.dataset.tone, text: c.textContent.trim() } : null;
		} );
		t.check( 'maintenance mode raises an amber chip',
			chip && chip.tone === 'amber' && /Maintenance/.test( chip.text ), JSON.stringify( chip ) );
		await revealBar();
		await page.click( '.minn-bar-status' );
		await page.waitForSelector( '#minn-bar-status-fix', { timeout: 10000 } );
		await page.click( '#minn-bar-status-fix' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-bar-status' ), null, { timeout: 15000 } );
		// Server truth: a fresh page render decides the chip from the option.
		// (The settings GET is no oracle here: update_option stores boolean
		// false as '', which fails schema validation and reads back null.)
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const chipAfterFix = await page.evaluate( () => !! document.querySelector( '.minn-bar-status' ) );
		const maint = await getSetting( 'minn_admin_maintenance' );
		t.check( 'the chip fix turns maintenance mode off for real',
			! chipAfterFix && ( maint === false || maint === null ), JSON.stringify( { chipAfterFix, maint } ) );

		// Hidden from search: the informational blue chip.
		await setSetting( { blog_public: 0 } );
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const chip2 = await page.evaluate( () => {
			const c = document.querySelector( '.minn-bar-status' );
			return c ? { tone: c.dataset.tone, text: c.textContent.trim() } : null;
		} );
		t.check( 'discouraged search engines raise the blue chip',
			chip2 && chip2.tone === 'blue' && /Hidden from search/.test( chip2.text ), JSON.stringify( chip2 ) );
		await setSetting( { blog_public: 1 } );

		// Builder-aware Edit: a page whose canvas Elementor owns edits in
		// Elementor — Minn's editor would only open a read-only fence, and on
		// the front end "edit this page" means the tool that renders it.
		// Created through the captured auth — createPost() needs window.MINN,
		// which only exists inside the app, and the page is on the front end.
		draftId = await page.evaluate( async ( { a, title } ) => {
			const r = await fetch( a.rest + 'wp/v2/posts', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
				body: JSON.stringify( { title, content: '<p>Managed by a builder.</p>', status: 'publish' } ),
			} );
			return ( await r.json() ).id;
		}, { a: auth, title: 'Minn bar builder fixture ' + Date.now() } );
		execSync( `wp --path=${ WP } post meta update ${ draftId } _elementor_data "[]"`, { stdio: 'ignore' } );
		execSync( `wp --path=${ WP } post meta update ${ draftId } _elementor_edit_mode builder`, { stdio: 'ignore' } );
		const builderLink = await page.evaluate( async ( { a, id } ) => {
			const r = await fetch( a.rest + 'wp/v2/posts/' + id + '?_fields=link', {
				headers: { 'X-WP-Nonce': a.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).link;
		}, { a: auth, id: draftId } );
		await page.goto( builderLink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const builderEdit = await page.evaluate( () => {
			const a = document.querySelector( '.minn-bar-edit' );
			return a ? { href: a.getAttribute( 'href' ), text: a.textContent.trim() } : null;
		} );
		t.check( 'a builder-owned page edits in its builder',
			builderEdit && /Edit in Elementor/.test( builderEdit.text ) && builderEdit.href.includes( 'action=elementor' ),
			JSON.stringify( builderEdit ) );
		const builderCmd = await page.evaluate( () =>
			( ( window.MINN_BAR || {} ).commands || [] ).find( ( c ) => /Edit in Elementor/.test( c.title ) ) || null );
		t.check( 'the palette Edit command follows the builder too',
			builderCmd && builderCmd.value.includes( 'action=elementor' ), JSON.stringify( builderCmd ) );

		// Cache purge from the palette: the fixture provider's REST-exposed
		// counter proves a real purge ran, and the toast reports it.
		const purgeCount = () => page.evaluate( async ( a ) => {
			const r = await fetch( a.rest + 'wp/v2/settings', {
				headers: { 'X-WP-Nonce': a.nonce }, credentials: 'same-origin',
			} );
			return parseInt( ( await r.json() ).minn_fixture_cache_purged || '0', 10 );
		}, auth );
		const purgesBefore = await purgeCount();
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await revealBar();
		await page.click( '#minn-bar-search' );
		await page.waitForSelector( '#minn-bar-palette.open', { timeout: 10000 } );
		await page.keyboard.type( 'clear site' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-bar-pal-row' ) )
			.some( ( r ) => /Clear site cache/.test( r.textContent ) ), null, { timeout: 10000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-bar-pal-row' ) )
				.find( ( r ) => /Clear site cache/.test( r.textContent ) ).click();
		} );
		await page.waitForFunction( () => {
			const el = document.getElementById( 'minn-bar-toast' );
			return el && el.classList.contains( 'show' ) && /Cache cleared/.test( el.textContent );
		}, null, { timeout: 45000 } );
		const purgesAfter = await purgeCount();
		t.check( 'palette cache purge really purges (fixture counter moved)',
			purgesAfter > purgesBefore, purgesBefore + ' -> ' + purgesAfter );

		// Core does not inspect a theme's overlays and neither does Minn. This
		// avoids a polling loop and keeps the toolbar's visibility predictable.
		await page.evaluate( () => {
			const o = document.createElement( 'div' );
			o.id = 'suite-lightbox';
			o.style.cssText = 'position:fixed;inset:0;z-index:5000;background:rgba(0,0,0,.8);';
			document.body.appendChild( o );
		} );
		await page.waitForTimeout( 850 );
		const overlayState = await page.evaluate( () => {
			const b = document.getElementById( 'minn-bar' );
			return { top: b.getBoundingClientRect().top, yielded: b.classList.contains( 'minn-bar-yield' ) };
		} );
		t.check( 'a site overlay does not make Corner Reveal disappear',
			overlayState.top === 12 && ! overlayState.yielded, JSON.stringify( overlayState ) );
		await page.evaluate( () => document.getElementById( 'suite-lightbox' ).remove() );

		// Phones cannot hover, so the complete control set stays visible in a
		// contained corner overlay without changing the page offset.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const mob = await page.evaluate( () => {
			const b = document.getElementById( 'minn-bar' );
			const r = b.getBoundingClientRect();
			return {
				top: r.top,
				left: r.left,
				width: r.width,
				vw: innerWidth,
				height: r.height,
				radius: getComputedStyle( b ).borderRadius,
				margin: getComputedStyle( document.documentElement ).marginTop,
			};
		} );
		t.check( 'mobile: the always-revealed corner control fits the viewport',
			mob.top === 12 && mob.left === 12 && Math.abs( mob.width - ( mob.vw - 24 ) ) < 1
				&& mob.height === 46 && mob.radius === '14px',
			JSON.stringify( mob ) );
		t.check( 'mobile: Corner Reveal does not offset the site', mob.margin === '0px', mob.margin );
		await page.setViewportSize( { width: 1280, height: 800 } );

		// The Minn bar stays off builder canvases even when that builder is
		// currently inactive and therefore cannot suppress core's own bar.
		// Query flags are enough: Elementor uses elementor-preview, Brizy uses
		// is-editor-iframe on the front-end iframe inside
		// post.php?action=in-front-editor.
		const sep = permalink.includes( '?' ) ? '&' : '?';
		for ( const [ label, q ] of [
			[ 'Elementor preview', 'elementor-preview=' + postId ],
			[ 'Brizy editor iframe', 'is-editor-iframe=1' ],
		] ) {
			await page.goto( permalink + sep + q, { waitUntil: 'domcontentloaded', timeout: 60000 } );
			const canvas = await page.evaluate( () => ( {
				minn: !! document.getElementById( 'minn-bar' ),
				core: !! document.getElementById( 'wpadminbar' ),
				bump: !! document.getElementById( 'minn-bar-bump' ),
			} ) );
			t.check( label + ' canvas has no Minn bar or corner shell',
				! canvas.minn && ! canvas.bump, JSON.stringify( canvas ) );
		}

		// Off again: everything back to core.
		await setFrontBar( false );
		await page.goto( permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		s = await page.evaluate( () => ( {
			minn: !! document.getElementById( 'minn-bar' ),
			core: !! document.getElementById( 'wpadminbar' ),
		} ) );
		t.check( 'opting back out restores the classic bar', ! s.minn && s.core, JSON.stringify( s ) );
	} finally {
		try {
			await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
			await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
			await setSetting( { minn_admin_maintenance: false, blog_public: 1 } );
			await setFrontBar( false );
			if ( postId ) await deletePost( page, postId );
			if ( draftId ) await deletePost( page, draftId );
		} catch ( e ) {}
	}

	t.done( browser, errors );
} )();
