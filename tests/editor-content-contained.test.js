/**
 * Stored content stays inside the box that shows it. kses keeps position,
 * the offsets and z-index in a style attribute for every role, so a
 * Contributor's paragraph with position:fixed and a huge z-index covered the
 * whole admin for whoever opened the post: every click landed on it. The
 * editor body and every other host of stored markup contain their content
 * (fixed and absolute descendants position against the host, and paint
 * inside it).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const OVERLAY = '<!-- wp:paragraph --><p style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2147483647;background:red">cover</p><!-- /wp:paragraph -->'
	+ '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-content-contained' );
	await login( page );

	const id = await createPost( page, { title: 'Contained content probe', content: OVERLAY, status: 'draft' } );
	await openEditor( page, id );
	await page.waitForSelector( '#minn-editor-body p', { timeout: 15000 } );
	await page.waitForTimeout( 300 );

	const hits = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const b = body.getBoundingClientRect();
		// Points on the admin around the editor body: its corners, the title
		// above it and the space to its right (the editor's side panel).
		const pts = [ [ 4, 4 ], [ window.innerWidth - 4, 4 ], [ 4, window.innerHeight - 4 ], [ b.left + 20, Math.max( 2, b.top - 30 ) ], [ Math.min( window.innerWidth - 2, b.right + 40 ), b.top + 20 ] ];
		return pts.map( ( [ x, y ] ) => {
			const el = document.elementFromPoint( x, y );
			return { x: Math.round( x ), y: Math.round( y ), inBody: !! ( el && body.contains( el ) ), tag: el ? el.tagName + '.' + el.className : null };
		} );
	} );
	t.check( 'content cannot cover the admin around the editor', hits.every( ( h ) => ! h.inBody ), JSON.stringify( hits.filter( ( h ) => h.inBody ) ) );
	const box = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' ).getBoundingClientRect();
		const p = document.querySelector( '#minn-editor-body p' ).getBoundingClientRect();
		return { body: [ body.left, body.top ], p: [ p.left, p.top ] };
	} );
	t.check( 'a fixed paragraph positions against the editor body', Math.abs( box.p[ 0 ] - box.body[ 0 ] ) < 2 && Math.abs( box.p[ 1 ] - box.body[ 1 ] ) < 2, JSON.stringify( box ) );

	/* ===== The other hosts of stored markup carry the same containment ===== */
	const hosts = await page.evaluate( () => [ 'minn-editor-body', 'minn-rt-body', 'minn-revision-preview', 'minn-diff' ].map( ( cls ) => {
		const el = document.createElement( 'div' );
		el.className = cls;
		document.body.appendChild( el );
		const cs = getComputedStyle( el );
		const out = { cls, contain: cs.contain, overflowY: cs.overflowY };
		el.remove();
		return out;
	} ) );
	t.check( 'every content host contains layout and clips', hosts.every( ( h ) => /layout/.test( h.contain ) && /paint/.test( h.contain ) ), JSON.stringify( hosts ) );

	/* ===== Minn's own chip on a leading island is not clipped =====
	   (it straddles the island's top border, above the body's box; the clip
	   margin is what keeps it whole) */
	const lead = await createPost( page, { title: 'Leading island probe', content: '<!-- wp:latest-posts /--><!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, lead );
	await page.waitForSelector( '#minn-editor-body .minn-island-chip', { timeout: 15000 } );
	const chip = await page.evaluate( () => {
		const c = document.querySelector( '#minn-editor-body .minn-island-chip' );
		const r = c.getBoundingClientRect();
		const el = document.elementFromPoint( r.left + r.width / 2, r.top + 2 );
		return { above: Math.round( document.querySelector( '#minn-editor-body' ).getBoundingClientRect().top - r.top ), hit: !! ( el && c.contains( el ) ) };
	} );
	t.check( 'a leading island\'s chip shows whole above the body', chip.above > 0 && chip.hit, JSON.stringify( chip ) );

	await deletePost( page, lead );
	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
