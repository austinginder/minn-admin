/**
 * WPForms: a form's notifications and confirmations page (/wpforms/form/{id}).
 *
 * The new Forms view lists the form and its row opens the page; the preview
 * runs WPForms' own notification email over the latest entry; a smart tag
 * goes in through the picker; a bad redirect address is refused on its field
 * and nothing saves; Save goes through WPForms' own form update, changing the
 * subject, sender and confirmation while the fields stay as they were;
 * turning notifications off for the form saves too.
 *
 * Fixtures: none standing. A disposable form from WPForms' contact template
 * and one entry are made through WPForms' own objects and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter, listSettled } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-wpfe-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php wp_set_current_user( 1 ); ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'wpforms-emails' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const stored = ( id ) => JSON.parse( wpEval( `$d = wpforms()->obj( 'form' )->get( ${ id }, array( 'content_only' => true ) ); echo wp_json_encode( array( 'settings' => $d['settings'], 'fields' => $d['fields'] ) );` ) || '{}' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			$id = wpforms()->obj( 'form' )->add( 'Minn notify suite', array(), array( 'template' => 'simple-contact-form-template' ) );
			$fields = array(
				1 => array( 'name' => 'Name', 'value' => 'Grace Suite', 'id' => 1, 'type' => 'name', 'first' => 'Grace', 'last' => 'Suite' ),
				2 => array( 'name' => 'Email', 'value' => 'grace.suite@example.com', 'id' => 2, 'type' => 'email' ),
				3 => array( 'name' => 'Comment or Message', 'value' => 'Hello from the suite', 'id' => 3, 'type' => 'textarea' ),
			);
			$eid = wpforms()->obj( 'entry' )->add( array( 'form_id' => $id, 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
			echo wp_json_encode( array( 'form' => $id, 'entry' => $eid ) );
		` ) || '{}' );
		t.check( 'disposable form and entry', made.form > 0 && made.entry > 0, JSON.stringify( made ) );
		const before = stored( made.form );

		// The Forms view lists it; the row opens the page.
		await page.goto( `${ BASE }/minn-admin/wpforms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
		await page.click( '[data-sview="manage"]' );
		await listSettled( page );
		const row = await page.evaluate( () => [ ...( [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn notify suite' ) ) || { children: [] } ).children ].map( ( c ) => c.textContent.trim() ) );
		t.check( 'the Forms view lists the form with its entries', row.some( ( c ) => /Minn notify suite/.test( c ) ) && row.includes( '1' ), JSON.stringify( row ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn notify suite' ) ).click() );
		await page.waitForURL( new RegExp( `/wpforms/form/${ made.form }$` ), { timeout: 30000 } );
		await page.waitForSelector( '.minn-wpfe [data-gfn="n1.subject"]', { timeout: 30000 } );
		t.check( 'a Forms row opens the notifications page', true );

		await page.waitForFunction( () => /With entry #/.test( ( document.querySelector( '#minn-wpfe-note' ) || {} ).textContent || '' ), null, { timeout: 30000, polling: 500 } );
		t.check( 'the preview runs WPForms’ email over the latest entry', /Hello from the suite/.test( await page.$eval( '#minn-wpfe-preview', ( el ) => el.innerHTML ) ) );

		// A smart tag through the picker.
		await page.fill( '[data-gfn="n1.subject"]', 'New message from ' );
		await page.click( '[data-gfn="n1.subject"]' );
		await page.keyboard.press( 'Meta+ArrowRight' );
		await page.click( '[data-gfntags="n1.subject"]' );
		await page.waitForSelector( '.minn-gfn-tagpop input', { timeout: 5000 } );
		await page.keyboard.type( 'Name' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		t.check( 'the picker inserts the field tag', await page.$eval( '[data-gfn="n1.subject"]', ( el ) => el.value ) === 'New message from {field_id="1"}' );
		await page.waitForFunction( () => /New message from Grace Suite/.test( ( document.querySelector( '#minn-wpfe-preview' ) || {} ).textContent || '' ), null, { timeout: 30000, polling: 500 } );
		t.check( 'the preview follows the edit', true );
		await page.fill( '[data-gfn="n1.sender_name"]', 'Front Desk' );

		// A bad redirect address is refused on its field.
		await page.click( '[data-wpfetype="c1"][data-v="redirect"]' );
		await page.waitForSelector( '[data-gfn="c1.redirect"]', { timeout: 5000 } );
		await page.fill( '[data-gfn="c1.redirect"]', 'not a url' );
		await page.click( '#minn-wpfe-save' );
		await page.waitForSelector( '[data-gfnset="c1.redirect"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a bad redirect address is refused on its field', stored( made.form ).settings.notifications[ 1 ].subject === before.settings.notifications[ 1 ].subject );

		await page.fill( '[data-gfn="c1.redirect"]', 'https://example.com/thanks' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Form saved' );
		await page.waitForSelector( '.minn-wpfe [data-gfn="n1.subject"]', { timeout: 30000 } );
		let s = stored( made.form );
		t.check( 'saved through WPForms: subject and sender', s.settings.notifications[ 1 ].subject === 'New message from {field_id="1"}' && s.settings.notifications[ 1 ].sender_name === 'Front Desk', JSON.stringify( s.settings.notifications[ 1 ] ) );
		t.check( 'saved: the confirmation redirects', s.settings.confirmations[ 1 ].type === 'redirect' && s.settings.confirmations[ 1 ].redirect === 'https://example.com/thanks', JSON.stringify( s.settings.confirmations[ 1 ] ) );
		const shape = ( f ) => JSON.stringify( Object.values( f || {} ).map( ( x ) => [ String( x.id ), x.type, x.label ] ) );
		t.check( 'the form’s fields are as they were', shape( s.fields ) === shape( before.fields ), shape( s.fields ) );
		t.check( 'untouched notification settings are kept', s.settings.notifications[ 1 ].replyto === before.settings.notifications[ 1 ].replyto && s.settings.notifications[ 1 ].email === before.settings.notifications[ 1 ].email );

		// Notifications off for the form.
		await page.click( '#minn-wpfe-enabled' );
		await page.click( '#minn-wpfe-save' );
		await toast( 'Form saved' );
		await page.waitForTimeout( 500 );
		s = stored( made.form );
		t.check( 'turning notifications off saves', '0' === String( s.settings.notification_enable ), String( s.settings.notification_enable ) );

		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-author' );
			wp_set_current_user( $u ? $u->ID : 0 );
			echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/wpforms/forms/${ made.form }/emails' ) )->get_status();
		` );
		t.check( 'someone who may not edit the form is refused', '403' === noEdit, noEdit );
	} finally {
		if ( made.entry ) wpEval( `wpforms()->obj( 'entry' )->delete( ${ made.entry } );` );
		if ( made.form ) wpEval( `wp_delete_post( ${ made.form }, true );` );
	}

	await t.done( browser, errors );
} )();
