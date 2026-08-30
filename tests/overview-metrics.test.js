/**
 * Overview metric swap: the default cards stay, a right-click on one opens
 * the catalog, picking another metric replaces that slot (or swaps if it
 * is already showing), and the choice survives a reload.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'overview-metrics' );
	await login( page );

	const clear = () => page.evaluate( () => localStorage.removeItem( 'minn-overview-metrics' ) );
	await clear();

	try {
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-mkey]', { timeout: 20000 } );

		const start = await page.evaluate( () => ( {
			keys: [ ...document.querySelectorAll( '.minn-stat[data-mkey]' ) ].map( ( el ) => el.dataset.mkey ),
			labels: [ ...document.querySelectorAll( '.minn-stat-label' ) ].map( ( el ) => el.textContent.trim() ),
			hasPosts: !! document.querySelector( '.minn-stat[data-goto="content:posts"]' ),
		} ) );
		t.check( 'default layout still has a Published posts card', start.hasPosts && start.keys.includes( 'posts' ), JSON.stringify( start ) );

		const catalog = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/overview?days=30', {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return {
				status: r.status,
				statKeys: ( j.stats || [] ).map( ( s ) => s.key ),
				metricKeys: ( j.metrics || [] ).map( ( s ) => s.key ),
				groups: [ ...new Set( ( j.metrics || [] ).map( ( s ) => s.group ) ) ],
			};
		} );
		t.check( 'overview catalog includes the default cards plus extras',
			catalog.status === 200
			&& catalog.metricKeys.includes( 'posts' )
			&& catalog.metricKeys.includes( 'drafts' )
			&& catalog.metricKeys.length > catalog.statKeys.length,
			JSON.stringify( catalog ) );

		const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
		if ( hasWc ) {
			t.check( 'store catalog offers monthly sales and orders',
				catalog.metricKeys.includes( 'products' )
				&& catalog.metricKeys.includes( 'orders' )
				&& catalog.metricKeys.includes( 'sales_month' )
				&& catalog.metricKeys.includes( 'orders_month' )
				&& catalog.groups.includes( 'store' ),
				JSON.stringify( catalog.metricKeys ) );
		}

		const postsSlot = await page.evaluate( () =>
			( document.querySelector( '.minn-stat[data-mkey="posts"]' ) || {} ).dataset.mslot
		);
		await page.click( '.minn-stat[data-mkey="posts"]', { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const menu = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].map( ( el ) => el.textContent.trim() )
		);
		t.check( 'right-click offers Customize, not the full catalog',
			menu.length === 1 && /^Customize/.test( menu[ 0 ] ), JSON.stringify( menu ) );

		await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( el ) => /^Customize/.test( el.textContent.trim() ) );
			if ( b ) b.click();
		} );
		await page.waitForSelector( '.minn-metric-picker', { timeout: 8000 } );
		const picker = await page.evaluate( () => ( {
			groups: [ ...document.querySelectorAll( '.minn-metric-group-label' ) ].map( ( el ) => el.textContent.trim() ),
			keys: [ ...document.querySelectorAll( '.minn-metric-tile' ) ].map( ( el ) => el.dataset.metric ),
			on: ( document.querySelector( '.minn-metric-tile.is-on' ) || {} ).dataset.metric || '',
		} ) );
		t.check( 'Customize opens a picker modal on this card',
			picker.on === 'posts' && picker.keys.includes( 'drafts' ), JSON.stringify( picker ) );
		if ( hasWc ) {
			t.check( 'picker leads with store sales metrics on a Woo site',
				picker.groups[ 0 ] === 'Store'
				&& picker.keys.includes( 'sales_month' )
				&& picker.keys.includes( 'orders_month' )
				&& picker.keys.includes( 'products' ),
				JSON.stringify( picker ) );
		}

		const pickKey = hasWc ? 'products' : 'drafts';
		await page.click( `.minn-metric-tile[data-metric="${ pickKey }"]` );
		await page.waitForFunction( ( a ) => {
			const el = document.querySelector( '.minn-stat[data-mslot="' + a.slot + '"]' );
			return el && el.dataset.mkey === a.key && ! document.querySelector( '.minn-metric-picker' );
		}, { slot: postsSlot, key: pickKey }, { timeout: 8000 } );
		t.check( 'picking a metric replaces that card', true, '' );

		const after = await page.evaluate( ( slot ) => ( {
			keys: [ ...document.querySelectorAll( '.minn-stat[data-mkey]' ) ].map( ( el ) => el.dataset.mkey ),
			atSlot: ( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey,
			saved: JSON.parse( localStorage.getItem( 'minn-overview-metrics' ) || '[]' ),
		} ), postsSlot );
		t.check( 'chosen metric sits in that slot and is stored',
			after.atSlot === pickKey && after.saved[ Number( postsSlot ) ] === pickKey && ! after.keys.includes( 'posts' ),
			JSON.stringify( after ) );

		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-mkey]', { timeout: 20000 } );
		const reloaded = await page.evaluate( ( slot ) =>
			( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey
		, postsSlot );
		t.check( 'the swap survives a reload', reloaded === pickKey, String( reloaded ) );

		await page.click( `.minn-stat[data-mslot="${ postsSlot }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( el ) => /^Customize/.test( el.textContent.trim() ) );
			if ( b ) b.click();
		} );
		await page.waitForSelector( '#minn-metric-reset-all', { timeout: 8000 } );
		await page.click( '#minn-metric-reset-all' );
		await page.waitForFunction( () => !! document.querySelector( '.minn-stat[data-mkey="posts"]' ) && ! document.querySelector( '.minn-metric-picker' ), null, { timeout: 8000 } );
		const reset = await page.evaluate( () => ( {
			keys: [ ...document.querySelectorAll( '.minn-stat[data-mkey]' ) ].map( ( el ) => el.dataset.mkey ),
			saved: localStorage.getItem( 'minn-overview-metrics' ),
		} ) );
		t.check( 'Reset all cards restores the default posts card',
			reset.keys.includes( 'posts' ) && reset.saved === null, JSON.stringify( reset ) );

		await page.click( '.minn-stat[data-goto="content:posts"]' );
		await page.waitForSelector( '.minn-content-cols.minn-table-row, .minn-content-cols', { timeout: 15000 } );
		t.check( 'left-click still opens the posts list', /\/minn-admin\/content/.test( page.url() ), page.url() );
	} finally {
		await clear().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
