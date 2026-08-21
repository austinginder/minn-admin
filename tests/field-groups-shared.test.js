/**
 * Field groups: one item, a view per plugin.
 *
 * ACF and ACPT both describe the same idea, a named group of fields attached
 * to something, and each used to claim its own sidebar entry. They share one
 * Field groups item now, contributed through `minn_admin_field_group_sources`.
 *
 * A view rather than one mixed list, because a row's verbs differ: ACF groups
 * edit in Minn's own builder, while ACPT's schema builder is a canvas Minn
 * does not reimplement, so its rows list and link out.
 *
 * Runs against whatever the site has: one provider means one view and no
 * switcher, two means two tabs. SKIPS when neither is installed.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'field-groups-shared' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const all = await page.evaluate( () => ( window.MINN.surfaces || [] )
		.filter( ( s ) => /field-groups/.test( s.id ) )
		.map( ( s ) => ( {
			id: s.id, label: s.label, sub: s.sub,
			main: s.collection && s.collection.viewLabel,
			mainRoute: s.collection && s.collection.route,
			views: ( s.views || [] ).map( ( v ) => ( { label: v.viewLabel, route: v.route } ) ),
		} ) ) );

	if ( ! all.length ) {
		t.check( 'a field-group provider is installed', true, 'neither ACF nor ACPT here — skipped' );
		await t.done( browser, errors );
		return;
	}

	t.check( 'field groups register exactly one item', all.length === 1, JSON.stringify( all.map( ( s ) => s.id ) ) );
	const surface = all[ 0 ];
	t.check( 'the item is the shared one', surface.id === 'field-groups', surface.id );
	// No plugin gets a surface of its own any more.
	t.check( 'no provider keeps a separate item',
		! all.some( ( s ) => /^(acf|acpt)-field-groups/.test( s.id ) ), JSON.stringify( all.map( ( s ) => s.id ) ) );

	const names = [ surface.main, ...surface.views.map( ( v ) => v.label ) ];
	// Each provider's own list is named for the plugin. A provider may bring
	// further lists of its own (ACF ships a Fields view beside its Groups
	// list); those keep their own names and are not provider tabs.
	const providerTabs = names.filter( ( n ) => /^(ACF|ACPT)$/.test( String( n ) ) );
	const viewCount = providerTabs.length;
	t.check( 'each provider contributes a view named for it',
		viewCount >= 1 && providerTabs.length === new Set( providerTabs ).size, JSON.stringify( names ) );
	// One provider needs no switcher, and says whose groups these are on the
	// item itself instead.
	t.check( 'one provider badges the item, several do not',
		viewCount > 1 ? surface.sub === '' : !! surface.sub, JSON.stringify( { viewCount, sub: surface.sub } ) );

	/* ===== Every view's route answers ===== */
	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		const text = await r.text();
		try { return { status: r.status, body: JSON.parse( text ) }; } catch ( e ) { return { status: r.status, body: text }; }
	}, path );

	// A templated route (ACF's per-group Fields list) needs a tab to answer;
	// its own suite covers that. Here, check the ones that stand alone.
	const listRoutes = [ surface.mainRoute, ...surface.views.map( ( v ) => v.route ) ]
		.filter( ( r ) => r && ! /\{/.test( r ) );
	for ( const route of listRoutes ) {
		const res = await api( route );
		const items = res.body && ( res.body.items || res.body.groups || ( Array.isArray( res.body ) ? res.body : null ) );
		t.check( `the ${ route.includes( 'acpt' ) ? 'ACPT' : 'ACF' } view lists its groups`,
			res.status === 200 && Array.isArray( items ), JSON.stringify( { route, status: res.status } ) );
	}

	/* ===== The ACPT view describes a group without pretending to edit it ===== */
	const acptRoute = [ surface.mainRoute, ...surface.views.map( ( v ) => v.route ) ].find( ( r ) => r.includes( 'acpt' ) );
	if ( acptRoute ) {
		const res = await api( acptRoute );
		const first = ( res.body.items || [] )[ 0 ];
		t.check( 'an ACPT row names the group and where it applies',
			!! first && !! first.name && 'applies' in first, JSON.stringify( first ) );
		t.check( 'an ACPT row counts its boxes and fields',
			!! first && typeof first.boxes === 'number' && typeof first.fields === 'number', JSON.stringify( first ) );
		t.check( 'an ACPT row points at ACPT for editing',
			!! first && /page=advanced-custom-post-type/.test( String( first.editUrl ) ), String( first && first.editUrl ) );
	}

	/* ===== It renders, with a switcher only when there is a choice ===== */
	await page.goto( `${ BASE }/minn-admin/field-groups`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row, .minn-empty', { timeout: 30000 } );
	await page.waitForTimeout( 900 );
	const ui = await page.evaluate( () => ( {
		tabs: [ ...document.querySelectorAll( '[data-sview]' ) ].map( ( b ) => b.textContent.trim() ),
		rows: document.querySelectorAll( '.minn-table-row' ).length,
	} ) );
	t.check( 'the first view lists rows', ui.rows > 0, JSON.stringify( ui ) );
	if ( viewCount > 1 ) {
		t.check( 'the switcher offers every provider', ui.tabs.length >= viewCount, JSON.stringify( ui.tabs ) );
		// Switch to the last view and confirm it loads its own rows.
		await page.evaluate( () => {
			const tabs = [ ...document.querySelectorAll( '[data-sview]' ) ];
			tabs[ tabs.length - 1 ].click();
		} );
		await page.waitForTimeout( 2500 );
		const second = await page.evaluate( () => document.querySelectorAll( '.minn-table-row' ).length );
		t.check( 'switching views loads that provider\'s groups', second > 0, String( second ) );
	} else {
		t.check( 'a single provider needs no switcher', ui.tabs.length === 0, JSON.stringify( ui.tabs ) );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
