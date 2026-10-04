/**
 * Contact Form 7: a form's email and messages page (/cf7/form/{id}).
 *
 * A Forms row opens the page; the preview fills the mail from the form's
 * latest Flamingo message; a tag goes in through the picker; Mail (2) turns
 * on with its own fields; a visitor message changes; ⌘Z undoes; Save writes
 * through CF7's own wpcf7_save_contact_form (every mail key and message kept)
 * and their configuration check flags a sender on another domain beside the
 * From field. Leaving with unsaved changes asks.
 *
 * Fixtures: none standing. A disposable CF7 form and one Flamingo message for
 * it are made through their own APIs and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter, listSettled } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-c7m-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'cf7-mail' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const props = ( id ) => JSON.parse( wpEval( `
		$f = wpcf7_contact_form( ${ id } );
		echo wp_json_encode( array( 'mail' => $f->prop( 'mail' ), 'mail_2' => $f->prop( 'mail_2' ), 'messages' => $f->prop( 'messages' ), 'form' => $f->prop( 'form' ) ) );
	` ) || '{}' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			wp_set_current_user( 1 );
			$f = wpcf7_save_contact_form( array( 'title' => 'Minn mail suite' ) );
			$f = wpcf7_contact_form( $f->id() ); // reloaded: the saved form's slug names its Flamingo channel
			// The channel term CF7's Flamingo module makes on a form's first submission.
			$tax    = Flamingo_Inbound_Message::channel_taxonomy;
			$parent = term_exists( 'contact-form-7', $tax );
			$parent = $parent ? $parent : wp_insert_term( 'Contact Form 7', $tax, array( 'slug' => 'contact-form-7' ) );
			wp_insert_term( $f->title(), $tax, array( 'slug' => $f->name(), 'parent' => (int) $parent['term_id'] ) );
			$m = Flamingo_Inbound_Message::add( array(
				'channel' => $f->name(),
				'subject' => 'Suite subject',
				'from'    => 'Dana Suite <dana-suite@example.com>',
				'fields'  => array( 'your-name' => 'Dana Suite', 'your-email' => 'dana-suite@example.com', 'your-subject' => 'Suite subject', 'your-message' => 'Hello from the suite' ),
			) );
			echo wp_json_encode( array( 'form' => $f->id(), 'message' => $m ? $m->id() : 0 ) );
		` ) || '{}' );
		t.check( 'disposable form and Flamingo message', made.form > 0 && made.message > 0, JSON.stringify( made ) );
		const before = props( made.form );

		// The Forms row opens the page.
		await page.goto( `${ BASE }/minn-admin/cf7`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
		await page.click( '[data-sview="manage"]' );
		await listSettled( page );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn mail suite' ) ).click() );
		await page.waitForURL( new RegExp( `/cf7/form/${ made.form }$` ), { timeout: 30000 } );
		await page.waitForSelector( '.minn-c7m [data-gfn="mail.subject"]', { timeout: 30000 } );
		t.check( 'a Forms row opens its email and messages page', true );

		await page.waitForFunction( () => /With message #/.test( ( document.querySelector( '#minn-c7m-note' ) || {} ).textContent || '' ), null, { timeout: 30000 } );
		const pv = await page.$eval( '#minn-c7m-preview', ( el ) => el.textContent );
		t.check( 'the preview fills the mail from the latest message', /Suite subject/.test( pv ), pv.slice( 0, 160 ) );

		// A tag through the picker.
		await page.fill( '[data-gfn="mail.subject"]', 'New message from ' );
		await page.click( '[data-gfn="mail.subject"]' );
		await page.keyboard.press( 'Meta+ArrowRight' );
		await page.click( '[data-gfntags="mail.subject"]' );
		await page.waitForSelector( '.minn-gfn-tagpop input', { timeout: 5000 } );
		await page.keyboard.type( 'your-name' );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		t.check( 'the picker inserts the mail tag', await page.$eval( '[data-gfn="mail.subject"]', ( el ) => el.value ) === 'New message from [your-name]' );
		await page.waitForFunction( () => /New message from Dana Suite/.test( ( document.querySelector( '#minn-c7m-preview' ) || {} ).textContent || '' ), null, { timeout: 30000 } );
		t.check( 'the preview follows the edit', true );

		// ⌘Z: the inserted tag is its own step (the picker took focus), then the typing.
		await page.evaluate( () => document.activeElement.blur() );
		const subj = () => page.$eval( '[data-gfn="mail.subject"]', ( el ) => el.value );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 400 );
		t.check( '⌘Z takes the inserted tag back out', await subj() === 'New message from ', await subj() );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 400 );
		t.check( 'a second ⌘Z undoes the typing', await subj() === before.mail.subject, await subj() );
		await page.keyboard.press( 'Meta+Shift+z' );
		await page.waitForTimeout( 400 );
		await page.keyboard.press( 'Meta+Shift+z' );
		await page.waitForTimeout( 400 );
		t.check( '⇧⌘Z redoes both', await subj() === 'New message from [your-name]', await subj() );

		// Mail (2) on, with its own fields; a visitor message; a foreign sender.
		await page.click( '[data-c7sw="mail_2.active"]' );
		await page.waitForSelector( '[data-gfn="mail_2.recipient"]', { timeout: 5000 } );
		await page.fill( '[data-gfn="mail_2.recipient"]', '[your-email]' );
		await page.fill( '[data-gfn="mail_2.subject"]', 'Thanks for writing' );
		await page.fill( '[data-gfn="messages.mail_sent_ok"]', 'Thanks! We got it.' );
		await page.fill( '[data-gfn="mail.sender"]', 'Someone <someone@elsewhere.example>' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Saved|Form saved' );
		await page.waitForSelector( '.minn-c7m [data-gfn="mail.subject"]', { timeout: 30000 } );
		const after = props( made.form );
		t.check( 'saved through CF7: the subject and the sender', after.mail.subject === 'New message from [your-name]' && after.mail.sender === 'Someone <someone@elsewhere.example>', JSON.stringify( { s: after.mail.subject, f: after.mail.sender } ) );
		t.check( 'saved: Mail (2) on with its fields', after.mail_2.active === true && after.mail_2.recipient === '[your-email]' && after.mail_2.subject === 'Thanks for writing', JSON.stringify( after.mail_2 ) );
		t.check( 'saved: the visitor message, the others kept', after.messages.mail_sent_ok === 'Thanks! We got it.' && after.messages.validation_error === before.messages.validation_error && Object.keys( after.messages ).length >= Object.keys( before.messages ).length );
		t.check( 'the mail body and the form itself are untouched', after.mail.body === before.mail.body && after.form === before.form );
		t.check( 'CF7’s check flags the foreign sender beside From', await page.$eval( '[data-gfnset="mail.sender"]', ( el ) => !! el.querySelector( '.minn-gfn-warn' ) ) );

		// Leaving with unsaved changes asks.
		await page.fill( '[data-gfn="mail.subject"]', 'Unsaved' );
		await page.click( '#minn-c7m-back' );
		await page.waitForSelector( '.minn-confirm-modal [data-cancel]', { timeout: 5000 } );
		t.check( 'leaving with unsaved changes asks first', true );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => ! /\/cf7\/form\//.test( location.pathname ), null, { timeout: 30000 } );
	} finally {
		if ( made.message ) wpEval( `wp_delete_post( ${ made.message }, true );` );
		if ( made.form ) wpEval( `$t = get_term_by( 'slug', get_post_field( 'post_name', ${ made.form } ), Flamingo_Inbound_Message::channel_taxonomy ); if ( $t ) { wp_delete_term( $t->term_id, Flamingo_Inbound_Message::channel_taxonomy ); } wp_delete_post( ${ made.form }, true );` );
	}

	await t.done( browser, errors );
} )();
