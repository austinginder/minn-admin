/**
 * The Gravity Forms notification page (/gravity-forms/notification/{form}:{id}).
 *
 * New notification from the list starts a page that saves nothing until
 * Create; recipients by form field; a merge tag inserted through the
 * picker; conditional logic; the live preview rendering the latest entry;
 * Create through Gravity Forms' own save; Send test (only to the editor);
 * routing rules; an inline refusal that saves nothing; duplicate and delete
 * from the More menu; the unsaved-changes guard.
 *
 * Fixtures: GF form 1 "Contact Form" (email field 2, select 3 "Topic" with
 * choices support / sales / other, entry #1 whose Topic is "Support
 * request"). minn_test_seed_gf_notifications resets form 1's notifications
 * to the canonical trio before and after.
 */
const { BASE, launch, login, reporter, listSettled } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-notification-page' );
	const { browser, page, errors } = await launch();
	await login( page );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const reseed = async () => {
		for ( let i = 0; i < 5; i++ ) {
			await rest( 'wp/v2/settings', { method: 'POST', body: { minn_test_seed_gf_notifications: '1' } } );
			const s = ( await rest( 'wp/v2/settings' ) ).body;
			if ( s && ( s.minn_test_seed_gf_notifications === '1' || s.minn_test_seed_gf_notifications === '' ) ) return true;
			await page.waitForTimeout( 600 );
		}
		return false;
	};
	const listForm1 = async () => ( ( await rest( 'minn-admin/v1/gf/forms/1/notifications' ) ).body || {} ).items || [];
	const pickCombo = async ( sel, text ) => {
		await page.click( `${ sel } .minn-ac-input` );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( text );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
	};
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const clearToasts = () => page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );

	try {
		t.check( 'fixture seeder armed', await reseed() );
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 60000 } );
		await page.click( '[data-sview="x0"]' );
		await listSettled( page );
		const before = ( await listForm1() ).length;

		// New notification: choose the form, land on an unsaved page.
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="form"]', { timeout: 10000 } );
		await pickCombo( '[data-createfield="form"]', 'Contact Form' );
		await page.click( '#minn-surface-create' );
		await page.waitForURL( /\/gravity-forms\/notification\/1(%3A|:)new$/, { timeout: 30000 } );
		await page.waitForSelector( '.minn-gfn .minn-gfn-sec', { timeout: 30000 } );
		t.check( 'New notification opens an unsaved page for the form', /Not saved yet/.test( await page.$eval( '.minn-gfn .minn-fgb-meta', ( el ) => el.textContent ) ) );
		t.check( 'nothing is saved until Create', ( await listForm1() ).length === before );

		// Name, recipients by form field.
		const name = 'Suite notice ' + String( Date.now() ).slice( -6 );
		await page.fill( '[data-gfn="name"]', name );
		await page.click( '[data-gfntotype="field"]' );
		await page.waitForSelector( '[data-gfncombo="toField"]', { timeout: 5000 } );
		await pickCombo( '[data-gfncombo="toField"]', 'Email Address' );

		// Subject with a merge tag from the picker (search + Enter inserts).
		await page.fill( '[data-gfn="subject"]', 'About ' );
		await page.click( '[data-gfn="subject"]' );
		await page.keyboard.press( 'Meta+ArrowRight' ); // End scrolls on macOS
		await page.click( '[data-gfntags="subject"]' );
		await page.waitForSelector( '.minn-gfn-tagpop input', { timeout: 5000 } );
		await page.keyboard.type( 'Topic' );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		const subject = await page.$eval( '[data-gfn="subject"]', ( el ) => el.value );
		t.check( 'the picker inserts the merge tag at the cursor', subject === 'About {Topic:3}', subject );
		t.check( 'address fields leave {all_fields} out of the picker', await page.evaluate( async () => {
			document.querySelector( '[data-gfntags="bcc"]' ).click();
			await new Promise( ( r ) => setTimeout( r, 200 ) );
			const has = !! document.querySelector( '.minn-gfn-tagpop [data-tag="{all_fields}"]' );
			document.querySelectorAll( '.minn-gfn-tagpop' ).forEach( ( e ) => e.remove() );
			return ! has;
		} ) );
		await page.fill( '[data-gfn="message"]', 'New enquiry about {Topic:3}.\n\n{all_fields}' );

		// The live preview renders the latest entry (Topic "Support request").
		await page.waitForFunction( () => /About Support request/.test( ( document.querySelector( '.minn-gfn-subj' ) || {} ).textContent || '' ), null, { timeout: 30000 } );
		t.check( 'the preview renders the subject with the latest entry', true );
		t.check( 'the preview body is a sandboxed frame', await page.$eval( '.minn-gfn-frame', ( el ) => el.getAttribute( 'sandbox' ) === '' ) );

		// Conditional logic: only when Topic is Sales question.
		await page.click( '[data-gfnsw="logic"]' );
		await page.waitForSelector( '[data-rf="logic:0"]', { timeout: 5000 } );
		await pickCombo( '[data-rf="logic:0"]', 'Topic' );
		await pickCombo( '[data-rv="logic:0"]', 'Sales question' );

		// Create through Gravity Forms' own save.
		await clearToasts();
		await page.click( '#minn-gfn-save' );
		await toast( 'Notification created' );
		await page.waitForURL( /\/gravity-forms\/notification\/1(%3A|:)[a-z0-9]+$/, { timeout: 30000 } );
		const id = decodeURIComponent( page.url().split( '/' ).pop() );
		t.check( 'Create gives the page its saved address', /^1:[a-z0-9]+$/.test( id ) && ! /new$/.test( id ), id );
		let saved = ( await rest( `minn-admin/v1/gf/notifications/${ id }/full` ) ).body;
		const sn = saved.notification;
		t.check( 'saved: name, field recipient, subject, message', sn.name === name && sn.toType === 'field' && sn.toField === '2' && sn.subject === 'About {Topic:3}' && /\{all_fields\}/.test( sn.message ), JSON.stringify( { n: sn.name, t: sn.toType, f: sn.toField, s: sn.subject } ) );
		t.check( 'saved: conditional logic', sn.conditionalLogic && sn.conditionalLogic.rules.length === 1 && sn.conditionalLogic.rules[ 0 ].fieldId === '3' && sn.conditionalLogic.rules[ 0 ].value === 'sales', JSON.stringify( sn.conditionalLogic ) );
		t.check( 'the list holds one more notification', ( await listForm1() ).length === before + 1 );

		// Send test: only to the editor.
		await clearToasts();
		await page.click( '#minn-gfn-test' );
		await toast( 'Test sent to admin@' );
		t.check( 'Send test mails the editor, built from the latest entry', await page.evaluate( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /entry #\d+/.test( x.textContent ) ) ) );

		// Routing: two rules.
		await page.click( '[data-gfntotype="routing"]' );
		await page.waitForSelector( '[data-gfnremail="0"]', { timeout: 5000 } );
		await page.fill( '[data-gfnremail="0"]', 'support-team@example.com' );
		await page.click( '[data-gfnradd="route"]' );
		await page.waitForSelector( '[data-gfnremail="1"]', { timeout: 5000 } );
		await page.fill( '[data-gfnremail="1"]', 'sales-team@example.com' );
		// A new rule starts on the first field routing can read (Email): test Topic.
		await pickCombo( '[data-rf="route:1"]', 'Topic' );
		await page.waitForSelector( '[data-rv="route:1"]', { timeout: 5000 } );
		await pickCombo( '[data-rv="route:1"]', 'Sales question' );
		await clearToasts();
		await page.click( '#minn-gfn-save' );
		await toast( 'Notification saved' );
		saved = ( await rest( `minn-admin/v1/gf/notifications/${ id }/full` ) ).body;
		t.check( 'routing rules saved', saved.notification.toType === 'routing' && saved.notification.routing.length === 2 && saved.notification.routing[ 1 ].email === 'sales-team@example.com' && saved.notification.routing[ 1 ].value === 'sales', JSON.stringify( saved.notification.routing ) );

		// A refusal names its field inline and saves nothing.
		await page.fill( '[data-gfnremail="0"]', 'not-an-address' );
		await clearToasts();
		await page.click( '#minn-gfn-save' );
		await page.waitForSelector( '[data-gfnset="routing"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a bad routing address is refused inline', /valid email/.test( await page.$eval( '[data-gfnset="routing"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) );
		saved = ( await rest( `minn-admin/v1/gf/notifications/${ id }/full` ) ).body;
		t.check( 'the refused save changed nothing', saved.notification.routing[ 0 ].email === 'support-team@example.com' );

		// Leaving with unsaved changes asks first.
		await page.click( '#minn-gfn-back' );
		await page.waitForSelector( '.minn-confirm-modal', { timeout: 5000 } );
		t.check( 'leaving with unsaved changes asks first', true );
		await page.click( '.minn-confirm-modal [data-cancel]' );
		await page.waitForTimeout( 300 );
		await page.fill( '[data-gfnremail="0"]', 'support-team@example.com' );

		// Duplicate from the More menu.
		await page.click( '#minn-gfn-save' );
		await toast( 'Notification saved' );
		await page.click( '#minn-gfn-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Duplicate' ).click() );
		await page.waitForFunction( ( was ) => decodeURIComponent( location.pathname.split( '/' ).pop() ) !== was, id, { timeout: 30000 } );
		await page.waitForSelector( '.minn-gfn [data-gfn="name"]', { timeout: 30000 } );
		const copyId = decodeURIComponent( page.url().split( '/' ).pop() );
		t.check( 'Duplicate opens the copy, named as Gravity Forms names copies', ( await page.$eval( '[data-gfn="name"]', ( el ) => el.value ) ) === name + ' - Copy 1' && copyId !== id );

		// Delete it from the More menu: back to the list.
		await page.click( '#minn-gfn-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Delete' ).click() );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => ! /\/notification\//.test( location.pathname ), null, { timeout: 30000 } );
		t.check( 'Delete removes the copy and returns to the list', ! ( await listForm1() ).some( ( x ) => x.id === copyId ) );

		// The list row opens the page.
		await listSettled( page );
		await page.waitForFunction( ( n ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( n ) ), name, { timeout: 30000 } );
		await page.evaluate( ( n ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( n ) ).click(), name );
		await page.waitForSelector( '.minn-gfn [data-gfn="name"]', { timeout: 30000 } );
		t.check( 'a list row opens its notification page', decodeURIComponent( page.url().split( '/' ).pop() ) === id );
	} finally {
		await reseed().catch( () => {} );
	}

	await t.done( browser, errors );
} )();
