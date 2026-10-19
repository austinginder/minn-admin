/**
 * v0.46.0 registry findings, the client half (WP Registry audit #2209):
 *
 *   09-1  Picking the Custom palette sends the scheme alone, on Your profile
 *         and on another user's page, so a color changed meanwhile in
 *         another session (or by an administrator) stays. Leaving the site
 *         default still seeds the palette with the colors on screen.
 *   16-1  An image URL is HTML-escaped on top of its CSS escaping wherever it
 *         lands in a style attribute: a stored URL holding the text &#39;
 *         or &#41; (or a literal " and >) cannot close url('…') and add
 *         declarations to the image control, the featured thumbnail, the
 *         media picker and library tiles or the sidebar's site icon. The one
 *         DOM assignment keeps the plain CSS form, so nothing is escaped
 *         twice.
 *
 * Every check but the guards marked as such fails on the v0.46.0 app.js.
 * Everything the suite sets up is put back by onExit(), however the run ends.
 *
 * Run: cd tests && MINN_TEST_PASS=… node security-v047-registry-client.test.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { launch, login, createPost, openEditor, reporter, onExit, BASE, WP } = require( './helpers' );

const wp = ( args, opts = {} ) => {
	try {
		return execFileSync( 'wp', [ ...args, '--path=' + WP, '--skip-themes', ...( opts.plugins ? [] : [ '--skip-plugins' ] ) ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	} catch ( e ) {
		return ( e && e.stdout ) || '';
	}
};
const lastLine = ( out ) => String( out || '' ).trim().split( '\n' ).pop().trim();
const jsonLine = ( out ) => {
	const line = String( out || '' ).split( '\n' ).find( ( l ) => /^[{[]/.test( l.trim() ) );
	try { return line ? JSON.parse( line ) : null; } catch ( e ) { return null; }
};
const metaGet = ( uid ) => jsonLine( wp( [ 'user', 'meta', 'get', String( uid ), 'minn_admin_appearance', '--format=json' ] ) );
const metaSet = ( uid, val ) => {
	if ( val && 'object' === typeof val ) wp( [ 'user', 'meta', 'update', String( uid ), 'minn_admin_appearance', JSON.stringify( val ), '--format=json' ] );
	else wp( [ 'user', 'meta', 'delete', String( uid ), 'minn_admin_appearance' ] );
};

const ADMIN_ID = 1;
const EDITOR_ID = Number( lastLine( wp( [ 'user', 'get', 'minn-editor', '--field=ID' ] ) ) ) || 4;

// A stored image URL shaped to close url('…') once an attribute decodes it:
// &#39;&#41; becomes ') and the declarations after it apply, unless the
// value was HTML-escaped. The literal " and > are encodeURI's to catch. The
// &q=1 shows a query string's & reaches the URL as itself.
const PROBE_FILE = "minn-v047-probe/x.png?a=&#39;&#41;;position:fixed;inset:0;z-index:2147483646;background-color:red;x:&#40;&#39;&q=1\"><i id=minn47css>";
const PROBE_TITLE = 'Minn v047 css probe';

( async () => {
	const t = reporter( 'security-v047-registry-client' );

	// Restores, armed before anything changes.
	const adminWas = metaGet( ADMIN_ID );
	const editorWas = metaGet( EDITOR_ID );
	const made = [];
	const restore = onExit( () => {
		metaSet( ADMIN_ID, adminWas );
		metaSet( EDITOR_ID, editorWas );
		if ( made.length ) wp( [ 'post', 'delete', ...made.map( String ), '--force' ] );
	} );

	const { browser, page, errors } = await launch();
	await login( page );

	const swatchPick = async ( sel, routeRe ) => {
		const res = page.waitForResponse( ( r ) => r.request().method() === 'POST' && routeRe.test( r.url() ), { timeout: 20000 } ).catch( () => null );
		await page.click( sel );
		const r = await res;
		return { r, body: r ? JSON.parse( r.request().postData() || '{}' ) : null };
	};
	const palette = ( dark, light ) => ( { dark: { accent: dark }, light: { accent: light } } );

	try {
		/* ===== 09-1 Your profile ===== */
		const mine = metaGet( ADMIN_ID ) || {};
		metaSet( ADMIN_ID, { ...mine, scheme: 'ocean', custom: palette( '#111111', '#121212' ) } );
		await page.goto( `${ BASE }/minn-admin/profile`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-scheme-swatch[data-scheme="custom"]', { timeout: 15000 } );
		// Another session changes the palette after this page loaded.
		metaSet( ADMIN_ID, { ...( metaGet( ADMIN_ID ) || {} ), custom: palette( '#222222', '#232323' ) } );
		const me = await swatchPick( '.minn-scheme-swatch[data-scheme="custom"]', /minn-admin\/v1\/me\/appearance/ );
		t.check( '09-1 a profile Custom pick sends the scheme alone', !! me.body && JSON.stringify( me.body ) === '{"scheme":"custom"}', JSON.stringify( me.body ) );
		let after = metaGet( ADMIN_ID ) || {};
		t.check( '09-1 the other session\'s colors stay', !! me.r && after.scheme === 'custom' && after.custom && after.custom.dark.accent === '#222222' && after.custom.light.accent === '#232323', JSON.stringify( after.custom && [ after.custom.dark.accent, after.custom.light.accent ] ) );
		const mode = await page.evaluate( () => document.documentElement.getAttribute( 'data-theme' ) || 'dark' );
		const shownAccent = await page.waitForFunction( ( want ) => {
			const el = document.querySelector( '[data-scheme-slot="accent"]' );
			return el && el.value.toLowerCase() === want ? el.value : false;
		}, 'light' === mode ? '#232323' : '#222222', { timeout: 5000 } ).then( ( h ) => h.jsonValue() ).catch( () => null );
		t.check( '09-1 the slot editors show the stored colors, not the page\'s copy', !! shownAccent, String( shownAccent ) );

		// Guard (passes before and after): leaving the site default seeds the
		// palette with the colors on screen.
		metaSet( ADMIN_ID, { ...( metaGet( ADMIN_ID ) || {} ), scheme: 'site' } );
		await page.goto( `${ BASE }/minn-admin/profile`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-scheme-swatch[data-scheme="custom"]', { timeout: 15000 } );
		const seed = await swatchPick( '.minn-scheme-swatch[data-scheme="custom"]', /minn-admin\/v1\/me\/appearance/ );
		t.check( '09-1 guard: site default to Custom still seeds the palette', !! seed.body && seed.body.scheme === 'custom' && !! seed.body.custom && !! seed.body.custom.dark, JSON.stringify( seed.body && Object.keys( seed.body ) ) );

		/* ===== 09-1 another user's page ===== */
		const theirs = metaGet( EDITOR_ID ) || {};
		metaSet( EDITOR_ID, { ...theirs, scheme: 'forest', custom: palette( '#333333', '#343434' ) } );
		await page.goto( `${ BASE }/minn-admin/users/${ EDITOR_ID }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ue-appearance .minn-scheme-swatch[data-scheme="custom"]', { timeout: 15000 } );
		await page.waitForTimeout( 800 );
		// The person changes their own palette after the administrator's page loaded.
		metaSet( EDITOR_ID, { ...( metaGet( EDITOR_ID ) || {} ), custom: palette( '#444444', '#454545' ) } );
		const ue = await swatchPick( '#minn-ue-appearance .minn-scheme-swatch[data-scheme="custom"]', new RegExp( 'users/' + EDITOR_ID + '/appearance' ) );
		t.check( '09-1 a Custom pick on the user page sends the scheme alone', !! ue.body && JSON.stringify( ue.body ) === '{"scheme":"custom"}', JSON.stringify( ue.body ) );
		after = metaGet( EDITOR_ID ) || {};
		t.check( '09-1 the user\'s own colors stay', !! ue.r && after.scheme === 'custom' && after.custom && after.custom.dark.accent === '#444444' && after.custom.light.accent === '#454545', JSON.stringify( after.custom && [ after.custom.dark.accent, after.custom.light.accent ] ) );
		const ueShown = await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-ue-appearance [data-scheme-slot="accent"][data-mode="dark"]' );
			return el && el.value.toLowerCase() === '#444444';
		}, null, { timeout: 5000 } ).then( () => true ).catch( () => false );
		t.check( '09-1 the user page\'s slot editors show the stored colors', ueShown );

		/* ===== 16-1 style attributes ===== */
		const att = Number( lastLine( wp( [ 'eval', `$id = wp_insert_attachment( array( 'post_title' => '${ PROBE_TITLE }', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_content' => '' ) ); update_post_meta( $id, '_wp_attached_file', ${ JSON.stringify( PROBE_FILE ) } ); echo (int) $id;` ] ) ) );
		if ( att ) made.push( att );
		const pid = await createPost( page, { title: 'Style attribute probe', content: '<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->' } );
		if ( pid ) made.push( pid );
		const served = await page.evaluate( async ( id ) => {
			const r = await fetch( `${ window.MINN.restUrl }wp/v2/media/${ id }?_fields=source_url`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).source_url || '';
		}, att );
		t.check( '16-1 fixture: the attachment URL is served as stored', !! att && !! pid && served.endsWith( PROBE_FILE ), served );
		// The social image a contributor can store through the SEO plugin's own
		// route, read back by Minn's SEO panel as stored.
		wp( [ 'eval', `update_post_meta( ${ pid }, '_thumbnail_id', ${ att } ); update_post_meta( ${ pid }, '_yoast_wpseo_opengraph-image-id', '${ att }' ); update_post_meta( ${ pid }, '_yoast_wpseo_opengraph-image', 'https://example.com/' . ${ JSON.stringify( PROBE_FILE ) } );` ] );

		const probe = ( sel ) => page.evaluate( ( s ) => {
			const el = document.querySelector( s );
			if ( ! el ) return null;
			return {
				inlinePos: el.style.position,
				inlineBg: el.style.backgroundColor,
				pos: getComputedStyle( el ).position,
				bg: el.style.backgroundImage,
				attrs: el.getAttributeNames().filter( ( n ) => ! /^(class|style|id|title|type|data-[a-z-]+|aria-label)$/.test( n ) ),
				injected: !! document.getElementById( 'minn47css' ),
			};
		}, sel );
		const held = ( p ) => !! p && p.inlinePos === '' && p.inlineBg === '' && p.pos !== 'fixed' && ! p.attrs.length && ! p.injected
			&& p.bg.indexOf( '&#39;&#41;' ) !== -1 && p.bg.indexOf( '&q=1' ) !== -1 && p.bg.indexOf( '&amp;' ) === -1;
		const settle = ( sel ) => page.waitForFunction( ( s ) => {
			const el = document.querySelector( s );
			return el && /minn-v047-probe/.test( el.getAttribute( 'style' ) || '' );
		}, sel, { timeout: 15000 } ).catch( () => null );

		// Clicks below go through the DOM: on an unescaped build the probe's
		// fixed overlay covers the page and takes every real click.
		const domClick = ( sel ) => page.$eval( sel, ( el ) => el.click() );
		await openEditor( page, pid );
		await settle( '#minn-featured-preview' );
		let p = await probe( '#minn-featured-preview' );
		t.check( '16-1 the featured thumbnail keeps the URL inside url()', held( p ), JSON.stringify( p ) );

		const ogOn = 'yes' === lastLine( wp( [ 'eval', "echo class_exists( 'WPSEO_Options' ) && true === WPSEO_Options::get( 'opengraph' ) ? 'yes' : 'no';" ], { plugins: true } ) );
		if ( ogOn ) {
			await page.waitForSelector( '[data-side-door="panel:seo"]', { timeout: 15000 } );
			await domClick( '[data-side-door="panel:seo"]' );
			const imgSel = '[data-pf="seo:social_image"] .minn-field-image-thumb';
			await settle( imgSel );
			p = await probe( imgSel );
			t.check( '16-1 the image control keeps a stored SEO image URL inside url()', held( p ), JSON.stringify( p ) );
			await page.keyboard.press( 'Escape' );
			await page.waitForTimeout( 400 );
		} else {
			t.check( '16-1 SKIP image control: Yoast\'s Facebook card is off on this site', true );
		}

		await domClick( '#minn-featured-set' );
		const tileSel = `.minn-picker-item[title="${ PROBE_TITLE }"]`;
		await settle( tileSel );
		p = await probe( tileSel );
		t.check( '16-1 the media picker tile keeps the URL inside url()', held( p ), JSON.stringify( p ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );

		await page.goto( `${ BASE }/minn-admin/media`, { waitUntil: 'domcontentloaded' } );
		const libSel = `[data-media="${ att }"] .minn-media-thumb, [data-media="${ att }"] .minn-media-thumb-sm`;
		await settle( libSel );
		p = await probe( libSel );
		t.check( '16-1 the media library tile keeps the URL inside url()', held( p ), JSON.stringify( p ) );

		// The site icon comes from the boot payload: a second page whose
		// payload carries the probe URL as the icon.
		const p2 = await page.context().newPage();
		const p2Errors = [];
		p2.on( 'pageerror', ( e ) => p2Errors.push( e.message ) );
		await p2.addInitScript( ( icon ) => {
			Object.defineProperty( window, 'MINN', {
				configurable: true,
				set( v ) {
					if ( v && v.site ) v.site.icon = icon;
					Object.defineProperty( window, 'MINN', { value: v, writable: true, configurable: true } );
				},
			} );
		}, 'https://example.com/' + PROBE_FILE );
		await p2.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await p2.waitForSelector( '.minn-logo-mark-icon', { timeout: 15000 } ).catch( () => null );
		const logo = await p2.evaluate( () => {
			const el = document.querySelector( '.minn-logo-mark-icon' );
			return el ? { pos: getComputedStyle( el ).position, inlinePos: el.style.position, bg: el.style.backgroundImage, injected: !! document.getElementById( 'minn47css' ) } : null;
		} );
		t.check( '16-1 the sidebar site icon keeps the URL inside url()', !! logo && logo.inlinePos === '' && logo.pos !== 'fixed' && ! logo.injected && logo.bg.indexOf( '&#39;&#41;' ) !== -1 && logo.bg.indexOf( '&amp;' ) === -1 && ! p2Errors.length, JSON.stringify( logo ) + ' ' + p2Errors.join( ' | ' ) );
		await p2.close();

		// Every other place the helper feeds: a style attribute in markup takes
		// the HTML-escaped form, a DOM style assignment the plain CSS form.
		const src = fs.readFileSync( path.join( __dirname, '../assets/js/app.js' ), 'utf8' ).split( '\n' );
		const domEsc = src.filter( ( l ) => /\.style\.[A-Za-z]+\s*=.*escCssUrl\(/.test( l ) );
		const attrRaw = src.filter( ( l ) => /url\('\$\{\s*cssUrl\(/.test( l ) && ! /\.style\.[A-Za-z]+\s*=/.test( l ) );
		const bare = src.filter( ( l ) => /url\('\$\{(?!\s*(?:escCssUrl|cssUrl)\()/.test( l ) );
		t.check( '16-1 no DOM style assignment takes the HTML-escaped form (it would escape twice)', ! domEsc.length, domEsc.join( ' | ' ).slice( 0, 300 ) );
		t.check( '16-1 no style attribute takes the plain CSS form', ! attrRaw.length, attrRaw.join( ' | ' ).slice( 0, 300 ) );
		t.check( '16-1 no url(\'…\') in the app takes a URL through neither helper', ! bare.length, bare.join( ' | ' ).slice( 0, 300 ) );
	} finally {
		restore();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.log( 'FAIL  suite crashed: ' + ( e && e.stack ? e.stack : e ) );
	process.exit( 1 );
} );
