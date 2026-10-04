/**
 * Editing a Gravity Forms entry's answers on the entry page.
 *
 * Edit answers turns the answers into a form (empty fields included, files
 * left to Gravity Forms); a bad email is refused on its field and nothing
 * saves; leaving with unsaved edits asks; Cancel discards; Save writes
 * through each field's own formatting (a name part, the email that Reply
 * then uses, a phone in the form's format, a choice, checkboxes, a date in
 * the field's format, a number cleaned) and notes who changed which
 * answers; ⌘S saves; an answer changed elsewhere since the page loaded
 * refuses the save; someone without the edit-entries capability sees no
 * edit block; anonymous requests are refused.
 *
 * Fixtures: none standing. A disposable form and entry are made with GFAPI
 * and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-gfe-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'gf-entry-edit' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', ...( a.anon ? {} : { 'X-WP-Nonce': window.MINN.nonce } ) },
			credentials: a.anon ? 'omit' : 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const clearToasts = () => page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
	const stored = ( eid ) => JSON.parse( wpEval( `echo wp_json_encode( GFAPI::get_entry( ${ eid } ) );` ) || '{}' );
	const openPage = async ( eid ) => {
		await page.goto( `${ BASE }/minn-admin/gravity-forms/entry/${ eid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 60000 } );
	};

	let fid = 0;
	let eid = 0;
	try {
		const made = JSON.parse( wpEval( `
			$fid = GFAPI::add_form( array( 'title' => 'Entry edit suite ' . time(), 'fields' => array(
				array( 'id' => 1, 'type' => 'name', 'label' => 'Name', 'nameFormat' => 'advanced', 'inputs' => array( array( 'id' => '1.3', 'label' => 'First' ), array( 'id' => '1.6', 'label' => 'Last' ) ) ),
				array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
				array( 'id' => 3, 'type' => 'phone', 'label' => 'Phone', 'phoneFormat' => 'standard' ),
				array( 'id' => 4, 'type' => 'select', 'label' => 'Topic', 'choices' => array( array( 'text' => 'Sales', 'value' => 'sales' ), array( 'text' => 'Help', 'value' => 'help' ) ) ),
				array( 'id' => 5, 'type' => 'checkbox', 'label' => 'Extras', 'choices' => array( array( 'text' => 'Newsletter', 'value' => 'news' ), array( 'text' => 'Call me', 'value' => 'call' ) ), 'inputs' => array( array( 'id' => '5.1', 'label' => 'Newsletter' ), array( 'id' => '5.2', 'label' => 'Call me' ) ) ),
				array( 'id' => 6, 'type' => 'date', 'label' => 'Visit date', 'dateType' => 'datepicker', 'dateFormat' => 'dmy' ),
				array( 'id' => 7, 'type' => 'textarea', 'label' => 'Message' ),
				array( 'id' => 8, 'type' => 'number', 'label' => 'Guests' ),
				array( 'id' => 9, 'type' => 'fileupload', 'label' => 'Upload' ),
			) ) );
			$eid = GFAPI::add_entry( array( 'form_id' => $fid, '1.3' => 'Dana', '1.6' => 'Tester', '2' => 'dana@exmaple.com', '4' => 'sales', '5.1' => 'news', '6' => '2026-10-04', '7' => 'Hello there', '8' => '2', '9' => 'https://example.com/x.pdf' ) );
			echo wp_json_encode( array( 'fid' => $fid, 'eid' => $eid ) );
		` ) || '{}' );
		fid = made.fid;
		eid = made.eid;
		t.check( 'disposable form and entry', fid > 0 && eid > 0, JSON.stringify( made ) );

		await openPage( eid );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		t.check( 'the entry page offers Edit answers', true );
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		const labels = await page.$$eval( '.minn-ep-edit-row > .minn-field-label', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'every editable field is listed, the empty phone too', [ 'Name', 'Email', 'Phone', 'Topic', 'Extras', 'Visit date', 'Message', 'Guests' ].every( ( l ) => labels.includes( l ) ) && ! labels.includes( 'Upload' ), labels.join( ' · ' ) );
		t.check( 'files are left to Gravity Forms, by name', /Upload/.test( await page.$eval( '.minn-ep-edit-locked', ( el ) => el.textContent ) ) );
		t.check( 'the date shows as a date', await page.$eval( '[data-epf="6"]', ( el ) => el.type === 'date' && el.value === '2026-10-04' ) );

		// A bad email is refused on its field; nothing saves.
		await page.fill( '[data-epf="2"]', 'not-an-email' );
		await clearToasts();
		await page.click( '#minn-ep-save' );
		await page.waitForSelector( '[data-epset="2"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a bad email is refused on its field', /valid email/.test( await page.$eval( '[data-epset="2"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) );
		t.check( 'the refused save changed nothing', stored( eid )[ '2' ] === 'dana@exmaple.com' );

		// Leaving with unsaved edits asks; staying keeps them.
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const asked = await page.waitForSelector( '.minn-confirm-modal [data-cancel]', { timeout: 6000 } ).then( () => true ).catch( () => false );
		t.check( 'leaving with unsaved edits asks first', asked );
		if ( asked ) await page.click( '.minn-confirm-modal [data-cancel]' );
		await page.waitForTimeout( 300 );
		t.check( 'staying keeps the edit', await page.$eval( '[data-epf="2"]', ( el ) => el.value ) === 'not-an-email' );

		// Cancel discards.
		await page.click( '#minn-ep-cancel' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 10000 } );
		t.check( 'Cancel discards and shows the answers again', /dana@exmaple\.com/.test( await page.$eval( '.minn-order-main', ( el ) => el.textContent ) ) );

		// The real edit, saved with ⌘S.
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		await page.fill( '[data-epf="1.6"]', 'Tester-Smith' );
		await page.fill( '[data-epf="2"]', 'dana@example.com' );
		await page.fill( '[data-epf="3"]', '5559876543' );
		await page.click( '[data-epchoice="4"] .minn-ac-input' );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( 'Help' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
		await page.click( '[data-epc="5.1"]' );
		await page.click( '[data-epc="5.2"]' );
		await page.fill( '[data-epf="6"]', '2026-12-25' );
		await page.fill( '[data-epf="8"]', '1,234' );
		await page.click( '[data-epf="7"]' );
		await clearToasts();
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Answers saved' );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		const e = stored( eid );
		t.check( 'saved: name part and email', e[ '1.6' ] === 'Tester-Smith' && e[ '2' ] === 'dana@example.com', JSON.stringify( { l: e[ '1.6' ], m: e[ '2' ] } ) );
		t.check( 'saved: the phone in the form’s format', e[ '3' ] === '(555) 987-6543', e[ '3' ] );
		t.check( 'saved: the choice and the checkboxes', e[ '4' ] === 'help' && e[ '5.1' ] === '' && e[ '5.2' ] === 'call', JSON.stringify( [ e[ '4' ], e[ '5.1' ], e[ '5.2' ] ] ) );
		t.check( 'saved: the date and the cleaned number', e[ '6' ] === '2026-12-25' && e[ '8' ] === '1234', JSON.stringify( [ e[ '6' ], e[ '8' ] ] ) );
		t.check( 'untouched answers stay as they were', e[ '7' ] === 'Hello there' && e[ '1.3' ] === 'Dana' && e[ '9' ] === 'https://example.com/x.pdf' );
		await page.waitForFunction( () => /Edited answers:/.test( document.querySelector( '.minn-order-main' ).textContent ), null, { timeout: 30000 } );
		t.check( 'the notes say who changed which answers', /Edited answers: .*Email/.test( await page.$eval( '.minn-order-main', ( el ) => el.textContent ) ) );
		t.check( 'the contact card shows the corrected email', await page.$eval( '.minn-ep-reach', ( el ) => /dana@example\.com/.test( el.textContent ) ) );

		// Changed elsewhere since the page loaded: refused.
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		wpEval( `GFAPI::update_entry_field( ${ eid }, '7', 'Changed elsewhere' );` );
		await page.fill( '[data-epf="7"]', 'My edit' );
		await clearToasts();
		await page.click( '#minn-ep-save' );
		await toast( 'changed since you opened it' );
		t.check( 'an answer changed elsewhere refuses the save', stored( eid )[ '7' ] === 'Changed elsewhere' );
		await page.click( '#minn-ep-cancel' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );

		// Capabilities.
		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-editor' );
			wp_set_current_user( $u ? $u->ID : 0 );
			add_filter( 'user_has_cap', function ( $c ) { $c['gravityforms_view_entries'] = true; $c['gravityforms_edit_entries'] = false; $c['gform_full_access'] = false; return $c; } );
			$r = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/gf/entries/${ eid }' ) );
			echo $r->get_status() . ' ' . ( empty( $r->get_data()['edit'] ) ? 'no-edit' : 'edit' );
		` );
		t.check( 'without the edit-entries capability there is no edit block', /^200 no-edit$/.test( noEdit ), noEdit );
		t.check( 'anonymous saves are refused', ( await rest( `minn-admin/v1/gf/entries/${ eid }/answers`, { method: 'POST', anon: true, body: { values: { 2: 'x@example.com' } } } ) ).status === 401 );
		t.check( 'a file answer cannot be written here', ( await rest( `minn-admin/v1/gf/entries/${ eid }/answers`, { method: 'POST', body: { values: { 9: 'https://evil.example/x' } } } ) ).status === 400 && stored( eid )[ '9' ] === 'https://example.com/x.pdf' );
	} finally {
		if ( fid ) wpEval( `GFAPI::delete_form( ${ fid } );` );
	}

	await t.done( browser, errors );
} )();
