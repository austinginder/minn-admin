/**
 * Media folders provider contract — the folder combobox on the Media view,
 * fed by the bundled FileBird provider (adapters/media-folders.php). Seeds a
 * folder through FileBird's OWN REST API (filebird/v1, cookie + nonce), so
 * nothing in the fixture bypasses the plugin's model. Verifies: folder
 * filtering via the ids shim + include=, the reserved Uncategorized row, and
 * the all-folders reset.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-folders' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	t.check( 'boot payload names the provider', await page.evaluate( () =>
		!! ( window.MINN.mediaFolders && window.MINN.mediaFolders.name === 'FileBird' ) ) );

	// Fixtures: a FileBird folder (their own REST), one PNG assigned to it,
	// one PNG left uncategorized.
	const fx = await page.evaluate( async () => {
		const jhead = { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' };
		const png = async ( color, name ) => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = 320;
			canvas.height = 240;
			const ctx = canvas.getContext( '2d' );
			ctx.fillStyle = color;
			ctx.fillRect( 0, 0, 320, 240 );
			const blob = await new Promise( ( r ) => canvas.toBlob( r, 'image/png' ) );
			const fd = new FormData();
			fd.append( 'file', blob, name );
			const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
			} );
			return ( await res.json() ).id;
		};
		const inFolder = await png( '#6e3aa5', 'minn-folders-in.png' );
		const loose = await png( '#a56e3a', 'minn-folders-loose.png' );
		const created = await ( await fetch( window.MINN.restUrl + 'filebird/v1/new-folder', {
			method: 'POST', headers: jhead, credentials: 'same-origin',
			body: JSON.stringify( { title: 'Minn Suite Folder', parent: 0 } ),
		} ) ).json();
		const folder = created && created[ 0 ] && ( created[ 0 ].id || ( created[ 0 ][ 0 ] && created[ 0 ][ 0 ].id ) );
		await fetch( window.MINN.restUrl + 'filebird/v1/assign-folder', {
			method: 'POST', headers: jhead, credentials: 'same-origin',
			body: JSON.stringify( { folderId: folder, ids: [ inFolder ] } ),
		} );
		return { folder, inFolder, loose };
	} );
	t.check( 'fixtures created through FileBird\'s own API', !! ( fx.folder && fx.inFolder && fx.loose ), JSON.stringify( fx ) );

	try {
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ fx.inFolder }"]`, { timeout: 20000 } );
		await page.waitForSelector( '[data-foldercombo] .minn-ac-input', { timeout: 8000 } );
		t.check( 'folder combobox renders with a provider', true );

		// The input renders before the async folder list binds it — retry the
		// open until the panel actually populates (slash-menu jiggle class).
		const openFolderPanel = async ( acv ) => {
			for ( let i = 0; i < 20; i++ ) {
				await page.click( '[data-foldercombo] .minn-ac-input' );
				const hit = await page.waitForSelector( `[data-foldercombo] .minn-ac-item[data-acv="${ acv }"]`, { timeout: 700 } )
					.then( () => true ).catch( () => false );
				if ( hit ) return;
			}
			throw new Error( 'folder combobox never offered ' + acv );
		};

		// Options: the seeded folder (with its count) and the reserved
		// Uncategorized row.
		await openFolderPanel( fx.folder );
		const folderLabel = await page.$eval( `[data-foldercombo] .minn-ac-item[data-acv="${ fx.folder }"]`, ( el ) => el.textContent );
		t.check( 'folder option carries its count', /Minn Suite Folder \(1\)/.test( folderLabel ), folderLabel );
		t.check( 'Uncategorized is offered', !! ( await page.$( '[data-foldercombo] .minn-ac-item[data-acv="0"]' ) ) );

		// Pick the folder: only the assigned file remains.
		await page.click( `[data-foldercombo] .minn-ac-item[data-acv="${ fx.folder }"]` );
		await page.waitForFunction( ( id ) => ! document.querySelector( `[data-media="${ id }"]` ), fx.loose, { timeout: 20000 } );
		t.check( 'loose file drops out inside the folder', true );
		t.check( 'assigned file stays inside the folder', !! ( await page.$( `[data-media="${ fx.inFolder }"]` ) ) );

		// Uncategorized: the flip side.
		await openFolderPanel( '0' );
		await page.click( '[data-foldercombo] .minn-ac-item[data-acv="0"]' );
		await page.waitForFunction( ( id ) => ! document.querySelector( `[data-media="${ id }"]` ), fx.inFolder, { timeout: 20000 } );
		t.check( 'assigned file drops out of Uncategorized', true );
		t.check( 'loose file shows under Uncategorized', !! ( await page.$( `[data-media="${ fx.loose }"]` ) ) );

		// Reset to all folders: both return.
		await openFolderPanel( '' );
		await page.click( '[data-foldercombo] .minn-ac-item[data-acv=""]' );
		await page.waitForSelector( `[data-media="${ fx.inFolder }"]`, { timeout: 20000 } );
		t.check( 'all-folders reset restores everything', !! ( await page.$( `[data-media="${ fx.loose }"]` ) ) );

		// Server truth: the ids shim answers exactly the folder's membership.
		const shim = await page.evaluate( async ( fid ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/media/folders/' + fid + '/ids', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		}, fx.folder );
		t.check( 'ids shim returns the assigned file only', shim.ids.length === 1 && shim.ids[ 0 ] === fx.inFolder && shim.capped === false, JSON.stringify( shim ) );
	} finally {
		await page.evaluate( async ( fx2 ) => {
			const jhead = { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' };
			await fetch( window.MINN.restUrl + 'filebird/v1/delete-folder', {
				method: 'POST', headers: jhead, credentials: 'same-origin',
				body: JSON.stringify( { ids: [ fx2.folder ] } ),
			} ).catch( () => {} );
			for ( const id of [ fx2.inFolder, fx2.loose ] ) {
				await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', {
					method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => {} );
			}
		}, fx ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
