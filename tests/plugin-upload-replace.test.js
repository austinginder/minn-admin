/**
 * Uploading a plugin zip whose folder is already there.
 *
 * A newer version is an UPDATE and goes through without a question: that is
 * what dropping a fresh build on a site is for. Only an equal or older version
 * is worth stopping for, because that is the drag that loses work.
 *
 * The suite builds its own throwaway packages (tests/lib/zip.js) and asserts
 * the version WordPress ended up holding, not what the screen said.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { launch, login, reporter, BASE } = require( './helpers' );
const { writePluginZip, writeThemeZip } = require( './lib/zip' );

// A slug per run, not a fixed one. On a WordPress Playground lab, deleting a
// plugin leaves a phantom directory behind: PHP's is_dir() says it is gone
// while WP_Filesystem still finds it, so the next install of the SAME folder
// dies inside move_dir() with "The destination directory already exists and
// could not be removed" — a lab ghost that reads exactly like a regression.
// A fresh folder each run steps around it and costs nothing anywhere else.
const STAMP = Date.now().toString( 36 ).slice( -6 );
const SLUG = `minn-lab-dummy-${ STAMP }`;
const NAME = `Minn Lab Dummy ${ STAMP }`;
const TSLUG = `minn-lab-skin-${ STAMP }`;
const TNAME = `Minn Lab Skin ${ STAMP }`;

( async () => {
	const t = reporter( 'plugin-upload-replace' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Both install dialogs search the WordPress.org directory as they open.
	// Answering it here pins the dialog's second render to a known moment
	// (see settle() below) and keeps the suite honest on a lab that cannot
	// reach wordpress.org at all. Nothing here tests the catalog.
	const noResults = ( route ) =>
		route.fulfill( { status: 200, contentType: 'application/json', body: '{"plugins":[],"themes":[],"total":0}' } );
	await page.route( '**/minn-admin/v1/plugins/search*', noResults );
	await page.route( '**/minn-admin/v1/themes/search*', noResults );

	const tmp = fs.mkdtempSync( path.join( os.tmpdir(), 'minn-pkg-' ) );
	const zipAt = ( version ) =>
		writePluginZip( path.join( tmp, `${ SLUG }-${ version }.zip` ), { slug: SLUG, name: NAME, version } );

	// Everything about the installed copy comes from WordPress itself.
	const installed = () => page.evaluate( async ( file ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + file, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		if ( ! r.ok ) return null;
		const j = await r.json();
		return { version: j.version, status: j.status };
	}, SLUG + '/' + SLUG );

	const setStatus = ( status ) => page.evaluate( async ( a ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.file, {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} ).catch( () => {} );
	}, { file: SLUG + '/' + SLUG, status } );

	const removePlugin = async () => {
		await setStatus( 'inactive' );
		await page.evaluate( async ( file ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + file, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} ).catch( () => {} );
		}, SLUG + '/' + SLUG );
	};

	/**
	 * Wait until a node stops being replaced under us.
	 *
	 * Stubbing the search above makes the dialog's second render land fast,
	 * but it still lands. Hand the file input a file while it is happening
	 * and the file uploads TWICE: Playwright retries an action whose element
	 * detached, the retry hits the fresh input, and one drop becomes two
	 * requests (and two dialogs). Marking the node and requiring the mark to
	 * survive a quiet stretch is the cheap way to know the render is over.
	 */
	const settle = async ( sel, quietMs = 1500 ) => {
		let quietFrom = 0;
		for ( let i = 0; i < 60; i++ ) {
			const same = await page.evaluate( ( s ) => {
				const el = document.querySelector( s );
				if ( ! el ) return false;
				if ( el.__minnSettled ) return true;
				el.__minnSettled = 1;
				return false;
			}, sel );
			if ( ! same ) {
				quietFrom = 0;
			} else if ( ! quietFrom ) {
				quietFrom = Date.now();
			} else if ( Date.now() - quietFrom >= quietMs ) {
				return;
			}
			await page.waitForTimeout( 200 );
		}
	};

	const openAddPlugin = async () => {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-plugin', { timeout: 20000 } );
		await page.click( '#minn-add-plugin' );
		await page.waitForSelector( '#minn-pi-dropzone', { timeout: 15000 } );
		await settle( '#minn-pi-file' );
	};

	/**
	 * Hand a zip to the installer and report which of the two doors it took:
	 * 'confirm' (the replace question is up) or 'done' (the modal closed on a
	 * finished install). Returns 'stuck' if neither happens.
	 */
	const upload = async ( zip ) => {
		await openAddPlugin();
		await page.setInputFiles( '#minn-pi-file', zip );
		return Promise.race( [
			page.waitForSelector( '.minn-confirm-overlay', { timeout: 30000 } ).then( () => 'confirm' ),
			page.waitForSelector( '#minn-pi-dropzone', { state: 'detached', timeout: 30000 } ).then( () => 'done' ),
		] ).catch( () => 'stuck' );
	};

	const themeZipAt = ( version ) =>
		writeThemeZip( path.join( tmp, `${ TSLUG }-${ version }.zip` ), { slug: TSLUG, name: TNAME, version } );

	const themeVersion = () => page.evaluate( async ( slug ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/themes', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		if ( ! r.ok ) return null;
		const found = ( ( await r.json() ).themes || [] ).find( ( x ) => x.stylesheet === slug );
		return found ? found.version : null;
	}, TSLUG );

	const removeTheme = () => page.evaluate( async ( slug ) => {
		await fetch( window.MINN.restUrl + 'minn-admin/v1/themes/delete', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { stylesheet: slug } ),
		} ).catch( () => {} );
	}, TSLUG );

	const uploadTheme = async ( zip ) => {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="themes"]', { timeout: 20000 } );
		await page.click( '[data-xtab="themes"]' );
		await page.waitForSelector( '#minn-add-theme', { timeout: 15000 } );
		await page.click( '#minn-add-theme' );
		await page.waitForSelector( '#minn-ti-dropzone', { timeout: 15000 } );
		await settle( '#minn-ti-file' );
		await page.setInputFiles( '#minn-ti-file', zip );
		return Promise.race( [
			page.waitForSelector( '.minn-confirm-overlay', { timeout: 30000 } ).then( () => 'confirm' ),
			page.waitForSelector( '#minn-ti-dropzone', { state: 'detached', timeout: 30000 } ).then( () => 'done' ),
		] ).catch( () => 'stuck' );
	};

	const confirmText = () => page.evaluate( () => {
		const el = document.querySelector( '.minn-confirm-modal' );
		return el ? el.textContent.replace( /\s+/g, ' ' ) : '';
	} );

	const answerConfirm = async ( accept, zone = '#minn-pi-dropzone' ) => {
		await page.click( `.minn-confirm-overlay [data-${ accept ? 'ok' : 'cancel' }]` );
		if ( accept ) {
			// Replacing runs a second upload; the dialog it belongs to closes
			// when that one lands.
			await page.waitForSelector( zone, { state: 'detached', timeout: 30000 } ).catch( () => {} );
		}
		await page.waitForSelector( '.minn-confirm-overlay', { state: 'detached', timeout: 10000 } ).catch( () => {} );
	};

	const toastText = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-toast' ) ).map( ( el ) => el.textContent ).join( ' | ' )
	);

	try {
		await removePlugin();

		/* ===== A folder that is not there installs, as it always did ===== */
		let door = await upload( zipAt( '1.0.0' ) );
		t.check( 'a first install asks nothing', door === 'done', door );
		let now = await installed();
		t.check( 'the first version is the one installed', now && now.version === '1.0.0', JSON.stringify( now ) );

		await setStatus( 'active' );
		now = await installed();
		t.check( 'the plugin is active before the update', now && now.status === 'active', JSON.stringify( now ) );

		/* ===== A NEWER zip is an update, not a question ===== */
		door = await upload( zipAt( '1.1.0' ) );
		t.check( 'a newer version installs without a question', door === 'done', door );
		const updateToast = await toastText();
		t.check( 'the toast names both versions', /1\.0\.0/.test( updateToast ) && /1\.1\.0/.test( updateToast ), updateToast );
		now = await installed();
		t.check( 'WordPress holds the newer version', now && now.version === '1.1.0', JSON.stringify( now ) );
		t.check( 'the update left it active', now && now.status === 'active', JSON.stringify( now ) );

		/* ===== An OLDER zip stops and names both versions ===== */
		door = await upload( zipAt( '0.9.0' ) );
		t.check( 'an older version asks first', door === 'confirm', door );
		const asked = await confirmText();
		t.check( 'the question names the installed version', /1\.1\.0/.test( asked ), asked );
		t.check( 'the question names the uploaded version', /0\.9\.0/.test( asked ), asked );

		await answerConfirm( false );
		now = await installed();
		t.check( 'cancelling changes nothing', now && now.version === '1.1.0', JSON.stringify( now ) );

		/* ===== …and replaces when the reader says so ===== */
		door = await upload( zipAt( '0.9.0' ) );
		t.check( 'the question comes back on a second try', door === 'confirm', door );
		await answerConfirm( true );
		now = await installed();
		t.check( 'Replace really downgrades the files', now && now.version === '0.9.0', JSON.stringify( now ) );
		t.check( 'the replace left it active', now && now.status === 'active', JSON.stringify( now ) );

		/* ===== The SAME version is not an update either ===== */
		door = await upload( zipAt( '0.9.0' ) );
		t.check( 'the same version still asks', door === 'confirm', door );
		await answerConfirm( false );

		/* ===== Themes ride the same rule ===== */
		await removeTheme();
		door = await uploadTheme( themeZipAt( '0.9.0' ) );
		t.check( 'a first theme install asks nothing', door === 'done', door );
		let tv = await themeVersion();
		t.check( 'the theme is installed', tv === '0.9.0', String( tv ) );

		door = await uploadTheme( themeZipAt( '1.0.0' ) );
		t.check( 'a newer theme installs without a question', door === 'done', door );
		const themeToast = await toastText();
		t.check( 'the theme toast names both versions', /0\.9\.0/.test( themeToast ) && /1\.0\.0/.test( themeToast ), themeToast );
		tv = await themeVersion();
		t.check( 'WordPress holds the newer theme', tv === '1.0.0', String( tv ) );

		door = await uploadTheme( themeZipAt( '0.8.0' ) );
		t.check( 'an older theme asks first', door === 'confirm', door );
		const askedTheme = await confirmText();
		t.check( 'the theme question names both versions', /1\.0\.0/.test( askedTheme ) && /0\.8\.0/.test( askedTheme ), askedTheme );
		await answerConfirm( false, '#minn-ti-dropzone' );
		tv = await themeVersion();
		t.check( 'cancelling leaves the theme alone', tv === '1.0.0', String( tv ) );
	} finally {
		await removePlugin().catch( () => {} );
		await removeTheme().catch( () => {} );
		fs.rmSync( tmp, { recursive: true, force: true } );
	}
	await t.done( browser, errors );
} )();
