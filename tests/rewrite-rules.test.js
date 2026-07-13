/**
 * Rewrite Rules Inspector adapter — Diagnostics family member.
 *
 * Proves: rule list + search (path and text), core tab, detail, status card,
 * Test URL action, Flush (count still sane after), and Diagnostics family
 * membership (Rewrites sub).
 *
 * Fixture: none — live rewrite_rules option always has rows on minnadmin.
 * Plugin rewrite-rules-inspector must be active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'rewrite-rules' );

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
		const id = 'rewrite-rules-inspector/rewrite-rules-inspector';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return { ok: false, status: r.status };
		return { ok: true, status: ( await r.json() ).status };
	} );
	t.check( 'rewrite-rules-inspector installed', !! plug.ok, JSON.stringify( plug ) );
	if ( plug.ok && plug.status !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/rewrite-rules-inspector/rewrite-rules-inspector', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	const list = await api( 'minn-admin/v1/rewrite-rules?per_page=25' );
	t.check( 'list returns many rules',
		list.status === 200 && list.body && list.body.total > 50
		&& ( list.body.items || [] ).length > 0,
		JSON.stringify( list.body && { status: list.status, total: list.body.total } ) );
	t.check( 'rows have rule/rewrite/source/status',
		( list.body.items || [] ).every( ( r ) => r.rule && r.rewrite && r.source && r.status && r.id ),
		JSON.stringify( ( list.body.items || [] )[ 0 ] ) );

	// Text search.
	const search = await api( 'minn-admin/v1/rewrite-rules?search=category&per_page=25' );
	t.check( 'search category finds rules',
		search.status === 200 && search.body.total > 0
		&& ( search.body.items || [] ).some( ( r ) => /category/i.test( r.rule + r.rewrite + r.source ) ),
		JSON.stringify( search.body && { total: search.body.total } ) );

	// Core tab.
	const core = await api( 'minn-admin/v1/rewrite-rules?kind=core&per_page=25' );
	t.check( 'core tab only core sources',
		core.status === 200 && ( core.body.items || [] ).length > 0
		&& ( core.body.items || [] ).every( ( r ) =>
			[ 'post', 'page', 'date', 'author', 'search', 'comments', 'root' ].includes( r.source ) ),
		JSON.stringify( ( core.body.items || [] ).slice( 0, 3 ) ) );

	// Detail.
	const first = list.body.items[ 0 ];
	const detail = await api( 'minn-admin/v1/rewrite-rules/' + first.id );
	t.check( 'detail 200 with sections',
		detail.status === 200 && Array.isArray( detail.body.sections )
		&& detail.body.title === first.rule,
		JSON.stringify( detail.body && { title: detail.body.title, status: detail.status } ) );
	t.check( 'detail adminUrl points at RRI',
		/tools\.php\?page=rewrite-rules-inspector/.test( detail.body.adminUrl || '' ),
		detail.body.adminUrl || '' );

	// Status card.
	const st = await api( 'minn-admin/v1/rewrite-rules/status' );
	t.check( 'status has rules count + flush + test',
		st.status === 200
		&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Rules' )
		&& ( st.body.actions || [] ).some( ( a ) => /Flush/.test( a.label ) )
		&& ( st.body.actions || [] ).some( ( a ) => /Test a URL/.test( a.label ) ),
		JSON.stringify( st.body ) );

	// Test URL — sample-page or home path should match something on a normal site.
	const test = await api( 'minn-admin/v1/rewrite-rules/test', {
		method: 'POST',
		body: JSON.stringify( { url: 'sample-page' } ),
	} );
	t.check( 'test URL returns a message',
		test.status === 200 && test.body && typeof test.body.message === 'string' && test.body.message.length > 5,
		JSON.stringify( test ) );

	// Flush — soft; count should stay in the same ballpark.
	const before = list.body.total;
	const flush = await api( 'minn-admin/v1/rewrite-rules/flush', { method: 'POST' } );
	t.check( 'flush ok',
		flush.status === 200 && flush.body && flush.body.ok
		&& /flushed/i.test( flush.body.message || '' ),
		JSON.stringify( flush ) );
	const after = await api( 'minn-admin/v1/rewrite-rules?per_page=1' );
	t.check( 'rules still present after flush',
		after.status === 200 && after.body.total > 50
		&& Math.abs( after.body.total - before ) < before * 0.5,
		JSON.stringify( { before, after: after.body && after.body.total } ) );

	// Diagnostics family membership.
	const boot = await page.evaluate( () => {
		const surfs = ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'diagnostics' );
		return {
			count: surfs.length,
			subs: surfs.map( ( s ) => s.sub ),
			labels: [ ...new Set( surfs.map( ( s ) => s.label ) ) ],
		};
	} );
	t.check( 'Rewrites is a diagnostics member',
		boot.subs.includes( 'Rewrites' ) && boot.labels[ 0 ] === 'Diagnostics',
		JSON.stringify( boot ) );

	// UI smoke.
	await page.goto( BASE + '/minn-admin/rewrite-rules-inspector', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-status, .minn-table-row', { timeout: 20000 } );
	await page.waitForTimeout( 400 );
	const ui = await page.evaluate( () => {
		const status = !! document.querySelector( '.minn-surface-status' );
		const rows = document.querySelectorAll( '.minn-table-row' ).length;
		const flushBtn = Array.from( document.querySelectorAll( 'button' ) )
			.some( ( b ) => /Flush rewrite rules/i.test( b.textContent || '' ) );
		return { status, rows, flushBtn };
	} );
	t.check( 'UI status card + rows + flush',
		ui.status && ui.rows > 0 && ui.flushBtn,
		JSON.stringify( ui ) );

	// Still one Diagnostics nav item (family collapse).
	const nav = await page.evaluate( () => {
		const all = Array.from( document.querySelectorAll( '.minn-nav-btn, button[data-nav], #minn-nav button' ) )
			.map( ( b ) => ( b.textContent || '' ).replace( /\s+/g, ' ' ).trim() )
			.filter( Boolean );
		const diag = all.filter( ( t ) => /^Diagnostics/i.test( t ) || t.includes( 'Diagnostics' ) );
		const bad = all.filter( ( t ) => /^(Profiler|Cron|Rewrites|Transients)$/i.test( t.split( /\s+/ )[ 0 ] ) );
		return { diag: diag.length, bad, sample: all.slice( 0, 30 ) };
	} );
	t.check( 'single Diagnostics nav item',
		nav.diag >= 1 && nav.bad.length === 0,
		JSON.stringify( nav ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
