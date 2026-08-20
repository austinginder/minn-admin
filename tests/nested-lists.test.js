/**
 * Nested lists (GH #43, serializer half): a list inside a list item is its own
 * wp:list block in saved markup, not a raw <ul> hidden in the item's HTML.
 * The raw shape parses without a warning but silently demotes the children to
 * inline content, so the block editor stops seeing them as items. Attrs on a
 * nested list and on nested items ride the round trip too.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const NESTED = `<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Alpha</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Beta<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Child one</li>
<!-- /wp:list-item --><!-- wp:list-item {"className":"is-style-x"} -->
<li class="is-style-x">Child two</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Gamma</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`;

// A nested ORDERED list carrying its own attrs (start) plus a styled nested list.
const NESTED_OL = `<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Top<!-- wp:list {"ordered":true,"start":3,"className":"nested-ol"} -->
<ol start="3" class="wp-block-list nested-ol"><!-- wp:list-item -->
<li>Third</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Fourth</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list --></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`;

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
	const t = reporter( 'nested-lists' );
	await login( page );

	/* ===== A Gutenberg-authored nested list loads as editable prose ===== */
	const id = await createPost( page, { title: 'Nested list probe', content: NESTED, status: 'draft' } );
	await openEditor( page, id );
	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const ul = body.querySelector( ':scope > ul' );
		return {
			editable: !! ul,
			island: !! body.querySelector( '.minn-block-island' ),
			nested: !! ( ul && ul.querySelector( 'li > ul' ) ),
			childCount: ul ? ul.querySelectorAll( 'li > ul > li' ).length : 0,
		};
	} );
	t.check( 'nested list loads editable, not as an island', shape.editable && ! shape.island, JSON.stringify( shape ) );
	t.check( 'the nested level survives the load', shape.nested && shape.childCount === 2, JSON.stringify( shape ) );

	/* ===== Round trip keeps the nested list a real block ===== */
	await page.click( '#minn-editor-body' );
	const saved = await saveAndRead( page, id );
	const inner = saved.slice( saved.indexOf( 'Beta' ) );
	t.check( 'nested list saves as its own wp:list block',
		/<li>Beta<!-- wp:list -->/.test( saved.replace( /\n/g, '' ) ) || /Beta[\s\S]*?<!-- wp:list -->/.test( inner ), saved.slice( 0, 400 ) );
	t.check( 'nested items save as wp:list-item blocks',
		( saved.match( /<!-- wp:list-item/g ) || [] ).length === 5, String( ( saved.match( /<!-- wp:list-item/g ) || [] ).length ) );
	t.check( 'nested list closes its block', ( saved.match( /<!-- \/wp:list -->/g ) || [] ).length === 2,
		String( ( saved.match( /<!-- \/wp:list -->/g ) || [] ).length ) );
	t.check( 'a nested item keeps its own attrs', /<!-- wp:list-item \{"className":"is-style-x"\} -->/.test( saved ), saved.slice( 0, 600 ) );
	t.check( 'no raw list is left inside an item', ! /<li>[^<]*<ul[^>]*>(?![\s\S]{0,40}wp:list)/.test( saved ), saved.slice( 0, 400 ) );

	/* ===== Second save is a byte-identical fixed point ===== */
	await page.click( '#minn-editor-body p, #minn-editor-body li' );
	const saved2 = await saveAndRead( page, id );
	t.check( 'second save is a fixed point', saved2 === saved, saved2 === saved ? '' : saved2.slice( 0, 300 ) );
	await deletePost( page, id );

	/* ===== A nested ORDERED list keeps its numbering attrs ===== */
	const oid = await createPost( page, { title: 'Nested ol probe', content: NESTED_OL, status: 'draft' } );
	await openEditor( page, oid );
	await page.click( '#minn-editor-body' );
	const savedOl = await saveAndRead( page, oid );
	t.check( 'nested ordered list keeps ordered + start', /<!-- wp:list \{[^}]*"ordered":true[^}]*"start":3/.test( savedOl ), savedOl.slice( 0, 500 ) );
	t.check( 'nested ordered list keeps its className', /"className":"nested-ol"/.test( savedOl ), savedOl.slice( 0, 500 ) );
	t.check( 'nested ol keeps its start attribute in HTML', /<ol start="3"/.test( savedOl ), savedOl.slice( 0, 500 ) );
	await deletePost( page, oid );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
