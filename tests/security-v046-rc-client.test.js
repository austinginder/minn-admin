/**
 * v0.46.0 RC audit, the client half:
 *
 *   16-1 A block's server render lands only in that island's own preview.
 *        Post kses keeps class and data-*, so a Contributor can put
 *        <span class="minn-island-preview" data-preview="K"> ahead of
 *        dynamic block K: when an Editor opened the post, block K's render
 *        (as the EDITOR sees it: a sign-out link carrying the Editor's own
 *        nonce) went into the look-alike, and the Editor's save stored it in
 *        the Contributor's post. Both writers are covered: the render on
 *        open and the inspector's Apply. So are the passes that write into
 *        every preview by sweeping them: a theme change stamped data-theme
 *        onto the look-alike and a broken image in it took Minn's
 *        missing-image class, and both were saved.
 *   16-2 A Details, Buttons or Shortcode block nobody edited keeps its bytes
 *        when the post is saved. The live-field rebuilds ran on every save
 *        and dropped Gutenberg's inner block comments, the details anchor
 *        and colour classes, summary and label markup, a custom rel, a lock
 *        on the block comment and a multi-line shortcode's line breaks. A
 *        field typed into and put back keeps them too; a real edit still
 *        saves.
 *   02-I1 The wp-config switch's "late" lock (a define after the line that
 *        loads WordPress) no longer reads "defined elsewhere".
 *
 * Every 16-1 and 16-2 check on a save, and the label check, fails on the RC
 * app.js (452ef6cb). Posts are seeded through REST as the role that writes
 * them: a Contributor this suite creates, and the lab's Author and Editor.
 * onExit() removes the posts and the Contributor however the run ends.
 *
 * Run: cd tests && MINN_TEST_PASS=… node security-v046-rc-client.test.js
 */
const { execFileSync } = require( 'child_process' );
const crypto = require( 'crypto' );
const fs = require( 'fs' );
const path = require( 'path' );
const { launch, login, loginAs, createPost, openEditor, freshParagraph, reporter, onExit, BASE, WP } = require( './helpers' );

