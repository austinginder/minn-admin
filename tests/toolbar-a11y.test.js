/**
 * Toolbar as a single ARIA stop (GH #42): the formatting toolbar's buttons
 * are out of the Tab ring, so Tab from the title lands in the body. ⌥F10
 * enters the toolbar, arrows rove between buttons, Escape or Tab returns to
 * the caret, and Enter activates a button against the body selection.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PARAS = Array.from( { length: 4 }, ( _, i ) =>
	`<!-- wp:paragraph --><p>Toolbar probe paragraph ${ i + 1 } with boldable words inside.</p><!-- /wp:paragraph -->` ).join( '' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'toolbar-a11y' );
	await login( page );

	const id = await createPost( page, { title: 'Toolbar a11y probe', content: PARAS, status: 'draft' } );
	await openEditor( page, id );

	/* ===== Structure: ARIA toolbar, buttons out of the Tab ring ===== */
	const structure = await page.evaluate( () => {
		const tb = document.querySelector( '.minn-editor-toolbar' );
		const tools = [ ...document.querySelectorAll( '.minn-tool' ) ];
		return {
			role: tb && tb.getAttribute( 'role' ),
			labeled: !! ( tb && tb.getAttribute( 'aria-label' ) ),
			count: tools.length,
			allOut: tools.every( ( b ) => b.getAttribute( 'tabindex' ) === '-1' ),
			allNamed: tools.every( ( b ) => !! b.getAttribute( 'aria-label' ) ),
		};
	} );
	t.check( 'toolbar has role and label', structure.role === 'toolbar' && structure.labeled, JSON.stringify( structure ) );
	t.check( 'every button is out of the Tab ring', structure.count >= 14 && structure.allOut, JSON.stringify( structure ) );
	t.check( 'every button keeps its accessible name', structure.allNamed, JSON.stringify( structure ) );

	/* ===== Tab from the title lands in the body ===== */
	await page.click( '#minn-editor-title' );
	await page.keyboard.press( 'Tab' );
	let active = await page.evaluate( () => document.activeElement && document.activeElement.id );
	t.check( 'Tab from title focuses the body', active === 'minn-editor-body', String( active ) );

	/* ===== Shift+Tab from the body returns to the title ===== */
	await page.keyboard.press( 'Shift+Tab' );
	active = await page.evaluate( () => document.activeElement && document.activeElement.id );
	t.check( 'Shift+Tab from body returns to the title', active === 'minn-editor-title', String( active ) );

	/* ===== ⌥F10 enters the toolbar; arrows rove; Home/End jump ===== */
	await page.click( '#minn-editor-body p:nth-of-type(2)' );
	await page.keyboard.press( 'Alt+F10' );
	const entry = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'aria-label' ) );
	t.check( '⌥F10 focuses the first toolbar button', entry === 'Bold', String( entry ) );
	await page.keyboard.press( 'ArrowRight' );
	let roved = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'aria-label' ) );
	t.check( 'ArrowRight roves to the next button', roved === 'Italic', String( roved ) );
	await page.keyboard.press( 'End' );
	roved = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'aria-label' ) );
	t.check( 'End jumps to the last button', roved === 'Clear formatting', String( roved ) );
	await page.keyboard.press( 'ArrowRight' );
	roved = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'aria-label' ) );
	t.check( 'roving wraps around', roved === 'Bold', String( roved ) );
	await page.keyboard.press( 'Home' );
	await page.keyboard.press( 'ArrowLeft' );
	roved = await page.evaluate( () => document.activeElement && document.activeElement.getAttribute( 'aria-label' ) );
	t.check( 'ArrowLeft wraps backwards', roved === 'Clear formatting', String( roved ) );

	/* ===== Escape returns to the body and restores the caret ===== */
	await page.keyboard.press( 'Escape' );
	const afterEsc = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const s = getSelection();
		const anchorEl = s.anchorNode && ( s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement );
		return {
			id: document.activeElement && document.activeElement.id,
			inP2: !! ( anchorEl && anchorEl.closest( 'p' ) === body.querySelectorAll( 'p' )[ 1 ] ),
		};
	} );
	t.check( 'Escape returns focus to the body', afterEsc.id === 'minn-editor-body', JSON.stringify( afterEsc ) );
	t.check( 'Escape restores the caret paragraph', afterEsc.inP2, JSON.stringify( afterEsc ) );

	/* ===== Keyboard activation formats the body selection ===== */
	await page.evaluate( () => {
		const p = document.querySelectorAll( '#minn-editor-body p' )[ 2 ];
		const node = p.firstChild; // "Toolbar probe paragraph 3 …"
		const r = document.createRange();
		r.setStart( node, 0 );
		r.setEnd( node, 7 ); // "Toolbar"
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus( { preventScroll: true } );
	} );
	await page.keyboard.press( 'Alt+F10' ); // first button = Bold
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 300 );
	const bolded = await page.evaluate( () => {
		const p = document.querySelectorAll( '#minn-editor-body p' )[ 2 ];
		return {
			hasBold: !! p.querySelector( 'b, strong' ),
			focusInBody: document.activeElement && document.activeElement.id === 'minn-editor-body',
		};
	} );
	t.check( 'Enter on Bold formats the selected word', bolded.hasBold, JSON.stringify( bolded ) );
	t.check( 'activation leaves the caret in the body', bolded.focusInBody, JSON.stringify( bolded ) );

	/* ===== The formatting reaches SAVED content ===== */
	const saveDone = page.waitForResponse( ( res ) =>
		res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
	await page.keyboard.press( 'Meta+s' );
	await saveDone;
	await page.waitForTimeout( 400 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		const j = await r.json();
		return j.content && j.content.raw || '';
	}, id );
	t.check( 'saved markup carries the keyboard-applied bold', /<(b|strong)>Toolbar<\/(b|strong)>/.test( saved ), saved.slice( 0, 300 ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
