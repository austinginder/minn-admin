/**
 * v0.45.0 registry findings, the client half (WP Registry audit #2208):
 *
 *   #2   The editor seeds its title from title.raw as written and sends it
 *        only when the person changed it: an escaped "<img …>" is never
 *        resent as live markup, and inline tags survive a save.
 *   #9   An appearance click sends only what it changed, on Your profile and
 *        on another user's page, so a choice made meanwhile in another
 *        session stays.
 *   #17  Opening a wysiwyg field in the rich-text modal never rewrites it:
 *        Apply with no edit hands back the stored bytes, and an edit keeps
 *        mailto:, tel:, #anchor, relative and inline-image targets.
 *   #18  A paste never rebuilds an island from the clipboard's HTML.
 *   #19  A planted island look-alike (a Buttons card, a wrapping Details
 *        marker, an upload placeholder) cannot write another block.
 *   #20  Opening a post never runs its front end in the app's origin.
 *   #6   The post type description is a textarea: line breaks survive, and
 *        an untouched description saves nothing.
 *   And one found on the way: the serializers restore parked markup on a
 *   copy in the inert document, so a stored handler never runs on save.
 *
 * Every check fails on the v0.45.0 app.js. Everything the suite sets up is
 * put back by onExit(), however the run ends.
 *
 * Run: cd tests && MINN_TEST_PASS=… node security-v046-registry-client.test.js
 */
const { execFileSync } = require( 'child_process' );
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter, onExit, BASE, WP } = require( './helpers' );

