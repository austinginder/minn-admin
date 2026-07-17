/**
 * SureForms entries adapter (v0.18.0, Wave B). SureForms stores submissions in
 * {prefix}srfm_entries (form_data is clean JSON keyed by field label). It rests
 * installed-inactive; the suite activates it, creates a form + two entries
 * (unread + read) through SureForms' own Entries model, drives the list /
 * form tab / status filter / search / entry card / mark-read / status card /
 * delete, then removes its fixtures and restores inactive.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();
// PHP through a temp eval-file: an inline `wp eval "…"` lets the shell expand
// $wpdb before wp-cli sees it. Retry across FrankenPHP restart windows.
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-sf-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 4; attempt++ ) {
			try {
				return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
			} catch ( e ) {
				if ( attempt === 4 ) return ( e.stdout || '' ).trim();
				execSync( 'sleep 3' );
			}
		}
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* ignore */ }
	}
	return '';
};

( async () => {
	const t = reporter( 'sureforms' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ p, opts || null ] );

	let wasActive = true;
	let formId = 0;
	try {
		try {
			execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active sureforms`, { stdio: 'ignore', timeout: 30000 } );
		} catch ( e ) {
			wasActive = false;
		}
		if ( ! wasActive ) wp( 'plugin activate sureforms' );

		// Create a form + two entries through SureForms' own model.
		const seedOut = evalPhp(
			`$fid = wp_insert_post( array( 'post_type' => 'sureforms_form', 'post_title' => 'minn-sf-suite', 'post_status' => 'publish' ) );
			 $a = \\SRFM\\Inc\\Database\\Tables\\Entries::add( array( 'form_id' => $fid, 'user_id' => 0, 'form_data' => array( 'Name' => 'Dana Suite', 'Email' => 'dana-suite@example.com', 'Message' => 'unread one' ), 'status' => 'unread', 'created_at' => current_time('mysql') ) );
			 $b = \\SRFM\\Inc\\Database\\Tables\\Entries::add( array( 'form_id' => $fid, 'user_id' => 0, 'form_data' => array( 'Name' => 'Sam Suite', 'Email' => 'sam@example.com', 'Message' => 'read one' ), 'status' => 'read', 'created_at' => current_time('mysql') ) );
			 echo wp_json_encode( array( 'form' => $fid, 'a' => $a, 'b' => $b ) );`
		);
		let seed = {};
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		formId = parseInt( seed.form, 10 ) || 0;
		t.check( 'form + two entries seeded', formId > 0 && seed.a > 0 && seed.b > 0, seedOut.slice( 0, 120 ) );

		const list = await api( 'minn-admin/v1/sureforms/entries' );
		t.check( 'entries list answers with the seeded rows', list.status === 200 && ( list.body.total || 0 ) >= 2, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const suiteRow = ( list.body.items || [] ).find( ( i ) => /Suite/.test( i.summary ) );
		t.check( 'row carries a contact summary, form title, pill status, UTC date',
			!! suiteRow && /·/.test( suiteRow.summary ) && suiteRow.form_title === 'minn-sf-suite' && suiteRow.status && /Z$/.test( suiteRow.date || '' ),
			JSON.stringify( suiteRow ) );

		const forms = await api( 'minn-admin/v1/sureforms/forms' );
		t.check( 'forms endpoint feeds the tab strip', forms.status === 200 && ( forms.body || [] ).some( ( f ) => f.id === formId ), JSON.stringify( forms.body && forms.body.slice( 0, 3 ) ) );

		const byForm = await api( `minn-admin/v1/sureforms/entries?form_id=${ formId }` );
		t.check( 'form_id tab narrows to that form', byForm.status === 200 && byForm.body.total === 2, JSON.stringify( { total: byForm.body && byForm.body.total } ) );

		const unread = await api( `minn-admin/v1/sureforms/entries?form_id=${ formId }&status=unread` );
		t.check( 'status filter narrows to unread', unread.status === 200 && unread.body.total === 1, JSON.stringify( { total: unread.body && unread.body.total } ) );

		const search = await api( `minn-admin/v1/sureforms/entries?search=sam@example.com` );
		t.check( 'search matches inside form_data JSON', search.status === 200 && ( search.body.total || 0 ) >= 1, JSON.stringify( { total: search.body && search.body.total } ) );

		const entryId = unread.body.items[ 0 ].id;
		const view = await api( `minn-admin/v1/sureforms/entries/${ entryId }` );
		t.check( 'entry detail is a contact card with labeled answers',
			view.status === 200 && view.body.kind === 'entry'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Answers' && s.rows.some( ( r ) => r.label === 'Email' && /@/.test( r.value ) ) ),
			JSON.stringify( ( view.body.sections || [] ).map( ( s ) => s.title ) ) );

		const mark = await api( `minn-admin/v1/sureforms/entries/${ entryId }/status`, { method: 'POST', body: { status: 'read' } } );
		const afterMark = await api( `minn-admin/v1/sureforms/entries?form_id=${ formId }&status=unread` );
		t.check( 'mark-read moves the entry out of unread', mark.status === 200 && afterMark.body.total === 0, JSON.stringify( { mark: mark.status, unread: afterMark.body && afterMark.body.total } ) );

		const st = await api( 'minn-admin/v1/sureforms/status' );
		t.check( 'status card carries unread + forms rows', st.status === 200 && ( st.body.rows || [] ).some( ( r ) => /Unread/.test( r.label ) ) && ( st.body.rows || [] ).some( ( r ) => r.label === 'Forms' ), JSON.stringify( ( st.body.rows || [] ).map( ( r ) => r.label ) ) );

		const del = await api( `minn-admin/v1/sureforms/entries/${ entryId }`, { method: 'DELETE' } );
		const gone = await api( `minn-admin/v1/sureforms/entries/${ entryId }` );
		t.check( 'delete removes the entry', del.status === 200 && del.body && del.body.deleted && gone.status === 404, JSON.stringify( { del: del.status, gone: gone.status } ) );

		// Browser: the surface renders under the forms family (Workspace).
		// The list rows are proven via REST above; here we confirm the surface
		// paints its status card with no console break (the zero-errors gate).
		await page.goto( `${ BASE }/minn-admin/sureforms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		t.check( 'surface renders its status card', await page.evaluate( () =>
			/Unread entries|Entries/.test( document.querySelector( '.minn-surface-status' ).textContent ) ) );
	} finally {
		if ( formId ) {
			evalPhp( `global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}srfm_entries WHERE form_id = %d", ${ formId } ) ); wp_delete_post( ${ formId }, true );` );
		}
		if ( ! wasActive ) wp( 'plugin deactivate sureforms' );
	}

	await t.done( browser, errors );
} )();
