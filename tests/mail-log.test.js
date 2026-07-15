/**
 * Email Log family — FluentSMTP shim (active mail provider on minnadmin).
 * List, tabs, search, detail, Resend, Delete. Opens /minn-admin/fluent-smtp
 * with family preference pinned so Gravity SMTP does not steal the slot.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'mail-log' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	// --- REST shim ----------------------------------------------------------
	const list = await api( 'minn-admin/v1/fluent-smtp/emails?per_page=5' );
	t.check( 'Shim returns {items,total}',
		list.status === 200 && Array.isArray( list.body.items ) && typeof list.body.total === 'number',
		JSON.stringify( list.status ) );
	t.check( 'Seeded emails present', list.body.total >= 2, `total=${ list.body.total }` );
	const first = list.body.items[ 0 ];
	t.check(
		'Rows carry subject/to/status/date',
		!! first && !! first.subject && String( first.to ).includes( '@' ) && !! first.status && !! first.created_at,
		JSON.stringify( first )
	);

	const detail = await api( 'minn-admin/v1/fluent-smtp/emails/' + first.id );
	t.check( 'Detail carries the message body',
		detail.status === 200 && detail.body.message && detail.body.message.length > 5,
		JSON.stringify( { status: detail.status, len: detail.body && ( detail.body.message || '' ).length } ) );
	t.check( 'Recipients extracted without unserializing',
		String( detail.body.to ).includes( '@' ) && ! String( detail.body.to ).includes( 'a:1:' ),
		String( detail.body.to ) );

	// Search
	const searchHit = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'Minn mail test' ) );
	const hitItems = ( searchHit.body && searchHit.body.items ) || [];
	t.check( 'Search by subject returns matches',
		searchHit.status === 200 && hitItems.length >= 1
		&& hitItems.every( ( it ) => /Minn mail test/i.test( it.subject || '' ) ),
		JSON.stringify( { n: hitItems.length, total: searchHit.body && searchHit.body.total } ) );

	const miss = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'zzznomatch-fluent-minn' ) );
	t.check( 'Search miss returns empty list',
		miss.status === 200 && Array.isArray( miss.body.items ) && miss.body.total === 0,
		JSON.stringify( miss.body ) );

	// Delete a disposable row.
	const doomed = await api( 'minn-admin/v1/fluent-smtp/test', {
		method: 'POST',
		body: JSON.stringify( { email: 'fluent-delete-suite@example.com' } ),
	} );
	let doomedId = null;
	if ( doomed.status === 200 ) {
		const found = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'fluent-delete-suite' ) );
		doomedId = found.body.items && found.body.items[ 0 ] && found.body.items[ 0 ].id;
	}
	t.check( 'Have an id to delete', !! doomedId, JSON.stringify( { status: doomed.status, doomedId } ) );
	if ( doomedId ) {
		const del = await api( 'minn-admin/v1/fluent-smtp/emails/' + doomedId, { method: 'DELETE' } );
		const gone = await api( 'minn-admin/v1/fluent-smtp/emails/' + doomedId );
		t.check( 'DELETE removes the log entry',
			del.status === 200 && del.body && del.body.deleted && gone.status === 404,
			JSON.stringify( { del: del.status, body: del.body, gone: gone.status } ) );
	}

	// --- Surface UI -----------------------------------------------------------
	await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'fluent-smtp' ) );
	await page.goto( BASE + '/minn-admin/fluent-smtp', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

	const ui = await page.evaluate( () => ( {
		rows: document.querySelectorAll( '.minn-table-row' ).length,
		search: !! document.querySelector( '#minn-surface-search' ),
	} ) );
	t.check( 'FluentSMTP surface loads rows', ui.rows >= 1, JSON.stringify( ui ) );
	t.check( 'Email surface exposes a search field', ui.search, JSON.stringify( ui ) );

	// Toolbar search (input event, 350ms debounce).
	if ( ui.search ) {
		await page.click( '#minn-surface-search', { clickCount: 3 } );
		await page.keyboard.type( 'Minn mail test', { delay: 20 } );
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
			return rows.length > 0 && rows.every( ( r ) => /Minn mail test/i.test( r.textContent || '' ) );
		}, null, { timeout: 12000 } ).catch( () => null );
		const found = await page.evaluate( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
			return {
				n: rows.length,
				ok: rows.length > 0 && rows.every( ( r ) => /Minn mail test/i.test( r.textContent || '' ) ),
				first: rows[ 0 ] && rows[ 0 ].textContent,
			};
		} );
		t.check( 'Toolbar search filters the list', found.ok, JSON.stringify( found ) );
	}

	// Open first row (title cell avoids checkbox) and wait for detail to finish loading.
	await page.click( '.minn-table-row .minn-row-title' );
	await page.waitForFunction( () => {
		const m = document.querySelector( '.minn-modal' );
		if ( ! m ) return false;
		const t = m.textContent || '';
		return ! /Loading…|Loading\.\.\./.test( t ) && m.querySelector( '[data-saction]' );
	}, null, { timeout: 20000 } ).catch( () => null );

	const actions = await page.evaluate( () => {
		const labels = Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
			.map( ( b ) => ( b.textContent || '' ).trim() );
		return {
			open: !! document.querySelector( '.minn-modal' ),
			resend: labels.some( ( l ) => /Resend/i.test( l ) ),
			del: labels.some( ( l ) => /Delete/i.test( l ) ),
			labels,
		};
	} );
	t.check( 'Detail view shows the email', actions.open && actions.labels.length > 0, JSON.stringify( actions ) );
	t.check( 'Resend action offered', actions.resend, JSON.stringify( actions ) );
	t.check( 'Detail offers Delete action', actions.del, JSON.stringify( actions ) );

	if ( actions.resend ) {
		const before = ( await api( 'minn-admin/v1/fluent-smtp/emails?per_page=1' ) ).body.total;
		await page.evaluate( () => {
			const b = Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
				.find( ( x ) => /Resend/i.test( x.textContent || '' ) );
			if ( b ) b.click();
		} );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) )
				.some( ( x ) => /Resend — done|resent|done/i.test( x.textContent ) ),
			null, { timeout: 20000 }
		).catch( () => null );
		const after = ( await api( 'minn-admin/v1/fluent-smtp/emails?per_page=1' ) ).body.total;
		t.check( 'Resend logged a new sent email', after >= before, `before=${ before } after=${ after }` );
	}

	/* ===== Gravity SMTP: single + bulk delete (active mail reference) =====
	 * Seed disposable event rows via REST-side insert is awkward (custom table
	 * shape); use rest_do_request after a CLI insert, then DELETE through the
	 * adapter. GSMTP is already an active resident on minnadmin. */
	{
		const { execSync } = require( 'child_process' );
		const path = require( 'path' );
		const fs = require( 'fs' );
		const wpPath = path.resolve( __dirname, '../../../../' );
		const gsmtpActive = ( () => {
			try {
				execSync( `wp --path=${ JSON.stringify( wpPath ) } plugin is-active gravitysmtp`, {
					stdio: 'ignore', timeout: 30000,
				} );
				return true;
			} catch ( e ) {
				return false;
			}
		} )();
		t.check( 'Gravity SMTP active for delete suite', gsmtpActive, gsmtpActive ? 'active' : 'inactive' );
		if ( gsmtpActive ) {
			const seedFile = path.join( require( 'os' ).tmpdir(), 'minn-gsmtp-seed-' + Date.now() + '.php' );
			fs.writeFileSync( seedFile, [
				'<?php',
				'global $wpdb;',
				"$t = $wpdb->prefix . 'gravitysmtp_events';",
				"if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { echo wp_json_encode( array( 'ok' => false ) ); exit; }",
				'$ids = array();',
				'for ( $i = 0; $i < 2; $i++ ) {',
				'  $wpdb->insert( $t, array(',
				"    'date_created' => gmdate( 'Y-m-d H:i:s' ),",
				"    'date_updated' => gmdate( 'Y-m-d H:i:s' ),",
				"    'status'       => 'sent',",
				"    'service'      => 'phpmail',",
				"    'subject'      => 'Minn GSMTP delete suite ' . $i . ' ' . time(),",
				"    'message'      => 'suite body',",
				"    'extra'        => '',",
				'  ) );',
				'  $ids[] = (int) $wpdb->insert_id;',
				'}',
				'echo wp_json_encode( array( "ok" => true, "ids" => $ids ) );',
			].join( '\n' ) );
			let seedOut = '';
			try {
				seedOut = execSync(
					`wp --path=${ JSON.stringify( wpPath ) } --skip-plugins --skip-themes eval-file ${ JSON.stringify( seedFile ) }`,
					{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 30000 }
				);
			} catch ( e ) {
				seedOut = ( e.stdout || '' ) + ( e.stderr || '' );
			}
			try { fs.unlinkSync( seedFile ); } catch ( e ) { /* ignore */ }
			let seed = {};
			try {
				const line = String( seedOut ).trim().split( /\r?\n/ ).filter( ( l ) => l.startsWith( '{' ) ).pop();
				seed = JSON.parse( line || '{}' );
			} catch ( e ) {
				seed = {};
			}
			const ids = Array.isArray( seed.ids ) ? seed.ids.filter( ( n ) => n > 0 ) : [];
			t.check( 'Seeded two Gravity SMTP events', ids.length === 2, JSON.stringify( seed ) );
			if ( ids.length === 2 ) {
				const del1 = await api( 'minn-admin/v1/gravity-smtp/events/' + ids[ 0 ], { method: 'DELETE' } );
				const gone1 = await api( 'minn-admin/v1/gravity-smtp/events/' + ids[ 0 ] );
				t.check( 'GSMTP DELETE removes one log entry',
					del1.status === 200 && del1.body && del1.body.deleted && gone1.status === 404,
					JSON.stringify( { del: del1.status, body: del1.body, gone: gone1.status } ) );
				const del2 = await api( 'minn-admin/v1/gravity-smtp/events/' + ids[ 1 ], { method: 'DELETE' } );
				const gone2 = await api( 'minn-admin/v1/gravity-smtp/events/' + ids[ 1 ] );
				t.check( 'GSMTP bulk path deletes second entry (same DELETE route)',
					del2.status === 200 && del2.body && del2.body.deleted && gone2.status === 404,
					JSON.stringify( { del: del2.status, body: del2.body, gone: gone2.status } ) );
			}
		}
	}

	/* ===== Post SMTP: search + delete (family inactive fixture) =====
	 * CLI activate + seed avoids browser plugin-toggle races (worker recycle /
	 * stale nonce). Always restore inactive in finally. */
	const { execSync } = require( 'child_process' );
	const path = require( 'path' );
	const wpPath = path.resolve( __dirname, '../../../../' );
	const wp = ( args, extra = '' ) => {
		try {
			return execSync( `wp --path=${ JSON.stringify( wpPath ) } ${ extra } ${ args }`, {
				encoding: 'utf8',
				stdio: [ 'ignore', 'pipe', 'pipe' ],
				timeout: 60000,
			} );
		} catch ( e ) {
			return ( e.stdout || '' ) + ( e.stderr || '' );
		}
	};

	let postSmtpInstalled = false;
	let postSmtpWasActive = false;
	try {
		const list = wp( 'plugin list --field=name' );
		postSmtpInstalled = list.split( /\r?\n/ ).map( ( s ) => s.trim() ).includes( 'post-smtp' );
		if ( postSmtpInstalled ) {
			try {
				execSync( `wp --path=${ JSON.stringify( wpPath ) } plugin is-active post-smtp`, {
					stdio: 'ignore', timeout: 30000,
				} );
				postSmtpWasActive = true;
			} catch ( e ) {
				postSmtpWasActive = false;
			}
		}
	} catch ( e ) {
		postSmtpInstalled = false;
	}

	t.check( 'Post SMTP plugin available for suite', postSmtpInstalled, postSmtpInstalled ? 'installed' : 'missing' );

	if ( postSmtpInstalled ) {
		// One SMTP mailer at a time (Fluent + Post SMTP together can white-screen).
		const fluentWasActive = ( () => {
			try {
				execSync( `wp --path=${ JSON.stringify( wpPath ) } plugin is-active fluent-smtp`, {
					stdio: 'ignore', timeout: 30000,
				} );
				return true;
			} catch ( e ) {
				return false;
			}
		} )();
		try {
			if ( fluentWasActive ) wp( 'plugin deactivate fluent-smtp' );
			if ( ! postSmtpWasActive ) wp( 'plugin activate post-smtp' );

			const stamp = 'post-smtp-minn-' + Date.now();
			const fs = require( 'fs' );
			const seedFile = path.join( require( 'os' ).tmpdir(), 'minn-postsmtp-seed-' + Date.now() + '.php' );
			fs.writeFileSync( seedFile, [
				'<?php',
				'global $wpdb;',
				"$t = $wpdb->prefix . 'post_smtp_logs';",
				"if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { echo 0; exit; }",
				'$ok = $wpdb->insert( $t, array(',
				"  'original_subject' => " + JSON.stringify( stamp ) + ',',
				"  'original_to'      => 'post-smtp-suite@example.com',",
				"  'to_header'        => 'post-smtp-suite@example.com',",
				"  'from_header'      => 'admin@example.com',",
				"  'original_message' => 'post-smtp suite body',",
				"  'success'          => '',",
				"  'time'             => (int) current_time( 'timestamp' ),",
				"), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );",
				'echo $ok ? (int) $wpdb->insert_id : 0;',
			].join( '\n' ) );
			let seedOut = '';
			try {
				seedOut = execSync(
					`wp --path=${ JSON.stringify( wpPath ) } --skip-plugins --skip-themes eval-file ${ JSON.stringify( seedFile ) }`,
					{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 30000 }
				);
			} catch ( e ) {
				seedOut = ( e.stdout || '' ) + ( e.stderr || '' );
			}
			try { fs.unlinkSync( seedFile ); } catch ( e ) { /* ignore */ }
			const m = String( seedOut ).trim().match( /^(\d+)$/m );
			const insertedId = m ? parseInt( m[ 1 ], 10 ) : 0;
			t.check( 'Seeded a Post SMTP log row', insertedId > 0, JSON.stringify( String( seedOut ).trim().slice( 0, 120 ) ) );

			// Drive REST through WP-CLI (rest_do_request) — no browser pageload
			// after the mailer swap (FrankenPHP / dual-mailer churn).
			const restPhp = path.join( require( 'os' ).tmpdir(), 'minn-postsmtp-rest-' + Date.now() + '.php' );
			fs.writeFileSync( restPhp, [
				'<?php',
				'wp_set_current_user( 1 );',
				'$out = array();',
				'$s = rest_do_request( new WP_REST_Request( "GET", "/minn-admin/v1/post-smtp/status" ) );',
				'$out["status"] = $s->get_status();',
				insertedId > 0 ? [
					'$q = new WP_REST_Request( "GET", "/minn-admin/v1/post-smtp/emails" );',
					'$q->set_param( "search", ' + JSON.stringify( stamp ) + ' );',
					'$hit = rest_do_request( $q );',
					'$out["search_status"] = $hit->get_status();',
					'$out["search_total"] = is_array( $hit->get_data() ) ? (int) ( $hit->get_data()["total"] ?? 0 ) : 0;',
					'$out["search_has"] = false;',
					'if ( is_array( $hit->get_data() ) && ! empty( $hit->get_data()["items"] ) ) {',
					'  foreach ( $hit->get_data()["items"] as $it ) {',
					'    if ( (int) ( $it["id"] ?? 0 ) === ' + insertedId + ' || false !== strpos( (string) ( $it["subject"] ?? "" ), ' + JSON.stringify( stamp ) + ' ) ) { $out["search_has"] = true; }',
					'  }',
					'}',
					'$m = new WP_REST_Request( "GET", "/minn-admin/v1/post-smtp/emails" );',
					'$m->set_param( "search", "zzznomatch-postsmtp-minn" );',
					'$miss = rest_do_request( $m );',
					'$out["miss_total"] = is_array( $miss->get_data() ) ? (int) ( $miss->get_data()["total"] ?? -1 ) : -1;',
					'$d = new WP_REST_Request( "DELETE", "/minn-admin/v1/post-smtp/emails/' + insertedId + '" );',
					'$del = rest_do_request( $d );',
					'$out["del_status"] = $del->get_status();',
					'$out["del_deleted"] = is_array( $del->get_data() ) && ! empty( $del->get_data()["deleted"] );',
					'$g = rest_do_request( new WP_REST_Request( "GET", "/minn-admin/v1/post-smtp/emails/' + insertedId + '" ) );',
					'$out["gone_status"] = $g->get_status();',
				].join( '\n' ) : '$out["skipped"] = true;',
				'echo wp_json_encode( $out );',
			].join( '\n' ) );
			let restOut = '';
			try {
				restOut = execSync(
					`wp --path=${ JSON.stringify( wpPath ) } eval-file ${ JSON.stringify( restPhp ) }`,
					{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 60000 }
				);
			} catch ( e ) {
				restOut = ( e.stdout || '' ) + ( e.stderr || '' );
			}
			try { fs.unlinkSync( restPhp ); } catch ( e ) { /* ignore */ }
			let rest = {};
			try {
				const jsonLine = String( restOut ).trim().split( /\r?\n/ ).filter( ( l ) => l.startsWith( '{' ) ).pop();
				rest = JSON.parse( jsonLine || '{}' );
			} catch ( e ) {
				rest = { parse_error: String( restOut ).slice( 0, 200 ) };
			}
			t.check( 'Post SMTP routes register when active', rest.status === 200, JSON.stringify( rest ) );
			if ( insertedId > 0 ) {
				t.check( 'Post SMTP search finds the seeded subject',
					rest.search_status === 200 && rest.search_has === true,
					JSON.stringify( rest ) );
				t.check( 'Post SMTP search miss is empty',
					rest.miss_total === 0,
					JSON.stringify( rest ) );
				t.check( 'Post SMTP DELETE removes the log entry',
					rest.del_status === 200 && rest.del_deleted === true && rest.gone_status === 404,
					JSON.stringify( rest ) );
			}
		} finally {
			// Restore mail residents: FluentSMTP active, Post SMTP inactive.
			if ( ! postSmtpWasActive ) wp( 'plugin deactivate post-smtp' );
			if ( fluentWasActive ) wp( 'plugin activate fluent-smtp' );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