const wp = ( args ) => {
	try {
		return execFileSync( 'wp', [ ...args, '--path=' + WP, '--skip-plugins', '--skip-themes' ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	} catch ( e ) {
		return ( e && e.stdout ) || '';
	}
};
const lastLine = ( out ) => String( out || '' ).trim().split( '\n' ).pop().trim();

const CONTRIB = 'minn-rc-client-contrib';
const CONTRIB_PASS = 'rc-' + crypto.randomBytes( 12 ).toString( 'hex' );
const contribId = () => {
	const n = parseInt( lastLine( wp( [ 'user', 'get', CONTRIB, '--field=ID' ] ) ), 10 );
	return Number.isInteger( n ) ? n : 0;
};
// Only the account this suite makes: its login and its one role both match.
const removeContrib = () => {
	const id = contribId();
	if ( ! ( Number.isInteger( id ) && id > 1 ) ) return;
	if ( lastLine( wp( [ 'user', 'get', String( id ), '--field=user_login' ] ) ) !== CONTRIB ) return;
	if ( lastLine( wp( [ 'user', 'get', String( id ), '--field=roles' ] ) ) !== 'contributor' ) return;
	wp( [ 'user', 'delete', String( id ), '--yes' ] );
};

// The same console filter launch() gives the admin page, for the role pages.
const ignorable = ( text ) => /Failed to load resource/.test( text )
	|| /violates the following Content Security Policy directive: "(?:img-src data: cid: blob:|style-src 'unsafe-inline'|font-src data:|media-src data:|default-src 'none')"/.test( text )
	|| /^Permissions policy violation: compute-pressure is not allowed in this document\.$/.test( text );

( async () => {
	const t = reporter( 'security-v046-rc-client' );

	/* ===== 02-I1: the lock label, statically (no browser) ===== */
	// Every lock kind the server emits gets a label of its own; only
	// 'elsewhere' (defined outside wp-config.php) says so.
	const app = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' );
	const restSrc = fs.readFileSync( path.join( __dirname, '..', 'includes', 'class-minn-admin-rest.php' ), 'utf8' );
	const fnAt = app.indexOf( 'function configLockLabel(' );
	const fnSrc = fnAt < 0 ? '' : app.slice( fnAt, app.indexOf( '\n\t}\n', fnAt ) + 3 );
	// eslint-disable-next-line no-new-func
	const configLockLabel = fnSrc ? new Function( '__', fnSrc + '\nreturn configLockLabel;' )( ( s ) => s ) : () => '';
	// The kinds: what config_define_scan() answers, and what config_switch()
	// sets as the lock (every kind but absent and bool, plus 'elsewhere').
	const phpFn = ( name ) => {
		const at = restSrc.indexOf( `function ${ name }(` );
		const end = at < 0 ? -1 : restSrc.indexOf( '\n\t}\n', at );
		return at < 0 ? '' : restSrc.slice( at, end < 0 ? undefined : end );
	};
	const kinds = new Set();
	( phpFn( 'config_define_scan' ).match( /'kind'\s*=>\s*'[a-z_]+'/g ) || [] ).forEach( ( m ) => kinds.add( m.replace( /^.*'([a-z_]+)'$/, '$1' ) ) );
	phpFn( 'config_switch' ).split( '\n' ).filter( ( l ) => /\$lock\s*=/.test( l ) ).forEach( ( l ) => ( l.match( /'[a-z_]+'/g ) || [] ).forEach( ( q ) => kinds.add( q.slice( 1, -1 ) ) ) );
	[ 'absent', 'bool' ].forEach( ( k ) => kinds.delete( k ) );
	t.check( '02-I1 the server\'s lock kinds were read', kinds.has( 'elsewhere' ) && kinds.has( 'late' ) && kinds.size >= 5, [ ...kinds ].join( ', ' ) );
	const mislabelled = [ ...kinds ].filter( ( k ) => 'elsewhere' !== k && 'defined elsewhere' === configLockLabel( { lock: k } ) );
	t.check( '02-I1 no lock but "elsewhere" reads "defined elsewhere"', 0 === mislabelled.length, mislabelled.join( ', ' ) );
	t.check( '02-I1 the late lock says the define comes after WordPress loads', /after .*loads WordPress/i.test( configLockLabel( { lock: 'late' } ) ), configLockLabel( { lock: 'late' } ) );
	t.check( '02-I1 a define outside wp-config.php still reads "defined elsewhere"', 'defined elsewhere' === configLockLabel( { lock: 'elsewhere' } ) );

	// Restores, armed before anything changes. A Contributor left by a run
	// that was killed is removed first.
	const made = { posts: [] };
	const restore = onExit( () => {
		const posts = made.posts.filter( ( id ) => Number.isInteger( id ) && id > 1 );
		if ( posts.length ) wp( [ 'post', 'delete', ...posts.map( String ), '--force' ] );
		removeContrib();
	} );
	removeContrib();
	wp( [ 'user', 'create', CONTRIB, CONTRIB + '@example.com', '--role=contributor', '--user_pass=' + CONTRIB_PASS, '--display_name=RC client contributor' ] );
	const CONTRIB_ID = contribId();

	const { browser, page, errors } = await launch();
	const watch = ( p, who ) => {
		p.on( 'pageerror', ( e ) => errors.push( `${ who } pageerror: ${ e.message }` ) );
		p.on( 'console', ( m ) => {
			if ( m.type() === 'error' && ! ignorable( m.text() ) ) errors.push( `${ who } console: ${ m.text() }` );
		} );
	};
	try {
		t.check( 'the suite\'s Contributor exists', CONTRIB_ID > 1, String( CONTRIB_ID ) );
		await login( page );
		// A loaded lab can miss a sign-in's 20-second window; try again.
		const signIn = async ( user, pass, who ) => {
			for ( let attempt = 1; ; attempt++ ) {
				try {
					const s = await loginAs( browser, user, pass );
					watch( s.page, who );
					return s;
				} catch ( e ) {
					if ( attempt >= 3 ) throw e;
					console.log( `  (${ who } sign-in retry)` );
				}
			}
		};
		const contrib = await signIn( CONTRIB, CONTRIB_PASS, 'contributor' );
		const editor = await signIn( 'minn-editor', 'minn-editor-pass-1', 'editor' );
		const author = await signIn( 'minn-author', 'minn-author-pass-1', 'author' );

		const rest = ( p, route, opts = {} ) => p.evaluate( async ( a ) => {
			const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
				method: a.method || 'GET',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: a.body ? JSON.stringify( a.body ) : undefined,
			} );
			return { status: r.status, body: await r.json().catch( () => null ) };
		}, { route, ...opts } );
		const make = async ( p, args ) => {
			const id = await createPost( p, { status: 'draft', ...args } );
			made.posts.push( id );
			return id;
		};
		const stored = async ( id ) => {
			const r = await rest( page, `wp/v2/posts/${ id }?context=edit&_fields=content,author` );
			return r.body && r.body.content ? { content: r.body.content.raw, author: r.body.author } : { content: '', author: 0 };
		};
		const renderDone = ( p ) => p.waitForResponse( ( res ) => res.request().method() === 'POST' && /minn-admin\/v1\/render-blocks/.test( res.url() ), { timeout: 30000 } ).catch( () => null );
		const open = async ( p, id ) => {
			const rendered = renderDone( p );
			await openEditor( p, id );
			await rendered;
			await p.waitForTimeout( 500 );
		};
		const saveNow = async ( p, id ) => {
			const done = p.waitForResponse( ( res ) => res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id + '(\\?|$)' ).test( res.url() ), { timeout: 30000 } ).catch( () => null );
			await p.keyboard.press( 'Meta+s' );
			const r = await done;
			await p.waitForTimeout( 500 );
			return r;
		};
		const typeElsewhere = async ( p, text ) => {
			await freshParagraph( p );
			await p.keyboard.type( text );
		};
		// The real island for a block name (Minn renders contenteditable=false,
		// which kses never lets content carry) and what its own preview holds.
		const islands = ( p ) => p.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			return [ ...body.querySelectorAll( '.minn-block-island' ) ]
				.filter( ( el ) => 'false' === el.getAttribute( 'contenteditable' ) && ! ( el.parentElement && el.parentElement.closest( '.minn-island-preview' ) ) )
				.map( ( el ) => {
					const own = el.querySelector( ':scope > .minn-island-preview' );
					return { idx: el.dataset.island, block: el.dataset.block, own: own ? own.innerHTML : null };
				} );
		} );

		/* ===== 16-1 the render on open ===== */
		const LOOKALIKE = '<!-- wp:paragraph -->\n<p>Lead <span class="minn-island-preview" data-preview="0">planted</span> tail</p>\n<!-- /wp:paragraph -->\n\n'
			+ '<!-- wp:loginout /-->\n\n'
			+ '<!-- wp:paragraph -->\n<p>End.</p>\n<!-- /wp:paragraph -->';
		const p1 = await make( contrib.page, { title: 'RC 16-1 open', content: LOOKALIKE } );
		const seeded1 = await stored( p1 );
		t.check( '16-1 kses kept the Contributor\'s look-alike (the precondition)', /<span class="minn-island-preview" data-preview="0">planted<\/span>/.test( seeded1.content ) && seeded1.author === CONTRIB_ID, seeded1.content.slice( 0, 160 ) );

		await open( editor.page, p1 );
		const planted1 = () => editor.page.evaluate( () => {
			const s = document.querySelector( '#minn-editor-body p span.minn-island-preview' );
			return s ? s.innerHTML : null;
		} );
		let look = await planted1();
		let isl = ( await islands( editor.page ) ).find( ( i ) => /loginout/.test( i.block || '' ) );
		t.check( '16-1 the render does not land in the look-alike when an Editor opens the post', 'planted' === look, String( look ).slice( 0, 160 ) );
		t.check( '16-1 the block\'s own preview shows its render (the Editor\'s view still works)', !! isl && /wp-block-loginout/.test( isl.own || '' ), JSON.stringify( isl ).slice( 0, 200 ) );
		await typeElsewhere( editor.page, 'Reviewed.' );
		await saveNow( editor.page, p1 );
		let s = await stored( p1 );
		t.check( '16-1 the Editor\'s save stores no render (no sign-out link, no nonce) in the Contributor\'s text', s.content.indexOf( 'action=logout' ) === -1 && s.content.indexOf( 'wp-block-loginout' ) === -1 && /Reviewed\./.test( s.content ), s.content.slice( 0, 300 ) );
		t.check( '16-1 the look-alike keeps its own text and the block stays a block', /<span class="minn-island-preview" data-preview="0">planted<\/span>/.test( s.content ) && /<!-- wp:loginout \/-->/.test( s.content ) && s.author === CONTRIB_ID, s.content.slice( 0, 300 ) );

		/* ===== 16-1 the inspector's Apply ===== */
		// The render on open is answered empty here, so only Apply renders.
		const p2 = await make( contrib.page, { title: 'RC 16-1 apply', content: LOOKALIKE } );
		let firstRender = true;
		const emptyFirst = ( route ) => {
			if ( firstRender && 'POST' === route.request().method() ) {
				firstRender = false;
				return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { rendered: [], styles: [] } ) } ).catch( () => {} );
			}
			return route.continue().catch( () => {} );
		};
		await editor.page.route( /minn-admin\/v1\/render-blocks/, emptyFirst );
		await open( editor.page, p2 );
		await editor.page.unroute( /minn-admin\/v1\/render-blocks/, emptyFirst );
		const chipOk = await editor.page.evaluate( () => {
			const isl2 = [ ...document.querySelectorAll( '#minn-editor-body .minn-block-island' ) ]
				.find( ( el ) => 'false' === el.getAttribute( 'contenteditable' ) && /loginout/.test( el.dataset.block || '' ) );
			const chip = isl2 && isl2.querySelector( ':scope > .minn-island-chip' );
			if ( chip ) chip.click();
			return !! chip;
		} );
		// The inspector reads the block's schema first, which a loaded lab
		// can take a while to answer.
		const applyBtn = await editor.page.waitForSelector( '#minn-insp-apply', { timeout: 45000 } ).catch( () => null );
		t.check( '16-1 the inspector opens with Apply for the dynamic block', chipOk && !! applyBtn, `chip ${ chipOk }, apply ${ !! applyBtn }` );
		if ( applyBtn ) {
			const applied = renderDone( editor.page );
			await editor.page.click( '#minn-insp-apply' );
			await applied;
			await editor.page.waitForTimeout( 500 );
		}
		look = await editor.page.evaluate( () => ( document.querySelector( '#minn-editor-body p span.minn-island-preview' ) || {} ).innerHTML );
		isl = ( await islands( editor.page ) ).find( ( i ) => /loginout/.test( i.block || '' ) );
		t.check( '16-1 Apply renders into the block\'s own preview, not the look-alike', 'planted' === look && !! isl && /wp-block-loginout/.test( isl.own || '' ), String( look ).slice( 0, 160 ) );
		await typeElsewhere( editor.page, 'Applied.' );
		await saveNow( editor.page, p2 );
		s = await stored( p2 );
		t.check( '16-1 the save after Apply stores no render in the Contributor\'s text', s.content.indexOf( 'action=logout' ) === -1 && s.content.indexOf( 'wp-block-loginout' ) === -1 && /Applied\./.test( s.content ), s.content.slice( 0, 300 ) );

		// Control: an administrator opening the first post (the Editor left it,
		// so its lock is gone) sees the same.
		await editor.page.waitForTimeout( 1500 );
		await open( page, p1 );
		isl = ( await islands( page ) ).find( ( i ) => /loginout/.test( i.block || '' ) );
		look = await page.evaluate( () => ( document.querySelector( '#minn-editor-body p span.minn-island-preview' ) || {} ).innerHTML );
		t.check( '16-1 an administrator: the render reaches the block, not the look-alike', !! isl && /wp-block-loginout/.test( isl.own || '' ) && 'planted' === look, String( look ).slice( 0, 120 ) );

		/* ===== 16-1 variants, and the sweeps over every preview ===== */
		// A look-alike inside another block's preview and one in a group's
		// typing slot, each naming a later block; a theme change; broken
		// images inside a preview look-alike and an image-tile look-alike.
		// Control: a real preview's broken image is still marked, and real
		// previews still take the theme.
		const MISS = ( k ) => `/wp-includes/images/minn-rc-client-missing-${ k }-${ Date.now() }.png`;
		const VARIANTS = '<!-- wp:minn-rc/probe -->\n<div class="wp-block-minn-rc-probe"><div class="minn-island-preview" data-preview="2">inside another block</div><img src="' + MISS( 'c' ) + '" alt="c"></div>\n<!-- /wp:minn-rc/probe -->\n\n'
			+ '<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>In a group <span class="minn-island-preview" data-preview="3">group text</span></p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n'
			+ '<!-- wp:loginout /-->\n\n'
			+ '<!-- wp:loginout {"displayLoginAsForm":false} /-->\n\n'
			+ '<!-- wp:paragraph -->\n<p>Theme <span class="minn-island-preview">shell</span> stamp</p>\n<!-- /wp:paragraph -->\n\n'
			+ '<!-- wp:paragraph -->\n<p>Pic <span class="minn-island-preview"><img src="' + MISS( 'a' ) + '" alt="a"></span> tile <span class="minn-imgedit-tile"><img src="' + MISS( 'b' ) + '" alt="b"></span></p>\n<!-- /wp:paragraph -->';
		const p3 = await make( contrib.page, { title: 'RC 16-1 variants', content: VARIANTS } );
		await open( editor.page, p3 );
		// Every broken image has failed before anything is judged.
		await editor.page.waitForFunction( () => [ ...document.querySelectorAll( '#minn-editor-body img' ) ].every( ( im ) => im.complete ), null, { timeout: 20000, polling: 250 } ).catch( () => {} );
		await editor.page.waitForTimeout( 600 );
		// A real preview loses its stamp first, so the theme pass must put it back.
		await editor.page.evaluate( () => {
			const real = [ ...document.querySelectorAll( '#minn-editor-body .minn-block-island' ) ]
				.find( ( el ) => 'false' === el.getAttribute( 'contenteditable' ) && /loginout/.test( el.dataset.block || '' ) );
			const own = real && real.querySelector( ':scope > .minn-island-preview' );
			if ( own ) own.removeAttribute( 'data-theme' );
			document.dispatchEvent( new Event( 'minn-theme-change' ) );
		} );
		await editor.page.waitForTimeout( 300 );
		const v = await editor.page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const real = [ ...body.querySelectorAll( '.minn-block-island' ) ].filter( ( el ) => 'false' === el.getAttribute( 'contenteditable' ) && ! ( el.parentElement && el.parentElement.closest( '.minn-island-preview' ) ) );
			const own = ( name, n ) => {
				const el = real.filter( ( i ) => ( i.dataset.block || '' ).indexOf( name ) !== -1 )[ n || 0 ];
				return el ? el.querySelector( ':scope > .minn-island-preview' ) : null;
			};
			const probe = own( 'minn-rc/probe' );
			const inProbe = probe && probe.querySelector( '.minn-island-preview' );
			const inGroup = body.querySelector( '.minn-slot p span.minn-island-preview' );
			const lo1 = own( 'loginout', 0 );
			const lo2 = own( 'loginout', 1 );
			const realImg = probe && probe.querySelector( 'img' );
			return {
				// Text, not markup: in-place editing wraps a preview's text in run spans.
				inProbe: inProbe ? inProbe.textContent : null,
				inGroup: inGroup ? inGroup.textContent : null,
				hasRender: !! ( inProbe && inProbe.querySelector( '.wp-block-loginout' ) ) || !! ( inGroup && inGroup.querySelector( '.wp-block-loginout' ) ),
				lo1: !! lo1 && /wp-block-loginout/.test( lo1.innerHTML ),
				lo2: !! lo2 && /wp-block-loginout/.test( lo2.innerHTML ),
				realImgMarked: !! realImg && realImg.classList.contains( 'minn-img-missing' ),
				realThemed: !! lo1 && lo1.hasAttribute( 'data-theme' ),
				islands: real.map( ( i ) => i.dataset.island + ':' + i.dataset.block ).join( ' ' ),
			};
		} );
		t.check( '16-1 a look-alike inside another block\'s preview does not take a later block\'s render', 'inside another block' === v.inProbe && ! v.hasRender && v.lo1, JSON.stringify( v ) );
		t.check( '16-1 a look-alike in a group\'s text does not take a later block\'s render', 'group text' === v.inGroup && ! v.hasRender && v.lo2, JSON.stringify( v ) );
		t.check( '16-1 control: a real preview\'s broken image is marked, and real previews take the theme', v.realImgMarked && v.realThemed, JSON.stringify( v ) );
		await typeElsewhere( editor.page, 'Variants.' );
		await saveNow( editor.page, p3 );
		s = await stored( p3 );
		const themePara = ( s.content.match( /<p>Theme [\s\S]*?<\/p>/ ) || [ '' ] )[ 0 ];
		const picPara = ( s.content.match( /<p>Pic [\s\S]*?<\/p>/ ) || [ '' ] )[ 0 ];
		t.check( '16-1 a theme change writes nothing into a look-alike in the text', !! themePara && ! /data-(root-)?theme/.test( themePara ), themePara );
		t.check( '16-1 broken images in preview and tile look-alikes are saved without Minn\'s class', !! picPara && picPara.indexOf( 'minn-img-missing' ) === -1 && /alt="a"/.test( picPara ) && /alt="b"/.test( picPara ), picPara );
		t.check( '16-1 the variants\' save stores no render anywhere', s.content.indexOf( 'action=logout' ) === -1 && s.content.indexOf( 'wp-block-loginout' ) === -1 && /Variants\./.test( s.content ), s.content.slice( 0, 200 ) );

		/* ===== 16-2 an untouched Details, Buttons or Shortcode block ===== */
		// Written the way Gutenberg writes them, by an Author in their own post
		// (kses applies): inner block comments, an anchor and colour classes,
		// summary and label markup, a custom rel, a lock, a multi-line body.
		const DETAILS = '<!-- wp:details {"backgroundColor":"pale-cyan-blue"} -->\n<details class="wp-block-details has-pale-cyan-blue-background-color has-background" id="returns"><summary><strong>Returns</strong> policy</summary><!-- wp:paragraph -->\n<p>Thirty days.</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details -->';
		const BUTTONS = '<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/deal" rel="nofollow sponsored">Learn <em>more</em></a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->';
		const LOCKED_SC = '<!-- wp:shortcode {"lock":{"move":true,"remove":true}} -->\n[minn_rc_probe id="1"]\n<!-- /wp:shortcode -->';
		const MULTI_SC = '<!-- wp:shortcode -->\n[minn_rc_probe a="1"\n  b="2"]\n<!-- /wp:shortcode -->';
		const GUTENBERG = [ '<!-- wp:paragraph -->\n<p>Intro.</p>\n<!-- /wp:paragraph -->', DETAILS, BUTTONS, LOCKED_SC, MULTI_SC ].join( '\n\n' );
		const blocksOf = ( content ) => ( {
			details: ( content.match( /<!-- wp:details[\s\S]*?<!-- \/wp:details -->/ ) || [ '' ] )[ 0 ],
			buttons: ( content.match( /<!-- wp:buttons[\s\S]*?<!-- \/wp:buttons -->/ ) || [ '' ] )[ 0 ],
			lockedSc: ( content.match( /<!-- wp:shortcode \{"lock"[\s\S]*?<!-- \/wp:shortcode -->/ ) || [ '' ] )[ 0 ],
			multiSc: ( content.match( /<!-- wp:shortcode -->\n\[minn_rc_probe a[\s\S]*?<!-- \/wp:shortcode -->/ ) || [ '' ] )[ 0 ],
		} );
		const p4 = await make( author.page, { title: 'RC 16-2 untouched', content: GUTENBERG } );
		const before = blocksOf( ( await stored( p4 ) ).content );
		t.check( '16-2 the Author\'s Gutenberg blocks are stored as written (the precondition)', before.details.indexOf( '<!-- wp:paragraph -->' ) !== -1 && before.details.indexOf( '<strong>Returns</strong>' ) !== -1
			&& before.buttons.indexOf( 'rel="nofollow sponsored"' ) !== -1 && before.buttons.indexOf( '<em>more</em>' ) !== -1 && !! before.lockedSc && /\n {2}b="2"\]/.test( before.multiSc ), JSON.stringify( before ).slice( 0, 400 ) );
		await open( author.page, p4 );
		await typeElsewhere( author.page, 'Author note.' );
		await saveNow( author.page, p4 );
		let after = blocksOf( ( await stored( p4 ) ).content );
		t.check( '16-2 a save keeps an untouched Details block byte for byte (inner comments, anchor, classes, summary markup)', !! before.details && after.details === before.details, after.details );
		t.check( '16-2 a save keeps an untouched Buttons block byte for byte (custom rel, label markup)', !! before.buttons && after.buttons === before.buttons, after.buttons );
		t.check( '16-2 a save keeps a Shortcode block\'s lock', !! before.lockedSc && after.lockedSc === before.lockedSc, after.lockedSc || '(lock gone)' );
		t.check( '16-2 a save keeps a multi-line Shortcode block\'s line breaks', !! before.multiSc && after.multiSc === before.multiSc, JSON.stringify( after.multiSc || '(rewritten)' ) );

		// Typed into and put back: still untouched.
		const typeAndUndo = async ( p, sel ) => {
			await p.click( sel );
			await p.keyboard.press( 'End' );
			await p.keyboard.type( 'x' );
			await p.keyboard.press( 'Backspace' );
		};
		await open( author.page, p4 );
		await typeAndUndo( author.page, '#minn-editor-body .minn-details-island .minn-details-summary' );
		await typeAndUndo( author.page, '#minn-editor-body .minn-buttons-island .minn-btn-label' );
		await typeAndUndo( author.page, '#minn-editor-body .minn-shortcode-island .minn-shortcode-input' );
		await typeElsewhere( author.page, 'Second note.' );
		await saveNow( author.page, p4 );
		after = blocksOf( ( await stored( p4 ) ).content );
		t.check( '16-2 fields typed into and put back keep the stored bytes', after.details === before.details && after.buttons === before.buttons && after.lockedSc === before.lockedSc, JSON.stringify( after ).slice( 0, 400 ) );

		// Control: the Author's real edits still save.
		await open( author.page, p4 );
		await author.page.click( '#minn-editor-body .minn-details-island .minn-details-body p' );
		await author.page.keyboard.press( 'End' );
		await author.page.keyboard.type( ' Ask us.' );
		await author.page.click( '#minn-editor-body .minn-details-island .minn-details-summary' );
		await author.page.keyboard.press( 'End' );
		await author.page.keyboard.type( ' (updated)' );
		await author.page.click( '#minn-editor-body .minn-buttons-island .minn-btn-label' );
		await author.page.keyboard.press( 'End' );
		await author.page.keyboard.type( ' now' );
		await author.page.click( '#minn-editor-body .minn-shortcode-island .minn-shortcode-input' );
		await author.page.keyboard.press( 'End' );
		await author.page.keyboard.press( 'ArrowLeft' );
		await author.page.keyboard.type( ' c="3"' );
		await saveNow( author.page, p4 );
		after = blocksOf( ( await stored( p4 ) ).content );
		t.check( '16-2 control: an edited Details summary and body save', /<summary>Returns policy \(updated\)<\/summary>/.test( after.details ) && /Thirty days\.(?: |&nbsp;|\u00a0)Ask us\./.test( after.details ), after.details );
		t.check( '16-2 control: an edited button label saves, its link kept', />Learn more now<\/a>/.test( after.buttons ) && after.buttons.indexOf( 'href="https://example.com/deal"' ) !== -1, after.buttons );
		const edited4 = ( await stored( p4 ) ).content;
		t.check( '16-2 control: an edited shortcode saves', /<!-- wp:shortcode[^>]*-->\n\[minn_rc_probe id="1" c="3"\]\n<!-- \/wp:shortcode -->/.test( edited4 ), edited4.slice( -400 ) );

		// A lower role's blocks, saved by the Editor reviewing them.
		const p5 = await make( contrib.page, { title: 'RC 16-2 planted', content: [ DETAILS, BUTTONS ].join( '\n\n' ) } );
		const planted5 = blocksOf( ( await stored( p5 ) ).content );
		await open( editor.page, p5 );
		await typeElsewhere( editor.page, 'Looks good.' );
		await saveNow( editor.page, p5 );
		const after5 = blocksOf( ( await stored( p5 ) ).content );
		t.check( '16-2 the Editor\'s save keeps a Contributor\'s untouched Details and Buttons blocks', !! planted5.details && after5.details === planted5.details && !! planted5.buttons && after5.buttons === planted5.buttons, JSON.stringify( after5 ).slice( 0, 400 ) );

		/* ===== 02-I1 in the app ===== */
		// The System page drawn from a config read that carries each lock kind.
		const sysRe = /minn-admin\/v1\/system(?![/a-z])/;
		await page.route( sysRe, async ( route ) => {
			if ( 'GET' !== route.request().method() ) return route.continue().catch( () => {} );
			try {
				const res = await route.fetch();
				const j = await res.json();
				j.config = { ...( j.config || {} ), editable: true, constants: [ ...kinds ].map( ( k ) => ( { name: 'MINN_RC_' + k.toUpperCase(), label: 'Probe ' + k, desc: '', value: false, in_config: 'elsewhere' !== k, locked: true, lock: k } ) ) };
				await route.fulfill( { response: res, json: j } );
			} catch ( e ) {
				await route.continue().catch( () => {} );
			}
		} );
		await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
		const labels = await page.waitForFunction( () => {
			const rows = [ ...document.querySelectorAll( '.minn-sys-toggle' ) ];
			if ( ! rows.length ) return null;
			const out = {};
			rows.forEach( ( r ) => {
				const name = ( r.querySelector( '.minn-sys-const' ) || {} ).textContent || '';
				out[ name ] = ( r.querySelector( '.minn-sys-locked' ) || {} ).textContent || '';
			} );
			return out;
		}, null, { timeout: 45000, polling: 300 } ).then( ( h ) => h.jsonValue() ).catch( () => null );
		await page.unroute( sysRe );
		t.check( '02-I1 the System page labels the late lock as a define after WordPress loads', !! labels && /after .*loads WordPress/i.test( labels.MINN_RC_LATE || '' ) && 'defined elsewhere' === labels.MINN_RC_ELSEWHERE, JSON.stringify( labels ) );
	} catch ( e ) {
		t.check( 'suite ran to the end', false, e && e.stack ? e.stack.split( '\n' ).slice( 0, 3 ).join( ' | ' ) : String( e ) );
	} finally {
		restore();
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
