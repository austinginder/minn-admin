/**
 * A write the server refused is not reported as done.
 *
 * WooCommerce's batch routes answer 200 and put each refusal inside its
 * item ({ id, error }); the product bulk edit and the tax-rate reorder read
 * only the status and said "Updated 2 products" and "Rate order saved". A
 * reply to a pending comment swallowed a refused approval and said "Reply
 * posted, comment approved" while the comment stayed pending.
 *
 * Each refusal is a real one: a product and a tax rate deleted after the
 * page loaded (as another tab would), and an approval answered with the
 * write fence's 403.
 *
 * Fixture: two products, two tax rates and a pending comment with its
 * reply, created and removed over REST; disable-comments, when active, is
 * switched off for the comment case and back on after.
 */
const { BASE, launch, login, reporter, comboValue } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'refused-writes' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
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
	const toastAfter = async ( act ) => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		await act();
		const el = await page.waitForSelector( '.minn-toast', { timeout: 20000 } ).catch( () => null );
		return el ? ( ( await el.textContent() ) || '' ).trim() : '';
	};

	const tag = 'Refused ' + Date.now();
	const made = { products: [], rates: [], comments: [], post: 0, commentsOff: false };
	try {
		const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
		if ( ! hasWc ) {
			console.log( 'SKIP  WooCommerce unavailable: product and tax cases' );
		} else {
			/* 1. Product bulk edit with one product deleted meanwhile. */
			for ( const n of [ 'A', 'B' ] ) {
				const r = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: `${ tag } ${ n }`, type: 'simple', regular_price: '5.00', status: 'publish' } ) } );
				if ( r.body && r.body.id ) made.products.push( r.body.id );
			}
			t.check( 'two fixture products', 2 === made.products.length );
			await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-order-search', { timeout: 20000 } );
			await page.fill( '#minn-order-search', tag );
			await page.waitForFunction( ( ids ) => ids.every( ( id ) => document.querySelector( `[data-psel="${ id }"]` ) ), made.products, { timeout: 15000 } );
			for ( const id of made.products ) await page.click( `[data-psel="${ id }"]` );
			await page.waitForSelector( '#minn-prod-bulk-apply', { timeout: 5000 } );
			// Each selection repaints the bulk bar; pick once it has settled.
			await page.waitForFunction( () => /2 selected/.test( ( document.querySelector( '.minn-bulk-count' ) || {} ).textContent || '' ), null, { timeout: 5000 } ).catch( () => null );
			const statusInput = '[data-prod-bulk-status] .minn-ac-input';
			const draftItem = '[data-prod-bulk-status] .minn-ac-item[data-acv="draft"]';
			for ( let i = 0; i < 3 && 'draft' !== await comboValue( page, statusInput ); i++ ) {
				await page.click( statusInput );
				await page.waitForSelector( draftItem, { timeout: 5000 } ).catch( () => null );
				await page.click( draftItem ).catch( () => null );
				await page.waitForTimeout( 200 );
			}
			const picked = await comboValue( page, statusInput );
			t.check( 'the bulk status reads Draft', 'draft' === picked, String( picked ) );
			const gone = made.products[ 1 ];
			await api( `wc/v3/products/${ gone }?force=true`, { method: 'DELETE' } );
			const said = await toastAfter( () => page.click( '#minn-prod-bulk-apply' ) );
			const kept = await api( `wc/v3/products/${ made.products[ 0 ] }?_fields=status` );
			t.check( 'a bulk edit with a refused product says so', /1 of 2/.test( said ), said );
			t.check( 'control: the product it could change is changed', 'draft' === ( kept.body || {} ).status, JSON.stringify( kept.body ) );
			made.products = [ made.products[ 0 ] ];

			/* 2. Tax rate reorder with one rate deleted meanwhile. */
			for ( const n of [ 'A', 'B' ] ) {
				const r = await api( 'wc/v3/taxes', { method: 'POST', body: JSON.stringify( { country: 'US', state: 'PA', rate: '1.0000', name: `${ tag } ${ n }`, class: 'standard', shipping: true } ) } );
				if ( r.body && r.body.id ) made.rates.push( r.body.id );
			}
			t.check( 'two fixture tax rates', 2 === made.rates.length );
			await page.goto( `${ BASE }/minn-admin/store-settings`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '[data-storesec="tax:standard"]', { timeout: 20000 } );
			await page.click( '[data-storesec="tax:standard"]' );
			await page.waitForFunction( ( ids ) => ids.every( ( id ) => document.querySelector( `.minn-store-tax[data-rate="${ id }"]` ) ), made.rates, { timeout: 15000 } );
			const [ ra, rb ] = made.rates;
			await api( `wc/v3/taxes/${ rb }?force=true`, { method: 'DELETE' } );
			const box = await ( await page.$( `.minn-store-tax[data-rate="${ rb }"]` ) ).boundingBox();
			const taxSaid = await toastAfter( () => page.dragAndDrop( `.minn-store-tax[data-rate="${ ra }"]`, `.minn-store-tax[data-rate="${ rb }"]`, { targetPosition: { x: Math.round( box.width / 2 ), y: Math.round( box.height - 3 ) } } ) );
			t.check( 'a reorder with a refused rate says part of it was not saved', /not saved/.test( taxSaid ), taxSaid );
			made.rates = [ ra ];
		}

		/* 3. A reply whose approval is refused. Comments have to be on: the
		   dev site keeps disable-comments active, put back afterwards. */
		const dc = await api( 'wp/v2/plugins/disable-comments/disable-comments' );
		made.commentsOff = 200 === dc.status && dc.body && 'active' === dc.body.status;
		if ( made.commentsOff ) await api( 'wp/v2/plugins/disable-comments/disable-comments', { method: 'PUT', body: JSON.stringify( { status: 'inactive' } ) } );
		const post = await api( 'wp/v2/posts', { method: 'POST', body: JSON.stringify( { title: tag, status: 'publish', comment_status: 'open' } ) } );
		made.post = post.body && post.body.id;
		const c = await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( { post: made.post, content: `${ tag } pending comment`, author_name: 'Refused Tester', author_email: 'refused@example.com', status: 'hold' } ) } );
		const cid = c.body && c.body.id;
		if ( cid ) made.comments.push( cid );
		t.check( 'a pending fixture comment', !! cid && 'hold' === c.body.status, JSON.stringify( c.body && c.body.status ) );
		await page.goto( BASE + '/minn-admin/comments', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-creply="${ cid }"]`, { timeout: 20000 } );
		await page.click( `[data-creply="${ cid }"]` );
		await page.waitForSelector( '#minn-reply-text', { timeout: 10000 } );
		await page.fill( '#minn-reply-text', `${ tag } reply` );
		const approveUrl = new RegExp( 'wp/v2/comments/' + cid + '(\\?|$)' );
		const refuse = ( route ) => ( 'POST' === route.request().method()
			? route.fulfill( { status: 403, contentType: 'application/json', body: JSON.stringify( { code: 'minn_write_fenced', message: 'Not allowed.', data: { status: 403 } } ) } )
			: route.continue() );
		await page.route( approveUrl, refuse );
		const replySaid = await toastAfter( () => page.click( '#minn-reply-send' ) );
		await page.unroute( approveUrl, refuse );
		t.check( 'a reply whose approval is refused says the comment was not approved', /not approved/.test( replySaid ), replySaid );
		const replies = await api( `wp/v2/comments?parent=${ cid }&status=any&context=edit&_fields=id` );
		( Array.isArray( replies.body ) ? replies.body : [] ).forEach( ( r ) => made.comments.push( r.id ) );
		t.check( 'control: the reply itself is posted', Array.isArray( replies.body ) && replies.body.length > 0, JSON.stringify( replies.body ) );
		const still = await api( `wp/v2/comments/${ cid }?context=edit&_fields=status` );
		t.check( 'and the comment is still pending', 'hold' === ( still.body || {} ).status, JSON.stringify( still.body ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const id of made.comments ) await api( `wp/v2/comments/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( made.commentsOff ) await api( 'wp/v2/plugins/disable-comments/disable-comments', { method: 'PUT', body: JSON.stringify( { status: 'active' } ) } ).catch( () => {} );
		if ( made.post ) await api( `wp/v2/posts/${ made.post }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		for ( const id of made.products ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		for ( const id of made.rates ) await api( `wc/v3/taxes/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
