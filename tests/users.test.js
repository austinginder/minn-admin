/**
 * Users view: role filter combobox + session-status tabs (All / Active /
 * Expired / Never signed in). Role filter replaces the old per-role tab
 * spread. Session filter hits minn-admin/v1/users (session_tokens can't be
 * filtered via core wp/v2/users).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'users' );
	await login( page );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ), total: r.headers.get( 'X-WP-Total' ) };
	}, [ path, opts ] );

	// Role column is the 3rd .minn-row-meta (ID, email, role, registered).
	const rowRoles = () => page.$$eval( '.minn-user-cols.minn-table-row', ( els ) =>
		els.map( ( el ) => {
			const metas = el.querySelectorAll( '.minn-row-meta' );
			return ( metas[ 2 ] && metas[ 2 ].textContent.trim() ) || '';
		} ) );

	const waitTable = async () => {
		await page.waitForFunction( () => ! document.querySelector( '.minn-table.minn-busy' ), { timeout: 10000 } );
		await page.waitForTimeout( 350 );
	};

	await page.goto( `${ BASE }/minn-admin/users`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-user-cols.minn-table-row', { timeout: 15000 } );

	const combo = '[data-rolecombo] .minn-ac-input';
	t.check( 'role combobox renders (no tab spread)', !! ( await page.$( combo ) ) && ! ( await page.$( '.minn-tab[data-role]' ) ), '' );
	t.check( 'session filter tabs render', !! ( await page.$( '[data-usess="all"]' ) ) && !! ( await page.$( '[data-usess="active"]' ) )
		&& !! ( await page.$( '[data-usess="expired"]' ) ) && !! ( await page.$( '[data-usess="never"]' ) ), '' );
	t.check( 'All session tab is active by default', await page.$eval( '[data-usess="all"]', ( el ) => el.classList.contains( 'active' ) ), '' );

	/* ===== Browse on focus ===== */
	await page.click( combo );
	await page.waitForSelector( '[data-rolecombo] .minn-ac-panel:not([hidden])' );
	const all = await page.$$eval( '[data-rolecombo] .minn-ac-item', ( o ) => o.map( ( e ) => e.textContent.trim() ) );
	t.check( 'focus browses the full role list with All first', all.length > 2 && all[ 0 ] === 'All roles', JSON.stringify( all ) );

	/* ===== Type-to-filter replaces the display label ===== */
	await page.keyboard.type( 'edi' );
	await page.waitForTimeout( 200 );
	const filtered = await page.$$eval( '[data-rolecombo] .minn-ac-item', ( o ) => o.map( ( e ) => e.textContent.trim() ) );
	t.check( 'typing filters (first keystroke replaces the label)', filtered.length > 0 && filtered.length < all.length && filtered.includes( 'Editor' ), JSON.stringify( filtered ) );

	/* ===== Enter picks the first match and filters the table ===== */
	await page.keyboard.press( 'Enter' );
	await waitTable();
	const roles = await rowRoles();
	t.check( 'picked role filters the table', roles.length > 0 && roles.every( ( r ) => r === 'Editor' ), JSON.stringify( roles ) );
	t.check( 'display shows the picked label', ( await page.$eval( combo, ( i ) => i.value ) ) === 'Editor', '' );

	/* ===== Reset to All ===== */
	await page.click( combo );
	await page.waitForSelector( '[data-rolecombo] .minn-ac-panel:not([hidden])' );
	await page.locator( '[data-rolecombo] .minn-ac-item', { hasText: 'All roles' } ).first().dispatchEvent( 'mousedown' );
	await waitTable();
	const rowsAfter = await page.$$( '.minn-user-cols.minn-table-row' );
	t.check( 'All roles resets the table', rowsAfter.length > roles.length, String( rowsAfter.length ) );
	t.check( 'display snaps back to All roles', ( await page.$eval( combo, ( i ) => i.value ) ) === 'All roles', '' );

	/* ===== Session filter fixtures ===== */
	let activeUid = null;
	let expiredUid = null;
	let neverUid = null;
	try {
		const stamp = Date.now() % 100000;
		const mk = async ( label ) => {
			const email = `minn_usess_${ label }_${ stamp }@example.com`;
			const created = await rest( 'wp/v2/users', {
				method: 'POST',
				body: JSON.stringify( {
					username: email, email, name: `Sess ${ label } ${ stamp }`, password: 'Sess-Test-9x!', roles: [ 'subscriber' ],
				} ),
			} );
			return created.body && created.body.id;
		};
		activeUid = await mk( 'active' );
		expiredUid = await mk( 'expired' );
		neverUid = await mk( 'never' );
		t.check( 'fixture users created', !! activeUid && !! expiredUid && !! neverUid, `${ activeUid }/${ expiredUid }/${ neverUid }` );

		const seedActive = await rest( 'minn-admin/v1/minn-test/seed-sessions', {
			method: 'POST', body: JSON.stringify( { uid: activeUid, mode: 'seed' } ),
		} );
		const seedExpired = await rest( 'minn-admin/v1/minn-test/seed-sessions', {
			method: 'POST', body: JSON.stringify( { uid: expiredUid, mode: 'expired' } ),
		} );
		t.check( 'active + expired fixtures seeded', seedActive.body && seedActive.body.ok && seedExpired.body && seedExpired.body.ok );

		const activeList = await rest( `minn-admin/v1/users?session=active&per_page=100&search=${ encodeURIComponent( `Sess active ${ stamp }` ) }` );
		const activeIds = ( activeList.body || [] ).map( ( u ) => u.id );
		t.check( 'REST active includes seeded active user', activeIds.includes( activeUid ), JSON.stringify( activeIds ) );
		t.check( 'REST active excludes never user', ! activeIds.includes( neverUid ), JSON.stringify( activeIds ) );

		const expiredList = await rest( `minn-admin/v1/users?session=expired&per_page=100&search=${ encodeURIComponent( `Sess expired ${ stamp }` ) }` );
		const expiredIds = ( expiredList.body || [] ).map( ( u ) => u.id );
		t.check( 'REST expired includes seeded expired user', expiredIds.includes( expiredUid ), JSON.stringify( expiredIds ) );

		const neverList = await rest( `minn-admin/v1/users?session=never&per_page=100&search=${ encodeURIComponent( `Sess never ${ stamp }` ) }` );
		const neverIds = ( neverList.body || [] ).map( ( u ) => u.id );
		t.check( 'REST never includes never-signed-in user', neverIds.includes( neverUid ), JSON.stringify( neverIds ) );
		t.check( 'REST never excludes active user', ! neverIds.includes( activeUid ), JSON.stringify( neverIds ) );

		// UI: tab first (keeps chrome), then search; wait for the row itself
		// rather than fixed sleeps (tab+search races made 700ms flaky).
		const filterTo = async ( session, query, uid ) => {
			await page.click( `[data-usess="${ session }"]` );
			await waitTable();
			await page.fill( '#minn-user-search', query );
			await page.waitForSelector( `[data-user="${ uid }"]`, { timeout: 12000 } );
		};

		await filterTo( 'active', `Sess active ${ stamp }`, activeUid );
		t.check( 'Active tab becomes active', await page.$eval( '[data-usess="active"]', ( el ) => el.classList.contains( 'active' ) ), '' );
		t.check( 'Active filter UI shows seeded active user', !! ( await page.$( `[data-user="${ activeUid }"]` ) ), String( activeUid ) );

		await filterTo( 'expired', `Sess expired ${ stamp }`, expiredUid );
		t.check( 'Expired filter UI shows seeded expired user', !! ( await page.$( `[data-user="${ expiredUid }"]` ) ), String( expiredUid ) );

		await filterTo( 'never', `Sess never ${ stamp }`, neverUid );
		t.check( 'Never filter UI shows never-signed-in user', !! ( await page.$( `[data-user="${ neverUid }"]` ) ), String( neverUid ) );
	} finally {
		for ( const uid of [ activeUid, expiredUid, neverUid ] ) {
			if ( ! uid ) continue;
			await rest( 'minn-admin/v1/minn-test/seed-sessions', { method: 'POST', body: JSON.stringify( { uid, mode: 'clear' } ) } ).catch( () => {} );
			await rest( `wp/v2/users/${ uid }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
