/**
 * Per-user accent palette (user meta minn_admin_appearance).
 * Boot exposes user.appearance; POST /me/appearance saves; profile swatches
 * apply data-accent (and custom CSS vars) and survive a reload.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'appearance' );
	await login( page );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	// Reset to Minn default so the suite is idempotent.
	await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'minn', custom: '' } ),
	} );

	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.user, { timeout: 15000 } );

	const boot = await page.evaluate( () => window.MINN.user.appearance );
	t.check( 'boot carries user.appearance', !! boot && typeof boot.accent === 'string', JSON.stringify( boot ) );
	t.check( 'default accent is minn', boot.accent === 'minn', JSON.stringify( boot ) );

	const got = await rest( 'minn-admin/v1/me/appearance' );
	t.check( 'GET me/appearance returns 200', got.status === 200, String( got.status ) );
	t.check( 'GET shape has accent+custom', got.body && got.body.accent === 'minn' && got.body.custom === '', JSON.stringify( got.body ) );

	const ocean = await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'ocean' } ),
	} );
	t.check( 'POST ocean saves', ocean.status === 200 && ocean.body && ocean.body.accent === 'ocean', JSON.stringify( ocean.body ) );

	// Live apply after save via UI path: open profile and click a swatch.
	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-user-area', { timeout: 15000 } );
	await page.click( '#minn-user-area' );
	await page.waitForSelector( '.minn-accent-swatch[data-accent="forest"]', { timeout: 15000 } );
	const hasSwatches = !! ( await page.$( '.minn-accent-swatch[data-accent="forest"]' ) );
	t.check( 'profile shows accent swatches', hasSwatches );

	if ( hasSwatches ) {
		await page.click( '.minn-accent-swatch[data-accent="forest"]' );
		// Wait for the meta write (not only optimistic data-accent).
		await page.waitForFunction( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/me/appearance', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			const j = await r.json().catch( () => null );
			return j && j.accent === 'forest';
		}, { timeout: 10000 } );
		t.check( 'forest swatch sets data-accent', await page.evaluate( () =>
			document.documentElement.getAttribute( 'data-accent' ) ) === 'forest' );

		// Persist across full reload (meta + pre-paint).
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.user, { timeout: 15000 } );
		const after = await page.evaluate( () => ( {
			boot: window.MINN.user.appearance,
			attr: document.documentElement.getAttribute( 'data-accent' ),
		} ) );
		t.check( 'reload boot still forest', after.boot && after.boot.accent === 'forest', JSON.stringify( after.boot ) );
		t.check( 'reload data-accent still forest', after.attr === 'forest', after.attr );
	}

	// Custom hex.
	const custom = await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'custom', custom: '#2a9d8f' } ),
	} );
	t.check( 'POST custom saves hex', custom.body && custom.body.accent === 'custom' && custom.body.custom === '#2a9d8f', JSON.stringify( custom.body ) );

	const bad = await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'not-a-color' } ),
	} );
	t.check( 'invalid accent falls back to minn', bad.body && bad.body.accent === 'minn', JSON.stringify( bad.body ) );

	const noHex = await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'custom', custom: 'nope' } ),
	} );
	t.check( 'custom without valid hex falls back to minn', noHex.body && noHex.body.accent === 'minn', JSON.stringify( noHex.body ) );

	// Restore default for the account.
	await rest( 'minn-admin/v1/me/appearance', {
		method: 'POST', body: JSON.stringify( { accent: 'minn', custom: '' } ),
	} );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
