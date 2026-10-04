/**
 * Leaving a page with unsaved edits asks first, every way out, and ⌘S saves
 * the page in front of you.
 *
 * Pages that hold edits until Save (the form builder, the notification page,
 * plugin settings forms…) used to ask only from their own Back button and the
 * tab close. Covered here: the sidebar, ⌘K, the browser's Back (the address
 * is put back while the person decides), switching a settings view, a reload
 * (the browser's own prompt), and staying keeps the edits. A page with
 * nothing unsaved never asks. ⌘S presses the page's Save on the builder, the
 * notification page and a settings form.
 *
 * Fixtures: none standing. A disposable Gravity Forms form is created over
 * REST and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-leave-guard-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'leave-guard' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const asked = () => page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 6000 } ).then( () => true ).catch( () => false );
	const answer = async ( leave ) => {
		await page.click( leave ? '.minn-confirm-modal [data-ok]' : '.minn-confirm-modal [data-cancel]' );
		await page.waitForTimeout( 400 );
	};
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const openBuilder = async ( id ) => {
		await page.goto( `${ BASE }/minn-admin/gravity-forms/form/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfb #minn-gfb-desc', { timeout: 60000 } );
	};

	let formId = 0;
	try {
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 60000 } );
		const made = await rest( 'minn-admin/v1/gf/forms', { method: 'POST', body: { title: 'Leave guard ' + Date.now() } } );
		formId = made.body && made.body.id;
		t.check( 'disposable form', !! formId, JSON.stringify( made.body ) );

		// A page with nothing unsaved never asks.
		await openBuilder( formId );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		t.check( 'an unchanged page leaves without asking', ! await asked() && /\/overview$/.test( page.url() ) );

		// The sidebar: asks, staying keeps the edit, leaving goes.
		await openBuilder( formId );
		await page.fill( '#minn-gfb-desc', 'Unsaved description' );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		t.check( 'the sidebar asks before leaving unsaved edits', await asked() );
		await answer( false );
		t.check( 'staying keeps the page and the edit', /\/gravity-forms\/form\//.test( page.url() ) && await page.$eval( '#minn-gfb-desc', ( el ) => el.value ) === 'Unsaved description' );

		// ⌘K: the palette's navigation asks the same way.
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'Overview' );
		await page.waitForTimeout( 400 );
		await page.keyboard.press( 'Enter' );
		t.check( '⌘K asks before leaving unsaved edits', await asked() );
		await answer( false );
		t.check( 'and staying keeps the edit', await page.$eval( '#minn-gfb-desc', ( el ) => el.value ) === 'Unsaved description' );

		// Leaving goes (and discards).
		const builderUrl = page.url();
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		await asked();
		await answer( true );
		t.check( 'choosing Leave goes', /\/overview$/.test( page.url() ) );

		// The browser's history inside the app (Back / Forward): asks, and the
		// address comes back while the person decides.
		await page.goBack( { waitUntil: 'commit' } ).catch( () => {} );
		await page.waitForSelector( '.minn-gfb #minn-gfb-desc', { timeout: 60000 } );
		t.check( 'Back to the builder brings the saved form, not the discarded edit', await page.$eval( '#minn-gfb-desc', ( el ) => el.value ) === '' );
		await page.fill( '#minn-gfb-desc', 'Unsaved description' );
		await page.goForward( { waitUntil: 'commit' } ).catch( () => {} );
		t.check( 'the browser’s Forward / Back asks before leaving unsaved edits', await asked() );
		t.check( 'the page’s address is put back while it asks', page.url() === builderUrl, page.url() );
		await answer( false );
		t.check( 'staying keeps the edit', await page.$eval( '#minn-gfb-desc', ( el ) => el.value ) === 'Unsaved description' );

		// A reload: the browser's own prompt.
		let unload = false;
		const onDialog = ( d ) => {
			if ( 'beforeunload' === d.type() ) unload = true;
			d.dismiss().catch( () => {} );
		};
		page.on( 'dialog', onDialog );
		await page.reload( { waitUntil: 'domcontentloaded', timeout: 5000 } ).catch( () => {} );
		await page.waitForTimeout( 500 );
		page.off( 'dialog', onDialog );
		t.check( 'a reload gets the browser’s unsaved-changes prompt', unload );
		t.check( 'dismissing it stays on the page', await page.$eval( '#minn-gfb-desc', ( el ) => el.value ).catch( () => '' ) === 'Unsaved description' );

		// ⌘S saves the builder; then leaving needs no answer.
		await page.click( '#minn-gfb-desc' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Form saved' );
		t.check( '⌘S saves the form builder', ( await rest( `minn-admin/v1/gf/forms/${ formId }/builder` ) ).body.form.description === 'Unsaved description' );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		t.check( 'after saving, leaving does not ask', ! await asked() && /\/overview$/.test( page.url() ) );

		// The notification page: ⌘S saves it.
		const nid = Object.keys( JSON.parse( wpEval( `echo wp_json_encode( GFAPI::get_form( ${ formId } )['notifications'] );` ) || '{}' ) )[ 0 ];
		t.check( 'the new form has its default notification', !! nid, String( nid ) );
		await page.goto( `${ BASE }/minn-admin/gravity-forms/notification/${ formId }%3A${ nid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfn [data-gfn="subject"]', { timeout: 60000 } );
		await page.fill( '[data-gfn="subject"]', 'Saved with the keyboard' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Notification saved' );
		t.check( '⌘S saves the notification page', ( await rest( `minn-admin/v1/gf/notifications/${ formId }:${ nid }/full` ) ).body.notification.subject === 'Saved with the keyboard' );

		// A settings form (the form's Gravity Forms settings): switching the
		// view asks; ⌘S saves.
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
		await page.click( '[data-sview="manage"]' );
		await page.waitForFunction( ( id ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( 'Leave guard' ) ) && ! document.querySelector( '.minn-table.minn-busy' ), formId, { timeout: 30000 } );
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( 'Leave guard' ) );
			row.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 300, clientY: 300 } ) );
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 10000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Form settings' ).click() );
		await page.waitForSelector( '[data-sset="description"]', { timeout: 30000 } );
		await page.fill( '[data-sset="description"]', 'From the settings form' );
		await page.click( '[data-sview="main"]' );
		t.check( 'switching away from a settings form with unsaved edits asks', await asked() );
		await answer( false );
		t.check( 'staying keeps the settings edit', await page.$eval( '[data-sset="description"]', ( el ) => el.value ) === 'From the settings form' );
		await page.click( '[data-sset="description"]' );
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Settings saved' );
		t.check( '⌘S saves a settings form', ( await rest( `minn-admin/v1/gf/forms/${ formId }/builder` ) ).body.form.description === 'From the settings form' );
	} finally {
		if ( formId ) wpEval( `GFAPI::delete_form( ${ formId } );` );
	}

	await t.done( browser, errors );
} )();
