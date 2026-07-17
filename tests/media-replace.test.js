/**
 * Enable Media Replace delight — the media detail modal's ⇅ Replace file
 * button uploads a new file over the same attachment through EMR's own
 * ReplaceController (adapters/enable-media-replace.php). Uploads a
 * disposable PNG, replaces it with a different-sized PNG through the real
 * file chooser, and verifies the URL survived while the pixels changed.
 * Also proves the same-type guard: a text file is refused with an honest
 * error and nothing changes.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-replace' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	t.check( 'boot payload flags mediaReplace', await page.evaluate( () => window.MINN.mediaReplace === true ) );

	// Disposable fixture: a 1200x800 blue PNG through wp/v2/media.
	const fixture = await page.evaluate( async () => {
		const canvas = document.createElement( 'canvas' );
		canvas.width = 1200;
		canvas.height = 800;
		const ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#3a6ea5';
		ctx.fillRect( 0, 0, 1200, 800 );
		const blob = await new Promise( ( r ) => canvas.toBlob( r, 'image/png' ) );
		const fd = new FormData();
		fd.append( 'file', blob, 'minn-replace-suite.png' );
		const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
		} );
		const m = await res.json();
		return { id: m.id, url: m.source_url };
	} );
	t.check( 'fixture image uploaded', !! fixture.id, String( fixture.id ) );

	// Replacement payload written to disk for the real file chooser: a
	// 900x600 red PNG (different bytes AND different dimensions), plus a
	// text file for the type-guard check.
	const redB64 = await page.evaluate( () => {
		const canvas = document.createElement( 'canvas' );
		canvas.width = 900;
		canvas.height = 600;
		const ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#a53a3a';
		ctx.fillRect( 0, 0, 900, 600 );
		return canvas.toDataURL( 'image/png' ).split( ',' )[ 1 ];
	} );
	const pngPath = path.join( os.tmpdir(), 'minn-replace-suite-red.png' );
	const txtPath = path.join( os.tmpdir(), 'minn-replace-suite.txt' );
	fs.writeFileSync( pngPath, Buffer.from( redB64, 'base64' ) );
	fs.writeFileSync( txtPath, 'not an image' );

	try {
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-media-card[data-media="${ fixture.id }"], .minn-media-row[data-media="${ fixture.id }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ fixture.id }"]` );
		await page.waitForSelector( '#minn-media-replace', { timeout: 8000 } );
		t.check( 'detail modal shows the ⇅ Replace file button', true );

		// Type guard first: a .txt over a PNG is refused server-side.
		const chooser1 = page.waitForEvent( 'filechooser', { timeout: 10000 } );
		await page.click( '#minn-media-replace' );
		await ( await chooser1 ).setFiles( txtPath );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /must stay|not allowed/.test( x.textContent ) ),
		null, { timeout: 30000 } );
		t.check( 'wrong-type replacement is refused with an honest toast', true );
		t.check( 'button re-enables after the refusal', await page.$eval( '#minn-media-replace', ( b ) => ! b.disabled ) );
		const untouched = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?_fields=media_details', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).media_details.width;
		}, fixture.id );
		t.check( 'refused replace changed nothing', untouched === 1200, String( untouched ) );

		// The real replace: same type, new pixels.
		const chooser2 = page.waitForEvent( 'filechooser', { timeout: 10000 } );
		await page.click( '#minn-media-replace' );
		await ( await chooser2 ).setFiles( pngPath );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /File replaced/.test( x.textContent ) ),
		null, { timeout: 30000 } );
		t.check( 'toast reports the replace', true );

		// Modal reflects the new dimensions without reopening.
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-modal .minn-side-row' ) );
			return rows.some( ( r ) => r.textContent.includes( '900×600' ) );
		}, null, { timeout: 8000 } );
		t.check( 'modal shows the new dimensions', true );

		// Server truth: URL preserved, metadata carries the new size.
		const after = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?_fields=source_url,media_details', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const m = await r.json();
			return { url: m.source_url, width: m.media_details.width, height: m.media_details.height };
		}, fixture.id );
		t.check( 'URL is preserved', after.url === fixture.url, after.url );
		t.check( 'attachment metadata carries the new dimensions', after.width === 900 && after.height === 600, `${ after.width }×${ after.height }` );

		// The served file really is the new image (cache-busted fetch).
		const servedIsNew = await page.evaluate( async ( url ) => {
			const res = await fetch( url + '?minn-suite-bust=' + Date.now(), { cache: 'no-store' } );
			const buf = new Uint8Array( await res.arrayBuffer() );
			const img = await new Promise( ( resolve ) => {
				const i = new Image();
				i.onload = () => resolve( i );
				i.onerror = () => resolve( null );
				i.src = URL.createObjectURL( new Blob( [ buf ], { type: 'image/png' } ) );
			} );
			return img ? { w: img.naturalWidth, h: img.naturalHeight } : null;
		}, fixture.url );
		t.check( 'served bytes are the replacement image', !! servedIsNew && servedIsNew.w === 900 && servedIsNew.h === 600, JSON.stringify( servedIsNew ) );
	} finally {
		await page.evaluate( async ( id ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
		}, fixture.id ).catch( () => {} );
		try { fs.unlinkSync( pngPath ); } catch ( e ) {}
		try { fs.unlinkSync( txtPath ); } catch ( e ) {}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
