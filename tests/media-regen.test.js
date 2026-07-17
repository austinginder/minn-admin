/**
 * Regenerate Thumbnails delight — the media detail modal's ↻ Thumbnails
 * button runs the plugin's regenerator server-side
 * (adapters/regenerate-thumbnails.php) and toasts the size count. Uploads a
 * disposable image so the regenerate has real pixel work to do, and verifies
 * the attachment still carries intact size metadata afterwards.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-regen' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	t.check( 'boot payload flags regenThumbs', await page.evaluate( () => window.MINN.regenThumbs === true ) );

	// Disposable fixture: a 1200x800 canvas PNG through wp/v2/media, so
	// thumbnails exist to rebuild and cleanup can't touch real media.
	const mediaId = await page.evaluate( async () => {
		const canvas = document.createElement( 'canvas' );
		canvas.width = 1200;
		canvas.height = 800;
		const ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#3a6ea5';
		ctx.fillRect( 0, 0, 1200, 800 );
		const blob = await new Promise( ( r ) => canvas.toBlob( r, 'image/png' ) );
		const fd = new FormData();
		fd.append( 'file', blob, 'minn-regen-suite.png' );
		const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
		} );
		return ( await res.json() ).id;
	} );
	t.check( 'fixture image uploaded', !! mediaId, String( mediaId ) );

	try {
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-media-card[data-media="${ mediaId }"], .minn-media-row[data-media="${ mediaId }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ mediaId }"]` );
		await page.waitForSelector( '#minn-media-regen', { timeout: 8000 } );
		t.check( 'detail modal shows the ↻ Thumbnails button', true );

		await page.click( '#minn-media-regen' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Regenerated \d+ thumbnail size/.test( x.textContent ) ),
		null, { timeout: 30000 } );
		t.check( 'toast reports the regenerated size count', true );
		t.check( 'button re-enables for another run', await page.$eval( '#minn-media-regen', ( b ) => ! b.disabled ) );

		// Server truth: the attachment still has size metadata after the rebuild.
		const sizes = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?_fields=media_details', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const m = await r.json();
			return Object.keys( ( m.media_details && m.media_details.sizes ) || {} ).length;
		}, mediaId );
		t.check( 'attachment metadata carries regenerated sizes', sizes > 0, String( sizes ) );
	} finally {
		await page.evaluate( async ( id ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
		}, mediaId ).catch( () => {} );
	}

	// Phase 2 — Force Regenerate Thumbnails covers the same button when RT
	// is off: the click goes to FRT's own admin-ajax handler with FRT's own
	// nonce (boot payload `frt`). Residents restored in finally.
	const plug = ( id, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return r.ok;
	}, { id, status } );
	let frtMedia = null;
	try {
		await plug( 'regenerate-thumbnails/regenerate-thumbnails', 'inactive' );
		await plug( 'force-regenerate-thumbnails/force-regenerate-thumbnails', 'active' );
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
		t.check( 'boot swaps to the FRT path', await page.evaluate( () =>
			window.MINN.regenThumbs === false && !! ( window.MINN.frt && window.MINN.frt.nonce ) ) );

		frtMedia = await page.evaluate( async () => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = 800;
			canvas.height = 600;
			const ctx = canvas.getContext( '2d' );
			ctx.fillStyle = '#a53a6e';
			ctx.fillRect( 0, 0, 800, 600 );
			const blob = await new Promise( ( r ) => canvas.toBlob( r, 'image/png' ) );
			const fd = new FormData();
			fd.append( 'file', blob, 'minn-frt-suite.png' );
			const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
			} );
			return ( await res.json() ).id;
		} );

		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ frtMedia }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ frtMedia }"]` );
		await page.waitForSelector( '#minn-media-regen', { timeout: 8000 } );
		await page.click( '#minn-media-regen' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Force Regenerate Thumbnails/.test( x.textContent ) ),
		null, { timeout: 30000 } );
		t.check( 'FRT run toasts through its own handler', true );

		const frtSizes = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?_fields=media_details', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const m = await r.json();
			return Object.keys( ( m.media_details && m.media_details.sizes ) || {} ).length;
		}, frtMedia );
		t.check( 'metadata carries sizes after the FRT rebuild', frtSizes > 0, String( frtSizes ) );
	} finally {
		if ( frtMedia ) {
			await page.evaluate( async ( id ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', {
					method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => {} );
			}, frtMedia ).catch( () => {} );
		}
		await plug( 'force-regenerate-thumbnails/force-regenerate-thumbnails', 'inactive' ).catch( () => {} );
		await plug( 'regenerate-thumbnails/regenerate-thumbnails', 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
