/**
 * Editing a classic menu item changes only what was edited.
 *
 * The label field used to be seeded with the stored label stripped of its
 * markup, and Save sent it back every time: fixing a custom link's URL turned
 * `<i class="…"></i> Home` into `Home`. The classic Menus screen edits the
 * stored label as written.
 *
 * Fixture: one menu with one custom link, created and removed over REST.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'menu-item-edit' );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	const label = '<i class="minn-test-icon"></i> Home';
	let menuId = null;
	try {
		const menu = await api( 'wp/v2/menus', { method: 'POST', body: JSON.stringify( { name: 'Minn item edit ' + Date.now() } ) } );
		menuId = menu.body && menu.body.id;
		const item = await api( 'wp/v2/menu-items', { method: 'POST', body: JSON.stringify( { menus: menuId, title: label, url: 'https://example.com/old', type: 'custom', status: 'publish' } ) } );
		const itemId = item.body && item.body.id;
		const raw = async () => ( await api( `wp/v2/menu-items/${ itemId }?context=edit&_fields=title,url` ) ).body || {};
		t.check( 'fixture item stored with its markup', !! itemId && ( await raw() ).title && ( await raw() ).title.raw === label, JSON.stringify( await raw() ) );

		await page.goto( `${ BASE }/minn-admin/menus`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-menu="${ menuId }"]`, { timeout: 15000 } );
		await page.click( `[data-menu="${ menuId }"]` );
		await page.waitForSelector( `.minn-menu-row[data-mi="${ itemId }"]`, { timeout: 15000 } );
		await page.click( `.minn-menu-row[data-mi="${ itemId }"]`, { button: 'right' } );
		await page.click( '.minn-ctx-menu button:has-text("Edit label")', { timeout: 8000 } ).catch( async () => {
			await page.click( 'button:has-text("Edit label")' );
		} );
		await page.waitForSelector( '#minn-mi-url', { timeout: 8000 } );
		t.check( 'the label field shows the stored label, markup included', label === await page.$eval( '#minn-mi-label', ( e ) => e.value ), await page.$eval( '#minn-mi-label', ( e ) => e.value ) );
		await page.fill( '#minn-mi-url', 'https://example.com/new' );
		await page.click( '[data-misave]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-mi-url' ), null, { timeout: 15000 } );
		const after = await raw();
		t.check( 'the URL edit saves (control)', 'https://example.com/new' === after.url, after.url );
		t.check( 'and the label keeps its markup', after.title && label === after.title.raw, JSON.stringify( after.title ) );
	} finally {
		if ( menuId ) await api( `wp/v2/menus/${ menuId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
