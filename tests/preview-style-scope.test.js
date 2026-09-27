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
const { BASE, launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

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

	/* ===== Hostile markup: each shape once got past the scoping. ===== */
	const SVG = '<!-- wp:acme/svg -->\n<div class="acme-svg"><svg width="10" height="10"><style>.minn-topbar{outline:5px solid rgb(5, 5, 5)}</style></svg></div>\n<!-- /wp:acme/svg -->';
	const PRESET = '<!-- wp:acme/preset -->\n<div class="acme-preset"><style data-minn-inert-css="">.minn-topbar{border-left:9px solid rgb(9, 8, 7)}</style>preset</div>\n<!-- /wp:acme/preset -->';
	const SIB = '<!-- wp:acme/sib -->\n<div class="acme-sib"><style>body ~ * {box-shadow:0 0 0 3px rgb(3, 3, 3)} body + * {box-shadow:0 0 0 3px rgb(3, 3, 3)}</style>sib</div>\n<!-- /wp:acme/sib -->';
	// Nested rules and selector shapes that once escaped the scope, plus a
	// "<" comparison that must keep working inside the preview.
	const NEST = '<!-- wp:acme/nest -->\n<div class="acme-nest"><style>.acme-nest .x{:not(&){outline:3px solid rgb(4, 4, 4)} & ~ *{border-top:3px solid rgb(4, 4, 5)}} :root:has(.acme-nest) .minn-topbar{border-bottom:3px solid rgb(4, 5, 4)} body[class] ~ *{box-shadow:0 0 0 2px rgb(5, 4, 4)} @media (width < 99999px){.acme-nest p{color:rgb(2, 4, 6)}}</style><p>nested</p><span class="x">x</span></div>\n<!-- /wp:acme/nest -->';
	// An SVG <style> with an element child: switched off, never flattened.
	const SVGKID = '<!-- wp:acme/svgkid -->\n<div class="acme-svgkid"><svg width="10" height="10"><style>.minn-topbar{outline:6px solid rgb(6, 6, 6)}<a>kid</a></style></svg></div>\n<!-- /wp:acme/svgkid -->';
	// The same SVG inside editable prose, which is serialized from the DOM.
	const SVGP_INNER = '<svg width="10" height="10"><style>.acme-svgp{fill:red}<a>kid</a></style></svg> svg in prose';
	const SVGP = '<!-- wp:paragraph -->\n<p>' + SVGP_INNER + '</p>\n<!-- /wp:paragraph -->';
	const STORED_ATTR = 'data-minn-inert-onerror="window.__pwnStored=1"';
	const STORED = '<!-- wp:paragraph -->\n<p>Stored <img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" ' + STORED_ATTR + ' alt=""></p>\n<!-- /wp:paragraph -->';
	let hid = 0;
	try {
		hid = await createPost( page, { title: 'Hostile style probe', content: SVG + '\n\n' + PRESET + '\n\n' + SIB + '\n\n' + NEST + '\n\n' + SVGKID + '\n\n' + SVGP + '\n\n' + STORED + '\n\n<!-- wp:paragraph -->\n<p>Last.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, hid );
		await page.waitForSelector( '.minn-island-preview .acme-sib', { timeout: 20000 } );
		await page.waitForTimeout( 800 );
		const h = await page.evaluate( () => {
			const bar = getComputedStyle( document.querySelector( '.minn-topbar' ) );
			const body = document.getElementById( 'minn-editor-body' );
			const sibs = Array.from( body.parentNode.children ).filter( ( x ) => x !== body );
			return {
				outline: bar.outlineWidth + ' ' + bar.outlineColor,
				border: bar.borderLeftWidth + ' ' + bar.borderLeftColor,
				sibShadow: sibs.map( ( x ) => getComputedStyle( x ).boxShadow ).filter( ( v ) => v.indexOf( 'rgb(3, 3, 3)' ) !== -1 ).length,
				liveOnerror: document.querySelectorAll( '#minn-editor-body img[onerror]' ).length,
				chromeHits: [ document.querySelector( '.minn-topbar' ) ].concat( sibs, Array.from( document.querySelectorAll( '.minn-editor-side, .minn-editor-toolbar' ) ) ).filter( Boolean ).map( ( el ) => {
					const c = getComputedStyle( el );
					return [ c.outlineColor, c.borderTopColor, c.borderBottomColor, c.boxShadow ].join( ' ' );
				} ).filter( ( v ) => /rgb\(4, 4, 4\)|rgb\(4, 4, 5\)|rgb\(4, 5, 4\)|rgb\(5, 4, 4\)|rgb\(6, 6, 6\)/.test( v ) ).length,
				mediaLt: ( () => { const p = document.querySelector( '.minn-island-preview .acme-nest p' ); return p ? getComputedStyle( p ).color : ''; } )(),
			};
		} );
		t.check( 'nested :not(&), & ~ *, :root:has(), body[class] ~ * and SVG-with-children styles stay off Minn\'s chrome', 0 === h.chromeHits, String( h.chromeHits ) );
		t.check( 'a "<" media range still applies inside the preview', 'rgb(2, 4, 6)' === h.mediaLt, h.mediaLt );
		t.check( 'SVG <style> in a preview does not restyle Minn', h.outline.indexOf( 'rgb(5, 5, 5)' ) === -1, h.outline );
		t.check( 'a stored data-minn-inert-css does not skip the scoping', h.border.indexOf( 'rgb(9, 8, 7)' ) === -1, h.border );
		t.check( 'body ~ * / body + * cannot reach the editor body\'s siblings', 0 === h.sibShadow, String( h.sibShadow ) );
		t.check( 'a stored parked-looking handler stays inert in the editor', 0 === h.liveOnerror, String( h.liveOnerror ) );

		// An escaped "</style>" inside a CSS string must not survive the
		// re-scope as a literal close tag: pasting block markup (plain text,
		// from any page) runs it back through an HTML sink.
		await page.evaluate( () => { window.__pwn = 0; } );
		await freshParagraph( page );
		await page.evaluate( ( md ) => {
			const dt = new DataTransfer();
			dt.setData( 'text/plain', md );
			document.querySelector( '#minn-editor-body' ).dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
		}, '<!-- wp:acme/evil -->\n<div class="acme-evil"><style>.acme-evil::after{content:"\\3c/style\\3e\\3cimg src=x onerror=window.__pwn=1\\3e"}</style>evil</div>\n<!-- /wp:acme/evil -->' );
		await page.waitForTimeout( 1500 );
		const px = await page.evaluate( () => ( { pwn: window.__pwn, imgs: document.querySelectorAll( 'img[onerror]' ).length } ) );
		t.check( 'escaped </style> in pasted block markup does not run', 0 === px.pwn && 0 === px.imgs, JSON.stringify( px ) );

		// Save: the stored attribute comes back exactly as written, never live.
		await page.evaluate( () => {
			const ps = Array.from( document.querySelectorAll( '#minn-editor-body > p' ) );
			const p = ps.find( ( x ) => /Last\./.test( x.textContent ) );
			document.getElementById( 'minn-editor-body' ).focus( { preventScroll: true } );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			getSelection().removeAllRanges();
			getSelection().addRange( r );
		} );
		await page.keyboard.type( ' Saved.' );
		const hsaved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( '/wp/v2/posts/' + hid + '(\\?|$)' ).test( r.url() ), { timeout: 30000 } );
		await page.keyboard.press( 'Meta+s' );
		await hsaved;
		const hraw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			return ( ( await r.json() ).content || {} ).raw || '';
		}, hid );
		t.check( 'hostile save landed', hraw.indexOf( 'Saved.' ) !== -1 );
		const storedImg = ( hraw.match( /<img[^>]*>/ ) || [ '' ] )[ 0 ];
		t.check( 'stored parked-looking attribute saved byte-for-byte, not unparked', storedImg.indexOf( STORED_ATTR ) !== -1 && ! /\sonerror=/.test( storedImg ), storedImg );
		t.check( 'stored data-minn-inert-css saved as written', hraw.indexOf( PRESET.split( '\n' )[ 1 ] ) !== -1 );
		t.check( 'nested style saved byte-for-byte', hraw.indexOf( NEST.split( '\n' )[ 1 ] ) !== -1 );
		t.check( 'SVG <style> with an element child saved byte-for-byte', hraw.indexOf( SVGKID.split( '\n' )[ 1 ] ) !== -1, ( hraw.match( /<svg[\s\S]*?<\/svg>/g ) || [] ).join( ' | ' ) );
		t.check( 'SVG <style> with an element child in editable prose saved byte-for-byte', hraw.indexOf( SVGP_INNER ) !== -1, ( hraw.match( /<svg[\s\S]*?<\/svg>/g ) || [] ).join( ' | ' ) );
	} finally {
		await deletePost( page, hid );
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