const wp = ( args, opts = {} ) => {
	try {
		return execFileSync( 'wp', [ ...args, '--path=' + WP, '--skip-themes', ...( opts.plugins ? [] : [ '--skip-plugins' ] ) ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	} catch ( e ) {
		return ( e && e.stdout ) || '';
	}
};
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
const EDITOR_ID = Number( String( wp( [ 'user', 'get', 'minn-editor', '--field=ID' ] ) ).trim().split( '\n' ).pop() ) || 4;
const CPT = 'minn_v46desc';

( async () => {
	const t = reporter( 'security-v046-registry-client' );

	// Restores, armed before anything changes.
	const adminWas = metaGet( ADMIN_ID );
	const editorWas = metaGet( EDITOR_ID );
	const posts = [];
	const restore = onExit( () => {
		metaSet( ADMIN_ID, adminWas );
		metaSet( EDITOR_ID, editorWas );
		if ( posts.length ) wp( [ 'post', 'delete', ...posts.map( String ), '--force' ] );
		wp( [ 'eval', `$o = get_option( 'cptui_post_types', array() ); if ( is_array( $o ) && isset( $o['${ CPT }'] ) ) { unset( $o['${ CPT }'] ); update_option( 'cptui_post_types', $o ); }` ] );
	} );

	const { browser, page, errors } = await launch();
	// #20 runs a real front end (isolated) for its preview CSS, and that page's
	// own scripts report into this context (minnadmin's Elementor Pro throws on
	// every front-end load). Errors whose stack sits in third-party plugin or
	// theme code belong to that page, not to Minn.
	const foreign = new Set();
	page.on( 'pageerror', ( e ) => {
		const stack = e.stack || '';
		if ( /\/wp-content\/(plugins|themes)\//.test( stack ) && stack.indexOf( '/plugins/minn-admin/' ) === -1 ) {
			foreign.add( 'pageerror: ' + e.message );
		}
	} );
	await login( page );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
			credentials: 'same-origin',
		} );
		return r.json();
	}, { route, ...opts } );
	const make = async ( args ) => {
		const id = await createPost( page, args );
		posts.push( id );
		return id;
	};
	const rawOf = async ( id ) => {
		const p = await rest( `wp/v2/posts/${ id }?context=edit&_fields=title,content&_cb=${ Date.now() }` );
		return { title: p.title && p.title.raw, content: p.content && p.content.raw };
	};
	const saveNow = async ( id ) => {
		const done = page.waitForResponse( ( res ) => res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id + '(\\?|$)' ).test( res.url() ), { timeout: 20000 } ).catch( () => null );
		await page.keyboard.press( 'Meta+s' );
		await done;
		await page.waitForTimeout( 400 );
	};
	const typeInBody = async ( text ) => {
		await freshParagraph( page );
		await page.keyboard.type( text );
	};

	try {
		/* ===== #2 editor title ===== */
		const TITLE = 'Test &lt;img src=x onerror=window.__minnT2=1&gt; and <em>Dune</em> &amp; more';
		const tid = await make( { title: TITLE, content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
		t.check( '#2 fixture title stored as written', ( await rawOf( tid ) ).title === TITLE );
		await openEditor( page, tid );
		const box = await page.$eval( '#minn-editor-title', ( el ) => el.value );
		t.check( '#2 the title box keeps escaped markup escaped and tags as tags', box === 'Test &lt;img src=x onerror=window.__minnT2=1&gt; and <em>Dune</em> & more', box );
		await typeInBody( 'x' );
		await saveNow( tid );
		let raw = await rawOf( tid );
		t.check( '#2 a save that did not touch the title leaves it byte for byte', raw.title === TITLE, raw.title );
		t.check( '#2 no live <img> reaches the title', raw.title.indexOf( '<img' ) === -1, raw.title );
		await page.$eval( '#minn-editor-title', ( el ) => {
			el.focus();
			el.setSelectionRange( el.value.length, el.value.length );
		} );
		await page.keyboard.type( ' v2' );
		await saveNow( tid );
		raw = await rawOf( tid );
		t.check( '#2 an edited title keeps the escape and the inline tag', raw.title === 'Test &lt;img src=x onerror=window.__minnT2=1&gt; and <em>Dune</em> & more v2', raw.title );
		t.check( '#2 the title never ran as markup in the app', await page.evaluate( () => undefined === window.__minnT2 ) );

		// A published post backs up to an autosave revision: whole snapshot,
		// so it carries the stored title byte for byte.
		const pid = await make( { title: TITLE, content: '<!-- wp:paragraph -->\n<p>Live body.</p>\n<!-- /wp:paragraph -->', status: 'publish' } );
		await openEditor( page, pid );
		const autosaved = page.waitForResponse( ( res ) => res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + pid + '/autosaves' ).test( res.url() ), { timeout: 40000 } ).catch( () => null );
		await typeInBody( 'backup' );
		const ar = await autosaved;
		const sent = ar ? JSON.parse( ar.request().postData() || '{}' ) : {};
		t.check( '#2 an autosave revision carries the stored title, not a decoded one', sent.title === TITLE, JSON.stringify( sent.title ) );

		/* ===== The serializers put parked markup back on an inert copy ===== */
		// Found while fixing #17: a cloneNode copy belongs to the live page, so
		// restoring a parked src and onerror on it ran the handler here, on
		// every save and crash-net snapshot.
		const UNPARK = '<!-- wp:paragraph -->\n<p>Before <img src="/wp-includes/images/minn-missing-probe.png" onerror="window.__minnUnpark=1" alt=""> after</p>\n<!-- /wp:paragraph -->';
		const nid = await make( { title: 'Unpark probe', content: UNPARK } );
		await openEditor( page, nid );
		await typeInBody( 'q' );
		await saveNow( nid );
		await page.waitForTimeout( 1500 );
		raw = await rawOf( nid );
		t.check( 'a save keeps a stored handler byte for byte and never runs it in the editor',
			await page.evaluate( () => undefined === window.__minnUnpark ) && raw.content.indexOf( 'onerror="window.__minnUnpark=1"' ) !== -1, raw.content.slice( 0, 200 ) );

		/* ===== #9 appearance ===== */
		await page.goto( `${ BASE }/minn-admin/profile`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-wp-fonts', { timeout: 15000 } );
		const mine = metaGet( ADMIN_ID ) || {};
		// Another session flips the Minn bar after this page loaded.
		metaSet( ADMIN_ID, { ...mine, frontBar: ! mine.frontBar } );
		// The response, not the request: the record is read once the save landed.
		const meRes = page.waitForResponse( ( r ) => r.request().method() === 'POST' && /minn-admin\/v1\/me\/appearance/.test( r.url() ), { timeout: 20000 } ).catch( () => null );
		await page.click( '#minn-wp-fonts' );
		const mr = await meRes;
		const meBody = mr ? JSON.parse( mr.request().postData() || '{}' ) : null;
		t.check( '#9 a profile font switch sends the font alone', !! meBody && JSON.stringify( Object.keys( meBody ) ) === '["font"]', JSON.stringify( meBody ) );
		t.check( '#9 the other session\'s Minn bar choice stays', !! mr && ( metaGet( ADMIN_ID ) || {} ).frontBar === ! mine.frontBar );

		await page.goto( `${ BASE }/minn-admin/users/${ EDITOR_ID }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ue-default-admin', { timeout: 15000 } );
		await page.waitForTimeout( 800 );
		const theirs = metaGet( EDITOR_ID ) || {};
		metaSet( EDITOR_ID, { ...theirs, frontBar: ! theirs.frontBar, font: 'wordpress' === theirs.font ? 'minn' : 'wordpress' } );
		const ueRes = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( 'users/' + EDITOR_ID + '/appearance' ).test( r.url() ), { timeout: 20000 } ).catch( () => null );
		await page.click( '#minn-ue-default-admin' );
		const ur = await ueRes;
		const ueBody = ur ? JSON.parse( ur.request().postData() || '{}' ) : null;
		t.check( '#9 the user page sends only the switch it flipped, never frontBar', !! ueBody && JSON.stringify( Object.keys( ueBody ) ) === '["defaultAdmin"]', JSON.stringify( ueBody ) );
		const after = metaGet( EDITOR_ID ) || {};
		t.check( '#9 the user\'s own bar and font choices stay', !! ur && after.frontBar === ! theirs.frontBar && after.font === ( 'wordpress' === theirs.font ? 'minn' : 'wordpress' ), JSON.stringify( after ) );

		/* ===== #17 rich-text field modal ===== */
		const HOST = new URL( BASE ).host;
		const NOTES = '<p>Write to <a href="mailto:hello@example.com">us</a>, call <a href="tel:+15551234567">us</a>, see <a href="#faq">FAQ</a> or <a href="../docs/">docs</a>.</p>'
			+ `<p><img src="//${ HOST }/wp-includes/images/w-logo-blue.png" alt="logo"><img src="data:image/png;base64,iVBORw0KGgo=" alt="dot"></p>`
			+ '<p><a href="javascript:window.__minnRt=1">bad</a><img src="/wp-includes/images/x.png" onerror="window.__minnRt2=1" alt=""></p>';
		const rid = await make( { title: 'Rich text field probe', content: '<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->' } );
		await rest( `wp/v2/posts/${ rid }`, { method: 'POST', body: { minn_acf: { slideshow_notes: NOTES } } } );
		const notesOf = async () => {
			const p = await rest( `wp/v2/posts/${ rid }?context=edit&_fields=minn_acf&_cb=${ Date.now() }` );
			return p && p.minn_acf ? p.minn_acf.slideshow_notes : undefined;
		};
		const v0 = await notesOf();
		t.check( '#17 fixture field holds every link shape', 'string' === typeof v0 && [ 'mailto:', 'tel:', '#faq', '../docs/', 'data:image' ].every( ( s ) => v0.indexOf( s ) !== -1 ), String( v0 ).slice( 0, 200 ) );
		const rtSel = '[data-pf$=":slideshow_notes"][data-ftype="wysiwyg"]';
		const openField = async () => {
			if ( ! await page.$( rtSel ) ) {
				await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
				await page.click( '[data-side-door="panel:acf"]' );
			}
			await page.waitForSelector( `${ rtSel } [data-rt-edit]`, { timeout: 15000 } );
			await page.click( `${ rtSel } [data-rt-edit]` );
			await page.waitForSelector( '.minn-rt-body', { timeout: 10000 } );
		};
		await openEditor( page, rid );
		await openField();
		const inert = await page.evaluate( () => ( {
			liveJs: !! document.querySelector( '.minn-rt-body a[href^="javascript"]' ),
			liveHandler: !! document.querySelector( '.minn-rt-body [onerror]' ),
			mailto: !! document.querySelector( '.minn-rt-body a[href^="mailto:"]' ),
		} ) );
		await page.waitForTimeout( 600 );
		t.check( '#17 the modal shows the stored links and runs none of the stored script', inert.mailto && ! inert.liveJs && ! inert.liveHandler
			&& await page.evaluate( () => undefined === window.__minnRt2 ), JSON.stringify( inert ) );
		await page.click( '#minn-rt-apply' );
		await page.waitForSelector( rtSel, { timeout: 10000 } );
		await page.waitForTimeout( 400 );
		await saveNow( rid );
		const v1 = await notesOf();
		t.check( '#17 Apply with no edit leaves the field byte for byte', v1 === v0, String( v1 ).slice( 0, 240 ) );
		await openField();
		await page.evaluate( () => {
			const b = document.querySelector( '.minn-rt-body' );
			const p = b.querySelector( 'p' );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			const s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
			b.focus();
		} );
		await page.keyboard.type( ' Thanks.' );
		await page.click( '#minn-rt-apply' );
		await page.waitForSelector( rtSel, { timeout: 10000 } );
		await page.waitForTimeout( 400 );
		await saveNow( rid );
		const v2 = String( await notesOf() );
		t.check( '#17 an edit keeps mailto:, tel:, #anchor, relative, protocol-relative and inline-image targets',
			v2.indexOf( 'Thanks.' ) !== -1 && [ 'href="mailto:hello@example.com"', 'href="tel:+15551234567"', 'href="#faq"', 'href="../docs/"', `src="//${ HOST }/`, 'src="data:image/png' ].every( ( s ) => v2.indexOf( s ) !== -1 ), v2.slice( 0, 300 ) );

		/* ===== #18 paste ===== */
		const ISLAND0 = '<!-- wp:latest-posts /-->';
		const sid = await make( { title: 'Paste island probe', content: ISLAND0 + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, sid );
		await page.waitForSelector( '.minn-block-island[data-island="0"][contenteditable="false"]', { timeout: 15000 } );
		await freshParagraph( page );
		await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const dt = new DataTransfer();
			dt.setData( 'text/html', '<p>before</p><div class="minn-block-island" data-island="0" data-block="core/shortcode"><div class="minn-island-preview"><p>See note</p></div></div><p>after</p>' );
			dt.setData( 'text/plain', 'before See note after' );
			body.dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
		} );
		await page.waitForTimeout( 500 );
		const pasted = await page.evaluate( () => ( {
			island0: document.querySelectorAll( '#minn-editor-body .minn-block-island[data-island="0"][contenteditable="false"]' ).length,
			shortcode: document.querySelectorAll( '#minn-editor-body .minn-shortcode-island[contenteditable="false"]' ).length,
			text: document.querySelector( '#minn-editor-body' ).textContent.indexOf( 'See note' ) !== -1,
		} ) );
		t.check( '#18 a pasted island marker becomes its visible text, not a copy of block 0', pasted.island0 === 1 && pasted.shortcode === 0 && pasted.text, JSON.stringify( pasted ) );
		await saveNow( sid );
		raw = await rawOf( sid );
		t.check( '#18 block 0 is saved once, as itself', raw.content.split( '<!-- wp:latest-posts' ).length === 2 && raw.content.indexOf( 'wp:shortcode' ) === -1, raw.content.slice( 0, 300 ) );

		/* ===== #19 island look-alikes ===== */
		const FAKE_BUTTONS = '<div class="minn-buttons-island" data-island="0"><div class="minn-buttons-rows"></div><button class="minn-buttons-add">+ Add button</button></div>';
		const bid = await make( { title: 'Look-alike buttons probe', content: ISLAND0 + '\n\n' + FAKE_BUTTONS + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, bid );
		await page.waitForSelector( '.minn-block-island[data-island="0"][contenteditable="false"]', { timeout: 15000 } );
		await page.evaluate( () => {
			const b = document.querySelector( '#minn-editor-body .minn-buttons-add' );
			if ( b ) b.click();
		} );
		await typeInBody( 'y' );
		await saveNow( bid );
		raw = await rawOf( bid );
		t.check( '#19 a planted "+ Add button" card cannot turn block 0 into Buttons', raw.content.indexOf( '<!-- wp:latest-posts' ) !== -1 && raw.content.indexOf( '<!-- wp:buttons' ) === -1, raw.content.slice( 0, 300 ) );

		const DETAILS = '<!-- wp:details -->\n<details class="wp-block-details"><summary>Q</summary><!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details -->';
		const did = await make( { title: 'Wrapping marker probe', content: ISLAND0 + '\n\n<div class="minn-details-island" data-island="0">\n\n' + DETAILS + '\n\n</div>\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, did );
		await page.waitForSelector( '.minn-block-island[data-island="0"][contenteditable="false"]', { timeout: 15000 } );
		await typeInBody( 'z' );
		await saveNow( did );
		raw = await rawOf( did );
		t.check( '#19 a wrapping Details marker cannot copy a Details block over block 0', raw.content.indexOf( '<!-- wp:latest-posts' ) !== -1 && raw.content.split( '<!-- wp:details' ).length === 2, raw.content.slice( 0, 400 ) );

		const FIG = '<!-- wp:image -->\n<figure class="wp-block-image" data-minn-upload="u1"><img src="/wp-includes/images/w-logo-blue.png" alt="planted"/></figure>\n<!-- /wp:image -->';
		const uid = await make( { title: 'Upload marker probe', content: FIG + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->' } );
		await openEditor( page, uid );
		await typeInBody( 'w' );
		await saveNow( uid );
		raw = await rawOf( uid );
		t.check( '#19 content wearing data-minn-upload is saved, not dropped as a placeholder', raw.content.indexOf( 'alt="planted"' ) !== -1, raw.content.slice( 0, 300 ) );

		/* ===== #20 front end never runs in the app's origin ===== */
		const SCRIPT = '<!-- wp:html -->\n<script>try{parent.__minnFramePwned=1}catch(e){}try{top.__minnFramePwned=1}catch(e){}</script>\n<!-- /wp:html -->';
		const fid = await make( { title: 'Front-end frame probe', content: '<!-- wp:minn-test/runtime-css /-->\n\n' + SCRIPT, status: 'publish' } );
		await openEditor( page, fid );
		// Until the runtime sheet the front end pulls in has reached the
		// preview (the harvest ran), watching every frame on the way.
		const watch = await page.evaluate( async () => {
			let frames = 0;
			let harvested = false;
			const unsandboxed = new Set();
			for ( let i = 0; i < 150 && ! harvested; i++ ) {
				document.querySelectorAll( 'iframe' ).forEach( ( f ) => {
					frames++;
					if ( ! f.hasAttribute( 'sandbox' ) || /allow-same-origin/.test( f.getAttribute( 'sandbox' ) ) ) unsandboxed.add( f.src || f.className || 'iframe' );
				} );
				const fr = document.querySelector( '.minn-island-preview .minn-test-rt-frame' );
				harvested = !! fr && 'grid' === getComputedStyle( fr ).display;
				await new Promise( ( r ) => setTimeout( r, 400 ) );
			}
			// A little longer: anything the script could still do lands now.
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
			return { frames, harvested, unsandboxed: [ ...unsandboxed ], pwned: window.__minnFramePwned === 1 };
		} );
		t.check( '#20 the post\'s front end ran and its stylesheet was harvested (the check is not vacuous)', watch.harvested && watch.frames > 0, JSON.stringify( watch ) );
		t.check( '#20 every frame the editor opens is sandboxed without same-origin', watch.unsandboxed.length === 0, JSON.stringify( watch.unsandboxed ) );
		t.check( '#20 the post\'s script never reached the app', ! watch.pwned );

		/* ===== #6 post type description ===== */
		wp( [ 'eval', `$o = get_option( 'cptui_post_types', array() ); $base = is_array( $o ) && $o ? reset( $o ) : array(); $base['name'] = '${ CPT }'; $base['label'] = 'Desc Probes'; $base['singular_label'] = 'Desc Probe'; $base['description'] = "First line of the description.\\nSecond line, kept."; $o['${ CPT }'] = $base; update_option( 'cptui_post_types', $o );` ] );
		await page.goto( `${ BASE }/minn-admin/posttypes`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-cpt="${ CPT }"]`, { timeout: 20000 } );
		await page.click( `[data-cpt="${ CPT }"]` );
		await page.waitForSelector( '[data-cptfield="description"]', { timeout: 10000 } );
		const desc = await page.$eval( '[data-cptfield="description"]', ( el ) => ( { tag: el.tagName, value: el.value } ) );
		t.check( '#6 the description is a textarea that keeps its line break', desc.tag === 'TEXTAREA' && desc.value === 'First line of the description.\nSecond line, kept.', JSON.stringify( desc ) );
		let cptPost = null;
		const onReq = ( r ) => { if ( r.method() === 'POST' && new RegExp( 'post-types/' + CPT ).test( r.url() ) ) cptPost = r; };
		page.on( 'request', onReq );
		await page.click( '#minn-cpt-save' );
		await page.waitForTimeout( 1500 );
		t.check( '#6 a save with nothing changed sends nothing', null === cptPost, cptPost ? cptPost.postData() : '' );
		cptPost = null;
		// Whatever that save did, start the edit from a fresh modal.
		await page.waitForFunction( () => ! document.querySelector( '#minn-cpt-save[disabled]' ), null, { timeout: 20000 } ).catch( () => {} );
		await page.goto( `${ BASE }/minn-admin/posttypes`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-cpt="${ CPT }"]`, { timeout: 20000 } );
		await page.click( `[data-cpt="${ CPT }"]` );
		await page.waitForSelector( '[data-cptfield="description"]', { timeout: 10000 } );
		await page.$eval( '[data-cptfield="description"]', ( el ) => {
			el.value = el.value.replace( 'kept', 'still kept' );
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		const cptSave = page.waitForRequest( ( r ) => r.method() === 'POST' && new RegExp( 'post-types/' + CPT ).test( r.url() ), { timeout: 10000 } ).catch( () => null );
		await page.click( '#minn-cpt-save' );
		const cr = await cptSave;
		page.off( 'request', onReq );
		const cBody = cr ? JSON.parse( cr.postData() || '{}' ) : {};
		t.check( '#6 an edited description is sent with its line break', cBody.description === 'First line of the description.\nSecond line, still kept.' && ! ( 'plural' in cBody ) && ! ( 'singular' in cBody ), JSON.stringify( cBody ) );
		await page.waitForTimeout( 1200 );
	} finally {
		for ( const id of posts ) await deletePost( page, id );
		posts.length = 0;
		restore();
	}

	await t.done( browser, errors.filter( ( e ) => ! foreign.has( e ) ) );
} )().catch( ( e ) => {
	console.log( 'FAIL  suite crashed: ' + ( e && e.stack ? e.stack : e ) );
	process.exit( 1 );
} );
