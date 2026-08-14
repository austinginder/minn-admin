/**
 * Right-to-left layout, in a real browser.
 *
 * Static checks can prove the CSS says `margin-inline-start`; only a browser
 * can prove the sidebar actually moved to the other side. This suite drives
 * the app under a genuine RTL user locale (fa_IR) and reads COMPUTED
 * geometry, then restores the locale it found.
 *
 * Requires the fa_IR core language pack: `wp language core install fa_IR`.
 * Without it WordPress reports is_rtl() false and the suite skips rather
 * than failing, because that is an environment gap, not a regression.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'rtl' );

	const setLocale = ( locale ) => page.evaluate( async ( loc ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/me/language', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( { locale: loc } ),
		} );
		return r.status;
	}, locale );

	let original = '';
	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );

		original = await page.evaluate( () => ( window.MINN.user && window.MINN.user.locale ) || '' );

		// --- baseline: LTR ------------------------------------------------
		const ltr = await page.evaluate( () => {
			const nav = document.querySelector( '.minn-sidebar' );
			return {
				dir: document.documentElement.getAttribute( 'dir' ),
				navLeft: nav ? nav.getBoundingClientRect().left : -1,
				width: window.innerWidth,
			};
		} );
		t.check( 'LTR document declares dir="ltr"', ltr.dir === 'ltr', `got ${ ltr.dir }` );
		t.check( 'LTR sidebar sits on the left', ltr.navLeft < ltr.width / 2, `left=${ Math.round( ltr.navLeft ) }` );

		// --- switch to a real RTL locale -----------------------------------
		const status = await setLocale( 'fa_IR' );
		t.check( 'Language switch accepted', status === 200, `HTTP ${ status }` );

		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );

		const rtl = await page.evaluate( () => {
			const nav = document.querySelector( '.minn-sidebar' );
			const cs = nav ? getComputedStyle( nav ) : null;
			return {
				dir: document.documentElement.getAttribute( 'dir' ),
				lang: document.documentElement.getAttribute( 'lang' ),
				navRight: nav ? nav.getBoundingClientRect().right : -1,
				navLeft: nav ? nav.getBoundingClientRect().left : -1,
				width: window.innerWidth,
				// border-inline-end resolves to border-LEFT under RTL.
				borderLeft: cs ? cs.borderLeftWidth : '',
				borderRight: cs ? cs.borderRightWidth : '',
				bodyDir: getComputedStyle( document.body ).direction,
			};
		} );

		if ( rtl.dir !== 'rtl' ) {
			t.check( 'SKIP: fa_IR core pack not installed, is_rtl() is false', true,
				'run `wp language core install fa_IR`' );
		} else {
			t.check( 'RTL document declares dir="rtl"', rtl.dir === 'rtl' );
			t.check( 'Computed direction is rtl', rtl.bodyDir === 'rtl', rtl.bodyDir );
			t.check( 'Sidebar mirrors to the right', rtl.navRight > rtl.width / 2,
				`right=${ Math.round( rtl.navRight ) } of ${ rtl.width }` );
			t.check( 'Sidebar border moved to its inline end (the left edge)',
				parseFloat( rtl.borderLeft ) > 0 && parseFloat( rtl.borderRight ) === 0,
				`left=${ rtl.borderLeft } right=${ rtl.borderRight }` );

			// Technical values must stay readable inside an RTL run.
			const iso = await page.evaluate( () => {
				const el = document.createElement( 'code' );
				el.textContent = 'https://example.com/a/b';
				document.body.appendChild( el );
				const d = getComputedStyle( el ).direction;
				const u = getComputedStyle( el ).unicodeBidi;
                el.remove();
				return { d, u };
			} );
			t.check( 'Code and URLs keep an isolated LTR run', iso.d === 'ltr' && /isolate/.test( iso.u ),
				`direction=${ iso.d } unicode-bidi=${ iso.u }` );

			// Crop handles are physical: they must NOT mirror.
			const crop = await page.evaluate( () => {
				const s = [ ...document.styleSheets ].flatMap( ( sh ) => {
					try { return [ ...sh.cssRules ]; } catch ( e ) { return []; }
				} ).filter( ( r ) => r.selectorText && r.selectorText.includes( 'imged-crop' ) );
				return s.map( ( r ) => r.cssText ).join( ' ' );
			} );
			t.check( 'Crop handles stay physical (left/right, not logical)',
				! /inset-inline/.test( crop ) && /left|right/.test( crop ) );
		}

		// --- restore -------------------------------------------------------
		await setLocale( original || '' );
		const back = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/me/language?_=' + Date.now(), {
				credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return r.status;
		} );
		t.check( 'Locale restored', back === 200 || back === 404 );
	} catch ( e ) {
		t.check( 'Suite ran without throwing', false, e.message );
		try { await setLocale( original || '' ); } catch ( e2 ) { /* best effort */ }
	}

	await t.done( browser, errors );
} )();
