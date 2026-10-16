/**
 * v0.46 pre-RC, the client half of "a save rewrites what nobody changed":
 * a save sends a field only when it now reads differently from what it
 * showed when it was drawn, and an Apply changes only what was changed.
 *
 *   Settings forms (surface settings tabs, the Store settings page and its
 *   form dialogs): a field typed into and put back is not sent, so the
 *   vendor never cleans and rewrites the stored value. Minn's own settings:
 *   a switch flipped and flipped back is not sent, and a text box that
 *   cannot show a stored line break does not send the value without it.
 *   Entry answers: an "other" box that only gained and lost focus keeps the
 *   stored answer (it used to turn an answer reading like a choice's label
 *   into that choice), and a text answer typed into and put back keeps its
 *   line break.
 *   Block inspector (ACF block fields): Apply rewrites only the fields
 *   changed; a number, a "1", a line break and an unset select stay as
 *   stored, an untouched Apply leaves the block's bytes, and the toolbar
 *   popover keeps the same rule.
 *   Link and image popovers: Apply keeps a rel the popover has no control
 *   for (nofollow), and the image popover keeps a caption's markup when
 *   only the alt text changed.
 *   Navigation items, terms and the editor excerpt: only a changed box is
 *   written; a Save with nothing changed writes nothing.
 *   Whole-record saves (custom CSS, role defaults, Overview cards) name the
 *   copy they were made from, so the server can refuse a stale one; palette
 *   saves (Settings → Appearance, Your profile, a user's page) send only the
 *   colors and keys that changed.
 *
 * Every check on a save fails on the pre-fix app.js. Settings, appearance
 * and the whole-record saves are answered in the browser (a fresh read, an
 * echo or a 409), so they never reach the server; everything else the suite
 * writes is its own fixture, removed by onExit() however the run ends.
 *
 * Run: cd tests && MINN_TEST_PASS=… node security-v046-prerc-client.test.js
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { launch, login, createPost, openEditor, freshParagraph, reporter, onExit, BASE, WP } = require( './helpers' );

const wp = ( args, opts = {} ) => {
	try {
		return execFileSync( 'wp', [ ...args, '--path=' + WP, ...( opts.plugins ? [] : [ '--skip-plugins', '--skip-themes' ] ) ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	} catch ( e ) {
		return ( e && e.stdout ) || '';
	}
};
// PHP with plugins loaded (Gravity Forms), through a file: no shell quoting.
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-prerc-client-${ process.pid }-${ Date.now() }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return String( wp( [ 'eval-file', file ], { plugins: true } ) ).trim();
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* gone */ }
	}
};
const jsonOut = ( out ) => {
	const line = String( out || '' ).split( '\n' ).reverse().find( ( l ) => /^[{["]/.test( l.trim() ) );
	try { return line ? JSON.parse( line ) : null; } catch ( e ) { return null; }
};

( async () => {
	const t = reporter( 'security-v046-prerc-client' );

	// Restores, armed before anything changes.
	const taglineWas = jsonOut( wp( [ 'option', 'get', 'blogdescription', '--format=json' ] ) );
	const made = { posts: [], terms: [], gfForm: 0 };
	const restore = onExit( () => {
		if ( 'string' === typeof taglineWas ) wp( [ 'option', 'update', 'blogdescription', JSON.stringify( taglineWas ), '--format=json' ] );
		const posts = made.posts.filter( ( id ) => Number.isInteger( id ) && id > 0 );
		if ( posts.length ) wp( [ 'post', 'delete', ...posts.map( String ), '--force' ] );
		made.terms.filter( ( id ) => Number.isInteger( id ) && id > 0 ).forEach( ( id ) => wp( [ 'term', 'delete', 'category', String( id ) ] ) );
		if ( Number.isInteger( made.gfForm ) && made.gfForm > 0 ) wpEval( `GFAPI::delete_form( ${ made.gfForm } );` );
	} );

	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const make = async ( args ) => {
		const id = await createPost( page, args );
		made.posts.push( id );
		return id;
	};
	const rawOf = async ( id, type = 'posts' ) => ( ( await rest( `wp/v2/${ type }/${ id }?context=edit&_fields=content` ) ).body || {} ).content.raw;

	// A settings save answered here with a fresh read of the same route: the
	// request body is recorded, nothing is written.
	const sent = [];
	let inflight = 0;
	// A route the page already gave up on (it navigated) cannot be answered.
	const fulfill = ( route, res ) => route.fulfill( res ).catch( () => {} );
	const answerWithRead = async ( re ) => {
		await page.route( re, async ( route ) => {
			const req = route.request();
			if ( req.method() !== 'POST' ) return route.continue().catch( () => {} );
			sent.push( { url: req.url(), body: req.postDataJSON() } );
			inflight++;
			try {
				const res = await page.request.get( req.url(), { headers: { 'X-WP-Nonce': req.headers()[ 'x-wp-nonce' ] || '' } } );
				await fulfill( route, { status: res.status(), contentType: 'application/json', body: await res.text() } );
			} catch ( e ) {
				await fulfill( route, { status: 500, contentType: 'application/json', body: '{"code":"minn_suite_probe","message":"read failed"}' } );
			} finally {
				inflight--;
			}
		} );
	};
	// A save answered here without the server: echo (the body comes back as
	// the saved record) or refuse (409, the stale-copy answer).
	const answerHere = async ( re, mode ) => {
		await page.route( re, async ( route ) => {
			const req = route.request();
			if ( req.method() !== 'POST' ) return route.continue().catch( () => {} );
			const body = req.postDataJSON();
			sent.push( { url: req.url(), body } );
			if ( 'echo' === mode ) return fulfill( route, { status: 200, contentType: 'application/json', body: JSON.stringify( body ) } );
			return fulfill( route, { status: 409, contentType: 'application/json', body: JSON.stringify( { code: 'minn_suite_probe', message: 'Answered by the suite', data: { status: 409 } } ) } );
		} );
	};
	const stopAnswering = ( re ) => page.unroute( re );
	// Exactly one color, as { mode: { slot: hex } }.
	const oneSlot = ( custom ) => {
		const modes = Object.keys( custom || {} );
		return modes.length === 1 && Object.keys( custom[ modes[ 0 ] ] || {} ).length === 1;
	};
	const sentSince = ( n, re ) => sent.slice( n ).filter( ( s ) => re.test( s.url ) );
	// Type at the end of a field and take it back off: touched, not changed.
	const touch = async ( sel ) => {
		await page.click( sel );
		await page.keyboard.press( 'Meta+ArrowRight' );
		await page.keyboard.type( 'zz' );
		await page.keyboard.press( 'Backspace' );
		await page.keyboard.press( 'Backspace' );
	};
	const appendTo = async ( sel, text ) => {
		await page.click( sel );
		await page.keyboard.press( 'Meta+ArrowRight' );
		await page.keyboard.type( text );
	};
	// Click Save, then wait until any save it sent has been answered.
	const saveSettingsAndSettle = async ( btnSel ) => {
		await page.click( btnSel );
		await page.waitForTimeout( 1800 );
		for ( let i = 0; i < 60 && inflight > 0; i++ ) await page.waitForTimeout( 500 );
		await page.waitForTimeout( 400 );
	};
	const clearToasts = () => page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
	const saveEditorNow = async ( id ) => {
		const done = page.waitForResponse( ( res ) => res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id + '(\\?|$)' ).test( res.url() ), { timeout: 20000 } ).catch( () => null );
		await page.keyboard.press( 'Meta+s' );
		const r = await done;
		await page.waitForTimeout( 400 );
		return r;
	};
	const typeInBody = async ( text ) => {
		await freshParagraph( page );
		await page.keyboard.type( text );
	};

	let raw = '';
	try {
		/* ===== Settings form engine: a surface settings tab ===== */
		const PM = /minn-admin\/v1\/perfmatters\/settings\//;
		const K_DNS = 'perfmatters_options:preload:dns_prefetch';
		const K_HINTS = 'perfmatters_options:preload:early_hints';
		await answerWithRead( PM );
		await page.goto( `${ BASE }/minn-admin/perfmatters`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ssettab="preload"]', { timeout: 30000 } );
		await page.click( '[data-ssettab="preload"]' );
		await page.waitForSelector( `[data-sset="${ K_DNS }"]`, { timeout: 20000 } );
		let n0 = sent.length;
		await touch( `[data-sset="${ K_DNS }"]` );
		await saveSettingsAndSettle( '#minn-sset-save' );
		t.check( 'settings tab: a field typed into and put back sends nothing', sentSince( n0, PM ).length === 0, JSON.stringify( sentSince( n0, PM ).map( ( s ) => s.body ) ) );
		await page.waitForSelector( `[data-sset="${ K_DNS }"]`, { timeout: 20000 } );
		n0 = sent.length;
		await touch( `[data-sset="${ K_DNS }"]` );
		await page.click( `[data-sset="${ K_HINTS }"]` );
		await saveSettingsAndSettle( '#minn-sset-save' );
		let got = sentSince( n0, PM ).map( ( s ) => Object.keys( ( s.body || {} ).values || {} ) );
		t.check( 'settings tab: a save sends the changed switch, not the field put back', got.length === 1 && JSON.stringify( got[ 0 ] ) === JSON.stringify( [ K_HINTS ] ), JSON.stringify( got ) );
		await stopAnswering( PM );

		/* ===== The Store settings page and its form dialog ===== */
		const WCS = /minn-admin\/v1\/wc\/settings\/general\//;
		await answerWithRead( WCS );
		await page.goto( `${ BASE }/minn-admin/store-settings/general`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-store-form [data-sset="woocommerce_store_address"]', { timeout: 30000 } );
		n0 = sent.length;
		await touch( '#minn-store-form [data-sset="woocommerce_store_address"]' );
		await appendTo( '#minn-store-form [data-sset="woocommerce_store_address_2"]', ' Suite' );
		await saveSettingsAndSettle( '#minn-store-form #minn-sset-save' );
		got = sentSince( n0, WCS ).map( ( s ) => Object.keys( ( s.body || {} ).values || {} ) );
		t.check( 'store settings: only the changed field is sent', got.length === 1 && JSON.stringify( got[ 0 ] ) === '["woocommerce_store_address_2"]', JSON.stringify( got ) );
		await stopAnswering( WCS );

		const WCE = /minn-admin\/v1\/wc\/emails\/new_order/;
		await answerWithRead( WCE );
		await page.goto( `${ BASE }/minn-admin/store-settings/email`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-emailedit="new_order"]', { timeout: 30000 } );
		await page.click( '[data-emailedit="new_order"]' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="subject"]', { timeout: 20000 } );
		n0 = sent.length;
		await touch( '#minn-store-form-modal [data-sset="subject"]' );
		await appendTo( '#minn-store-form-modal [data-sset="heading"]', ' suite' );
		await saveSettingsAndSettle( '#minn-store-form-modal #minn-sset-save' );
		got = sentSince( n0, WCE ).map( ( s ) => Object.keys( ( s.body || {} ).values || {} ) );
		t.check( 'store email dialog: only the changed field is sent', got.length === 1 && JSON.stringify( got[ 0 ] ) === '["heading"]', JSON.stringify( got ) );
		await stopAnswering( WCE );

		/* ===== Minn's own settings ===== */
		const WPS = /wp\/v2\/settings(\?|$)/;
		await answerWithRead( WPS );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-section="Content"]', { timeout: 30000 } );
		await page.click( '[data-section="Content"]' );
		await page.waitForSelector( '[data-setting="use_smilies"]', { timeout: 15000 } );
		n0 = sent.length;
		await page.click( '[data-setting="use_smilies"]' );
		await page.click( '[data-setting="use_smilies"]' );
		await saveSettingsAndSettle( '#minn-save-settings' );
		t.check( 'settings: a switch flipped and flipped back is not sent', sentSince( n0, WPS ).length === 0, JSON.stringify( sentSince( n0, WPS ).map( ( s ) => s.body ) ) );

		wp( [ 'option', 'update', 'blogdescription', 'Line one\nLine two' ] );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-section="Site"]', { timeout: 30000 } );
		await page.click( '[data-section="Site"]' );
		await page.waitForSelector( '[data-key="title"]', { timeout: 15000 } );
		n0 = sent.length;
		await appendTo( '[data-key="title"]', ' suite' );
		await saveSettingsAndSettle( '#minn-save-settings' );
		got = sentSince( n0, WPS ).map( ( s ) => s.body || {} );
		t.check( 'settings: a tagline its box cannot show whole is not sent back without its line break', got.length === 1 && 'title' in got[ 0 ] && ! ( 'description' in got[ 0 ] ), JSON.stringify( got ) );
		await stopAnswering( WPS );
		if ( 'string' === typeof taglineWas ) wp( [ 'option', 'update', 'blogdescription', JSON.stringify( taglineWas ), '--format=json' ] );

		/* ===== Entry answers (Gravity Forms) ===== */
		const gf = jsonOut( wpEval( `
			if ( ! class_exists( 'GFAPI' ) ) { echo wp_json_encode( array( 'skip' => true ) ); return; }
			$fid = GFAPI::add_form( array( 'title' => 'Prerc client suite ' . time(), 'fields' => array(
				array( 'id' => 1, 'type' => 'text', 'label' => 'Note' ),
				array( 'id' => 2, 'type' => 'text', 'label' => 'Lines' ),
				array( 'id' => 3, 'type' => 'radio', 'label' => 'Colour', 'enableOtherChoice' => true, 'choices' => array( array( 'text' => 'Red', 'value' => 'red' ), array( 'text' => 'Blue', 'value' => 'blue' ) ) ),
			) ) );
			if ( is_wp_error( $fid ) || ! $fid ) { echo wp_json_encode( array( 'fid' => 0 ) ); return; }
			$eid = GFAPI::add_entry( array( 'form_id' => $fid, '1' => 'hello', '2' => "Line one\\nLine two", '3' => 'Red' ) );
			echo wp_json_encode( array( 'fid' => (int) $fid, 'eid' => is_wp_error( $eid ) ? 0 : (int) $eid ) );
		` ) ) || {};
		if ( gf.skip ) {
			console.log( 'SKIP  entry answers: Gravity Forms is not active' );
		} else {
			made.gfForm = Number( gf.fid ) || 0;
			const eid = Number( gf.eid ) || 0;
			t.check( 'entry fixture made', made.gfForm > 0 && eid > 0, JSON.stringify( gf ) );
			const stored = () => jsonOut( wpEval( `echo wp_json_encode( GFAPI::get_entry( ${ eid } ) );` ) ) || {};
			await page.goto( `${ BASE }/minn-admin/gravity-forms/entry/${ eid }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-ep-edit', { timeout: 60000 } );
			await page.click( '#minn-ep-edit' );
			await page.waitForSelector( '[data-epchoice="3"] .minn-ac-input', { timeout: 15000 } );
			t.check( 'the "other" box shows the stored answer', await page.$eval( '[data-epchoice="3"] .minn-ac-input', ( el ) => el.value ) === 'Red' );
			// Focus and leave the "other" box; type into the lines answer and
			// take it back; change the note.
			await page.click( '[data-epchoice="3"] .minn-ac-input' );
			await page.keyboard.press( 'Tab' );
			await page.waitForTimeout( 200 );
			await touch( '[data-epf="2"]' );
			await appendTo( '[data-epf="1"]', ' there' );
			const saved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && /gf\/entries\/\d+\/answers/.test( r.url() ), { timeout: 30000 } ).catch( () => null );
			await page.click( '#minn-ep-save' );
			const sr = await saved;
			const body = sr ? JSON.parse( sr.request().postData() || '{}' ) : {};
			await page.waitForTimeout( 600 );
			const e = stored();
			t.check( 'entry: the changed answer is saved', e[ '1' ] === 'hello there', JSON.stringify( e[ '1' ] ) );
			t.check( 'entry: an "other" answer that only had focus keeps its stored value', e[ '3' ] === 'Red' && ! ( '3' in ( body.values || {} ) ), JSON.stringify( { stored: e[ '3' ], sent: body.values } ) );
			t.check( 'entry: a text answer typed into and put back keeps its line break', e[ '2' ] === 'Line one\nLine two' && ! ( '2' in ( body.values || {} ) ), JSON.stringify( { stored: e[ '2' ], sent: body.values } ) );
		}

		/* ===== Block inspector: ACF block fields ===== */
		const hasAcf = ( await rest( 'wp/v2/block-types/acf/minn-inline' ) ).status === 200;
		if ( ! hasAcf ) {
			console.log( 'SKIP  block inspector: acf/minn-inline is not registered (ACF Pro inactive?)' );
		} else {
			const keys = { kicker: 'field_minn_acfi_kicker', headline: 'field_minn_acfi_headline', note: 'field_minn_acfi_note', stats: 'field_minn_acfi_stats', link: 'field_minn_acfi_link', style: 'field_minn_acfi_style', markers: 'field_minn_acfi_markers', button: 'field_minn_acfi_button' };
			const d = { kicker: 'Work', headline: 'Café notes', note: 42, stats: 'Line one\nLine two', button: 'Book a call', link: 'https://example.com/one/', style: null, markers: '1' };
			const data = {};
			Object.keys( d ).forEach( ( k ) => { data[ k ] = d[ k ]; data[ '_' + k ] = keys[ k ]; } );
			const BLOCK = '<!-- wp:acf/minn-inline ' + JSON.stringify( { name: 'acf/minn-inline', data, mode: 'preview' } ) + ' /-->';
			const aid = await make( { title: 'Prerc ACF Apply probe', content: BLOCK + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
			const blockOf = ( raw ) => ( String( raw ).match( /<!-- wp:acf\/minn-inline [\s\S]*? \/-->/ ) || [] )[ 0 ] || '';
			const dataOf = ( raw ) => { try { return JSON.parse( blockOf( raw ).replace( /^<!-- wp:acf\/minn-inline /, '' ).replace( / \/-->$/, '' ) ).data; } catch ( e ) { return null; } };
			// ACF re-serializes its blocks on save; what it stored is the baseline.
			const stored0 = blockOf( await rawOf( aid ) );
			const d0 = dataOf( stored0 ) || {};
			t.check( 'ACF fixture stored with a number, a "1", a line break and an unset select', d0.note === 42 && d0.markers === '1' && d0.stats === 'Line one\nLine two' && d0.style === null, stored0.slice( 0, 300 ) );
			const island = page.locator( '.minn-block-island[data-block="acf/minn-inline"]' ).first();
			const clickAt = async ( loc ) => {
				await loc.scrollIntoViewIfNeeded();
				const box = await loc.boundingBox();
				await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
			};
			const openChip = async () => {
				for ( let i = 0; i < 6; i++ ) {
					try {
						await clickAt( island.locator( '.minn-island-chip' ).first() );
						await page.waitForSelector( '.minn-inspector [data-inspdf="own:kicker"]', { timeout: 6000 } );
						return;
					} catch ( e ) { await page.waitForTimeout( 1200 ); }
				}
				throw new Error( 'the ACF block inspector never opened' );
			};
			await openEditor( page, aid );
			await page.waitForSelector( '.minn-block-island[data-block="acf/minn-inline"]', { timeout: 45000 } );
			await page.waitForTimeout( 1500 );

			await openChip();
			await page.click( '#minn-insp-apply' );
			await page.waitForTimeout( 800 );
			await typeInBody( 'a' );
			await saveEditorNow( aid );
			raw = await rawOf( aid );
			t.check( 'inspector: an untouched Apply leaves the block byte for byte', blockOf( raw ) === stored0, blockOf( raw ).slice( 0, 400 ) );

			await openChip();
			await page.fill( '.minn-inspector [data-inspdf="own:kicker"]', 'Projects' );
			await page.click( '#minn-insp-apply' );
			await page.waitForTimeout( 800 );
			await typeInBody( 'd' );
			await saveEditorNow( aid );
			raw = await rawOf( aid );
			let dd = dataOf( raw ) || {};
			t.check( 'inspector: the changed field is applied', dd.kicker === 'Projects', JSON.stringify( dd.kicker ) );
			t.check( 'inspector: fields left alone keep their stored values (a number, a "1", a line break, an unset select)',
				dd.note === 42 && dd.markers === '1' && dd.stats === 'Line one\nLine two' && dd.style === null && dd.headline === 'Café notes',
				JSON.stringify( { note: dd.note, markers: dd.markers, stats: dd.stats, style: dd.style, headline: dd.headline } ) );

			// The toolbar popover behind the block's button: link and style.
			const arrow = island.locator( '.acfi-arrow' );
			let tbOpen = false;
			for ( let i = 0; i < 4 && ! tbOpen; i++ ) {
				try {
					await arrow.scrollIntoViewIfNeeded();
					const ab = await arrow.boundingBox();
					await page.mouse.move( ab.x + ab.width / 2, ab.y + ab.height / 2 );
					await page.waitForTimeout( 400 );
					await page.mouse.click( ab.x + ab.width / 2, ab.y + ab.height / 2 );
					await page.waitForSelector( '.minn-tb-pop input[data-inspdf="tb:link"]', { timeout: 5000 } );
					tbOpen = true;
				} catch ( e ) { await page.waitForTimeout( 1500 ); }
			}
			t.check( 'toolbar popover opens on the block\'s button', tbOpen );
			if ( tbOpen ) {
				await clickAt( page.locator( '.minn-tb-pop input[data-inspdf="tb:link"]' ) );
				await page.keyboard.press( 'Meta+a' );
				await page.keyboard.type( 'https://example.com/two/' );
				await clickAt( page.locator( '.minn-tb-pop [data-tb-apply]' ) );
				await page.waitForTimeout( 800 );
				await typeInBody( 'e' );
				await saveEditorNow( aid );
				raw = await rawOf( aid );
				dd = dataOf( raw ) || {};
				t.check( 'toolbar popover: the link changes and the unset style stays unset', dd.link === 'https://example.com/two/' && dd.style === null && dd.note === 42, JSON.stringify( { link: dd.link, style: dd.style, note: dd.note } ) );
			}
		}

		/* ===== Link popover ===== */
		const LINKED = '<!-- wp:paragraph -->\n<p>See <a href="https://example.com/x" rel="nofollow">the page</a> now.</p>\n<!-- /wp:paragraph -->';
		const lid = await make( { title: 'Prerc link probe', content: LINKED } );
		await openEditor( page, lid );
		const openLink = async () => {
			await page.click( '#minn-editor-body a[href="https://example.com/x"]' );
			await page.waitForSelector( '.minn-link-pop [data-link-apply]', { timeout: 10000 } );
		};
		await openLink();
		await page.click( '.minn-link-pop [data-link-apply]' );
		await typeInBody( 'b' );
		await saveEditorNow( lid );
		raw = await rawOf( lid );
		t.check( 'link popover: an untouched Apply keeps rel="nofollow"', raw.includes( '<a href="https://example.com/x" rel="nofollow">' ), raw.slice( 0, 200 ) );
		await openLink();
		await page.click( '.minn-link-pop [data-link-newtab]' );
		await page.click( '.minn-link-pop [data-link-apply]' );
		await typeInBody( 'f' );
		await saveEditorNow( lid );
		raw = await rawOf( lid );
		const rel = ( raw.match( /<a [^>]*rel="([^"]*)"/ ) || [] )[ 1 ] || '';
		t.check( 'link popover: turning on a new tab keeps nofollow beside noopener', /target="_blank"/.test( raw ) && rel.split( ' ' ).includes( 'nofollow' ) && rel.split( ' ' ).includes( 'noopener' ), raw.slice( 0, 220 ) );

		/* ===== Image popover ===== */
		const IMG = '<!-- wp:image {"linkDestination":"custom"} -->\n<figure class="wp-block-image"><a href="https://example.com/photo" rel="nofollow"><img src="/wp-includes/images/w-logo-blue.png" alt="Logo"/></a><figcaption class="wp-element-caption">Photo by <em>Jane</em></figcaption></figure>\n<!-- /wp:image -->';
		const iid = await make( { title: 'Prerc image probe', content: IMG + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, iid );
		await page.waitForSelector( '#minn-editor-body figure img', { timeout: 15000 } );
		await page.click( '#minn-editor-body figure img' );
		await page.waitForSelector( '[data-img-alt]', { timeout: 10000 } );
		await page.fill( '[data-img-alt]', 'Blue logo' );
		await page.click( '[data-img-apply]' );
		await typeInBody( 'g' );
		await saveEditorNow( iid );
		raw = await rawOf( iid );
		t.check( 'image popover: the alt text changes', raw.includes( 'alt="Blue logo"' ), raw.slice( 0, 300 ) );
		t.check( 'image popover: an alt edit keeps the caption\'s markup and the link\'s rel', raw.includes( 'Photo by <em>Jane</em>' ) && raw.includes( 'rel="nofollow"' ) && raw.includes( '"linkDestination":"custom"' ), raw.slice( 0, 300 ) );

		/* ===== Terms ===== */
		const DESC = '<p class="lead">Kept <em>markup</em></p>';
		const term = await rest( 'wp/v2/categories', { method: 'POST', body: { name: 'AAA prerc term ' + Date.now(), description: DESC } } );
		const tid = term.body && Number.isInteger( term.body.id ) ? term.body.id : 0;
		if ( tid > 0 ) made.terms.push( tid );
		t.check( 'term fixture made', tid > 0, JSON.stringify( term.body && term.body.code ) );
		await page.goto( `${ BASE }/minn-admin/terms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-term="${ tid }"]`, { timeout: 30000 } );
		await page.click( `[data-term="${ tid }"] .minn-row-title` );
		await page.waitForSelector( '.minn-term-edit [data-tf="name"]', { timeout: 10000 } );
		await appendTo( '.minn-term-edit [data-tf="name"]', ' renamed' );
		const termPost = page.waitForRequest( ( r ) => r.method() === 'POST' && new RegExp( 'wp/v2/categories/' + tid ).test( r.url() ), { timeout: 15000 } ).catch( () => null );
		await page.click( '.minn-term-edit [data-tsave]' );
		const tr = await termPost;
		const tBody = tr ? JSON.parse( tr.postData() || '{}' ) : null;
		t.check( 'terms: a rename sends the name only, never the description', !! tBody && /renamed$/.test( tBody.name || '' ) && ! ( 'description' in tBody ) && ! ( 'slug' in tBody ), JSON.stringify( tBody ) );
		await page.waitForTimeout( 1200 );

		/* ===== Navigation items ===== */
		const NAV = '<!-- wp:navigation-link {"label":"Calendar","url":"webcal://example.com/events.ics","kind":"custom"} /-->\n\n<!-- wp:navigation-link {"label":"Alpha","url":"https://example.com/a","kind":"custom"} /-->';
		const nav = await rest( 'wp/v2/navigation', { method: 'POST', body: { title: 'Prerc nav probe ' + Date.now(), status: 'publish', content: NAV } } );
		const nid = nav.body && Number.isInteger( nav.body.id ) ? nav.body.id : 0;
		if ( nid > 0 ) made.posts.push( nid );
		t.check( 'navigation fixture made', nid > 0 );
		await page.goto( `${ BASE }/minn-admin/navigation/${ nid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-navtoken="1"]', { timeout: 30000 } );
		const navPosts = [];
		const onNav = ( r ) => { if ( r.method() === 'POST' && new RegExp( 'wp/v2/navigation/' + nid ).test( r.url() ) ) navPosts.push( r ); };
		page.on( 'request', onNav );
		await page.click( '[data-navtoken="1"] .minn-menu-info' );
		await page.waitForSelector( '#minn-navi-label', { timeout: 10000 } );
		await page.click( '[data-navsave]' );
		await page.waitForTimeout( 1500 );
		t.check( 'navigation: a Save with nothing changed writes nothing', navPosts.length === 0, String( navPosts.length ) );
		await page.waitForSelector( '[data-navtoken="0"] .minn-menu-info', { timeout: 15000 } );
		await page.click( '[data-navtoken="0"] .minn-menu-info' );
		await page.waitForSelector( '#minn-navi-label', { timeout: 10000 } );
		await page.fill( '#minn-navi-label', 'Events' );
		const navSaved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( 'wp/v2/navigation/' + nid ).test( r.url() ), { timeout: 15000 } ).catch( () => null );
		await page.click( '[data-navsave]' );
		await navSaved;
		await page.waitForTimeout( 500 );
		page.off( 'request', onNav );
		const navRaw = await rawOf( nid, 'navigation' );
		t.check( 'navigation: a label edit saves and keeps the stored address', navRaw.includes( '"label":"Events"' ) && navRaw.includes( 'webcal://example.com/events.ics' ), navRaw.slice( 0, 300 ) );

		/* ===== Whole-record saves carry the copy they were made from ===== */
		// Custom CSS: Settings → Design sends the stamp its GET answered.
		const CSSR = /minn-admin\/v1\/custom-css/;
		const cssNow = ( await rest( 'minn-admin/v1/custom-css' ) ).body || {};
		await answerHere( CSSR, 'refuse' );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-section="Design"]', { timeout: 30000 } );
		await page.click( '[data-section="Design"]' );
		await page.waitForSelector( '#minn-custom-css', { timeout: 15000 } );
		n0 = sent.length;
		await page.$eval( '#minn-custom-css', ( el ) => {
			el.value += '\n/* suite probe */';
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await saveSettingsAndSettle( '#minn-save-settings' );
		got = sentSince( n0, CSSR ).map( ( s ) => s.body || {} );
		t.check( 'custom CSS: a save names the copy it edited (its stamp)', got.length === 1 && !! cssNow.stamp && got[ 0 ].base === cssNow.stamp, JSON.stringify( { stamp: cssNow.stamp, base: got[ 0 ] && got[ 0 ].base } ) );
		await stopAnswering( CSSR );

		// Role defaults: the same, from the role defaults GET.
		const RDR = /minn-admin\/v1\/role-defaults/;
		const rdNow = ( await rest( 'minn-admin/v1/role-defaults' ) ).body || {};
		await answerHere( RDR, 'refuse' );
		await page.goto( `${ BASE }/minn-admin/users`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-utab="roles"]', { timeout: 30000 } );
		await page.click( '[data-utab="roles"]' );
		await page.waitForSelector( '[data-rdpol="author:signin"] .minn-ac-input', { timeout: 30000 } );
		await page.click( '[data-rdpol="author:signin"] .minn-ac-input' );
		await page.waitForSelector( '[data-rdpol="author:signin"] .minn-ac-panel:not([hidden]) .minn-ac-item', { timeout: 10000 } );
		await page.evaluate( () => {
			const cur = document.querySelector( '[data-rdpol="author:signin"] .minn-ac-input' ).dataset.acValue;
			const item = [ ...document.querySelectorAll( '[data-rdpol="author:signin"] .minn-ac-panel .minn-ac-item' ) ].find( ( x ) => x.dataset.acv !== cur );
			if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		} );
		await page.waitForFunction( () => ! document.querySelector( '#minn-rd-save' ).disabled, null, { timeout: 10000 } ).catch( () => {} );
		n0 = sent.length;
		await saveSettingsAndSettle( '#minn-rd-save' );
		got = sentSince( n0, RDR ).map( ( s ) => s.body || {} );
		t.check( 'role defaults: a save names the copy it edited (its stamp)', got.length === 1 && !! rdNow.stamp && got[ 0 ].base === rdNow.stamp, JSON.stringify( { stamp: rdNow.stamp, base: got[ 0 ] && got[ 0 ].base } ) );
		await stopAnswering( RDR );

		// Overview cards: a card pick sends the layout it was picked over.
		const OVR = /minn-admin\/v1\/overview\/metrics/;
		await answerHere( OVR, 'refuse' );
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stat[data-mkey]', { timeout: 30000 } );
		await page.click( '.minn-stat[data-mkey]', { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( el ) => /^Customize/.test( el.textContent.trim() ) );
			if ( b ) b.click();
		} );
		await page.waitForSelector( '.minn-metric-picker .minn-metric-tile:not(.is-on)', { timeout: 10000 } );
		await page.click( '.minn-metric-picker .minn-metric-tile:not(.is-on)' );
		n0 = sent.length;
		await saveSettingsAndSettle( '#minn-metric-save' );
		got = sentSince( n0, OVR ).map( ( s ) => s.body || {} );
		t.check( 'overview: a card pick names the layout it was picked over', got.length === 1 && Array.isArray( got[ 0 ].base ) && got[ 0 ].base.length > 0, JSON.stringify( got ) );
		await stopAnswering( OVR );

		/* ===== Palettes: only the color changed ===== */
		// Settings → Appearance: a starting-mode change sends the mode alone.
		const SAR = /minn-admin\/v1\/site-appearance/;
		await answerHere( SAR, 'refuse' );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item[data-section="Appearance"]', { timeout: 30000 } );
		await page.click( '.minn-settings-nav-item[data-section="Appearance"]' );
		await page.waitForSelector( '#minn-site-appearance [data-site-mode]', { timeout: 15000 } );
		await page.click( '#minn-site-appearance [data-site-mode]:not(.on)' );
		n0 = sent.length;
		await saveSettingsAndSettle( '#minn-save-site-appearance' );
		got = sentSince( n0, SAR ).map( ( s ) => Object.keys( s.body || {} ).sort() );
		t.check( 'site appearance: a mode change sends the mode alone', got.length === 1 && JSON.stringify( got[ 0 ] ) === '["mode","resetUsers"]', JSON.stringify( got ) );
		await stopAnswering( SAR );

		// Your profile: a slot edit sends that slot, never the whole palette.
		const MER = /minn-admin\/v1\/me\/appearance/;
		await answerHere( MER, 'echo' );
		await page.goto( `${ BASE }/minn-admin/profile`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-scheme-swatch[data-scheme="custom"]', { timeout: 30000 } );
		if ( ! await page.$( '[data-scheme-slot="accent"]' ) ) {
			await page.click( '.minn-scheme-swatch[data-scheme="custom"]' );
			await page.waitForSelector( '[data-scheme-slot="accent"]', { timeout: 10000 } );
		}
		await page.waitForTimeout( 600 );
		n0 = sent.length;
		await page.$eval( '[data-scheme-slot="accent"]', ( el ) => {
			el.value = '#123456';
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await page.waitForTimeout( 1200 );
		got = sentSince( n0, MER ).map( ( s ) => s.body || {} );
		t.check( 'profile palette: a slot edit sends that one color', got.length >= 1 && got.every( ( b ) => b.scheme === 'custom' && oneSlot( b.custom ) && Object.values( b.custom )[ 0 ].accent === '#123456' ), JSON.stringify( got ).slice( 0, 400 ) );
		await stopAnswering( MER );

		// A user's page: the same, for an administrator editing their palette.
		const EDITOR_ID = Number( String( wp( [ 'user', 'get', 'minn-editor', '--field=ID' ] ) ).trim().split( '\n' ).pop() ) || 0;
		if ( EDITOR_ID > 0 ) {
			const UER = new RegExp( 'minn-admin/v1/users/' + EDITOR_ID + '/appearance' );
			await answerHere( UER, 'echo' );
			await page.goto( `${ BASE }/minn-admin/users/${ EDITOR_ID }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-ue-appearance .minn-scheme-swatch[data-scheme="custom"]', { timeout: 30000 } );
			if ( ! await page.$( '#minn-ue-appearance [data-scheme-slot="accent"]' ) ) {
				await page.click( '#minn-ue-appearance .minn-scheme-swatch[data-scheme="custom"]' );
				await page.waitForSelector( '#minn-ue-appearance [data-scheme-slot="accent"]', { timeout: 10000 } );
			}
			await page.waitForTimeout( 600 );
			n0 = sent.length;
			await page.$eval( '#minn-ue-appearance [data-scheme-slot="accent"]', ( el ) => {
				el.value = '#654321';
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
			await page.waitForTimeout( 1200 );
			got = sentSince( n0, UER ).map( ( s ) => s.body || {} );
			t.check( 'user page palette: a slot edit sends that one color', got.length === 1 && got[ 0 ].scheme === 'custom' && oneSlot( got[ 0 ].custom ) && Object.values( got[ 0 ].custom )[ 0 ].accent === '#654321', JSON.stringify( got ).slice( 0, 400 ) );
			await stopAnswering( UER );
		} else {
			console.log( 'SKIP  user page palette: no minn-editor account' );
		}

		/* ===== Editor excerpt ===== */
		const eid2 = await make( { title: 'Prerc excerpt probe', content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->', excerpt: '<em>Kept</em> excerpt' } );
		await openEditor( page, eid2 );
		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '#minn-editor-excerpt', { timeout: 10000 } );
		await touch( '#minn-editor-excerpt' );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		await typeInBody( 'c' );
		const er = await saveEditorNow( eid2 );
		const eBody = er ? JSON.parse( er.request().postData() || '{}' ) : null;
		t.check( 'editor: an excerpt typed into and put back is not sent', !! eBody && 'content' in eBody && ! ( 'excerpt' in eBody ), JSON.stringify( eBody && Object.keys( eBody ) ) );
	} finally {
		await clearToasts().catch( () => {} );
		restore();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.log( 'FAIL  suite crashed: ' + ( e && e.stack ? e.stack : e ) );
	process.exit( 1 );
} );
