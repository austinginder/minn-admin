/**
 * Runtime-CSS harvest: some blocks load the stylesheet their layout is built
 * on from their own front-end script (Gutenslider pulls gs-base.css as a
 * webpack async chunk), so it never passes through wp_styles() and no
 * server-side collector can report it. Previews never run third-party JS, so
 * the block's pieces stack in normal flow and its content is clipped out of a
 * fixed-height frame. The harvest loads the post's front end in the hidden
 * same-origin iframe, takes the <link> sheets the page pulled in, and sends
 * the new ones through the usual scoper.
 *
 * Fixture: minn-test/runtime-css in the dev-fixtures mu-plugin. Its BASE sheet
 * is registered normally (so the editor-styles sweep collects the 200px
 * clipping frame); the sheet that makes the caption overlay that frame is
 * appended by a front-end script at runtime, exactly like the real case.
 * A control island proves an ordinary preview triggers nothing.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const RT = '<!-- wp:minn-test/runtime-css /-->';
const CONTROL = '<!-- wp:acme/plain -->\n<div class="acme-plain"><p>Always visible</p></div>\n<!-- /wp:acme/plain -->';
const CONTENT = RT + '\n\n' + CONTROL + '\n\n<!-- wp:paragraph -->\n<p>Tail paragraph.</p>\n<!-- /wp:paragraph -->';

const OVER_URL = BASE + '/?minn_test_css=over';

( async () => {
	const t = reporter( 'runtime-css' );
	const { browser, page, errors } = await launch();

	// The harvest deliberately runs a REAL front-end page in a hidden iframe,
	// so that page's own scripts report into this context (minnadmin's
	// Elementor Pro throws on every front-end load). Those belong to the
	// harvested page, not to Minn — drop errors whose stack sits in
	// third-party plugin or theme code, and keep everything else.
	const foreign = new Set();
	page.on( 'pageerror', ( e ) => {
		const stack = e.stack || '';
		if ( /\/wp-content\/(plugins|themes)\//.test( stack ) && stack.indexOf( '/plugins/minn-admin/' ) === -1 ) {
			foreign.add( 'pageerror: ' + e.message );
		}
	} );

	// Delay the runtime sheet so the pre-harvest state is observable rather
	// than a race (the <link> is appended immediately; only its BYTES wait).
	await page.route( '**/*minn_test_css=over*', async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, 4000 ) );
		await route.continue();
	} );

	await login( page );

	let id = 0;
	try {
		// Published: the harvest loads the post's own permalink.
		id = await createPost( page, { title: 'Runtime CSS probe', content: CONTENT, status: 'publish' } );
		t.check( 'fixture post created', id > 0, String( id ) );

		// The runtime sheet must be invisible to the server collectors, or
		// there is nothing for the harvest to prove.
		const server = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-styles', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return { urls: j.urls || [], inline: ( j.inline || '' ).length };
		} );
		const hasBase = server.urls.some( ( u ) => u.indexOf( 'minn_test_css=base' ) !== -1 );
		const hasOver = server.urls.some( ( u ) => u.indexOf( 'minn_test_css=over' ) !== -1 );
		t.check( 'server reports the base sheet', hasBase );
		t.check( 'server cannot see the runtime sheet', ! hasOver );

		await openEditor( page, id );
		await page.waitForSelector( '.minn-island-preview .minn-test-rt-frame', { timeout: 20000 } );

		// The base sheet alone is the broken state the harvest exists to fix:
		// a 200px frame with the caption flowing past its bottom edge.
		const before = await page.waitForFunction( () => {
			const f = document.querySelector( '.minn-island-preview .minn-test-rt-frame' );
			const c = document.querySelector( '.minn-island-preview .minn-test-rt-cap' );
			if ( ! f || ! c ) return null;
			const fb = f.getBoundingClientRect(), cb = c.getBoundingClientRect();
			if ( Math.round( fb.height ) !== 200 ) return null;   // base sheet not in yet
			return { capTop: Math.round( cb.top - fb.top ), display: getComputedStyle( f ).display };
		}, null, { timeout: 30000 } ).then( ( h ) => h.jsonValue() ).catch( () => null );
		t.check( 'base sheet gives the frame its 200px clip', !! before, JSON.stringify( before ) );
		t.check( 'caption starts clipped out of the frame', before && before.capTop >= 200, JSON.stringify( before ) );
		t.check( 'overlay not applied before the harvest', before && before.display === 'block', JSON.stringify( before ) );

		// Then the harvest lands and the caption moves inside it.
		const fixed = await page.waitForFunction( () => {
			const f = document.querySelector( '.minn-island-preview .minn-test-rt-frame' );
			const c = document.querySelector( '.minn-island-preview .minn-test-rt-cap' );
			if ( ! f || ! c ) return false;
			const fb = f.getBoundingClientRect(), cb = c.getBoundingClientRect();
			return getComputedStyle( f ).display === 'grid' && cb.height > 0 && cb.top < fb.bottom - 2;
		}, null, { timeout: 60000 } ).then( () => true ).catch( () => false );
		t.check( 'caption pulled inside the clipping frame', fixed );

		const geo = await page.evaluate( () => {
			const f = document.querySelector( '.minn-island-preview .minn-test-rt-frame' );
			const c = document.querySelector( '.minn-island-preview .minn-test-rt-cap' );
			const fb = f.getBoundingClientRect(), cb = c.getBoundingClientRect();
			return {
				frameH: Math.round( fb.height ),
				capOffset: Math.round( cb.top - fb.top ),
				display: getComputedStyle( f ).display,
				gridArea: getComputedStyle( c ).gridArea,
			};
		} );
		t.check( 'frame still clips at its base height', geo.frameH === 200, JSON.stringify( geo ) );
		t.check( 'runtime rule applied (grid overlay)', geo.display === 'grid' && geo.gridArea === '1 / 1', JSON.stringify( geo ) );

		// The harvested sheet must arrive scoped, through the same pipeline as
		// every other preview stylesheet — never as a bare global rule.
		const scoping = await page.evaluate( () => {
			const tags = [ ...document.querySelectorAll( 'style.minn-preview-css, style#minn-frontend-css' ) ];
			const all = tags.map( ( s ) => s.textContent || '' ).join( '\n' );
			const i = all.indexOf( '.minn-test-rt-bg' );
			return {
				found: i !== -1,
				scoped: i !== -1 && all.slice( Math.max( 0, i - 60 ), i ).indexOf( '.minn-island-preview' ) !== -1,
				unscopedGlobal: /(^|\})\s*\.minn-test-rt-frame\s*\{/.test( all ),
			};
		} );
		t.check( 'runtime rule present in preview CSS', scoping.found );
		t.check( 'runtime rule is scoped to previews', scoping.scoped, JSON.stringify( scoping ) );
		t.check( 'no unscoped global rule leaked', scoping.unscopedGlobal === false );

		// Editor typography must be untouched: scoping means the harvested
		// sheet can only ever reach preview chrome.
		const bodyClean = await page.evaluate( () => {
			const p = document.querySelector( '.minn-editor-body > p' );
			return p ? getComputedStyle( p ).fontSize : '';
		} );
		t.check( 'editor body typography untouched', bodyClean !== '20px', bodyClean );

		// The control island must not have been reshaped by any of this.
		const control = await page.evaluate( () => {
			const w = document.querySelector( '.minn-island-preview .acme-plain' );
			return w ? { display: getComputedStyle( w ).display, inline: w.getAttribute( 'style' ) || '' } : null;
		} );
		t.check( 'control island untouched', control && control.inline === '', JSON.stringify( control ) );

		// One warm load per session: the iframe is removed once it has been read.
		const frames = await page.evaluate( () => document.querySelectorAll( 'iframe' ).length );
		t.check( 'harvest iframe cleaned up', frames === 0, String( frames ) );

		// Byte-identity: the harvest is preview chrome only.
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return ( j.content && j.content.raw ) || '';
		}, id );
		t.check( 'stored raw keeps the block verbatim', raw.indexOf( '<!-- wp:minn-test/runtime-css /-->' ) !== -1 );
		t.check( 'no preview CSS reached saved content', raw.indexOf( 'minn-island-preview' ) === -1 && raw.indexOf( 'minn-test-rt-frame' ) === -1 );
		t.check( 'harvest URL never stored', raw.indexOf( OVER_URL ) === -1 );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors.filter( ( e ) => ! foreign.has( e ) ) );
} )();
