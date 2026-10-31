/**
 * v0.48.0 registry findings, the client half (WP Registry audit #2214):
 *
 *   16-1  A content iframe loads with a sandbox that leaves out
 *         allow-same-origin, so a frame of a same-origin page cannot run the
 *         writer's scripts as whoever opened the post (a writer's own
 *         sandbox is narrowed, never widened). An <object> or <embed> URL
 *         is parked, since no sandbox reaches them. The stored bytes round
 *         trip unchanged. (On a busy lab the framed page may not finish
 *         loading inside the editor in time to show the reach itself, so the
 *         sandbox and the parked URLs are what is checked first.)
 *   00-1  Popover and command invokers in stored content are parked, so a
 *         click on one in the revision viewer or the editor puts nothing in
 *         the top layer (a modal dialog there covered the whole admin and
 *         ignored Escape). The save keeps the writer's attributes.
 *   01-1  An image, link or table inside an island's preview wearing a
 *         look-alike .minn-slot opens no edit control. (An image there is
 *         also taken by the island's own image doorway first, so its check
 *         passes on v0.48.0 too; island-lookup-guard covers the handler.)
 *   16-2  The mail and notification preview strips dns-prefetch and
 *         preconnect links whose rel is written with character references,
 *         and the same links inside a nested srcdoc, and shows the
 *         remote-content bar for a URL written with character references.
 *         Run against the shipped functions in Chrome, no fixture needed.
 *   17-2  The notification panel and the modals bind their controls inside
 *         their own root: post content wearing data-nupd, minn-notif-link
 *         or data-revlist gets no click listener when the bell or the
 *         revisions dialog opens.
 *
 * Every check but the premises, the round trips and the two noted above
 * fails on the v0.48.0 app.js. Everything the
 * suite creates is deleted by onExit(), however the run ends.
 *
 * Run: cd tests && MINN_TEST_PASS=… node security-v049-registry-client.test.js
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { launch, login, createPost, openEditor, freshParagraph, reporter, onExit, BASE, WP } = require( './helpers' );

const wp = ( args ) => {
	try {
		return execFileSync( 'wp', [ ...args, '--path=' + WP, '--skip-themes', '--skip-plugins' ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	} catch ( e ) {
		return ( e && e.stdout ) || '';
	}
};

( async () => {
	const t = reporter( 'security-v049-registry-client' );
	const made = [];
	onExit( () => {
		if ( made.length ) wp( [ 'post', 'delete', ...made.map( String ), '--force' ] );
	} );

	const { browser, page, errors, ctx } = await launch();
	await login( page );

	const rawOf = ( pid ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p + '?context=edit&_fields=content.raw&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, pid );
	const save = async ( pid ) => {
		const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && res.url().includes( 'wp/v2/posts/' + pid ), { timeout: 150000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		return rawOf( pid );
	};
	const update = ( pid, content ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.pid, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { content: a.content } ),
		} );
		if ( ! r.ok ) throw new Error( 'update failed' );
	}, { pid, content } );
	// The rail's History door appears once the revisions load, which is slow
	// on a busy machine: wait, and reopen the editor once if it never shows.
	const openHistory = async ( pid ) => {
		for ( let i = 0; i < 2; i++ ) {
			try {
				await page.waitForSelector( '[data-side-door="history"]', { timeout: 60000 } );
				break;
			} catch ( e ) {
				if ( i ) throw e;
				await openEditor( page, pid );
			}
		}
		await page.click( '[data-side-door="history"]' );
		await page.waitForSelector( '.minn-modal [data-revlist]', { timeout: 60000 } );
	};
	// Click listeners attached to the element itself (not delegated ones).
	const cdp = await ctx.newCDPSession( page );
	const ownClickListeners = async ( selector ) => {
		const { result } = await cdp.send( 'Runtime.evaluate', { expression: `document.querySelector(${ JSON.stringify( selector ) })` } );
		if ( ! result.objectId ) return -1;
		const { listeners } = await cdp.send( 'DOMDebugger.getEventListeners', { objectId: result.objectId } );
		return listeners.filter( ( l ) => 'click' === l.type ).length;
	};

	try {
		/* ===== 16-1 content frames ===== */
		const target = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/pages', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {
					title: 'Minn v049 frame target',
					status: 'publish',
					content: '<!-- wp:html --><script>(function(){var k=new URLSearchParams(location.search).get("k")||"x";try{window.parent["__minn49_"+k]=(window.parent.MINN&&window.parent.MINN.nonce)?"reached":"no";}catch(e){}})();</script><!-- /wp:html -->',
				} ),
			} );
			return ( await r.json() ).id;
		} );
		made.push( target );
		const u = ( k ) => `/?page_id=${ target }&amp;k=${ k }`;
		const FRAMES = `<iframe src="${ u( 'iframe' ) }" width="40" height="40"></iframe>`
			+ `<iframe src="${ u( 'own' ) }" sandbox="allow-scripts allow-same-origin" width="40" height="40"></iframe>`
			+ `<object data="${ u( 'object' ) }" type="text/html" width="40" height="40"></object>`
			+ `<embed src="${ u( 'embed' ) }" type="text/html" width="40" height="40">`;
		const FRAME_POST = `<!-- wp:html -->\n<div class="minn49-frames">${ FRAMES }</div>\n<!-- /wp:html -->\n\n<!-- wp:paragraph -->\n<p>Frames above.</p>\n<!-- /wp:paragraph -->`;
		const fid = await createPost( page, { title: 'Minn v049 frames', content: FRAME_POST, status: 'draft' } );
		made.push( fid );
		const front = await page.evaluate( async ( pid ) => ( await fetch( '/?page_id=' + pid + '&k=probe' ) ).text(), target );
		t.check( 'the framed page carries the writer\'s script (premise)', /__minn49_/.test( front ), '' );

		// The framed page is slow to answer under load: wait for both frames'
		// documents, then for their scripts.
		const errorsBefore = errors.length;
		const framed = [ 'iframe', 'own' ].map( ( k ) => page.waitForResponse( ( r ) => r.url().includes( `page_id=${ target }&k=${ k }` ), { timeout: 90000 } ).catch( () => null ) );
		await openEditor( page, fid );
		await page.waitForSelector( '#minn-editor-body iframe', { timeout: 15000 } );
		const got = await Promise.all( framed );
		t.check( 'both content frames loaded their page (premise)', got.every( Boolean ), '' );
		await page.waitForTimeout( 3000 );
		const reached = await page.evaluate( () => Object.keys( window ).filter( ( k ) => /^__minn49_/.test( k ) ).map( ( k ) => k + '=' + window[ k ] ) );
		// Asked from inside each content frame as well: can its document read
		// the admin's nonce through parent?
		for ( const f of page.frames() ) {
			if ( ! /[?&]k=(iframe|own)/.test( f.url() ) ) continue;
			const can = await f.evaluate( () => {
				try {
					return !! window.parent.MINN.nonce;
				} catch ( e ) {
					return false;
				}
			} ).catch( () => false );
			if ( can ) reached.push( f.url().replace( /^.*[?&]k=/, 'frame k=' ) + ' reads parent.MINN.nonce' );
		}
		t.check( 'no content frame runs script in the admin\'s origin', 0 === reached.length, reached.join( ', ' ) );
		const live = await page.evaluate( () => {
			const fr = Array.from( document.querySelectorAll( '#minn-editor-body iframe' ) );
			const o = document.querySelector( '#minn-editor-body object' );
			const e = document.querySelector( '#minn-editor-body embed' );
			return {
				sandboxes: fr.map( ( f ) => f.getAttribute( 'sandbox' ) ),
				objectData: o ? o.getAttribute( 'data' ) : 'missing',
				embedSrc: e ? e.getAttribute( 'src' ) : 'missing',
			};
		} );
		t.check( 'every live content iframe is sandboxed without allow-same-origin',
			2 === live.sandboxes.length && live.sandboxes.every( ( s ) => null !== s && ! /allow-same-origin/.test( s ) ), JSON.stringify( live.sandboxes ) );
		t.check( 'a writer\'s own sandbox is narrowed, not widened', 'allow-scripts' === live.sandboxes[ 1 ], String( live.sandboxes[ 1 ] ) );
		t.check( 'object data and embed src are parked in the live copy', null === live.objectData && null === live.embedSrc, JSON.stringify( live ) );
		await freshParagraph( page );
		await page.keyboard.type( 'edited' );
		const fsaved = await save( fid );
		// Each element as written. (The editor re-saves a Custom HTML block
		// holding a bare div as paragraphs, on the shipped build too; that is
		// not what this checks.)
		const els = FRAMES.match( /<(iframe|object|embed)\b[^>]*>/g );
		t.check( 'every frame, object and embed comes back as written on save',
			4 === els.length && els.every( ( e ) => fsaved.includes( e ) ) && ! /data-minn-inert-/.test( fsaved ) && /edited/.test( fsaved ), fsaved.slice( 0, 600 ) );
		// The framed front-end page's own scripts now run in an opaque origin
		// and complain (localStorage, cookies, fonts); those errors are the
		// sandbox working, not the app's. They are dropped from the error gate.
		const dropFramedNoise = () => {
			const framedNoise = /sandboxed and lacks the 'allow-same-origin' flag|from origin 'null' has been blocked by CORS policy/;
			for ( let i = errors.length - 1; i >= errorsBefore; i-- ) {
				if ( framedNoise.test( errors[ i ] ) || /^pageerror: /.test( errors[ i ] ) ) {
					console.log( `INFO  framed page error (dropped from the gate): ${ errors[ i ].slice( 0, 160 ) }` );
					errors.splice( i, 1 );
				}
			}
		};

		/* ===== 00-1 top-layer invokers ===== */
		const INVOKERS = '<!-- wp:paragraph -->\n<p>Same <button type="button" class="minn49-cmd" commandfor="minn49dlg" command="show-modal">open</button> <button type="button" class="minn49-pop" popovertarget="minn49pop">pop</button> <span id="minn49pop" popover>pop</span></p>\n<!-- /wp:paragraph -->\n\n'
			+ '<!-- wp:html -->\n<dialog id="minn49dlg" closedby="none">trap</dialog>\n<!-- /wp:html -->';
		const tid = await createPost( page, { title: 'Minn v049 top layer', content: '<p>seed</p>', status: 'draft' } );
		made.push( tid );
		await update( tid, INVOKERS + '\n\n<!-- wp:paragraph -->\n<p>alpha</p>\n<!-- /wp:paragraph -->' );
		await update( tid, INVOKERS + '\n\n<!-- wp:paragraph -->\n<p>beta</p>\n<!-- /wp:paragraph -->' );
		await openEditor( page, tid );
		// The frames' page is gone now; whatever it logged is behind us.
		dropFramedNoise();
		await openHistory( tid );
		await page.evaluate( () => {
			const rows = document.querySelectorAll( '[data-revlist]' );
			rows[ rows.length - 1 ].click();
		} );
		await page.waitForSelector( '#minn-diff .minn49-cmd', { timeout: 15000 } );
		const topLayer = () => page.evaluate( () => ( {
			modal: !! document.querySelector( ':modal' ),
			popover: !! document.querySelector( ':popover-open' ),
		} ) );
		await page.click( '#minn-diff .minn49-cmd', { force: true } );
		await page.waitForTimeout( 300 );
		await page.click( '#minn-diff .minn49-pop', { force: true } ).catch( () => {} );
		await page.waitForTimeout( 300 );
		const inDiff = await topLayer();
		t.check( 'an invoker in the revision viewer puts nothing in the top layer', ! inDiff.modal && ! inDiff.popover, JSON.stringify( inDiff ) );
		await page.keyboard.press( 'Escape' );
		await page.evaluate( () => {
			const d = document.querySelector( ':modal' );
			if ( d && d.close ) d.close();
		} );
		await openEditor( page, tid );
		await page.waitForSelector( '#minn-editor-body .minn49-cmd', { timeout: 15000 } );
		await page.click( '#minn-editor-body .minn49-cmd', { force: true } );
		await page.click( '#minn-editor-body .minn49-pop', { force: true } ).catch( () => {} );
		await page.waitForTimeout( 300 );
		const inEditor = await topLayer();
		t.check( 'an invoker in the editor puts nothing in the top layer', ! inEditor.modal && ! inEditor.popover, JSON.stringify( inEditor ) );
		await page.evaluate( () => {
			const d = document.querySelector( ':modal' );
			if ( d && d.close ) d.close();
		} );
		await freshParagraph( page );
		await page.keyboard.type( 'kept' );
		const tsaved = await save( tid );
		t.check( 'the save keeps the writer\'s invoker attributes',
			/commandfor="minn49dlg"/.test( tsaved ) && /command="show-modal"/.test( tsaved ) && /popovertarget="minn49pop"/.test( tsaved ) && /<span id="minn49pop" popover(="")?>/.test( tsaved ) && ! /data-minn-inert-/.test( tsaved ), tsaved.slice( 0, 400 ) );

		/* ===== 01-1 look-alike slot in an island preview ===== */
		const IMG = `${ BASE }/wp-includes/images/w-logo-blue.png`;
		const SLOTPOST = `<!-- wp:minn-test/box -->\n<div class="minn49-box"><div class="minn-slot" contenteditable="true"><img class="minn49-img" src="${ IMG }" alt=""><a class="minn49-a" href="https://example.invalid/x">link</a><table class="minn49-t"><tbody><tr><td>cell</td></tr></tbody></table></div></div>\n<!-- /wp:minn-test/box -->\n\n<!-- wp:paragraph -->\n<p>After.</p>\n<!-- /wp:paragraph -->`;
		const sid = await createPost( page, { title: 'Minn v049 slot look-alike', content: SLOTPOST, status: 'draft' } );
		made.push( sid );
		await openEditor( page, sid );
		await page.waitForSelector( '#minn-editor-body .minn-island-preview .minn49-img', { timeout: 20000 } );
		await page.waitForTimeout( 500 );
		t.check( 'the look-alike sits inside an island preview (premise)', await page.evaluate( () => !! document.querySelector( '#minn-editor-body .minn-block-island .minn-island-preview .minn-slot .minn49-img' ) ), '' );
		const popOpen = ( cls ) => page.evaluate( ( c ) => {
			// Popovers are position: fixed (no offsetParent): read the box.
			const el = document.querySelector( c );
			if ( ! el || el.hidden ) return false;
			const r = el.getBoundingClientRect();
			return 'none' !== getComputedStyle( el ).display && r.width > 0 && r.height > 0;
		}, cls );
		await page.click( '#minn-editor-body .minn49-img', { force: true } );
		await page.waitForTimeout( 400 );
		t.check( 'no image popover on a preview image behind a look-alike slot', ! await popOpen( '.minn-img-pop' ), '' );
		await page.keyboard.press( 'Escape' );
		await page.click( '#minn-editor-body .minn49-a', { force: true } );
		await page.waitForTimeout( 400 );
		t.check( 'no link popover on a preview link behind a look-alike slot', ! await popOpen( '.minn-link-pop' ), '' );
		await page.keyboard.press( 'Escape' );
		await page.click( '#minn-editor-body .minn49-t td', { button: 'right', force: true } );
		await page.waitForTimeout( 400 );
		t.check( 'no table menu on a preview table behind a look-alike slot', ! await popOpen( '.minn-table-menu' ), '' );
		await page.keyboard.press( 'Escape' );

		/* ===== 17-2 overlay bindings stay in their root ===== */
		const PLANT = '<!-- wp:paragraph -->\n<p class="minn49-plant">x <span data-nupd="plugin-hello-1.0" class="minn49-nupd">update</span> <span class="minn-notif-link minn49-nl" data-nid="notice-x" data-li="0">act</span> <span class="minn-notif-row minn49-row" data-nid="notice-x">row</span> <span class="minn-notif-hide minn49-hide" data-nid="notice-x">hide</span> <span data-revlist="1" class="minn49-rev">rev</span></p>\n<!-- /wp:paragraph -->';
		const pid = await createPost( page, { title: 'Minn v049 overlay scope', content: '<p>seed</p>', status: 'draft' } );
		made.push( pid );
		await update( pid, PLANT + '\n\n<!-- wp:paragraph -->\n<p>one</p>\n<!-- /wp:paragraph -->' );
		await update( pid, PLANT + '\n\n<!-- wp:paragraph -->\n<p>two</p>\n<!-- /wp:paragraph -->' );
		await openEditor( page, pid );
		await page.waitForSelector( '#minn-editor-body .minn49-nupd', { timeout: 15000 } );
		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '.minn-notif-panel', { timeout: 15000 } );
		await page.waitForTimeout( 1500 );
		const counts = {};
		for ( const c of [ 'nupd', 'nl', 'row', 'hide' ] ) counts[ c ] = await ownClickListeners( `#minn-editor-body .minn49-${ c }` );
		t.check( 'the notification panel binds nothing in the post behind it', Object.values( counts ).every( ( n ) => 0 === n ), JSON.stringify( counts ) );
		// Close the panel (its overlay takes every click while it is open).
		if ( await page.$( '#minn-notif-close' ) ) await page.click( '#minn-notif-close' ).catch( () => {} );
		await page.waitForSelector( '.minn-notif-panel', { state: 'detached', timeout: 15000 } ).catch( () => {} );
		await openHistory( pid );
		const rev = await ownClickListeners( '#minn-editor-body .minn49-rev' );
		t.check( 'the revisions dialog binds nothing in the post behind it', 0 === rev, String( rev ) );
		const realRow = await ownClickListeners( '.minn-modal [data-revlist]' );
		t.check( 'the dialog\'s own rows are still bound', realRow > 0, String( realRow ) );
		await page.keyboard.press( 'Escape' );

		/* ===== 16-2 preview strip, the shipped functions in Chrome ===== */
		const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' );
		const from = src.indexOf( '\tconst PREVIEW_CSP_BLOCK' );
		const to = src.indexOf( '\tdocument.addEventListener( \'click\', ( e ) => {', from );
		const slice = src.slice( from, to );
		t.check( 'the preview helpers are found in app.js (premise)', from > 0 && to > from && /function previewFrameHtml/.test( slice ), '' );
		const res = await page.evaluate( ( code ) => {
			// eslint-disable-next-line no-new-func
			const make = new Function( '$$', 'esc', '__', code + '\nreturn { previewSrcdoc, previewFrameHtml };' );
			const $$ = ( sel, root ) => Array.from( ( root || document ).querySelectorAll( sel ) );
			const esc = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
			const { previewSrcdoc, previewFrameHtml } = make( $$, esc, ( s ) => s );
			const doc = ( html ) => new DOMParser().parseFromString( previewSrcdoc( html, false ), 'text/html' );
			const links = ( d ) => Array.from( d.querySelectorAll( 'link' ) ).map( ( l ) => l.getAttribute( 'rel' ) );
			const plain = '<p>Hello there.</p>';
			const nested = doc( '<p>x</p><iframe srcdoc="<link rel=&quot;pre&amp;#x63;onnect&quot; href=&quot;//t.example&quot;><link rel=&quot;dns-prefetch&quot; href=&quot;//t.example&quot;>"></iframe>' );
			const inner = nested.querySelector( 'iframe' );
			return {
				refPrefetch: links( doc( '<link rel="dns-pre&#102;etch" href="//t.example"><p>x</p>' ) ),
				refConnect: links( doc( '<link rel="pre&#x63;onnect" href="//t.example"><p>x</p>' ) ),
				nestedLinks: inner ? links( new DOMParser().parseFromString( inner.getAttribute( 'srcdoc' ) || '', 'text/html' ) ) : [ 'no iframe' ],
				prefetchOn: !! doc( '<meta http-equiv="x-dns-prefetch-control" content="on"><p>x</p>' ).querySelector( 'meta[content="on"]' ),
				ownOff: /<meta http-equiv="x-dns-prefetch-control" content="off">/.test( previewSrcdoc( plain, false ) ),
				plainKept: previewSrcdoc( plain, false ).endsWith( plain ),
				barRef: /data-remote-bar/.test( previewFrameHtml( '<img src="&#47;&#47;t.example/p.gif">', { cls: 'x', title: 't' } ) ),
				barPlain: /data-remote-bar/.test( previewFrameHtml( plain, { cls: 'x', title: 't' } ) ),
			};
		}, slice );
		t.check( 'a character-referenced dns-prefetch link is stripped', 0 === res.refPrefetch.length, JSON.stringify( res.refPrefetch ) );
		t.check( 'a character-referenced preconnect link is stripped', 0 === res.refConnect.length, JSON.stringify( res.refConnect ) );
		t.check( 'prefetch links inside a nested srcdoc are stripped', 0 === res.nestedLinks.length, JSON.stringify( res.nestedLinks ) );
		t.check( 'a message cannot switch link prefetching on, and the preview switches it off', ! res.prefetchOn && res.ownOff, JSON.stringify( res ) );
		t.check( 'a message with nothing to strip is left as written', res.plainKept, '' );
		t.check( 'a remote URL written with character references shows the remote bar', res.barRef && ! res.barPlain, JSON.stringify( res ) );
	} finally {
		if ( made.length ) {
			await page.evaluate( async ( ids ) => {
				for ( const id of ids ) {
					for ( const type of [ 'posts', 'pages' ] ) {
						await fetch( window.MINN.restUrl + 'wp/v2/' + type + '/' + id + '?force=true', { method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce } } ).catch( () => {} );
					}
				}
			}, made ).catch( () => {} );
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
