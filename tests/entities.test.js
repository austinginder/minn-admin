/**
 * Issue #11: HTML character references must be decoded before display.
 * Two classes: (a) core's WP_Locale swaps a space thousands separator for
 * the literal string `&nbsp;` (space-separated locales: pl, cs, fr, ru…),
 * so server-formatted numbers used to show the raw entity; (b) registered
 * CPT/taxonomy labels legitimately carry entities (&#039; etc — translated
 * labels ship that way) and used to render un-decoded. The mu-fixture
 * option minn_test_entities arms both: an entity-labeled CPT + taxonomy
 * and an `&nbsp;` thousands separator.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'entities' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-scroll', { timeout: 15000 } );

	const rest = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, path );

	// Write-then-verify with retries (rule-47c: a REST settings write can
	// race the app's boot requests and read back stale).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_entities: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_entities;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	try {
		t.check( 'entities fixture armed', await setOpt( '1' ), '' );

		/* ===== Server: labels decode at the Minn endpoints ===== */
		const pt = await rest( 'minn-admin/v1/post-types' );
		const fixType = ( ( pt.body && pt.body.types ) || [] ).find( ( x ) => x.slug === 'minn_ent_type' );
		t.check( 'post-types plural label is decoded', !! fixType && fixType.plural === "Fixture's Templates", JSON.stringify( fixType && fixType.plural ) );
		t.check( 'post-types singular label is decoded', !! fixType && fixType.singular === "Fixture's Template", JSON.stringify( fixType && fixType.singular ) );
		const cat = ( ( pt.body && pt.body.taxCatalog ) || [] ).find( ( x ) => x.slug === 'minn_ent_tag' );
		t.check( 'tax catalog label is decoded', !! cat && cat.label === "Fixture's Tags", JSON.stringify( cat && cat.label ) );

		const tx = await rest( 'minn-admin/v1/taxonomies' );
		const fixTax = ( ( tx.body && tx.body.taxonomies ) || [] ).find( ( x ) => x.slug === 'minn_ent_tag' );
		t.check( 'taxonomies labels are decoded', !! fixTax && fixTax.plural === "Fixture's Tags" && fixTax.singular === "Fixture's Tag", JSON.stringify( fixTax && [ fixTax.plural, fixTax.singular ] ) );

		const tt = await rest( 'minn-admin/v1/term-taxonomies' );
		const fixTT = ( Array.isArray( tt.body ) ? tt.body : [] ).find( ( x ) => x.slug === 'minn_ent_tag' );
		t.check( 'term-taxonomies label is decoded', !! fixTT && fixTT.label === "Fixture's Tags", JSON.stringify( fixTT && fixTT.label ) );

		/* ===== Server: numbers carry a real NBSP, never the entity ===== */
		const sys = await rest( 'minn-admin/v1/system' );
		const sysJson = JSON.stringify( sys.body || {} );
		t.check( 'system response has no raw &nbsp;', sys.status === 200 && ! sysJson.includes( '&nbsp;' ), '' );
		const tables = ( ( sys.body && sys.body.groups ) || [] ).flatMap( ( g ) => g.tables || [] );
		const bigRow = tables.map( ( x ) => String( x.rows || '' ) ).find( ( v ) => /\d\u00A0\d{3}/.test( v ) );
		t.check( 'large table counts use a real non-breaking space', !! bigRow, JSON.stringify( tables.map( ( x ) => x.rows ) ) );

		/* ===== UI: Structure page renders decoded labels ===== */
		await page.goto( `${ BASE }/minn-admin/posttypes`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => document.body.textContent.includes( 'Fixture' ), { timeout: 15000 } );
		let text = await page.evaluate( () => document.querySelector( '#minn-view' ).textContent );
		t.check( 'Post Types list shows the decoded label', text.includes( "Fixture's Templates" ) && ! text.includes( '&#039;' ), '' );

		await page.click( '[data-structtab="taxonomies"]' );
		await page.waitForFunction( () => document.querySelector( '#minn-view' ).textContent.includes( "Fixture's Tags" ), { timeout: 15000 } );
		text = await page.evaluate( () => document.querySelector( '#minn-view' ).textContent );
		t.check( 'Taxonomies tab shows the decoded label', text.includes( "Fixture's Tags" ) && ! text.includes( '&#039;' ), '' );

		/* ===== UI: content type switcher (wp/v2/types client decode) ===== */
		await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-typecombo] .minn-ac-input', { timeout: 15000 } );
		await page.click( '[data-typecombo] .minn-ac-input' );
		await page.waitForSelector( '[data-typecombo] .minn-ac-panel:not([hidden])', { timeout: 10000 } );
		const items = await page.$$eval( '[data-typecombo] .minn-ac-item', ( o ) => o.map( ( e ) => e.textContent.trim() ) );
		t.check( 'content switcher lists the decoded type label', items.includes( "Fixture's Templates" ) && ! items.some( ( x ) => x.includes( '&#039;' ) ), JSON.stringify( items ) );
	} finally {
		await setOpt( '' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
