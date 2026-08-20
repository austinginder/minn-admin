/**
 * Deleting a customer from the Customers surface (GitHub #40).
 *
 * Two entry points, one flow: the row's right-click menu and the detail
 * modal's action row both open the same user-delete modal the Users row menu
 * uses (reassignment picker, danger confirm, wp/v2/users force delete).
 *
 * The claim this suite has to keep honest is the copy: deleting a customer
 * does NOT touch their orders. The order must survive with its billing
 * details, and WooCommerce resets customer_id to 0 so it reads as a guest
 * order afterwards. That assertion is the reason this suite creates a real
 * order rather than a bare user.
 *
 * Fixtures are deleted in the finally regardless of outcome.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'customer-delete' );
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

	const gate = await page.evaluate( () => ( {
		wc: !! ( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.customers ),
		del: !! ( window.MINN && window.MINN.caps && window.MINN.caps.deleteUsers ),
		ms: !! ( window.MINN && window.MINN.multisite ),
	} ) );
	if ( ! gate.wc || ! gate.del || gate.ms ) {
		// Multisite deletes accounts network-wide, so the surface offers
		// "Remove from this site" there instead — nothing to assert here.
		t.check( 'WooCommerce customers + delete_users available', false, 'skip — ' + JSON.stringify( gate ) );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce customers + delete_users available', true, '' );

	const suffix = Date.now().toString( 36 );
	const made = [];
	const mkCustomer = async ( tag ) => {
		const email = `suite-del-${ tag }-${ suffix }@example.com`;
		const r = await api( 'wc/v3/customers', {
			method: 'POST',
			body: JSON.stringify( {
				email,
				first_name: 'Delete',
				last_name: 'Fixture' + tag,
				username: `suitedel${ tag }${ suffix }`,
				password: 'TempPass123!x',
				billing: { first_name: 'Delete', last_name: 'Fixture' + tag, email },
			} ),
		} );
		const id = r.body && r.body.id;
		if ( id ) made.push( id );
		return { id, email };
	};

	let orderId = null;
	try {
		const victim = await mkCustomer( 'a' );
		const viaModal = await mkCustomer( 'b' );
		t.check( 'fixture customers created', !! victim.id && !! viaModal.id, `${ victim.id } / ${ viaModal.id }` );
		if ( ! victim.id || ! viaModal.id ) throw new Error( 'no fixtures' );

		// A real order, so the copy's promise about orders is testable.
		let productId = null;
		const prods = await api( 'wc/v3/products?per_page=1&status=publish&_fields=id' );
		productId = prods.body && prods.body[ 0 ] && prods.body[ 0 ].id;
		if ( ! productId ) {
			const p = await api( 'wc/v3/products', {
				method: 'POST',
				body: JSON.stringify( { name: 'Del suite prod ' + suffix, type: 'simple', regular_price: '5', status: 'publish' } ),
			} );
			productId = p.body && p.body.id;
		}
		if ( productId ) {
			const o = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( {
					customer_id: victim.id,
					status: 'processing',
					billing: { first_name: 'Delete', last_name: 'FixtureA', email: victim.email },
					line_items: [ { product_id: productId, quantity: 1 } ],
				} ),
			} );
			orderId = o.body && o.body.id;
		}
		t.check( 'fixture order created for the victim', !! orderId, String( orderId ) );

		const findRow = async ( c ) => {
			await page.goto( BASE + '/minn-admin/customers', { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-customer-search', { timeout: 20000 } );
			await page.fill( '#minn-customer-search', c.email );
			await page.waitForFunction( ( id ) =>
				!! document.querySelector( `.minn-table-row[data-customer="${ id }"]` ), c.id, { timeout: 15000 } ).catch( () => null );
			return page.$( `.minn-table-row[data-customer="${ c.id }"]` );
		};

		// ---- entry point 1: the row's context menu ----
		const row = await findRow( victim );
		t.check( 'victim row on the list', !! row, '' );
		const box = row && await row.boundingBox();
		await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 8000 } );
		const menu = await page.evaluate( () => {
			const el = document.querySelector( '.minn-ctx-menu' );
			return {
				danger: !! Array.from( el.querySelectorAll( '.minn-new-menu-label' ) ).find( ( n ) => /Danger/i.test( n.textContent ) ),
				del: !! Array.from( el.querySelectorAll( 'button' ) ).find( ( n ) => /Delete customer/i.test( n.textContent ) ),
			};
		} );
		t.check( 'context menu offers Delete customer under Danger zone', menu.del && menu.danger, JSON.stringify( menu ) );

		await page.evaluate( () => {
			const btn = Array.from( document.querySelectorAll( '.minn-ctx-menu button' ) ).find( ( n ) => /Delete customer/i.test( n.textContent ) );
			btn.click();
		} );
		await page.waitForSelector( '#minn-ud-confirm', { timeout: 10000 } );
		const modalText = await page.evaluate( () => document.querySelector( '.minn-modal' ).textContent );
		t.check( 'delete modal explains what happens to orders',
			/orders stay/i.test( modalText ) && /guest orders/i.test( modalText ), '' );
		t.check( 'delete modal offers reassignment', /Reassign content to/i.test( modalText ), '' );

		// The reassignment combobox seeds to the current user, so confirming
		// straight through is the documented default path.
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-ud-confirm' );
			return b && ! b.disabled;
		}, null, { timeout: 15000 } );
		await page.click( '#minn-ud-confirm' );
		await page.waitForSelector( '.minn-confirm-overlay [data-ok]', { timeout: 10000 } );
		const confirmText = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent );
		t.check( 'confirm repeats the orders note', /orders stay/i.test( confirmText ), '' );
		t.check( 'confirm names the customer', new RegExp( 'FixtureA', 'i' ).test( confirmText ), confirmText.slice( 0, 80 ) );
		await page.click( '.minn-confirm-overlay [data-ok]' );

		// The row must go WITHOUT a reload: that is the `after` hook doing its
		// job. Before this shipped, the delete landed and the list kept the row.
		const gone = await page.waitForFunction( ( id ) =>
			! document.querySelector( `.minn-table-row[data-customer="${ id }"]` ), victim.id, { timeout: 20000 } )
			.then( () => true ).catch( () => false );
		t.check( 'row disappears from the list without a reload', gone, '' );

		const check = await api( `wp/v2/users/${ victim.id }?context=edit` );
		t.check( 'account is gone on the server', check.status === 404, String( check.status ) );

		// ---- the promise the copy makes ----
		if ( orderId ) {
			const ord = await api( `wc/v3/orders/${ orderId }?_fields=id,customer_id,billing,status` );
			t.check( 'order survives the deletion', ord.status === 200, String( ord.status ) );
			t.check( 'order became a guest order', ord.body && ord.body.customer_id === 0, JSON.stringify( ord.body && ord.body.customer_id ) );
			t.check( 'order keeps its billing details',
				!! ( ord.body && ord.body.billing && ord.body.billing.email ), ( ord.body && ord.body.billing && ord.body.billing.email ) || '' );
		}

		// ---- entry point 2: the detail modal's action row ----
		const row2 = await findRow( viaModal );
		t.check( 'second fixture row on the list', !! row2, '' );
		await page.evaluate( ( id ) => document.querySelector( `.minn-table-row[data-customer="${ id }"]` ).click(), viaModal.id );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ! m.textContent.includes( 'Loading customer' );
		}, null, { timeout: 15000 } ).catch( () => null );
		t.check( 'customer modal carries a Delete customer button', !! ( await page.$( '#minn-cust-delete' ) ), '' );
		await page.click( '#minn-cust-delete' );
		await page.waitForSelector( '#minn-ud-confirm', { timeout: 10000 } );
		const fromModal = await page.evaluate( () => document.querySelector( '.minn-modal' ).textContent );
		t.check( 'modal button opens the same delete flow',
			/Reassign content to/i.test( fromModal ) && /orders stay/i.test( fromModal ), '' );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( orderId ) await api( `wc/v3/orders/${ orderId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		for ( const id of made ) {
			await api( `wp/v2/users/${ id }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => null );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
