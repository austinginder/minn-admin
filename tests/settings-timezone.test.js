/**
 * A manual UTC offset survives a Settings save that doesn't touch it.
 *
 * A site on a manual offset stores an empty timezone_string plus gmt_offset,
 * and wp/v2/settings carries only the timezone_string. The Site tab used to
 * seed the field with UTC and post it on every save, so editing the tagline
 * stored timezone_string UTC, which wins over gmt_offset and moved every
 * local time on the site. The field now names the offset as core does
 * (UTC+5.5) and is sent only when changed; picking a named zone still saves.
 *
 * Fixture: the dev site's timezone_string, gmt_offset and tagline, restored
 * in finally.
 */
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
// The stored value: get_option() derives gmt_offset from a named zone.
const php = ( v ) => "'" + String( v ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
const opt = ( name ) => wp( 'eval', `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", ${ php( name ) } ) );` );
// Through PHP, not wp option update: WP-CLI reads a value like -4 as a flag.
const setOpt = ( name, value ) => wp( 'eval', `update_option( ${ php( name ) }, ${ php( value ) } );` );

( async () => {
	const t = reporter( 'settings-timezone' );
	const was = { timezone_string: opt( 'timezone_string' ), gmt_offset: opt( 'gmt_offset' ), blogdescription: opt( 'blogdescription' ) };
	const { browser, page, errors } = await launch();
	try {
		setOpt( 'timezone_string', '' );
		setOpt( 'gmt_offset', '5.5' );
		await login( page );

		const save = async () => {
			const res = page.waitForResponse( ( r ) => r.request().method() === 'POST' && /\/wp\/v2\/settings$/.test( r.url() ), { timeout: 20000 } );
			await page.click( '#minn-save-settings' );
			const r = await res;
			return JSON.parse( r.request().postData() || '{}' );
		};

		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-key="timezone"]', { timeout: 20000 } );
		const shown = await page.$eval( '[data-key="timezone"]', ( el ) => el.value );
		t.check( 'a manual offset shows as core names it', shown === 'UTC+5.5', shown );

		const tagline = 'Timezone check ' + Date.now();
		await page.fill( '[data-key="description"]', tagline );
		const sent = await save();
		t.check( 'saving the tagline leaves the timezone out', ! ( 'timezone' in sent ), JSON.stringify( sent ).slice( 0, 200 ) );
		t.check( 'the tagline saved', opt( 'blogdescription' ) === tagline );
		t.check( 'the manual offset is kept', opt( 'timezone_string' ) === '' && opt( 'gmt_offset' ) === '5.5', `${ opt( 'timezone_string' ) } / ${ opt( 'gmt_offset' ) }` );

		await page.fill( '[data-key="timezone"]', 'America/Chicago' );
		const picked = await save();
		t.check( 'a zone you pick is sent', picked.timezone === 'America/Chicago', JSON.stringify( picked ).slice( 0, 200 ) );
		t.check( 'and saved', opt( 'timezone_string' ) === 'America/Chicago', opt( 'timezone_string' ) );
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
