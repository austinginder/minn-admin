/**
 * The coupons list filter bar: the shared machine again, over the thinnest
 * vocabulary of the four lists. wc/v3/coupons is a plain post collection —
 * status, search, code and a created-date window are all it registers — so
 * this bar is a status dropdown, the search box, and Date as the only
 * dimension in the Add filter menu.
 *
 * The rule is the orders rule: filtering is SERVER side. Every check asserts
 * the rows AND the query Minn sent. The suite also pins the absence of a
 * discount-type filter, because that is a decision rather than an omission:
 * wc/v3/coupons has no such parameter, and narrowing one page of results
 * client-side would misreport the count and every page after the first.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'coupon-filters' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	const sent = [];
	page.on( 'request', ( r ) => {
		const u = decodeURIComponent( r.url() );
		if ( /\/wc\/v3\/coupons\?/.test( u ) ) sent.push( u );
	} );
	const lastQuery = () => ( sent.length ? sent[ sent.length - 1 ] : '' );
	const waitForQuery = async ( re, label ) => {
		const start = Date.now();
		while ( Date.now() - start < 15000 ) {
			if ( re.test( lastQuery() ) ) return true;
			await page.waitForTimeout( 200 );
		}
		t.check( 'query carried ' + label, false, lastQuery().slice( 0, 200 ) );
		return false;
	};

	await login( page );

	const hasCoupons = await page.evaluate( () => !! ( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.coupons ) );
	if ( ! hasCoupons ) {
		t.check( 'WooCommerce coupons available', false, 'skip' );
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
	const made = {};

	const visibleIds = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row[data-coupon]' ) ).map( ( r ) => parseInt( r.dataset.coupon, 10 ) ) );
	const chipLabels = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '[data-ofchip]' ) ).map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) );
	const settle = async () => {
		await page.waitForFunction( () =>
			! document.querySelector( '#minn-view .minn-loading, #minn-view .minn-busy' ),
		null, { timeout: 20000 } ).catch( () => {} );
		await page.waitForTimeout( 150 );
	};
	const waitRows = async ( has, hasNot ) => {
		try {
			await page.waitForFunction( ( a ) => {
				const ids = Array.from( document.querySelectorAll( '.minn-table-row[data-coupon]' ) ).map( ( r ) => parseInt( r.dataset.coupon, 10 ) );
				if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
				return a.has.every( ( id ) => ids.includes( id ) ) && a.hasNot.every( ( id ) => ! ids.includes( id ) );
			}, { has, hasNot }, { timeout: 15000 } );
			return true;
		} catch ( e ) {
			return false;
		}
	};
	const pickPreset = async ( slug ) => {
		await settle();
		await page.click( '#minn-order-preset' );
		await page.waitForSelector( `.minn-of-pop [data-opreset="${ slug }"]`, { timeout: 8000 } );
		await page.click( `.minn-of-pop [data-opreset="${ slug }"]` );
		await settle();
	};
	const presetLabel = () => page.evaluate( () => {
		const b = document.querySelector( '#minn-order-preset' );
		return b ? b.textContent.replace( /\s+/g, ' ' ).trim() : '';
	} );
	const openFilterMenu = async ( which ) => {
		await settle();
		await page.click( '#minn-order-addfilter' );
		await page.waitForSelector( `[data-offilter="${ which }"]`, { timeout: 8000 } );
		await page.click( `[data-offilter="${ which }"]` );
		await page.waitForSelector( '.minn-of-pop', { timeout: 8000 } );
	};
	const clearAll = async () => {
		await settle();
		await page.click( '#minn-order-clearfilters' ).catch( () => {} );
		await page.waitForFunction( () => ! document.querySelector( '[data-ofchip]' ), null, { timeout: 8000 } ).catch( () => {} );
		await settle();
	};

	try {
		const mk = async ( key, body ) => {
			const r = await api( 'wc/v3/coupons', { method: 'POST', body: JSON.stringify( body ) } );
			made[ key ] = r.body && r.body.id;
			return made[ key ];
		};
		await mk( 'published', { code: 'cf-live-' + suffix, discount_type: 'percent', amount: '10', status: 'publish' } );
		await mk( 'draft', { code: 'cf-draft-' + suffix, discount_type: 'fixed_cart', amount: '5', status: 'draft' } );
		t.check( 'fixtures: two coupons created', Object.values( made ).every( Boolean ), JSON.stringify( made ) );

		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page.goto( `${ BASE }/minn-admin/coupons`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-coupon]', { timeout: 25000 } );

		// ---- The bar replaces the tab strip ----
		const bar = await page.evaluate( () => {
			const x = ( sel ) => {
				const el = document.querySelector( sel );
				return el ? el.getBoundingClientRect().x : -1;
			};
			const row = ( sel ) => {
				const el = document.querySelector( sel );
				return el ? Math.round( el.getBoundingClientRect().y ) : -1;
			};
			return {
				preset: x( '#minn-order-preset' ), search: x( '#minn-order-search' ), add: x( '#minn-order-addfilter' ),
				sameRow: Math.abs( row( '#minn-order-preset' ) - row( '#minn-order-search' ) ) < 12
					&& Math.abs( row( '#minn-order-search' ) - row( '#minn-order-addfilter' ) ) < 12,
				tabsGone: ! document.querySelector( '[data-ctab]' ),
				addCoupon: !! document.querySelector( '#minn-coupon-add' ),
			};
		} );
		t.check( 'one row: status, search, add filter, in that order',
			bar.preset < bar.search && bar.search < bar.add && bar.sameRow, JSON.stringify( bar ) );
		t.check( 'the tab strip is gone and Add coupon survives', bar.tabsGone && bar.addCoupon, JSON.stringify( bar ) );

		// ---- The Add filter menu offers the date window, and nothing wc/v3
		//      cannot actually filter on ----
		await openFilterMenu( 'date' );
		const kinds = await page.evaluate( () => {
			// Re-open the top menu to read the whole list.
			document.querySelector( '.minn-of-pop' ).remove();
			document.querySelector( '#minn-order-addfilter' ).click();
			return Array.from( document.querySelectorAll( '[data-offilter]' ) ).map( ( b ) => b.dataset.offilter );
		} );
		t.check( 'the filter menu offers exactly the dimensions wc/v3 supports',
			kinds.length === 1 && kinds[ 0 ] === 'date', JSON.stringify( kinds ) );
		await page.keyboard.press( 'Escape' );

		// ---- Status dropdown ----
		await pickPreset( 'draft' );
		t.check( 'status dropdown filters to drafts',
			await waitRows( [ made.draft ], [ made.published ] ), JSON.stringify( await visibleIds() ) );
		await waitForQuery( /[?&]status=draft/, 'status=draft' );
		t.check( 'status dropdown sent status to the server', /[?&]status=draft/.test( lastQuery() ), lastQuery().slice( -100 ) );
		t.check( 'the dropdown names the active status', /Draft/i.test( await presetLabel() ), await presetLabel() );
		t.check( 'the status reaches the URL',
			new URL( await page.evaluate( () => location.href ) ).searchParams.get( 'status' ) === 'draft',
			await page.evaluate( () => location.search ) );

		await pickPreset( 'any' );
		await waitRows( [ made.published, made.draft ], [] );

		// ---- Date window ----
		// WooCommerce ignores date_created over REST, so every fixture is from
		// today and an "excludes the old coupon" check would pass vacuously.
		// What IS ours to test is the boundary math and that the list mirrors
		// the server for that window.
		await openFilterMenu( 'date' );
		await page.click( '.minn-of-pop [data-ofval="30"]' );
		await waitForQuery( /[?&]after=/, 'after=' );
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const from = new Date( Date.now() - 29 * 86400000 );
		const expected = `after=${ from.getFullYear() }-${ pad( from.getMonth() + 1 ) }-${ pad( from.getDate() ) }T00:00:00`;
		t.check( 'date preset asks for the right window', lastQuery().indexOf( expected ) !== -1, `${ expected } vs ${ lastQuery().slice( -70 ) }` );
		await settle();
		const server = await api( `wc/v3/coupons?per_page=25&page=1&orderby=date&order=desc&status=any&${ expected }&_fields=id` );
		const serverList = ( server.body || [] ).map( ( x ) => x.id );
		const uiIds = await visibleIds();
		t.check( 'the list mirrors the server for that window',
			serverList.length === uiIds.length && serverList.every( ( id ) => uiIds.includes( id ) ),
			JSON.stringify( { server: serverList.slice( 0, 8 ), ui: uiIds.slice( 0, 8 ) } ) );
		const dateChip = await chipLabels();
		t.check( 'the window gets a chip that names it',
			dateChip.some( ( c ) => /Date/i.test( c ) && /30 days/i.test( c ) ), JSON.stringify( dateChip ) );

		// ---- Removing the chip drops the window from the query ----
		await page.click( '[data-ofchip="date"] [data-ofremove]' );
		await settle();
		t.check( 'removing the date chip clears the window',
			! /[?&](after|before)=/.test( lastQuery() ) && ( await chipLabels() ).length === 0,
			lastQuery().slice( -100 ) );

		// ---- Status and the window ride the URL together, and survive a reload ----
		await pickPreset( 'publish' );
		await waitRows( [ made.published ], [ made.draft ] );
		await openFilterMenu( 'date' );
		await page.click( '.minn-of-pop [data-ofval="7"]' );
		await settle();
		let url = new URL( await page.evaluate( () => location.href ) );
		t.check( 'status and the date preset both reach the URL',
			url.searchParams.get( 'status' ) === 'publish' && url.searchParams.get( 'date' ) === '7', url.search );

		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-coupon]', { timeout: 25000 } );
		t.check( 'a reload restores the filtered rows',
			await waitRows( [ made.published ], [ made.draft ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'a reload restores the chips and the dropdown label',
			( await chipLabels() ).some( ( c ) => /Date/i.test( c ) ) && /Published/i.test( await presetLabel() ),
			JSON.stringify( { chips: await chipLabels(), preset: await presetLabel() } ) );
		t.check( 'the reloaded request carried the filters',
			/[?&]status=publish/.test( lastQuery() ) && /[?&]after=/.test( lastQuery() ), lastQuery().slice( -100 ) );

		// ---- Clear all ----
		await clearAll();
		t.check( 'clear all removes every chip', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );
		await waitRows( [ made.published, made.draft ], [] );
		t.check( 'cleared query carries no filter params',
			! /[?&](after|before)=/.test( lastQuery() ) && /[?&]status=any/.test( lastQuery() ), lastQuery().slice( -100 ) );
		t.check( 'the dropdown falls back to All', /All/i.test( await presetLabel() ), await presetLabel() );

		// ---- A hand-edited URL is untrusted input ----
		await page.goto( `${ BASE }/minn-admin/coupons?status=not-a-status&date=999`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-coupon]', { timeout: 25000 } );
		t.check( 'junk in the URL is ignored, not sent on',
			! /status=not-a-status/.test( lastQuery() ) && ! /[?&]after=/.test( lastQuery() ), lastQuery().slice( -100 ) );
		t.check( 'junk in the URL leaves no chips', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );

		// ---- Search still narrows, alongside the status filter ----
		await pickPreset( 'draft' );
		await waitRows( [ made.draft ], [ made.published ] );
		await page.fill( '#minn-order-search', 'cf-draft-' + suffix );
		await waitForQuery( /[?&]search=/, 'search=' );
		await settle();
		t.check( 'search narrows within the active filter',
			await waitRows( [ made.draft ], [ made.published ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'search and status travelled together',
			/[?&]search=/.test( lastQuery() ) && /[?&]status=draft/.test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- A filter change always returns to page one ----
		t.check( 'a filter change asks for page 1', /[?&]page=1(&|$)/.test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- The count label follows the filtered total ----
		const shown = ( await visibleIds() ).length;
		const meta = await page.evaluate( () => {
			const el = document.querySelector( '.minn-toolbar-meta' );
			return el ? el.textContent.trim() : '';
		} );
		t.check( 'count label reflects the filtered total', new RegExp( '\\b' + shown + '\\b' ).test( meta ), `${ meta } vs ${ shown } rows` );
	} finally {
		for ( const id of Object.values( made ) ) {
			if ( id ) await api( `wc/v3/coupons/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
