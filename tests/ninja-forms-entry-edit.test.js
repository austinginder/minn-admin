/**
 * Ninja Forms answers on the entry page: shown decoded, and editable.
 *
 * Ninja Forms stores answers encoded (an apostrophe as &#039;, & as &amp;)
 * and decodes them on read; the entry page shows them as typed. Edit answers
 * lists the text, email, choice and multi-choice fields (a single checkbox
 * stays in Ninja Forms), and Save writes through their submission model (the
 * path their own update route takes), stored encoded as they expect. Someone
 * Ninja Forms would not let update submissions sees no edit block.
 *
 * Fixtures: none standing. A disposable form, its fields and one submission
 * are made through Ninja Forms' own models and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-nfe-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'ninja-forms-entry-edit' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const meta = ( sid, fid ) => JSON.parse( wpEval( `echo wp_json_encode( get_post_meta( ${ sid }, '_field_${ fid }', true ) );` ) || 'null' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			$form = Ninja_Forms()->form()->get();
			$form->update_setting( 'title', 'Entry edit suite' );
			$form->save();
			$fid = $form->get_id();
			$mk = function ( $s ) use ( $fid ) { $f = Ninja_Forms()->form( $fid )->field()->get(); $f->update_settings( $s ); $f->update_setting( 'parent_id', $fid ); $f->save(); return $f->get_id(); };
			$ids = array(
				'name'   => $mk( array( 'type' => 'textbox', 'label' => 'Name', 'key' => 'name', 'order' => 1 ) ),
				'email'  => $mk( array( 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'order' => 2 ) ),
				'topic'  => $mk( array( 'type' => 'listselect', 'label' => 'Topic', 'key' => 'topic', 'order' => 3, 'options' => array( array( 'label' => 'Sales', 'value' => 'sales', 'order' => 0 ), array( 'label' => 'Help', 'value' => 'help', 'order' => 1 ) ) ) ),
				'extras' => $mk( array( 'type' => 'listcheckbox', 'label' => 'Extras', 'key' => 'extras', 'order' => 4, 'options' => array( array( 'label' => 'News', 'value' => 'news', 'order' => 0 ), array( 'label' => 'Call', 'value' => 'call', 'order' => 1 ) ) ) ),
				'agree'  => $mk( array( 'type' => 'checkbox', 'label' => 'Agree', 'key' => 'agree', 'order' => 5 ) ),
			);
			WPN_Helper::build_nf_cache( $fid );
			$sub = Ninja_Forms()->form( $fid )->sub()->get();
			$sub->update_field_values( array( $ids['name'] => "O'Brien & Sons", $ids['email'] => 'ob@exmaple.com', $ids['topic'] => 'sales', $ids['extras'] => array( 'news' ), $ids['agree'] => 1 ) );
			$sub->save();
			echo wp_json_encode( array( 'form' => $fid, 'sub' => $sub->get_id(), 'ids' => $ids ) );
		` ) || '{}' );
		const ids = made.ids || {};
		t.check( 'disposable form and submission', made.form > 0 && made.sub > 0, JSON.stringify( made ) );
		t.check( 'Ninja Forms stores the answer encoded', /&#039;|&amp;/.test( String( meta( made.sub, ids.name ) ) ) );

		await page.goto( `${ BASE }/minn-admin/ninja-forms/entry/${ made.sub }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 60000 } );
		const shown = await page.$eval( '.minn-entry-page', ( el ) => el.textContent );
		t.check( 'the entry page shows the answer as typed', shown.includes( "O'Brien & Sons" ) && ! /&#039;|&amp;/.test( shown ), shown.slice( 0, 120 ) );

		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		const labels = await page.$$eval( '.minn-ep-edit-row > .minn-field-label', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'text, email and list fields are editable', [ 'Name', 'Email', 'Topic', 'Extras' ].every( ( l ) => labels.includes( l ) ), labels.join( ' · ' ) );
		t.check( 'the single checkbox stays in Ninja Forms', /Agree/.test( await page.$eval( '.minn-ep-edit-locked', ( el ) => el.textContent ) ) );
		t.check( 'the edit box holds the decoded answer', await page.$eval( `[data-epf="${ ids.name }"]`, ( el ) => el.value ) === "O'Brien & Sons" );

		await page.fill( `[data-epf="${ ids.name }"]`, "O'Brien & Daughters" );
		await page.fill( `[data-epf="${ ids.email }"]`, 'ob@example.com' );
		await page.click( `[data-epchoice="${ ids.topic }"] .minn-ac-input` );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( 'Help' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
		await page.click( `[data-epm="${ ids.extras }"][value="call"]` );
		await page.click( '#minn-ep-save' );
		await toast( 'Answers saved' );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		t.check( 'saved through their model, encoded as they store it', meta( made.sub, ids.name ) === 'O&#039;Brien &amp; Daughters', String( meta( made.sub, ids.name ) ) );
		t.check( 'saved: email, choice and choices', meta( made.sub, ids.email ) === 'ob@example.com' && meta( made.sub, ids.topic ) === 'help' && JSON.stringify( meta( made.sub, ids.extras ) ) === '["news","call"]' );
		t.check( 'the page shows the new answers', await page.$eval( '.minn-entry-page', ( el ) => el.textContent.includes( "O'Brien & Daughters" ) && el.textContent.includes( 'ob@example.com' ) ) );

		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-editor' );
			wp_set_current_user( $u ? $u->ID : 0 );
			add_filter( 'ninja_forms_admin_submissions_capabilities', function () { return 'edit_posts'; } );
			$r = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/ninja-forms/entries/${ made.sub }' ) );
			$w = new WP_REST_Request( 'POST', '/minn-admin/v1/ninja-forms/entries/${ made.sub }/answers' );
			$w->set_header( 'content-type', 'application/json' );
			$w->set_body( wp_json_encode( array( 'values' => array( '${ ids.email }' => 'x@example.com' ) ) ) );
			echo $r->get_status() . ' ' . ( empty( $r->get_data()['edit'] ) ? 'no-edit' : 'edit' ) . ' ' . rest_do_request( $w )->get_status();
		` );
		t.check( 'someone Ninja Forms would not let update submissions cannot edit', /^200 no-edit 403$/.test( noEdit ), noEdit );
	} finally {
		if ( made.sub ) wpEval( `wp_delete_post( ${ made.sub }, true );` );
		if ( made.form ) wpEval( `Ninja_Forms()->form( ${ made.form } )->get()->delete();` );
	}

	await t.done( browser, errors );
} )();
