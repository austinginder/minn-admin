/**
 * Status/action editor panels (statusRoute descriptors).
 *
 * The minn-dev-fixtures mu-plugin arms a newsletter-shaped panel through the
 * public minn_admin_editor_panels filter (option minn_test_status_panel).
 * Covers: door renders with the server summary + tone, modal shows status
 * rows and actions, a plain action repaints door + modal from the action
 * response, a danger action goes through the themed confirm, and a fields
 * action collects its value into the action body. Server truth verified via
 * a fresh status GET at the end.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'editor-status-panel' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (REST settings writes can race the app's
	// parallel boot requests — the site-kit suite rule).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_status_panel: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_status_panel;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	let id = 0;
	try {
		t.check( 'fixture armed', await setOpt( true ) );
		id = await createPost( page, { title: 'Status panel probe', content: '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' } );
		await openEditor( page, id );

		const door = '[data-side-door="panel:fixture-status"]';
		await page.waitForSelector( door, { timeout: 15000 } );
		const doorState = await page.evaluate( ( sel ) => {
			const el = document.querySelector( sel );
			const sum = el.querySelector( '.minn-side-door-sum' );
			return { summary: sum ? sum.textContent : '', amber: !! ( sum && sum.classList.contains( 'amber' ) ) };
		}, door );
		t.check( 'door shows server summary', /Not sent · 5 subscribers/.test( doorState.summary ), doorState.summary );
		t.check( 'door summary carries the tone class', doorState.amber );

		// Open the panel: rows + note + three actions.
		await page.click( door );
		await page.waitForSelector( '.minn-status-panel', { timeout: 8000 } );
		const panel = await page.evaluate( () => {
			const root = document.querySelector( '.minn-status-panel' );
			return {
				note: ( root.querySelector( '.minn-status-note' ) || {} ).textContent || '',
				rows: Array.from( root.querySelectorAll( '.minn-sstat' ) ).map( ( r ) => ( {
					label: r.querySelector( '.minn-sstat-label' ).textContent,
					value: r.querySelector( '.minn-sstat-value' ).textContent,
				} ) ),
				actions: Array.from( root.querySelectorAll( '[data-panelact]' ) ).map( ( b ) => b.textContent ),
			};
		} );
		t.check( 'status rows render', panel.rows.some( ( r ) => r.label === 'Status' && r.value === 'Not sent' ), JSON.stringify( panel.rows ) );
		t.check( 'note renders', /never delivered/.test( panel.note ), panel.note );
		t.check( 'three actions render', panel.actions.length === 3, JSON.stringify( panel.actions ) );

		// Plain action: response status repaints modal + door in one round trip.
		await page.click( '.minn-status-panel [data-panelact="0"]' );
		await page.waitForFunction( () => {
			const root = document.querySelector( '.minn-status-panel' );
			if ( ! root ) return false;
			return Array.from( root.querySelectorAll( '.minn-sstat' ) )
				.some( ( r ) => /Recorded sends/.test( r.textContent ) && /1/.test( r.querySelector( '.minn-sstat-value' ).textContent ) );
		}, null, { timeout: 8000 } );
		t.check( 'plain action repaints modal from response', true );
		const doorAfter = await page.evaluate( ( sel ) => {
			const sum = document.querySelector( sel + ' .minn-side-door-sum' );
			return { summary: sum ? sum.textContent : '', green: !! ( sum && sum.classList.contains( 'green' ) ) };
		}, door );
		t.check( 'door summary updates too', /Sent 1 time/.test( doorAfter.summary ), doorAfter.summary );
		t.check( 'door tone flips to green', doorAfter.green );

		// Danger action rides the themed confirm (not native).
		await page.click( '.minn-status-panel [data-panelact="1"]' );
		await page.waitForSelector( '.minn-confirm-overlay [data-ok]', { timeout: 5000 } );
		t.check( 'danger action opens the themed confirm', true );
		await page.click( '.minn-confirm-overlay [data-ok]' );
		await page.waitForFunction( () => {
			const root = document.querySelector( '.minn-status-panel' );
			return root && Array.from( root.querySelectorAll( '.minn-sstat' ) )
				.some( ( r ) => /Recorded sends/.test( r.textContent ) && r.querySelector( '.minn-sstat-value' ).textContent === '2' );
		}, null, { timeout: 8000 } );
		t.check( 'confirmed danger action lands', true );

		// Fields action: button swaps for the inline form, value rides the body.
		await page.click( '.minn-status-panel [data-panelact="2"]' );
		await page.waitForSelector( '.minn-status-panel [data-actfield="email"]', { timeout: 5000 } );
		await page.fill( '.minn-status-panel [data-actfield="email"]', 'dana@example.com' );
		await page.click( '.minn-status-panel [data-actgo]' );
		await page.waitForFunction( () => {
			const root = document.querySelector( '.minn-status-panel' );
			return root && Array.from( root.querySelectorAll( '.minn-sstat' ) )
				.some( ( r ) => /Last recipient/.test( r.textContent ) && /dana@example\.com/.test( r.textContent ) );
		}, null, { timeout: 8000 } );
		t.check( 'fields action collects its value', true );

		// Server truth: the status route itself reports all three sends.
		const server = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/minn-test/status-panel/' + pid, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		}, id );
		t.check( 'server status reports 3 sends', /Sent 3 times/.test( server.summary || '' ), server.summary );
	} finally {
		await deletePost( page, id );
		await setOpt( false );
	}

	await t.done( browser, errors );
} )();
