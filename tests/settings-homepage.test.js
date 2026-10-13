/**
 * Settings › Homepage saves the mode you pick.
 *
 * Picking "A static page" re-renders the tab so the page pickers appear.
 * The pick used to be written into the cached settings the save compares
 * against, so the changed-only save saw nothing new and dropped
 * show_on_front: the homepage stayed on Latest posts, and switching back
 * sent no request at all while the toast said "Settings saved".
 *
 * Fixture: a published page named to sort first in the picker, deleted in
 * finally; show_on_front, page_on_front, page_for_posts and posts_per_page
 * restored.
 */
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter, pickCombo } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const php = ( v ) => "'" + String( v ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) + "'";
const opt = ( name ) => wp( 'eval', `echo (string) get_option( ${ php( name ) } );` );
const setOpt = ( name, value ) => wp( 'eval', `update_option( ${ php( name ) }, ${ php( value ) } );` );

( async () => {
	const t = reporter( 'settings-homepage' );
	const KEYS = [ 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page' ];
	const was = {};
	KEYS.forEach( ( k ) => { was[ k ] = opt( k ); } );
	let pageId = 0;
	const { browser, page, errors } = await launch();
	try {
		pageId = parseInt( wp( 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=AAA Homepage check', '--porcelain' ), 10 );
		setOpt( 'show_on_front', 'posts' );
		setOpt( 'page_on_front', '0' );
		await login( page );

		// Resolves with the body sent, or null when no request goes out.
		const save = async () => {
			const res = page.waitForResponse( ( r ) => r.request().method() === 'POST' && /\/wp\/v2\/settings$/.test( r.url() ), { timeout: 8000 } ).catch( () => null );
			await page.click( '#minn-save-settings' );
			const r = await res;
			if ( r ) await page.waitForTimeout( 300 );
			return r ? JSON.parse( r.request().postData() || '{}' ) : null;
		};

		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-section="Homepage"]', { timeout: 20000 } );
		await page.click( '[data-section="Homepage"]' );
		await page.waitForSelector( '[data-key="show_on_front"]', { timeout: 10000 } );

		await pickCombo( page, '[data-key="show_on_front"]', 'page' );
		await page.waitForSelector( '[data-key="page_on_front"]', { timeout: 10000 } );
		t.check( 'picking a static page shows the page pickers', true );
		await pickCombo( page, '[data-key="page_on_front"]', String( pageId ) );
		const sent = await save();
		t.check( 'the save sends the mode', !! sent && sent.show_on_front === 'page', JSON.stringify( sent ) );
		t.check( 'a static homepage is stored', opt( 'show_on_front' ) === 'page' && opt( 'page_on_front' ) === String( pageId ), `${ opt( 'show_on_front' ) } / ${ opt( 'page_on_front' ) }` );
		t.check( 'the tab still shows the static page', !! await page.$( '[data-key="page_on_front"]' ) );

		await pickCombo( page, '[data-key="show_on_front"]', 'posts' );
		const back = await save();
		t.check( 'switching back sends the mode', !! back && back.show_on_front === 'posts', JSON.stringify( back ) );
		t.check( 'Latest posts is stored', opt( 'show_on_front' ) === 'posts', opt( 'show_on_front' ) );

		// Untouched, the mode stays out of a save of another field.
		await page.fill( '[data-key="posts_per_page"]', String( ( parseInt( was.posts_per_page, 10 ) || 10 ) + 1 ) );
		const other = await save();
		t.check( 'an untouched mode is not sent', !! other && ! ( 'show_on_front' in other ), JSON.stringify( other ) );
	} finally {
		for ( const k of KEYS ) {
			try { setOpt( k, was[ k ] ); } catch ( e ) {}
			if ( opt( k ) !== was[ k ] ) console.log( `RESTORE FAILED ${ k }` );
		}
		if ( pageId ) {
			try { wp( 'post', 'delete', String( pageId ), '--force' ); } catch ( e ) {}
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
