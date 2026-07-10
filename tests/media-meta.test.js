/**
 * Media caption + description editing (core-gaps daily-work bundle). The
 * media detail modal previously edited title + alt only; captions are daily
 * work for content teams. This proves the two new fields load their raw
 * edit-context value, save through wp/v2/media, and round-trip (verify the
 * SAVED value, not just the DOM).
 *
 * Uses a REST-created throwaway attachment so no real media is touched, and
 * deletes it in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-meta' );
	const { browser, page, errors } = await launch();
	await login( page );

	let mediaId = null;
	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	try {
		// Create a tiny throwaway image attachment via a base64 upload.
		mediaId = await page.evaluate( async () => {
			// 1x1 transparent PNG
			const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
			const bin = atob( b64 );
			const arr = new Uint8Array( bin.length );
			for ( let i = 0; i < bin.length; i++ ) arr[ i ] = bin.charCodeAt( i );
			const fd = new FormData();
			fd.append( 'file', new Blob( [ arr ], { type: 'image/png' } ), 'minn-cap-test.png' );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
			} );
			return ( await r.json() ).id;
		} );
		t.check( 'test attachment created', !! mediaId, String( mediaId ) );

		// Seed a caption via REST so the lazy load has something to show.
		await rest( `wp/v2/media/${ mediaId }`, { method: 'POST', body: JSON.stringify( { caption: 'Seeded caption text', description: 'Seeded description text' } ) } );

		// Open the media grid and click into our attachment's detail modal
		// (newest upload sorts first; tiles are .minn-media-card[data-media=ID]).
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ mediaId }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ mediaId }"]` );

		// Wait for the caption field to appear and lazy-fill.
		await page.waitForSelector( '#minn-media-caption', { timeout: 10000 } );
		await page.waitForFunction( () => {
			const c = document.querySelector( '#minn-media-caption' );
			return c && c.value.length > 0;
		}, null, { timeout: 10000 } );
		const loaded = await page.evaluate( () => ( {
			caption: document.querySelector( '#minn-media-caption' ).value,
			description: document.querySelector( '#minn-media-description' ).value,
		} ) );
		t.check( 'caption loads its raw value', loaded.caption === 'Seeded caption text', loaded.caption );
		t.check( 'description loads its raw value', loaded.description === 'Seeded description text', loaded.description );

		// Edit both and save through the UI.
		await page.fill( '#minn-media-caption', 'Edited caption via Minn' );
		await page.fill( '#minn-media-description', 'Edited description via Minn' );
		await page.click( '#minn-media-save' );
		await page.waitForTimeout( 800 );

		// Verify the SAVED value on the server (context=edit raw).
		const after = await rest( `wp/v2/media/${ mediaId }?context=edit&_fields=caption,description` );
		t.check( 'caption saved to the server', after.body && after.body.caption.raw === 'Edited caption via Minn', after.body && after.body.caption.raw );
		t.check( 'description saved to the server', after.body && after.body.description.raw === 'Edited description via Minn', after.body && after.body.description.raw );

	} finally {
		if ( mediaId ) await rest( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
