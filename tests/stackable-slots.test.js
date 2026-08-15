/**
 * Vendor slot islands (SLOT_VENDOR — the Stackable shape): a
 * stackable/columns container renders one editable slot per
 * stackable/column, with every wrapper byte (per-block <style> tags,
 * uniqueIds, data attributes, nested wrapper divs) re-emitted verbatim from
 * the stored raw. Complex children keep their protected nested-island card.
 * The parser is marker-based (stk-inner-blocks content divs), so this suite
 * builds the real saved shape by hand — no Stackable JS involved.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'stackable-slots' );
	const { browser, page, errors } = await launch();
	await login( page );

	const RAW = `<!-- wp:stackable/columns {"uniqueId":"aaa1111"} -->
<div class="wp-block-stackable-columns stk-block-columns stk-block stk-aaa1111" data-block-id="aaa1111"><style>.stk-aaa1111{margin-bottom:0px !important}</style><div class="stk-row stk-inner-blocks stk-block-content stk-content-align stk-aaa1111-column"><!-- wp:stackable/column {"uniqueId":"bbb2222"} -->
<div class="wp-block-stackable-column stk-block-column stk-column stk-block stk-bbb2222" data-v="4" data-block-id="bbb2222"><style>.stk-bbb2222{max-width:400px !important}</style><div class="stk-column-wrapper stk-block-column__content stk-container stk-bbb2222-container stk--no-background stk--no-padding"><div class="stk-block-content stk-inner-blocks stk-bbb2222-inner-blocks"><!-- wp:paragraph -->
<p>Left column text.</p>
<!-- /wp:paragraph --></div></div></div>
<!-- /wp:stackable/column -->

<!-- wp:stackable/column {"uniqueId":"ccc3333"} -->
<div class="wp-block-stackable-column stk-block-column stk-column stk-block stk-ccc3333" data-v="4" data-block-id="ccc3333"><div class="stk-column-wrapper stk-block-column__content stk-container stk-ccc3333-container stk--no-background stk--no-padding"><div class="stk-block-content stk-inner-blocks stk-ccc3333-inner-blocks"><!-- wp:acme/widget {"x":1} -->
<div class="acme-widget">Complex leaf</div>
<!-- /wp:acme/widget --></div></div></div>
<!-- /wp:stackable/column --></div></div>
<!-- /wp:stackable/columns -->`;

	const id = await createPost( page, { title: 'Stackable slots test', content: RAW } );
	const readRaw = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="stackable/columns"]', { timeout: 15000 } );
		const shape = await page.evaluate( () => {
			const el = document.querySelector( '.minn-block-island[data-block="stackable/columns"]' );
			return {
				slotIsland: el.classList.contains( 'minn-slot-island' ) && el.classList.contains( 'minn-cols-island' ),
				slots: el.querySelectorAll( '.minn-slot' ).length,
				rowMarked: !! el.querySelector( '.minn-slot-row' ),
				colsMarked: el.querySelectorAll( '.minn-slot-col' ).length,
				nested: Array.from( el.querySelectorAll( '.minn-block-island' ) ).map( ( n ) => n.dataset.block ),
				leftText: /Left column text\./.test( el.textContent ),
			};
		} );
		t.check( 'container upgrades to a two-slot island', shape.slotIsland && shape.slots === 2, JSON.stringify( shape ) );
		t.check( 'row and columns carry the DOM layout markers', shape.rowMarked && shape.colsMarked === 2 );
		t.check( 'complex child keeps its protected nested island', shape.nested.includes( 'acme/widget' ), shape.nested.join( ',' ) );
		t.check( 'column prose renders editable in place', shape.leftText );

		// Type into the left column, save, verify byte-fidelity around the edit.
		await page.evaluate( () => {
			const p = Array.from( document.querySelectorAll( '.minn-slot p' ) ).find( ( x ) => /Left column text\./.test( x.textContent ) );
			p.scrollIntoView( { block: 'center' } );
			const range = document.createRange();
			range.selectNodeContents( p );
			range.collapse( false );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
		} );
		await page.keyboard.type( ' Edited.' );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 500 );
		const saved = await readRaw();
		t.check( 'slot edit persisted', saved.includes( 'Left column text. Edited.' ), saved.slice( 0, 200 ) );
		const norm = ( s ) => s.replace( /Left column text\.( Edited\.)?/, 'XX' );
		t.check( 'byte-identical outside the edit (styles, uniqueIds, wrappers, complex child)',
			norm( saved ) === norm( RAW ), ( () => {
				const a = norm( RAW ), b = norm( saved );
				let i = 0;
				while ( i < Math.min( a.length, b.length ) && a[ i ] === b[ i ] ) i++;
				return 'diff@' + i + ': ' + JSON.stringify( b.slice( Math.max( 0, i - 60 ), i + 60 ) );
			} )() );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
