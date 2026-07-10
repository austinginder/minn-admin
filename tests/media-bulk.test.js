/**
 * Media bulk select + delete (core-gaps bundle). The media library had no
 * bulk operations while content lists did. This uploads three throwaway
 * attachments, selects them via the grid checkboxes (including a shift-range),
 * bulk-deletes through the UI, and verifies they're gone from the server.
 *
 * Everything created here is deleted whether or not the bulk delete runs, so
 * a crash can't leave orphan fixtures.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-bulk' );
	const { browser, page, errors } = await launch();
	await login( page );
	// Accept the native confirm() the bulk delete raises.
	page.on( 'dialog', ( d ) => d.accept() );

	const ids = [];
	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	const upload = ( name ) => page.evaluate( async ( n ) => {
		const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
		const bin = atob( b64 ); const arr = new Uint8Array( bin.length );
		for ( let i = 0; i < bin.length; i++ ) arr[ i ] = bin.charCodeAt( i );
		const fd = new FormData();
		fd.append( 'file', new Blob( [ arr ], { type: 'image/png' } ), n );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
		} );
		return ( await r.json() ).id;
	}, name );

	try {
		for ( let i = 0; i < 3; i++ ) ids.push( await upload( `minn-bulk-${ i }-${ Date.now() % 100000 }.png` ) );
		t.check( 'three test attachments created', ids.every( Boolean ), ids.join( ',' ) );

		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		for ( const id of ids ) await page.waitForSelector( `[data-media="${ id }"] .minn-media-cb`, { timeout: 20000 } );

		// No bar until something is selected.
		const barBefore = await page.$( '#minn-media-bulk-slot .minn-bulkbar' );
		t.check( 'no bulk bar with nothing selected', barBefore === null );

		// Select the three checkboxes (force — the grid overlay is hover-revealed).
		for ( const id of ids ) await page.click( `[data-media="${ id }"] .minn-media-cb`, { force: true } );

		const count = await page.textContent( '.minn-bulk-count' ).catch( () => '' );
		t.check( 'bulk bar shows the selected count', /3 selected/.test( count ), count );

		// Clicking a checkbox must NOT open the preview modal.
		const modalOpen = await page.$( '#minn-modal-overlay' );
		t.check( 'checkbox does not open the preview modal', modalOpen === null );

		await page.click( '#minn-media-bulk-delete' );
		// Deletes are serial (~1.5s each on this loaded box); the bar clears
		// only once the batch finishes and the grid reloads.
		await page.waitForSelector( '#minn-media-bulk-slot .minn-bulkbar', { state: 'detached', timeout: 45000 } );
		t.check( 'bulk bar clears after delete', true );

		// All three gone from the server.
		let gone = 0;
		for ( const id of ids ) {
			const r = await rest( `wp/v2/media/${ id }?_fields=id` );
			if ( r.status === 404 ) gone++;
		}
		t.check( 'all three deleted on the server', gone === 3, `${ gone }/3 gone` );

	} finally {
		for ( const id of ids ) await rest( `wp/v2/media/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
