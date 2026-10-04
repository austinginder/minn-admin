/**
 * Per-form settings — item-scoped settings views + the Gravity Forms
 * Settings-framework mapper. A Forms row's "Form settings" action (row menu) opens a
 * settings view scoped to that form, drawn at request time from
 * GFFormSettings::form_settings_fields(): groups, dependency-driven
 * showWhen rows, locked date-time controls with the GF escape, choice
 * whitelists and GF's own validation semantics (duplicate titles refused).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-form-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	const restSettings = ( vals ) => page.evaluate( async ( v ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/forms/1/settings/form' + ( v ? '' : '?_cb=' + Math.random() ), {
			method: v ? 'POST' : 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( v ? { body: JSON.stringify( { values: v } ) } : {} ),
		} );
		return r.json();
	}, vals || null );

	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /gf\/forms\/1\/settings\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForTimeout( 400 );
		return res.status();
	};

	try {
		/* ===== Entry: Forms row → Form settings ===== */
		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );
		t.check( 'switcher has no Settings tab (item-scoped)',
			! ( await page.$$eval( '.minn-view-switch [data-sview]', ( els ) => els.some( ( e ) => e.dataset.sview === 'settings' ) ) ) );

		await page.click( '[data-sview="manage"]' );
		// v0.16 soft reload keeps the Entries rows painted while Forms loads —
		// wait for the FORM row itself, not just any table row.
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Contact Form' ) ),
		null, { timeout: 20000 } );
		// A row click opens the form builder now (gf-builder covers it); the
		// row's menu carries Form settings with the other actions.
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Contact Form' ) )
				.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 300, clientY: 300 } ) );
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 15000 } );
		const actions = await page.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'form row offers Form settings', actions.includes( 'Form settings' ), actions.join( ',' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Form settings' ).click() );
		await page.waitForSelector( '[data-sset]', { timeout: 20000 } );

		/* ===== The mapped schema renders ===== */
		t.check( 'toolbar names the item', ( await page.$eval( '.minn-toolbar-meta', ( el ) => el.textContent ) ).includes( 'Contact Form' ) );
		t.check( 'Forms switcher tab stays active', await page.$eval( '[data-sview="manage"]', ( el ) => el.classList.contains( 'active' ) ) );
		const groups = await page.$$eval( '.minn-fields-sub', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'GF sections render as groups', groups.includes( 'Form Basics' ) && groups.includes( 'Restrictions' ) && groups.includes( 'Spam Detection' ), groups.join( ' · ' ) );
		t.check( 'title seeds from the form', await page.$eval( '[data-sset="title"]', ( el ) => el.value === 'Contact Form' ) );
		t.check( 'selects render as themed comboboxes', await page.$eval( '[data-sset="labelPlacement"]', ( el ) => el.dataset.ftype === 'combobox' ) );
		t.check( 'schedule date-times count as locked with the GF escape', await page.$$eval( '.minn-panel-locked', ( els ) =>
			els.some( ( el ) => /2 advanced settings/.test( el.textContent ) && !! el.querySelector( 'a[href*="gf_edit_forms"]' ) ) ) );

		/* ===== showWhen follows the checkbox-idiom toggle ===== */
		t.check( 'dependent limit rows hidden while off', await page.$eval( '[data-srow="limitEntriesCount"]', ( el ) => el.hidden ) );
		await page.click( '[data-sset="limitEntries"]' );
		t.check( 'dependent limit rows reveal when toggled on', await page.$eval( '[data-srow="limitEntriesCount"]', ( el ) => ! el.hidden ) );

		/* ===== A real save through GF's own write path ===== */
		await page.fill( '[data-sset="limitEntriesCount"]', '25' );
		await page.click( '[data-srow="limitEntriesPeriod"] .minn-ac-input' );
		await page.click( '.minn-ac-item[data-acv="month"]' );
		t.check( 'save answers 200', ( await clickSave() ) === 200 );
		let stored = await restSettings();
		t.check( 'limit settings persisted through GFAPI',
			stored.values.limitEntries === true && stored.values.limitEntriesCount === '25' && stored.values.limitEntriesPeriod === 'month',
			JSON.stringify( { l: stored.values.limitEntries, c: stored.values.limitEntriesCount, p: stored.values.limitEntriesPeriod } ) );

		/* ===== GF's own validation semantics ===== */
		await page.waitForSelector( '[data-sset="title"]', { timeout: 15000 } );
		await page.fill( '[data-sset="title"]', 'Old Newsletter' );
		t.check( 'duplicate title refused with 400', ( await clickSave() ) === 400 );
		t.check( 'refusal toasts GF-style message', await page.evaluate( () => {
			const el = document.querySelector( '.minn-toast-msg' );
			return !! el && /already uses that title/.test( el.textContent );
		} ) );
		t.check( 'form keeps the typed title after refusal', await page.$eval( '[data-sset="title"]', ( el ) => el.value === 'Old Newsletter' ) );

		/* ===== Leaving settings returns to the list ===== */
		// The refused title is still typed in, unsaved: leaving asks first.
		await page.click( '[data-sview="manage"]' );
		const asked = await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 6000 } ).then( () => true ).catch( () => false );
		t.check( 'leaving with the unsaved title asks first', asked );
		if ( asked ) await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'Forms list returns from item settings', true );
	} finally {
		await restSettings( {
			limitEntries: false, limitEntriesCount: 0, limitEntriesPeriod: '', limitEntriesMessage: '',
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
