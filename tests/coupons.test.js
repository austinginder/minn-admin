/**
 * Coupons surface — list/search/create/edit via wc/v3, fenced from Content.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'coupons' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.coupons ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce coupons available', false, 'caps.coupons missing — skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce coupons available', true, '' );

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
	const code = 'MINN' + suffix.toUpperCase();
	const created = await api( 'wc/v3/coupons', {
		method: 'POST',
		body: JSON.stringify( {
			code,
			discount_type: 'percent',
			amount: '15',
			status: 'publish',
			description: 'Coupons suite',
			usage_limit: 10,
		} ),
	} );
	t.check( 'created fixture coupon', created.status === 201 || created.status === 200, JSON.stringify( created.status ) );
	const couponId = created.body && created.body.id;
	t.check( 'have coupon id', !! couponId, String( couponId ) );

	// Content fence: shop_coupon not a Content type tab.
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-view', { timeout: 20000 } );
	await page.waitForTimeout( 800 );
	const fenced = await page.evaluate( () => {
		const html = document.body.innerHTML;
		return /data-filter=["']shop_coupon["']/.test( html )
			|| Array.from( document.querySelectorAll( '.minn-tab' ) )
				.some( ( el ) => /coupon/i.test( el.textContent || '' ) );
	} );
	t.check( 'Content has no Coupons type tab', ! fenced, '' );

	await page.goto( BASE + '/minn-admin/coupons', { waitUntil: 'domcontentloaded' } );
	// The filter bar paints while the list is still loading, so the search box
	// existing does not mean the rows have landed: wait for the table.
	await page.waitForSelector( '#minn-order-search, .minn-empty, .minn-loading', { timeout: 20000 } );
	await page.waitForSelector( '.minn-table-row[data-coupon], .minn-empty', { timeout: 20000 } ).catch( () => null );

	const hasSearch = await page.$( '#minn-order-search' );
	const hasAdd = await page.$( '#minn-coupon-add' );
	t.check( 'coupons toolbar has search', !! hasSearch, '' );
	t.check( 'coupons has Add coupon button', !! hasAdd, '' );

	if ( hasSearch && couponId ) {
		await page.fill( '#minn-order-search', code );
		await page.waitForTimeout( 700 );
		await page.waitForFunction( ( id ) => {
			const rows = document.querySelectorAll( '.minn-table-row[data-coupon]' );
			return Array.from( rows ).some( ( r ) => r.dataset.coupon === String( id ) );
		}, couponId, { timeout: 12000 } ).catch( () => null );
		const found = await page.evaluate( ( id ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row[data-coupon]' ) );
			return { n: rows.length, hit: rows.some( ( r ) => r.dataset.coupon === String( id ) ) };
		}, couponId );
		t.check( 'search by code finds coupon', found.hit, JSON.stringify( found ) );
	}

	const clicked = await page.evaluate( ( id ) => {
		const row = document.querySelector( `.minn-table-row[data-coupon="${ id }"]` )
			|| document.querySelector( '.minn-table-row[data-coupon]' );
		if ( ! row ) return false;
		row.click();
		return true;
	}, couponId );
	t.check( 'clicked coupon row', clicked, '' );

	if ( clicked ) {
		await page.waitForSelector( '#minn-c-code', { timeout: 20000 } ).catch( () => null );

		const ui = await page.evaluate( () => ( {
			url: location.pathname,
			hasCode: !! document.querySelector( '#minn-c-code' ),
			hasAmount: !! document.querySelector( '#minn-c-amount' ),
			hasSave: !! document.querySelector( '#minn-coupon-save' ),
			hasWc: /Edit in WooCommerce/.test( document.querySelector( '#minn-view' )?.textContent || '' ),
		} ) );
		t.check( 'a coupon row opens the coupon page with its fields, and no wp-admin link',
			/\/coupons\/\d+$/.test( ui.url ) && ui.hasCode && ui.hasAmount && ui.hasSave && ! ui.hasWc, JSON.stringify( ui ) );

		if ( ui.hasSave ) {
			await page.fill( '#minn-c-amount', '20' );
			await page.click( '#minn-coupon-save' );
			// Never flat-wait a save: the handler's async continuation (PUT +
			// re-GET, then a list cache null + cold refetch) can land seconds
			// later under load and race whatever the suite does next.
			await page.waitForFunction(
				() => /Coupon updated/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
				null, { timeout: 20000 } );
			const verify = await api( `wc/v3/coupons/${ couponId }?_fields=id,amount,code` );
			t.check( 'coupon amount saved',
				verify.status === 200 && verify.body && String( parseFloat( verify.body.amount ) ) === '20',
				JSON.stringify( verify.body ) );
		}

		// Layout: the option switches are full rows that do not overlap, and
		// the page's own verbs sit in its header.
		const layout = await page.evaluate( () => {
			const r1 = document.querySelector( '#minn-c-individual' )?.closest( '.minn-toggle-row' )?.getBoundingClientRect();
			const r2 = document.querySelector( '#minn-c-nosale' )?.closest( '.minn-toggle-row' )?.getBoundingClientRect();
			const head = document.querySelector( '.minn-order-head-actions' );
			return {
				w: r1 ? Math.round( r1.width ) : 0,
				overlap: r1 && r2 ? r2.top < r1.bottom - 2 : true,
				headVerbs: !! ( head && head.querySelector( '#minn-co-copy' ) && head.querySelector( '#minn-co-delete' ) ),
			};
		} );
		t.check( 'option switches render as full rows without overlap', layout.w > 100 && ! layout.overlap, JSON.stringify( layout ) );
		t.check( 'Copy code and Delete sit in the page header', layout.headVerbs, JSON.stringify( layout ) );

		// Right-click menu: verbs present, status flips through it, and its
		// Delete (behind the app confirm) really removes the coupon.
		await page.click( '#minn-co-back' );
		// The save nulled the list cache — wait for the cold refetch to put
		// the row back before right-clicking it.
		await page.waitForSelector( `.minn-table-row[data-coupon="${ couponId }"]`, { timeout: 15000 } );
		// A status flip re-renders the list, so a dispatch can land on a row
		// mid-replacement — retry the whole open against a fresh node.
		const openCtx = async () => {
			for ( let i = 0; i < 5; i++ ) {
				await page.evaluate( ( id ) => {
					const row = document.querySelector( `.minn-table-row[data-coupon="${ id }"]` );
					if ( ! row ) return;
					const r = row.getBoundingClientRect();
					row.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, cancelable: true, clientX: r.left + 60, clientY: r.top + 12 } ) );
				}, couponId );
				const ok = await page.waitForSelector( '.minn-ctx-menu', { timeout: 2500 } ).then( () => true ).catch( () => false );
				if ( ok ) return;
				await page.waitForTimeout( 600 );
			}
			throw new Error( 'context menu never opened' );
		};
		await openCtx();
		const menuText = await page.evaluate( () => document.querySelector( '.minn-ctx-menu' ).textContent );
		t.check( 'coupon context menu offers the verbs',
			/Open coupon/.test( menuText ) && /Copy code/.test( menuText )
			&& /Move to draft/.test( menuText ) && /Delete/.test( menuText )
			&& ! /Edit in WooCommerce/.test( menuText ), menuText );
		// Evaluate-click menu items after a right-click opens them (the
		// mousedown+contextmenu pair can re-open and detach the first node).
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-ctx-menu button' ) )
				.find( ( b ) => /Move to draft/.test( b.textContent ) ).click();
		} );
		let flipped = null;
		for ( let i = 0; i < 12 && ! flipped; i++ ) {
			await page.waitForTimeout( 500 );
			const v = await api( `wc/v3/coupons/${ couponId }?_fields=id,status` );
			if ( v.body && v.body.status === 'draft' ) flipped = v.body;
		}
		t.check( 'menu status flip moved the coupon to draft', !! flipped, JSON.stringify( flipped ) );

		await openCtx();
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-ctx-menu button' ) )
				.find( ( b ) => /Delete/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( 'button' ) )
			.some( ( b ) => /Delete coupon/.test( b.textContent ) ), null, { timeout: 8000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( 'button' ) )
				.find( ( b ) => /Delete coupon/.test( b.textContent ) ).click();
		} );
		let goneStatus = 0;
		for ( let i = 0; i < 12 && goneStatus !== 404; i++ ) {
			await page.waitForTimeout( 500 );
			goneStatus = ( await api( `wc/v3/coupons/${ couponId }?_fields=id` ) ).status;
		}
		t.check( 'menu delete removes the coupon for good', goneStatus === 404, String( goneStatus ) );
	}

	// A type and status outside the modal's lists survive an unrelated edit.
	// The selects used to fall back to their first option, so fixing a
	// description turned a Subscriptions sign-up fee coupon into a plain
	// percentage one and published a pending coupon.
	const odd = await api( 'wc/v3/coupons', {
		method: 'POST',
		body: JSON.stringify( { code: 'ODD' + suffix.toUpperCase(), discount_type: 'sign_up_fee_percent', amount: '100', status: 'pending', description: 'Odd coupon' } ),
	} );
	const oddId = odd.body && odd.body.id;
	if ( ! oddId ) {
		t.check( 'a Subscriptions sign-up fee coupon can be created (skip without Subscriptions)', true, JSON.stringify( odd.body ).slice( 0, 160 ) );
	} else {
		await page.goto( BASE + '/minn-admin/coupons', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-order-search', { timeout: 20000 } );
		await page.fill( '#minn-order-search', 'ODD' + suffix.toUpperCase() );
		await page.waitForSelector( `.minn-table-row[data-coupon="${ oddId }"]`, { timeout: 15000 } );
		await page.click( `.minn-table-row[data-coupon="${ oddId }"]` );
		await page.waitForSelector( '#minn-c-desc', { timeout: 15000 } );
		await page.fill( '#minn-c-desc', 'Odd coupon, reworded' );
		await page.click( '#minn-coupon-save' );
		await page.waitForFunction(
			() => /Coupon updated/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
			null, { timeout: 20000 } ).catch( () => null );
		const oddAfter = await api( `wc/v3/coupons/${ oddId }?_fields=discount_type,status,description` );
		t.check( 'an edit saves (control)', oddAfter.body && 'Odd coupon, reworded' === oddAfter.body.description, JSON.stringify( oddAfter.body ) );
		t.check( 'a description edit keeps the sign-up fee type and the pending status', oddAfter.body && 'sign_up_fee_percent' === oddAfter.body.discount_type && 'pending' === oddAfter.body.status, JSON.stringify( oddAfter.body ) );
		await api( `wc/v3/coupons/${ oddId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	// Create via UI.
	if ( hasAdd ) {
		await page.goto( BASE + '/minn-admin/coupons', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-coupon-add', { timeout: 20000 } );
		await page.click( '#minn-coupon-add' );
		await page.waitForSelector( '#minn-c-code', { timeout: 20000 } );
		const newCode = 'NEW' + suffix.toUpperCase();
		await page.fill( '#minn-c-code', newCode );
		await page.fill( '#minn-c-amount', '5' );
		await page.click( '#minn-coupon-save' );
		// A flat wait races the POST under load; wait for the created toast
		// before verifying against the API.
		await page.waitForFunction(
			() => /Coupon created/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
			null, { timeout: 15000 } ).catch( () => null );
		const listed = await api( `wc/v3/coupons?search=${ encodeURIComponent( newCode ) }&_fields=id,code` );
		const hit = ( listed.body || [] ).find( ( c ) => ( c.code || '' ).toLowerCase() === newCode.toLowerCase() );
		t.check( 'Add coupon creates via UI', !! hit, JSON.stringify( listed.body ) );
		await page.waitForFunction( () => /\/coupons\/\d+$/.test( location.pathname ), null, { timeout: 15000 } ).catch( () => null );
		t.check( 'and lands on the new coupon\'s page', !! hit && page.url().endsWith( '/coupons/' + hit.id ), page.url() );
		if ( hit ) await api( `wc/v3/coupons/${ hit.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	if ( couponId ) await api( `wc/v3/coupons/${ couponId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
