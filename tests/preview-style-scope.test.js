/**
 * Style and stylesheet elements inside rendered block markup (island
 * previews, locked-mode bodies, revision views) must not restyle the admin.
 * A <style> element is global wherever it lands in the document, so markup
 * rendered from a post could otherwise repaint or hide Minn's own chrome
 * around the preview, and a <link rel=stylesheet> would load a remote sheet
 * the moment an editor opened the post.
 *
 * The inert parse scopes a style element's rules to the preview (the same
 * scoper the bundled front-end CSS goes through), parks a stylesheet link's
 * href, and hands the writer's bytes back at serialize time.
 *
 * Fixture: an unregistered block (renders verbatim as an island) carrying a
 * style element that targets body, Minn's topbar and its own paragraph, and
 * a stylesheet link.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const STYLE = '<style>body{outline:7px solid rgb(1, 2, 3)}.minn-topbar{background:rgb(1, 2, 3) !important}.acme-styled p{color:rgb(4, 5, 6)}</style>';
const LINK = '<link rel="stylesheet" href="' + BASE + '/wp-content/plugins/minn-admin/tests/does-not-exist.css">';
const STYLED = '<!-- wp:acme/styled -->\n<div class="acme-styled">' + STYLE + LINK + '<p>Styled by its own sheet</p></div>\n<!-- /wp:acme/styled -->';
// core/html is editable prose (serialized from the live DOM, not spliced).
const HTML_STYLE = '<style>.acme-html{color:rgb(7, 8, 9)}</style>';
const HTMLBLOCK = '<!-- wp:html -->\n' + HTML_STYLE + '<div class="acme-html">Raw HTML block</div>\n<!-- /wp:html -->';
const CONTENT = STYLED + '\n\n' + HTMLBLOCK + '\n\n<!-- wp:paragraph -->\n<p>Tail paragraph.</p>\n<!-- /wp:paragraph -->';
// Classic mode (no block comments) serializes the whole body from the DOM.
const CLASSIC_STYLE = '<style>.acme-classic{color:rgb(9, 9, 9)}</style>';
const CLASSIC = CLASSIC_STYLE + '\n<p class="acme-classic">Classic paragraph.</p>';

( async () => {
	const t = reporter( 'preview-style-scope' );
	const { browser, page, errors } = await launch();
	await login( page );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Preview style scope probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );

		await openEditor( page, id );
		await page.waitForSelector( '.minn-island-preview .acme-styled p', { timeout: 20000 } );
		await page.waitForTimeout( 800 );

		const st = await page.evaluate( () => {
			const p = document.querySelector( '.minn-island-preview .acme-styled p' );
			const bar = document.querySelector( '.minn-topbar' );
			return {
				bodyOutline: getComputedStyle( document.body ).outlineWidth,
				bar: bar ? getComputedStyle( bar ).backgroundColor : '',
				para: p ? getComputedStyle( p ).color : '',
				liveLink: !! document.querySelector( '.minn-island-preview link[href]' ),
				sheetHrefs: Array.from( document.styleSheets ).map( ( s ) => s.href || '' ).filter( ( h ) => h.indexOf( 'does-not-exist.css' ) !== -1 ).length,
			};
		} );
		t.check( 'preview style does not reach the page body', st.bodyOutline !== '7px', st.bodyOutline );
		t.check( 'preview style does not repaint Minn\'s topbar', st.bar !== 'rgb(1, 2, 3)', st.bar );
		t.check( 'preview style still styles its own block', st.para === 'rgb(4, 5, 6)', st.para );
		t.check( 'stylesheet link in a preview is parked (no live href)', ! st.liveLink );
		t.check( 'parked stylesheet never loaded', 0 === st.sheetHrefs, String( st.sheetHrefs ) );

		// Byte-identity: an edit elsewhere and a save keep the writer's style
		// and link exactly as stored.
		await page.evaluate( () => {
			const ps = Array.from( document.querySelectorAll( '#minn-editor-body > p' ) );
			const p = ps.find( ( x ) => /Tail paragraph/.test( x.textContent ) );
			document.getElementById( 'minn-editor-body' ).focus( { preventScroll: true } );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			const sel = getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
		} );
		await page.keyboard.type( ' More.' );
		const saved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( '/wp/v2/posts/' + id + '(\\?|$)' ).test( r.url() ), { timeout: 30000 } );
		await page.keyboard.press( 'Meta+s' );
		await saved;
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return j.content && j.content.raw || '';
		}, id );
		t.check( 'save landed', raw.indexOf( 'More.' ) !== -1 );
		t.check( 'stored raw keeps the style element byte-for-byte', raw.indexOf( STYLE ) !== -1 );
		t.check( 'stored raw keeps the stylesheet link', raw.indexOf( 'does-not-exist.css' ) !== -1 );
		// (The editor saves a core/html block's top-level nodes as paragraphs;
		// that predates this suite. What it pins is that the writer's style
		// text comes back and no parked copy ever reaches the database.)
		t.check( 'editable html keeps its style text byte-for-byte', raw.indexOf( HTML_STYLE ) !== -1, JSON.stringify( raw ) );
		t.check( 'no parked markup reaches the database', raw.indexOf( 'data-minn-inert-' ) === -1 && raw.indexOf( 'minn-island-preview' ) === -1 );
	} finally {
		await deletePost( page, id );
	}

	let cid = 0;
	try {
		cid = await createPost( page, { title: 'Classic style scope probe', content: CLASSIC } );
		await openEditor( page, cid );
		await page.waitForSelector( '#minn-editor-body .acme-classic', { timeout: 20000 } );
		t.check( 'classic body style still styles the post content', await page.evaluate( () => getComputedStyle( document.querySelector( '#minn-editor-body .acme-classic' ) ).color === 'rgb(9, 9, 9)' ) );
		t.check( 'classic body style does not reach the page', await page.evaluate( () => getComputedStyle( document.body ).color !== 'rgb(9, 9, 9)' && getComputedStyle( document.querySelector( '.minn-topbar' ) ).color !== 'rgb(9, 9, 9)' ) );
		await page.evaluate( () => {
			const p = document.querySelector( '#minn-editor-body .acme-classic' );
			document.getElementById( 'minn-editor-body' ).focus( { preventScroll: true } );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			getSelection().removeAllRanges();
			getSelection().addRange( r );
		} );
		await page.keyboard.type( ' Edited.' );
		const csaved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( '/wp/v2/posts/' + cid + '(\\?|$)' ).test( r.url() ), { timeout: 30000 } );
		await page.keyboard.press( 'Meta+s' );
		await csaved;
		const craw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return j.content && j.content.raw || '';
		}, cid );
		t.check( 'classic save landed', craw.indexOf( 'Edited.' ) !== -1, craw.slice( 0, 160 ) );
		t.check( 'classic save keeps the style byte-for-byte', craw.indexOf( CLASSIC_STYLE ) !== -1, craw.slice( 0, 160 ) );
		t.check( 'classic save carries no parked markup', craw.indexOf( 'data-minn-inert-' ) === -1 && craw.indexOf( 'minn-island-preview' ) === -1 );
	} finally {
		await deletePost( page, cid );
	}

	/* ===== A preview font named like Minn's own UI font is aliased, not
	 * installed: on the WordPress font setting Minn's stack borrows Roboto,
	 * so a theme's @font-face Roboto would otherwise restyle Minn itself. ===== */
	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' }, credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );
	const before = await rest( 'minn-admin/v1/me/appearance' );
	let fid = 0;
	try {
		await rest( 'minn-admin/v1/me/appearance', { method: 'POST', body: JSON.stringify( Object.assign( {}, before.body || {}, { font: 'wordpress' } ) ) } );
		const FONT = '<!-- wp:acme/font -->\n<div class="acme-font"><style>@font-face{font-family:Roboto;src:local("Arial")}.acme-font p{font-family:Roboto, serif}</style><p>Font probe</p></div>\n<!-- /wp:acme/font -->';
		fid = await createPost( page, { title: 'Preview font alias probe', content: FONT } );
		// A snapshot under the old unscoped key, and one under a scoped key.
		await page.evaluate( () => {
			localStorage.setItem( 'minn-net-posts-999999', JSON.stringify( { t: Date.now(), title: 'old', content: 'x' } ) );
			localStorage.setItem( 'minn-net-u999.test-posts-1', JSON.stringify( { t: Date.now(), title: 'scoped', content: 'x' } ) );
		} );
		await openEditor( page, fid );
		await page.waitForSelector( '.minn-island-preview .acme-font p', { timeout: 20000 } );
		await page.waitForTimeout( 800 );
		const f = await page.evaluate( () => {
			const faces = [];
			Array.from( document.styleSheets ).forEach( ( sh ) => {
				let rules = [];
				try { rules = Array.from( sh.cssRules ); } catch ( e ) {}
				rules.forEach( ( r ) => { if ( r instanceof CSSFontFaceRule ) faces.push( r.style.getPropertyValue( 'font-family' ).replace( /["']/g, '' ).trim().toLowerCase() ); } );
			} );
			return {
				font: document.documentElement.getAttribute( 'data-font' ),
				para: getComputedStyle( document.querySelector( '.minn-island-preview .acme-font p' ) ).fontFamily,
				faces,
				legacy: localStorage.getItem( 'minn-net-posts-999999' ),
				scoped: localStorage.getItem( 'minn-net-u999.test-posts-1' ),
			};
		} );
		t.check( 'running on the WordPress font setting', 'wordpress' === f.font, f.font );
		t.check( 'no @font-face named Roboto reaches the page', ! f.faces.includes( 'roboto' ), f.faces.join( ',' ) );
		t.check( 'the preview gets the font under a private alias', f.faces.includes( 'minn-pv-roboto' ) && /^"?minn-pv-roboto/.test( f.para ), f.para );
		t.check( 'old unscoped draft snapshot removed at boot', null === f.legacy );
		t.check( 'scoped draft snapshot kept', null !== f.scoped );
		await page.evaluate( () => localStorage.removeItem( 'minn-net-u999.test-posts-1' ) );
	} finally {
		await deletePost( page, fid );
		await rest( 'minn-admin/v1/me/appearance', { method: 'POST', body: JSON.stringify( before.body || { font: 'minn' } ) } );
	}

	await t.done( browser, errors );
} )();
