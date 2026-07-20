/**
 * Shared confirm modal — typed confirmation for irreversible deletes.
 *
 * Drives the real plugin-delete flow against a disposable wp.org install
 * (Hello Dolly): Escape cancels harmlessly and confirming deletes for real.
 * Deletes are one click (no typed gate, by request); the component still
 * supports typeToConfirm for anything that earns it later.
 * The Update-everything scope disclosure is pinned by
 * core-update-visibility.test.js.
 *
 * Second leg (the v0.20.0 sweep): a confirm STACKED over a modal (media
 * detail → Delete). Escape peels only the confirm — the modal underneath
 * survives (the global Escape handler's confirm guard) — and confirming
 * deletes the file for real.
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
		t.check( 'confirm is one click (no typed gate on deletes)', await page.evaluate( () => ! document.querySelector( '.minn-confirm-modal [data-ok]' ).disabled && ! document.querySelector( '#minn-confirm-input' ) ), '' );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-confirm-modal' ), null, { timeout: 5000 } );
		const still = await api( `wp/v2/plugins/${ file }` );
		t.check( 'Escape cancelled — plugin still installed', still.status === 200, String( still.status ) );

		// Confirming deletes for real.
		await page.click( `[data-del="${ file }"]` );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 8000 } );
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

	/* ===== Stacked confirm over a modal: Escape peels one layer ===== */
	let mediaId = null;
	try {
		mediaId = await page.evaluate( async () => {
			const c = document.createElement( 'canvas' );
			c.width = 40; c.height = 40;
			c.getContext( '2d' ).fillRect( 0, 0, 40, 40 );
			const blob = await new Promise( ( res ) => c.toBlob( res, 'image/png' ) );
			const fd = new FormData();
			fd.append( 'file', blob, 'confirm-suite-probe.png' );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: fd,
			} );
			return r.ok ? ( await r.json() ).id : null;
		} );
		t.check( 'media fixture uploaded', !! mediaId, String( mediaId ) );

		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ mediaId }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ mediaId }"]` );
		await page.waitForSelector( '#minn-media-delete', { timeout: 8000 } );
		await page.click( '#minn-media-delete' );
		await page.waitForSelector( '.minn-confirm-modal', { timeout: 8000 } );
		const mtxt = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent );
		t.check( 'media delete confirm names the stakes', /permanently/i.test( mtxt ) && /thumbnails/i.test( mtxt ), mtxt.slice( 0, 80 ) );

		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-confirm-modal' ), null, { timeout: 5000 } );
		const layered = await page.evaluate( () => !! document.querySelector( '#minn-media-delete' ) );
		t.check( 'Escape closed only the confirm — media modal survives', layered, '' );
		const stillThere = await api( `wp/v2/media/${ mediaId }` );
		t.check( 'nothing deleted on cancel', stillThere.status === 200, String( stillThere.status ) );

		await page.click( '#minn-media-delete' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 8000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		let mg = null;
		for ( let i = 0; i < 10; i++ ) {
			mg = await api( `wp/v2/media/${ mediaId }` );
			if ( mg.status === 404 ) break;
			await page.waitForTimeout( 800 );
		}
		t.check( 'confirming deletes the file for real', mg.status === 404, String( mg.status ) );
		if ( mg && mg.status === 404 ) mediaId = null;
	} finally {
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
