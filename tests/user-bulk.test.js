/**
 * Bulk user role change (core-gaps bundle). Users were row-at-a-time; role
 * changes at scale need a batch. This seeds throwaway subscribers, selects
 * them via the checkbox column, bulk-changes their role to Editor through the
 * UI, and verifies the SAVED roles on the server. The current user is
 * deliberately skipped by the batch (self-lockout guard).
 *
 * Throwaway users are deleted in the finally regardless.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'user-bulk' );
	const { browser, page, errors } = await launch();
	await login( page );
	page.on( 'dialog', ( d ) => d.accept() );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	const ids = [];
	try {
		const stamp = Date.now();
		for ( let i = 0; i < 2; i++ ) {
			const r = await rest( 'wp/v2/users', { method: 'POST', body: JSON.stringify( {
				username: `minnbulk_${ stamp }_${ i }`, email: `minnbulk_${ stamp }_${ i }@example.com`,
				password: 'Bulk-Test-Pass-9x!', roles: [ 'subscriber' ], name: `Minn Bulk ${ i }`,
			} ) } );
			if ( r.body && r.body.id ) ids.push( r.body.id );
		}
		t.check( 'two subscribers seeded', ids.length === 2, ids.join( ',' ) );

		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		for ( const id of ids ) await page.waitForSelector( `.minn-user-cb[data-cbid="${ id }"]`, { timeout: 20000 } );

		// No bar until selected.
		t.check( 'no bulk bar with nothing selected', ( await page.$( '#minn-user-bulk-slot .minn-bulkbar' ) ) === null );

		for ( const id of ids ) await page.click( `.minn-user-cb[data-cbid="${ id }"]` );
		const count = await page.textContent( '.minn-bulk-count' ).catch( () => '' );
		t.check( 'selection bar counts the picked users', /2 selected/.test( count ), count );

		// Clicking a checkbox must not open the user modal.
		t.check( 'checkbox does not open the user modal', ( await page.$( '#minn-modal-overlay' ) ) === null );

		// Change role to Editor.
		await page.selectOption( '#minn-user-bulk-role', 'editor' );
		await page.click( '#minn-user-bulk-apply' );
		await page.waitForSelector( '#minn-user-bulk-slot .minn-bulkbar', { state: 'detached', timeout: 30000 } );

		// Verify roles on the server.
		let editors = 0;
		for ( const id of ids ) {
			const r = await rest( `wp/v2/users/${ id }?context=edit&_fields=roles` );
			if ( r.body && Array.isArray( r.body.roles ) && r.body.roles.includes( 'editor' ) ) editors++;
		}
		t.check( 'both users are now Editor on the server', editors === 2, `${ editors }/2` );

	} finally {
		for ( const id of ids ) await rest( `wp/v2/users/${ id }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
