/**
 * A post type the owner created that WordPress will not serve over REST.
 *
 * This is the shape behind "my custom post types don't show up in Minn".
 * CPT UI's "Show in REST API" select opens with nothing chosen and registers
 * an untouched value as FALSE, while its own help text says the default is
 * true — so a type made without thinking about that field works normally in
 * wp-admin and is invisible to every REST client, Minn and the block editor
 * alike. Minn cannot list what WordPress will not serve; what it CAN do is
 * say so instead of silently omitting it.
 *
 * Fixtures (standing on minnadmin, seeded in CPT UI's own option shape):
 *   minn_untouched "Case Studies"  — show_in_rest untouched → hidden
 *   minn_rest_on   "Field Reports" — show_in_rest on        → listed
 *
 * Without a hidden type on the site the health half SKIPs; the control half
 * (a REST-visible CPT UI type really does reach Content) still runs.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'cpt-hidden-rest' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const types = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/post-types', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( ( await r.json() ).types || [] );
	} );
	const hidden = types.filter( ( x ) => ! x.show_in_rest && [ 'cptui', 'acf', 'minn' ].indexOf( x.source ) !== -1 );
	const shown = types.filter( ( x ) => x.show_in_rest && 'cptui' === x.source );

	// Control: a REST-visible CPT UI type is a first-class content type. If
	// this ever fails, the report really IS about Minn rather than the flag.
	if ( shown.length ) {
		const inRest = await page.evaluate( async ( slug ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/types', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return !! ( await r.json() )[ slug ];
		}, shown[ 0 ].slug );
		t.check( 'a REST-visible CPT UI type reaches Minn', inRest, shown[ 0 ].slug );
	}

	if ( ! hidden.length ) {
		console.log( 'note: no REST-hidden post type on this site — health half skipped' );
	} else {
		// The health check names the type, because "1 post type is hidden"
		// sends people hunting through wp-admin.
		const check = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( ( await r.json() ).checks || [] ).find( ( c ) => c.key === 'cpt_rest' ) || null;
		} );
		t.check( 'the health check warns', !! check && check.status === 'warn', JSON.stringify( check ) );
		t.check( 'it names the hidden type', !! check && check.detail.includes( hidden[ 0 ].plural ), check && check.detail );
		t.check( 'it points at the post types manager', !! check && check.goto === 'posttypes', check && check.goto );

		// The type is genuinely absent from what Content can offer — the
		// symptom being explained.
		const inRest = await page.evaluate( async ( slug ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/types', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return !! ( await r.json() )[ slug ];
		}, hidden[ 0 ].slug );
		t.check( 'the hidden type really is absent from wp/v2/types', ! inRest, hidden[ 0 ].slug );

		// The card is a doorway, and the row it lands on says what is wrong.
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sys-check', { timeout: 25000 } );
		await page.waitForTimeout( 800 );
		const clicked = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-sys-check' ) ]
				.find( ( c ) => /Post types in Minn/.test( c.textContent ) );
			if ( ! el ) return false;
			el.click();
			return true;
		} );
		t.check( 'the health card is clickable', clicked );
		await page.waitForFunction( () => /\/posttypes/.test( location.pathname ), null, { timeout: 30000 } );
		t.check( 'it lands on the post types manager', /\/posttypes/.test( page.url() ), page.url() );
		await page.waitForFunction( ( plural ) => [ ...document.querySelectorAll( '.minn-table-row' ) ]
			.some( ( row ) => row.textContent.includes( plural ) ), hidden[ 0 ].plural, { timeout: 30000 } );

		const row = await page.evaluate( ( plural ) => {
			const r = [ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( x ) => x.textContent.includes( plural ) );
			return r ? r.textContent.replace( /\s+/g, ' ' ).trim() : '';
		}, hidden[ 0 ].plural );
		t.check( 'the row is marked hidden, not just dashed', /Hidden from Minn/i.test( row ), row );
	}

	t.done( browser, errors );
} )();
