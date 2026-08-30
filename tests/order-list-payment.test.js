/**
 * Orders list Payment column: WooCommerce's payment_method_title on each
 * row, between Items and Total. Empty methods stay a quiet dash. Phone
 * widths hide the column with the other secondary cells.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-list-payment' );

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

	const suffix = Date.now().toString( 36 );
	const title = 'Check payments ' + suffix;
	let paidId = null;
	let bareId = null;

	try {
		const paid = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'processing',
				payment_method: 'cheque',
				payment_method_title: title,
				billing: { first_name: 'Pay', last_name: 'Col', email: `paycol-${ suffix }@example.com` },
			} ),
		} );
		paidId = paid.body && paid.body.id;
		t.check( 'created order with a method title', paid.status === 201 && !! paidId, String( paid.status ) );

		const bare = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'pending',
				billing: { first_name: 'Bare', last_name: 'Col', email: `barecol-${ suffix }@example.com` },
			} ),
		} );
		bareId = bare.body && bare.body.id;
		t.check( 'created order without a method', bare.status === 201 && !! bareId, String( bare.status ) );

		let listedFields = '';
		page.on( 'request', ( r ) => {
			const u = r.url();
			if ( /\/wc\/v3\/orders\?/.test( u ) && /_fields=/.test( u ) ) listedFields = decodeURIComponent( u );
		} );

		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-order-cols', { timeout: 20000 } );
		if ( paidId ) {
			await page.waitForSelector( `.minn-table-row[data-order="${ paidId }"]`, { timeout: 20000 } );
		}

		const head = await page.evaluate( () => {
			const cells = [ ...document.querySelectorAll( '.minn-table-head.minn-order-cols > div' ) ]
				.map( ( el ) => el.textContent.trim() );
			return { cells, paymentAt: cells.indexOf( 'Payment' ), totalAt: cells.indexOf( 'Total' ) };
		} );
		t.check( 'list head includes Payment between Items and Total',
			head.paymentAt === 4 && head.totalAt === 5, JSON.stringify( head ) );

		t.check( 'list request asks WooCommerce for payment_method_title',
			/_fields=[^&]*payment_method_title/.test( listedFields ), listedFields.slice( 0, 220 ) );

		const paidRow = await page.evaluate( ( id ) => {
			const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` );
			if ( ! row ) return { found: false };
			const cells = [ ...row.children ].map( ( el ) => el.textContent.trim() );
			return { found: true, payment: cells[ 4 ] || '', total: cells[ 5 ] || '' };
		}, paidId );
		t.check( 'paid row shows the method title in the Payment cell',
			paidRow.found && paidRow.payment === title, JSON.stringify( paidRow ) );

		const bareRow = await page.evaluate( ( id ) => {
			const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` );
			if ( ! row ) return { found: false };
			const cells = [ ...row.children ].map( ( el ) => el.textContent.trim() );
			return { found: true, payment: cells[ 4 ] || '' };
		}, bareId );
		t.check( 'order with no method shows a dash',
			bareRow.found && bareRow.payment === '—', JSON.stringify( bareRow ) );

		await page.setViewportSize( { width: 390, height: 800 } );
		await page.waitForTimeout( 250 );
		const phone = await page.evaluate( ( id ) => {
			const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` );
			if ( ! row ) return { found: false };
			const vis = [ ...row.children ].map( ( el ) => getComputedStyle( el ).display !== 'none' );
			return {
				found: true,
				vis,
				paymentHidden: vis[ 4 ] === false,
				totalShown: vis[ 5 ] === true,
				noHScroll: document.documentElement.scrollWidth <= window.innerWidth + 1,
			};
		}, paidId );
		t.check( 'phone hides Payment and keeps Total',
			phone.found && phone.paymentHidden && phone.totalShown && phone.noHScroll, JSON.stringify( phone ) );
	} finally {
		if ( paidId ) await api( `wc/v3/orders/${ paidId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( bareId ) await api( `wc/v3/orders/${ bareId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
