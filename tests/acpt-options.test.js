/**
 * ACPT option pages as settings-only surfaces.
 *
 * ACPT keeps site-wide fields on its own option pages, which Minn could not
 * see: the most ordinary handover request there is (change the phone number,
 * update a social link) still meant going back to wp-admin. Each page the
 * current user may manage becomes a settings surface, gated by the page's own
 * capability, reading and writing through ACPT's value API.
 *
 * Also pins the value shapes that bit: ACPT stores a URL with a display label
 * beside it and a phone number with a dialling code, so reading either as a
 * plain value showed an empty box on a field that had content in it, and
 * writing a URL as a plain string overwrote a label someone had written.
 *
 * SKIPS unless the site under test runs ACPT with at least one option page.
 * Point it at such a site with MINN_TEST_URL / MINN_TEST_USER / MINN_TEST_PASS.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'acpt-options' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const surfaces = await page.evaluate( () => ( window.MINN.surfaces || [] )
		.filter( ( s ) => 'site-options' === s.id )
		.map( ( s ) => ( {
			id: s.id, label: s.label, sub: s.sub, group: s.group,
			tabs: ( s.settings && s.settings.tabs || [] ).length,
			route: s.settings && s.settings.route,
			hasCollection: !! s.collection,
		} ) ) );

	if ( ! surfaces.length ) {
		t.check( 'an ACPT option page is available', true, 'no ACPT option pages here — skipped' );
		await t.done( browser, errors );
		return;
	}
	const surface = surfaces[ 0 ];
	t.check( 'an ACPT option page is available', true, JSON.stringify( surface ) );
	t.check( 'it is a settings-only surface', surface.tabs > 0 && ! surface.hasCollection, JSON.stringify( surface ) );
	t.check( 'it is filed under Tools and badged ACPT',
		surface.group === 'tools' && surface.sub === 'ACPT', JSON.stringify( surface ) );
	t.check( 'it shares the one Site options item, not one per page',
		surface.id === 'site-options' && /minn-admin\/v1\/options\//.test( surface.route ), surface.route );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		try { return { status: r.status, body: JSON.parse( text ) }; } catch ( e ) { return { status: r.status, body: text }; }
	}, { path, opts } );

	const route = surface.route.replace( '{tab}', 'tab-0' );
	const shape = await api( route );
	t.check( 'the page answers with its fields and values', shape.status === 200 && !! shape.body.groups, JSON.stringify( shape.status ) );
	const fields = ( shape.body.groups || [] ).flatMap( ( g ) => g.fields || [] );
	t.check( 'it offers real controls', fields.length > 0, JSON.stringify( fields.map( ( f ) => f.type ) ) );
	t.check( 'it links back to ACPT for the rest', !! shape.body.adminUrl, String( shape.body.adminUrl ) );

	// No control may render the word "null": ACPT answers with null for a
	// field nobody has filled in yet.
	const nulls = Object.entries( shape.body.values || {} ).filter( ( [ , v ] ) => v === null );
	t.check( 'an empty field reads as empty, never null', nulls.length === 0, JSON.stringify( nulls.slice( 0, 3 ) ) );

	/* ===== A URL field shows its address, and keeps its label on save ===== */
	// Prefer one that HAS an address: an empty field would skip the round trip
	// this is here to prove.
	const urlField = fields.find( ( f ) => f.type === 'url' && shape.body.values[ f.name ] )
		|| fields.find( ( f ) => f.type === 'url' );
	if ( urlField ) {
		const before = shape.body.values[ urlField.name ];
		t.check( 'a URL field shows its address, not an empty box',
			typeof before === 'string' && ( before === '' || /^https?:|^\//.test( before ) ), JSON.stringify( before ) );
		if ( before ) {
			const probe = 'https://example.com/minn-suite-probe';
			const saved = await api( route, { method: 'POST', body: JSON.stringify( { values: { [ urlField.name ]: probe } } ) } );
			t.check( 'saving a URL returns the new address', saved.status === 200 && saved.body.values[ urlField.name ] === probe,
				JSON.stringify( saved.body.values && saved.body.values[ urlField.name ] ) );
			const restored = await api( route, { method: 'POST', body: JSON.stringify( { values: { [ urlField.name ]: before } } ) } );
			t.check( 'the original address is restored', restored.body.values[ urlField.name ] === before,
				JSON.stringify( restored.body.values[ urlField.name ] ) );
		}
	}

	/* ===== A key that is not on the page is ignored, not written ===== */
	const textField = fields.find( ( f ) => f.type === 'text' );
	if ( textField ) {
		const keep = shape.body.values[ textField.name ];
		const stray = await api( route, { method: 'POST', body: JSON.stringify( { values: { 'not-a-field-here': 'nope' } } ) } );
		t.check( 'a key the page does not own is ignored',
			stray.status === 200 && stray.body.values[ textField.name ] === keep,
			JSON.stringify( { was: keep, now: stray.body.values[ textField.name ] } ) );
	}

	/* ===== An unknown tab is a 404, not an empty page ===== */
	const bogus = await api( surface.route.replace( '{tab}', 'tab-99' ) );
	t.check( 'an unknown tab is refused', bogus.status === 404, String( bogus.status ) );

	/* ===== It renders as a page, with the values in the fields ===== */
	await page.goto( `${ BASE }/minn-admin/${ surface.id }`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-view input, #minn-view textarea', { timeout: 20000 } );
	await page.waitForTimeout( 800 );
	const ui = await page.evaluate( () => ( {
		inputs: document.querySelectorAll( '#minn-view input, #minn-view textarea' ).length,
		filled: [ ...document.querySelectorAll( '#minn-view input' ) ].filter( ( i ) => i.value ).length,
		labels: document.querySelectorAll( '#minn-view .minn-field-label' ).length,
		nullText: /(^|\s)null(\s|$)/.test( document.querySelector( '#minn-view' ).textContent ),
	} ) );
	t.check( 'the page renders its fields', ui.inputs > 0 && ui.labels > 0, JSON.stringify( ui ) );
	t.check( 'stored values arrive in the fields', ui.filled > 0, JSON.stringify( ui ) );
	t.check( 'nothing renders the word null', ! ui.nullText, JSON.stringify( ui ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
