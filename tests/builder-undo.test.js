/**
 * Undo and redo on the builder pages (makeHistory in app.js).
 *
 * The form builder: adding a field undoes and redoes with ⌘Z / ⇧⌘Z (even
 * with focus left in the new field's label), typing into a box is one step,
 * a text box keeps its own ⌘Z while you type in it, the toolbar arrows do
 * the same as the keys, undoing back to what was saved clears "Unsaved
 * changes" (and leaving then asks nothing), and saving starts the history
 * over. The field group builder, the notification page and the
 * confirmation page undo their own edits the same way.
 *
 * Fixtures: none standing. A disposable Gravity Forms form and an ACF field
 * group are made with their plugins' APIs and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-undo-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'builder-undo' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const cells = () => page.$$eval( '.minn-gfb-cell', ( els ) => els.length );
	const dirty = () => page.$( '.minn-fgb-top .minn-fgb-dirty' ).then( ( el ) => !! el );
	const canUndo = () => page.$eval( '[data-minnundo="back"]', ( el ) => ! el.disabled );
	const canRedo = () => page.$eval( '[data-minnundo="fwd"]', ( el ) => ! el.disabled );
	const undo = async () => { await page.keyboard.press( 'Meta+z' ); await page.waitForTimeout( 350 ); };
	const redo = async () => { await page.keyboard.press( 'Meta+Shift+z' ); await page.waitForTimeout( 350 ); };
	const blur = () => page.evaluate( () => document.activeElement && document.activeElement.blur() );

	let fid = 0;
	let groupId = 0;
	try {
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 60000 } );
		const made = await rest( 'minn-admin/v1/gf/forms', { method: 'POST', body: { title: 'Undo suite ' + Date.now() } } );
		fid = made.body && made.body.id;
		t.check( 'disposable form', !! fid, JSON.stringify( made.body ) );

		/* ===== The form builder ===== */
		await page.goto( `${ BASE }/minn-admin/gravity-forms/form/${ fid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfb #minn-gfb-desc', { timeout: 60000 } );
		t.check( 'a fresh page has nothing to undo or redo', ! await canUndo() && ! await canRedo() );

		await page.click( '[data-gfbadd="text"]' );
		await page.waitForSelector( '#minn-gfb-panel [data-gf="label"]', { timeout: 10000 } );
		t.check( 'adding a field arms Undo', await cells() === 1 && await canUndo() );
		// Focus sits in the new field's label, untouched: ⌘Z undoes the add.
		await undo();
		t.check( '⌘Z undoes adding the field', await cells() === 0 );
		t.check( 'back at the saved form, "Unsaved changes" clears', ! await dirty() );
		t.check( 'and Redo is armed', await canRedo() );
		await redo();
		t.check( '⇧⌘Z brings the field back', await cells() === 1 && await dirty() );

		// Typing into a box is one step.
		await page.click( '.minn-gfb-cell' );
		await page.waitForSelector( '#minn-gfb-panel [data-gf="label"]', { timeout: 10000 } );
		const before = await page.$eval( '#minn-gfb-panel [data-gf="label"]', ( el ) => el.value );
		await page.click( '#minn-gfb-panel [data-gf="label"]' );
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.type( 'Your name' );
		await blur();
		t.check( 'the canvas shows the typed label', /Your name/.test( await page.$eval( '.minn-gfb-cell', ( el ) => el.textContent ) ) );
		await undo();
		const after = await page.$eval( '.minn-gfb-cell', ( el ) => el.textContent );
		t.check( 'one ⌘Z undoes the whole typing, not a letter', ! /Your name|Your nam/.test( after ) && after.includes( before ), after.trim() );
		await redo();
		t.check( 'and redo puts it back', /Your name/.test( await page.$eval( '.minn-gfb-cell', ( el ) => el.textContent ) ) );

		// The toolbar arrows: a width change.
		await page.click( '.minn-gfb-cell' );
		await page.waitForSelector( '[data-gfw="6"]', { timeout: 10000 } );
		await page.click( '[data-gfw="6"]' );
		const span = () => page.$eval( '.minn-gfb-cell', ( el ) => el.getAttribute( 'style' ) || el.className );
		const half = await span();
		await page.click( '[data-minnundo="back"]' );
		await page.waitForTimeout( 350 );
		const full = await span();
		t.check( 'the Undo arrow undoes the width change', half !== full, `${ half } → ${ full }` );
		await page.click( '[data-minnundo="fwd"]' );
		await page.waitForTimeout( 350 );
		t.check( 'the Redo arrow redoes it', await span() === half );

		// A text box keeps its own ⌘Z while you type in it.
		await page.click( '#minn-gfb-desc' );
		await page.keyboard.type( 'Draft' );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 350 );
		t.check( 'typing in a box: ⌘Z is the box’s own undo', await page.$eval( '#minn-gfb-desc', ( el ) => el.value ) === '' && await cells() === 1 );

		// Saving starts the history over.
		await page.click( '#minn-gfb-save' );
		await toast( 'Form saved' );
		await page.waitForTimeout( 400 );
		t.check( 'after saving there is nothing to undo', ! await canUndo() && ! await canRedo() );
		const saved = ( await rest( `minn-admin/v1/gf/forms/${ fid }/builder` ) ).body;
		t.check( 'what saved is what the page showed', saved.fields.length === 1 && /Your name/.test( saved.fields[ 0 ].label ) && ! saved.form.description, JSON.stringify( { n: saved.fields.length, l: saved.fields[ 0 ] && saved.fields[ 0 ].label, d: saved.form.description } ) );

		// Undo back to the saved state: leaving asks nothing.
		await page.click( '#minn-gfb-title' );
		await page.keyboard.type( ' edited' );
		await blur();
		t.check( 'an edit marks the form unsaved', await dirty() );
		await undo();
		t.check( 'undoing it leaves the form saved', ! await dirty() );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const asked = await page.waitForSelector( '.minn-confirm-modal', { timeout: 2500 } ).then( () => true ).catch( () => false );
		t.check( 'and leaving asks nothing', ! asked && /\/overview$/.test( page.url() ) );

		/* ===== The notification page ===== */
		const nid = Object.keys( JSON.parse( wpEval( `echo wp_json_encode( GFAPI::get_form( ${ fid } )['notifications'] );` ) || '{}' ) )[ 0 ];
		await page.goto( `${ BASE }/minn-admin/gravity-forms/notification/${ fid }:${ nid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfn [data-gfn="subject"]', { timeout: 60000 } );
		const subj = await page.$eval( '[data-gfn="subject"]', ( el ) => el.value );
		await page.fill( '[data-gfn="subject"]', 'Changed subject' );
		await page.click( '[data-gfntotype="routing"]' );
		await page.waitForSelector( '[data-gfnremail="0"]', { timeout: 5000 } ).catch( () => {} );
		await undo();
		t.check( 'the notification page undoes a recipient switch', await page.$eval( '[data-gfntotype="email"]', ( el ) => el.classList.contains( 'active' ) ) );
		await undo();
		t.check( '…then the subject', await page.$eval( '[data-gfn="subject"]', ( el ) => el.value ) === subj && ! await dirty() );

		/* ===== The confirmation page ===== */
		const cid = ( ( await rest( `minn-admin/v1/gf/forms/${ fid }/confirmations` ) ).body.items[ 0 ] || {} ).id;
		await page.goto( `${ BASE }/minn-admin/gravity-forms/confirmation/${ cid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfc [data-gfctype="redirect"]', { timeout: 60000 } );
		await page.click( '[data-gfctype="redirect"]' );
		await page.waitForSelector( '[data-gfn="url"]', { timeout: 5000 } );
		await undo();
		t.check( 'the confirmation page undoes a type switch', await page.$eval( '[data-gfctype="message"]', ( el ) => el.classList.contains( 'active' ) ) && !! await page.$( '[data-gfn="message"]' ) && ! await dirty() );

		/* ===== The field group builder ===== */
		const g = JSON.parse( wpEval( `
			$g = acf_update_field_group( array( 'title' => 'Undo Suite Group', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
			echo wp_json_encode( array( 'id' => $g['ID'], 'key' => $g['key'] ) );
		` ) || '{}' );
		groupId = g.id || 0;
		t.check( 'disposable field group', !! g.key, JSON.stringify( g ) );
		await page.goto( `${ BASE }/minn-admin/field-groups/${ g.key }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-fgb #minn-fgb-add', { timeout: 60000 } );
		const rows = () => page.$$eval( '.minn-fgb-rows > .minn-fgb-row', ( els ) => els.length );
		await page.click( '#minn-fgb-add' );
		await page.waitForTimeout( 400 );
		await page.click( '#minn-fgb-add' );
		await page.waitForTimeout( 400 );
		t.check( 'two fields added', await rows() === 2 );
		await blur();
		await undo();
		t.check( 'the field group builder undoes one add at a time', await rows() === 1 );
		await undo();
		t.check( '…back to the saved group', await rows() === 0 && ! await dirty() );
		await redo();
		t.check( 'and redoes', await rows() === 1 );
		await undo();
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
	} finally {
		if ( fid ) wpEval( `GFAPI::delete_form( ${ fid } );` );
		if ( groupId ) wpEval( `acf_delete_field_group( ${ groupId } );` );
	}

	await t.done( browser, errors );
} )();
