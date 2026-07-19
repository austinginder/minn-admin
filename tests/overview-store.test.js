/**
 * Overview "Store" strip — needs-attention order buckets on the dashboard,
 * each chip a door to the Orders list pre-filtered to its status tab.
 *
 * Fixtures: creates one pending and one failed order over wc/v3, deletes
 * them on the way out. Counts are asserted against the overview endpoint's
 * own numbers (never absolutes — minnadmin carries real fixture orders).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'overview-store' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce orders available', false, 'caps.orders missing — skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce orders available', true, '' );

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

	const fixtureIds = [];
	try {
		// Fixture orders: one awaiting payment, one failed, so those chips
		// are guaranteed nonzero regardless of the site's resident orders.
		for ( const status of [ 'pending', 'failed' ] ) {
			const made = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( {
					status,
					billing: { first_name: 'Strip', last_name: 'Fixture', email: 'strip-fixture@example.com' },
				} ),
			} );
			t.check( `created ${ status } fixture order`, made.status === 201 && made.body && made.body.id, String( made.status ) );
			if ( made.body && made.body.id ) fixtureIds.push( made.body.id );
		}

		// The endpoint's own counts are the expected truth for the chips.
		const ov = await api( 'minn-admin/v1/overview?days=30' );
		t.check( 'overview carries store counts', ov.status === 200 && ov.body && ov.body.store && typeof ov.body.store.processing === 'number', JSON.stringify( ov.body && ov.body.store ) );
		const store = ( ov.body && ov.body.store ) || {};

		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-store-strip', { timeout: 15000 } );
		t.check( 'store strip renders', true, '' );

		// Chips match the endpoint: nonzero buckets present with the count,
		// zero buckets absent.
		const buckets = [
			[ 'pending', store.pending, 'awaiting payment' ],
			[ 'on-hold', store.onhold, 'on hold' ],
			[ 'processing', store.processing, 'to fulfill' ],
			[ 'failed', store.failed, 'failed' ],
		];
		for ( const [ tab, count, label ] of buckets ) {
			const text = await page.evaluate( ( sel ) => {
				const el = document.querySelector( `[data-sotab="${ sel }"]` );
				return el ? el.textContent.trim() : null;
			}, tab );
			if ( count > 0 ) {
				t.check( `chip ${ tab } shows "${ count } ${ label }"`, text === `${ count } ${ label }`, String( text ) );
			} else {
				t.check( `chip ${ tab } absent at zero`, text === null, String( text ) );
			}
		}

		// Chip navigation: pending chip lands on Orders with the Pending tab
		// active and the fixture order in the list.
		await page.click( '[data-sotab="pending"]' );
		await page.waitForSelector( '[data-otab="pending"].active', { timeout: 15000 } );
		t.check( 'pending chip lands on Orders with Pending tab active', true, '' );
		t.check( 'URL is the orders route', page.url().includes( '/minn-admin/orders' ), page.url() );
		const searchCleared = await page.evaluate( () => {
			const s = document.querySelector( '#minn-order-search' );
			return s ? s.value === '' : true;
		} );
		t.check( 'order search arrives cleared', searchCleared, '' );
		await page.waitForFunction( ( id ) => document.body.textContent.includes( '#' + id ), fixtureIds[ 0 ], { timeout: 15000 } );
		t.check( 'fixture pending order visible in the filtered list', true, '' );

		// View orders button: back to Overview, then the plain door.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-store-view', { timeout: 15000 } );
		await page.click( '#minn-store-view' );
		await page.waitForSelector( '[data-otab]', { timeout: 15000 } );
		t.check( 'View orders opens the Orders list', page.url().includes( '/minn-admin/orders' ), page.url() );
	} finally {
		for ( const id of fixtureIds ) {
			await api( `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
