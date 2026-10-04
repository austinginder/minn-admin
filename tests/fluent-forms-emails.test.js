/**
 * Fluent Forms: a form's emails and confirmation page (/fluent-forms/form/{id}).
 *
 * A Forms row opens the page; the preview renders the notification with the
 * form's latest entry through Fluent's own parser; a smart tag goes in
 * through the picker; Send to a form field; a blank subject is refused on
 * its field by Fluent's own validator and nothing saves; Save writes the
 * notification through their settings store and the confirmation (a redirect
 * address) through their general settings save, leaving the rest of the
 * form's settings as they were. Without Fluent Forms Pro there is no
 * routing choice, and a stored routing set is kept and explained.
 *
 * Fixtures: none standing. A disposable form (the Minn Contact fields), its
 * settings, one notification and one submission are inserted and deleted in
 * finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter, listSettled } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-ffe-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'fluent-forms-emails' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const meta = ( fid ) => JSON.parse( wpEval( `
		global $wpdb;
		$o = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, meta_key, value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d", ${ fid } ) ) as $r ) { $o[ $r->meta_key . ( 'notifications' === $r->meta_key ? ':' . $r->id : '' ) ] = json_decode( $r->value, true ); }
		echo wp_json_encode( $o );
	` ) || '{}' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			global $wpdb;
			$p = $wpdb->prefix;
			$src = $wpdb->get_row( "SELECT form_fields FROM {$p}fluentform_forms WHERE id = 3" );
			$wpdb->insert( "{$p}fluentform_forms", array( 'title' => 'Minn emails suite', 'status' => 'published', 'type' => 'form', 'form_fields' => $src->form_fields, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			$fid = (int) $wpdb->insert_id;
			$settings = json_decode( $wpdb->get_var( "SELECT value FROM {$p}fluentform_form_meta WHERE form_id = 2 AND meta_key = 'formSettings'" ), true );
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'formSettings', 'value' => wp_json_encode( $settings ) ) );
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Admin email', 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'fromName' => '', 'fromEmail' => '', 'replyTo' => '', 'bcc' => '', 'subject' => 'New message', 'message' => '<p>{all_data}</p>', 'conditionals' => array( 'status' => false, 'type' => 'all', 'conditions' => array() ), 'enabled' => true, 'email_template' => '' ) ) ) );
			$nid = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Routed email', 'sendTo' => array( 'type' => 'routing', 'email' => '', 'field' => '', 'routing' => array( array( 'input_value' => 'team@example.com', 'field' => 'names', 'operator' => '=', 'value' => 'x' ) ) ), 'subject' => 'Routed', 'message' => '<p>x</p>', 'enabled' => false ) ) ) );
			$rid = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}fluentform_submissions", array( 'form_id' => $fid, 'serial_number' => 1, 'response' => wp_json_encode( array( 'names' => 'Grace Suite', 'email' => 'grace.suite@example.com', 'message' => 'Hello from the suite' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			echo wp_json_encode( array( 'form' => $fid, 'n' => $nid, 'r' => $rid, 'sub' => (int) $wpdb->insert_id ) );
		` ) || '{}' );
		t.check( 'disposable form, notifications and entry', made.form > 0 && made.n > 0 && made.sub > 0, JSON.stringify( made ) );
		const before = meta( made.form );

		// A Forms row opens the page.
		await page.goto( `${ BASE }/minn-admin/fluent-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
		await page.click( '[data-sview="manage"]' );
		await listSettled( page );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Minn emails suite' ) ).click() );
		await page.waitForURL( new RegExp( `/fluent-forms/form/${ made.form }$` ), { timeout: 30000 } );
		await page.waitForSelector( `.minn-ffe [data-gfn="n${ made.n }.subject"]`, { timeout: 30000 } );
		t.check( 'a Forms row opens the emails page', true );

		await page.waitForFunction( () => /With entry #/.test( ( document.querySelector( '#minn-ffe-note' ) || {} ).textContent || '' ), null, { timeout: 30000, polling: 500 } );
		t.check( 'the preview renders with the latest entry through Fluent’s parser', /Grace Suite/.test( await page.$eval( '#minn-ffe-preview', ( el ) => el.innerHTML ) ) );

		// Pro-only: no routing choice; the stored routing is kept and explained.
		t.check( 'without Pro there is no routing choice', ! await page.$( `[data-ffeto="n${ made.n }"][data-v="routing"]` ) );
		t.check( 'a stored routing set is explained, not offered', /routing rules set up in Fluent Forms Pro/.test( await page.$eval( `[data-ffesec="n${ made.r }"]`, ( el ) => el.textContent ) ) );

		// A smart tag through the picker.
		await page.fill( `[data-gfn="n${ made.n }.subject"]`, 'New message from ' );
		await page.click( `[data-gfn="n${ made.n }.subject"]` );
		await page.keyboard.press( 'Meta+ArrowRight' );
		await page.click( `[data-gfntags="n${ made.n }.subject"]` );
		await page.waitForSelector( '.minn-gfn-tagpop input', { timeout: 5000 } );
		await page.keyboard.type( 'inputs.names' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		t.check( 'the picker inserts the smart tag', await page.$eval( `[data-gfn="n${ made.n }.subject"]`, ( el ) => el.value ) === 'New message from {inputs.names}' );

		// Their validator refuses a blank subject on its field.
		const typed = await page.$eval( `[data-gfn="n${ made.n }.subject"]`, ( el ) => el.value );
		await page.fill( `[data-gfn="n${ made.n }.subject"]`, '' );
		await page.click( '#minn-ffe-save' );
		await page.waitForSelector( `[data-gfnset="n${ made.n }.subject"].minn-gfn-err`, { timeout: 30000 } );
		t.check( 'Fluent’s validator refuses a blank subject on its field', meta( made.form )[ 'notifications:' + made.n ].subject === 'New message' );
		await page.fill( `[data-gfn="n${ made.n }.subject"]`, typed );

		// Send to a form field; a redirect confirmation.
		await page.click( `[data-ffeto="n${ made.n }"][data-v="field"]` );
		await page.waitForSelector( `[data-ffefield="n${ made.n }"]`, { timeout: 5000 } );
		await page.click( `[data-ffefield="n${ made.n }"] .minn-ac-input` );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( 'Email' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
		await page.click( '[data-ffeconf="customUrl"]' );
		await page.waitForSelector( '[data-gfn="confirmation.customUrl"]', { timeout: 5000 } );
		await page.fill( '[data-gfn="confirmation.customUrl"]', 'https://example.com/thanks' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Form saved' );
		await page.waitForSelector( `.minn-ffe [data-gfn="n${ made.n }.subject"]`, { timeout: 30000 } );
		const after = meta( made.form );
		const n = after[ 'notifications:' + made.n ];
		t.check( 'saved: subject and Send to the email field', n.subject === 'New message from {inputs.names}' && n.sendTo.type === 'field' && n.sendTo.field === 'email', JSON.stringify( n.sendTo ) );
		t.check( 'saved: the confirmation redirects', after.formSettings.confirmation.redirectTo === 'customUrl' && after.formSettings.confirmation.customUrl === 'https://example.com/thanks', JSON.stringify( after.formSettings.confirmation ) );
		t.check( 'the rest of the form’s settings are as they were', JSON.stringify( after.formSettings.restrictions ) === JSON.stringify( before.formSettings.restrictions ) && JSON.stringify( after.formSettings.layout ) === JSON.stringify( before.formSettings.layout ) );
		t.check( 'the routed notification is untouched', JSON.stringify( after[ 'notifications:' + made.r ].sendTo ) === JSON.stringify( before[ 'notifications:' + made.r ].sendTo ) );

		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-author' );
			wp_set_current_user( $u ? $u->ID : 0 );
			echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/fluent-forms/forms/${ made.form }/emails' ) )->get_status();
		` );
		t.check( 'someone who may not manage the form is refused', '403' === noEdit, noEdit );
	} finally {
		if ( made.form ) {
			wpEval( `
				global $wpdb;
				$p = $wpdb->prefix;
				$wpdb->delete( "{$p}fluentform_submissions", array( 'form_id' => ${ made.form } ) );
				$wpdb->delete( "{$p}fluentform_form_meta", array( 'form_id' => ${ made.form } ) );
				$wpdb->delete( "{$p}fluentform_forms", array( 'id' => ${ made.form } ) );
			` );
		}
	}

	await t.done( browser, errors );
} )();
