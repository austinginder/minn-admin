/**
 * i18n plumbing — translations ride the boot payload (B.i18n, built by
 * Minn_Admin::js_translations from JED files + the filter) into the SPA's
 * __()/_n()/sprintf helpers. The mu-fixture arms a handful of German strings
 * through the filter when minn_test_i18n is set, so this asserts the whole
 * pipeline (payload → helper → DOM) without shipping a translation file.
 *
 * English is the source vocabulary: the baseline pass also proves that an
 * empty catalog falls through to the literals.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'i18n' );

	// Settings writes can read stale for a beat (rule 47c) — write, then
	// verify with a cache-buster, retrying before letting the suite proceed.
	const setOpt = async ( val ) => {
		for ( let i = 0; i < 5; i++ ) {
			await page.evaluate( async ( v ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { minn_test_i18n: v } ),
				} );
			}, val );
			const read = await page.evaluate( async () => {
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_fields=minn_test_i18n&_=' + Date.now(), {
					credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce },
				} );
				return ( await r.json() ).minn_test_i18n;
			} );
			if ( read === val ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const shellState = () => page.evaluate( () => ( {
		overview: ( document.querySelector( '.minn-nav-btn[data-nav="overview"]' ) || {} ).textContent || '',
		content: ( document.querySelector( '.minn-nav-btn[data-nav="content"]' ) || {} ).textContent || '',
		group: ( document.querySelector( '[data-navgroup="workspace"]' ) || {} ).textContent || '',
		search: ( document.querySelector( '#minn-open-palette' ) || {} ).textContent || '',
		i18nKeys: Object.keys( window.MINN.i18n || {} ).length,
	} ) );

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );

		await setOpt( '' );

		// Baseline: English source strings, empty catalog.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
		let s = await shellState();
		t.check( 'Empty catalog serves as {}', s.i18nKeys === 0 );
		t.check( 'Baseline nav is English', /Overview/.test( s.overview ) && /Content/.test( s.content ) );
		t.check( 'Baseline group + search are English', /Workspace/.test( s.group ) && /Search…/.test( s.search ) );

		// Armed: fixture strings flow through the filter into the shell.
		t.check( 'Fixture option armed', await setOpt( '1' ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
		s = await shellState();
		t.check( 'Catalog rides the boot payload', s.i18nKeys > 0 );
		t.check( 'Nav labels translate', /Übersicht/.test( s.overview ) && /Inhalte/.test( s.content ) );
		t.check( 'Group label translates', /Arbeitsbereich/.test( s.group ) );
		t.check( 'Search placeholder translates', /Suchen…/.test( s.search ) );
		t.check( 'Topbar title translates (TITLES map)', await page.evaluate(
			() => /Übersicht/.test( ( document.querySelector( '#minn-title' ) || {} ).textContent || '' )
		) );
		t.check( 'Untranslated strings fall through to English', await page.evaluate(
			() => /Settings/.test( ( document.querySelector( '.minn-nav-btn[data-nav="settings"]' ) || {} ).textContent || '' )
		) );

		// Plural path: the Content topbar sub uses sprintf( _n( '%d type',
		// '%d types', n ), n ) — the fixture maps the plural pair to Typ/Typen.
		await page.click( '.minn-nav-btn[data-nav="content"]' );
		await page.waitForFunction(
			() => /Typ/.test( ( document.querySelector( '#minn-sub' ) || {} ).textContent || '' ),
			{ timeout: 20000 }
		);
		t.check( 'Plural entry renders via _n + sprintf', await page.evaluate(
			() => /\d+ Typen/.test( ( document.querySelector( '#minn-sub' ) || {} ).textContent || '' )
		) );
	} finally {
		await setOpt( '' ).catch( () => {} );
	}

	// Back to English after disarm.
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
	const s2 = await shellState();
	t.check( 'Disarmed shell is English again', /Overview/.test( s2.overview ) && s2.i18nKeys === 0 );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
