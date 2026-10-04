/**
 * Gravity Forms Notifications view — the second consumer of surface `views`
 * and the first slice of the GF settings-estate work: every notification
 * across forms (per-form tabs), type-aware To display (email address /
 * Field: label / routing rule count), activate-deactivate through GF's own
 * toggle from the row menu, and rows opening the notification page, where
 * edits save through GF's own notification save with server refusals shown
 * on the field (bad address, duplicate name). gf-notification-page covers
 * the page itself.
 *
 * Fixtures: minn_test_seed_gf_notifications RESETS form 1's notifications
 * to a canonical trio through GF's own save path on every arm.
 */
const { launch, login, reporter, BASE, listSettled } = require( './helpers' );

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

	// A row opens the notification page.
	const openRowByText = async ( text ) => {
		await page.waitForFunction( ( txt ) =>
			[ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( txt ) ), text, { timeout: 20000 } );
		await page.evaluate( ( txt ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( txt ) );
			row.click();
		}, text );
		await page.waitForSelector( '.minn-gfn [data-gfn="name"]', { timeout: 30000 } );
	};
	const rowMenu = async ( text ) => {
		await page.evaluate( ( txt ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( txt ) );
			row.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 300, clientY: 300 } ) );
		}, text );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 10000 } );
		return page.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	};
	const backToList = async () => {
		await page.click( '#minn-gfn-back' );
		const ask = await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 1500 } ).catch( () => null );
		if ( ask ) await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => ! /\/notification\//.test( location.pathname ), null, { timeout: 20000 } );
		await listSettled( page );
	};

	try {
		t.check( 'fixture seeder armed', await setOpt( 'minn_test_seed_gf_notifications', '1' ) );

		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );
		const tabs = await page.$$eval( '.minn-view-switch [data-sview]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		// Feeds joined the switcher in v0.18.0 (only while a feed add-on is
		// registered) — assert membership + order of the first three, never
		// the exact list (rule-52 baseline class).
		t.check( 'switcher shows Entries / Forms / Notifications', JSON.stringify( tabs.slice( 0, 3 ) ) === JSON.stringify( [ 'Entries', 'Forms', 'Notifications' ] ), tabs.join( ' · ' ) );

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
		t.check( 'active and inactive pills render', JSON.stringify( pills.map( ( p ) => p.toLowerCase() ) ) === JSON.stringify( [ 'active', 'active', 'inactive' ] ), pills.join( ',' ) );

		// Tabs ride gf/v2/forms, which lists ACTIVE forms only — an inactive
		// form's notifications still appear on the All tab (same shape as
		// the Entries view's tabs).
		const formTabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'form tabs render (active forms only)', formTabs[ 0 ] === 'All notifications' && formTabs.includes( 'Contact Form' ) && ! formTabs.includes( 'Old Newsletter' ), formTabs.join( ' · ' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '[data-stab]' ) ].find( ( b ) => b.textContent.trim() === 'Contact Form' ).click() );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 3, { timeout: 20000 } );
		t.check( 'the per-form tab filters to that form', true );
		await page.evaluate( () => document.querySelector( '[data-stab="_all"]' ).click() );

		/* ===== Toggle through GF's own API (row menu) ===== */
		await listSettled( page );
		let btns = await rowMenu( 'User confirmation' );
		t.check( 'inactive row offers Activate (not Deactivate)', btns.includes( 'Activate' ) && ! btns.includes( 'Deactivate' ), btns.join( ',' ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Activate' ).click() );
		let items = [];
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 500 );
			items = await listItems();
			if ( items.find( ( x ) => x.name === 'User confirmation' ).status === 'active' ) break;
		}
		t.check( 'toggle persisted through GF', items.find( ( i ) => i.name === 'User confirmation' ).status === 'active' );

		/* ===== Edit on the notification page ===== */
		await listSettled( page );
		await openRowByText( 'Admin Notification' );
		t.check( 'a row opens the notification page', /\/gravity-forms\/notification\/1(%3A|:)minnfixadmin0001$/.test( page.url() ) );
		await page.fill( '[data-gfn="subject"]', 'Edited: {form_title} enquiry' );
		await page.fill( '[data-gfn="toEmail"]', 'team@example.com, {admin_email}' );
		await page.click( '#minn-gfn-save' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /Notification saved/.test( x.textContent ) ), null, { timeout: 30000 } );
		items = await listItems();
		const admin = items.find( ( i ) => i.nid === 'minnfixadmin0001' );
		t.check( 'subject and send-to persisted', admin.subject === 'Edited: {form_title} enquiry' && admin.to === 'team@example.com, {admin_email}', JSON.stringify( admin ) );

		/* ===== Server refusals show on the field ===== */
		await page.fill( '[data-gfn="toEmail"]', 'not-an-address' );
		await page.click( '#minn-gfn-save' );
		await page.waitForSelector( '[data-gfnset="toEmail"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'bad address refused on its field', /valid email/.test( await page.$eval( '[data-gfnset="toEmail"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) );
		await page.fill( '[data-gfn="toEmail"]', '{admin_email}' );
		await page.fill( '[data-gfn="name"]', 'Sales routing' );
		await page.click( '#minn-gfn-save' );
		await page.waitForSelector( '[data-gfnset="name"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a duplicate name is refused on its field', /already uses that name/.test( await page.$eval( '[data-gfnset="name"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) );
		items = await listItems();
		t.check( 'refused saves changed nothing', items.find( ( i ) => i.nid === 'minnfixadmin0001' ).name === 'Admin Notification' );
		await backToList();

		/* ===== Routing rules and the GF escape on the page ===== */
		await openRowByText( 'Sales routing' );
		t.check( 'routing rules render as rows', ( await page.$$( '.minn-gfn-route' ) ).length === 2 );
		await page.click( '#minn-gfn-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const hrefs = await page.$$eval( '.minn-ctx-menu a[href]', ( els ) => els.map( ( e ) => e.href ) );
		t.check( 'Edit in Gravity Forms deep-links the notification', hrefs.some( ( h ) => /subview=notification/.test( h ) && /nid=minnfixroute0003/.test( h ) ), hrefs.join( ' ' ) );
		await page.keyboard.press( 'Escape' );
		await backToList();

		/* ===== Entries view unaffected ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'entries view still renders', true );
	} finally {
		await setOpt( 'minn_test_seed_gf_notifications', '1' ).catch( () => {} ); // reset baseline
	}

	await t.done( browser, errors );
} )();
