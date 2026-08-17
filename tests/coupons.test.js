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
	await page.waitForSelector( '#minn-coupon-search, .minn-empty, .minn-loading', { timeout: 20000 } );
	await page.waitForFunction( () => !! document.querySelector( '#minn-coupon-search' ), null, { timeout: 15000 } ).catch( () => null );

	const hasSearch = await page.$( '#minn-coupon-search' );
	const hasAdd = await page.$( '#minn-coupon-add' );
	t.check( 'coupons toolbar has search', !! hasSearch, '' );
	t.check( 'coupons has Add coupon button', !! hasAdd, '' );

	if ( hasSearch && couponId ) {
		await page.fill( '#minn-coupon-search', code );
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
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ! m.textContent.includes( 'Loading coupon' );
		}, null, { timeout: 15000 } ).catch( () => null );

		const ui = await page.evaluate( () => ( {
			hasCode: !! document.querySelector( '#minn-c-code' ),
			hasAmount: !! document.querySelector( '#minn-c-amount' ),
			hasSave: !! document.querySelector( '#minn-coupon-save' ),
			hasWc: /Edit in WooCommerce/.test( document.querySelector( '.minn-modal' )?.textContent || '' ),
		} ) );
		t.check( 'coupon modal has edit fields', ui.hasCode && ui.hasAmount && ui.hasSave, JSON.stringify( ui ) );

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

		// Layout: the option checkboxes are real rows, not the term-picker's
		// 15px tick glyph (a class collision squeezed both labels into each
		// other and the usage line), and Save/Delete share one row.
		const layout = await page.evaluate( () => {
			const indRow = document.querySelector( '#minn-c-individual' )?.closest( 'label' );
			const shipRow = document.querySelector( '#minn-c-ship' )?.closest( 'label' );
			const save = document.querySelector( '#minn-coupon-save' );
			const del = document.querySelector( '#minn-coupon-delete' );
			const r1 = indRow && indRow.getBoundingClientRect();
			const r2 = shipRow && shipRow.getBoundingClientRect();
			return {
				w: r1 ? Math.round( r1.width ) : 0,
				overlap: r1 && r2 ? r2.top < r1.bottom - 2 : true,
				sameRow: save && del
					? Math.abs( save.getBoundingClientRect().top - del.getBoundingClientRect().top ) < 4
					: false,
			};
		} );
		t.check( 'option checkboxes render as full rows without overlap', layout.w > 100 && ! layout.overlap, JSON.stringify( layout ) );
		t.check( 'Save and Delete share one row', layout.sameRow, JSON.stringify( layout ) );

		// Right-click menu: verbs present, status flips through it, and its
		// Delete (behind the app confirm) really removes the coupon.
		await page.keyboard.press( 'Escape' );
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
			&& /Edit in WooCommerce/.test( menuText ), menuText );
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

	// Create via UI.
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 200 );
	if ( hasAdd ) {
		await page.click( '#minn-coupon-add' );
		await page.waitForSelector( '#minn-c-code', { timeout: 5000 } );
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
		if ( hit ) await api( `wc/v3/coupons/${ hit.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	if ( couponId ) await api( `wc/v3/coupons/${ couponId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
