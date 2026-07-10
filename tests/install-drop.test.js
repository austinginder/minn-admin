/**
 * Install-modal drop routing (Austin's builders repro, 2026-07-10): with the
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
	await page.route( '**/minn-admin/v1/plugins/upload*', ( route ) => {
		pluginUploads++;
		route.fulfill( { status: 200, contentType: 'application/json', body: '{"ok":true}' } );
	} );
	await page.route( '**/wp/v2/media*', ( route ) => {
		if ( route.request().method() === 'POST' ) {
			mediaUploads++;
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
	} finally {
		// Network stubs mean nothing was installed or uploaded; no cleanup.
	}
	await t.done( browser, errors );
} )();
