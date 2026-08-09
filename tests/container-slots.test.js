/**
 * Container slots (nested-content plan, phase 3, slice 1): a core/group
 * whose children are all simple blocks renders as a slot island — wrapper
 * preserved byte-verbatim, children directly editable inside a nested
 * contenteditable. Untouched groups re-save byte-identical; edited groups
 * splice serialized children between the wrapper bytes. Containers with
 * complex children (columns here) stay phase-2 islands.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'container-slots' );
	await login( page );

	const GROUP_B = [
		'<!-- wp:group {"layout":{"type":"flow"},"style":{"spacing":{"padding":{"top":"20px"}}}} -->',
		'<div class="wp-block-group" style="padding-top:20px"><!-- wp:paragraph -->',
		'<p>Untouched group text.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:group -->',
	].join( '\n' );
	const content = [
		'<!-- wp:group {"layout":{"type":"constrained"}} -->',
		'<div class="wp-block-group"><!-- wp:heading -->',
		'<h2 class="wp-block-heading">Slot heading</h2>',
		'<!-- /wp:heading -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Slot paragraph text.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:paragraph {"fontSize":"large"} -->',
		'<p class="has-large-font-size">Styled slot child.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:list -->',
		'<ul class="wp-block-list"><!-- wp:list-item -->',
		'<li>Slot item</li>',
		'<!-- /wp:list-item --></ul>',
		'<!-- /wp:list --></div>',
		'<!-- /wp:group -->',
		'',
		GROUP_B,
		'',
		'<!-- wp:columns -->',
		'<div class="wp-block-columns"><!-- wp:column -->',
		'<div class="wp-block-column"><!-- wp:paragraph -->',
		'<p>Column text stays a phase-2 island.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:column --></div>',
		'<!-- /wp:columns -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Plain closer.</p>',
		'<!-- /wp:paragraph -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Container slots ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-slot', { timeout: 25000 } );
	// Phase-2 arming on the columns island is async (render-blocks round trip).
	await page.waitForSelector( '.minn-block-island[data-block="columns"] .minn-island-run', { timeout: 20000 } ).catch( () => {} );

	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const slots = [ ...body.querySelectorAll( '.minn-slot-island' ) ];
		const cols = body.querySelector( '.minn-block-island[data-block="columns"]' );
		return {
			slotIslands: slots.length,
			slotEditable: slots.every( ( s ) => s.querySelector( '.minn-slot' )?.isContentEditable ),
			slotAH2: !! slots[ 0 ]?.querySelector( '.minn-slot > h2' ),
			slotAStyledMarker: slots[ 0 ]?.querySelector( '.minn-slot > p[data-minn-attrs]' )?.dataset.minnAttrs || '',
			wrapperClass: slots[ 0 ]?.querySelector( '.wp-block-group' ) ? true : false,
			columnsIsIsland: !! cols && ! cols.classList.contains( 'minn-slot-island' ),
			columnsRuns: cols ? cols.querySelectorAll( '.minn-island-run' ).length : 0,
		};
	} );
	t.check( 'both groups render as slot islands', shape.slotIslands === 2 && shape.slotEditable, JSON.stringify( shape ) );
	t.check( 'children are real blocks inside the wrapper', shape.slotAH2 && shape.wrapperClass, JSON.stringify( shape ) );
	t.check( 'styled child carries its phase-1 marker', shape.slotAStyledMarker === '{"fontSize":"large"}', shape.slotAStyledMarker );
	t.check( 'columns stays a phase-2 island with runs', shape.columnsIsIsland && shape.columnsRuns === 1, JSON.stringify( shape ) );

	const caretEnd = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	}, sel );
	const rawOf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async ( expect ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 900 );
			const raw = await rawOf();
			if ( ! expect || raw.includes( expect ) ) return raw;
		}
		return rawOf();
	};

	// 1. Type in the slot heading; mid-Backspace must not arm the island.
	await page.click( '.minn-slot-island .minn-slot > h2' );
	await caretEnd( '.minn-slot-island .minn-slot > h2' );
	await page.keyboard.type( ' edited' );
	await page.keyboard.press( 'Backspace' );
	await page.keyboard.press( 'Backspace' );
	const guardState = await page.evaluate( () => ( {
		armed: !! document.querySelector( '.minn-slot-island.minn-island-armed' ),
		islands: document.querySelectorAll( '.minn-slot-island' ).length,
		h2: document.querySelector( '.minn-slot > h2' )?.textContent,
	} ) );
	t.check( 'backspace inside slot never arms the container', ! guardState.armed && guardState.islands === 2 && guardState.h2 === 'Slot heading edit', JSON.stringify( guardState ) );

	// 2. Inline markdown works inside the slot.
	await page.click( '.minn-slot-island .minn-slot > p' );
	await caretEnd( '.minn-slot-island .minn-slot > p' );
	await page.keyboard.type( ' Now **bold** words.' );
	const pHtml = await page.$eval( '.minn-slot-island .minn-slot > p', ( el ) => el.innerHTML );
	t.check( 'inline markdown converts inside slot', /<strong>bold<\/strong>/.test( pHtml ), pHtml );

	// 3. Enter at slot end adds a paragraph INSIDE the group.
	await page.evaluate( () => {
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		const li = slot.querySelector( 'ul li' );
		const r = document.createRange();
		r.selectNodeContents( li );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Enter' ); // exit the list into a fresh paragraph
	await page.keyboard.type( 'New slot paragraph.' );

	// 4. Save; verify wrapper bytes + child structure.
	let raw = await save( 'New slot paragraph.' );
	// Minn's serializer always emits {"level":2} on headings (the standing
	// first-save normalization) — wrapper bytes stay verbatim around it.
	t.check( 'group comment + wrapper byte-identical', raw.includes( '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group"><!-- wp:heading' ), raw.slice( 0, 120 ) );
	t.check( 'heading edit saved inside group', raw.includes( '<h2 class="wp-block-heading">Slot heading edit</h2>' ), '' );
	t.check( 'markdown bold saved inside group', raw.includes( 'Now <strong>bold</strong> words.' ), '' );
	const groupEnd = raw.indexOf( '<!-- /wp:group -->' );
	t.check( 'new paragraph saved INSIDE the group', raw.indexOf( 'New slot paragraph.' ) > -1 && raw.indexOf( 'New slot paragraph.' ) < groupEnd, '' );
	t.check( 'styled child re-saves byte-identical', raw.includes( '<!-- wp:paragraph {"fontSize":"large"} -->\n<p class="has-large-font-size">Styled slot child.</p>' ), '' );
	t.check( 'untouched group byte-identical', raw.includes( GROUP_B ), '' );
	t.check( 'columns island untouched', raw.includes( '<p>Column text stays a phase-2 island.</p>' ), '' );
	t.check( 'no chrome leaked', ! raw.includes( 'minn-slot' ) && ! raw.includes( 'contenteditable' ) && ! raw.includes( 'data-minn-attrs' ), '' );

	// 5. ⌘A clamps to the slot.
	await page.click( '.minn-slot-island .minn-slot > p' );
	await page.keyboard.press( 'Meta+a' );
	const selShape = await page.evaluate( () => {
		const sel = window.getSelection();
		const slot = document.querySelector( '.minn-slot-island .minn-slot' );
		return { inSlot: slot.contains( sel.anchorNode ), hasCloser: /Plain closer/.test( sel.toString() ) };
	} );
	t.check( 'select-all clamped to slot', selShape.inSlot && ! selShape.hasCloser, JSON.stringify( selShape ) );

	// 6. Plain-text paste inside slot.
	await caretEnd( '.minn-slot-island .minn-slot > p' );
	await page.evaluate( () => {
		const el = document.querySelector( '.minn-slot-island .minn-slot > p' );
		const dt = new DataTransfer();
		dt.setData( 'text/html', '<b>RICH</b> slot paste' );
		dt.setData( 'text/plain', 'RICH slot paste' );
		el.dispatchEvent( new ClipboardEvent( 'paste', { clipboardData: dt, bubbles: true, cancelable: true } ) );
	} );
	const pAfterPaste = await page.$eval( '.minn-slot-island .minn-slot > p', ( el ) => el.innerHTML );
	t.check( 'paste lands plain in slot', pAfterPaste.includes( 'RICH slot paste' ) && ! /<b>/.test( pAfterPaste ), pAfterPaste.slice( -60 ) );

	// 7. Undo works for slot typing.
	raw = await save( 'RICH slot paste' );
	t.check( 'paste saved inside group', raw.indexOf( 'RICH slot paste' ) > -1 && raw.indexOf( 'RICH slot paste' ) < raw.indexOf( '<!-- /wp:group -->' ), '' );

	// ---- Block creation inside slots (phase 3 follow-up slice) ----
	// Work in the SECOND group (its untouched byte-identity was already
	// asserted above, before these edits dirty it).
	const slotB = '.minn-slot-island:nth-of-type(2) .minn-slot';
	await page.click( slotB + ' > p' );
	await caretEnd( slotB + ' > p' );
	await page.keyboard.press( 'Enter' );

	// 8. Slash menu opens inside the slot, offering prose basics only.
	await page.keyboard.type( '/' );
	await page.waitForTimeout( 400 );
	const menuShape = await page.evaluate( () => {
		const menu = document.querySelector( '.minn-slash-menu' );
		return {
			open: !! menu,
			labels: menu ? [ ...menu.querySelectorAll( '.minn-slash-item' ) ].map( ( el ) => el.textContent.trim() ) : [],
			browse: !! ( menu && menu.querySelector( '[data-browse]' ) ),
		};
	} );
	t.check( 'slash menu opens inside slot', menuShape.open, JSON.stringify( menuShape ) );
	t.check( 'slot menu is prose basics only',
		menuShape.labels.some( ( l ) => /Heading 2/.test( l ) )
		&& ! menuShape.labels.some( ( l ) => /Image|Embed|Table|Gallery|Buttons|Details/.test( l ) )
		&& ! menuShape.browse,
		JSON.stringify( menuShape.labels ) );

	// 9. Run Heading 2 from the menu; type into the new heading.
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /Heading 2/.test( el.textContent ) );
		item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 250 );
	await page.keyboard.type( 'Slot subheading' );
	const h2InSlot = await page.evaluate( ( sel ) => !! document.querySelector( sel + ' > h2' ), slotB );
	t.check( 'slash heading lands inside slot', h2InSlot, '' );

	// 10. Markdown list prefix inside the slot. Enter at a heading's end
	// clones the heading (Blink, same as top level) — convert the clone to
	// a paragraph with the toolbar ¶, which also proves the toolbar's block
	// buttons are slot-contained now.
	await page.keyboard.press( 'Enter' );
	await page.evaluate( () => {
		const b = document.querySelector( '.minn-tool[data-block="p"]' );
		b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 200 );
	const afterPilcrow = await page.evaluate( ( sel ) => {
		const slot = document.querySelector( sel );
		return { lastTag: slot.lastElementChild.tagName, inSlot: true, h2s: slot.querySelectorAll( 'h2' ).length };
	}, slotB );
	t.check( 'toolbar ¶ converts inside slot', afterPilcrow.lastTag === 'P' && afterPilcrow.h2s === 1, JSON.stringify( afterPilcrow ) );
	await page.keyboard.type( '- ' );
	await page.keyboard.type( 'Slot list item' );
	const listShape = await page.evaluate( ( sel ) => ( {
		ul: !! document.querySelector( sel + ' > ul' ),
		nested: !! document.querySelector( sel + ' > p > ul' ),
		li: document.querySelector( sel + ' > ul > li' )?.textContent || '',
	} ), slotB );
	t.check( 'markdown list converts inside slot (lifted)', listShape.ul && ! listShape.nested && listShape.li === 'Slot list item', JSON.stringify( listShape ) );

	// 11. Markdown heading prefix inside the slot.
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Enter' ); // out of the list
	await page.keyboard.type( '### ' );
	await page.keyboard.type( 'Deep heading' );
	const h3State = await page.evaluate( ( sel ) => {
		const slot = document.querySelector( sel );
		const selN = window.getSelection();
		return {
			h3: slot.querySelector( ':scope > h3' )?.textContent || '',
			tail: slot.innerHTML.slice( -220 ),
			caretIn: selN.anchorNode ? ( selN.anchorNode.nodeType === 1 ? selN.anchorNode : selN.anchorNode.parentElement ).tagName : 'none',
		};
	}, slotB );
	t.check( 'markdown heading converts inside slot', h3State.h3 === 'Deep heading', JSON.stringify( h3State ) );

	// 12. Save; everything landed INSIDE the second group's comments.
	raw = await save( 'Slot subheading' );
	const gbStart = raw.indexOf( '<!-- wp:group {"layout":{"type":"flow"' );
	const gbEnd = raw.indexOf( '<!-- /wp:group -->', gbStart );
	const gb = raw.slice( gbStart, gbEnd );
	t.check( 'created blocks saved inside the group',
		gb.includes( 'Slot subheading' ) && gb.includes( '<!-- wp:heading' )
		&& gb.includes( '<!-- wp:list' ) && gb.includes( 'Slot list item' )
		&& gb.includes( '<h3 class="wp-block-heading">Deep heading</h3>' ),
		gb.slice( 0, 200 ) );
	t.check( 'wrapper bytes still verbatim', gb.startsWith( '<!-- wp:group {"layout":{"type":"flow"},"style":{"spacing":{"padding":{"top":"20px"}}}} -->\n<div class="wp-block-group" style="padding-top:20px">' ), '' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
