/**
 * Transients Manager adapter — Diagnostics family member.
 *
 * Proves: list/search, expired tab, detail, delete via core delete_transient,
 * delete-expired status action, and the Diagnostics family (shared nav label
 * with Scrutoscope / WP Crontrol).
 *
 * Fixture: minn_test_seed_transients → live + expired + persistent keys.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'transients-manager' );

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

	// Ensure plugin active.
	const plug = await page.evaluate( async () => {
		const id = 'transients-manager/transients-manager';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return { ok: false, status: r.status };
		return { ok: true, status: ( await r.json() ).status };
	} );
	t.check( 'transients-manager installed', !! plug.ok, JSON.stringify( plug ) );
	if ( plug.ok && plug.status !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/transients-manager/transients-manager', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed fixtures.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_transients: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );

	let list = null;
	for ( let i = 0; i < 10; i++ ) {
		list = await api( 'minn-admin/v1/transients?search=minn_tm_fixture&per_page=25' );
		if ( list.status === 200 && list.body && list.body.total >= 3 ) break;
		await page.waitForTimeout( 700 );
	}
	t.check( 'list returns three fixtures',
		!! list && list.status === 200 && list.body.total >= 3,
		JSON.stringify( list && { status: list.status, total: list.body && list.body.total } ) );

	const items = ( list && list.body && list.body.items ) || [];
	const live = items.find( ( r ) => r.name === 'minn_tm_fixture_live' );
	const dead = items.find( ( r ) => r.name === 'minn_tm_fixture_dead' );
	const persist = items.find( ( r ) => r.name === 'minn_tm_fixture_persist' );
	t.check( 'live fixture is active array',
		!! live && live.status === 'active' && live.type === 'array',
		JSON.stringify( live ) );
	t.check( 'dead fixture is expired',
		!! dead && dead.status === 'expired',
		JSON.stringify( dead ) );
	t.check( 'persist fixture is persistent',
		!! persist && persist.status === 'persistent',
		JSON.stringify( persist ) );

	// Expired tab.
	const expTab = await api( 'minn-admin/v1/transients?kind=expired&search=minn_tm_fixture' );
	t.check( 'expired tab only expired rows',
		expTab.status === 200
		&& ( expTab.body.items || [] ).length >= 1
		&& ( expTab.body.items || [] ).every( ( r ) => r.status === 'expired' ),
		JSON.stringify( expTab.body && expTab.body.items ) );

	// Detail.
	const detail = await api( 'minn-admin/v1/transients/' + live.id );
	t.check( 'detail 200', detail.status === 200 && detail.body.title === 'minn_tm_fixture_live',
		JSON.stringify( detail.body && detail.body.title ) );
	t.check( 'detail does not expand serialized array payload',
		!! detail.body.sections
		&& JSON.stringify( detail.body.sections ).includes( 'serialized' ),
		JSON.stringify( detail.body.sections ) );

	// Status card + delete expired.
	const st = await api( 'minn-admin/v1/transients/status' );
	t.check( 'status card has counts + actions',
		st.status === 200
		&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Transients' )
		&& ( st.body.actions || [] ).some( ( a ) => /Delete expired/.test( a.label ) ),
		JSON.stringify( st.body ) );

	const delExp = await api( 'minn-admin/v1/transients/delete-expired', { method: 'POST' } );
	t.check( 'delete expired ok', delExp.status === 200 && delExp.body && delExp.body.ok,
		JSON.stringify( delExp ) );
	const afterExp = await api( 'minn-admin/v1/transients?search=minn_tm_fixture_dead' );
	t.check( 'expired fixture gone after purge',
		afterExp.status === 200 && ( afterExp.body.items || [] ).length === 0,
		JSON.stringify( afterExp.body ) );

	// Single delete of live.
	const del = await api( 'minn-admin/v1/transients/' + live.id, { method: 'DELETE' } );
	t.check( 'delete live ok', del.status === 200 && del.body && del.body.ok, JSON.stringify( del ) );

	// Diagnostics family: surface descriptor + nav only one Tools "Diagnostics".
	const boot = await page.evaluate( () => {
		const surfs = ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'diagnostics' );
		return {
			count: surfs.length,
			subs: surfs.map( ( s ) => s.sub ),
			labels: [ ...new Set( surfs.map( ( s ) => s.label ) ) ],
		};
	} );
	t.check( 'diagnostics family has multiple providers',
		boot.count >= 2 && boot.labels.length === 1 && boot.labels[ 0 ] === 'Diagnostics',
		JSON.stringify( boot ) );
	t.check( 'Transients is a diagnostics member',
		boot.subs.includes( 'Transients' ),
		JSON.stringify( boot.subs ) );

	// UI: open surface, status card.
	await page.goto( BASE + '/minn-admin/transients-manager', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-status, .minn-table-row, .minn-empty', { timeout: 20000 } );
	await page.waitForTimeout( 400 );
	const ui = await page.evaluate( () => {
		const status = !! document.querySelector( '.minn-surface-status' );
		const switcher = Array.from( document.querySelectorAll( '.minn-sf-switch, .minn-provider, [data-sf], .minn-surface-switch button, .minn-chip' ) )
			.map( ( el ) => el.textContent.trim() )
			.filter( Boolean );
		// Provider switcher chips often sit in toolbar
		const chips = Array.from( document.querySelectorAll( 'button, a' ) )
			.map( ( el ) => el.textContent.trim() )
			.filter( ( t ) => /Scrutoscope|WP Crontrol|Transients/i.test( t ) );
		return { status, chips: chips.slice( 0, 12 ) };
	} );
	t.check( 'UI status card renders', ui.status, JSON.stringify( ui ) );

	// Nav: only one "Diagnostics" under Tools (not Profiler + Cron + Transients).
	const nav = await page.evaluate( () => {
		const tools = document.querySelector( '#minn-navgrp-tools, [data-navgroup="tools"]' )?.closest( '.minn-nav-group' )
			|| document.querySelector( '.minn-nav' );
		const labels = Array.from( ( tools || document ).querySelectorAll( '.minn-nav-btn, button[data-nav]' ) )
			.map( ( b ) => ( b.textContent || '' ).replace( /\s+/g, ' ' ).trim() )
			.filter( Boolean );
		// Fall back: all nav buttons
		const all = Array.from( document.querySelectorAll( '#minn-nav .minn-nav-btn, .minn-nav button' ) )
			.map( ( b ) => ( b.textContent || '' ).replace( /\s+/g, ' ' ).trim() );
		const use = labels.length ? labels : all;
		const diag = use.filter( ( t ) => /^Diagnostics/i.test( t ) || t.includes( 'Diagnostics' ) );
		const bad = use.filter( ( t ) => /^(Profiler|Cron)$/i.test( t.split( ' ' )[ 0 ] ) );
		return { diag: diag.length, bad, sample: use.slice( 0, 25 ) };
	} );
	t.check( 'Tools has one Diagnostics item, not Profiler/Cron tops',
		nav.diag === 1 && nav.bad.length === 0,
		JSON.stringify( nav ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
