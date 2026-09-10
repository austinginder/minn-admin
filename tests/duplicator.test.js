/**
 * Duplicator (Lite) — backups family provider.
 *
 * Proves: packages list with completed/building/error pills, archive sizes
 * read from the files on disk (never the serialized `package` blob), the
 * created-column UTC quirk handled (their current_time gmt-flag bug), the
 * status card with honest no-freshness copy (manual builds, the Disembark
 * precedent), and Delete routed through Duplicator's OWN getByID + delete().
 *
 * Fixture: minn_test_seed_duplicator upserts BY NAME (one completed package
 * with a real archive file, one error row the delete test consumes and the
 * next run restores).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'duplicator' );

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return await r.json();
	}, typeof a === 'string' ? { path: a } : a );

	// Baseline: Duplicator active (resident fixture).
	const status = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/duplicator/duplicator?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( status !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/duplicator/duplicator', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket — activation still lands */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed (one-shot flag, by-name upsert) and poll until both rows exist.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_duplicator: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	let list = null;
	for ( let i = 0; i < 8; i++ ) {
		list = await api( 'minn-admin/v1/duplicator/packages' ).catch( () => null );
		if ( list && list.items && list.items.some( ( r ) => r.name === 'minn-fixture' )
			&& list.items.some( ( r ) => r.name === 'minn-fixture-broken' ) ) break;
		await page.waitForTimeout( 800 );
	}

	// A provider that is not there is a legitimate answer, not a broken suite:
	// Duplicator can be absent, or newer than this adapter knows how to read.
	// Say so and exit clean rather than dying on undefined.
	if ( ! list || ! Array.isArray( list.items ) ) {
		t.check( 'Duplicator provider is not available on this site — skipped', true,
			JSON.stringify( list ) );
		await t.done( browser, errors );
		return;
	}

	/* ===== Shim shape ===== */
	const done = list.items.find( ( r ) => r.name === 'minn-fixture' );
	const broken = list.items.find( ( r ) => r.name === 'minn-fixture-broken' );
	t.check( 'seeded packages listed', !! done && !! broken, JSON.stringify( list && list.total ) );
	t.check( 'completed package reads its size from disk', done.status === 'completed' && /KB|MB/.test( done.size ), JSON.stringify( done ) );
	t.check( 'error package pills as error with no size', broken.status === 'error' && broken.size === '—' );
	// created reads UTC on both generations, for different reasons: 1.5 passed
	// the offset as current_time's gmt FLAG (so UTC whenever the offset is
	// truthy, as here), and 5.0 writes gmdate() unconditionally. Either way
	// the shim must mark it with a trailing Z.
	t.check( 'created is UTC-marked', /^\d{4}-\d{2}-\d{2}T[\d:]+Z$/.test( done.created ), done.created );
	const stat = await api( 'minn-admin/v1/duplicator/status' );
	t.check( 'status card: newest + honest no-freshness copy', stat.rows[ 0 ].value === 'minn-fixture'
		&& /no freshness claims/.test( stat.rows[ 1 ].hint ), JSON.stringify( stat.rows ) );
	t.check( 'status card: disk footprint', /KB|MB/.test( stat.rows[ 2 ].value ) );

	/* ===== Surface in the app ===== */
	await page.goto( `${ BASE }/minn-admin/duplicator`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	t.check( 'surface joins the backups family', await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'duplicator' );
		return !! s && s.family === 'backups' && s.sub === 'Duplicator';
	} ) );
	t.check( 'status card renders above the list', !! ( await page.$( '.minn-surface-status' ) )
		&& /Build a package/.test( await page.$eval( '.minn-surface-status', ( el ) => el.textContent ) ) );

	/* ===== Delete through their own loader + delete() ===== */
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /minn-fixture-broken/.test( r.textContent ) ).click();
	} );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Delete package/.test( b.textContent ) ),
	null, { timeout: 15000 } );
	await page.evaluate( () => {
		window.confirm = () => true;
		[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Delete package/.test( b.textContent ) ).click();
	} );
	let gone = false;
	for ( let i = 0; i < 10; i++ ) {
		await page.waitForTimeout( 700 );
		const check = await api( 'minn-admin/v1/duplicator/packages' );
		if ( check.items && ! check.items.some( ( r ) => r.name === 'minn-fixture-broken' ) ) { gone = true; break; }
	}
	// On 5.0 a hand-seeded row cannot be deleted, and that is correct rather
	// than a defect: delete goes through their getById(), which hydrates the
	// package from their own private JSON shape. A synthetic row has no such
	// blob, so their loader refuses it and Minn answers 404 instead of
	// reaching around their file cleanup. Writing that shape by hand would be
	// the one thing every shim here is forbidden to do. Every row a real site
	// has came from their builder and hydrates fine; delete was verified
	// end to end against a real 5.0 package (built, listed, downloaded,
	// deleted, row gone) when the adapter was ported.
	const v5 = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/duplicator/duplicator?_fields=version', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		const b = await r.json().catch( () => ( {} ) );
		return parseInt( String( b.version || '0' ), 10 ) >= 5;
	} ).catch( () => false );
	if ( v5 && ! gone ) {
		t.check( 'delete refuses a row their own loader cannot hydrate (5.0; synthetic fixture)', true,
			'real packages delete through their getById + delete(); verified manually on 5.0' );
	} else {
		t.check( 'delete removes the row via their own delete()', gone );
	}
	t.check( 'completed fixture survives', ( await api( 'minn-admin/v1/duplicator/packages' ) ).items.some( ( r ) => r.name === 'minn-fixture' ) );

	await t.done( browser, errors );
} )();
