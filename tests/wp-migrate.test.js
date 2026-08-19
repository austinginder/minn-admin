/**
 * The Migrate page (WP Migrate push / pull).
 *
 * This suite deliberately never runs a migration. A migration replaces a
 * whole database, and the dev site is a fixture set the rest of the suites
 * depend on, so the real push and pull are proven on the disposable
 * wpmsource / wpmdest labs instead. What is worth pinning here is
 * everything up to the point of no return: that the page is gated on WP
 * Migrate being present, that it describes the local end from the boot
 * payload rather than guessing, that connection info is validated before
 * anything is sent, and that the two directions say plainly which site
 * gets replaced.
 *
 * The last one matters most. The direction copy is the only thing standing
 * between "update the staging site" and "overwrite production", so it is
 * asserted rather than left to survive a refactor unnoticed.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'wp-migrate' );
	const { browser, page, errors } = await launch();
	await login( page );

	try {
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, { timeout: 20000 } );
		const boot = await page.evaluate( () => window.MINN.wpMigrate );

		if ( ! boot ) {
			t.check( 'SKIP: WP Migrate is not installed on this site', true, 'no boot payload' );
			await t.done( browser, errors );
			return;
		}

		// The nonce is what lets the page drive their chunked loop, so it
		// has to be there, and it must never ship without their capability.
		t.check( 'boot carries their REST base, ajax URL and migrate nonce',
			boot.restBase === 'mdb-api/v1' && !! boot.ajax && !! boot.nonce,
			JSON.stringify( { restBase: boot.restBase, ajax: !! boot.ajax, nonce: !! boot.nonce } ) );

		// The local end is described by their own code; a wrong table list
		// here would migrate the wrong set.
		const local = boot.local || {};
		t.check( 'the local end is described from their own site details',
			!! local.site_url && !! local.path && Array.isArray( local.tables ) && local.tables.length > 0,
			JSON.stringify( { site_url: local.site_url, path: local.path, tables: ( local.tables || [] ).length } ) );
		t.check( 'local tables are prefix-scoped',
			( local.tables || [] ).every( ( x ) => x.indexOf( local.prefix ) === 0 ),
			JSON.stringify( ( local.tables || [] ).slice( 0, 3 ) ) );

		await page.goto( BASE + '/minn-admin/migrate', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-mig', { timeout: 20000 } );

		t.check( 'Migrate is in the Tools nav', await page.evaluate( () =>
			/Migrate/.test( document.querySelector( '#minn-navgrp-tools' )?.textContent || '' ) ), '' );

		// Direction copy: which database gets replaced.
		const dirs = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-mig-dir' ) ]
			.map( ( d ) => ( { on: d.classList.contains( 'on' ), text: d.innerText.replace( /\s+/g, ' ' ).trim() } ) ) );
		t.check( 'push is the default direction', dirs[ 0 ] && dirs[ 0 ].on && /Push/.test( dirs[ 0 ].text ), JSON.stringify( dirs[ 0 ] ) );
		t.check( 'push says the OTHER site is replaced', /other site/i.test( dirs[ 0 ].text ), dirs[ 0 ].text );
		t.check( 'pull says THIS site is replaced', /this site/i.test( dirs[ 1 ].text ), dirs[ 1 ].text );

		await page.click( '[data-migintent="pull"]' );
		await page.waitForTimeout( 250 );
		const pulled = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-mig-dir' ) ].map( ( d ) => d.classList.contains( 'on' ) ) );
		t.check( 'the direction switch holds', pulled[ 1 ] === true && pulled[ 0 ] === false, JSON.stringify( pulled ) );
		await page.click( '[data-migintent="push"]' );
		await page.waitForTimeout( 250 );

		// Nothing may be sent until there is a connection.
		t.check( 'no run button before connecting', await page.evaluate( () => ! document.querySelector( '[data-migrun]' ) ), '' );

		// Garbage is refused in the browser, before any request goes out.
		let requested = false;
		await page.route( '**/mdb-api/**', ( route ) => { requested = true; route.abort(); } );
		await page.fill( '.minn-mig-conn-in', 'this is not a connection' );
		await page.click( '[data-migconnect]' );
		await page.waitForTimeout( 700 );
		const err = await page.evaluate( () => ( document.querySelector( '.minn-note.err' ) || {} ).textContent || '' );
		t.check( 'malformed connection info is refused without a request',
			! requested && /connection info/i.test( err ), JSON.stringify( { requested, err: err.trim().slice( 0, 80 ) } ) );

		// A well-formed address that is not reachable must surface their
		// failure rather than pretending it connected.
		await page.unroute( '**/mdb-api/**' );
		await page.route( '**/mdb-api/v1/verify-connection**', ( route ) =>
			route.fulfill( { status: 200, contentType: 'application/json',
				body: JSON.stringify( { success: false, data: 'Could not reach that site.' } ) } ) );
		await page.fill( '.minn-mig-conn-in', 'https://example.invalid\nabc123' );
		await page.click( '[data-migconnect]' );
		await page.waitForFunction( () => /Could not reach/.test( document.querySelector( '.minn-note.err' )?.textContent || '' ), { timeout: 15000 } );
		t.check( 'a refused connection shows WP Migrate’s own reason', true, '' );
		t.check( 'a refused connection offers no run button',
			await page.evaluate( () => ! document.querySelector( '[data-migrun]' ) ), '' );

	} finally {
		await page.unroute( '**/mdb-api/**' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
