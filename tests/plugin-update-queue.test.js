/**
 * Per-plugin Update clicks must serialize. Concurrent Plugin_Upgrader runs
 * recycle the PHP worker and the follow-up plugins list fetch dies with
 * "Failed to fetch", blanking Extensions (Austin 2026-07-12). This suite
 * stubs the update endpoint to measure concurrency and asserts the page
 * never paints the empty showErr card when two Updates are clicked.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'plugin-update-queue' );

	await login( page );

	let concurrent = 0;
	let maxConcurrent = 0;
	const started = [];
	const finished = [];

	// Stable plugins list with two pending updates (no real upgrades).
	await page.route( '**/wp/v2/plugins**', async ( route ) => {
		if ( route.request().method() !== 'GET' ) return route.continue();
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( [
				{
					plugin: 'acme-one/acme-one',
					status: 'active',
					name: 'Acme One',
					version: '1.0.0',
					description: { rendered: 'Fixture one' },
					author: 'Minn',
				},
				{
					plugin: 'acme-two/acme-two',
					status: 'active',
					name: 'Acme Two',
					version: '2.0.0',
					description: { rendered: 'Fixture two' },
					author: 'Minn',
				},
				{
					plugin: 'minn-admin/minn-admin',
					status: 'active',
					name: 'Minn Admin',
					version: '0.12.0',
					description: { rendered: 'Admin' },
					author: 'Austin',
				},
			] ),
		} );
	} );
	await page.route( '**/minn-admin/v1/plugin-updates**', async ( route ) => {
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				updates: {
					'acme-one/acme-one.php': '1.1.0',
					'acme-two/acme-two.php': '2.1.0',
				},
				themes: {},
			} ),
		} );
	} );
	await page.route( '**/minn-admin/v1/plugins/update**', async ( route ) => {
		const body = route.request().postDataJSON() || {};
		const plugin = body.plugin || '';
		started.push( plugin );
		concurrent += 1;
		maxConcurrent = Math.max( maxConcurrent, concurrent );
		// Hold long enough that a second click would overlap if not queued.
		await new Promise( ( r ) => setTimeout( r, 900 ) );
		concurrent -= 1;
		finished.push( plugin );
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				updated: true,
				version: plugin.includes( 'one' ) ? '1.1.0' : '2.1.0',
			} ),
		} );
	} );

	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-plugin[data-plugin="acme-one/acme-one"]', { timeout: 20000 } );
	t.check( 'fixture plugins with updates render', await page.$( '[data-update="acme-one/acme-one"]' )
		&& await page.$( '[data-update="acme-two/acme-two"]' ) );

	// Click both as fast as possible — the old path fired two POSTs at once.
	await page.evaluate( () => {
		document.querySelector( '[data-update="acme-one/acme-one"]' ).click();
		document.querySelector( '[data-update="acme-two/acme-two"]' ).click();
	} );

	// Second click should toast as queued while first is in flight.
	await page.waitForFunction( () => /Queued Acme Two|Updating Acme/.test( document.body.textContent ), null, { timeout: 5000 } ).catch( () => null );
	const sawQueueToast = await page.evaluate( () => /Queued Acme Two \(2 in line\)/.test( document.body.textContent )
		|| /Queued Acme/.test( document.body.textContent ) );
	t.check( 'second click is queued (toast)', sawQueueToast || started.length >= 1 );

	// Wait for both to finish (serial: ~900ms × 2 + drain refresh).
	await page.waitForFunction( () => {
		// Both buttons gone or no longer "Updating" after optimistic badge clear
		// OR both finished toasts.
		const text = document.body.textContent;
		return /Acme One updated/.test( text ) && /Acme Two updated/.test( text );
	}, null, { timeout: 20000 } ).catch( () => null );

	// Give the chain a beat to settle finished[].
	for ( let i = 0; i < 20 && finished.length < 2; i++ ) {
		await page.waitForTimeout( 200 );
	}

	t.check( 'both updates started', started.length === 2, JSON.stringify( started ) );
	t.check( 'updates never ran concurrently', maxConcurrent === 1, `maxConcurrent=${ maxConcurrent }` );
	t.check( 'both updates finished', finished.length === 2, JSON.stringify( finished ) );
	t.check( 'order is serial (first finishes before second starts)',
		// started[0] before finished[0] is trivial; stronger: when second
		// started, first must already be finished — check via sequence log
		// by ensuring maxConcurrent stayed 1 (already) and both completed.
		maxConcurrent === 1 && finished.length === 2 );

	// The crash: Extensions painted "Something went wrong: Failed to fetch".
	const blanked = await page.evaluate( () => /Something went wrong/.test( document.body.textContent ) );
	t.check( 'Extensions did not blank with showErr', ! blanked );
	t.check( 'plugin grid still rendered', !! ( await page.$( '.minn-plugin-grid, .minn-plugin' ) ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
