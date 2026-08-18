/**
 * WPForms Pro entries surface (2026-08-06). Active fixture: WPForms Pro
 * 2.0.0.2 (dev zip, provenance-verified) + standing form 5363 "Minn Contact
 * Form" with Dana/Miguel entries. The suite seeds a disposable entry through
 * WPForms' own handler, drives status ops on it, and restores the standing
 * entries' unread state in finally. Caps note: editor-gets-403 was verified
 * at build time via wp eval (helpers login is admin-only).
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );
const os = require( 'os' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'wpforms' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	const wpPath = path.resolve( __dirname, '../../../../' );
	// eval-file, never inline eval: a double-quoted shell arg lets the shell
	// expand $wpdb/$f to empty strings (documented wp-eval trap).
	const wp = ( code ) => {
		const f = path.join( os.tmpdir(), 'minn-wpforms-suite-' + process.pid + '.php' );
		fs.writeFileSync( f, '<?php\n' + code + '\n' );
		try {
			return execSync(
				`wp --path=${ JSON.stringify( wpPath ) } eval-file ${ JSON.stringify( f ) } --user=admin 2>/dev/null`,
				{ timeout: 60000 } ).toString().split( /\r?\n/ )
				.filter( ( line ) => ! /^Deprecated:/.test( line.trim() ) ).join( '\n' ).trim();
		} finally {
			try { fs.unlinkSync( f ); } catch ( e ) { /* tmp cleanup */ }
		}
	};

	await login( page );

	const api = ( path2, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path: path2, opts } );

	let doomed = 0;
	try {
		// Seed a disposable entry through THEIR handler (single-line code:
		// JSON.stringify newlines reach wp eval as literal backslash-n).
		doomed = parseInt( wp( "$f = array( 0 => array( 'id' => 0, 'type' => 'name', 'name' => 'Name', 'value' => 'Suite Doomed' ), 1 => array( 'id' => 1, 'type' => 'email', 'name' => 'Email', 'value' => 'doomed@example.com' ) ); $forms = get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) ); echo (int) wpforms()->obj( 'entry' )->add( array( 'form_id' => (int) $forms[0], 'status' => '', 'fields' => wp_json_encode( $f ) ) );" ), 10 );
		t.check( 'Seeded a disposable entry via their handler', doomed > 0, String( doomed ) );

		// --- REST shim -------------------------------------------------------
		const list = await api( 'minn-admin/v1/wpforms/entries?per_page=10' );
		const first = ( list.body && list.body.items && list.body.items[ 0 ] ) || null;
		t.check( 'List returns {items,total} with contact-card rows',
			list.status === 200 && !! first && !! first.summary && !! first.status && !! first.date,
			JSON.stringify( first ) );

		const forms = await api( 'minn-admin/v1/wpforms/forms' );
		t.check( 'Forms tab route lists the fixture form',
			forms.status === 200 && ( forms.body || [] ).some( ( f ) => /Minn Contact Form/.test( f.title ) ),
			JSON.stringify( forms.body ) );

		const hit = await api( 'minn-admin/v1/wpforms/entries?search=' + encodeURIComponent( 'doomed@example.com' ) );
		const miss = await api( 'minn-admin/v1/wpforms/entries?search=zzznomatch-wpf' );
		t.check( 'Search over fields JSON: hit + empty miss',
			hit.body && hit.body.total >= 1 && miss.body && miss.body.total === 0,
			JSON.stringify( { hit: hit.body && hit.body.total, miss: miss.body && miss.body.total } ) );

		// Detail marks viewed through their handler.
		const det = await api( 'minn-admin/v1/wpforms/entries/' + doomed );
		const titles = ( ( det.body && det.body.sections ) || [] ).map( ( s ) => s.title );
		t.check( 'Detail sections: Answers + Submission',
			det.status === 200 && titles.includes( 'Answers' ) && titles.includes( 'Submission' ),
			JSON.stringify( titles ) );
		const viewed = wp( `global $wpdb; echo (int) $wpdb->get_var( "SELECT viewed FROM {$wpdb->prefix}wpforms_entries WHERE entry_id = ${ doomed }" );` );
		t.check( 'Opening the detail marked it viewed (their update)', viewed === '1', viewed );

		// Status ops round-trip on the disposable row.
		await api( 'minn-admin/v1/wpforms/entries/' + doomed + '/star', { method: 'POST', body: JSON.stringify( { on: 1 } ) } );
		const starredList = await api( 'minn-admin/v1/wpforms/entries?status=starred' );
		t.check( 'Star + starred filter', ( starredList.body.items || [] ).some( ( i ) => i.id === doomed && i.starred === '★' ),
			JSON.stringify( starredList.body.items && starredList.body.items.map( ( i ) => [ i.id, i.starred ] ) ) );

		await api( 'minn-admin/v1/wpforms/entries/' + doomed + '/status', { method: 'POST', body: JSON.stringify( { status: 'spam' } ) } );
		const spamList = await api( 'minn-admin/v1/wpforms/entries?status=spam' );
		t.check( 'Spam bucket holds it', ( spamList.body.items || [] ).some( ( i ) => i.id === doomed ), JSON.stringify( spamList.body.total ) );
		const inbox = await api( 'minn-admin/v1/wpforms/entries' );
		t.check( 'Inbox hides spam', ! ( inbox.body.items || [] ).some( ( i ) => i.id === doomed ), '' );

		await api( 'minn-admin/v1/wpforms/entries/' + doomed + '/status', { method: 'POST', body: JSON.stringify( { status: 'restore' } ) } );
		await api( 'minn-admin/v1/wpforms/entries/' + doomed + '/status', { method: 'POST', body: JSON.stringify( { status: 'trash' } ) } );
		const del = await api( 'minn-admin/v1/wpforms/entries/' + doomed, { method: 'DELETE' } );
		const gone = await api( 'minn-admin/v1/wpforms/entries/' + doomed );
		const fieldRows = wp( `global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entry_fields WHERE entry_id = ${ doomed }" );` );
		t.check( 'Trash → delete through their handler (satellite rows cleaned)',
			del.status === 200 && gone.status === 404 && fieldRows === '0',
			JSON.stringify( { del: del.status, gone: gone.status, fieldRows } ) );
		doomed = 0;

		// Bad status refused.
		const bad = await api( 'minn-admin/v1/wpforms/entries/1/status', { method: 'POST', body: JSON.stringify( { status: 'bogus' } ) } );
		t.check( 'Unknown status refused (400)', bad.status === 400, String( bad.status ) );

		// --- Surface UI ------------------------------------------------------
		await page.evaluate( () => localStorage.setItem( 'minn-sf-forms', 'wpforms' ) );
		await page.goto( BASE + '/minn-admin/wpforms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const ui = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-table-row' ).length,
			card: /Unread entries/.test( document.body.textContent || '' ) && /Open WPForms/.test( document.body.textContent || '' ),
		} ) );
		t.check( 'Surface renders rows', ui.rows >= 1, JSON.stringify( ui ) );
		t.check( 'Status card renders', ui.card, '' );

		// Detail modal via row click. The clicked standing entry is UNREAD, so
		// the when-gated action set is Mark read + Star (spam/trash gate on
		// read). Sections load async after the actions — wait for the answer
		// content itself, not just the modal chrome.
		// The forms family renders a CONTACT CARD (renderEntryDetail) — no
		// "Answers" heading; assert the card's content instead.
		await page.click( '.minn-table-row .minn-row-title' );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && /@example\.com/.test( m.textContent || '' ) && /Entry #/.test( m.textContent || '' );
		}, null, { timeout: 20000 } ).catch( () => null );
		const modal = await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			return {
				open: !! m,
				answers: m ? /@example\.com/.test( m.textContent || '' ) && /Entry #/.test( m.textContent || '' ) : false,
				labels: Array.from( ( m || document ).querySelectorAll( '[data-saction]' ) ).map( ( b ) => ( b.textContent || '' ).trim() ),
			};
		} );
		t.check( 'Detail modal shows the contact card', modal.open && modal.answers, JSON.stringify( modal ) );
		t.check( 'When-gated actions match the unread row (Mark read + Star)',
			modal.labels.includes( 'Mark read' ) && modal.labels.includes( 'Star' )
			&& ! modal.labels.includes( 'Mark spam' ),
			JSON.stringify( modal.labels ) );
	} finally {
		// Leave no residue: drop a straggler doomed row and restore the
		// standing entries' unread state (the UI click marked one read).
		try {
			if ( doomed ) {
				wp( `wpforms()->obj( 'entry' )->delete( ${ doomed } );` );
			}
			wp( `global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}wpforms_entries SET viewed = 0, starred = 0, status = '' WHERE form_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wpforms' AND post_title = 'Minn Contact Form' )" );` );
		} catch ( e ) { /* fixture reset is best-effort */ }
	}

	await t.done( browser, errors );
} )();
