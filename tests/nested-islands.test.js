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

	/* ===== Undo toast restores the nested island (click within the 7s
	 * toast window — before any slow save polling) ===== */
	await page.click( '.minn-toast-btn' );
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

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
