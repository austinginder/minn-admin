/**
 * Saving a user's name or email leaves their roles alone.
 *
 * The role picker always holds a value (the first role, or Subscriber when
 * the user has none), and every save used to send it: renaming a user who
 * had no role on this site made them a Subscriber, and a user with two roles
 * lost the second. Roles now go only when the picked one changed.
 *
 * A user with no role, or several, opens on an empty picker, so picking any
 * role for them (Subscriber, or the first of their roles) is a change.
 *
 * Fixtures: two users made through WP-CLI, removed after.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-urk-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};
const roles = ( id ) => JSON.parse( wpEval( `$u = get_userdata( ${ id } ); echo wp_json_encode( $u ? array_values( $u->roles ) : null );` ) || 'null' );

( async () => {
	const t = reporter( 'user-role-keep' );
	const { browser, page, errors } = await launch();
	await login( page );
	const tag = Date.now().toString( 36 );
	const made = [];
	try {
		const none = parseInt( wpEval( `echo (int) wp_insert_user( array( 'user_login' => 'minn-norole-${ tag }', 'user_email' => 'minn-norole-${ tag }@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => '' ) );` ), 10 );
		const two = parseInt( wpEval( `$id = wp_insert_user( array( 'user_login' => 'minn-tworole-${ tag }', 'user_email' => 'minn-tworole-${ tag }@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'author' ) ); ( new WP_User( $id ) )->add_role( 'contributor' ); echo (int) $id;` ), 10 );
		made.push( none, two );
		t.check( 'fixtures: one user with no role, one with two', JSON.stringify( roles( none ) ) === '[]' && JSON.stringify( roles( two ) ) === '["author","contributor"]', JSON.stringify( [ roles( none ), roles( two ) ] ) );

		for ( const [ id, want, what ] of [ [ none, [], 'no role' ], [ two, [ 'author', 'contributor' ], 'two roles' ] ] ) {
			await page.goto( `${ BASE }/minn-admin/users/${ id }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-ue-name', { timeout: 15000 } );
			await page.fill( '#minn-ue-name', `Renamed ${ tag }` );
			const done = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `wp/v2/users/${ id }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
			await page.click( '[data-ue-save]' );
			const res = await done;
			const sent = JSON.parse( res.request().postData() || '{}' );
			t.check( `renaming a user with ${ what } saves the name (control)`, wpEval( `echo get_userdata( ${ id } )->display_name;` ) === `Renamed ${ tag }`, '' );
			t.check( `...sends no roles`, ! ( 'roles' in sent ), JSON.stringify( sent.roles ) );
			t.check( `...and keeps ${ what }`, JSON.stringify( roles( id ) ) === JSON.stringify( want ), JSON.stringify( roles( id ) ) );
		}

		// A user with no role, or several, opens on an empty picker, so any
		// role picked for them is sent: Subscriber for the role-less one, and
		// the first of the two for the other.
		const pick = async ( id, label ) => {
			await page.goto( `${ BASE }/minn-admin/users/${ id }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-ue-role', { timeout: 15000 } );
			const shown = await page.$eval( '#minn-ue-role', ( e ) => [ e.value, e.dataset.acValue ] );
			await page.click( '#minn-ue-role' );
			await page.fill( '#minn-ue-role', label );
			await page.waitForSelector( '#minn-ue-role-ac .minn-ac-item', { timeout: 8000 } );
			await page.click( `#minn-ue-role-ac .minn-ac-item:has-text("${ label }")` );
			const done = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `wp/v2/users/${ id }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
			await page.click( '[data-ue-save]' );
			await done;
			return shown;
		};
		const noneShown = await pick( none, 'Subscriber' );
		t.check( 'a user with no role opens on an empty picker', '' === noneShown[ 1 ], JSON.stringify( noneShown ) );
		t.check( '...and picking Subscriber gives them Subscriber', JSON.stringify( roles( none ) ) === '["subscriber"]', JSON.stringify( roles( none ) ) );
		const twoShown = await pick( two, 'Author' );
		t.check( 'a user with two roles opens on an empty picker', '' === twoShown[ 1 ], JSON.stringify( twoShown ) );
		t.check( '...and picking the first of them leaves just that one', JSON.stringify( roles( two ) ) === '["author"]', JSON.stringify( roles( two ) ) );
		// Control: picking another role still saves it, from a user with ONE
		// role (Author), so the change back below is to the role the page
		// first loaded.
		wpEval( `( new WP_User( ${ two } ) )->set_role( 'author' );` );
		await page.goto( `${ BASE }/minn-admin/users/${ two }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ue-role', { timeout: 15000 } );
		await page.click( '#minn-ue-role' );
		await page.fill( '#minn-ue-role', 'Editor' );
		await page.waitForSelector( '#minn-ue-role-ac .minn-ac-item', { timeout: 8000 } );
		await page.click( '#minn-ue-role-ac .minn-ac-item:has-text("Editor")' );
		const done = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `wp/v2/users/${ two }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
		await page.click( '[data-ue-save]' );
		await done;
		t.check( 'picking another role saves it', JSON.stringify( roles( two ) ) === '["editor"]', JSON.stringify( roles( two ) ) );
		// And changing it back on the same page saves too: the comparison is
		// against the user as last saved, not as the page first loaded them.
		await page.click( '#minn-ue-role' );
		await page.fill( '#minn-ue-role', 'Author' );
		await page.waitForSelector( '#minn-ue-role-ac .minn-ac-item', { timeout: 8000 } );
		await page.click( '#minn-ue-role-ac .minn-ac-item:has-text("Author")' );
		const back = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `wp/v2/users/${ two }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
		await page.click( '[data-ue-save]' );
		await back;
		t.check( 'changing the role back on the same page saves it', JSON.stringify( roles( two ) ) === '["author"]', JSON.stringify( roles( two ) ) );
	} finally {
		for ( const id of made ) {
			if ( id ) wpEval( `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${ id } );` );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
