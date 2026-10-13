/**
 * /minn-admin/users/new — Add user is the user page over an account not
 * created yet, holding what core's Add User screen asks.
 *
 * Covers: the Users list's Add user opens the page; it starts on the site's
 * default role (the suite moves default_role off Subscriber to prove it is
 * read, not assumed), a generated password and the welcome email on; typing
 * arms the leave guard; a missing field or a taken username keeps the page
 * and creates nothing; a full create sends the account, then the language,
 * then the welcome email, saves every field, and swaps the address for the
 * account's own page (Back returns to the list); with the email off, none is
 * sent. The welcome route's email is read through core's own filter, its gate
 * refuses an Author, and it reports (never claims) an email a plugin turned
 * off, an account barred from resets and a failed send, keeping the mailer's
 * detail to the log. ⌘S never creates (Create user can send an email). Once
 * the account exists, leaving while its email is still on the way asks
 * nothing and stays where it went. An account that may add users but not
 * edit them (a network site's administrator; here a role made for it) gets
 * no language or email, which it could not set, and lands on the list. An
 * Author on /users/new gets the permission note.
 */
const { BASE, WP, launch, login, loginAs, reporter, pickCombo, comboValue, setSwitch, switchOn } = require( './helpers' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
const evalFile = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-user-new-${ process.pid }.php` );
	fs.writeFileSync( file, php );
	try {
		return wp( `eval-file ${ JSON.stringify( file ) }` );
	} finally {
		fs.unlinkSync( file );
	}
};
const userId = ( login ) => parseInt( wp( `user get ${ JSON.stringify( login ) } --field=ID` ) || '0', 10 ) || 0;

( async () => {
	const t = reporter( 'user-new-page' );
	const { browser, page, errors } = await launch();
	const stamp = Date.now() % 1000000;
	const full = { username: `minn-new-${ stamp }`, email: `minn-new-${ stamp }@example.com` };
	const bare = { username: `minn-bare-${ stamp }`, email: `minn-bare-${ stamp }@example.com` };
	const made = [];
	const origDefault = wp( 'option get default_role' ) || 'subscriber';
	let author = null;

	// Every create-flow request, in order, with its status.
	const flow = [];
	page.on( 'response', ( r ) => {
		const u = r.url();
		const req = r.request();
		if ( 'POST' !== req.method() ) return;
		if ( /wp\/v2\/users(\?|$)/.test( u.split( 'rest_route=' ).pop() ) || /\/wp-json\/wp\/v2\/users$/.test( u.split( '?' )[ 0 ] ) ) flow.push( { step: 'create', status: r.status() } );
		else if ( /\/users\/\d+\/language/.test( u ) ) flow.push( { step: 'language', status: r.status() } );
		else if ( /\/users\/\d+\/welcome/.test( u ) ) flow.push( { step: 'welcome', status: r.status() } );
	} );

	try {
		// A default role other than Subscriber, so the picker has to read it.
		wp( 'option update default_role author' );
		await login( page );

		/* ===== The page opens from the Users list ===== */
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-user', { timeout: 20000 } );
		await page.click( '#minn-add-user' );
		await page.waitForSelector( '#minn-un-username', { timeout: 15000 } );
		t.check( 'Add user opens /users/new', /\/minn-admin\/users\/new$/.test( new URL( page.url() ).pathname ), page.url() );
		const shell = await page.evaluate( () => ( {
			title: ( document.querySelector( '.minn-ue-new .minn-modal-title' ) || {} ).textContent,
			topbar: ( document.querySelector( '#minn-title' ) || {} ).textContent,
			usersLit: !! document.querySelector( '[data-nav="users"].active' ),
			dialog: !! document.querySelector( '.minn-modal-overlay' ),
			password: document.querySelector( '#minn-un-password' ).value,
			pristine: document.querySelector( '#minn-un-savebar' ).hasAttribute( 'data-pristine' ),
			focused: document.activeElement && document.activeElement.id,
		} ) );
		t.check( 'the page is titled New user, the topbar Add user', 'New user' === shell.title && 'Add user' === shell.topbar, JSON.stringify( shell ) );
		t.check( 'no dialog opens', ! shell.dialog );
		t.check( 'the Users item stays lit', shell.usersLit );
		t.check( 'a password is generated', shell.password.length >= 20, String( shell.password.length ) );
		t.check( 'the username field has focus', 'minn-un-username' === shell.focused, shell.focused );
		t.check( 'the role starts on the site default role', 'author' === await comboValue( page, '#minn-un-role' ), await comboValue( page, '#minn-un-role' ) );
		t.check( 'the language starts on Site default', '' === await comboValue( page, '#minn-un-lang' ) );
		t.check( 'the welcome email starts on', true === await switchOn( page, '#minn-un-notify' ) );
		t.check( 'the save bar starts pristine', shell.pristine );

		/* ===== Typing arms the leave guard ===== */
		await page.click( '#minn-un-username' );
		await page.keyboard.type( 'leave-me' );
		t.check( 'typing marks the page unsaved', ! await page.evaluate( () => document.querySelector( '#minn-un-savebar' ).hasAttribute( 'data-pristine' ) ) );
		await page.click( '#minn-un-back' );
		await page.waitForSelector( '.minn-confirm-overlay [data-cancel]', { timeout: 5000 } );
		t.check( 'leaving asks first', /Leave without saving/.test( await page.textContent( '.minn-confirm-overlay' ) ) );
		await page.click( '.minn-confirm-overlay [data-cancel]' );
		await page.waitForSelector( '.minn-confirm-overlay', { state: 'detached', timeout: 5000 } ).catch( () => {} );
		t.check( 'staying keeps the page and what was typed',
			/\/users\/new$/.test( page.url() ) && 'leave-me' === await page.inputValue( '#minn-un-username' ), page.url() );

		/* ===== ⌘S leaves Create user to a click ===== */
		const beforeShortcut = flow.length;
		await page.fill( '#minn-un-email', `minn-shortcut-${ stamp }@example.com` );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 800 );
		t.check( '⌘S creates nothing', flow.length === beforeShortcut && /\/users\/new$/.test( page.url() ), JSON.stringify( flow.slice( beforeShortcut ) ) );
		await page.fill( '#minn-un-email', '' );

		/* ===== A missing field: nothing is sent ===== */
		const before = flow.length;
		await page.click( '#minn-un-save' );
		await page.waitForTimeout( 400 );
		t.check( 'a create with no email sends nothing', flow.length === before, JSON.stringify( flow.slice( before ) ) );
		t.check( 'and puts the caret in Email', 'minn-un-email' === await page.evaluate( () => document.activeElement && document.activeElement.id ) );

		/* ===== A taken username: refused, the page stays ===== */
		await page.fill( '#minn-un-username', 'admin' );
		await page.fill( '#minn-un-email', `minn-taken-${ stamp }@example.com` );
		const refused = page.waitForResponse( ( r ) => 'POST' === r.request().method() && /wp\/v2\/users/.test( r.url() ), { timeout: 20000 } );
		await page.click( '#minn-un-save' );
		const refusedRes = await refused;
		await page.waitForFunction( () => ! document.querySelector( '#minn-un-save' ).disabled, null, { timeout: 10000 } );
		t.check( 'a taken username is refused', refusedRes.status() >= 400, String( refusedRes.status() ) );
		t.check( 'the page stays, with the caret in Username',
			/\/users\/new$/.test( page.url() ) && 'minn-un-username' === await page.evaluate( () => document.activeElement && document.activeElement.id ), page.url() );
		t.check( 'no account was made for the refused email', '' === wp( `user list --search=${ JSON.stringify( `minn-taken-${ stamp }@example.com` ) } --field=ID` ) );

		/* ===== Everything filled in ===== */
		await page.fill( '#minn-un-username', full.username );
		await page.fill( '#minn-un-email', full.email );
		await pickCombo( page, '#minn-un-role', 'editor' );
		await pickCombo( page, '#minn-un-lang', 'fr_FR' );
		await page.fill( '#minn-un-first', 'Nora' );
		await page.fill( '#minn-un-last', 'Quill' );
		await page.fill( '#minn-un-url', 'https://example.com/nora' );
		await page.fill( '#minn-un-bio', 'Writes the field guides.' );
		const password = await page.inputValue( '#minn-un-password' );
		flow.length = 0;
		await page.click( '#minn-un-save' );
		await page.waitForFunction( () => /\/minn-admin\/users\/\d+$/.test( location.pathname ), null, { timeout: 30000 } );
		const fullId = parseInt( new URL( page.url() ).pathname.split( '/' ).pop(), 10 );
		if ( fullId ) made.push( fullId );
		t.check( 'Create lands on the account’s page', fullId > 0 && fullId === userId( full.username ), page.url() );
		t.check( 'it sends the account, then the language, then the email',
			'create,language,welcome' === flow.map( ( f ) => f.step ).join( ',' ) && flow.every( ( f ) => f.status < 300 ), JSON.stringify( flow ) );
		const saved = JSON.parse( evalFile( `<?php $u = get_userdata( ${ fullId } ); echo wp_json_encode( array(
			'roles' => array_values( $u->roles ), 'first' => $u->first_name, 'last' => $u->last_name, 'url' => $u->user_url,
			'bio' => $u->description, 'locale' => get_user_meta( $u->ID, 'locale', true ), 'display' => $u->display_name,
			'email' => $u->user_email, 'key' => '' !== (string) $u->user_activation_key,
			'pass' => wp_check_password( base64_decode( '${ Buffer.from( password ).toString( 'base64' ) }' ), $u->user_pass, $u->ID ),
		) );` ) || '{}' );
		t.check( 'the picked role is saved', 'editor' === ( saved.roles || [] ).join( ',' ), JSON.stringify( saved.roles ) );
		t.check( 'the profile fields are saved',
			'Nora' === saved.first && 'Quill' === saved.last && 'https://example.com/nora' === saved.url && 'Writes the field guides.' === saved.bio && full.email === saved.email, JSON.stringify( saved ) );
		t.check( 'an empty display name is composed from the names', 'Nora Quill' === saved.display, saved.display );
		t.check( 'the language is saved', 'fr_FR' === saved.locale, saved.locale );
		t.check( 'the generated password is the account’s', true === saved.pass );
		t.check( 'the welcome email minted a set-password link', true === saved.key );
		await page.waitForSelector( '#minn-ue-name', { timeout: 15000 } );
		t.check( 'the account’s page shows the new account', 'Nora Quill' === await page.inputValue( '#minn-ue-name' ) );
		await page.goBack();
		await page.waitForFunction( () => ! /\/users\/(new|\d+)$/.test( location.pathname ), null, { timeout: 10000 } ).catch( () => {} );
		t.check( 'Back returns to the Users list, not the empty form', /\/minn-admin\/users$/.test( new URL( page.url() ).pathname ), page.url() );

		/* ===== Only what is required, email off ===== */
		await page.goto( BASE + '/minn-admin/users/new', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-un-username', { timeout: 15000 } );
		await page.fill( '#minn-un-username', bare.username );
		await page.fill( '#minn-un-email', bare.email );
		await setSwitch( page, '#minn-un-notify', false );
		flow.length = 0;
		await page.click( '#minn-un-save' );
		await page.waitForFunction( () => /\/minn-admin\/users\/\d+$/.test( location.pathname ), null, { timeout: 30000 } );
		const bareId = parseInt( new URL( page.url() ).pathname.split( '/' ).pop(), 10 );
		if ( bareId ) made.push( bareId );
		t.check( 'with the email off, only the account is sent', 'create' === flow.map( ( f ) => f.step ).join( ',' ), JSON.stringify( flow ) );
		const bareSaved = JSON.parse( evalFile( `<?php $u = get_userdata( ${ bareId } ); echo wp_json_encode( array(
			'roles' => array_values( $u->roles ), 'display' => $u->display_name, 'key' => '' !== (string) $u->user_activation_key,
			'locale' => get_user_meta( $u->ID, 'locale', true ), 'first' => $u->first_name,
		) );` ) || '{}' );
		t.check( 'it gets the site’s default role', 'author' === ( bareSaved.roles || [] ).join( ',' ), JSON.stringify( bareSaved.roles ) );
		t.check( 'its display name is the username', bare.username === bareSaved.display, bareSaved.display );
		t.check( 'no language and no names are stored', '' === bareSaved.locale && '' === bareSaved.first, JSON.stringify( bareSaved ) );
		t.check( 'no set-password link was minted', false === bareSaved.key );

		/* ===== Leaving while the email is on its way ===== */
		const slow = { username: `minn-slow-${ stamp }`, email: `minn-slow-${ stamp }@example.com` };
		const hold = async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 3000 ) );
			await route.continue().catch( () => {} );
		};
		await page.route( /\/users\/\d+\/welcome/, hold );
		await page.goto( BASE + '/minn-admin/users/new', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-un-username', { timeout: 15000 } );
		await page.fill( '#minn-un-username', slow.username );
		await page.fill( '#minn-un-email', slow.email );
		const created = page.waitForResponse( ( r ) => 'POST' === r.request().method() && /\/wp\/v2\/users$/.test( r.url().split( '?' )[ 0 ] ), { timeout: 20000 } );
		await page.click( '#minn-un-save' );
		await created;
		await page.waitForTimeout( 200 );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const askedWhileSending = await page.waitForSelector( '.minn-confirm-overlay', { timeout: 1500 } ).catch( () => null );
		t.check( 'leaving after the account exists asks nothing', ! askedWhileSending );
		if ( askedWhileSending ) await page.click( '.minn-confirm-overlay [data-cancel]' ).catch( () => {} );
		await page.waitForTimeout( 3800 );
		const slowId = userId( slow.username );
		if ( slowId ) made.push( slowId );
		t.check( 'and the finished create leaves them where they went', !! slowId && ! /\/users\/\d+$/.test( new URL( page.url() ).pathname ), page.url() );
		await page.unroute( /\/users\/\d+\/welcome/, hold );

		/* ===== The welcome route: core's email, and its gate ===== */
		author = userId( 'minn-author' );
		const probe = JSON.parse( evalFile( `<?php
			$sent = array();
			add_filter( 'wp_new_user_notification_email', function ( $email, $user ) use ( &$sent ) {
				$sent[] = array( 'to' => $email['to'], 'body' => $email['message'], 'user' => $user->user_login );
				return $email;
			}, 10, 2 );
			// No real send: the filter above already saw the message.
			add_filter( 'pre_wp_mail', '__return_true' );
			$ask = function ( $as ) {
				wp_set_current_user( $as );
				return rest_do_request( new WP_REST_Request( 'POST', '/minn-admin/v1/users/${ bareId }/welcome' ) );
			};
			$admin_id = get_user_by( 'login', 'admin' )->ID;
			$admin = $ask( $admin_id );
			$by_author = $ask( ${ author || 0 } );
			$sent_by_then = count( $sent );
			$said = function ( $res ) {
				$d = $res->get_data();
				return array( 'status' => $res->get_status(), 'code' => is_array( $d ) && isset( $d['code'] ) ? $d['code'] : '', 'message' => is_array( $d ) && isset( $d['message'] ) ? $d['message'] : '' );
			};
			// A plugin that turns the new-account email off.
			add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
			$off = $said( $ask( $admin_id ) );
			remove_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
			// A plugin that refuses password resets for the account.
			add_filter( 'allow_password_reset', '__return_false' );
			$blocked = $said( $ask( $admin_id ) );
			remove_filter( 'allow_password_reset', '__return_false' );
			// A failed send, first for someone else, then for them, carrying
			// mailer detail that must not reach the caller.
			$fail_for = '';
			add_filter( 'wp_new_user_notification_email', function ( $email ) use ( &$fail_for ) {
				if ( $fail_for ) {
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: relay.secret.example:587 refused', array( 'to' => array( $fail_for ) ) ) );
				}
				return $email;
			}, 5 );
			$fail_for = 'someone-else@example.com';
			$other = $said( $ask( $admin_id ) );
			$fail_for = ${ JSON.stringify( bare.email ) };
			$mine = $said( $ask( $admin_id ) );
			echo wp_json_encode( array( 'admin' => $admin->get_status(), 'author' => $by_author->get_status(), 'sent' => $sent, 'sentByThen' => $sent_by_then,
				'off' => $off, 'blocked' => $blocked, 'other' => $other, 'mine' => $mine ) );
		` ) || '{}' );
		const mail = ( probe.sent || [] )[ 0 ] || {};
		t.check( 'an administrator can send the welcome email', 200 === probe.admin, JSON.stringify( probe ) );
		t.check( 'it is WordPress’s new-account email, to the account', bare.email === mail.to && bare.username === mail.user && mail.body && mail.body.includes( bare.username ), JSON.stringify( mail ) );
		t.check( 'an Author is refused, and nothing more is sent', author > 0 && 403 === probe.author && 1 === probe.sentByThen, JSON.stringify( { author, status: probe.author, sent: probe.sentByThen } ) );
		const said = ( k ) => JSON.stringify( probe[ k ] );
		t.check( 'an email a plugin turned off is reported, not claimed', probe.off && 409 === probe.off.status && 'minn_welcome_off' === probe.off.code, said( 'off' ) );
		t.check( 'an account barred from resets is reported, not emailed', probe.blocked && 409 === probe.blocked.status && 'minn_welcome_blocked' === probe.blocked.code, said( 'blocked' ) );
		t.check( 'another email failing meanwhile is not this one', probe.other && 200 === probe.other.status, said( 'other' ) );
		t.check( 'their email failing is reported', probe.mine && 500 === probe.mine.status && 'minn_welcome_failed' === probe.mine.code, said( 'mine' ) );
		t.check( 'without the mailer’s own detail', probe.mine && ! /relay\.secret|SMTP/.test( probe.mine.message ), said( 'mine' ) );

		/* ===== Someone who may add users but not edit them ===== */
		const adder = { username: `minn-adder-${ stamp }`, pass: `minn-adder-pass-${ stamp }` };
		const added = { username: `minn-added-${ stamp }`, email: `minn-added-${ stamp }@example.com` };
		wp( `eval 'add_role( "minn_adder_role", "Minn adder", array( "read" => true, "edit_posts" => true, "list_users" => true, "create_users" => true ) );'` );
		const adderId = parseInt( wp( `user create ${ adder.username } ${ adder.username }@example.com --role=minn_adder_role --user_pass=${ adder.pass } --porcelain` ), 10 ) || 0;
		if ( adderId ) made.push( adderId );
		const { ctx: dctx, page: dp } = await loginAs( browser, adder.username, adder.pass );
		const dflow = [];
		dp.on( 'response', ( r ) => {
			if ( 'POST' !== r.request().method() ) return;
			const u = r.url();
			if ( /\/wp-json\/wp\/v2\/users$/.test( u.split( '?' )[ 0 ] ) ) dflow.push( 'create' );
			else if ( /\/users\/\d+\/(language|welcome)/.test( u ) ) dflow.push( u.match( /\/(language|welcome)/ )[ 1 ] );
		} );
		await dp.goto( BASE + '/minn-admin/users/new', { waitUntil: 'domcontentloaded' } );
		await dp.waitForSelector( '#minn-un-username', { timeout: 15000 } );
		const adderPage = await dp.evaluate( () => ( {
			lang: !! document.querySelector( '#minn-un-lang' ),
			notify: !! document.querySelector( '#minn-un-notify' ),
			note: ( document.querySelector( '#minn-un-noemail' ) || {} ).textContent || '',
		} ) );
		t.check( 'an account that can\'t edit users gets no language or email, and is told why',
			! adderPage.lang && ! adderPage.notify && /pass the password on/.test( adderPage.note ), JSON.stringify( adderPage ) );
		await dp.fill( '#minn-un-username', added.username );
		await dp.fill( '#minn-un-email', added.email );
		await dp.click( '#minn-un-save' );
		await dp.waitForFunction( () => /\/minn-admin\/users$/.test( location.pathname ), null, { timeout: 30000 } ).catch( () => {} );
		const addedId = userId( added.username );
		if ( addedId ) made.push( addedId );
		t.check( 'it creates the account and lands on the Users list', !! addedId && /\/minn-admin\/users$/.test( new URL( dp.url() ).pathname ), dp.url() );
		t.check( 'sending only the account', 'create' === dflow.join( ',' ), dflow.join( ',' ) );
		await dctx.close();

		/* ===== Someone who may not add users ===== */
		const { ctx: actx, page: ap } = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const aerr = [];
		ap.on( 'pageerror', ( e ) => aerr.push( String( e ) ) );
		await ap.goto( BASE + '/minn-admin/users/new', { waitUntil: 'domcontentloaded' } );
		await ap.waitForSelector( '#minn-view .minn-empty', { timeout: 15000 } );
		t.check( 'an Author gets the permission note, not the form',
			/permission to add users/.test( await ap.textContent( '#minn-view' ) ) && ! await ap.$( '#minn-un-username' ), await ap.textContent( '#minn-view' ) );
		t.check( 'and no page errors', 0 === aerr.length, aerr.join( ' | ' ) );
		await actx.close();
	} finally {
		made.forEach( ( id ) => { try { wp( `user delete ${ id } --yes` ); } catch ( e ) {} } );
		try { wp( `eval 'remove_role( "minn_adder_role" );'` ); } catch ( e ) {}
		try { wp( `option update default_role ${ origDefault }` ); } catch ( e ) {}
	}
	await t.done( browser, errors );
} )();
