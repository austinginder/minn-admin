/**
 * Elementor Pro form submissions: editing answers on the entry page.
 *
 * Edit answers lists the submission's answers by the form snapshot's field
 * types (an upload stays in Elementor); Save writes through Elementor's own
 * Query::update_submission (what their REST update calls), keeping a
 * paragraph's line breaks, joining several choices the way their form does,
 * and refusing a bad email on its field. Someone without manage_options sees
 * no edit block.
 *
 * Fixtures: none standing. A draft page carries the form snapshot; one
 * submission is added through Elementor's own Query; both are deleted in
 * finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-ele-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'elementor-forms-entry-edit' );
	const { browser, page, errors } = await launch();
	await login( page );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const stored = ( sid ) => JSON.parse( wpEval( `
		global $wpdb;
		$o = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT \`key\`, value FROM {$wpdb->prefix}e_submissions_values WHERE submission_id = %d", ${ sid } ) ) as $r ) { $o[ $r->key ] = $r->value; }
		echo wp_json_encode( $o );
	` ) || '{}' );

	let made = {};
	try {
		made = JSON.parse( wpEval( `
			$pid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Elementor edit suite' ) );
			$el  = 'minnedit' . wp_rand( 1000, 9999 );
			\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Repositories\\Form_Snapshot_Repository::instance()->create_or_update( $pid, $el, array(
				'name'   => 'Edit suite',
				'fields' => array(
					array( 'id' => 'name', 'type' => 'text', 'label' => 'Name' ),
					array( 'id' => 'email', 'type' => 'email', 'label' => 'Email' ),
					array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
					array( 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => array( 'Sales|sales', 'Help|help' ) ),
					array( 'id' => 'extras', 'type' => 'checkbox', 'label' => 'Extras', 'options' => array( 'News', 'Call' ) ),
					array( 'id' => 'file', 'type' => 'upload', 'label' => 'File' ),
				),
			) );
			$sid = \\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Query::get_instance()->add_submission(
				array( 'post_id' => $pid, 'element_id' => $el, 'form_name' => 'Edit suite', 'referer' => home_url( '/' ), 'referer_title' => 'Home' ),
				array(
					array( 'id' => 'name', 'type' => 'text', 'value' => 'Grace Hopper' ),
					array( 'id' => 'email', 'type' => 'email', 'value' => 'grace@exmaple.com' ),
					array( 'id' => 'message', 'type' => 'textarea', 'value' => 'Hello' ),
					array( 'id' => 'topic', 'type' => 'select', 'value' => 'sales' ),
					array( 'id' => 'extras', 'type' => 'checkbox', 'value' => 'News' ),
					array( 'id' => 'file', 'type' => 'upload', 'value' => 'https://example.com/a.pdf' ),
				)
			);
			echo wp_json_encode( array( 'page' => $pid, 'sub' => $sid ) );
		` ) || '{}' );
		t.check( 'disposable snapshot and submission', made.page > 0 && made.sub > 0, JSON.stringify( made ) );

		await page.goto( `${ BASE }/minn-admin/elementor-forms/entry/${ made.sub }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 60000 } );
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 10000 } );
		const labels = await page.$$eval( '.minn-ep-edit-row > .minn-field-label', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'answers are listed by the snapshot’s labels', [ 'Name', 'Email', 'Message', 'Topic', 'Extras' ].every( ( l ) => labels.includes( l ) ), labels.join( ' · ' ) );
		t.check( 'the upload stays in Elementor', /File/.test( await page.$eval( '.minn-ep-edit-locked', ( el ) => el.textContent ) ) );
		t.check( 'a paragraph edits in a text area', await page.$eval( '[data-epf="message"]', ( el ) => 'TEXTAREA' === el.tagName ) );

		// A bad email is refused on its field.
		await page.fill( '[data-epf="email"]', 'nope' );
		await page.click( '#minn-ep-save' );
		await page.waitForSelector( '[data-epset="email"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a bad email is refused on its field', stored( made.sub ).email === 'grace@exmaple.com' );

		await page.fill( '[data-epf="email"]', 'grace@example.com' );
		await page.fill( '[data-epf="message"]', 'Hello\nSecond line' );
		await page.click( '[data-epchoice="topic"] .minn-ac-input' );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( 'Help' );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
		await page.click( '[data-epm="extras"][value="Call"]' );
		await page.click( '#minn-ep-save' );
		await toast( 'Answers saved' );
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		const v = stored( made.sub );
		t.check( 'saved through Elementor’s own update', v.email === 'grace@example.com' && v.topic === 'help', JSON.stringify( v ) );
		t.check( 'the paragraph keeps its line break', v.message === 'Hello\nSecond line', JSON.stringify( v.message ) );
		t.check( 'several choices are joined the way their form joins them', v.extras === 'News, Call', v.extras );
		t.check( 'the upload is untouched', v.file === 'https://example.com/a.pdf' );

		const noEdit = wpEval( `
			$u = get_user_by( 'login', 'minn-editor' );
			wp_set_current_user( $u ? $u->ID : 0 );
			$w = new WP_REST_Request( 'POST', '/minn-admin/v1/elementor/submissions/${ made.sub }/answers' );
			$w->set_header( 'content-type', 'application/json' );
			$w->set_body( wp_json_encode( array( 'values' => array( 'email' => 'x@example.com' ) ) ) );
			echo rest_do_request( $w )->get_status();
		` );
		t.check( 'someone without manage_options cannot save', '403' === noEdit, noEdit );
	} finally {
		if ( made.sub ) wpEval( `\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Query::get_instance()->delete_submission( ${ made.sub } );` );
		if ( made.page ) wpEval( `wp_delete_post( ${ made.page }, true );` );
	}

	await t.done( browser, errors );
} )();
