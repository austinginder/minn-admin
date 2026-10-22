/**
 * v0.47.0 RC audit, in the browser.
 *
 * - WPForms Emails (06-H1): a notification or confirmation key went into the
 *   page's markup unescaped, and WPForms builds its form from posted input
 *   names, so anyone who can edit a form could store a key that ran script
 *   when an administrator opened its Emails page. Two halves, each checked
 *   on its own: the server serves only integer keys (a crafted key in a real
 *   form), and the page escapes every key it puts in an attribute (a crafted
 *   key served through an intercepted response, so the escaping is proved
 *   without the server's filter).
 *
 * Fixtures: none standing. A disposable WPForms form is made through
 * WPForms' own objects and deleted in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-rcb-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php wp_set_current_user( 1 ); ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8' } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

// Sets window.__minnXss when it runs, so a check never depends on a dialog.
const PAYLOAD = '1"><img src=x onerror="window.__minnXss=1">';

( async () => {
	const t = reporter( 'security-v047-rc-browser' );
	const { browser, page, errors } = await launch();
	await login( page );

	let form = 0;
	try {
		if ( 'yes' !== wpEval( "echo function_exists( 'wpforms' ) ? 'yes' : 'no';" ) ) {
			console.log( 'SKIP  WPForms Emails: WPForms inactive' );
		} else {
			form = parseInt( wpEval( `
				$id = wpforms()->obj( 'form' )->add( 'Minn RC key suite', array(), array( 'template' => 'simple-contact-form-template' ) );
				$d  = wpforms()->obj( 'form' )->get( $id, array( 'content_only' => true ) );
				$d['settings']['notifications'][ ${ JSON.stringify( PAYLOAD ) } ] = $d['settings']['notifications'][1];
				$d['settings']['confirmations'][ ${ JSON.stringify( PAYLOAD ) } ] = $d['settings']['confirmations'][1];
				wpforms()->obj( 'form' )->update( $id, $d );
				echo $id;
			` ), 10 ) || 0;
			t.check( 'a form holding a crafted notification and confirmation key', form > 0 );

			// Server half: the crafted key is not served.
			const served = await page.evaluate( async ( id ) => {
				const r = await fetch( window.MINN.restUrl + `minn-admin/v1/wpforms/forms/${ id }/emails`, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
				return r.json();
			}, form );
			const keys = [ ...( served.notifications || [] ), ...( served.confirmations || [] ) ].map( ( x ) => x.key );
			t.check( 'the server serves integer keys only', keys.length > 0 && keys.every( ( k ) => /^\d+$/.test( k ) ), JSON.stringify( keys ) );

			const open = async () => {
				await page.goto( `${ BASE }/minn-admin/wpforms/form/${ form }`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '.minn-wpfe [data-gfn="n1.subject"]', { timeout: 60000 } );
				await page.waitForTimeout( 1500 );
				return page.evaluate( () => ( { ran: !! window.__minnXss, img: !! document.querySelector( '.minn-wpfe img[src="x"]' ) } ) );
			};
			const real = await open();
			t.check( 'the Emails page for that form runs no script', ! real.ran && ! real.img, JSON.stringify( real ) );

			// Client half: serve the crafted key anyway; the page escapes it.
			await page.route( /\/minn-admin\/v1\/wpforms\/forms\/\d+\/emails/, async ( route ) => {
				if ( 'GET' !== route.request().method() ) {
					return route.continue();
				}
				const res = await route.fetch();
				const body = await res.json();
				if ( body.notifications && body.notifications[ 0 ] ) {
					body.notifications.push( { ...body.notifications[ 0 ], key: PAYLOAD } );
				}
				if ( body.confirmations && body.confirmations[ 0 ] ) {
					body.confirmations.push( { ...body.confirmations[ 0 ], key: PAYLOAD } );
				}
				return route.fulfill( { response: res, json: body } );
			} );
			const forced = await open();
			t.check( 'a crafted key served to the page is escaped, not run', ! forced.ran && ! forced.img, JSON.stringify( forced ) );
			await page.unroute( /\/minn-admin\/v1\/wpforms\/forms\/\d+\/emails/ );
		}
	} finally {
		if ( form ) {
			wpEval( `wp_delete_post( ${ form }, true );` );
		}
	}
	await t.done( browser, errors );
} )();
