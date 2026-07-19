/**
 * Shared confirm modal — typed confirmation for irreversible deletes.
 *
 * Drives the real plugin-delete flow against a disposable wp.org install
 * (Hello Dolly): Escape cancels harmlessly, the confirm button stays
 * disabled until the exact word is typed, and confirming deletes for real.
 * The Update-everything scope disclosure is pinned by
 * core-update-visibility.test.js.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'confirm-modal' );

	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	let file = null;
	try {
		// Disposable fixture from wp.org (installed inactive; delete is the
		// test). Installing swaps files and can recycle the worker, killing
		// the install's own reply — attempt, then poll for the plugin.
		await api( 'wp/v2/plugins', { method: 'POST', body: JSON.stringify( { slug: 'hello-dolly' } ) } ).catch( () => {} );
		for ( let i = 0; i < 12; i++ ) {
			const probe = await api( 'wp/v2/plugins/hello-dolly/hello' ).catch( () => null );
			if ( probe && probe.status === 200 ) {
				file = 'hello-dolly/hello'; // REST plugin id — no .php suffix
				break;
			}
			await page.waitForTimeout( 1000 );
		}
		t.check( 'fixture plugin installed', !! file, String( file ) );

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-del="${ file }"]`, { timeout: 20000 } );

		// Escape cancels, nothing deleted.
		await page.click( `[data-del="${ file }"]` );
		await page.waitForSelector( '.minn-confirm-modal', { timeout: 8000 } );
		const modal1 = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent );
		t.check( 'modal names the plugin and the stakes', /Hello Dolly/.test( modal1 ) && /no trash/i.test( modal1 ), modal1.slice( 0, 80 ) );
		t.check( 'confirm starts disabled (typed confirmation)', await page.evaluate( () => document.querySelector( '.minn-confirm-modal [data-ok]' ).disabled ), '' );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-confirm-modal' ), null, { timeout: 5000 } );
		const still = await api( `wp/v2/plugins/${ file }` );
		t.check( 'Escape cancelled — plugin still installed', still.status === 200, String( still.status ) );

		// The typed gate: wrong word keeps it disabled, the right one arms it.
		await page.click( `[data-del="${ file }"]` );
		await page.waitForSelector( '#minn-confirm-input', { timeout: 8000 } );
		await page.fill( '#minn-confirm-input', 'nope' );
		t.check( 'wrong word keeps confirm disabled', await page.evaluate( () => document.querySelector( '.minn-confirm-modal [data-ok]' ).disabled ), '' );
		await page.fill( '#minn-confirm-input', 'delete' );
		t.check( 'typing delete arms the button', await page.evaluate( () => ! document.querySelector( '.minn-confirm-modal [data-ok]' ).disabled ), '' );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-confirm-modal' ), null, { timeout: 5000 } );
		let gone = null;
		for ( let i = 0; i < 10; i++ ) {
			gone = await api( `wp/v2/plugins/${ file }` );
			if ( gone.status === 404 ) break;
			await page.waitForTimeout( 800 );
		}
		t.check( 'confirming deletes the plugin for real', gone.status === 404, String( gone.status ) );
		file = null; // deleted — nothing to clean
	} finally {
		if ( file ) await api( `wp/v2/plugins/${ file }`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
