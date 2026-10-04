/**
 * The merge tag picker in the Gravity Forms form builder.
 *
 * An HTML block's content offers the form's field tags (never {all_fields},
 * and never a field removed on the page), inserted at the cursor; a default
 * value offers only the tags their editor offers there (user, date, embed…,
 * no field or entry tags). Both save.
 *
 * Fixtures: none standing. A disposable form is made with GFAPI and deleted
 * in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-gfbt-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'gf-builder-tags' );
	const { browser, page, errors } = await launch();
	await login( page );

	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const popTags = () => page.$$eval( '.minn-gfn-tagpop [data-tag]', ( els ) => els.map( ( e ) => e.dataset.tag ) );
	const closePop = () => page.evaluate( () => document.querySelectorAll( '.minn-gfn-tagpop' ).forEach( ( e ) => e.remove() ) );
	const selectCell = async ( id ) => {
		await page.click( `.minn-gfb-cell[data-gk="f${ id }"]` );
		await page.waitForSelector( '#minn-gfb-panel [data-gf]', { timeout: 10000 } );
	};

	let fid = 0;
	try {
		fid = parseInt( wpEval( `
			echo GFAPI::add_form( array( 'title' => 'Builder tags suite ' . time(), 'fields' => array(
				array( 'id' => 1, 'type' => 'text', 'label' => 'Full name' ),
				array( 'id' => 2, 'type' => 'email', 'label' => 'Work email' ),
				array( 'id' => 3, 'type' => 'html', 'label' => 'Intro', 'content' => '' ),
				array( 'id' => 4, 'type' => 'hidden', 'label' => 'Owner' ),
			) ) );
		` ), 10 ) || 0;
		t.check( 'disposable form', fid > 0, String( fid ) );
		await page.goto( `${ BASE }/minn-admin/gravity-forms/form/${ fid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfb #minn-gfb-desc', { timeout: 60000 } );

		// The HTML block: the form's field tags.
		await selectCell( 3 );
		t.check( 'the HTML content has the tag picker', !! await page.$( '[data-gfbtags="content"]' ) );
		await page.fill( '[data-gf="content"]', 'Hello ' );
		await page.click( '[data-gfbtags="content"]' );
		await page.waitForSelector( '.minn-gfn-tagpop [data-tag]', { timeout: 5000 } );
		let tags = await popTags();
		t.check( 'it offers the form’s field tags', tags.includes( '{Full name:1}' ) && tags.includes( '{Work email:2}' ), tags.slice( 0, 8 ).join( ' ' ) );
		t.check( 'but not {all_fields}', ! tags.includes( '{all_fields}' ) );
		await page.keyboard.type( 'Full name' );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		t.check( 'the tag goes in at the cursor', await page.$eval( '[data-gf="content"]', ( el ) => el.value ) === 'Hello {Full name:1}' );

		// A field removed on the page is no longer offered.
		await page.click( '.minn-gfb-cell[data-gk="f2"] [data-gfbdel]' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForTimeout( 400 );
		await selectCell( 3 );
		await page.click( '[data-gfbtags="content"]' );
		await page.waitForSelector( '.minn-gfn-tagpop [data-tag]', { timeout: 5000 } );
		tags = await popTags();
		t.check( 'a field removed on the page is not offered', ! tags.some( ( x ) => /:2\}$/.test( x ) ) && tags.includes( '{Full name:1}' ), tags.slice( 0, 8 ).join( ' ' ) );
		await closePop();

		// A default value: only the tags their editor offers there.
		await selectCell( 4 );
		t.check( 'a default value has the tag picker', !! await page.$( '[data-gfbtags="defaultValue"]' ) );
		await page.click( '[data-gfbtags="defaultValue"]' );
		await page.waitForSelector( '.minn-gfn-tagpop [data-tag]', { timeout: 5000 } );
		tags = await popTags();
		t.check( 'it offers user and site tags', tags.includes( '{user:user_email}' ) && tags.includes( '{date_mdy}' ) && tags.includes( '{embed_url}' ), tags.join( ' ' ) );
		t.check( 'and no field, entry or form tags', ! tags.some( ( x ) => /:\d+\}$/.test( x ) ) && ! tags.includes( '{entry_id}' ) && ! tags.includes( '{form_title}' ) && ! tags.includes( '{all_fields}' ) );
		await page.keyboard.type( 'user email' );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		t.check( 'the default takes the tag', await page.$eval( '[data-gf="defaultValue"]', ( el ) => el.value ) === '{user:user_email}' );

		// Both save.
		await page.click( '#minn-gfb-save' );
		await toast( 'Form saved' );
		const saved = JSON.parse( wpEval( `
			$f = GFAPI::get_form( ${ fid } );
			$o = array();
			foreach ( $f['fields'] as $x ) { $o[ $x->id ] = array( 'content' => $x->content, 'defaultValue' => $x->defaultValue ); }
			echo wp_json_encode( $o );
		` ) || '{}' );
		t.check( 'the HTML content saved with its tag', saved[ 3 ] && saved[ 3 ].content === 'Hello {Full name:1}', JSON.stringify( saved[ 3 ] ) );
		t.check( 'the default value saved with its tag', saved[ 4 ] && saved[ 4 ].defaultValue === '{user:user_email}', JSON.stringify( saved[ 4 ] ) );
		t.check( 'the removed field went with the save', ! saved[ 2 ] );
	} finally {
		if ( fid ) wpEval( `GFAPI::delete_form( ${ fid } );` );
	}

	await t.done( browser, errors );
} )();
