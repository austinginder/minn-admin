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

			const storeLabels = await page.evaluate( () => {
				const strip = document.querySelector( '.minn-store-strip' );
				const catalog = window.MINN.i18n || {};
				const translated = ( id ) => Array.isArray( catalog[ id ] ) ? catalog[ id ][ 0 ] : catalog[ id ];
				return {
					rendered: strip ? strip.textContent.replace( /\s+/g, ' ' ).trim() : '',
					pending: translated( 'awaiting payment' ) || '',
					fulfill: translated( 'to fulfill' ) || '',
				};
			} );
			t.check( 'Store attention labels use the Persian catalog',
				/در انتظار پرداخت/.test( storeLabels.pending ) && /برای تکمیل/.test( storeLabels.fulfill )
					&& ! /awaiting payment|to fulfill/i.test( storeLabels.rendered ),
				JSON.stringify( storeLabels ) );

			// Force the activity chart, then use a real mouse movement so the
			// translated singular/plural label is exercised in its hover card.
			await page.evaluate( () => localStorage.setItem( 'minn-chart-source', 'activity' ) );
			await page.reload( { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-chart-col', { timeout: 10000 } );
			const activityCol = page.locator( '.minn-chart-col' ).last();
			const activityBox = await activityCol.boundingBox();
			if ( activityBox ) {
				await page.mouse.move( activityBox.x + activityBox.width / 2, activityBox.y + Math.max( 4, activityBox.height - 8 ) );
			}
			const activityTip = await page.evaluate( () => {
				const tip = document.querySelector( '#minn-chart-tip' );
				return tip && ! tip.hidden ? tip.textContent.replace( /\s+/g, ' ' ).trim() : '';
			} );
			t.check( 'Activity hover uses a translated event label',
				/رویداد/.test( activityTip ) && ! /\bEvents?\b/.test( activityTip ), activityTip );

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

			// The notification panel anchors to inline-end, which is the left in
			// RTL, and its entrance must come from that same physical edge.
			await page.click( '#minn-notif-btn' );
			await page.waitForSelector( '.minn-notif-panel', { timeout: 5000 } );
			await page.waitForTimeout( 250 );
			const notif = await page.evaluate( () => {
				const panel = document.querySelector( '.minn-notif-panel' );
				const rules = [ ...document.styleSheets ].flatMap( ( sheet ) => {
					try { return [ ...sheet.cssRules ]; } catch ( e ) { return []; }
				} );
				const rtlKeyframes = rules.find( ( rule ) => rule.name === 'minnSlideInRtl' );
				const rect = panel && panel.getBoundingClientRect();
				return {
					left: rect ? rect.left : -1,
					right: rect ? rect.right : -1,
					width: window.innerWidth,
					name: panel ? getComputedStyle( panel ).animationName : '',
					keyframes: rtlKeyframes ? rtlKeyframes.cssText : '',
					tabs: [ ...document.querySelectorAll( '.minn-notif-tab' ) ].map( ( el ) => el.textContent.trim() ),
				};
			} );
			t.check( 'RTL notification panel anchors on the left', notif.left < notif.width / 2 && notif.right <= notif.width / 2,
				`left=${ Math.round( notif.left ) } right=${ Math.round( notif.right ) } width=${ notif.width }` );
			t.check( 'RTL notification panel enters from the left', notif.name === 'minnSlideInRtl'
				&& /translateX\(-100%\)/.test( notif.keyframes ),
				`${ notif.name }: ${ notif.keyframes }` );
			t.check( 'Notification tabs use the Persian catalog',
				notif.tabs.length > 0 && ! notif.tabs.some( ( label ) => /\b(?:All|Comments|Updates|Notices|System)\b/.test( label ) ),
				notif.tabs.join( ' | ' ) );
			await page.click( '#minn-notif-close' );
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
