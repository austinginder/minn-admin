/**
 * GF Feeds view — every add-on integration across forms (backlog rank 10's
 * shipped half: visibility + on/off + delete; feed CONFIG stays on the
 * add-on's screen). Fixture: the Twilio feed add-on; the feed is seeded
 * through GF's OWN gf/v2 REST CRUD and everything else drives the real UI.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-feeds' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// Seed through GF's own REST API.
	const feedId = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'gf/v2/feeds', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { form_id: 1, addon_slug: 'gravityformstwilio', meta: { feedName: 'Minn Suite Feed', smsMessage: 'suite' } } ),
		} );
		const j = await r.json().catch( () => null );
		return j && ( j.id || ( typeof j === 'number' ? j : null ) );
	} );
	t.check( 'feed seeded through gf/v2', !! feedId, String( feedId ) );

	const shimRow = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/feeds?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		const j = await r.json();
		return j.items.find( ( x ) => x.name === 'Minn Suite Feed' ) || null;
	} );

	try {
		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );
		const feedsTab = await page.$$eval( '.minn-view-switch [data-sview]', ( els ) => {
			const hit = els.find( ( e ) => e.textContent.trim() === 'Feeds' );
			return hit ? hit.dataset.sview : null;
		} );
		t.check( 'switcher offers the Feeds view', !! feedsTab, String( feedsTab ) );

		await page.click( `[data-sview="${ feedsTab }"]` );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ),
		null, { timeout: 20000 } );
		const rowText = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ).textContent );
		t.check( 'row names the add-on and form', /Twilio/.test( rowText ) && /Contact Form/.test( rowText ), rowText.trim().replace( /\s+/g, ' ' ) );
		t.check( 'row wears the active pill', /active/i.test( rowText ) );

		// Deactivate from the detail modal (GF's own property write).
		await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ).click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		const actions = await page.$$eval( '.minn-modal [data-saction]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		// href actions render as links, not buttons — the deep link carries
		// the add-on subview + feed id.
		const hrefs = await page.$$eval( '.minn-modal a[href]', ( els ) => els.map( ( e ) => e.href ) );
		t.check( 'detail offers toggle, delete and the GF escape',
			actions.includes( 'Deactivate' ) && actions.includes( 'Delete' )
				&& hrefs.some( ( h ) => /subview=gravityformstwilio/.test( h ) && /fid=/.test( h ) ),
			actions.join( ',' ) + ' | ' + hrefs.join( ' ' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Deactivate' ).click() );
		await page.waitForFunction( () =>
			! document.querySelector( '.minn-modal' ) ||
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) && /inactive/i.test( r.textContent ) ),
		null, { timeout: 20000 } );
		let row = null;
		for ( let i = 0; i < 10 && ! ( row && row.status === 'inactive' ); i++ ) {
			await page.waitForTimeout( 500 );
			row = await shimRow();
		}
		t.check( 'deactivate persisted through GF', !! row && row.status === 'inactive', JSON.stringify( row ) );

		// Reactivate from the row (when-conditional flip).
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ),
		null, { timeout: 20000 } );
		await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ).click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		t.check( 'toggle flips to Activate when inactive', await page.$$eval( '.minn-modal [data-saction]', ( els ) =>
			els.some( ( e ) => e.textContent.trim() === 'Activate' ) && ! els.some( ( e ) => e.textContent.trim() === 'Deactivate' ) ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Activate' ).click() );
		row = null;
		for ( let i = 0; i < 10 && ! ( row && row.status === 'active' ); i++ ) {
			await page.waitForTimeout( 500 );
			row = await shimRow();
		}
		t.check( 'reactivate persisted through GF', !! row && row.status === 'active', JSON.stringify( row ) );

		// Delete through the real confirm.
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ),
		null, { timeout: 20000 } );
		await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn Suite Feed' ) ).click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Delete' ).click() );
		let gone = false;
		for ( let i = 0; i < 12 && ! gone; i++ ) {
			await page.waitForTimeout( 500 );
			gone = ! ( await shimRow() );
		}
		t.check( 'delete removes the feed through GFAPI', gone );
	} finally {
		await page.evaluate( async ( id ) => {
			if ( ! id ) return;
			await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/feeds/' + id, {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ).catch( () => {} );
		}, feedId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
