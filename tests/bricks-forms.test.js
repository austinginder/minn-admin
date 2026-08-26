/**
 * Bricks form submissions in the forms family (adapters/bricks.php).
 *
 * The surface exists only while Bricks is the active theme, its
 * saveFormSubmissions setting is on and the submissions table exists, so on
 * minnadmin this SKIPs (exit 0). Run it for real against the builders lab:
 *
 *   MINN_TEST_URL=https://builders.localhost MINN_TEST_USER=admin \
 *   MINN_TEST_PASS=minn-builders-test-1 \
 *   MINN_TEST_WP=/Users/austin/Cove/Sites/builders.localhost/public \
 *   node bricks-forms.test.js
 *
 * Standing fixtures: page "Minn Contact" carries form element `mnform`
 * (Name/Email/Message) and three seeded submissions (Dana, Miguel, Priya) —
 * the suite must not delete them. It seeds one disposable submission through
 * Bricks' own insert path and deletes exactly that one through the UI.
 */
const { execFileSync } = require( 'child_process' );
const { launch, login, reporter, BASE, WP } = require( './helpers' );

const wpEval = ( code ) => execFileSync( 'wp', [ '--path=' + WP, 'eval', code ], { encoding: 'utf8' } ).trim();

( async () => {
	const t = reporter( 'bricks-forms' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 20000 } );

	const surface = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'bricks-forms' ) || null );
	if ( ! surface ) {
		console.log( 'SKIP: no Bricks forms surface (needs Bricks active + saveFormSubmissions on — run against builders.localhost)' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path + ( a.path.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );

	try {
		t.check( 'surface joins the forms family in Workspace', surface.family === 'forms' && surface.group === 'workspace',
			surface.family + '/' + surface.group );

		/* ===== List + status card render the standing fixtures ===== */
		await page.goto( BASE + '/minn-admin/bricks-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Dana Tester' ) ),
		null, { timeout: 20000 } );
		const danaRow = await page.evaluate( () =>
			( Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).find( ( r ) => r.textContent.includes( 'Dana Tester' ) ) || { textContent: '' } ).textContent );
		t.check( 'submission row names its form', /Contact form/.test( danaRow ), danaRow.slice( 0, 140 ) );
		const statusText = await page.evaluate( () =>
			( document.querySelector( '.minn-surface-status, .minn-status-card' ) || { textContent: '' } ).textContent );
		t.check( 'status card reports submissions and forms', /Submissions/.test( statusText ) && /Forms/.test( statusText ), statusText.slice( 0, 120 ) );

		/* ===== Per-form tab from the grouped-forms route ===== */
		const tabbed = await page.evaluate( () => {
			const pill = document.querySelector( '[data-stab="mnform"]' );
			if ( pill ) {
				pill.click();
				return 'pill';
			}
			return document.querySelector( '[data-stabcombo]' ) ? 'combo' : 'none';
		} );
		t.check( 'form tab renders from the forms route', tabbed !== 'none', tabbed );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Priya Shah' ) ),
		null, { timeout: 15000 } );

		/* ===== Detail: entry card with labels through their own resolver ===== */
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Dana Tester' ) ).click();
		} );
		await page.waitForSelector( '.minn-modal', { timeout: 15000 } );
		await page.waitForFunction( () =>
			( document.querySelector( '.minn-modal' ) || { textContent: '' } ).textContent.includes( 'dana@example.com' ),
		null, { timeout: 15000 } );
		const modalText = await page.evaluate( () => document.querySelector( '.minn-modal' ).textContent );
		t.check( 'answers carry the form field labels', /Message/.test( modalText ) && /kitchen remodel/.test( modalText ), modalText.slice( 0, 200 ) );
		t.check( 'submission meta reaches the card', /Browser|Chrome/i.test( modalText ), '' );
		await page.keyboard.press( 'Escape' );

		/* ===== Search rides the form_data LIKE ===== */
		const found = await rest( 'GET', 'minn-admin/v1/bricks/entries?search=kitchen' );
		t.check( 'search narrows to the matching submission', found.data.total === 1, String( found.data.total ) );

		/* ===== Delete exactly the disposable seeded row (confirm dialog) ===== */
		wpEval( `\\Bricks\\Integrations\\Form\\Submission_Database::insert_data( array(
			"post_id" => 0, "form_id" => "mnform",
			"form_data" => array(
				"fldnam" => array( "type" => "text", "value" => "Suite Disposable" ),
				"fldeml" => array( "type" => "email", "value" => "disposable@example.com" ),
			),
		) ); echo "seeded";` );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Disposable' ) ),
		null, { timeout: 20000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Disposable' ) ).click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-saction]' ) ).find( ( b ) => /Delete/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Disposable' ) ),
		null, { timeout: 15000 } );
		const after = await rest( 'GET', 'minn-admin/v1/bricks/entries?search=Disposable' );
		t.check( 'delete removes the seeded submission only', after.data.total === 0, String( after.data.total ) );
		const standing = await rest( 'GET', 'minn-admin/v1/bricks/entries' );
		t.check( 'standing fixtures survive', standing.data.total === 3, String( standing.data.total ) );
	} finally {
		// Sweep any disposable row a crashed run left behind.
		wpEval( 'global $wpdb; $t = \\Bricks\\Integrations\\Form\\Submission_Database::get_table_name(); $wpdb->query( "DELETE FROM {$t} WHERE form_data LIKE \'%Suite Disposable%\'" ); echo "clean";' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
