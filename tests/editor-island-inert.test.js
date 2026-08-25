/**
 * Stored block markup must never reach a live element unparked.
 *
 * The editor loads a post through rtNeutralizeInto: parse inertly, park every
 * attribute that runs, import the nodes. The file calls that "the one way
 * stored post content should ever reach a live element", because the writer
 * and the reader are usually different people.
 *
 * Two paths rebuilt an island from its stored raw and handed the markup
 * straight to a live element instead — duplicating a block, and copying one
 * within the same post — so the copy carried live handler attributes while
 * every other copy on the page carried parked ones. Previews are
 * pointer-events:none, so this was an inconsistency rather than a
 * demonstrated way to run something; the point of the test is that the
 * invariant now holds everywhere, and that the writer's bytes still survive
 * the round trip.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

// Unregistered on purpose: a registered block gets upgraded to editable prose
// by the load pipeline, and only an island keeps its markup verbatim.
const RAW = '<!-- wp:acme/probe -->\n<div class="acme-probe"><img src="x" onerror="window.__minnFired=1"/>probe</div>\n<!-- /wp:acme/probe -->';

const liveHandlers = ( page ) => page.evaluate( () =>
	[ ...document.querySelectorAll( '#minn-editor-body [onerror], #minn-editor-body [onload], #minn-editor-body [onmouseover], #minn-editor-body [onclick], #minn-editor-body [onfocus]' ) ].length );
const parkedHandlers = ( page ) => page.evaluate( () =>
	[ ...document.querySelectorAll( '#minn-editor-body [data-minn-inert-onerror]' ) ].length );

( async () => {
	const t = reporter( 'editor-island-inert' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, { title: 'Island inert probe', content: RAW } );
	await openEditor( page, id );
	await page.waitForSelector( '.minn-block-island', { timeout: 20000 } );

	t.check( 'Load parks the handler', ( await parkedHandlers( page ) ) === 1 );
	t.check( 'Load leaves nothing live', ( await liveHandlers( page ) ) === 0 );

	// Duplicate — alt-click on the island chip is the shipped gesture.
	await page.evaluate( () => {
		const el = document.querySelector( '.minn-block-island' );
		el.scrollIntoView();
		el.querySelector( '[data-inspect]' ).dispatchEvent( new MouseEvent( 'click', { bubbles: true, altKey: true } ) );
	} );
	await page.waitForFunction( () => document.querySelectorAll( '.minn-block-island' ).length === 2, { timeout: 10000 } );
	await page.waitForTimeout( 800 );

	t.check( 'Duplicate really landed', ( await page.$$( '.minn-block-island' ) ).length === 2 );
	t.check( 'Duplicate carries no live handler', ( await liveHandlers( page ) ) === 0,
		'live attrs: ' + ( await liveHandlers( page ) ) );
	t.check( 'Duplicate is parked like its original', ( await parkedHandlers( page ) ) === 2,
		'parked attrs: ' + ( await parkedHandlers( page ) ) );

	// Copy/paste within the post goes through the other sink.
	await page.evaluate( () => {
		const body = document.getElementById( 'minn-editor-body' );
		const isl = document.querySelectorAll( '.minn-block-island' )[ 0 ];
		const r = document.createRange();
		r.selectNode( isl );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( r );
		body.focus( { preventScroll: true } );
	} );
	await page.keyboard.press( 'Meta+c' );
	await page.waitForTimeout( 200 );
	await page.keyboard.press( 'Meta+v' );
	await page.waitForTimeout( 1200 );

	t.check( 'Paste leaves nothing live either', ( await liveHandlers( page ) ) === 0,
		'live attrs: ' + ( await liveHandlers( page ) ) );

	// The parked copies must still serialize back to the writer's bytes.
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( `${ window.MINN.restUrl }wp/v2/posts/${ pid }?context=edit&_fields=content`,
			{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'Stored markup keeps the original bytes', saved.includes( 'onerror="window.__minnFired=1"' ),
		saved.slice( 0, 100 ) );
	t.check( 'No parked attribute leaked into storage', ! saved.includes( 'data-minn-inert' ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )();
