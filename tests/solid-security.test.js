/**
 * Solid Security (better-wp-security) — lockout log in the Activity Log
 * family + System posture row.
 *
 * Proves: the shim lists {base_prefix}itsec_lockouts with locked / expired /
 * released pills derived from lockout_active + the GMT expiry, UTC dates
 * from the *_gmt columns, the "Locked out now" tab and host/username search,
 * Release routed through their own $itsec_lockout->release_lockout(), the
 * status card (active / all-time / bans / protection modules), and the
 * System health "Solid Security" brute-force posture row.
 *
 * Solid Security is INSTALLED-INACTIVE at rest (the Wordfence convention;
 * WSAL and LLA-R are the resident activity-log providers) — the suite
 * activates it and restores inactive in finally.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'solid-security' );

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return await r.json();
	}, typeof a === 'string' ? { path: a } : a );

	const setPlugin = ( status ) => page.evaluate( async ( s ) => {
		const id = 'better-wp-security/better-wp-security';
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return ( await r.json() ).status;
		} catch ( e ) {
			return 'dropped'; // heavy toggles can recycle the worker
		}
	}, status );

	const wasActive = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/better-wp-security/better-wp-security?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status === 'active';
	} );
	if ( ! wasActive ) {
		await setPlugin( 'active' );
		await page.waitForTimeout( 2000 );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 800 );
	}

	try {
		// Seed the deterministic lockout mix and poll for the active row.
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_solid: '1' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );
		let list = null;
		for ( let i = 0; i < 8; i++ ) {
			list = await api( 'minn-admin/v1/solid-security/lockouts' ).catch( () => null );
			if ( list && list.items && list.items.some( ( r ) => r.status === 'locked' && r.ip === '198.51.100.9' ) ) break;
			await page.waitForTimeout( 800 );
		}

		/* ===== Shim shape ===== */
		t.check( 'lockouts listed', !! list && list.total >= 3, JSON.stringify( list && list.total ) );
		const locked = list.items.find( ( r ) => r.status === 'locked' && r.ip === '198.51.100.9' );
		t.check( 'active lockout carries type/ip/UTC date', !! locked && locked.type === 'host' && /Z$/.test( locked.date ), JSON.stringify( locked ) );
		t.check( 'expired + released states derive', list.items.some( ( r ) => r.status === 'expired' && r.who === 'admin' )
			&& list.items.some( ( r ) => r.status === 'released' ) );
		const lockedOnly = await api( 'minn-admin/v1/solid-security/lockouts?kind=locked' );
		t.check( 'Locked-out-now tab filters', lockedOnly.items.length >= 1 && lockedOnly.items.every( ( r ) => r.status === 'locked' ) );
		const searched = await api( 'minn-admin/v1/solid-security/lockouts?search=admin' );
		t.check( 'search matches usernames', searched.items.length >= 1 && searched.items.every( ( r ) => r.who === 'admin' ) );

		/* ===== Status card + System posture row ===== */
		const stat = await api( 'minn-admin/v1/solid-security/status' );
		t.check( 'status card reports active count + protection modules',
			parseInt( stat.rows[ 0 ].value, 10 ) >= 1 && /Brute force/.test( stat.rows[ 3 ].value ), JSON.stringify( stat.rows ) );
		const sys = await api( 'minn-admin/v1/system' );
		const posture = ( sys.checks || [] ).find( ( c ) => c.label === 'Solid Security' );
		t.check( 'System posture row: brute force on', !! posture && posture.status === 'pass'
			&& /Brute force protection is on/.test( posture.detail ), JSON.stringify( posture ) );

		/* ===== Surface in the app + release ===== */
		await page.goto( `${ BASE }/minn-admin/solid-security`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'surface joins the activity-log family', await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'solid-security' );
			return !! s && s.family === 'activity-log' && s.sub === 'Solid Security';
		} ) );
		t.check( 'rows wear lifecycle pills', await page.$$eval( '.minn-table-row .minn-status', ( els ) => {
			const texts = els.map( ( e ) => e.textContent.trim() );
			return texts.includes( 'locked' ) && texts.includes( 'expired' ) && texts.includes( 'released' );
		} ) );

		// Expired row must not offer Release (when-gate).
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /203\.0\.113\.5/.test( r.textContent ) ).click();
		} );
		await page.waitForSelector( '.minn-modal', { timeout: 10000 } );
		await page.waitForTimeout( 600 );
		t.check( 'expired row offers no Release (when-gate)', await page.evaluate( () =>
			! [ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Release/.test( b.textContent ) ) ) );
		await page.click( '#minn-modal-close' );

		// Release the live one through their own API.
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /198\.51\.100\.9/.test( r.textContent ) && /locked/.test( r.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Release lockout/.test( b.textContent ) ),
		null, { timeout: 15000 } );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Release lockout/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /Released 198\.51\.100\.9/.test( x.textContent ) ),
		null, { timeout: 15000 } );
		t.check( 'release toast names the freed host', true );
		let released = false;
		for ( let i = 0; i < 10; i++ ) {
			await page.waitForTimeout( 700 );
			const check = await api( 'minn-admin/v1/solid-security/lockouts?kind=locked' );
			if ( check.items && ! check.items.some( ( r ) => r.ip === '198.51.100.9' ) ) { released = true; break; }
		}
		t.check( 'lockout released in their table', released );
	} finally {
		if ( ! wasActive ) {
			await setPlugin( 'inactive' );
		}
	}

	await t.done( browser, errors );
} )();
