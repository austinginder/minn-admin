/**
 * Zero external requests — pins the marketing claim as an enforced
 * invariant: a full app session (boot, every chrome route, notifications,
 * palette, a real editor session) makes no browser requests off the origin.
 *
 * Deliberate allowances (Austin, 2026-07-23), each a named tier so a new
 * external host fails loudly instead of joining silently:
 * - *.gravatar.com — core's avatar URLs; kept as-is by decision.
 * - *.w.org / *.wp.org / *.wordpress.org — plugin icons on Extensions ride
 *   the directory's own CDN (the same host updates already talk to).
 * Site-stack-driven requests are out of scope, attributed precisely:
 * - The wp-login page (counting starts AFTER login) — other plugins style it.
 * - The editor's WYSIWYG preview pipeline inlines the SITE's real editor
 *   styles; if the installed theme/plugins reference external fonts, those
 *   load in previews. The editor check allows exactly the hosts the site's
 *   own minn-admin/v1/editor-styles payload names (plus fonts.gstatic.com
 *   when fonts.googleapis.com CSS is among them), so Minn-originated
 *   externals still fail.
 * - Content embeds: the fixture post is plain paragraphs, so none here.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const ALLOWED = [
	new URL( BASE ).host,
	/(^|\.)gravatar\.com$/,
	/(^|\.)w\.org$/,
	/(^|\.)wp\.org$/,
	/(^|\.)wordpress\.org$/,
];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'zero-external' );

	const external = [];
	let siteStyleHosts = new Set();
	let counting = false;
	page.on( 'request', ( r ) => {
		if ( ! counting ) return;
		const u = r.url();
		if ( ! /^https?:/.test( u ) ) return; // data:, blob:, about:
		const host = new URL( u ).host;
		const ok = ALLOWED.some( ( a ) => ( a instanceof RegExp ? a.test( host ) : a === host ) )
			|| siteStyleHosts.has( host );
		if ( ! ok ) external.push( u );
	} );

	await login( page );
	counting = true;
	let post = null;
	try {
		post = await createPost( page, {
			title: 'Zero external probe',
			content: '<!-- wp:paragraph --><p>Plain fixture content, no embeds.</p><!-- /wp:paragraph -->',
		} );

		for ( const route of [ 'overview', 'content', 'media', 'users', 'extensions', 'settings', 'system', 'terms' ] ) {
			await page.goto( BASE + '/minn-admin/' + route, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
			await page.waitForTimeout( 2200 );
			t.check( `No external requests through ${ route }`, external.length === 0, external.slice( 0, 3 ).join( ' ' ) );
		}

		// Notifications panel (notices digest capture included).
		await page.click( '#minn-notif-btn' );
		await page.waitForTimeout( 1500 );
		t.check( 'No external requests from notifications', external.length === 0, external.slice( 0, 3 ).join( ' ' ) );
		await page.keyboard.press( 'Escape' );

		// Palette open + a query (content search hits the REST API).
		await page.keyboard.press( 'Meta+k' );
		await page.keyboard.type( 'hello', { delay: 30 } );
		await page.waitForTimeout( 1200 );
		await page.keyboard.press( 'Escape' );
		t.check( 'No external requests from the palette', external.length === 0, external.slice( 0, 3 ).join( ' ' ) );

		// A real editor session: open, type, save with ⌘S. Preview fidelity
		// means the SITE's registered editor styles (and fonts they name)
		// may load; collect those hosts first so only Minn-originated
		// externals can fail the check.
		siteStyleHosts = new Set( await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-styles', {
				credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const d = r.ok ? await r.json() : {};
			const hosts = [];
			( d.urls || [] ).forEach( ( u ) => {
				try { hosts.push( new URL( u, location.origin ).host ); } catch ( e ) {}
			} );
			return hosts;
		} ) );
		siteStyleHosts.delete( new URL( BASE ).host );
		if ( siteStyleHosts.has( 'fonts.googleapis.com' ) ) siteStyleHosts.add( 'fonts.gstatic.com' );
		await openEditor( page, post.id );
		await page.click( '#minn-editor-body' );
		await page.keyboard.type( ' More words.', { delay: 20 } );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2500 );
		t.check( 'No external requests from an editor session', external.length === 0, external.slice( 0, 5 ).join( ' ' ) );
	} finally {
		if ( post ) await deletePost( page, post.id ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
