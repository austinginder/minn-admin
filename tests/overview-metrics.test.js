/**
 * Overview metric swap: the default cards stay, a right-click on one opens
 * Customize, picking another metric replaces that slot (or swaps if it is
 * already showing), the choice is stored per user, and an administrator
 * can set the site-wide default for everyone else.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, loginAs, reporter } = require( './helpers' );

function clearMetricPrefs() {
	execSync( `wp --path=${ JSON.stringify( WP ) } eval-file -`, {
		input: `<?php
foreach ( array( 'admin', 'minn-editor' ) as $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		delete_user_meta( $u->ID, 'minn_admin_overview_metrics' );
	}
}
delete_option( 'minn_admin_overview_metric_defaults' );
`,
		timeout: 30000,
	} );
}

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'overview-metrics' );
	await login( page );

	const rest = ( p, method, path, body ) => p.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { ok: r.ok, status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );

	const openCustomize = async ( p, slotSel ) => {
		await p.click( slotSel, { button: 'right' } );
		await p.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await p.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( el ) => /^Customize/.test( el.textContent.trim() ) );
			if ( b ) b.click();
		} );
		await p.waitForSelector( '.minn-metric-picker', { timeout: 8000 } );
	};

	clearMetricPrefs();
	await page.evaluate( () => localStorage.removeItem( 'minn-overview-metrics' ) );

	let editor = null;
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
				layoutKeys: j.metricKeys,
				custom: j.metricCustom,
				canSet: j.canSetMetricDefaults,
			};
		} );
		t.check( 'overview catalog includes the default cards plus extras',
			catalog.status === 200
			&& catalog.metricKeys.includes( 'posts' )
			&& catalog.metricKeys.includes( 'drafts' )
			&& catalog.metricKeys.length > catalog.statKeys.length,
			JSON.stringify( catalog ) );
		t.check( 'a fresh account is not marked custom and may set site defaults',
			catalog.custom === false && catalog.canSet === true, JSON.stringify( catalog ) );

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
			asDefault: !! document.querySelector( '#minn-metric-as-default' ),
		} ) );
		t.check( 'Customize opens a picker modal on this card',
			picker.on === 'posts' && picker.keys.includes( 'drafts' ), JSON.stringify( picker ) );
		t.check( 'Use as default stays hidden on the built-in layout',
			picker.asDefault === false, JSON.stringify( picker ) );
		if ( hasWc ) {
			t.check( 'picker leads with store sales metrics on a Woo site',
				picker.groups[ 0 ] === 'Store'
				&& picker.keys.includes( 'sales_month' )
				&& picker.keys.includes( 'orders_month' )
				&& picker.keys.includes( 'products' ),
				JSON.stringify( picker ) );
		}

		const pickKey = 'drafts';
		const savePersonal = page.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/overview/metrics' ) && r.request().method() === 'POST'
		);
		await page.click( `.minn-metric-tile[data-metric="${ pickKey }"]` );
		const personalRes = await savePersonal;
		t.check( 'picking a metric POSTs the personal layout', personalRes.status() === 200, String( personalRes.status() ) );
		await page.waitForFunction( ( a ) => {
			const el = document.querySelector( '.minn-stat[data-mslot="' + a.slot + '"]' );
			return el && el.dataset.mkey === a.key && ! document.querySelector( '.minn-metric-picker' );
		}, { slot: postsSlot, key: pickKey }, { timeout: 8000 } );
		t.check( 'picking a metric replaces that card', true, '' );

		const after = await rest( page, 'GET', 'minn-admin/v1/overview?days=30' );
		const afterSlot = await page.evaluate( ( slot ) =>
			( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey
		, postsSlot );
		t.check( 'chosen metric sits in that slot and is stored on the user',
			after.ok
			&& after.data.metricCustom === true
			&& ( after.data.metricKeys || [] )[ Number( postsSlot ) ] === pickKey
			&& afterSlot === pickKey,
			JSON.stringify( { afterSlot, keys: after.data && after.data.metricKeys, custom: after.data && after.data.metricCustom } ) );

		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-mkey]', { timeout: 20000 } );
		const reloaded = await page.evaluate( ( slot ) =>
			( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey
		, postsSlot );
		t.check( 'the swap survives a reload', reloaded === pickKey, String( reloaded ) );

		await openCustomize( page, `.minn-stat[data-mslot="${ postsSlot }"]` );
		await page.waitForSelector( '#minn-metric-as-default', { timeout: 8000 } );
		t.check( 'an administrator can set the layout as the site default', true, '' );

		const saveDefault = page.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/overview/metric-defaults' ) && r.request().method() === 'POST'
		);
		await page.click( '#minn-metric-as-default' );
		const defaultRes = await saveDefault;
		t.check( 'Use as default POSTs the site layout', defaultRes.status() === 200, String( defaultRes.status() ) );
		await page.waitForFunction( () => ! document.querySelector( '.minn-metric-picker' ), null, { timeout: 8000 } );

		const storedDefault = await rest( page, 'GET', 'minn-admin/v1/overview?days=30' );
		t.check( 'site default now carries the swapped card',
			storedDefault.ok
			&& ( storedDefault.data.metricDefaults || [] )[ Number( postsSlot ) ] === pickKey,
			JSON.stringify( storedDefault.data && storedDefault.data.metricDefaults ) );

		editor = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const ep = editor.page;
		await ep.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await ep.waitForSelector( '.minn-stat[data-mkey]', { timeout: 20000 } );
		const editorView = await ep.evaluate( ( slot ) => ( {
			atSlot: ( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey,
			asDefault: false,
		} ), postsSlot );
		const editorOverview = await rest( ep, 'GET', 'minn-admin/v1/overview?days=30' );
		t.check( 'an editor without a personal pick follows the site default',
			editorView.atSlot === pickKey
			&& editorOverview.ok
			&& editorOverview.data.metricCustom === false
			&& editorOverview.data.canSetMetricDefaults === false
			&& ( editorOverview.data.metricKeys || [] )[ Number( postsSlot ) ] === pickKey,
			JSON.stringify( { view: editorView, data: editorOverview.data } ) );

		const refused = await rest( ep, 'POST', 'minn-admin/v1/overview/metric-defaults', { keys: [ 'media' ] } );
		t.check( 'an editor is refused the site-default API', refused.status === 403, String( refused.status ) );

		await openCustomize( ep, `.minn-stat[data-mslot="${ postsSlot }"]` );
		const editorPicker = await ep.evaluate( () => ( {
			asDefault: !! document.querySelector( '#minn-metric-as-default' ),
			hasMedia: !! document.querySelector( '.minn-metric-tile[data-metric="media"]' ),
		} ) );
		t.check( 'an editor does not see Use as default for everyone',
			editorPicker.asDefault === false && editorPicker.hasMedia, JSON.stringify( editorPicker ) );

		const editorSave = ep.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/overview/metrics' ) && r.request().method() === 'POST'
		);
		await ep.click( '.minn-metric-tile[data-metric="media"]' );
		const editorSaveRes = await editorSave;
		t.check( 'an editor can still save a personal layout', editorSaveRes.status() === 200, String( editorSaveRes.status() ) );
		await ep.waitForFunction( ( a ) => {
			const el = document.querySelector( '.minn-stat[data-mslot="' + a.slot + '"]' );
			return el && el.dataset.mkey === 'media';
		}, { slot: postsSlot }, { timeout: 8000 } );

		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-mkey]', { timeout: 20000 } );
		const adminStill = await page.evaluate( ( slot ) =>
			( document.querySelector( '.minn-stat[data-mslot="' + slot + '"]' ) || {} ).dataset.mkey
		, postsSlot );
		t.check( "an editor's pick does not change the administrator's cards",
			adminStill === pickKey, String( adminStill ) );

		await openCustomize( ep, `.minn-stat[data-mslot="${ postsSlot }"]` );
		await ep.waitForSelector( '#minn-metric-reset-all', { timeout: 8000 } );
		const resetSave = ep.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/overview/metrics' ) && r.request().method() === 'POST'
		);
		await ep.click( '#minn-metric-reset-all' );
		await resetSave;
		await ep.waitForFunction( ( a ) => {
			const el = document.querySelector( '.minn-stat[data-mslot="' + a.slot + '"]' );
			return el && el.dataset.mkey === a.key && ! document.querySelector( '.minn-metric-picker' );
		}, { slot: postsSlot, key: pickKey }, { timeout: 8000 } );
		t.check( 'Reset all cards follows the site default, not the built-in posts card', true, '' );

		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-goto="content:posts"]', { timeout: 20000 } );
		await page.click( '.minn-stat[data-goto="content:posts"]' );
		await page.waitForSelector( '.minn-content-cols.minn-table-row, .minn-content-cols', { timeout: 15000 } );
		t.check( 'left-click still opens the posts list', /\/minn-admin\/content/.test( page.url() ), page.url() );
	} finally {
		if ( editor && editor.ctx ) await editor.ctx.close().catch( () => {} );
		try { clearMetricPrefs(); } catch ( e ) { /* keep going */ }
		await page.evaluate( () => localStorage.removeItem( 'minn-overview-metrics' ) ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
