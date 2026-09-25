/**
 * HTML email previews block remote content by default.
 *
 * Opening a logged email must not fire its tracking pixels. Proves, with a
 * real browser and a local pixel server that counts requests: the preview
 * loads nothing remote (img src AND CSS url()), a "Load images" bar offers to
 * load it and does, and WP Mail Logging's own "Always Load Remote Images"
 * setting skips the block for that provider.
 *
 * The pixel server runs in Node on 127.0.0.1, never on the site (an in-site
 * endpoint would hold a PHP worker while the page waits on it).
 *
 * Fixture: one synthetic {prefix}wpml_mails row inserted over wp-cli and
 * deleted in finally; wpml_settings is restored to what the run found.
 */
const http = require( 'http' );
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

// PHP rides stdin (eval-file -): a shell-quoted `wp eval` would expand its $vars.
const wpEval = ( php ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const hits = [];
	const server = http.createServer( ( req, res ) => {
		hits.push( req.url );
		res.writeHead( 200, { 'Content-Type': 'image/gif', 'Cache-Control': 'no-store' } );
		res.end( Buffer.from( 'R0lGODlhAQABAAAAACw=', 'base64' ) );
	} );
	await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
	const port = server.address().port;

	const { browser, page, errors } = await launch();
	const t = reporter( 'email-remote-images' );
	await login( page );

	const subject = 'Minn remote pixel ' + Date.now();
	const body = `<!DOCTYPE html><html><head><style>.hero{background:url(http://127.0.0.1:${ port }/px.gif?css)}</style></head>`
		+ `<body><div class="hero">Hello</div><img src="http://127.0.0.1:${ port }/px.gif?img" width="1" height="1" alt=""></body></html>`;
	const settingsBefore = wpEval( "echo wp_json_encode( get_option( 'wpml_settings', null ) );" );
	const mailId = parseInt( wpEval( `global $wpdb; $wpdb->insert( $wpdb->prefix . 'wpml_mails', array( 'timestamp' => current_time( 'mysql' ), 'host' => '127.0.0.1', 'receiver' => 'pixel@example.com', 'subject' => ${ JSON.stringify( subject ) }, 'message' => base64_decode( ${ JSON.stringify( Buffer.from( body ).toString( 'base64' ) ) } ), 'headers' => '', 'attachments' => '', 'error' => '', 'plugin_version' => 'test' ) ); echo $wpdb->insert_id;` ), 10 );

	const setAlwaysLoad = ( on ) => wpEval( `$s = get_option( 'wpml_settings', array() ); if ( ! is_array( $s ) ) { $s = array(); } $s['load-remote-images'] = ${ on ? "'1'" : "'0'" }; update_option( 'wpml_settings', $s ); echo 'ok';` );

	const openMail = async () => {
		await page.goto( `${ BASE }/minn-admin/wp-mail-logging`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await page.fill( '#minn-surface-search', subject );
		await page.waitForFunction( ( s ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( s ) ), subject, { timeout: 15000 } );
		await page.evaluate( ( s ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( s ) ).click(), subject );
		await page.waitForSelector( '.minn-modal iframe.minn-email-frame', { timeout: 15000 } );
		await page.waitForTimeout( 1500 );
	};

	try {
		t.check( 'fixture email logged', mailId > 0, String( mailId ) );
		setAlwaysLoad( false );

		await openMail();
		const blocked = await page.evaluate( () => ( {
			bar: !! document.querySelector( '.minn-modal [data-remote-bar]' ),
			csp: /img-src data: cid: blob:;/.test( document.querySelector( '.minn-modal iframe.minn-email-frame' ).getAttribute( 'srcdoc' ) || '' ),
			doctypeFirst: /^<!DOCTYPE html><meta /i.test( document.querySelector( '.minn-modal iframe.minn-email-frame' ).getAttribute( 'srcdoc' ) || '' ),
		} ) );
		t.check( 'opening the email loads nothing remote (img and CSS url)', hits.length === 0, JSON.stringify( hits ) );
		t.check( 'the preview carries the blocking policy after the doctype', blocked.csp && blocked.doctypeFirst, JSON.stringify( blocked ) );
		t.check( 'a Load images bar is offered', blocked.bar );

		await page.click( '.minn-modal [data-load-remote]' );
		for ( let i = 0; i < 20 && hits.length < 2; i++ ) await page.waitForTimeout( 250 );
		t.check( 'Load images fetches the remote image and CSS background', hits.some( ( h ) => /img/.test( h ) ) && hits.some( ( h ) => /css/.test( h ) ), JSON.stringify( hits ) );
		t.check( 'the bar goes away once loaded', ! ( await page.$( '.minn-modal [data-remote-bar]' ) ) );

		hits.length = 0;
		setAlwaysLoad( true );
		await openMail();
		for ( let i = 0; i < 20 && hits.length < 1; i++ ) await page.waitForTimeout( 250 );
		t.check( 'WP Mail Logging "Always Load Remote Images" is honored', hits.some( ( h ) => /img/.test( h ) ) && ! ( await page.$( '.minn-modal [data-remote-bar]' ) ), JSON.stringify( hits ) );
	} finally {
		if ( mailId > 0 ) wpEval( `global $wpdb; $wpdb->delete( $wpdb->prefix . 'wpml_mails', array( 'mail_id' => ${ mailId } ) ); echo 'ok';` );
		if ( 'null' === settingsBefore ) {
			wpEval( "delete_option( 'wpml_settings' ); echo 'ok';" );
		} else {
			wpEval( `update_option( 'wpml_settings', json_decode( base64_decode( ${ JSON.stringify( Buffer.from( settingsBefore ).toString( 'base64' ) ) } ), true ) ); echo 'ok';` );
		}
		server.close();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
