/**
 * Tab nesting in lists (GH #43, keyboard half): Tab inside a list item nests
 * it under the item above, Shift+Tab lifts it back, and both survive a save as
 * real nested wp:list blocks. Tab outside a list (and on a list's first item)
 * still moves focus out of the body, so the editor is never a keyboard trap.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const LIST = `<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>One</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Two</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Three</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
<!-- wp:paragraph -->
<p>A paragraph after the list.</p>
<!-- /wp:paragraph -->`;

// Put the caret at the end of the list item whose text matches.
const caretIn = ( page, text ) => page.evaluate( ( txt ) => {
	const body = document.querySelector( '#minn-editor-body' );
	const li = [ ...body.querySelectorAll( 'li' ) ].find( ( el ) => el.textContent.trim().startsWith( txt ) );
	const node = li.firstChild;
	const r = document.createRange();
	r.setStart( node, node.textContent.length );
	r.collapse( true );
	const s = getSelection();
	s.removeAllRanges();
	s.addRange( r );
	body.focus( { preventScroll: true } );
}, text );

const listShape = ( page ) => page.evaluate( () => {
	const ul = document.querySelector( '#minn-editor-body > ul' );
	return {
		html: ul ? ul.outerHTML.replace( /\n/g, '' ) : null,
		strayList: !! document.querySelector( '#minn-editor-body ul > ul, #minn-editor-body ul > ol, #minn-editor-body ol > ul, #minn-editor-body ol > ol' ),
		nestedItems: ul ? ul.querySelectorAll( 'li > ul > li' ).length : 0,
		topItems: ul ? ul.querySelectorAll( ':scope > li' ).length : 0,
	};
} );

async function saveAndRead( page, id ) {
	const done = page.waitForResponse( ( r ) =>
		r.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( r.url() ), { timeout: 20000 } );
	await page.keyboard.press( 'Meta+s' );
	await done;
	await page.waitForTimeout( 400 );
	return page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id );
}

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'list-tab-nesting' );
	await login( page );

	const id = await createPost( page, { title: 'List Tab probe', content: LIST, status: 'draft' } );
	await openEditor( page, id );

	/* ===== Tab nests the second item under the first ===== */
	await caretIn( page, 'Two' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 300 );
	let shape = await listShape( page );
	t.check( 'Tab nests the item', shape.nestedItems === 1 && shape.topItems === 2, JSON.stringify( shape ) );
	t.check( 'no stray list is left beside an item', ! shape.strayList, JSON.stringify( shape ) );
	t.check( 'the nested list sits inside the item above', /<li>One<ul[^>]*><li>Two<\/li>/.test( shape.html ), String( shape.html ) );

	/* ===== Typing continues in the nested item ===== */
	await page.keyboard.type( ' plus' );
	await page.waitForTimeout( 200 );
	shape = await listShape( page );
	t.check( 'the caret stays in the nested item', /<li>Two plus<\/li>/.test( shape.html ), String( shape.html ) );

	/* ===== The nesting reaches saved markup as real blocks ===== */
	let saved = await saveAndRead( page, id );
	t.check( 'saved markup nests a real wp:list block', ( saved.match( /<!-- wp:list -->/g ) || [] ).length === 2, saved.slice( 0, 400 ) );
	t.check( 'saved markup keeps every item a block', ( saved.match( /<!-- wp:list-item/g ) || [] ).length === 3, saved.slice( 0, 400 ) );
	t.check( 'the typed text is in the nested item', /<li>Two plus<\/li>/.test( saved ), saved.slice( 0, 400 ) );

	/* ===== Two levels deep: Three joins Two's level, then nests under it ===== */
	await caretIn( page, 'Three' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 250 );
	await caretIn( page, 'Three' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 250 );
	const deep = await page.evaluate( () => ( {
		depth3: !! document.querySelector( '#minn-editor-body > ul li > ul li > ul li' ),
		stray: !! document.querySelector( '#minn-editor-body ul > ul, #minn-editor-body ul > ol' ),
	} ) );
	t.check( 'a second Tab nests one level deeper', deep.depth3, JSON.stringify( deep ) );
	t.check( 'deep nesting leaves no stray list', ! deep.stray, JSON.stringify( deep ) );
	saved = await saveAndRead( page, id );
	t.check( 'three levels save as three wp:list blocks', ( saved.match( /<!-- wp:list -->/g ) || [] ).length === 3, saved.slice( 0, 500 ) );

	/* ===== Tab is NOT captured where there is nothing to nest ===== */
	await caretIn( page, 'One' ); // first item of the top list
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 250 );
	let active = await page.evaluate( () => document.activeElement && document.activeElement.id );
	t.check( 'Tab on a first item moves focus out of the body', active !== 'minn-editor-body', String( active ) );

	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body > p' );
		const r = document.createRange();
		r.setStart( p.firstChild, 3 );
		r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus( { preventScroll: true } );
	} );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 250 );
	active = await page.evaluate( () => document.activeElement && document.activeElement.id );
	t.check( 'Tab in a paragraph still leaves the body', active !== 'minn-editor-body', String( active ) );
	const paraIntact = await page.evaluate( () => document.querySelector( '#minn-editor-body > p' ).textContent );
	t.check( 'Tab in a paragraph inserts nothing', paraIntact === 'A paragraph after the list.', paraIntact );
	await deletePost( page, id );

	/* ===== Undo and Shift+Tab, each from a clean editor =====
	   A fresh post per phase: Chrome groups typing into its own undo units,
	   so a phase that inherits another's stack proves nothing about either. */
	const uid = await createPost( page, { title: 'List Tab undo probe', content: LIST, status: 'draft' } );
	await openEditor( page, uid );
	await caretIn( page, 'Two' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 300 );
	t.check( 'the undo probe starts nested', ( await listShape( page ) ).nestedItems === 1, '' );
	// Press ⌘Z until the list is flat again rather than assuming how many
	// transactions Chrome recorded.
	let flat = false;
	for ( let i = 0; i < 6 && ! flat; i++ ) {
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 250 );
		const s = await listShape( page );
		flat = s.nestedItems === 0 && s.topItems === 3;
	}
	t.check( 'undo unwinds the nesting', flat, JSON.stringify( await listShape( page ) ) );

	await caretIn( page, 'Two' );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 300 );
	await caretIn( page, 'Two' );
	await page.keyboard.press( 'Shift+Tab' );
	await page.waitForTimeout( 350 );
	shape = await listShape( page );
	t.check( 'Shift+Tab lifts the item back to the top level', shape.nestedItems === 0 && shape.topItems === 3, JSON.stringify( shape ) );
	t.check( 'lifting leaves no stray list', ! shape.strayList, JSON.stringify( shape ) );
	const lifted = await saveAndRead( page, uid );
	t.check( 'a lifted list saves flat again', ( lifted.match( /<!-- wp:list -->/g ) || [] ).length === 1, lifted.slice( 0, 300 ) );

	/* ===== Shift+Tab on a top-level item is not swallowed ===== */
	await caretIn( page, 'Two' );
	await page.keyboard.press( 'Shift+Tab' );
	await page.waitForTimeout( 250 );
	const back = await page.evaluate( () => document.activeElement && document.activeElement.id );
	t.check( 'Shift+Tab at the top level moves focus out of the body', back !== 'minn-editor-body', String( back ) );

	await deletePost( page, uid );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
