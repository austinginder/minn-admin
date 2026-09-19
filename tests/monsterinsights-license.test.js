/**
 * MonsterInsights Pro license provider — activate / verify / deactivate
 * through the plugin's OWN MonsterInsights_License_Actions methods, with
 * only the vendor's license API mocked (mu-fixture minn_test_mi_license
 * answers www.monsterinsights.com/license-api with magic keys). Runs over
 * real REST, which is the point: the license store, the actions class and
 * the addons helper are wp-admin-only files the provider must require
 * itself, and WP-CLI (which loads them anyway) cannot prove that. The Pro
 * plugin rests installed-inactive with no key; the suite activates it and
 * restores everything, key included.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

const PRO = 'google-analytics-premium/googleanalytics-premium';
const LITE = 'google-analytics-for-wordpress/googleanalytics';
const ROW = 'MonsterInsights Pro';

( async () => {
	const t = reporter( 'monsterinsights-license' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path + ( a.path.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		const text = await r.text();
		let json = null;
		try { json = JSON.parse( text ); } catch ( e ) { /* not json */ }
		return { status: r.status, json, text };
	}, { method, path, body } );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await rest( 'POST', 'wp/v2/settings', { minn_test_mi_license: v } ).catch( () => null );
			const r = await rest( 'GET', 'wp/v2/settings' ).catch( () => null );
			if ( r && r.json && r.json.minn_test_mi_license === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};
	const setStatus = async ( id, status ) => {
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				const r = await rest( 'POST', 'wp/v2/plugins/' + id, { status } );
				if ( r.json && r.json.status === status ) return status;
			} catch ( e ) { /* dropped socket: verify below */ }
			await page.waitForTimeout( 4000 );
			const now = await rest( 'GET', 'wp/v2/plugins/' + id + '?_fields=status' ).catch( () => null );
			if ( now && now.json && now.json.status === status ) return status;
		}
		throw new Error( `plugin toggle failed: ${ id } -> ${ status }` );
	};
	const action = ( act, secret ) => rest( 'POST', 'minn-admin/v1/licenses/action', { provider: 'monsterinsights-pro', action: act, ...( secret ? { secret } : {} ) } );
	const rowViaRest = async () => {
		const r = await rest( 'GET', 'minn-admin/v1/licenses' );
		const list = ( r.json && ( r.json.items || r.json.licenses ) ) || [];
		return { row: list.find( ( i ) => ( i.name || '' ).startsWith( ROW ) ) || null, text: r.text };
	};

	const openLicenses = async () => {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="licenses"]', { timeout: 20000 } );
		await page.click( '[data-xtab="licenses"]' );
		await page.waitForFunction( ( n ) => [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.some( ( el ) => el.textContent.includes( n ) && el.querySelector( '[data-lic]' ) ), ROW, { timeout: 20000 } );
	};
	const uiRow = () => page.evaluate( ( n ) => {
		const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( n ) );
		if ( ! el ) return null;
		return {
			pill: ( el.querySelector( '.minn-lic-pill' ) || {} ).textContent || '',
			off: el.classList.contains( 'off' ),
			buttons: [ ...el.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ),
			text: el.textContent,
		};
	}, ROW );
	const clickRow = ( sel ) => page.evaluate( ( a ) => {
		const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( a.n ) );
		const b = el && el.querySelector( a.sel );
		if ( ! b ) throw new Error( 'missing ' + a.sel );
		b.click();
	}, { n: ROW, sel } );
	const waitToast = ( text ) => page.waitForFunction( ( s ) => document.body.textContent.includes( s ), text, { timeout: 15000 } );
	const rowReady = () => page.waitForFunction( ( n ) => {
		const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( n ) );
		return el && el.querySelector( '[data-lic]' );
	}, ROW, { timeout: 20000 } );

	const was = {};
	try {
		const plugins = ( await rest( 'GET', 'wp/v2/plugins?_fields=plugin,status' ) ).json || [];
		for ( const id of [ PRO, LITE ] ) {
			const p = plugins.find( ( x ) => x.plugin === id );
			was[ id ] = p ? p.status : null;
		}
		t.check( 'MonsterInsights Pro installed', was[ PRO ] !== null, String( was[ PRO ] ) );
		if ( was[ PRO ] === null ) throw new Error( 'google-analytics-premium not installed' );
		// Lite and Pro cannot run together; Pro deactivates Lite on load.
		if ( was[ LITE ] === 'active' ) await setStatus( LITE, 'inactive' );
		if ( was[ PRO ] !== 'active' ) await setStatus( PRO, 'active' );
		t.check( 'Fixture on (write verified)', await setOpt( '1' ) );

		// Start from no key: a prior crashed run may have left the magic key
		// stored; their deactivate against the mocked API clears it.
		let start = await rowViaRest();
		if ( start.row && start.row.key ) {
			await action( 'deactivate' );
			start = await rowViaRest();
		}
		t.check( 'row reads No license with all three controls attached (admin-only classes required under REST)', !! start.row && start.row.state === 'missing' && [ 'activate', 'deactivate', 'verify' ].every( ( c ) => ( start.row.can || [] ).includes( c ) ), JSON.stringify( start.row && { state: start.row.state, can: start.row.can } ) );

		/* ===== REST contract: codes and storage ===== */
		const bad = await action( 'activate', 'totally-wrong-key' );
		const afterBad = await rowViaRest();
		t.check( 'wrong key → invalid, nothing stored', bad.json && bad.json.ok === false && bad.json.code === 'invalid' && afterBad.row && afterBad.row.state === 'missing', JSON.stringify( bad.json && { ok: bad.json.ok, code: bad.json.code } ) );
		const limit = await action( 'activate', 'mi-fixture-limit' );
		t.check( 'activation-limit answer → site_limit', limit.json && limit.json.code === 'site_limit', JSON.stringify( limit.json && { ok: limit.json.ok, code: limit.json.code } ) );
		const expired = await action( 'activate', 'mi-fixture-expired' );
		t.check( 'expired answer → expired', expired.json && expired.json.code === 'expired', JSON.stringify( expired.json && { ok: expired.json.ok, code: expired.json.code } ) );

		/* ===== UI: paste a good key, verify, deactivate ===== */
		await openLicenses();
		let ui = await uiRow();
		t.check( 'UI row offers Activate on an unlicensed Pro', ui && ui.buttons.includes( 'activate' ), JSON.stringify( ui && ui.buttons ) );
		await clickRow( '[data-lic="activate"]' );
		await page.waitForSelector( '#minn-sys-licenses .minn-lic-key', { timeout: 10000 } );
		await page.evaluate( ( n ) => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( n ) );
			el.querySelector( '.minn-lic-key' ).focus( { preventScroll: true } );
		}, ROW );
		await page.keyboard.type( 'mi-fixture-valid' );
		await clickRow( '[data-lic-go]' );
		await waitToast( 'License activated' );
		await rowReady();
		await page.waitForFunction( ( n ) => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( n ) );
			return el && /valid/i.test( ( el.querySelector( '.minn-lic-pill' ) || {} ).textContent || '' );
		}, ROW, { timeout: 20000 } );
		ui = await uiRow();
		t.check( 'good key → Valid pill with plan and expiry in the meta', ui && /valid/i.test( ui.pill ) && /plan: pro/.test( ui.text ) && /20\d\d/.test( ui.text ), JSON.stringify( ui && { pill: ui.pill, text: ui.text.slice( 0, 160 ) } ) );
		const stored = await rowViaRest();
		t.check( 'pasted key never appears in GET /licenses', ! stored.text.includes( 'mi-fixture-valid' ) );
		t.check( 'expiry surfaced from the vendor answer', stored.row && /^\d{4}-\d{2}-\d{2}$/.test( stored.row.expires ), stored.row && stored.row.expires );

		await clickRow( '[data-lic="verify"]' );
		await waitToast( 'License re-verified' );
		await rowReady();
		t.check( 're-verify runs their forced validation and stays Valid', /valid/i.test( ( await uiRow() ).pill ) );

		await clickRow( '[data-lic="deactivate"]' );
		await page.waitForSelector( '.minn-confirm-overlay', { timeout: 10000 } );
		await page.click( '.minn-confirm-overlay [data-ok]' );
		await waitToast( 'License deactivated' );
		await page.waitForFunction( ( n ) => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ].find( ( r ) => r.textContent.includes( n ) );
			return el && /no license/i.test( ( el.querySelector( '.minn-lic-pill' ) || {} ).textContent || '' );
		}, ROW, { timeout: 20000 } );
		const gone = await rowViaRest();
		t.check( 'deactivate frees the seat with the vendor and deletes the option', gone.row && gone.row.state === 'missing' && ! gone.row.key );
	} finally {
		// Leave no key behind (the mocked API is still armed for this call).
		await action( 'deactivate' ).catch( () => {} );
		await setOpt( '' ).catch( () => {} );
		if ( was[ PRO ] && was[ PRO ] !== 'active' ) await setStatus( PRO, 'inactive' ).catch( () => {} );
		if ( was[ LITE ] === 'active' ) await setStatus( LITE, 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
