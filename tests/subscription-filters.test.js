/**
 * The subscriptions list wears the same filter bar as orders, driven by the
 * same machine with its own vocabulary: WooCommerce Subscriptions has its own
 * statuses (active, pending-cancel, expired, switched) and its REST collection
 * accepts the same native parameters (status, customer, product, after).
 *
 * Like the orders suite, every check asserts the query string as well as the
 * rows: filtering happens on the server or it is not filtering.
 *
 * SKIPs cleanly when WooCommerce Subscriptions is not installed.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'subscription-filters' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	const sent = [];
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( /\/wc\/v3\/subscriptions\?/.test( u ) ) sent.push( decodeURIComponent( u ) );
	} );
	const lastQuery = () => ( sent.length ? sent[ sent.length - 1 ] : '' );

	await login( page );

	const hasWcs = await page.evaluate( () => !!( window.MINN && window.MINN.wcs ) );
	if ( ! hasWcs ) {
		t.check( 'WooCommerce Subscriptions available', false, 'skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce Subscriptions available', true, '' );

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
	const email = `minn-subfilter-${ suffix }@example.com`;
	let pid = null, custId = null;
	const made = {};

	const visibleIds = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row[data-sub]' ) ).map( ( r ) => parseInt( r.dataset.sub, 10 ) ) );
	const chipLabels = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '[data-ofchip]' ) ).map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) );
	const waitRows = async ( has, hasNot ) => {
		try {
			await page.waitForFunction( ( a ) => {
				if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
				const ids = Array.from( document.querySelectorAll( '.minn-table-row[data-sub]' ) ).map( ( r ) => parseInt( r.dataset.sub, 10 ) );
				return a.has.every( ( id ) => ids.includes( id ) ) && a.hasNot.every( ( id ) => ! ids.includes( id ) );
			}, { has, hasNot }, { timeout: 15000 } );
			return true;
		} catch ( e ) {
			return false;
		}
	};
	const pickPreset = async ( slug ) => {
		await page.click( '#minn-order-preset' );
		await page.waitForSelector( `.minn-of-pop [data-opreset="${ slug }"]`, { timeout: 8000 } );
		await page.click( `.minn-of-pop [data-opreset="${ slug }"]` );
	};

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Sub Filter Widget ' + suffix, type: 'simple', regular_price: '9.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		const cust = await api( 'wc/v3/customers', {
			method: 'POST',
			body: JSON.stringify( { email, first_name: 'Subby', last_name: 'Tester ' + suffix, username: 'subby-' + suffix, password: 'sub-pass-' + suffix } ),
		} );
		custId = cust.body && cust.body.id;

		const billing = { first_name: 'Subby', last_name: 'Tester', email, country: 'US' };
		const mkSub = async ( key, status ) => {
			const r = await api( 'wc/v3/subscriptions', {
				method: 'POST',
				body: JSON.stringify( {
					status,
					customer_id: custId,
					billing,
					billing_period: 'month',
					billing_interval: 1,
					line_items: [ { product_id: pid, quantity: 1 } ],
				} ),
			} );
			made[ key ] = r.body && r.body.id;
			return r;
		};
		const a = await mkSub( 'active', 'active' );
		await mkSub( 'onhold', 'on-hold' );
		if ( ! made.active || ! made.onhold ) {
			// Subscriptions cannot always be created through REST on a given
			// build; say so plainly rather than fail on a fixture.
			t.check( 'fixtures: two subscriptions created', false, JSON.stringify( a.body ).slice( 0, 220 ) );
			await t.done( browser, errors );
			return;
		}
		t.check( 'fixtures: two subscriptions created', true, JSON.stringify( made ) );

		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page.goto( `${ BASE }/minn-admin/subscriptions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-sub]', { timeout: 25000 } );

		// ---- Start date: WooCommerce's own list carries it, so this one does ----
		const startCol = await page.evaluate( ( id ) => {
			const heads = Array.from( document.querySelectorAll( '.minn-table-head.minn-sub-cols > div' ) )
				.map( ( h ) => h.textContent.trim() );
			const row = document.querySelector( `.minn-table-row[data-sub="${ id }"]` );
			const cell = row && row.querySelector( '[data-substart]' );
			return {
				heads,
				text: cell ? cell.textContent.trim() : null,
				title: cell ? cell.getAttribute( 'title' ) : null,
				offset: window.MINN.gmtOffset,
			};
		}, made.active );
		t.check( 'the list has a Start date column, ahead of Next payment',
			startCol.heads.indexOf( 'Start date' ) > 0 &&
			startCol.heads.indexOf( 'Start date' ) < startCol.heads.indexOf( 'Next payment' ),
			JSON.stringify( startCol.heads ) );
		// start_date_gmt is GMT and must be read as GMT. Parsed as site-local on
		// a site at UTC-5 this same fixture renders "in 5h" — a subscription that
		// has not started yet. The check only discriminates when gmtOffset != 0.
		t.check( 'the start date is read as GMT, not site-local',
			startCol.text === 'just now',
			JSON.stringify( { text: startCol.text, title: startCol.title, gmtOffset: startCol.offset } ) );

		// ---- The bar is here, with the SUBSCRIPTION vocabulary ----
		const presets = await page.evaluate( async () => {
			document.querySelector( '#minn-order-preset' ).click();
			await new Promise( ( r ) => setTimeout( r, 300 ) );
			const list = Array.from( document.querySelectorAll( '.minn-of-pop [data-opreset]' ) ).map( ( b ) => b.dataset.opreset );
			document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) );
			return list;
		} );
		t.check( 'the dropdown offers subscription statuses, not order ones',
			presets.includes( 'active' ) && presets.includes( 'pending-cancel' ) && ! presets.includes( 'refunded' ),
			JSON.stringify( presets ) );

		// ---- Status ----
		await pickPreset( 'active' );
		t.check( 'status filters the subscriptions list',
			await waitRows( [ made.active ], [ made.onhold ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'status travelled to the subscriptions endpoint',
			/status(\[\]|)=active/.test( lastQuery() ), lastQuery().slice( -90 ) );

		// ---- Customer, through the shared picker ----
		await page.click( '#minn-order-addfilter' );
		await page.waitForSelector( '[data-offilter="customer"]', { timeout: 8000 } );
		await page.click( '[data-offilter="customer"]' );
		await page.fill( '.minn-of-pop .minn-ac-input', 'Subby' );
		await page.waitForSelector( '.minn-of-pop .minn-ac-item', { timeout: 10000 } );
		await page.click( '.minn-of-pop .minn-ac-item' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-view .minn-loading' ), null, { timeout: 15000 } );
		t.check( 'customer id travelled to the subscriptions endpoint',
			new RegExp( '[?&]customer=' + custId + '(&|$)' ).test( lastQuery() ), lastQuery().slice( -90 ) );

		// ---- The URL carries them, and a reload restores them ----
		const url = new URL( await page.evaluate( () => location.href ) );
		t.check( 'subscription filters reach the URL',
			url.searchParams.get( 'status' ) === 'active' && url.searchParams.get( 'customer' ) === String( custId ),
			url.search );

		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-sub]', { timeout: 25000 } );
		t.check( 'a reload restores the filtered subscriptions',
			await waitRows( [ made.active ], [ made.onhold ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'a reload restores the chips',
			( await chipLabels() ).some( ( c ) => /Customer/i.test( c ) ), JSON.stringify( await chipLabels() ) );

		// ---- Orders keep their own filters: two lists, one machine ----
		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-order-preset', { timeout: 25000 } );
		await page.waitForFunction( () => ! document.querySelector( '#minn-view .minn-loading' ), null, { timeout: 20000 } );
		const orderChips = await chipLabels();
		t.check( 'the orders list does not inherit the subscription filters',
			orderChips.length === 0, JSON.stringify( orderChips ) );
	} finally {
		for ( const id of Object.values( made ) ) {
			if ( id ) await api( `wc/v3/subscriptions/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( custId ) await api( `wc/v3/customers/${ custId }?force=true&reassign=0`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
