/**
 * License visibility Phase 0 (adapters/licenses.php + the System page's
 * Licenses card). Read-only by design: the suite proves classification,
 * worst-first ordering, the health check, copy-report coverage and the
 * manage_options gate — never any activation path (none exists).
 *
 * The minn-dev-fixtures mu-plugin registers a synthetic provider behind the
 * REST-exposed minn_test_license option covering all five states, so no
 * real vendor's options are ever written. The baseline rows (AnalyticsWP,
 * Elementor Pro, Gravity Forms as "missing") come from real installed
 * fixtures; per the live-testing rule the suite finds rows by content,
 * never by position, and tolerates extra real rows.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'licenses' );
	const { browser, page, errors } = await launch();
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await login( page );

	// Write-then-verify with retries (REST settings writes can race the
	// app's parallel boot requests — site-kit suite rule).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_license: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_license;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const openSystem = async () => {
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-licenses', { timeout: 20000 } );
	};

	try {
		/* ===== Baseline: real fixtures classify as missing ===== */
		await openSystem();
		let text = await page.$eval( '#minn-sys-licenses', ( el ) => el.textContent );
		t.check( 'card lists a real paid component', /Elementor Pro/.test( text ) );
		t.check( 'unlicensed dev components read "No license"', /No license/.test( text ) );
		t.check( 'card states its read-only posture', /never the network/.test( text ) );
		const healthBase = await page.$$eval( '.minn-sys-check', ( els ) =>
			els.map( ( e ) => e.textContent ).find( ( s ) => /Licenses/.test( s ) ) || '' );
		t.check( 'Licenses health check present (warn: missing only)', /missing/.test( healthBase ) );

		/* ===== REST endpoint shape + summary ===== */
		const rest = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'REST endpoint answers for admin', rest.status === 200 );
		t.check( 'summary carries all five state counters',
			[ 'valid', 'expired', 'invalid', 'missing', 'unknown' ].every( ( k ) => k in rest.body.summary ) );
		t.check( 'items carry name + state + source',
			rest.body.items.length > 0 && rest.body.items.every( ( i ) => i.name && i.state && i.source ) );

		/* ===== Fixture provider: all five states render ===== */
		if ( ! await setOpt( true ) ) throw new Error( 'could not enable minn_test_license' );
		await openSystem();
		text = await page.$eval( '#minn-sys-licenses', ( el ) => el.textContent );
		t.check( 'fixture rows render', /Fixture Valid Pro/.test( text ) && /Fixture Unknown Theme/.test( text ) );
		const pill = async ( name ) => page.evaluate( ( n ) => {
			const row = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-sys-ext-item' ) ]
				.find( ( el ) => el.textContent.includes( n ) );
			const p = row && row.querySelector( '.minn-lic-pill' );
			return p ? { cls: p.className, label: p.textContent } : null;
		}, name );
		const pValid = await pill( 'Fixture Valid Pro' );
		const pExpired = await pill( 'Fixture Expired Pro' );
		const pInvalid = await pill( 'Fixture Invalid Pro' );
		const pMissing = await pill( 'Fixture Missing Pro' );
		const pUnknown = await pill( 'Fixture Unknown Theme' );
		t.check( 'valid pill', pValid && /valid/.test( pValid.cls ) && pValid.label === 'Valid' );
		t.check( 'expired pill', pExpired && /expired/.test( pExpired.cls ) && pExpired.label === 'Expired' );
		t.check( 'invalid pill', pInvalid && /invalid/.test( pInvalid.cls ) && pInvalid.label === 'Invalid' );
		t.check( 'missing pill', pMissing && /missing/.test( pMissing.cls ) && pMissing.label === 'No license' );
		t.check( 'unknown pill', pUnknown && /unknown/.test( pUnknown.cls ) && pUnknown.label === 'Unknown' );
		t.check( 'meta line: renews date', new RegExp( 'renews 2030-01-01' ).test( text ) );
		t.check( 'meta line: expired date', /expired 2024-01-01/.test( text ) );
		t.check( 'meta line: staleness admitted', /may be stale/.test( text ) );
		t.check( 'theme rows are badged', await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-sys-ext-item' ) ]
				.find( ( el ) => el.textContent.includes( 'Fixture Unknown Theme' ) );
			return row && /theme/.test( row.querySelector( '.minn-sys-ext-parent' )?.textContent || '' );
		} ) );

		/* ===== Worst-first ordering ===== */
		const order = await page.$$eval( '#minn-sys-licenses .minn-sys-ext-item', ( els ) =>
			els.map( ( e ) => e.querySelector( '.minn-lic-pill' ).className.replace( /.*minn-lic-pill\s*/, '' ) ) );
		const rank = { expired: 0, invalid: 1, missing: 2, unknown: 3, valid: 4 };
		t.check( 'rows sort worst first', order.every( ( s, i ) => i === 0 || rank[ order[ i - 1 ] ] <= rank[ s ] ), order.join( ',' ) );

		/* ===== Health check goes red with expired/invalid present ===== */
		const healthBad = await page.$$eval( '.minn-sys-check', ( els ) => {
			const el = els.find( ( e ) => /Licenses/.test( e.textContent ) );
			return el ? { cls: el.className, text: el.textContent } : null;
		} );
		t.check( 'health check fails on expired + invalid', healthBad && /fail/.test( healthBad.cls ) && /expired/.test( healthBad.text ) );

		/* ===== Copy report carries the section ===== */
		await page.click( '#minn-sys-copy' );
		await page.waitForTimeout( 400 );
		const report = await page.evaluate( () => navigator.clipboard.readText() );
		t.check( 'report has a Licenses section', /## Licenses \(stored state, not live\)/.test( report ) );
		t.check( 'report line carries state + expiry', /- Fixture Expired Pro: expired \(expires 2024-01-01\)/.test( report ) );
		t.check( 'report lists the license reader integration', /License reader minn-fixture-licenses/.test( report ) );

		/* ===== Integrations card lists the reader ===== */
		const intText = await page.$eval( '#minn-sys-integrations', ( el ) => el.textContent );
		t.check( 'Integrations card has License readers', /License readers/.test( intText ) && /Minn License Fixture/.test( intText ) );

		/* ===== manage_options gate ===== */
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		const editorStatus = await p2.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.status;
		} );
		t.check( 'editors get 403 from the endpoint', editorStatus === 403, String( editorStatus ) );
		await ctx2.close();

	} finally {
		await setOpt( false ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
