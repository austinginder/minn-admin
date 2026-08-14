/* Lab seeder (not a suite): one order per WooCommerce status, plus a partial
 * refund, order notes, a customer note and a distinct shipping address, so the
 * order detail can be walked by hand in every state it renders.
 *
 * It leaves what it creates behind on purpose, which is the opposite of a
 * suite's contract: point it at a throwaway lab, never a real site. */
const { launch, login } = require( './helpers' );

( async () => {
	const { browser, page } = await launch();
	await login( page );
	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const b = await r.json();
		if ( ! r.ok ) throw new Error( a.path + ' -> ' + r.status + ' ' + JSON.stringify( b ).slice( 0, 200 ) );
		return b;
	}, { path, opts } );

	const products = [];
	for ( const [ name, price ] of [ [ 'Zen Garden Kit', '20.00' ], [ 'Aurora Desk Lamp', '45.50' ], [ 'Field Notebook (3-pack)', '12.00' ] ] ) {
		const p = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name, type: 'simple', regular_price: price, status: 'publish' } ) } );
		products.push( p );
	}
	const dana = { first_name: 'Dana', last_name: 'Lee', email: 'dana@example.com', phone: '+1 555 0134', address_1: '742 Evergreen Terrace', city: 'Springfield', state: 'IL', postcode: '62704', country: 'US' };
	const shipDana = { first_name: 'Dana', last_name: 'Lee', address_1: '1 Warehouse Way', city: 'Peoria', state: 'IL', postcode: '61602', country: 'US' };
	const sam = { first_name: 'Sam', last_name: 'Rivera', email: 'sam@example.com', phone: '', address_1: '9 Rue de Rivoli', city: 'Paris', postcode: '75001', country: 'FR' };

	const mk = ( body ) => api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( body ) } );
	const note = ( id, text, customer ) => api( `wc/v3/orders/${ id }/notes`, { method: 'POST', body: JSON.stringify( { note: text, customer_note: !! customer } ) } );

	const made = [];
	// processing, paid, notes, distinct shipping, customer note.
	const o1 = await mk( { status: 'processing', set_paid: true, billing: dana, shipping: shipDana, customer_note: 'Please gift wrap, it is a present.', line_items: [ { product_id: products[ 0 ].id, quantity: 1 }, { product_id: products[ 1 ].id, quantity: 2 } ] } );
	await note( o1.id, 'Called the customer to confirm the address.' );
	await note( o1.id, 'Your order is being prepared for shipment.', true );
	made.push( [ 'processing', o1.id ] );
	// completed, paid, partial refund.
	const o2 = await mk( { status: 'completed', set_paid: true, billing: dana, shipping: dana, line_items: [ { product_id: products[ 2 ].id, quantity: 3 } ] } );
	await api( `wc/v3/orders/${ o2.id }/refunds`, { method: 'POST', body: JSON.stringify( { amount: '12.00', reason: 'One unit arrived damaged', api_refund: false, api_restock: false } ) } );
	made.push( [ 'completed+refund', o2.id ] );
	// on-hold (classic check flow), unpaid.
	made.push( [ 'on-hold', ( await mk( { status: 'on-hold', payment_method: 'cheque', payment_method_title: 'Check payments', billing: sam, shipping: sam, line_items: [ { product_id: products[ 0 ].id, quantity: 2 } ] } ) ).id ] );
	// pending, unpaid → Record payment flow.
	made.push( [ 'pending', ( await mk( { status: 'pending', billing: sam, shipping: sam, line_items: [ { product_id: products[ 1 ].id, quantity: 1 } ] } ) ).id ] );
	// cancelled.
	made.push( [ 'cancelled', ( await mk( { status: 'cancelled', billing: dana, shipping: dana, line_items: [ { product_id: products[ 2 ].id, quantity: 1 } ] } ) ).id ] );
	// refunded in full.
	const o6 = await mk( { status: 'completed', set_paid: true, billing: sam, shipping: sam, line_items: [ { product_id: products[ 0 ].id, quantity: 1 } ] } );
	await api( `wc/v3/orders/${ o6.id }/refunds`, { method: 'POST', body: JSON.stringify( { amount: o6.total, reason: 'Order returned', api_refund: false, api_restock: false } ) } );
	made.push( [ 'refunded', o6.id ] );
	// failed.
	made.push( [ 'failed', ( await mk( { status: 'failed', billing: dana, shipping: dana, line_items: [ { product_id: products[ 1 ].id, quantity: 1 } ] } ) ).id ] );

	console.log( made.map( ( [ s, id ] ) => `${ s }: /minn-admin/orders/${ id }` ).join( '\n' ) );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
