/**
 * BackWPup — backups family provider.
 *
 * Proves: local FOLDER archives listed via their destination file_get_list,
 * status card (last run / archive count / jobs), Delete through their own
 * file_delete, and the surface joins the backups family switcher.
 *
 * Fixture: minn_test_seed_backwpup drops two .tar files into the first
 * job's backupdir (keeper + disposable). Suite activates BackWPup if
 * needed and restores inactive in finally (pack convention).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'backwpup' );
	let wasActive = false;

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, typeof a === 'string' ? { path: a } : a );

	const setPlugin = async ( status ) => {
		const r = await page.evaluate( async ( s ) => {
			try {
				const res = await fetch( window.MINN.restUrl + 'wp/v2/plugins/backwpup/backwpup', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: s } ),
				} );
				return { status: res.status };
			} catch ( e ) {
				return { status: 0, err: String( e && e.message || e ) };
			}
		}, status );
		return r;
	};

	try {
		const st = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/backwpup/backwpup?_fields=status', {
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

		// Seed keeper + disposable archives.
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_backwpup: '1' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );

		let list = null;
		for ( let i = 0; i < 10; i++ ) {
			list = await api( 'minn-admin/v1/backwpup/backups' );
			if ( list.body && list.body.items
				&& list.body.items.some( ( r ) => /minn-fixture-backwpup\.tar/.test( r.filename ) )
				&& list.body.items.some( ( r ) => /minn-fixture-backwpup-delete/.test( r.filename ) ) ) break;
			await page.waitForTimeout( 600 );
		}
		t.check( 'seeded archives listed',
			!! list && list.body && list.body.items
			&& list.body.items.some( ( r ) => r.filename === 'minn-fixture-backwpup.tar' )
			&& list.body.items.some( ( r ) => r.filename === 'minn-fixture-backwpup-delete.tar' ),
			JSON.stringify( list && list.body && { total: list.body.total, names: ( list.body.items || [] ).map( ( r ) => r.filename ) } ) );

		const keeper = list.body.items.find( ( r ) => r.filename === 'minn-fixture-backwpup.tar' );
		t.check( 'keeper has size + job + utc date',
			!! keeper && /B|KB|MB/.test( keeper.size ) && keeper.job && /Z$/.test( keeper.date ),
			JSON.stringify( keeper ) );

		const stat = await api( 'minn-admin/v1/backwpup/status' );
		t.check( 'status card reports local archives + jobs',
			!! stat.body && Array.isArray( stat.body.rows ) && stat.body.rows.length >= 3
			&& stat.body.rows.some( ( r ) => /Local archives|archives/i.test( r.label ) ),
			JSON.stringify( stat.body && stat.body.rows ) );
		t.check( 'status card offers Run first job now',
			!! stat.body && ( stat.body.actions || [] ).some( ( a ) => /Run first job/.test( a.label ) ),
			JSON.stringify( stat.body && stat.body.actions ) );

		/* ===== Surface in the app ===== */
		await page.goto( `${ BASE }/minn-admin/backwpup`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'surface joins the backups family', await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'backwpup' );
			return !! s && s.family === 'backups' && s.sub === 'BackWPup';
		} ) );
		t.check( 'status card renders above the list',
			!! ( await page.$( '.minn-surface-status' ) )
			&& /Local archives|Last run|Run first job/.test( await page.$eval( '.minn-surface-status', ( el ) => el.textContent ) ) );

		/* ===== Delete through their own file_delete ===== */
		const doomed = list.body.items.find( ( r ) => r.filename === 'minn-fixture-backwpup-delete.tar' );
		const del = await api( {
			path: 'minn-admin/v1/backwpup/backups/' + encodeURIComponent( doomed.id ),
			opts: { method: 'DELETE' },
		} );
		t.check( 'delete removes the disposable archive', del.status === 200 && del.body && del.body.deleted,
			JSON.stringify( del ) );
		const after = await api( 'minn-admin/v1/backwpup/backups' );
		t.check( 'disposable gone; keeper remains',
			! ( after.body.items || [] ).some( ( r ) => r.filename === 'minn-fixture-backwpup-delete.tar' )
			&& ( after.body.items || [] ).some( ( r ) => r.filename === 'minn-fixture-backwpup.tar' ),
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
