/**
 * A logged email's preview never reaches the sender when it opens. The
 * sandboxed frame's policy blocks remote content, but not the document
 * navigating itself (a meta refresh, which a sandbox without scripts still
 * follows) or the browser's own lookups (dns-prefetch, preconnect), so a
 * message could tell its sender it was read. Those are taken out of the
 * preview; the message still renders.
 *
 * Seeds one FluentSMTP log row through wp_mail (FluentSMTP is the lab's mail
 * resident) and deletes it after.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const TAG = 'minnselfnav' + Date.now().toString( 36 );
const SUBJECT = 'Minn preview probe ' + TAG;
const BEACON = `${ BASE }/minn-preview-beacon-${ TAG }`;
const BODY = `<!doctype html><html><head><meta http-equiv="refresh" content="0;url=${ BEACON }"><link rel="dns-prefetch" href="//${ TAG }.example"><base href="${ BEACON }/"></head><body><p>Preview body ${ TAG }</p><a href="relative">link</a></body></html>`;

const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), TAG + '.php' );
	fs.writeFileSync( file, '<?php\n' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'preview-self-nav' );
	const seeded = wpEval( `wp_mail( "preview-probe@example.com", ${ JSON.stringify( SUBJECT ) }, ${ JSON.stringify( BODY ) }, array( "Content-Type: text/html; charset=UTF-8" ) ); global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fsmpt_email_logs WHERE subject = %s", ${ JSON.stringify( SUBJECT ) } ) );` );
	t.check( 'the probe email is in the log (premise)', '1' === seeded, seeded );

	const hits = [];
	page.on( 'request', ( r ) => {
		if ( r.url().includes( 'minn-preview-beacon-' + TAG ) || r.url().includes( TAG + '.example' ) ) hits.push( r.url() );
	} );
	try {
		await login( page );
		await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'fluent-smtp' ) );
		await page.goto( BASE + '/minn-admin/fluent-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-search', { timeout: 30000 } );
		await page.fill( '#minn-surface-search', TAG );
		await page.waitForFunction( ( tag ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( tag ) ), TAG, { timeout: 30000 } );
		await page.click( '.minn-table-row .minn-row-title' );
		await page.waitForSelector( '.minn-modal iframe', { timeout: 30000 } );
		await page.waitForTimeout( 3000 );
		const doc = await page.$eval( '.minn-modal iframe', ( f ) => f.getAttribute( 'srcdoc' ) || '' );
		t.check( 'the message still renders in the preview', doc.includes( 'Preview body ' + TAG ), doc.slice( 0, 160 ) );
		t.check( 'its refresh, prefetch and base are taken out', ! /http-equiv="refresh"/i.test( doc ) && ! /dns-prefetch/i.test( doc ) && ! /<base\b/i.test( doc ), doc.slice( 0, 300 ) );
		t.check( 'opening the preview sends nothing to the sender', 0 === hits.length, JSON.stringify( hits ) );
	} finally {
		wpEval( `global $wpdb; $wpdb->delete( $wpdb->prefix . "fsmpt_email_logs", array( "subject" => ${ JSON.stringify( SUBJECT ) } ) );` );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
