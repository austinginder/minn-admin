/**
 * PDF Invoices & Packing Slips delight — the order detail modal carries a
 * download link per enabled document (adapters/wcpdf.php), riding the
 * plugin's own admin-ajax endpoint + nonce. Fetches the invoice link
 * in-page and asserts real PDF bytes come back, so the nonce and the
 * plugin's permission model are proven end to end.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'wcpdf' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const boot = await page.evaluate( () => window.MINN.wcpdf );
	t.check( 'boot payload carries wcpdf docs + nonce', !! boot && Array.isArray( boot.docs ) && boot.docs.length >= 1 && !! boot.nonce );
	t.check( 'invoice document is enabled', !! boot && boot.docs.some( ( d ) => d.type === 'invoice' ) );

	await page.waitForSelector( '.minn-table-row[data-order]', { timeout: 20000 } );
	await page.click( '.minn-table-row[data-order]' );
	await page.waitForSelector( '.minn-modal-actions', { timeout: 10000 } );

	const links = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-modal-actions a' ) )
			.map( ( a ) => ( { text: a.textContent.trim(), href: a.href } ) ) );
	const invoice = links.find( ( l ) => /Invoice \(PDF\)/.test( l.text ) );
	t.check( 'order modal shows an Invoice (PDF) link', !! invoice, JSON.stringify( links.map( ( l ) => l.text ) ) );
	t.check( 'order modal shows a Packing Slip (PDF) link', links.some( ( l ) => /Packing Slip \(PDF\)/.test( l.text ) ) );
	t.check( 'link targets the plugin endpoint with its nonce',
		!! invoice && invoice.href.includes( 'action=generate_wpo_wcpdf' ) && invoice.href.includes( 'access_key=' ) && invoice.href.includes( 'document_type=invoice' ) );

	// Prove the link actually streams a PDF (nonce + caps accepted).
	if ( invoice ) {
		const res = await page.evaluate( async ( url ) => {
			const r = await fetch( url, { credentials: 'same-origin' } );
			const buf = new Uint8Array( await r.arrayBuffer() );
			return {
				status: r.status,
				type: r.headers.get( 'content-type' ) || '',
				magic: String.fromCharCode( ...buf.slice( 0, 5 ) ),
			};
		}, invoice.href );
		t.check( 'invoice link streams a real PDF', res.status === 200 && res.magic === '%PDF-',
			`status=${ res.status } type=${ res.type } magic=${ JSON.stringify( res.magic ) }` );
	} else {
		t.check( 'invoice link streams a real PDF', false, 'no invoice link' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
