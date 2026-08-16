/**
 * Settings-shaped surfaces fold into one "Site Options" item instead of each
 * claiming a top-level nav slot.
 *
 * Top-level navigation is a budget, not a free-for-all: a surface that is
 * nothing but a settings screen, and names no family to belong to, is site
 * configuration and belongs with the rest of it. This is the sibling of the
 * workspace guard — that one stops a surface claiming a group it has not
 * earned, this one stops configuration bleeding into the sidebar an item at
 * a time as plugins are added.
 *
 * A family is the opt-out and it is the honest one: Perfmatters is
 * settings-only but belongs to `performance` alongside Performance Lab, so
 * it stays on that topic's item rather than being filed under options.
 *
 * Fixture: `minn_test_options_fold` arms a settings-ONLY, family-less
 * surface ("Widget Kit") with its own route, so the fold has a guest to
 * fold. Without ACF options pages on the site there is only one such
 * surface and nothing to merge, so the suite SKIPs.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'site-options-fold' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const setOpt = ( on ) => page.evaluate( async ( v ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/settings', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { minn_test_options_fold: v } ),
		} );
	}, on );

	try {
		await setOpt( true );
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.surfaces, null, { timeout: 20000 } );

		const surfaces = await page.evaluate( () => ( window.MINN.surfaces || [] ).map( ( s ) => ( {
			id: s.id, label: s.label, family: s.family || '',
			settingsOnly: ! s.collection && !! s.settings,
			tabs: ( s.settings && s.settings.tabs || [] ),
		} ) ) );
		const host = surfaces.find( ( s ) => s.label === 'Site Options' );
		if ( ! host ) {
			console.log( 'SKIP: nothing to fold on this site (needs two settings-only surfaces)' );
			await setOpt( false );
			await browser.close().catch( () => {} );
			process.exit( 0 );
		}

		t.check( 'the guest claims no nav item of its own',
			! surfaces.some( ( s ) => s.label === 'Widget Kit' ), JSON.stringify( surfaces.map( ( s ) => s.label ) ) );
		const guest = host.tabs.find( ( x ) => x.label === 'Widget Kit' );
		t.check( 'it became a tab on Site Options instead', !! guest, JSON.stringify( host.tabs.map( ( x ) => x.label ) ) );

		// Ids are only unique WITHIN a surface, so a guest keeping its own
		// tab-0 would load the host's first screen instead.
		t.check( 'the guest tab id cannot collide with the host\'s',
			!! guest && host.tabs.filter( ( x ) => x.id === guest.id ).length === 1 && guest.id !== 'tab-0', guest && guest.id );
		t.check( 'the guest tab carries its own route',
			!! guest && /minn-fold\/settings/.test( guest.route || '' ), guest && guest.route );

		// A family is the opt-out: settings-only, but part of a topic.
		const perf = surfaces.find( ( s ) => s.id === 'perfmatters' );
		if ( perf ) {
			t.check( 'a settings-only surface WITH a family keeps its own home',
				perf.family === 'performance' && perf.label !== 'Site Options', JSON.stringify( perf ) );
		}

		/* ===== The folded tab is a real screen, not a link ===== */
		await page.goto( BASE + '/minn-admin/' + host.id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ssettab]', { timeout: 20000 } );
		await page.click( `[data-ssettab="${ guest.id }"]` );
		await page.waitForSelector( '.minn-surface-settings [data-sset="minn_fold_probe"]', { timeout: 15000 } );
		t.check( 'the folded tab renders its own fields', true );

		const stamp = 'folded ' + Date.now();
		await page.fill( '.minn-surface-settings [data-sset="minn_fold_probe"]', stamp );
		const wait = page.waitForResponse( ( r ) => r.request().method() === 'POST'
			&& /minn-fold\/settings/.test( r.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		t.check( 'it saves through its OWN endpoint, nothing proxied', res.status() === 200, String( res.status() ) );
		await page.waitForTimeout( 600 );
		const stored = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/settings', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).minn_fold_probe;
		} );
		t.check( 'the value really landed', stored === stamp, stored );

		// The host's own tabs are untouched by having a guest.
		await page.click( `[data-ssettab="${ host.tabs[ 0 ].id }"]` );
		await page.waitForSelector( '.minn-surface-settings [data-sset]', { timeout: 15000 } );
		const hostFields = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-surface-settings [data-sset]' ) ].map( ( e ) => e.dataset.sset ) );
		t.check( 'the host\'s own tabs still load their own fields',
			hostFields.length > 0 && ! hostFields.includes( 'minn_fold_probe' ), JSON.stringify( hostFields.slice( 0, 3 ) ) );
	} finally {
		await setOpt( false ).catch( () => {} );
	}

	t.done( browser, errors );
} )();
