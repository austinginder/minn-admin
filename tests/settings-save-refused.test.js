/**
 * A refused Settings save keeps what you typed.
 *
 * After any save the tab re-rendered from the cached settings, so when the
 * server refused the save (an address that isn't an email, say) every edit
 * on the tab went back to the stored value along with the error toast.
 * A refused save now leaves the form as typed, ready to correct and retry.
 *
 * Fixture: the dev site's tagline and admin email, restored in finally.
 */
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const php = ( v ) => "'" + String( v ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
const opt = ( name ) => wp( 'eval', `echo (string) get_option( ${ php( name ) } );` );
const setOpt = ( name, value ) => wp( 'eval', `update_option( ${ php( name ) }, ${ php( value ) } );` );

( async () => {
	const t = reporter( 'settings-save-refused' );
	const was = { blogdescription: opt( 'blogdescription' ), admin_email: opt( 'admin_email' ) };
	const { browser, page, errors } = await launch();
	try {
		await login( page );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-key="description"]', { timeout: 20000 } );

		const tagline = 'Refused save check ' + Date.now();
		await page.fill( '[data-key="description"]', tagline );
		await page.fill( '[data-key="email"]', 'not-an-address' );
		const res = page.waitForResponse( ( r ) => r.request().method() === 'POST' && /\/wp\/v2\/settings$/.test( r.url() ), { timeout: 20000 } );
		await page.click( '#minn-save-settings' );
		const r = await res;
		t.check( 'the server refuses the save', r.status() >= 400, String( r.status() ) );
		await page.waitForFunction( () => ! document.querySelector( '#minn-save-settings' ).disabled, null, { timeout: 10000 } );
		await page.waitForTimeout( 300 );
		const kept = await page.evaluate( () => ( {
			tagline: document.querySelector( '[data-key="description"]' ).value,
			email: document.querySelector( '[data-key="email"]' ).value,
		} ) );
		t.check( 'the typed tagline is still in the form', kept.tagline === tagline, kept.tagline );
		t.check( 'and so is the address to correct', kept.email === 'not-an-address', kept.email );
		t.check( 'nothing was stored', opt( 'blogdescription' ) === was.blogdescription, opt( 'blogdescription' ) );

		await page.fill( '[data-key="email"]', was.admin_email );
		const res2 = page.waitForResponse( ( r2 ) => r2.request().method() === 'POST' && /\/wp\/v2\/settings$/.test( r2.url() ), { timeout: 20000 } );
		await page.click( '#minn-save-settings' );
		const r2 = await res2;
		t.check( 'the corrected retry saves', r2.status() === 200 && opt( 'blogdescription' ) === tagline, String( r2.status() ) );
	} finally {
		for ( const [ k, v ] of Object.entries( was ) ) {
			try { setOpt( k, v ); } catch ( e ) {}
			if ( opt( k ) !== v ) console.log( `RESTORE FAILED ${ k }` );
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
