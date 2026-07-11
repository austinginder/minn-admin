/**
 * Two related user-dropdown bugs (v0.11.0 cycle):
 *  1. "Name · email" collapsed to a redundant "email · email" when a user
 *     has no distinct display name (WordPress defaults display_name to the
 *     login, which for imported accounts is the email). fmtUserLabel now
 *     shows the value once. Seen in the Delete-user subtitle and the
 *     "Reassign content to" combobox options.
 *  2. A combobox inside a modal (overflow-y:auto) had its dropdown panel
 *     clipped at the modal edge, hiding lower options (the Add-user Role
 *     picker lost roles). The panel now escapes to the viewport (fixed)
 *     when it has a scroll-clipping ancestor.
 *
 * Creates a throwaway user whose display name equals its email; never
 * actually deletes a real user (the delete modal is opened and cancelled).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'user-dropdown' );
	const { browser, page, errors } = await launch();
	await login( page );
	page.on( 'dialog', ( d ) => d.dismiss() ); // never confirm a real delete

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	let uid = null;
	const email = `minn_noname_${ Date.now() % 100000 }@example.com`;
	try {
		// A user whose display name IS the email (the reported repro shape).
		const created = await rest( 'wp/v2/users', { method: 'POST', body: JSON.stringify( {
			username: email, email, name: email, password: 'Dropdown-Test-9x!', roles: [ 'subscriber' ],
		} ) } );
		uid = created.body && created.body.id;
		t.check( 'name==email test user created', !! uid, String( uid ) );

		/* ===== Bug 2: modal combobox panel escapes the modal ===== */
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-user', { timeout: 20000 } );
		await page.click( '#minn-add-user' );
		await page.waitForSelector( '.minn-modal .minn-ac-input', { timeout: 5000 } );
		await page.click( '.minn-modal .minn-ac-input' );
		await page.waitForSelector( '.minn-modal .minn-ac-panel:not([hidden])', { timeout: 5000 } );
		const roleGeo = await page.evaluate( () => {
			const modal = document.querySelector( '.minn-modal' );
			const panel = document.querySelector( '.minn-modal .minn-ac-panel' );
			const m = modal.getBoundingClientRect(), p = panel.getBoundingClientRect();
			return {
				pos: getComputedStyle( panel ).position,
				escapesModal: p.bottom > m.bottom + 1, // panel extends past the modal box (no longer clipped by it)
				withinViewport: p.bottom <= window.innerHeight + 1,
				items: panel.querySelectorAll( '.minn-ac-item' ).length,
			};
		} );
		t.check( 'modal dropdown is fixed-positioned', roleGeo.pos === 'fixed', roleGeo.pos );
		t.check( 'modal dropdown escapes the modal overflow box', roleGeo.escapesModal, JSON.stringify( roleGeo ) );
		t.check( 'modal dropdown stays within the viewport', roleGeo.withinViewport, JSON.stringify( roleGeo ) );
		t.check( 'all roles present in the panel', roleGeo.items >= 5, String( roleGeo.items ) );
		// Picking still works.
		await page.click( '.minn-modal .minn-ac-item[data-acv="editor"]' );
		const picked = await page.evaluate( () => document.querySelector( '.minn-modal .minn-ac-input' ).dataset.acValue );
		t.check( 'picking a role through the escaped panel works', picked === 'editor', picked );
		await page.click( '#minn-modal-close' ).catch( () => {} );
		await page.keyboard.press( 'Escape' ).catch( () => {} );

		/* ===== Bug 1: no "email · email" duplication ===== */
		// Open the delete modal for our name==email user via its row menu.
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-user][data-uemail="${ email }"]`, { timeout: 20000 } );
		await page.click( `[data-user][data-uemail="${ email }"] .minn-row-more` );
		// Menu items render on document.body; click "Delete user…".
		await page.waitForSelector( '.minn-menu-item, .minn-row-menu button, [data-menuitem]', { timeout: 5000 } ).catch( () => {} );
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( 'button, .minn-menu-item, [role="menuitem"]' ) ]
				.find( ( b ) => /Delete user/i.test( b.textContent ) );
			if ( el ) el.click();
		} );
		await page.waitForSelector( '.minn-modal-sub', { timeout: 5000 } );
		const sub = await page.textContent( '.minn-modal-sub' );
		t.check( 'delete subtitle shows the email once (no duplication)',
			sub.includes( email ) && ! new RegExp( email.replace( /[.]/g, '\\.' ) + '\\s*·\\s*' + email.replace( /[.]/g, '\\.' ) ).test( sub ), JSON.stringify( sub ) );

		// Reassign combobox: open it, confirm no option reads "X · X".
		await page.waitForSelector( '#minn-ud-reassign', { timeout: 8000 } );
		await page.click( '#minn-ud-reassign' );
		await page.waitForSelector( '#minn-ud-reassign-ac .minn-ac-panel:not([hidden])', { timeout: 5000 } );
		const dupOption = await page.evaluate( () => {
			return [ ...document.querySelectorAll( '#minn-ud-reassign-ac .minn-ac-item' ) ]
				.map( ( el ) => el.textContent )
				.some( ( txt ) => { const parts = txt.split( ' · ' ); return parts.length === 2 && parts[ 0 ].trim() === parts[ 1 ].trim(); } );
		} );
		t.check( 'reassign options never read "X · X"', dupOption === false );
		const reassignFixed = await page.evaluate( () => getComputedStyle( document.querySelector( '#minn-ud-reassign-ac .minn-ac-panel' ) ).position );
		t.check( 'reassign dropdown also escapes the modal (fixed)', reassignFixed === 'fixed', reassignFixed );

	} finally {
		if ( uid ) await rest( `wp/v2/users/${ uid }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
