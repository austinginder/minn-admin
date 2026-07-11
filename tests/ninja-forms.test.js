/**
 * Ninja Forms — forms-family provider (entries + forms).
 *
 * Proves: nf_sub submissions list as entry cards (summary from field values
 * in form order, form title, seq, site-local dates), form tabs + cross-field
 * search via postmeta, a sectionsRoute detail with labeled answers +
 * submission meta + adminUrl, the Forms manage view with live entry counts
 * and the edit-in-Ninja-Forms link, and Trash routed through their own
 * Submission model (restore stays on their screen; the confirm says so).
 *
 * Fixture: minn_test_seed_nf seeds three submissions on the default
 * "Contact Me" form through Ninja Forms' OWN model, upserting per row (the
 * suite trashes Priya's entry; the next run restores exactly that one).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ninja-forms' );

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return await r.json();
	}, typeof a === 'string' ? { path: a } : a );

	// Baseline: Ninja Forms active (resident fixture).
	const status = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/ninja-forms/ninja-forms?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( status !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/ninja-forms/ninja-forms', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket — activation still lands */ }
		} );
		await page.waitForTimeout( 1500 );
	}

	// Seed (one-shot flag, per-row upsert) and poll for all three fixtures.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_nf: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	let list = null;
	for ( let i = 0; i < 8; i++ ) {
		list = await api( 'minn-admin/v1/ninja-forms/entries?form_id=1' ).catch( () => null );
		if ( list && list.items && [ 'dana@', 'miguel@', 'priya@' ].every( ( n ) =>
			list.items.some( ( r ) => r.summary.includes( n ) ) ) ) break;
		await page.waitForTimeout( 800 );
	}

	/* ===== Shim shape ===== */
	t.check( 'fixture entries listed for the form tab', !! list && list.total >= 3, JSON.stringify( list && list.total ) );
	const dana = list.items.find( ( r ) => r.summary.includes( 'dana@example.com' ) );
	t.check( 'entry cards carry summary/form/seq/local date',
		!! dana && /Dana Tester · dana@example.com/.test( dana.summary )
		&& dana.form_title === 'Contact Me' && dana.seq >= 1 && ! /Z$/.test( dana.date ),
		JSON.stringify( dana ) );
	const searched = await api( 'minn-admin/v1/ninja-forms/entries?search=' + encodeURIComponent( 'Puerto Rico' ) );
	t.check( 'search matches across field values', searched.total >= 1
		&& searched.items.every( ( r ) => r.summary.includes( 'miguel@example.com' ) ), JSON.stringify( searched.total ) );
	const detail = await api( 'minn-admin/v1/ninja-forms/entries/' + dana.id );
	t.check( 'detail sections carry labeled answers', detail.kind === 'entry'
		&& detail.sections[ 0 ].rows.some( ( r ) => r.label === 'Message' && /woodworking/.test( r.value ) ) );
	t.check( 'detail carries submission meta + adminUrl', detail.sections[ 1 ].rows.some( ( r ) => r.label === 'Form' && r.value === 'Contact Me' )
		&& /page=nf-submissions/.test( detail.adminUrl ) );
	const forms = await api( 'minn-admin/v1/ninja-forms/forms?manage=1' );
	t.check( 'forms manage rows carry live entry counts', Array.isArray( forms )
		&& forms.some( ( f ) => f.title === 'Contact Me' && f.entries >= 3 ), JSON.stringify( forms ) );

	/* ===== Surface in the app ===== */
	await page.goto( `${ BASE }/minn-admin/ninja-forms`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	t.check( 'surface joins the forms family in the workspace', await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'ninja-forms' );
		return !! s && s.family === 'forms' && s.group === 'workspace' && s.sub === 'Ninja Forms';
	} ) );
	t.check( 'form tabs render', await page.$$eval( '[data-stab]', ( els ) =>
		els.some( ( e ) => /Contact Me/.test( e.textContent ) ) ) );

	// Detail modal via the row.
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /dana@example\.com/.test( r.textContent ) ).click();
	} );
	await page.waitForFunction( () =>
		document.querySelector( '.minn-modal' ) && /Woodworking|woodworking/.test( document.querySelector( '.minn-modal' ).textContent ),
	null, { timeout: 15000 } );
	t.check( 'entry modal renders the labeled card', await page.evaluate( () =>
		/Message/.test( document.querySelector( '.minn-modal' ).textContent ) ) );
	await page.click( '#minn-modal-close' );

	// Manage view: Forms.
	await page.click( '[data-sview="manage"]' );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => /Contact Me/.test( r.textContent ) ),
	null, { timeout: 15000 } );
	t.check( 'Forms manage view lists the form', true );
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /Contact Me/.test( r.textContent ) ).click();
	} );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-modal a' ) ].some( ( x ) => /Edit in Ninja Forms/.test( x.textContent ) ),
	null, { timeout: 15000 } );
	t.check( 'form detail offers the builder escape hatch', await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-modal a' ) ]
			.find( ( x ) => /Edit in Ninja Forms/.test( x.textContent ) ).href.includes( 'page=ninja-forms&form_id=' ) ) );
	await page.click( '#minn-modal-close' );

	/* ===== Trash through their own model ===== */
	await page.click( '[data-sview="main"]' );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => /priya@example\.com/.test( r.textContent ) ),
	null, { timeout: 15000 } );
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /priya@example\.com/.test( r.textContent ) ).click();
	} );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Trash entry/.test( b.textContent ) ),
	null, { timeout: 15000 } );
	await page.evaluate( () => {
		window.confirm = () => true;
		[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Trash entry/.test( b.textContent ) ).click();
	} );
	let trashed = false;
	for ( let i = 0; i < 10; i++ ) {
		await page.waitForTimeout( 700 );
		const check = await api( 'minn-admin/v1/ninja-forms/entries?search=' + encodeURIComponent( 'priya@example.com' ) );
		if ( check.total === 0 ) { trashed = true; break; }
	}
	t.check( 'trash removes the entry from the received list', trashed );

	await t.done( browser, errors );
} )();
