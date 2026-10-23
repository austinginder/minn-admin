/**
 * Overview Sales chart: on a store, the Overview chart leads with what sold
 * each day (WooCommerce's own sales report), its hover card names the
 * amount and the orders, and a bar opens the Orders list narrowed to exactly
 * the orders it counted. Someone who cannot read the store's reports never
 * gets the series.
 *
 * Fixtures: one completed order and one pending order created now over
 * wc/v3, and one completed order placed at 00:05 local time today (on a
 * site east of UTC its GMT date is yesterday, which is the case the HPOS
 * query's time zone handling exists for), all deleted on the way out. Amounts are asserted as differences
 * against a baseline taken first (minnadmin carries resident orders).
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'overview-sales' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce orders available', false, 'caps.orders missing — skip' );
		await t.done( browser, errors );
		return;
	}

	const api = ( p, path, opts ) => p.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body, total: r.headers.get( 'X-WP-Total' ) };
	}, { path, opts } );

	const last = ( ov ) => {
		const c = ov.body && ov.body.sales && ov.body.sales.chart;
		return c && c.length ? c[ c.length - 1 ] : null;
	};

	const fixtureIds = [];
	let editor = null;
	try {
		const before = await api( page, 'minn-admin/v1/overview?days=7' );
		t.check( 'overview carries a sales series',
			before.status === 200 && before.body.sales && Array.isArray( before.body.sales.chart ) && before.body.sales.chart.length === 7,
			JSON.stringify( before.body && before.body.sales ).slice( 0, 300 ) );
		const base = last( before ) || { value: 0, orders: 0 };

		// One sale the report counts, one it does not (awaiting payment).
		for ( const [ status, total ] of [ [ 'completed', '123.45' ], [ 'pending', '77.00' ] ] ) {
			const made = await api( page, 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( {
					status,
					billing: { first_name: 'Sales', last_name: 'Fixture', email: 'sales-fixture@example.com' },
					fee_lines: [ { name: 'Sales fixture', total, tax_status: 'none' } ],
				} ),
			} );
			t.check( `created ${ status } fixture order`, made.status === 201 && made.body && made.body.id, String( made.status ) );
			if ( made.body && made.body.id ) fixtureIds.push( made.body.id );
		}

		const early = execSync( `wp --path=${ JSON.stringify( WP ) } eval-file -`, {
			input: `<?php
$o = wc_create_order( array( 'status' => 'completed' ) );
$o->set_date_created( ( new DateTimeImmutable( 'today 00:05', wp_timezone() ) )->getTimestamp() );
$o->set_total( 10 );
$o->save();
echo $o->get_id();
`,
			timeout: 60000,
			stdio: [ 'pipe', 'pipe', 'ignore' ],
		} ).toString().trim().split( /\s+/ ).pop();
		t.check( 'created the just-after-midnight order', /^\d+$/.test( early ), early );
		if ( /^\d+$/.test( early ) ) fixtureIds.push( Number( early ) );

		const after = await api( page, 'minn-admin/v1/overview?days=7' );
		const today = last( after );
		t.check( 'today\'s bar counts both completed orders and not the pending one',
			today && today.orders === base.orders + 2, JSON.stringify( { base, today } ) );
		t.check( 'today\'s bar adds the completed orders\' totals',
			today && Math.abs( today.value - base.value - 133.45 ) < 0.01, JSON.stringify( { base, today } ) );
		t.check( 'a daily bar spans one local day', today && today.from === today.to, JSON.stringify( today ) );

		// The bar's question, asked of the Orders list the bar opens.
		const sales = after.body.sales;
		const ymd = ( s, n ) => {
			const [ y, m, d ] = s.split( '-' ).map( Number );
			return new Date( Date.UTC( y, m - 1, d + n ) ).toISOString().slice( 0, 10 );
		};
		const q = sales.statuses.map( ( s ) => 'status[]=' + encodeURIComponent( s ) ).join( '&' )
			+ '&after=' + encodeURIComponent( ymd( today.from, -1 ) + 'T23:59:59' )
			+ '&before=' + encodeURIComponent( ymd( today.to, 1 ) + 'T00:00:00' );
		const listed = await api( page, `wc/v3/orders?per_page=100&_fields=id&${ q }` );
		t.check( 'the Orders query behind the bar returns the orders the bar counted',
			Number( listed.total ) === today.orders, `${ listed.total } vs ${ today.orders }` );
		const ids = ( listed.body || [] ).map( ( o ) => o.id );
		t.check( 'it holds both completed fixtures and not the pending one',
			ids.includes( fixtureIds[ 0 ] ) && ids.includes( fixtureIds[ 2 ] ) && ! ids.includes( fixtureIds[ 1 ] ), JSON.stringify( ids ) );

		const wide = await api( page, 'minn-admin/v1/overview?days=90' );
		const wc = ( wide.body.sales && wide.body.sales.chart ) || [];
		t.check( '90 days reads as weekly bars', wc.length === 13 && /^Week of /.test( wc[ 0 ].label ) && wc[ 0 ].from !== wc[ 0 ].to,
			JSON.stringify( wc[ 0 ] ) );
		t.check( 'the weekly series sums the same orders as its last week of days',
			wc.length && wc[ wc.length - 1 ].orders >= today.orders, JSON.stringify( wc[ wc.length - 1 ] ) );

		// UI: Sales leads on a store when nothing was picked.
		await page.evaluate( () => localStorage.removeItem( 'minn-chart-source' ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-chart .minn-chart-col', { timeout: 20000 } );
		const head = await page.evaluate( () => ( document.querySelector( '.minn-chart-head .minn-panel-title' ) || {} ).textContent || '' );
		t.check( 'the chart leads with Sales', /^Sales/.test( head.trim() ), head );
		t.check( 'its header names the range\'s orders', /\d+ orders?/.test( head ), head );

		// Hover the last column with a real mouse.
		const col = page.locator( '#minn-chart .minn-chart-col' ).last();
		const box = await col.boundingBox();
		await page.mouse.move( box.x + box.width / 2, box.y + box.height - 6 );
		await page.waitForSelector( '#minn-chart-tip:not([hidden])', { timeout: 5000 } );
		const tip = await page.evaluate( () => document.querySelector( '#minn-chart-tip' ).textContent.replace( /\s+/g, ' ' ) );
		t.check( 'the hover card names sales and orders', /Sales/.test( tip ) && /Orders?/.test( tip ) && ! /Events?/.test( tip ), tip );
		t.check( 'the hover card offers the orders', /Click for details/.test( tip ), tip );

		// Click it: the Orders list, narrowed to the report's statuses and today.
		await col.click();
		await page.waitForFunction( () => location.pathname.includes( '/minn-admin/orders' ), null, { timeout: 15000 } );
		await page.waitForFunction( ( id ) => document.body.textContent.includes( '#' + id ), fixtureIds[ 0 ], { timeout: 20000 } );
		t.check( 'the bar opens the Orders list with the completed fixture in it', true, '' );
		const url = new URL( page.url() );
		t.check( 'the list is narrowed to the bar\'s window',
			url.searchParams.get( 'after' ) === ymd( today.from, -1 ) + 'T23:59:59'
				&& url.searchParams.get( 'before' ) === ymd( today.to, 1 ) + 'T00:00:00',
			url.search );
		t.check( 'and to the report\'s statuses', url.searchParams.get( 'status' ) === sales.statuses.join( ',' ), url.search );
		const pendingShown = await page.evaluate( ( id ) => document.body.textContent.includes( '#' + id ), fixtureIds[ 1 ] );
		t.check( 'the pending fixture is not in it', ! pendingShown, '' );
		const chips = await page.evaluate( () => document.body.textContent.replace( /\s+/g, ' ' ) );
		t.check( 'the date chip names the day itself', chips.includes( today.from ) && ! chips.includes( ymd( today.from, -1 ) ), today.from );

		// The swap button walks every source and comes back.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-chart-swap', { timeout: 20000 } );
		const seen = [];
		for ( let n = 0; n < 4; n++ ) {
			const title = await page.evaluate( () => document.querySelector( '.minn-chart-head .minn-panel-title' ).firstChild.textContent.trim() );
			seen.push( title );
			const next = await page.getAttribute( '#minn-chart-swap', 'title' );
			await page.click( '#minn-chart-swap' );
			await page.waitForFunction( ( was ) => {
				const el = document.querySelector( '.minn-chart-head .minn-panel-title' );
				return el && el.firstChild.textContent.trim() !== was;
			}, title, { timeout: 5000 } ).catch( () => null );
			const now = await page.evaluate( () => document.querySelector( '.minn-chart-head .minn-panel-title' ).firstChild.textContent.trim() );
			if ( n === 0 ) t.check( 'the swap button names the next source', next === `Show ${ now }`, `${ next } → ${ now }` );
			if ( now === seen[ 0 ] ) break;
		}
		t.check( 'the swap cycles through Activity and back to Sales', seen.includes( 'Activity' ) && seen[ 0 ] === 'Sales', seen.join( ' → ' ) );
		await page.evaluate( () => localStorage.removeItem( 'minn-chart-source' ) );

		// Someone who cannot read the store's reports gets no series.
		editor = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const ed = await api( editor.page, 'minn-admin/v1/overview?days=7' );
		t.check( 'an Editor gets no sales series', ed.status === 200 && ! ed.body.sales, JSON.stringify( ed.body && ed.body.sales ) );
	} finally {
		for ( const id of fixtureIds ) {
			await api( page, `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( editor && editor.ctx ) await editor.ctx.close().catch( () => {} );
	}

	await t.done( browser, errors );
} )();
