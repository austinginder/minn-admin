/* Lab seeder (not a suite): enables the cheque, bacs and cod gateways so the
 * order suites' payment-method picker has entries. A fresh WooCommerce install
 * ships them all disabled, which reads as a product regression in
 * order-payment. Point it at a throwaway lab, never a real site. */
const { launch, login } = require( './helpers' );

( async () => {
	const { browser, page } = await launch();
	await login( page );
	const out = await page.evaluate( async () => {
		const res = [];
		for ( const id of [ 'cheque', 'bacs', 'cod' ] ) {
			const r = await fetch( window.MINN.restUrl + 'wc/v3/payment_gateways/' + id, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { enabled: true } ),
			} );
			const b = await r.json();
			res.push( id + ':' + r.status + ':' + ( b && b.enabled ) );
		}
		return res.join( ' ' );
	} );
	console.log( out );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
