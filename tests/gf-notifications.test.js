/**
 * Gravity Forms Notifications view — the second consumer of surface `views`
 * and the first slice of the GF settings-estate work: every notification
 * across forms (per-form tabs), type-aware To display (email address /
 * Field: label / routing rule count), activate-deactivate through GF's own
 * toggle, and daily-field editing (name, send-to, subject, message) with
 * server-side refusals (bad address, duplicate name, routing-type send-to).
 *
 * Fixtures: minn_test_seed_gf_notifications RESETS form 1's notifications
 * to a canonical trio through GF's own save path on every arm.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-notifications' );
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

	const listItems = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/notifications?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).items;
	} );

	const openRowByText = async ( text ) => {
		await page.waitForFunction( ( txt ) =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( txt ) ), text, { timeout: 20000 } );
		await page.evaluate( ( txt ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( txt ) );
			row.click();
		}, text );
		await page.waitForSelector( '.minn-modal', { timeout: 15000 } );
	};

	try {
		t.check( 'fixture seeder armed', await setOpt( 'minn_test_seed_gf_notifications', '1' ) );

		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );
		const tabs = await page.$$eval( '.minn-view-switch [data-sview]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'switcher shows Entries / Forms / Notifications', JSON.stringify( tabs ) === JSON.stringify( [ 'Entries', 'Forms', 'Notifications' ] ), tabs.join( ' · ' ) );

		/* ===== The list: type-aware To column, status pills, form tabs ===== */
		await page.click( '[data-sview="x0"]' );
		// v0.16 soft reload keeps the Entries rows painted while the
		// Notifications collection loads — wait for notification CONTENT
		// (the seeded trio's admin notification), not just any table row.
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Admin Notification' ) ),
		null, { timeout: 20000 } );
		const body = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'email-type To shows the address', body.includes( '{admin_email}' ) );
		t.check( 'field-type To resolves the field label', body.includes( 'Field: Email Address' ) );
		t.check( 'routing-type To shows the rule count', body.includes( 'Routing (2 rules)' ) );
		const pills = await page.$$eval( '.minn-table-row .minn-status', ( els ) => els.map( ( e ) => e.textContent.trim() ).sort() );
		t.check( 'active and inactive pills render', JSON.stringify( pills ) === JSON.stringify( [ 'active', 'active', 'inactive' ] ), pills.join( ',' ) );

		// Tabs ride gf/v2/forms, which lists ACTIVE forms only — an inactive
		// form's notifications still appear on the All tab (same shape as
		// the Entries view's tabs).
		const formTabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'form tabs render (active forms only)', formTabs[ 0 ] === 'All notifications' && formTabs.includes( 'Contact Form' ) && ! formTabs.includes( 'Old Newsletter' ), formTabs.join( ' · ' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '[data-stab]' ) ].find( ( b ) => b.textContent.trim() === 'Contact Form' ).click() );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 3, { timeout: 20000 } );
		t.check( 'the per-form tab filters to that form', true );
		await page.evaluate( () => document.querySelector( '[data-stab="_all"]' ).click() );

		/* ===== Toggle through GF's own API ===== */
		await openRowByText( 'User confirmation' );
		let btns = await page.$$eval( '.minn-modal [data-saction]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'inactive row offers Activate (not Deactivate)', btns.includes( 'Activate' ) && ! btns.includes( 'Deactivate' ), btns.join( ',' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Activate' ).click() );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), { timeout: 15000 } );
		let items = await listItems();
		t.check( 'toggle persisted through GF', items.find( ( i ) => i.name === 'User confirmation' ).status === 'active' );

		/* ===== Edit the daily fields ===== */
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await openRowByText( 'Admin Notification' );
		const fieldKeys = await page.$$eval( '.minn-modal [data-editfield]', ( els ) => els.map( ( e ) => e.dataset.editfield ) );
		t.check( 'edit fields render (name, send-to, subject, message)',
			JSON.stringify( fieldKeys ) === JSON.stringify( [ 'name', 'to_email', 'subject', 'message' ] ), fieldKeys.join( ',' ) );
		await page.fill( '.minn-modal [data-editfield="subject"]', 'Edited: {form_title} enquiry' );
		await page.fill( '.minn-modal [data-editfield="to_email"]', 'team@example.com, {admin_email}' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), { timeout: 15000 } );
		items = await listItems();
		const admin = items.find( ( i ) => i.nid === 'minnfixadmin0001' );
		t.check( 'subject and send-to persisted', admin.subject === 'Edited: {form_title} enquiry' && admin.to === 'team@example.com, {admin_email}', JSON.stringify( admin ) );

		/* ===== Server refusals keep the modal open ===== */
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await openRowByText( 'Admin Notification' );
		await page.fill( '.minn-modal [data-editfield="to_email"]', 'not-an-address' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '.minn-toast-msg' );
			return !! el && /not an email address/.test( el.textContent );
		}, { timeout: 15000 } );
		t.check( 'bad address refused with the server message', true );
		t.check( 'modal stays open after a refusal', !! ( await page.$( '.minn-modal' ) ) );
		await page.keyboard.press( 'Escape' );

		/* ===== Routing rows carry the GF escape ===== */
		await openRowByText( 'Sales routing' );
		const hrefs = await page.$$eval( '.minn-modal a[href]', ( els ) => els.map( ( e ) => e.href ) );
		t.check( 'Edit in Gravity Forms deep-links the notification', hrefs.some( ( h ) => /subview=notification/.test( h ) && /nid=minnfixroute0003/.test( h ) ), hrefs.join( ' ' ) );
		await page.keyboard.press( 'Escape' );

		/* ===== Entries view unaffected ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'entries view still renders', true );
	} finally {
		await setOpt( 'minn_test_seed_gf_notifications', '1' ).catch( () => {} ); // reset baseline
	}

	await t.done( browser, errors );
} )();
