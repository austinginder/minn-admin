/**
 * Surface setup gate (descriptor `setup` key). A surface whose plugin still
 * needs its own first-run install renders a setup card in place of the
 * collection — wizard choices as toggles, "Set up now" runs the plugin's own
 * installer server-side — and comes alive without a reload. Driven entirely
 * by the minn_test_setup_surface fixture so no real plugin's install state
 * is touched; Redirection is the real bundled consumer.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'surface-setup' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (REST settings writes race the app's
	// parallel boot requests — the site-kit-suite rule).
	const setOpt = async ( key, val ) => {
		for ( let i = 0; i < 5; i++ ) {
			const stored = await page.evaluate( async ( [ k, v ] ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify( { [ k ]: v } ) } );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
				return ( await r.json() )[ k ];
			}, [ key, val ] );
			// Core's settings controller reads a stored boolean false back as
			// null (get_option can't distinguish false from unset) — accept it.
			if ( String( stored ) === String( val ) || ( false === val && null === stored ) ) return true;
			await page.waitForTimeout( 600 );
		}
		return false;
	};
	const getOpt = ( key ) => page.evaluate( async ( k ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return ( await r.json() )[ k ];
	}, key );

	try {
		t.check( 'fixture armed, install state cleared',
			await setOpt( 'minn_test_setup_surface', true )
			&& await setOpt( 'minn_fixture_setup_done', false )
			&& await setOpt( 'minn_fixture_setup_choices', '' ) );

		/* ===== Gate replaces the collection ===== */
		await page.goto( BASE + '/minn-admin/minn-setup-fixture', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-setup', { timeout: 20000 } );
		let card = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-setup-title' ).textContent,
			note: document.querySelector( '.minn-setup-note' ).textContent,
			table: !! document.querySelector( '.minn-table' ),
			add: !! document.querySelector( '#minn-surface-add' ),
			navRow: !! [ ...document.querySelectorAll( '.minn-nav-btn' ) ].find( ( n ) => /Setup Fixture/.test( n.textContent ) ),
		} ) );
		t.check( 'setup card renders with the descriptor copy', /one-time setup/.test( card.title ) && /fixture tables/i.test( card.note ) );
		t.check( 'collection and create are unreachable behind the gate', ! card.table && ! card.add );
		t.check( 'surface still has its nav row', card.navRow );

		/* ===== Option toggles carry defaults, are flippable ===== */
		const states = () => page.evaluate( () =>
			[ ...document.querySelectorAll( '[data-setupopt]' ) ].map( ( s ) => [ s.dataset.setupopt, s.classList.contains( 'on' ) ] ) );
		t.check( 'toggles render the declared defaults', JSON.stringify( await states() ) === '[["alpha",true],["beta",false]]', JSON.stringify( await states() ) );
		await page.click( '[data-setupopt="beta"]' );
		t.check( 'toggle flips on click', JSON.stringify( await states() ) === '[["alpha",true],["beta",true]]' );

		/* ===== Run: card becomes the live surface without a reload ===== */
		await page.click( '#minn-setup-run' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const live = await page.evaluate( () => ( {
			gone: ! document.querySelector( '#minn-surface-setup' ),
			row: document.querySelector( '.minn-table-row' ).textContent,
		} ) );
		t.check( 'gate is gone and the collection renders in place', live.gone && /Fixture item one/.test( live.row ) );
		t.check( 'run persisted the install flag', true === ( await getOpt( 'minn_fixture_setup_done' ) ) );
		const choices = JSON.parse( ( await getOpt( 'minn_fixture_setup_choices' ) ) || '{}' );
		t.check( 'run received the toggle choices', true === choices.alpha && true === choices.beta, JSON.stringify( choices ) );

		/* ===== Fresh load skips the gate once installed ===== */
		await page.goto( BASE + '/minn-admin/minn-setup-fixture', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'reload goes straight to the collection', await page.evaluate( () => ! document.querySelector( '#minn-surface-setup' ) ) );

		/* ===== Endpoint honesty ===== */
		const already = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces/minn-setup-fixture/setup', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { choices: {} } ),
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'setup on an already-installed surface is a no-op', 200 === already.status && true === already.body.already, JSON.stringify( already ) );
		const missing = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces/no-such-surface/setup', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {} ),
			} );
			return r.status;
		} );
		t.check( 'unknown surface 404s', 404 === missing );
	} finally {
		await setOpt( 'minn_test_setup_surface', false );
		await setOpt( 'minn_fixture_setup_done', false );
		await setOpt( 'minn_fixture_setup_choices', '' );
	}

	await t.done( browser, errors );
} )();
