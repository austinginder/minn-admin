/**
 * Upload-overwrite flow (wp-admin's "replace current with uploaded"): a
 * plugin zip whose folder is already installed answers 409 folder_exists
 * with both versions; the modal offers a themed Replace confirm, and
 * confirming re-sends the same file with overwrite=1. Cancel sends nothing.
 * Routes are stubbed (the install-drop convention) so nothing real
 * installs and the flow stays deterministic.
 */
const { launch, login, reporter, BASE } = require( './helpers' );
const fs = require( 'fs' );
const path = require( 'path' );

( async () => {
	const t = reporter( 'upload-overwrite' );
	const { browser, page, errors } = await launch();
	await login( page );

	const zipPath = path.join( require( 'os' ).tmpdir(), 'overwrite-suite.zip' );
	fs.writeFileSync( zipPath, 'PK\u0005\u0006' + '\u0000'.repeat( 18 ) ); // empty-but-valid zip magic

	const uploads = [];
	await page.route( '**/minn-admin/v1/plugins/upload*', ( route ) => {
		const body = route.request().postData() || '';
		const overwrite = /name="overwrite"/.test( body );
		uploads.push( { overwrite } );
		if ( ! overwrite ) {
			return route.fulfill( { status: 409, contentType: 'application/json', body: JSON.stringify( {
				code: 'folder_exists',
				message: 'Probe Tiny is already installed.',
				data: { status: 409, name: 'Probe Tiny', current_version: '1.0', new_version: '1.1' },
			} ) } );
		}
		return route.fulfill( { status: 200, contentType: 'application/json', body: '{"installed":true}' } );
	} );

	try {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-plugin', { timeout: 20000 } );

		/* ===== Cancel path: 409 offer appears, cancel sends nothing. ===== */
		await page.click( '#minn-add-plugin' );
		await page.waitForSelector( '#minn-pi-file', { state: 'attached', timeout: 10000 } );
		await page.setInputFiles( '#minn-pi-file', zipPath );
		await page.waitForSelector( '.minn-confirm-overlay', { timeout: 10000 } );
		t.check( 'collision opens a Replace confirm with both versions', await page.evaluate( () => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			return /Replace Probe Tiny\?/.test( ov.textContent ) && /1\.0/.test( ov.textContent ) && /1\.1/.test( ov.textContent );
		} ) );
		await page.evaluate( () => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			ov.querySelector( '[data-cancel]' ).click();
		} );
		await page.waitForTimeout( 500 );
		t.check( 'cancel sends no overwrite request', uploads.length === 1 && ! uploads[ 0 ].overwrite, JSON.stringify( uploads ) );

		/* ===== Replace path: confirm re-sends with overwrite=1. ===== */
		// Same file again fires no change event; clear the input first.
		await page.evaluate( () => { document.querySelector( '#minn-pi-file' ).value = ''; } );
		await page.setInputFiles( '#minn-pi-file', zipPath );
		await page.waitForSelector( '.minn-confirm-overlay', { timeout: 10000 } );
		await page.evaluate( () => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			Array.from( ov.querySelectorAll( 'button' ) ).find( ( b ) => /Replace/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal-overlay' ), null, { timeout: 10000 } );
		t.check( 'confirm re-sends the file with overwrite', uploads.length === 3
			&& ! uploads[ 1 ].overwrite && uploads[ 2 ].overwrite, JSON.stringify( uploads ) );
	} finally {
		fs.unlinkSync( zipPath );
	}

	await t.done( browser, errors );
} )();
