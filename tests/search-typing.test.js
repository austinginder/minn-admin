/**
 * List search survives its own reloads — typing while results load must
 * never be mangled. The failure this pins: a reload's render rebuilt the
 * filter bar from the last COMMITTED search (the debounce hadn't fired), and
 * maybeFocusSearch's swap-repair refocused the fresh box at caret 0, so the
 * rest of the phrase typed at the START of a truncated value. The repair now
 * adopts the detached node's live text, seats the caret at the end, and
 * fires input so the results converge. Responses are artificially delayed so
 * the reload/typing race is deterministic (the anchor.host latency that
 * surfaced it).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'search-typing' );
	const { browser, page, errors } = await launch();
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce orders available', false, 'skip: no wc' );
		await t.done( browser, errors );
		return;
	}

	// Delay every orders list request so renders land mid-typing, and record
	// each request's search param so convergence is observable.
	const searches = [];
	await page.route( '**/wc/v3/orders*', async ( route ) => {
		const u = new URL( route.request().url() );
		if ( u.searchParams.has( 'search' ) || u.searchParams.has( 'per_page' ) ) {
			searches.push( u.searchParams.get( 'search' ) || '' );
		}
		await new Promise( ( r ) => setTimeout( r, 1200 ) );
		route.continue();
	} );

	try {
		await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-order-search', { timeout: 30000 } );
		await page.click( '#minn-order-search' );

		// Human-paced digits across at least three reload cycles.
		const phrase = '123456789012';
		for ( const ch of phrase ) {
			await page.keyboard.type( ch, { delay: 0 } );
			await page.waitForTimeout( 250 );
		}
		const st = await page.evaluate( () => {
			const s = document.getElementById( 'minn-order-search' );
			return {
				value: s ? s.value : 'GONE',
				focused: document.activeElement === s,
				caret: s && document.activeElement === s ? s.selectionStart : -1,
			};
		} );
		t.check( 'every keystroke survives the reloads', st.value === phrase, JSON.stringify( st ) );
		t.check( 'the box stays focused with the caret at the end',
			st.focused && st.caret === phrase.length, JSON.stringify( st ) );

		// The results converge on the full phrase (the adopted value fires
		// input, so the view's own debounce commits it).
		let converged = false;
		for ( let i = 0; i < 25 && ! converged; i++ ) {
			await page.waitForTimeout( 400 );
			converged = searches.includes( phrase );
		}
		t.check( 'the list requests the full phrase', converged, JSON.stringify( searches.slice( -5 ) ) );

		// A URL-restored query seats the caret at the END on arrival, so
		// typing extends rather than prepends.
		await page.goto( BASE + '/minn-admin/orders?q=121459', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-order-search', { timeout: 30000 } );
		const seeded = await page.waitForFunction( () => {
			const s = document.getElementById( 'minn-order-search' );
			return s && document.activeElement === s && s.value === '121459'
				? { caret: s.selectionStart } : false;
		}, null, { timeout: 15000 } ).then( ( h ) => h.jsonValue() ).catch( () => null );
		t.check( 'URL-seeded search focuses with caret at the end',
			!! seeded && seeded.caret === 6, JSON.stringify( seeded ) );
	} finally {
		await page.unroute( '**/wc/v3/orders*' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
