/**
 * WPForms Pro entries: editing answers on the entry page.
 *
 * Edit answers lists the name (by part), email, drop-down, checkboxes and
 * paragraph (an upload stays in WPForms); Save runs WPForms' own entry edit
 * restricted to the changed fields: their formatting (the name rebuilt from
 * its parts, several choices on lines), their entry_fields rows, their
 * "Entry edited." record, and every other field (the upload) kept as stored.
 * Their validation answers on its field (a required email can't be blanked).
 * Someone WPForms would not let edit the entry sees no edit block.
 *
 * Fixtures: none standing. A disposable form and entry are made through
 * WPForms' own objects and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-wpe-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'wpforms-entry-edit' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const stored = ( eid ) => JSON.parse( wpEval( `
		wp_set_current_user( 1 );
		$e = wpforms()->obj( 'entry' )->get( ${ eid } );
		$o = array();
		foreach ( wpforms_decode( $e->fields ) as $fid => $f ) { $o[ $fid ] = $f['value']; }
		$m = array();
		foreach ( (array) wpforms()->obj( 'entry_meta' )->get_meta( array( 'entry_id' => ${ eid } ) ) as $x ) { $m[] = wp_strip_all_tags( $x->data ); }
		echo wp_json_encode( array( 'fields' => $o, 'meta' => $m ) );
	` ) || '{}' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			wp_set_current_user( 1 ); // their form read checks capabilities
			$form_id = wpforms()->obj( 'form' )->add( 'Entry edit suite' );
			wpforms()->obj( 'form' )->update( $form_id, array(
				'id' => $form_id, 'field_id' => 7, 'settings' => array( 'form_title' => 'Entry edit suite' ),
				'fields' => array(
					1 => array( 'id' => 1, 'type' => 'name', 'label' => 'Name', 'format' => 'first-last' ),
					2 => array( 'id' => 2, 'type' => 'email', 'label' => 'Email', 'required' => '1' ),
					3 => array( 'id' => 3, 'type' => 'select', 'label' => 'Topic', 'choices' => array( 1 => array( 'label' => 'Sales', 'value' => '' ), 2 => array( 'label' => 'Help', 'value' => '' ) ) ),
					4 => array( 'id' => 4, 'type' => 'checkbox', 'label' => 'Extras', 'choices' => array( 1 => array( 'label' => 'News', 'value' => '' ), 2 => array( 'label' => 'Call', 'value' => '' ) ) ),
					5 => array( 'id' => 5, 'type' => 'textarea', 'label' => 'Message' ),
					6 => array( 'id' => 6, 'type' => 'file-upload', 'label' => 'Upload' ),
				),
			) );
			$fields = array(
				1 => array( 'name' => 'Name', 'value' => 'Ada Lovelace', 'id' => 1, 'type' => 'name', 'first' => 'Ada', 'middle' => '', 'last' => 'Lovelace' ),
				2 => array( 'name' => 'Email', 'value' => 'ada@exmaple.com', 'id' => 2, 'type' => 'email' ),
				3 => array( 'name' => 'Topic', 'value' => 'Sales', 'value_raw' => 'Sales', 'id' => 3, 'type' => 'select' ),
				4 => array( 'name' => 'Extras', 'value' => 'News', 'value_raw' => 'News', 'id' => 4, 'type' => 'checkbox' ),
				5 => array( 'name' => 'Message', 'value' => 'Line one', 'id' => 5, 'type' => 'textarea' ),
				6 => array( 'name' => 'Upload', 'value' => 'https://example.com/f.pdf', 'value_raw' => '', 'id' => 6, 'type' => 'file-upload', 'file' => 'f.pdf' ),
			);
			$entry_id = wpforms()->obj( 'entry' )->add( array( 'form_id' => $form_id, 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
			wpforms()->obj( 'entry_fields' )->save( $fields, wpforms()->obj( 'form' )->get( $form_id, array( 'content_only' => true ) ), $entry_id );
			echo wp_json_encode( array( 'form' => $form_id, 'entry' => $entry_id ) );
		` ) || '{}' );
		t.check( 'disposable form and entry', made.form > 0 && made.entry > 0, JSON.stringify( made ) );

		await page.goto( `${ BASE }/minn-admin/wpforms/entry/${ made.entry }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 60000 } );
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		const labels = await page.$$eval( '.minn-ep-edit-row > .minn-field-label', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'name, email, choices and paragraph are editable', [ 'Name', 'Email', 'Topic', 'Extras', 'Message' ].every( ( l ) => labels.includes( l ) ), labels.join( ' · ' ) );
		t.check( 'the name edits by part', !! await page.$( '[data-epf="1.first"]' ) && !! await page.$( '[data-epf="1.last"]' ) );
		t.check( 'the upload stays in WPForms', /Upload/.test( await page.$eval( '.minn-ep-edit-locked', ( el ) => el.textContent ) ) );

		// Their validation: a required email can't be blanked.
		await page.fill( '[data-epf="2"]', '' );
		await page.click( '#minn-ep-save' );
		await page.waitForSelector( '[data-epset="2"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'WPForms’ own validation answers on its field', stored( made.entry ).fields[ 2 ] === 'ada@exmaple.com' );

		await page.fill( '[data-epf="2"]', 'ada@example.com' );
		await page.fill( '[data-epf="1.last"]', 'King' );
		await page.click( '[data-epchoice="3"] .minn-ac-input' );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( 'Help' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
		await page.click( '[data-epm="4"][value="Call"]' );
		await page.fill( '[data-epf="5"]', 'Line one\nLine two' );
		await page.click( '#minn-ep-save' );
		await toast( 'Answers saved' );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		const s = stored( made.entry );
		t.check( 'their formatting rebuilds the name from its parts', s.fields[ 1 ] === 'Ada King', s.fields[ 1 ] );
		t.check( 'email, choice and paragraph saved', s.fields[ 2 ] === 'ada@example.com' && s.fields[ 3 ] === 'Help' && s.fields[ 5 ] === 'Line one\nLine two', JSON.stringify( s.fields ) );
		t.check( 'several choices are stored on lines, their way', s.fields[ 4 ] === 'News\nCall', JSON.stringify( s.fields[ 4 ] ) );
		t.check( 'the upload is kept as stored', s.fields[ 6 ] === 'https://example.com/f.pdf' );
		t.check( 'WPForms records the edit', s.meta.some( ( m ) => /Entry edited/.test( m ) ), JSON.stringify( s.meta ) );

		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-author' );
			wp_set_current_user( $u ? $u->ID : 0 );
			$w = new WP_REST_Request( 'POST', '/minn-admin/v1/wpforms/entries/${ made.entry }/answers' );
			$w->set_header( 'content-type', 'application/json' );
			$w->set_body( wp_json_encode( array( 'values' => array( '2' => 'x@example.com' ) ) ) );
			echo rest_do_request( $w )->get_status();
		` );
		t.check( 'someone WPForms would not let edit the entry cannot save', '403' === noEdit, noEdit );
	} finally {
		if ( made.entry ) wpEval( `wp_set_current_user( 1 ); wpforms()->obj( 'entry' )->delete( ${ made.entry } );` );
		if ( made.form ) wpEval( `wp_delete_post( ${ made.form }, true );` );
	}

	await t.done( browser, errors );
} )();
