/**
 * Nested content endgame (editor-direction handoff items 4+5): containers
 * with COMPLEX children become slot islands whose complex children render
 * as protected nested islands, and containers nest (group in group). The
 * corpus said mixed containers are the norm — spacer + buttons are the
 * real-world complex leaves, so they're the fixtures here. Byte-identity
 * for everything untouched stays the non-negotiable invariant.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'nested-islands' );
	await login( page );

	const CONTENT = [
		// Group A: prose + spacer + buttons (the corpus's dominant leaves).
		'<!-- wp:group {"layout":{"type":"constrained"}} -->',
		'<div class="wp-block-group"><!-- wp:paragraph -->',
		'<p>Mixed group intro.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:spacer {"height":"40px"} -->',
		'<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>',
		'<!-- /wp:spacer -->',
		'',
		'<!-- wp:buttons -->',
		'<div class="wp-block-buttons"><!-- wp:button -->',
		'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">Press me</a></div>',
		'<!-- /wp:button --></div>',
		'<!-- /wp:buttons -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Mixed group outro.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:group -->',
		'',
		// Group B: a group INSIDE a group (nested container), untouched.
		'<!-- wp:group {"layout":{"type":"flow"}} -->',
		'<div class="wp-block-group"><!-- wp:paragraph -->',
		'<p>Outer prose.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"10px"}}}} -->',
		'<div class="wp-block-group" style="padding-top:10px"><!-- wp:heading {"level":3} -->',
		'<h3 class="wp-block-heading">Inner heading</h3>',
		'<!-- /wp:heading -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Inner prose.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:group --></div>',
		'<!-- /wp:group -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Top closer.</p>',
		'<!-- /wp:paragraph -->',
	].join( '\n' );

	const id = await createPost( page, { title: 'Nested islands ' + Date.now(), content: CONTENT, status: 'draft' } );
	t.check( 'created fixture', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-slot', { timeout: 25000 } );
	await page.waitForTimeout( 1500 );

	const rawOf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async ( expectFn ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 900 );
			const raw = await rawOf();
			if ( ! expectFn || expectFn( raw ) ) return raw;
		}
		return rawOf();
	};
	const groupA = ( r ) => r.slice( 0, r.indexOf( '<!-- wp:group {"layout":{"type":"flow"}}' ) );
	const groupB = ( r ) => r.slice( r.indexOf( '<!-- wp:group {"layout":{"type":"flow"}}' ), r.lastIndexOf( '<!-- wp:paragraph -->' ) );

	/* ===== Shape: mixed group is a slot with nested islands ===== */
	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const slotIslands = [ ...body.querySelectorAll( '.minn-slot-island' ) ];
		const outerA = slotIslands[ 0 ];
		const aSlot = outerA && outerA.querySelector( '.minn-slot' );
		const nested = aSlot ? [ ...aSlot.querySelectorAll( ':scope > .minn-block-island' ) ] : [];
		const outerB = body.querySelector( '.minn-slot .minn-slot-island' );
		return {
			slotIslands: slotIslands.length,
			aNested: nested.map( ( n ) => n.dataset.block ),
			aProse: aSlot ? aSlot.querySelectorAll( ':scope > p' ).length : 0,
			buttonsLive: !! ( aSlot && aSlot.querySelector( '.minn-block-island .minn-btn-label, .minn-block-island .minn-buttons-island' ) ),
			nestedGroup: !! outerB,
			innerSlotEditable: !! ( outerB && outerB.querySelector( '.minn-slot' )?.isContentEditable ),
			innerH3: outerB ? outerB.querySelector( '.minn-slot > h3' )?.textContent : '',
		};
	} );
	t.check( 'mixed group renders as a slot island', shape.slotIslands >= 2 && shape.aProse === 2, JSON.stringify( shape ) );
	t.check( 'spacer + buttons are nested islands inside the slot', shape.aNested.join( ',' ) === 'spacer,buttons', JSON.stringify( shape.aNested ) );
	t.check( 'group-in-group nests with an editable inner slot', shape.nestedGroup && shape.innerSlotEditable && shape.innerH3 === 'Inner heading', JSON.stringify( shape ) );

	/* ===== Byte-identity: open + save with no edits. The buttons live-field
	 * island normalizes its own raw on first commit (adds the {"url"} attr —
	 * the established top-level first-save fixed point); everything else,
	 * nested group wrappers included, must be byte-identical. ===== */
	const FIXED = CONTENT.replace( '<!-- wp:button -->', '<!-- wp:button {"url":"https://example.com"} -->' );
	let raw = await save();
	t.check( 'untouched nested content re-saves byte-identical (buttons fixed point aside)',
		raw === CONTENT || raw === FIXED, raw === CONTENT || raw === FIXED ? '' : raw.slice( 0, 300 ) );

	/* ===== Outer prose edit; nested island bytes stay verbatim ===== */
	await page.click( '.minn-slot-island .minn-slot > p' );
	await page.evaluate( () => {
		const p = document.querySelector( '.minn-slot-island .minn-slot > p' );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.type( ' Edited.' );
	raw = await save( ( r ) => r.includes( 'Mixed group intro. Edited.' ) );
	t.check( 'outer slot edit saved', raw.includes( 'Mixed group intro. Edited.' ), '' );
	t.check( 'nested spacer bytes verbatim after sibling edit',
		groupA( raw ).includes( '<!-- wp:spacer {"height":"40px"} -->\n<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>\n<!-- /wp:spacer -->' ), groupA( raw ) );
	t.check( 'nested buttons bytes verbatim after sibling edit',
		groupA( raw ).includes( 'wp-block-button__link wp-element-button" href="https://example.com">Press me</a>' ), '' );

	/* ===== Inner-group edit routes through BOTH containers ===== */
	await page.click( '.minn-slot .minn-slot-island .minn-slot > p' );
	await page.evaluate( () => {
		const p = document.querySelector( '.minn-slot .minn-slot-island .minn-slot > p' );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.type( ' Deep edit.' );
	raw = await save( ( r ) => r.includes( 'Inner prose. Deep edit.' ) );
	const gb = groupB( raw );
	t.check( 'inner-group edit saved inside BOTH wrappers',
		gb.includes( 'Inner prose. Deep edit.' )
		&& gb.startsWith( '<!-- wp:group {"layout":{"type":"flow"}} -->\n<div class="wp-block-group">' )
		&& gb.includes( '<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"10px"}}}} -->\n<div class="wp-block-group" style="padding-top:10px">' ),
		gb.slice( 0, 400 ) );
	t.check( 'outer prose sibling untouched', gb.includes( '<p>Outer prose.</p>' ), '' );

	/* ===== Guard: Backspace at edge arms the NESTED island, two presses
	 * remove it, undo restores it — all inside the slot ===== */
	await page.evaluate( () => {
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		const ps = slot.querySelectorAll( ':scope > p' );
		const outro = ps[ ps.length - 1 ];
		slot.focus( { preventScroll: true } );
		const r = document.createRange();
		r.setStart( outro.firstChild, 0 );
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Backspace' );
	const armed1 = await page.evaluate( () => {
		const el = document.querySelector( '.minn-slot .minn-block-island.minn-island-armed' );
		return el ? el.dataset.block : null;
	} );
	t.check( 'Backspace at edge arms the nested buttons island', armed1 === 'buttons', String( armed1 ) );
	await page.keyboard.press( 'Backspace' );
	const afterRemove = await page.evaluate( () => ( {
		gone: ! document.querySelector( '.minn-slot .minn-block-island[data-block="buttons"]' ),
		toast: !! document.querySelector( '.minn-toast-btn' ),
	} ) );
	t.check( 'second press removes the nested island', afterRemove.gone && afterRemove.toast, JSON.stringify( afterRemove ) );

	/* ===== Undo toast restores the nested island (evaluate-click NOW —
	 * the toast auto-dismisses at 7s and page.click's actionability waits
	 * can race the detach) ===== */
	const undoClicked = await page.evaluate( () => {
		const b = document.querySelector( '.minn-toast-btn' );
		if ( b ) { b.click(); return true; }
		return false;
	} );
	t.check( 'undo toast button clicked in time', undoClicked, '' );
	const restored = await page.evaluate( () => !! document.querySelector( '.minn-slot .minn-block-island[data-block="buttons"]' ) );
	t.check( 'undo toast restores the nested island', restored, '' );
	raw = await save( ( r ) => groupA( r ).includes( 'Press me' ) );
	t.check( 'restored nested island saved back into the group', groupA( raw ).includes( 'Press me' ), groupA( raw ).slice( 0, 300 ) );

	/* ===== Remove again; removal must persist through save ===== */
	await page.evaluate( () => {
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		const ps = slot.querySelectorAll( ':scope > p' );
		const outro = ps[ ps.length - 1 ];
		slot.focus( { preventScroll: true } );
		const r = document.createRange();
		r.setStart( outro.firstChild, 0 );
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Backspace' );
	await page.keyboard.press( 'Backspace' );
	raw = await save( ( r ) => ! groupA( r ).includes( 'wp:buttons' ) );
	t.check( 'nested island removal saved (buttons gone, spacer kept)',
		! groupA( raw ).includes( 'wp:buttons' ) && groupA( raw ).includes( 'wp:spacer' ), groupA( raw ) );

	/* ===== Insert flows inside slots (the follow-up slice): the slash
	 * menu's island/table inserts and the embed-URL paste land INSIDE the
	 * slot and splice on save. ===== */
	const caretEndIn = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		el.closest( '.minn-slot' ).focus( { preventScroll: true } );
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	}, sel );

	// Table via the slash menu (the action.html path).
	await page.click( '.minn-slot-island .minn-slot > p' );
	await caretEndIn( '.minn-slot-island .minn-slot > p' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( '/tab' );
	await page.waitForSelector( '.minn-slash-menu', { timeout: 8000 } );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /^Table/.test( el.textContent.trim() ) );
		item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 400 );
	const tableInSlot = await page.evaluate( () => !! document.querySelector( '.minn-slot > figure.wp-block-table' ) );
	t.check( 'slash table insert lands inside the slot', tableInSlot, '' );
	raw = await save( ( r ) => groupA( r ).includes( 'wp:table' ) );
	t.check( 'slot table saved inside the group', groupA( raw ).includes( '<!-- wp:table' ), groupA( raw ).slice( 0, 200 ) );

	// Embed URL paste into an empty slot paragraph → nested embed island.
	await page.evaluate( () => {
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		const empty = [ ...slot.querySelectorAll( ':scope > p' ) ].find( ( p ) => ! p.textContent.trim() );
		const landing = empty || ( () => {
			const p = document.createElement( 'p' );
			p.appendChild( document.createElement( 'br' ) );
			slot.appendChild( p );
			return p;
		} )();
		slot.focus( { preventScroll: true } );
		const r = document.createRange();
		r.selectNodeContents( landing );
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
		const dt = new DataTransfer();
		dt.setData( 'text/plain', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		document.querySelector( '#minn-editor-body' ).dispatchEvent(
			new ClipboardEvent( 'paste', { clipboardData: dt, bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 500 );
	const embedInSlot = await page.evaluate( () => {
		const isl = document.querySelector( '.minn-slot > .minn-block-island[data-block="core/embed"], .minn-slot > .minn-block-island[data-block="embed"]' );
		return !! isl;
	} );
	t.check( 'embed URL paste islands inside the slot', embedInSlot, '' );
	raw = await save( ( r ) => groupA( r ).includes( 'wp:embed' ) );
	t.check( 'slot embed saved inside the group', groupA( raw ).includes( 'wp:embed' ) && groupA( raw ).includes( 'dQw4w9WgXcQ' ), groupA( raw ).slice( -400 ) );

	await deletePost( page, id );

	/* ===== Cover + media-text slots (the content-container locator): the
	 * background/media bytes are preserved as a verbatim PREAMBLE and the
	 * children edit inside the content container. ===== */
	const CONTENT3 = [
		'<!-- wp:cover {"dimRatio":50} -->',
		'<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center"} -->',
		'<p class="has-text-align-center">Cover line.</p>',
		'<!-- /wp:paragraph --></div></div>',
		'<!-- /wp:cover -->',
		'',
		'<!-- wp:media-text {"mediaType":"image"} -->',
		'<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="https://minnadmin.localhost/wp-includes/images/media/default.svg" alt=""/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph -->',
		'<p>Media text body.</p>',
		'<!-- /wp:paragraph --></div></div>',
		'<!-- /wp:media-text -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Below the media blocks.</p>',
		'<!-- /wp:paragraph -->',
	].join( '\n' );
	const id3 = await createPost( page, { title: 'Media slots ' + Date.now(), content: CONTENT3, status: 'draft' } );
	await page.goto( BASE + '/minn-admin/editor/posts/' + id3, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-slot', { timeout: 25000 } );
	await page.waitForTimeout( 1000 );

	const rawOf3 = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id3 );
	const save3 = async ( expectFn ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 900 );
			const raw3 = await rawOf3();
			if ( ! expectFn || expectFn( raw3 ) ) return raw3;
		}
		return rawOf3();
	};

	const mediaShape = await page.evaluate( () => {
		const cover = document.querySelector( '.minn-block-island[data-block="cover"], .minn-block-island[data-block="core/cover"]' );
		const mt = document.querySelector( '.minn-block-island[data-block="media-text"], .minn-block-island[data-block="core/media-text"]' );
		return {
			coverSlots: !! ( cover && cover.classList.contains( 'minn-media-slot-island' ) && cover.querySelector( '.wp-block-cover__inner-container > .minn-slot' ) ),
			coverText: cover ? ( cover.querySelector( '.minn-slot' ) || {} ).textContent || '' : '',
			coverBg: !! ( cover && cover.querySelector( '.wp-block-cover__background' ) ),
			mtSlots: !! ( mt && mt.querySelector( '.wp-block-media-text__content > .minn-slot' ) ),
			mtMedia: !! ( mt && mt.querySelector( '.wp-block-media-text__media img' ) ),
		};
	} );
	t.check( 'cover renders as a media slot island (background kept)', mediaShape.coverSlots && mediaShape.coverBg && mediaShape.coverText.includes( 'Cover line.' ), JSON.stringify( mediaShape ) );
	t.check( 'media-text slots with its figure kept', mediaShape.mtSlots && mediaShape.mtMedia, JSON.stringify( mediaShape ) );

	let raw3 = await save3();
	t.check( 'untouched cover + media-text re-save byte-identical', raw3 === CONTENT3, raw3 === CONTENT3 ? '' : raw3.slice( 0, 400 ) );

	await page.evaluate( () => {
		const p = document.querySelector( '.wp-block-cover__inner-container .minn-slot > p' );
		p.closest( '.minn-slot' ).focus( { preventScroll: true } );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.type( ' Edited cover.' );
	raw3 = await save3( ( r ) => r.includes( 'Cover line. Edited cover.' ) );
	const coverRaw = raw3.slice( raw3.indexOf( '<!-- wp:cover' ), raw3.indexOf( '<!-- /wp:cover -->' ) );
	t.check( 'cover edit saved inside the inner container',
		coverRaw.includes( 'Cover line. Edited cover.' )
		&& coverRaw.indexOf( 'wp-block-cover__background' ) < coverRaw.indexOf( 'Cover line.' ), coverRaw );
	t.check( 'cover preamble bytes verbatim',
		coverRaw.includes( '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container">' ), coverRaw );

	await page.evaluate( () => {
		const p = document.querySelector( '.wp-block-media-text__content .minn-slot > p' );
		p.closest( '.minn-slot' ).focus( { preventScroll: true } );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.type( ' Edited media.' );
	raw3 = await save3( ( r ) => r.includes( 'Media text body. Edited media.' ) );
	const mtRaw = raw3.slice( raw3.indexOf( '<!-- wp:media-text' ), raw3.indexOf( '<!-- /wp:media-text -->' ) );
	t.check( 'media-text edit saved; figure bytes verbatim',
		mtRaw.includes( 'Media text body. Edited media.' )
		&& mtRaw.includes( '<figure class="wp-block-media-text__media"><img src="https://minnadmin.localhost/wp-includes/images/media/default.svg" alt=""/></figure>' ), mtRaw );

	await deletePost( page, id3 );

	/* ===== Comment-tolerant slotting (GH #4 follow-up, the line-return
	 * report): AI-generated markup labels sections with plain HTML comments
	 * (<!-- Testimonial 1 -->) — those must not sink a container to a
	 * phase-2 island, and a DIRTY flush must re-emit them (the serializer's
	 * COMMENT_NODE path). ===== */
	const CONTENT4 = [
		'<!-- wp:group {"layout":{"type":"constrained"}} -->',
		'<div class="wp-block-group"><!-- wp:paragraph -->',
		'<p>Before the label.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- Section label -->',
		'<!-- wp:paragraph -->',
		'<p>After the label.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:group -->',
	].join( '\n' );
	const id4 = await createPost( page, { title: 'Comment slots ' + Date.now(), content: CONTENT4, status: 'draft' } );
	await page.goto( BASE + '/minn-admin/editor/posts/' + id4, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-slot', { timeout: 25000 } );
	await page.waitForTimeout( 800 );

	const rawOf4 = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id4 );

	const commentShape = await page.evaluate( () => {
		const isl = document.querySelector( '.minn-slot-island' );
		const slot = isl && isl.querySelector( '.minn-slot' );
		let hasComment = false;
		if ( slot ) {
			for ( const n of slot.childNodes ) {
				if ( n.nodeType === Node.COMMENT_NODE && /Section label/.test( n.data ) ) hasComment = true;
			}
		}
		return { slots: !! slot, hasComment, paras: slot ? slot.querySelectorAll( ':scope > p' ).length : 0 };
	} );
	t.check( 'comment-labeled group still slots (comment in DOM)', commentShape.slots && commentShape.hasComment && commentShape.paras >= 2, JSON.stringify( commentShape ) );

	// Dirty the slot (Enter = a line return, the report's exact ask), save:
	// the comment must survive the re-serialize.
	await page.evaluate( () => {
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		const p = [ ...slot.querySelectorAll( ':scope > p' ) ].find( ( x ) => /After the label/.test( x.textContent ) );
		slot.focus( { preventScroll: true } );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'Line return line.' );
	await page.keyboard.press( 'Meta+s' );
	let raw4 = '';
	for ( let i = 0; i < 15; i++ ) {
		await page.waitForTimeout( 900 );
		raw4 = await rawOf4();
		if ( raw4.includes( 'Line return line.' ) ) break;
	}
	t.check( 'Enter creates a saved paragraph beside the comment',
		/<!-- wp:paragraph -->\s*<p>Line return line\.<\/p>/.test( raw4 ), raw4 );
	t.check( 'plain comment re-emitted by the dirty flush',
		raw4.includes( '<!-- Section label -->' ) && raw4.indexOf( '<!-- Section label -->' ) < raw4.indexOf( 'After the label.' ), raw4 );

	await deletePost( page, id4 );

	/* ===== Duplicate (Austin's testimonial ask): the inspector's copy
	 * button clones an island in place — a nested card group duplicates
	 * INSIDE its column and both copies save. ===== */
	const CONTENT5 = [
		'<!-- wp:columns -->',
		'<div class="wp-block-columns"><!-- wp:column -->',
		'<div class="wp-block-column"><!-- wp:group {"className":"testimonial-card"} -->',
		'<div class="wp-block-group testimonial-card"><!-- wp:paragraph -->',
		'<p>Card one text.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:group --></div>',
		'<!-- /wp:column -->',
		'',
		'<!-- wp:column -->',
		'<div class="wp-block-column"><!-- wp:paragraph -->',
		'<p>Second column.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:column --></div>',
		'<!-- /wp:columns -->',
	].join( '\n' );
	const id5 = await createPost( page, { title: 'Duplicate card ' + Date.now(), content: CONTENT5, status: 'draft' } );
	await page.goto( BASE + '/minn-admin/editor/posts/' + id5, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-cols-island .minn-slot .minn-slot-island', { timeout: 25000 } );
	await page.waitForTimeout( 800 );

	// Open the nested card's inspector via its chip, then Duplicate.
	await page.evaluate( () => {
		const card = document.querySelector( '.minn-cols-island .minn-slot .minn-slot-island' );
		card.querySelector( '.minn-island-chip' ).click();
	} );
	await page.waitForSelector( '#minn-insp-duplicate', { timeout: 10000 } );
	await page.click( '#minn-insp-duplicate' );
	await page.waitForTimeout( 500 );
	const dupShape = await page.evaluate( () => {
		const col = document.querySelector( '.minn-cols-island .minn-slot' );
		const cards = col.querySelectorAll( ':scope > .minn-slot-island' );
		return {
			cards: cards.length,
			bothEditable: [ ...cards ].every( ( c ) => c.querySelector( '.minn-slot' )?.isContentEditable ),
		};
	} );
	t.check( 'duplicate clones the card inside its column', dupShape.cards === 2 && dupShape.bothEditable, JSON.stringify( dupShape ) );

	const rawOf5 = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id5 );
	await page.keyboard.press( 'Meta+s' );
	let raw5 = '';
	for ( let i = 0; i < 15; i++ ) {
		await page.waitForTimeout( 900 );
		raw5 = await rawOf5();
		if ( ( raw5.match( /Card one text\./g ) || [] ).length === 2 ) break;
	}
	const firstCol5 = raw5.slice( raw5.indexOf( '<!-- wp:column -->' ), raw5.indexOf( '<!-- /wp:column -->' ) );
	t.check( 'both card copies saved inside the FIRST column',
		( firstCol5.match( /testimonial-card/g ) || [] ).length >= 4 && ( raw5.match( /Card one text\./g ) || [] ).length === 2
		&& raw5.indexOf( 'Second column.' ) > raw5.indexOf( '<!-- wp:column -->', raw5.indexOf( '<!-- /wp:column -->' ) ), firstCol5 );

	await deletePost( page, id5 );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
