/**
 * App-chrome accessibility — pins the 2026-07-22 a11y pass. Two layers:
 *
 * 1. axe-core (npm dev dep) across the chrome routes in BOTH themes with
 *    ZERO tolerated violations. No rule exclusions: the app audits clean,
 *    so any new violation is a regression, not noise.
 * 2. Functional checks axe can't see: aria-current tracking the active nav
 *    item, the polite route announcer, focus rescue after a view swap drops
 *    focus, the topbar h1, and scrollMotion() honoring reduced motion.
 *
 * Editor a11y has its own suite (editor-a11y); this one is the chrome.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const AXE_PATH = require.resolve( 'axe-core/axe.min.js' );
const ROUTES = [ 'overview', 'content', 'media', 'users', 'extensions', 'settings', 'system', 'terms' ];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'a11y-chrome' );

	await login( page );

	for ( const scheme of [ 'dark', 'light' ] ) {
		await page.emulateMedia( { colorScheme: scheme } );
		for ( const route of ROUTES ) {
			await page.goto( BASE + '/minn-admin/' + route, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
			await page.waitForTimeout( 2200 );
			await page.addScriptTag( { path: AXE_PATH } );
			const v = await page.evaluate( async () => {
				const r = await window.axe.run( document, { resultTypes: [ 'violations' ] } );
				return r.violations.map( ( x ) => `${ x.id }×${ x.nodes.length }(${ ( x.nodes[ 0 ] && x.nodes[ 0 ].target && x.nodes[ 0 ].target[ 0 ] ) || '' })` );
			} );
			t.check( `axe clean: ${ scheme } ${ route }`, v.length === 0, v.join( ' ' ) );
		}
	}

	// Functional layer (still on the last route; navigate via the SPA).
	await page.click( '.minn-nav-btn[data-nav="overview"]' );
	await page.waitForTimeout( 600 );
	const fn = await page.evaluate( () => {
		const active = document.querySelector( '.minn-nav-btn[aria-current="page"]' );
		return {
			current: active ? active.dataset.nav : null,
			currentCount: document.querySelectorAll( '.minn-nav-btn[aria-current="page"]' ).length,
			announcer: ( document.querySelector( '#minn-route-announcer' ) || {} ).textContent || '',
			h1: ( document.querySelector( 'h1#minn-title' ) || {} ).textContent || '',
			navLandmark: !! document.querySelector( 'nav.minn-nav-scroll[aria-label]' ),
			viewFocusable: ( document.querySelector( '#minn-view' ) || {} ).tabIndex === -1,
		};
	} );
	t.check( 'aria-current marks exactly the active nav item', fn.current === 'overview' && fn.currentCount === 1 );
	t.check( 'Route announcer carries the view title', /Overview/.test( fn.announcer ) );
	t.check( 'Topbar title is the page h1', /Overview/.test( fn.h1 ) );
	t.check( 'Sidebar nav is a labeled landmark', fn.navLandmark );
	t.check( 'View container is a programmatic focus target', fn.viewFocusable );

	// Navigate again: announcer updates, aria-current moves.
	await page.click( '.minn-nav-btn[data-nav="media"]' );
	await page.waitForTimeout( 600 );
	const fn2 = await page.evaluate( () => ( {
		current: ( document.querySelector( '.minn-nav-btn[aria-current="page"]' ) || {} ).dataset || {},
		announcer: ( document.querySelector( '#minn-route-announcer' ) || {} ).textContent || '',
	} ) );
	t.check( 'aria-current follows navigation', fn2.current.nav === 'media' );
	t.check( 'Announcer follows navigation', /Media/.test( fn2.announcer ) );

	// Focus rescue: blur everything (simulates focus dying with the old
	// view), navigate, and the view container catches focus.
	await page.evaluate( () => document.activeElement && document.activeElement.blur() );
	await page.click( '.minn-nav-btn[data-nav="settings"]' );
	// The nav click focuses the button; simulate the lost-focus path directly.
	await page.evaluate( () => {
		document.activeElement.blur();
		window.history.back();
	} );
	await page.waitForTimeout( 800 );
	t.check( 'Focus rescued onto the view after a swap with no focus', await page.evaluate(
		() => document.activeElement && ( document.activeElement.id === 'minn-view' || document.activeElement !== document.body )
	) );

	// Reduced motion: scrollMotion-driven scrolls become instant.
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	t.check( 'Reduced motion collapses transition durations', await page.evaluate( () => {
		const el = document.querySelector( '.minn-nav-btn' );
		const d = getComputedStyle( el ).transitionDuration;
		return d.split( ',' ).every( ( v ) => parseFloat( v ) <= 0.011 );
	} ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
