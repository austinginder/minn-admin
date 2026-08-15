/**
 * Install-modal drop routing: with the
 * Add plugin (or Add theme) modal open, a file dropped ANYWHERE must go to
 * the modal's installer, never the media library — a zip aimed at the small
 * dropzone but landing a few pixels outside used to upload to Media. The
 * global "Drop files to upload" veil also stays hidden while such a modal is
 * open. Uploads are stubbed at the network layer so nothing real installs.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'install-drop' );
	const { browser, page, errors } = await launch();
	await login( page );

	let pluginUploads = 0;
	let mediaUploads = 0;
	// The picker phase needs a media POST that succeeds, so the upload can
	// hand its result back the way a real one does.
	let mediaOk = false;
	await page.route( '**/minn-admin/v1/plugins/upload*', ( route ) => {
		pluginUploads++;
		route.fulfill( { status: 200, contentType: 'application/json', body: '{"ok":true}' } );
	} );
	await page.route( '**/wp/v2/media*', ( route ) => {
		if ( route.request().method() === 'POST' ) {
			mediaUploads++;
			if ( mediaOk ) {
				return route.fulfill( { status: 201, contentType: 'application/json', body: JSON.stringify( {
					id: 999999, title: { rendered: 'icon' }, source_url: BASE + '/icon.png',
					alt_text: '', media_details: { sizes: {} },
				} ) } );
			}
			return route.fulfill( { status: 500, contentType: 'application/json', body: '{"message":"stubbed upload"}' } );
		}
		return route.continue();
	} );

	// Synthetic file events on document.body — deliberately NOT on the
	// dropzone, so only the window-level handler sees them. Chrome's
	// DragEvent constructor drops the dataTransfer member; pin it on the
	// instance (media-flow suite convention).
	const dropOnBody = ( fname, type ) => page.evaluate( ( a ) => {
		const dt = new DataTransfer();
		dt.items.add( new File( [ 'x' ], a.fname, { type: a.type } ) );
		const ev = new DragEvent( 'drop', { bubbles: true, cancelable: true } );
		Object.defineProperty( ev, 'dataTransfer', { value: dt } );
		document.body.dispatchEvent( ev );
	}, { fname, type } );
	const dragEnterShowsVeil = () => page.evaluate( () => {
		const dt = new DataTransfer();
		dt.items.add( new File( [ 'x' ], 'x.zip', { type: 'application/zip' } ) );
		const ev = new DragEvent( 'dragenter', { bubbles: true, cancelable: true } );
		Object.defineProperty( ev, 'dataTransfer', { value: dt } );
		document.body.dispatchEvent( ev );
		const on = document.body.classList.contains( 'minn-dragging' );
		document.body.classList.remove( 'minn-dragging' );
		document.body.dispatchEvent( new DragEvent( 'dragleave', { bubbles: true } ) );
		return on;
	} );
	// The media picker owns drops the same way: a picture aimed at its zone
	// but landing a few pixels outside used to navigate the app to Media and
	// upload there, losing the picker and whatever asked for it.
	const openPicker = async () => {
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-icon-pick', { timeout: 20000 } );
		await page.click( '#minn-icon-pick' );
		await page.waitForSelector( '#minn-picker-drop', { timeout: 15000 } );
	};
	const openAddPlugin = async () => {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-plugin', { timeout: 20000 } );
		await page.click( '#minn-add-plugin' );
		await page.waitForSelector( '#minn-pi-dropzone' );
	};

	try {
		/* ===== Veil suppressed while the modal is open ===== */
		await openAddPlugin();
		t.check( 'no upload veil while Add plugin is open', ! await dragEnterShowsVeil() );

		/* ===== A zip dropped outside the zone installs the plugin ===== */
		await dropOnBody( 'fake-plugin.zip', 'application/zip' );
		await page.waitForFunction( () => document.body.textContent.includes( 'Plugin installed' ), null, { timeout: 10000 } );
		t.check( 'zip routed to the plugin installer', pluginUploads === 1, String( pluginUploads ) );
		t.check( 'nothing reached the media library', mediaUploads === 0, String( mediaUploads ) );
		t.check( 'no navigation away from Extensions', page.url().includes( '/minn-admin/extensions' ), page.url() );

		/* ===== A non-zip while the modal is open is rejected, not uploaded ===== */
		await openAddPlugin();
		await dropOnBody( 'screenshot.png', 'image/png' );
		await page.waitForFunction( () => document.body.textContent.includes( 'must be .zip' ), null, { timeout: 10000 } );
		t.check( 'non-zip gets the zip-only toast', true );
		t.check( 'non-zip did not hit either upload route', pluginUploads === 1 && mediaUploads === 0, `${ pluginUploads }/${ mediaUploads }` );
		await page.click( '#minn-modal-close' );
		await page.waitForSelector( '#minn-pi-dropzone', { state: 'detached' } );

		/* ===== Baseline behavior intact once the modal closes ===== */
		t.check( 'upload veil returns after close', await dragEnterShowsVeil() );
		await dropOnBody( 'photo.png', 'image/png' );
		await page.waitForFunction( () => location.pathname.includes( '/minn-admin/media' ), null, { timeout: 10000 } );
		t.check( 'drop-anywhere still lands in media', page.url().includes( '/minn-admin/media' ) );
		await page.waitForFunction( () => document.body.textContent.includes( 'stubbed upload' ), null, { timeout: 10000 } );
		t.check( 'media upload path was used', mediaUploads === 1, String( mediaUploads ) );

		/* ===== The media picker owns drops while it is open ===== */
		await openPicker();
		t.check( 'no upload veil while the picker is open', ! await dragEnterShowsVeil() );

		// A non-image proves the routing on its own: the picker refuses it, so
		// nothing uploads anywhere and there is no navigation to undo.
		await dropOnBody( 'notes.txt', 'text/plain' );
		// Tolerant: without the routing this times out because the drop went to
		// Media instead, and the checks below should report that rather than
		// crash the run.
		const refused = await page.waitForFunction( () => document.body.textContent.includes( 'Drop an image file' ), null, { timeout: 10000 } ).then( () => true, () => false );
		t.check( 'the picker refused the non-image itself', refused );
		t.check( 'the picker answered the drop, not the media library', mediaUploads === 1, String( mediaUploads ) );
		t.check( 'no navigation away from settings', page.url().includes( '/minn-admin/settings' ), page.url() );
		t.check( 'the picker is still open', !! await page.$( '#minn-picker-drop' ) );

		// And an image goes through the picker's own upload, which hands the
		// result back to whatever opened it (here, the site icon field).
		mediaOk = true;
		await dropOnBody( 'icon.png', 'image/png' );
		await page.waitForSelector( '#minn-picker-drop', { state: 'detached', timeout: 15000 } ).catch( () => {} );
		t.check( 'the image uploaded through the picker', mediaUploads === 2, String( mediaUploads ) );
		t.check( 'still no navigation away from settings', page.url().includes( '/minn-admin/settings' ), page.url() );
		t.check( 'the pick reached the field that asked for it', await page.$eval( '#minn-icon-img', ( el ) => ! el.hidden ) );
	} finally {
		// Network stubs mean nothing was installed or uploaded; no cleanup.
	}
	await t.done( browser, errors );
} )();
