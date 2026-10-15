/**
 * PDF Invoices & Packing Slips (adapters/wcpdf.php).
 *
 * Proves, on an order the suite creates:
 * - the orders list carries an Invoice column from the plugin's own
 *   wpo_wcpdf_invoice_number field, its number linking to the invoice PDF,
 *   and the row's right-click menu opens each enabled document;
 * - the order page has a Documents card with a download per enabled
 *   document (and the ⋯ menu no longer repeats them);
 * - Create, Edit, Regenerate and Delete on the card write through the
 *   plugin, as read back from the order itself;
 * - the plugin's own editing rule holds: with document data editing off the
 *   edit dialog offers notes only and the route refuses a number, and with
 *   it on the number saves; an edit sends only the field that changed;
 * - a note the plugin's own form stored with CRLF line breaks is not sent
 *   back by an edit that leaves it alone;
 * - the date the card reads names the right instant on a site east of UTC;
 * - a notes save leaves a free packing slip without a number;
 * - a trashed order has no Documents card (the plugin makes no documents
 *   for one);
 * - the invoice link streams a real PDF (the plugin's nonce and caps);
 * - an Author is refused and a signed-out caller is asked to sign in;
 * - zero console errors.
 *
 * Restores the plugin's debug settings and the site's time zone, and
 * deletes the order.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, loginAs, reporter } = require( './helpers' );

const wpPhp = ( php ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=admin 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'wcpdf' );
	const debugBefore = wpPhp( "echo wp_json_encode( get_option( 'wpo_wcpdf_settings_debug', array() ) );" );
	const zoneBefore = wpPhp( "echo wp_json_encode( array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) ) );" );
	const setEditing = ( on ) => wpPhp( `$d = json_decode( ${ JSON.stringify( debugBefore ) }, true ); $d = is_array( $d ) ? $d : array(); if ( ${ on ? 'true' : 'false' } ) { $d['enable_document_data_editing'] = 1; } else { unset( $d['enable_document_data_editing'] ); } update_option( 'wpo_wcpdf_settings_debug', $d ); echo 'ok';` );
	setEditing( false );
	const oid = parseInt( wpPhp( "$p = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'type' => 'simple', 'return' => 'ids' ) ); $o = wc_create_order(); if ( $p ) { $o->add_product( wc_get_product( $p[0] ), 1 ); } $o->set_billing_first_name( 'Wcpdf' ); $o->set_billing_last_name( 'Suite' ); $o->calculate_totals(); $o->set_status( 'processing' ); $o->save(); echo $o->get_id();" ), 10 );
	const invoice = () => JSON.parse( wpPhp( `$d = wcpdf_get_document( 'invoice', wc_get_order( ${ oid } ) ); echo wp_json_encode( $d && $d->exists() ? array( 'plain' => $d->get_number()->get_plain(), 'formatted' => $d->get_number()->get_formatted(), 'notes' => (string) $d->get_notes() ) : null );` ) );
	const { browser, page, errors } = await launch();
	try {
		t.check( 'fixture order created', oid > 1, String( oid ) );
		await login( page );
		await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
		const boot = await page.evaluate( () => window.MINN.wcpdf );
		t.check( 'boot payload carries wcpdf docs + nonce', !! boot && Array.isArray( boot.docs ) && boot.docs.length >= 1 && !! boot.nonce );
		t.check( 'invoice and packing slip are enabled', !! boot && boot.docs.some( ( d ) => d.type === 'invoice' ) && boot.docs.some( ( d ) => d.type === 'packing-slip' ) );

		// The list: an Invoice column, empty for an order with no invoice yet.
		const row = `.minn-table-row[data-order="${ oid }"]`;
		await page.waitForSelector( row, { timeout: 20000 } );
		const head = await page.$$eval( '.minn-table-head.minn-order-cols > div', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'the orders list has an Invoice column before Total', head.indexOf( 'Invoice' ) !== -1 && head.indexOf( 'Invoice' ) === head.indexOf( 'Total' ) - 1, JSON.stringify( head ) );
		t.check( 'an order with no invoice shows no number', ( await page.$$( `${ row } [data-wcpdf-cell]` ) ).length === 0 );
		await page.click( row, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const menu = await page.$$eval( '.minn-ctx-menu button', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'the row menu opens the Invoice and Packing Slip PDFs', menu.includes( 'Invoice (PDF)' ) && menu.includes( 'Packing Slip (PDF)' ), JSON.stringify( menu ) );
		await page.keyboard.press( 'Escape' );

		// The order page: a Documents card with a download per document.
		await page.goto( BASE + '/minn-admin/orders/' + oid, { waitUntil: 'domcontentloaded' } );
		const card = '.minn-order-page .minn-order-wcpdf';
		await page.waitForSelector( `${ card } [data-wcpdf-doc="invoice"] .minn-wcpdf-meta`, { timeout: 20000 } );
		const links = await page.$$eval( `${ card } a.minn-wcpdf-pdf`, ( els ) => els.map( ( a ) => a.href ) );
		t.check( 'the card links each document to the plugin endpoint with its nonce',
			links.length === 2 && links.every( ( h ) => h.includes( 'action=generate_wpo_wcpdf' ) && h.includes( 'access_key=' ) && h.includes( 'order_ids=' + oid ) )
			&& links.some( ( h ) => h.includes( 'document_type=invoice' ) ) && links.some( ( h ) => h.includes( 'document_type=packing-slip' ) ), JSON.stringify( links ) );
		t.check( 'the ⋯ menu no longer repeats the PDF links', ( await page.$$( '.minn-order-more-src a[href*="generate_wpo_wcpdf"]' ) ).length === 0 );
		t.check( 'the invoice reads "Not created yet"', /Not created yet/.test( await page.textContent( `${ card } [data-wcpdf-doc="invoice"]` ) ) );
		t.check( 'the packing slip has a download and no data actions', ( await page.$$( `${ card } [data-wcpdf-doc="packing-slip"] [data-wcpdf-menu]` ) ).length === 0 );

		const docMenu = async ( label ) => {
			await page.click( `${ card } [data-wcpdf-menu="invoice"]` );
			await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
			const btn = await page.$( `.minn-ctx-menu button:text-is("${ label }")` );
			if ( ! btn ) return false;
			await btn.click();
			return true;
		};
		const metaText = () => page.textContent( `${ card } [data-wcpdf-doc="invoice"] .minn-wcpdf-meta` );

		// Create.
		t.check( 'Create is offered for a missing invoice', await docMenu( 'Create' ) );
		await page.waitForFunction( ( sel ) => /No\. /.test( ( document.querySelector( sel ) || {} ).textContent || '' ), `${ card } [data-wcpdf-doc="invoice"] .minn-wcpdf-meta`, { timeout: 15000 } );
		const created = invoice();
		t.check( 'Create made the invoice on the order', !! created && created.plain > 0, JSON.stringify( created ) );
		t.check( 'the card shows the plugin\'s number', !! created && ( await metaText() ).includes( 'No. ' + created.formatted ), await metaText() );

		// Edit with data editing off: notes only, and only notes sent.
		const sent = [];
		page.on( 'request', ( r ) => {
			if ( r.method() === 'PUT' && r.url().includes( `wcpdf/orders/${ oid }/documents/invoice` ) ) sent.push( r.postData() || '' );
		} );
		t.check( 'Edit is offered once it exists', await docMenu( 'Edit…' ) );
		await page.waitForSelector( '#minn-wcpdf-notes', { timeout: 5000 } );
		t.check( 'with editing off the dialog offers no number or date', ( await page.$$( '#minn-wcpdf-number, #minn-wcpdf-date' ) ).length === 0 );
		await page.fill( '#minn-wcpdf-notes', 'Thank you for your order.' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForSelector( '.minn-order-submodal', { state: 'detached', timeout: 15000 } );
		t.check( 'the notes saved on the order', ( invoice() || {} ).notes === 'Thank you for your order.' );
		t.check( 'the save sent the notes alone', sent.length === 1 && JSON.stringify( Object.keys( JSON.parse( sent[ 0 ] ) ) ) === '["notes"]', JSON.stringify( sent ) );
		t.check( 'the card shows the notes', /Thank you for your order\./.test( await page.textContent( `${ card } [data-wcpdf-doc="invoice"]` ) ) );
		const refused = await page.evaluate( async ( id ) => {
			const r = await fetch( `${ window.MINN.restUrl }minn-admin/v1/wcpdf/orders/${ id }/documents/invoice`, { method: 'PUT', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( { number: 999999 } ) } );
			return r.status;
		}, oid );
		t.check( 'with editing off the route refuses a number (403)', refused === 403, String( refused ) );
		t.check( 'and the number did not change', ( invoice() || {} ).plain === created.plain );

		// Edit with data editing on: the number saves, the date is not sent,
		// and a note stored with CRLF breaks (as the plugin's form saves it)
		// is not sent back either.
		setEditing( true );
		wpPhp( `$o = wc_get_order( ${ oid } ); $d = wcpdf_get_document( 'invoice', $o ); $d->set_notes( "Line one\r\nLine two" ); $d->save(); echo 'ok';` );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `${ card } [data-wcpdf-menu="invoice"]`, { timeout: 20000 } );
		await page.waitForFunction( ( sel ) => /No\. /.test( ( document.querySelector( sel ) || {} ).textContent || '' ), `${ card } [data-wcpdf-doc="invoice"] .minn-wcpdf-meta`, { timeout: 15000 } );
		sent.length = 0;
		await docMenu( 'Edit…' );
		await page.waitForSelector( '#minn-wcpdf-number', { timeout: 5000 } );
		t.check( 'with editing on the dialog offers the number and date', ( await page.$$( '#minn-wcpdf-number, #minn-wcpdf-date' ) ).length === 2 );
		const next = created.plain + 1000;
		await page.fill( '#minn-wcpdf-number', String( next ) );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForSelector( '.minn-order-submodal', { state: 'detached', timeout: 15000 } );
		const edited = invoice() || {};
		t.check( 'the number saved on the order', edited.plain === next, JSON.stringify( edited ) );
		t.check( 'the save sent the number alone', sent.length === 1 && JSON.stringify( Object.keys( JSON.parse( sent[ 0 ] ) ) ) === '["number"]', JSON.stringify( sent ) );
		t.check( 'the card follows the new number', ( await metaText() ).includes( 'No. ' + edited.formatted ), await metaText() );

		// The date names the right instant on a site east of UTC.
		wpPhp( "update_option( 'timezone_string', 'Pacific/Auckland' ); echo 'ok';" );
		const instant = await page.evaluate( async ( id ) => {
			const r = await fetch( `${ window.MINN.restUrl }minn-admin/v1/wcpdf/orders/${ id }/documents`, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			const inv = ( ( await r.json() ).documents || [] ).find( ( d ) => d.type === 'invoice' ) || {};
			return { at: Date.parse( inv.date ) / 1000, local: inv.date_local };
		}, oid );
		const stored = JSON.parse( wpPhp( `$d = wcpdf_get_document( 'invoice', wc_get_order( ${ oid } ) ); echo wp_json_encode( array( $d->get_date()->getTimestamp(), wp_date( 'Y-m-d\\TH:i', $d->get_date()->getTimestamp() ) ) );` ) );
		t.check( 'on a UTC+13 site the date names the stored instant', instant.at === stored[ 0 ], JSON.stringify( [ instant, stored ] ) );
		t.check( 'and its local form is the site\'s clock', instant.local === stored[ 1 ], JSON.stringify( [ instant, stored ] ) );
		wpPhp( `$z = json_decode( ${ JSON.stringify( zoneBefore ) }, true ); update_option( 'timezone_string', $z[0] ); update_option( 'gmt_offset', $z[1] ); echo 'ok';` );

		// A free packing slip: created, it keeps no number, and a notes save
		// does not give it one.
		const slip = await page.evaluate( async ( id ) => {
			const call = async ( suffix, method, body ) => ( await fetch( `${ window.MINN.restUrl }minn-admin/v1/wcpdf/orders/${ id }/documents/packing-slip${ suffix }`, { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify( body ) : undefined } ) ).json();
			const made = await call( '/create', 'POST' );
			const noted = await call( '', 'PUT', { notes: 'Fragile' } );
			return { made: made.document, noted: noted.document };
		}, oid );
		t.check( 'a created packing slip keeps no number and is not tracked', !! slip.made && slip.made.exists && slip.made.number_plain === null && slip.made.tracked === false, JSON.stringify( slip.made ) );
		t.check( 'a notes save leaves it without a number', !! slip.noted && slip.noted.notes === 'Fragile' && slip.noted.number_plain === null, JSON.stringify( slip.noted ) );

		// Regenerate keeps the number and the plugin notes it on the order.
		await docMenu( 'Regenerate…' );
		await page.click( '.minn-confirm-overlay [data-ok]' );
		await page.waitForFunction( () => /regenerated/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ), null, { timeout: 15000 } );
		const notes = JSON.parse( wpPhp( `echo wp_json_encode( array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => ${ oid } ) ) ) );` ) );
		t.check( 'Regenerate kept the number', ( invoice() || {} ).plain === next );
		t.check( 'the plugin noted the regeneration on the order', notes.some( ( n ) => /regenerated/i.test( n ) ), JSON.stringify( notes ) );

		// The invoice link streams a real PDF.
		const href = await page.getAttribute( `${ card } [data-wcpdf-doc="invoice"] a.minn-wcpdf-pdf`, 'href' );
		const pdf = await page.evaluate( async ( url ) => {
			const r = await fetch( url, { credentials: 'same-origin' } );
			const buf = new Uint8Array( await r.arrayBuffer() );
			return { status: r.status, magic: String.fromCharCode( ...buf.slice( 0, 5 ) ) };
		}, href );
		t.check( 'the invoice link streams a real PDF', pdf.status === 200 && pdf.magic === '%PDF-', JSON.stringify( pdf ) );

		// The list now shows the number, linking to the PDF.
		await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `${ row } [data-wcpdf-cell]`, { timeout: 20000 } );
		const cell = await page.$eval( `${ row } [data-wcpdf-cell]`, ( a ) => ( { text: a.textContent.trim(), href: a.href } ) );
		t.check( 'the list shows the invoice number', cell.text === edited.formatted, JSON.stringify( cell ) );
		t.check( 'the number links to the invoice PDF', cell.href.includes( 'document_type=invoice' ) && cell.href.includes( 'order_ids=' + oid ) );

		// Delete.
		await page.goto( BASE + '/minn-admin/orders/' + oid, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( ( sel ) => /No\. /.test( ( document.querySelector( sel ) || {} ).textContent || '' ), `${ card } [data-wcpdf-doc="invoice"] .minn-wcpdf-meta`, { timeout: 20000 } );
		await docMenu( 'Delete…' );
		await page.click( '.minn-confirm-overlay [data-ok]' );
		await page.waitForFunction( ( sel ) => /Not created yet/.test( ( document.querySelector( sel ) || {} ).textContent || '' ), `${ card } [data-wcpdf-doc="invoice"]`, { timeout: 15000 } );
		t.check( 'Delete removed the invoice from the order', invoice() === null );
		t.check( 'the packing slip shows its download and no data actions', ( await page.$$( `${ card } [data-wcpdf-doc="packing-slip"] [data-wcpdf-menu]` ) ).length === 0 );

		// A trashed order: no Documents card.
		wpPhp( `wc_get_order( ${ oid } )->delete( false ); echo 'ok';` );
		await page.goto( BASE + '/minn-admin/orders/' + oid, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-order-page .minn-order-customer', { timeout: 20000 } );
		t.check( 'a trashed order has no Documents card', ( await page.$$( card ) ).length === 0 );

		// Gates: an Author is refused, a signed-out caller asked to sign in.
		const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const authorStatus = await author.page.evaluate( async ( id ) => {
			const r = await fetch( `${ window.MINN.restUrl }minn-admin/v1/wcpdf/orders/${ id }/documents`, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return r.status;
		}, oid );
		t.check( 'an Author is refused (403)', authorStatus === 403, String( authorStatus ) );
		await author.ctx.close();
		const anon = await browser.newContext( { ignoreHTTPSErrors: true } );
		const res = await anon.request.post( `${ BASE }/wp-json/minn-admin/v1/wcpdf/orders/${ oid }/documents/invoice/create` );
		t.check( 'a signed-out caller gets 401', res.status() === 401, String( res.status() ) );
		await anon.close();
		t.check( 'no invoice was made by the refused callers', invoice() === null );
	} catch ( e ) {
		t.check( 'suite ran to the end', false, e.message );
	} finally {
		wpPhp( `$z = json_decode( ${ JSON.stringify( zoneBefore ) }, true ); update_option( 'timezone_string', $z[0] ); update_option( 'gmt_offset', $z[1] ); update_option( 'wpo_wcpdf_settings_debug', json_decode( ${ JSON.stringify( debugBefore ) }, true ) ); $o = wc_get_order( ${ oid || 0 } ); if ( ${ oid || 0 } > 1 && $o ) { $o->delete( true ); } echo 'ok';` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
