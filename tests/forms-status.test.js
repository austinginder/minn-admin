/**
 * Forms family status cards (SureForms parity batch, 2026-08-06):
 * Fluent Forms, Ninja Forms, Forminator, CF7/Flamingo, Everest, WPForms,
 * Elementor, Formidable, CFDB7. REST shape for every card + the card
 * rendering on one live surface. All are active fixtures on minnadmin;
 * counts are live so checks assert shape, never absolute numbers.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'forms-status' );

	await login( page );

	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, path );

	const CARDS = [
		{ slug: 'fluent-forms', first: 'Unread entries', open: /fluent_forms_all_entries/ },
		{ slug: 'ninja-forms', first: 'Submissions', open: /nf-submissions/ },
		{ slug: 'forminator', first: 'Entries', open: /forminator-entries/ },
		{ slug: 'cf7', first: 'Inbox messages', open: /flamingo_inbound/ },
		{ slug: 'everest', first: 'Unread entries', open: /evf-entries/ },
		{ slug: 'wpforms', first: 'Unread entries', open: /wpforms-entries/ },
		{ slug: 'elementor', first: 'Unread entries', open: /e-form-submissions/ },
		{ slug: 'formidable', first: 'Entries', open: /formidable-entries/ },
		{ slug: 'cfdb7', first: 'Unread entries', open: /cfdb7-list/ },
	];

	for ( const c of CARDS ) {
		const r = await api( 'minn-admin/v1/' + c.slug + '/status' );
		const rows = ( r.body && r.body.rows ) || [];
		const forms = rows.find( ( x ) => x.label === 'Forms' );
		t.check( c.slug + ' status card shape',
			r.status === 200 && rows.length >= 2 && rows[ 0 ].label === c.first && !! forms,
			JSON.stringify( { status: r.status, rows: rows.map( ( x ) => x.label ) } ) );
		const href = ( ( r.body && r.body.actions ) || [] )
			.map( ( a ) => a.href || '' ).find( ( h ) => c.open.test( h ) );
		t.check( c.slug + ' card links to the plugin screen', !! href, JSON.stringify( r.body && r.body.actions ) );
		// Every card charts the last 14 site-local days; a spam series rides
		// only where the plugin tracks spam.
		const ch = r.body && r.body.chart;
		t.check( c.slug + ' card charts the last 14 days',
			!! ch && ch.title === 'Last 14 days' && Array.isArray( ch.points ) && ch.points.length === 14
			&& ch.points.every( ( pt ) => /^\d{4}-\d{2}-\d{2}$/.test( pt.label ) && Number.isInteger( pt.value ) ),
			JSON.stringify( ch && { title: ch.title, primary: ch.primary, secondary: ch.secondary, n: ( ch.points || [] ).length } ) );
	}

	// The card renders above one live forms surface.
	await page.evaluate( () => localStorage.setItem( 'minn-sf-forms', 'fluent-forms' ) );
	await page.goto( BASE + '/minn-admin/fluent-forms', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	const cardText = await page.evaluate( () => document.body.textContent || '' );
	t.check( 'Fluent Forms surface renders the card',
		/Unread entries/.test( cardText ) && /Open Fluent Forms/.test( cardText ), '' );

	await t.done( browser, errors );
} )();
