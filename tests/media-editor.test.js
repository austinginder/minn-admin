/**
 * Minn's own image editor: canvas preview + drag crop over core's
 * wp/v2/media/{id}/edit endpoint (all pixel work server-side, saved as a
 * NEW copy). Uploads a deterministic 200×100 fixture, rotates right and
 * crops the middle 50%×50% via real mouse drag, saves, and verifies the
 * copy's dimensions: rotate → 100×200, crop 50% → 50×100.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'media-editor' );
	await login( page );

	/* ===== Deterministic fixture: 200×100 PNG generated in-page ===== */
	const fixtureId = await page.evaluate( async () => {
		const c = document.createElement( 'canvas' );
		c.width = 200;
		c.height = 100;
		const cx = c.getContext( '2d' );
		cx.fillStyle = '#3355ff';
		cx.fillRect( 0, 0, 200, 100 );
		cx.fillStyle = '#ff5533';
		cx.fillRect( 0, 0, 100, 50 );
		const blob = await new Promise( ( res ) => c.toBlob( res, 'image/png' ) );
		const fd = new FormData();
		fd.append( 'file', blob, 'editor-probe.png' );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, body: fd,
		} );
		return ( await r.json() ).id;
	} );
	t.check( 'fixture image uploads', !! fixtureId, String( fixtureId ) );

	/* ===== Open the editor from the preview modal ===== */
	await page.goto( `${ BASE }/minn-admin/media`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `[data-media="${ fixtureId }"]`, { timeout: 15000 } );
	await page.click( `[data-media="${ fixtureId }"]` );
	await page.waitForSelector( '#minn-media-edit-image', { timeout: 10000 } );
	await page.click( '#minn-media-edit-image' );
	await page.waitForSelector( '#minn-imged-canvas', { timeout: 5000 } );
	await page.waitForFunction( () => {
		const c = document.querySelector( '#minn-imged-canvas' );
		return c && c.width > 0;
	}, { timeout: 5000 } );

	/* ===== Rotate right: canvas aspect flips ===== */
	const before = await page.$eval( '#minn-imged-canvas', ( c ) => ( { w: c.width, h: c.height } ) );
	await page.click( '#minn-imged-rr' );
	await page.waitForTimeout( 200 );
	const after = await page.$eval( '#minn-imged-canvas', ( c ) => ( { w: c.width, h: c.height } ) );
	t.check( 'rotate flips the canvas aspect', before.w > before.h && after.h > after.w, JSON.stringify( { before, after } ) );

	/* ===== Drag the middle 50%×50% crop ===== */
	const rect = await page.$eval( '#minn-imged-canvas', ( c ) => {
		const r = c.getBoundingClientRect();
		return { x: r.x, y: r.y, w: r.width, h: r.height };
	} );
	await page.mouse.move( rect.x + rect.w * 0.25, rect.y + rect.h * 0.25 );
	await page.mouse.down();
	await page.mouse.move( rect.x + rect.w * 0.75, rect.y + rect.h * 0.75, { steps: 6 } );
	await page.mouse.up();
	const box = await page.$eval( '#minn-imged-crop', ( el ) => ! el.hidden );
	t.check( 'drag draws the crop box', box, '' );

	/* ===== Save as copy → server applies rotate then crop ===== */
	await page.click( '#minn-imged-save' );
	await page.waitForSelector( '.minn-modal.media:not(.wide), .minn-modal.media .minn-modal-preview', { timeout: 20000 } );
	await page.waitForTimeout( 600 );
	const copy = await page.evaluate( async ( orig ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/media?search=editor-probe&per_page=10&_fields=id,source_url,media_details`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		const items = await r.json();
		return items.find( ( i ) => i.id !== orig && /edited/.test( i.source_url ) ) || null;
	}, fixtureId );
	t.check( 'edited copy exists alongside the original', !! copy, JSON.stringify( copy && copy.id ) );
	t.check( 'copy dims prove rotate→crop math (100×200 → 50×100)',
		!! copy && Math.abs( copy.media_details.width - 50 ) <= 1 && Math.abs( copy.media_details.height - 100 ) <= 1,
		copy ? `${ copy.media_details.width }x${ copy.media_details.height }` : 'missing' );

	/* ===== Cleanup both attachments ===== */
	await page.evaluate( async ( ids ) => {
		for ( const id of ids ) {
			if ( id ) await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', { method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		}
	}, [ fixtureId, copy && copy.id ] );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
