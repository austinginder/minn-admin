/**
 * The Gravity Forms confirmation page (/gravity-forms/confirmation/{form}:{id}).
 *
 * The Confirmations view and its columns; the form's default confirmation
 * (no name to change, no conditions, no switch, no delete); a merge tag
 * through the picker and the preview rendering the latest entry; ⌘S;
 * New confirmation from the list starts a page that saves nothing until
 * Create; an inline refusal (empty redirect address, a taken name) that
 * saves nothing; redirect with the answers in the query string and its
 * preview address; conditional logic; the Page type; the Active switch;
 * duplicate and delete from the More menu; the unsaved-changes guard; the
 * server refusing to delete or switch off the default; the unsafe merge tag
 * filter for people without unfiltered_html; anonymous requests refused.
 *
 * Fixtures: none standing. A disposable form (Name text 1, Topic select 2
 * with Sales / Help) and one entry are made with GFAPI and deleted in
 * finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter, listSettled } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-gfc-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'gf-confirmation-page' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', ...( a.anon ? {} : { 'X-WP-Nonce': window.MINN.nonce } ) },
			...( a.anon ? { credentials: 'omit' } : {} ),
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const pickCombo = async ( sel, text ) => {
		await page.click( `${ sel } .minn-ac-input` );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( text );
		await page.waitForTimeout( 200 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
	};
	const toast = ( re ) => page.waitForFunction( ( src ) => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => new RegExp( src ).test( x.textContent ) ), re, { timeout: 30000 } );
	const clearToasts = () => page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
	const moreMenu = async ( label ) => {
		await page.click( '#minn-gfc-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		return page.evaluate( ( l ) => {
			const items = [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( b ) => b.textContent.trim() );
			const hit = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === l );
			if ( hit ) hit.click();
			else document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
			return items;
		}, label );
	};
	const listForm = async ( fid ) => ( ( await rest( `minn-admin/v1/gf/forms/${ fid }/confirmations` ) ).body || {} ).items || [];
	const full = async ( id ) => ( await rest( `minn-admin/v1/gf/confirmations/${ id }/full` ) ).body;
	const openPage = async ( id ) => {
		await page.goto( `${ BASE }/minn-admin/gravity-forms/confirmation/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfc .minn-gfn-sec', { timeout: 60000 } );
	};
	const previewText = ( re ) => page.waitForFunction( ( src ) => {
		const body = document.querySelector( '#minn-gfc-preview-body' );
		if ( ! body ) return false;
		const frame = body.querySelector( 'iframe' );
		const text = frame ? ( frame.getAttribute( 'srcdoc' ) || '' ) : body.textContent;
		return new RegExp( src ).test( text );
	}, re, { timeout: 30000 } );

	const stamp = String( Date.now() ).slice( -6 );
	let fid = 0;
	try {
		fid = parseInt( wpEval( `
			$fid = GFAPI::add_form( array( 'title' => 'Confirm suite ${ stamp }', 'fields' => array(
				array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ),
				array( 'id' => 2, 'type' => 'select', 'label' => 'Topic', 'choices' => array( array( 'text' => 'Sales', 'value' => 'sales' ), array( 'text' => 'Help', 'value' => 'help' ) ) ),
			) ) );
			GFAPI::add_entry( array( 'form_id' => $fid, '1' => 'Dana Tester', '2' => 'sales' ) );
			echo $fid;
		` ), 10 ) || 0;
		t.check( 'disposable form and entry', fid > 0, String( fid ) );
		const def = ( await listForm( fid ) )[ 0 ];
		t.check( 'a new form lists its default confirmation', def && def.default === '1' && def.when === 'Default' && def.toggle === '', JSON.stringify( def ) );

		// The Confirmations view.
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 60000 } );
		const viewSel = await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '[data-sview]' ) ].find( ( x ) => x.textContent.trim() === 'Confirmations' );
			return b ? `[data-sview="${ b.dataset.sview }"]` : '';
		} );
		t.check( 'the switcher offers Confirmations', !! viewSel );
		await page.click( viewSel );
		await listSettled( page );
		const heads = await page.$$eval( '.minn-table-head > *, .minn-table thead th', ( els ) => els.map( ( e ) => e.textContent.trim() ).filter( Boolean ) );
		t.check( 'the list shows type, what it shows and when', [ 'Confirmation', 'Form', 'Type', 'Shows', 'When' ].every( ( h ) => heads.includes( h ) ), heads.join( ' · ' ) );
		await page.click( '.minn-table-row' );
		await page.waitForURL( /\/gravity-forms\/confirmation\/\d+(%3A|:)[a-z0-9]+$/, { timeout: 30000 } );
		t.check( 'a list row opens its confirmation page', true );

		// The default confirmation: fixed name, no conditions, no switch, no delete.
		await openPage( def.id );
		t.check( 'the default shows its name fixed, marked Default', await page.$eval( '.minn-gfc-fixed', ( el ) => /Default Confirmation/.test( el.textContent ) && /Default/.test( el.querySelector( '.minn-gfc-default' ).textContent ) ) && ! await page.$( '.minn-gfc [data-gfn="name"]' ) && ! await page.$( '#minn-gfc-active' ) );
		t.check( 'the default has no conditions of its own', ! await page.$( '[data-gfcsw="logic"]' ) && /no other confirmation applies/.test( await page.$eval( '[data-gfnsec="when"]', ( el ) => el.textContent ) ) );
		const defMenu = await moreMenu( '' );
		t.check( 'the default offers no Delete', ! defMenu.includes( 'Delete' ) && defMenu.includes( 'Duplicate' ), defMenu.join( ' · ' ) );

		// A merge tag through the picker; the preview renders the latest entry.
		await page.fill( '[data-gfn="message"]', 'Thanks ' );
		await page.click( '[data-gfn="message"]' );
		await page.keyboard.press( 'Meta+ArrowDown' );
		await page.click( '[data-gfntags="message"]' );
		await page.waitForSelector( '.minn-gfn-tagpop input', { timeout: 5000 } );
		await page.keyboard.type( 'Name' );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		const msg = await page.$eval( '[data-gfn="message"]', ( el ) => el.value );
		t.check( 'the picker inserts the merge tag at the cursor', msg === 'Thanks {Name:1}', msg );
		await previewText( 'Thanks Dana Tester' );
		t.check( 'the preview renders the message with the latest entry', /With entry #\d+/.test( await page.$eval( '#minn-gfc-preview-note', ( el ) => el.textContent ) ) );
		t.check( 'the message preview is a sandboxed frame', await page.$eval( '#minn-gfc-preview-body iframe', ( el ) => el.getAttribute( 'sandbox' ) === '' ) );

		// ⌘S saves.
		await clearToasts();
		await page.keyboard.press( 'Meta+s' );
		await toast( 'Confirmation saved' );
		t.check( '⌘S saves the confirmation', ( await full( def.id ) ).confirmation.message === 'Thanks {Name:1}' );

		// New confirmation from the list: an unsaved page for the chosen form.
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( viewSel, { timeout: 60000 } );
		await page.click( viewSel );
		await listSettled( page );
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="form"]', { timeout: 10000 } );
		await pickCombo( '[data-createfield="form"]', `Confirm suite ${ stamp }` );
		await page.click( '#minn-surface-create' );
		await page.waitForURL( new RegExp( `/gravity-forms/confirmation/${ fid }(%3A|:)new$` ), { timeout: 30000 } );
		await page.waitForSelector( '.minn-gfc [data-gfn="name"]', { timeout: 30000 } );
		t.check( 'New confirmation opens an unsaved page for the form', /Not saved yet/.test( await page.$eval( '.minn-gfc .minn-fgb-meta', ( el ) => el.textContent ) ) );
		t.check( 'nothing is saved until Create', ( await listForm( fid ) ).length === 1 );

		// Redirect: an empty address is refused inline and nothing saves.
		const name = 'Sales thanks ' + stamp;
		await page.fill( '[data-gfn="name"]', name );
		await page.click( '[data-gfctype="redirect"]' );
		await page.waitForSelector( '[data-gfn="url"]', { timeout: 5000 } );
		await clearToasts();
		await page.click( '#minn-gfc-save' );
		await page.waitForSelector( '[data-gfnset="url"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'an empty redirect address is refused inline', /full address/.test( await page.$eval( '[data-gfnset="url"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) );
		t.check( 'the refused create saved nothing', ( await listForm( fid ) ).length === 1 );

		// The address with the answers passed along; the preview shows where visitors go.
		await page.fill( '[data-gfn="url"]', 'https://example.com/thanks' );
		await page.fill( '[data-gfn="queryString"]', 'name={Name:1}' );
		await previewText( 'https://example\\.com/thanks\\?name=Dana\\+Tester' );
		t.check( 'the preview shows the address a visitor lands on', true );

		// Conditional logic: only when Topic is Sales.
		await page.click( '[data-gfcsw="logic"]' );
		await page.waitForSelector( '[data-rf="logic:0"]', { timeout: 5000 } );
		await pickCombo( '[data-rf="logic:0"]', 'Topic' );
		await page.waitForSelector( '[data-rv="logic:0"]', { timeout: 5000 } );
		await pickCombo( '[data-rv="logic:0"]', 'Sales' );

		// Create through Gravity Forms' own save.
		await clearToasts();
		await page.click( '#minn-gfc-save' );
		await toast( 'Confirmation created' );
		await page.waitForURL( new RegExp( `/gravity-forms/confirmation/${ fid }(%3A|:)[a-z0-9]+$` ), { timeout: 30000 } );
		const id = decodeURIComponent( page.url().split( '/' ).pop() );
		t.check( 'Create gives the page its saved address', ! /new$/.test( id ), id );
		let saved = ( await full( id ) ).confirmation;
		t.check( 'saved: name, redirect, query string', saved.name === name && saved.type === 'redirect' && saved.url === 'https://example.com/thanks' && saved.queryString === 'name={Name:1}', JSON.stringify( saved ) );
		t.check( 'saved: conditional logic', saved.conditionalLogic && saved.conditionalLogic.rules.length === 1 && saved.conditionalLogic.rules[ 0 ].fieldId === '2' && saved.conditionalLogic.rules[ 0 ].value === 'sales', JSON.stringify( saved.conditionalLogic ) );

		// A taken name is refused inline.
		await page.fill( '[data-gfn="name"]', 'default confirmation' );
		await clearToasts();
		await page.click( '#minn-gfc-save' );
		await page.waitForSelector( '[data-gfnset="name"].minn-gfn-err', { timeout: 30000 } );
		t.check( 'a name another confirmation uses is refused inline', /already uses that name/.test( await page.$eval( '[data-gfnset="name"] .minn-gfn-errmsg', ( el ) => el.textContent ) ) && ( await full( id ) ).confirmation.name === name );
		await page.fill( '[data-gfn="name"]', name );

		// Leaving with unsaved changes asks first; staying keeps them.
		await page.click( '#minn-gfc-back' );
		await page.waitForSelector( '.minn-confirm-modal [data-cancel]', { timeout: 5000 } );
		t.check( 'leaving with unsaved changes asks first', true );
		await page.click( '.minn-confirm-modal [data-cancel]' );
		await page.waitForTimeout( 300 );

		// The Page type, and the Active switch.
		const pg = ( await rest( 'wp/v2/pages?per_page=1&status=publish&orderby=title&order=asc&_fields=id,title,link' ) ).body[ 0 ];
		await page.click( '[data-gfctype="page"]' );
		await page.waitForSelector( '[data-gfccombo="pageId"]', { timeout: 5000 } );
		await pickCombo( '[data-gfccombo="pageId"]', pg.title.rendered.replace( /&#8217;/g, '’' ) );
		await previewText( pg.link.replace( /[.?+]/g, '\\$&' ) );
		t.check( 'the preview shows the chosen page’s address', true );
		await page.click( '#minn-gfc-active' );
		await clearToasts();
		await page.click( '#minn-gfc-save' );
		await toast( 'Confirmation saved' );
		saved = ( await full( id ) ).confirmation;
		const row = ( await listForm( fid ) ).find( ( r ) => r.id === id );
		t.check( 'saved: the page, switched off', saved.type === 'page' && saved.pageId === String( pg.id ) && saved.isActive === false && row && row.status === 'inactive' && row.toggle === 'activate', JSON.stringify( { saved, row } ) );

		// Duplicate, then delete the copy.
		await moreMenu( 'Duplicate' );
		await page.waitForFunction( ( was ) => decodeURIComponent( location.pathname.split( '/' ).pop() ) !== was, id, { timeout: 30000 } );
		await page.waitForSelector( '.minn-gfc [data-gfn="name"]', { timeout: 30000 } );
		const copyId = decodeURIComponent( page.url().split( '/' ).pop() );
		t.check( 'Duplicate opens the copy, named as Gravity Forms names copies', ( await page.$eval( '[data-gfn="name"]', ( el ) => el.value ) ) === name + ' (1)' && copyId !== id );
		await moreMenu( 'Delete' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => ! /\/confirmation\//.test( location.pathname ), null, { timeout: 30000 } );
		t.check( 'Delete removes the copy and returns to the list', ! ( await listForm( fid ) ).some( ( x ) => x.id === copyId ) );
		await listSettled( page );
		t.check( 'the list it returns to is Confirmations', await page.$eval( viewSel, ( el ) => el.classList.contains( 'active' ) || el.getAttribute( 'aria-pressed' ) === 'true' || el.getAttribute( 'aria-selected' ) === 'true' ) );

		// The server keeps the default on and in place.
		t.check( 'the default cannot be switched off', ( await rest( `minn-admin/v1/gf/confirmations/${ def.id }/active`, { method: 'POST', body: { active: false } } ) ).status === 400 );
		t.check( 'the default cannot be deleted', ( await rest( `minn-admin/v1/gf/confirmations/${ def.id }`, { method: 'DELETE' } ) ).status === 400 );

		// Their filter for people without unfiltered_html: a merge tag used as an attribute value is emptied.
		const filtered = wpEval( `
			wp_set_current_user( 1 );
			add_filter( 'user_has_cap', function ( $c ) { $c['unfiltered_html'] = false; return $c; } );
			echo minn_admin_gfc_kses( '<a href="{Name:1}">x</a> {Name:1}' );
		` );
		t.check( 'without unfiltered_html, attribute merge tags are emptied', /href=""/.test( filtered ) && /\{Name:1\}$/.test( filtered ), filtered );

		t.check( 'anonymous requests are refused', ( await rest( `minn-admin/v1/gf/confirmations/${ def.id }/full`, { anon: true } ) ).status === 401 );
	} finally {
		if ( fid ) wpEval( `GFAPI::delete_form( ${ fid } );` );
	}

	await t.done( browser, errors );
} )();
