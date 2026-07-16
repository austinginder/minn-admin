/**
 * Comment bulk moderation (core-gaps bundle). Every comment action was one
 * row at a time; sites with real comment volume need batches. This seeds
 * pending comments, selects them via the select-page control, bulk-approves
 * through the UI (the bar's verbs are the current tab's own actions), and
 * verifies the SAVED status on the server.
 *
 * Disable Comments is the resident plugin on minnadmin, so it gates the
 * Comments route; the suite deactivates it at start and restores it (plus
 * deletes the seeded comments) in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'comment-bulk' );
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

	const setPlugin = ( plugin, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.plugin, {
			method: 'PUT', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return r.ok;
	}, { plugin, status } );

	const ids = [];
	try {
		t.check( 'disable-comments deactivates over REST', await setPlugin( 'disable-comments/disable-comments', 'inactive' ) );

		// Seed three pending comments on post 1 via REST.
		for ( let i = 0; i < 3; i++ ) {
			const r = await rest( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( {
				post: 1, author_name: `Bulk Tester ${ i }`, author_email: `bulk${ i }@example.com`,
				content: `Bulk moderation test comment ${ i } ${ Date.now() }`, status: 'hold',
			} ) } );
			if ( r.body && r.body.id ) ids.push( r.body.id );
		}
		t.check( 'three pending comments seeded', ids.length === 3, ids.join( ',' ) );

		// Open comments (defaults to the Pending/hold tab).
		await page.goto( BASE + '/minn-admin/comments', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-comment-selall', { timeout: 20000 } );

		// No bar until something is selected.
		t.check( 'no bulk bar with nothing selected', ( await page.$( '#minn-comment-bulk-slot .minn-bulkbar' ) ) === null );

		// Select the whole page.
		await page.click( '#minn-comment-selall' );
		const count = await page.textContent( '.minn-bulk-count' ).catch( () => '' );
		t.check( 'select-page selects every row', /[1-9]\d* selected/.test( count ), count );

		// The Pending tab offers Approve / Spam / Trash — the bar should carry them.
		const verbs = await page.$$eval( '#minn-comment-bulk-slot [data-cbulk]', ( els ) => els.map( ( e ) => e.dataset.cbulk ) );
		t.check( 'bar verbs match the Pending tab actions', verbs.includes( 'approved' ) && verbs.includes( 'spam' ) && verbs.includes( 'trash' ), verbs.join( ',' ) );

		// Bulk approve. The bar clears when the run finishes (selection reset
		// + re-render) — wait for that, not a flat timeout: three sequential
		// POSTs can outlast 1.5s and the verify then reads a comment whose
		// update hasn't landed yet.
		await page.click( '#minn-comment-bulk-slot [data-cbulk="approved"]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-comment-bulk-slot .minn-bulkbar' ), null, { timeout: 20000 } );
		await page.waitForTimeout( 400 );

		// Verify each seeded comment is now approved on the server.
		let approved = 0;
		for ( const id of ids ) {
			const r = await rest( `wp/v2/comments/${ id }?context=edit&_fields=status` );
			if ( r.body && r.body.status === 'approved' ) approved++;
		}
		t.check( 'all seeded comments approved on the server', approved === ids.length, `${ approved }/${ ids.length }` );

	} finally {
		for ( const id of ids ) await rest( `wp/v2/comments/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await setPlugin( 'disable-comments/disable-comments', 'active' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
