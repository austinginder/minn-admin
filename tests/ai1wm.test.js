/**
 * All-in-One WP Migration — backups family provider (local export list).
 *
 * Proves: .wpress files listed via Ai1wm_Backups::get_files, labels as
 * titles, status card with honest no-freshness copy, Delete through their
 * own delete_file + delete_label, surface joins the backups family.
 *
 * Fixture: minn_test_seed_ai1wm drops two .wpress files under
 * AI1WM_BACKUPS_PATH. Suite activates AIOWM if needed and restores
 * inactive in finally.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ai1wm' );
	let wasActive = false;

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, typeof a === 'string' ? { path: a } : a );

	const setPlugin = async ( status ) => page.evaluate( async ( s ) => {
		try {
			const res = await fetch( window.MINN.restUrl + 'wp/v2/plugins/all-in-one-wp-migration/all-in-one-wp-migration', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return { status: res.status };
		} catch ( e ) {
			return { status: 0 };
		}
	}, status );

	try {
		const st = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/all-in-one-wp-migration/all-in-one-wp-migration?_fields=status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).status;
		} );
		wasActive = st === 'active';
		if ( ! wasActive ) {
			await setPlugin( 'active' );
			await page.waitForTimeout( 1500 );
			await page.reload( { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-app', { timeout: 20000 } );
		}

		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_ai1wm: '1' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );

		let list = null;
		for ( let i = 0; i < 10; i++ ) {
			list = await api( 'minn-admin/v1/ai1wm/exports' );
			if ( list.body && list.body.items
				&& list.body.items.some( ( r ) => r.filename === 'minn-fixture.wpress' )
				&& list.body.items.some( ( r ) => r.filename === 'minn-fixture-delete.wpress' ) ) break;
			await page.waitForTimeout( 600 );
		}
		t.check( 'seeded exports listed',
			!! list && list.body && list.body.items
			&& list.body.items.some( ( r ) => r.filename === 'minn-fixture.wpress' )
			&& list.body.items.some( ( r ) => r.filename === 'minn-fixture-delete.wpress' ),
			JSON.stringify( list && list.body && { total: list.body.total, names: ( list.body.items || [] ).map( ( r ) => r.filename ) } ) );

		const keeper = list.body.items.find( ( r ) => r.filename === 'minn-fixture.wpress' );
		t.check( 'keeper title uses their label option',
			!! keeper && keeper.title === 'Minn fixture export' && /B|KB|MB/.test( keeper.size ),
			JSON.stringify( keeper ) );
		t.check( 'export id is base64url (no slashes)',
			!! keeper && /^[A-Za-z0-9_-]+$/.test( keeper.id ),
			keeper && keeper.id );

		const stat = await api( 'minn-admin/v1/ai1wm/status' );
		t.check( 'status card: honest no-freshness copy',
			!! stat.body && ( stat.body.rows || [] ).some( ( r ) => /no freshness claims/.test( r.hint || '' ) ),
			JSON.stringify( stat.body && stat.body.rows ) );
		t.check( 'status card: export + open deep links',
			!! stat.body && ( stat.body.actions || [] ).some( ( a ) => /Export site/.test( a.label ) )
			&& ( stat.body.actions || [] ).some( ( a ) => /Open backups/.test( a.label ) ),
			JSON.stringify( stat.body && stat.body.actions ) );

		/* ===== Surface in the app ===== */
		await page.goto( `${ BASE }/minn-admin/ai1wm`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'surface joins the backups family', await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'ai1wm' );
			return !! s && s.family === 'backups' && /All-in-One/.test( s.sub || '' );
		} ) );
		t.check( 'status card renders above the list',
			!! ( await page.$( '.minn-surface-status' ) )
			&& /Export|Exports|no freshness/.test( await page.$eval( '.minn-surface-status', ( el ) => el.textContent ) ) );

		/* ===== Delete through their own delete_file ===== */
		const doomed = list.body.items.find( ( r ) => r.filename === 'minn-fixture-delete.wpress' );
		const del = await api( {
			path: 'minn-admin/v1/ai1wm/exports/' + doomed.id,
			opts: { method: 'DELETE' },
		} );
		t.check( 'delete removes the disposable export', del.status === 200 && del.body && del.body.deleted,
			JSON.stringify( del ) );
		const after = await api( 'minn-admin/v1/ai1wm/exports' );
		t.check( 'disposable gone; keeper remains',
			! ( after.body.items || [] ).some( ( r ) => r.filename === 'minn-fixture-delete.wpress' )
			&& ( after.body.items || [] ).some( ( r ) => r.filename === 'minn-fixture.wpress' ),
			JSON.stringify( after.body && after.body.items && after.body.items.map( ( r ) => r.filename ) ) );

	} finally {
		if ( ! wasActive ) {
			await setPlugin( 'inactive' ).catch( () => {} );
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
