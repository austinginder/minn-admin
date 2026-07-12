/**
 * Formidable adapter — forms family (entries + forms). Entries read from
 * frm_items via prefix-scoped SQL with answers hydrated through
 * FrmEntry::getOne (their model owns the serialized array shapes), labels
 * from FrmField at runtime, UTC created_at stamps, search over answer
 * meta, and permanent delete through FrmEntry::destroy (no entry trash;
 * the confirm says so). Caps mirror their permission model (granular cap
 * OR administrator).
 *
 * Fixtures: the standing "Survey Form" (key minn-survey) +
 * minn_test_seed_formidable (upsert by email through FrmEntry::create).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'formidable' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v || ( v === '1' && stored === '' ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const restTotal = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/formidable/entries?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).total;
	} );

	try {
		t.check( 'entry seeder armed', await setOpt( 'minn_test_seed_formidable', '1' ) );

		await page.goto( BASE + '/minn-admin/formidable', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

		/* ===== Entries list ===== */
		const body = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'seeded entries render with answer summaries', body.includes( 'Dana Tester' ) && body.includes( 'dana@example.com' ) );
		t.check( 'form column names the form', body.includes( 'Survey Form' ) );
		const tabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'form tabs render', tabs[ 0 ] === 'All entries' && tabs.includes( 'Survey Form' ), tabs.join( ' · ' ) );

		/* ===== Search over answer meta ===== */
		await page.fill( '#minn-surface-search', 'neutral' );
		await page.waitForFunction( () => {
			const rows = document.querySelectorAll( '.minn-table-row' );
			return rows.length === 1 && rows[ 0 ].textContent.includes( 'Miguel' );
		}, { timeout: 20000 } );
		t.check( 'search filters entries', true );
		await page.fill( '#minn-surface-search', '' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length >= 3, { timeout: 20000 } );

		/* ===== Detail: labels through their field model ===== */
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Priya' ) ).click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		const modal = await page.$eval( '.minn-modal', ( el ) => el.textContent );
		t.check( 'answers wear their field labels', modal.includes( 'Comments' ) && modal.includes( 'Submitting twice showed a duplicate warning.' ) );
		t.check( 'entry renders as a contact card', !! ( await page.$( '.minn-modal.entry' ) ) );
		t.check( 'card links out to Formidable', await page.$$eval( '.minn-modal a[href]', ( els ) =>
			els.some( ( a ) => /page=formidable-entries/.test( a.href ) ) ) );

		/* ===== Permanent delete through their own model ===== */
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ].find( ( b ) => b.textContent.includes( 'Delete permanently' ) ).click();
		} );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), { timeout: 15000 } );
		t.check( 'delete removed the entry (their destroy ran)', ( await restTotal() ) === 2 );

		/* ===== Forms view ===== */
		await page.click( '[data-sview="manage"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const formsBody = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'forms view lists the form with a live count', formsBody.includes( 'Survey Form' ) && formsBody.includes( '2' ) );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Survey Form' ) ).click();
		} );
		await page.waitForSelector( '.minn-modal a[href]', { timeout: 15000 } );
		t.check( 'form row links into Formidable\'s builder', await page.$$eval( '.minn-modal a[href]', ( els ) =>
			els.some( ( a ) => /page=formidable&frm_action=edit&id=\d+/.test( a.href ) ) ) );
	} finally {
		await setOpt( 'minn_test_seed_formidable', '1' ).catch( () => {} ); // restore the deleted row
	}

	await t.done( browser, errors );
} )();
