/**
 * Site options: one sidebar item for every plugin's option pages.
 *
 * ACF, ACPT and anything else that keeps site-wide fields used to claim their
 * own navigation entries, so a site running two of them grew two or three
 * items describing the same kind of thing. They gather under one item now,
 * each page a tab, contributed through `minn_admin_option_pages`.
 *
 * The fixture provider stands in for a theme or plugin: it proves the door is
 * open to code Minn does not bundle, which is the point of the filter.
 */
const { BASE, WP, launch, login, reporter } = require( './helpers' );
const { execFileSync } = require( 'child_process' );

const wp = ( args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8' } ).trim();
const surfaces = ( page ) => page.evaluate( () => ( window.MINN.surfaces || [] )
	.filter( ( s ) => 'site-options' === s.id || /options/.test( s.id ) )
	.map( ( s ) => ( {
		id: s.id, label: s.label, sub: s.sub, group: s.group,
		tabs: ( s.settings && s.settings.tabs || [] ).map( ( t ) => t.label ),
		route: s.settings && s.settings.route,
	} ) ) );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'site-options' );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		try { return { status: r.status, body: JSON.parse( text ) }; } catch ( e ) { return { status: r.status, body: text }; }
	}, { path, opts } );

	const reload = async () => {
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
		await page.waitForTimeout( 400 );
	};

	try {
		/* ===== Before: whatever this site's bundled providers offer ===== */
		await reload();
		const before = await surfaces( page );
		const beforeTabs = before.length ? before[ 0 ].tabs.length : 0;

		/* ===== A third party adds a page ===== */
		wp( [ 'option', 'update', 'minn_test_option_page', '1' ] );
		await reload();
		const after = await surfaces( page );
		t.check( 'a contributed page reaches the sidebar', after.length === 1 && after[ 0 ].id === 'site-options',
			JSON.stringify( after.map( ( s ) => s.id ) ) );
		const surface = after[ 0 ];
		t.check( 'every options page shares ONE item', after.length === 1, JSON.stringify( after.map( ( s ) => s.id ) ) );
		t.check( 'the contributed page appears as a tab', surface.tabs.includes( 'Brand kit' ), JSON.stringify( surface.tabs ) );
		t.check( 'it did not displace what was already there', surface.tabs.length === beforeTabs + 1,
			JSON.stringify( { beforeTabs, now: surface.tabs } ) );
		t.check( 'the item is filed under Tools', surface.group === 'tools', String( surface.group ) );
		t.check( 'the shared route is Minn\'s own', surface.route === 'minn-admin/v1/options/{tab}', String( surface.route ) );

		/* ===== Its tab reads and writes through its own route ===== */
		const idx = surface.tabs.indexOf( 'Brand kit' );
		const route = `minn-admin/v1/options/tab-${ idx }`;
		const shape = await api( route );
		t.check( 'the contributed tab answers with its fields', shape.status === 200
			&& ( shape.body.groups || [] ).flatMap( ( g ) => g.fields || [] ).length === 2, JSON.stringify( shape.status ) );

		const probe = 'Built by hand ' + Date.now();
		const saved = await api( route, { method: 'POST', body: JSON.stringify( { values: { tagline: probe } } ) } );
		t.check( 'saving reaches the provider', saved.status === 200 && saved.body.values.tagline === probe,
			JSON.stringify( saved.body.values ) );
		// Read the provider's own storage, not Minn's answer about it.
		const stored = wp( [ 'eval', "echo (string) ( (array) get_option('minn_fixture_brand', array()) )['tagline'] ?? '';" ] );
		t.check( 'the provider really stored it', stored === probe, `${ stored } vs ${ probe }` );

		/* ===== A tab nobody contributed is not found ===== */
		const bogus = await api( 'minn-admin/v1/options/tab-97' );
		t.check( 'an unknown tab is not found', bogus.status === 404, String( bogus.status ) );

		/* ===== It renders, and the provider's values arrive in the fields ===== */
		await page.goto( `${ BASE }/minn-admin/site-options`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view input', { timeout: 20000 } );
		await page.waitForTimeout( 700 );
		const rendered = await page.evaluate( ( label ) => {
			const tabs = [ ...document.querySelectorAll( '.minn-tab, [data-stab]' ) ].map( ( b ) => b.textContent.trim() );
			return { tabs, hasTab: tabs.some( ( x ) => x.includes( label ) ), inputs: document.querySelectorAll( '#minn-view input' ).length };
		}, 'Brand kit' );
		t.check( 'the page renders its tabs', rendered.inputs > 0, JSON.stringify( rendered ) );
		if ( rendered.tabs.length ) {
			t.check( 'the contributed page is one of the tabs on screen', rendered.hasTab, JSON.stringify( rendered.tabs ) );
		}
	} finally {
		wp( [ 'option', 'update', 'minn_test_option_page', '' ] );
		wp( [ 'option', 'delete', 'minn_fixture_brand' ] );
	}

	/* ===== Removing the provider removes its tab ===== */
	await reload();
	const restored = await surfaces( page );
	t.check( 'withdrawing a page withdraws its tab',
		! restored.length || ! restored[ 0 ].tabs.includes( 'Brand kit' ),
		JSON.stringify( restored.map( ( s ) => s.tabs ) ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
