/**
 * GH #7: "hide just for you" on core nav items, restore from Your profile,
 * and admin restore for another user from the user edit page.
 *
 * Covers: right-click a core nav row → hide → the item leaves the nav and
 * stays gone across a reload; Your profile lists it as a Menu item with
 * Restore; a second user's own hide shows on the admin's user edit page
 * ("Hidden for them") and restoring there clears the target's stored meta.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'core-hide' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
		return r.json();
	}, { route, opts } );

	const navHas = ( id ) => page.evaluate( ( n ) => !! document.querySelector( `.minn-nav-btn[data-nav="${ n }"]` ), id );

	let editorUid = 0;
	try {
		/* ===== Self: hide Media via right-click, restore from profile ===== */
		t.check( 'Media starts in the nav', await navHas( 'media' ) );
		await page.click( '.minn-nav-btn[data-nav="media"]', { button: 'right' } );
		await page.waitForSelector( '.minn-menu-pop, .minn-new-menu, .minn-row-menu, .minn-ctx-menu', { timeout: 5000 } ).catch( () => {} );
		// Rule-31: right-click can detach the first-rendered menu — click by text via evaluate.
		await page.waitForTimeout( 300 );
		const hid = await page.evaluate( () => {
			const item = Array.from( document.querySelectorAll( 'button, [role="menuitem"]' ) )
				.find( ( el ) => /Hide “.*” for you/.test( el.textContent || '' ) );
			if ( item ) { item.click(); return item.textContent; }
			return '';
		} );
		t.check( 'context menu offers the hide', /Media/.test( hid ), hid );
		await page.waitForFunction( () => ! document.querySelector( '.minn-nav-btn[data-nav="media"]' ), null, { timeout: 8000 } );
		t.check( 'Media leaves the nav', true );

		// Survives a full reload (server-stored, not client state).
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		await page.waitForTimeout( 600 );
		t.check( 'hide survives reload', ! ( await navHas( 'media' ) ) );

		// Your profile lists it as a Menu item and restores it.
		await page.goto( BASE + '/minn-admin/profile', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-unhide="core:media"]', { timeout: 15000 } );
		const kindLabel = await page.evaluate( () =>
			document.querySelector( '[data-unhide="core:media"]' ).closest( '.minn-session-row' ).textContent );
		t.check( 'profile lists Media as a hidden Menu item', /Menu item/.test( kindLabel ), kindLabel );
		await page.click( '[data-unhide="core:media"]' );
		await page.waitForFunction( () => !! document.querySelector( '.minn-nav-btn[data-nav="media"]' ), null, { timeout: 8000 } );
		t.check( 'restore brings Media back without a reload', true );

		/* ===== Other user: their hide shows on the admin's edit page ===== */
		const targets = await rest( 'wp/v2/users?search=minn-editor&context=edit' );
		editorUid = ( targets.find( ( x ) => x.username === 'minn-editor' ) || {} ).id;
		t.check( 'editor user resolved', !! editorUid, String( editorUid ) );

		// The editor hides Terms from THEIR own session.
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		const theirHide = await p2.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/integrations/hide', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { id: 'core:terms' } ),
			} );
			return r.ok;
		} );
		t.check( 'editor hides Terms for themselves', theirHide );
		await ctx2.close();

		// Admin sees it on the edit page and restores it.
		await page.goto( `${ BASE }/minn-admin/users/${ editorUid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ue-unhide="core:terms"]', { timeout: 15000 } );
		const cardText = await page.evaluate( () =>
			document.querySelector( '[data-ue-unhide="core:terms"]' ).closest( '.minn-card' ).textContent );
		t.check( 'edit page shows Hidden for them with Terms', /Hidden for them/.test( cardText ) && /Terms/.test( cardText ), cardText.slice( 0, 120 ) );
		await page.click( '[data-ue-unhide="core:terms"]' );
		await page.waitForFunction( () => ! document.querySelector( '[data-ue-unhide="core:terms"]' ), null, { timeout: 8000 } );
		const after = await rest( `minn-admin/v1/users/${ editorUid }/hidden` );
		t.check( 'restore clears the target user\'s hidden meta', ! ( after.hidden || [] ).some( ( h ) => h.id === 'core:terms' ), JSON.stringify( after.hidden ) );
	} finally {
		// Belt and braces: clear both hides whatever happened above.
		await rest( 'minn-admin/v1/integrations/unhide', { method: 'POST', body: JSON.stringify( { id: 'core:media' } ) } ).catch( () => {} );
		if ( editorUid ) {
			await rest( `minn-admin/v1/users/${ editorUid }/integrations/unhide`, { method: 'POST', body: JSON.stringify( { integration: 'core:terms' } ) } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
