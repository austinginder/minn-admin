/**
 * /column adds a column to the Columns block the caret is in.
 * Verifies the SAVED markup: the new column is a real core/column, the rest
 * of the block keeps its text, and no width is invented for the new column
 * (which would silently squeeze the row).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const COLS = [
	'<!-- wp:columns -->',
	'<div class="wp-block-columns">',
	'<!-- wp:column -->',
	'<div class="wp-block-column">',
	'<!-- wp:paragraph -->',
	'<p>Left.</p>',
	'<!-- /wp:paragraph -->',
	'</div>',
	'<!-- /wp:column -->',
	'',
	'<!-- wp:column -->',
	'<div class="wp-block-column">',
	'<!-- wp:paragraph -->',
	'<p>Right.</p>',
	'<!-- /wp:paragraph -->',
	'</div>',
	'<!-- /wp:column -->',
	'</div>',
	'<!-- /wp:columns -->',
].join( '\n' );

( async () => {
	const t = reporter( 'column-slash' );
	const { browser, page, errors } = await launch();
	await login( page );
	let id = 0;
	try {
		id = await createPost( page, { title: 'Column slash probe', content: COLS } );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-cols-island', { timeout: 20000 } );
		await page.waitForTimeout( 2000 );

		const before = await page.evaluate( () => document.querySelectorAll( '.minn-cols-island .minn-slot' ).length );
		t.check( 'fixture renders two column slots', before === 2, String( before ) );

		// Click into the first column with a REAL mouse: focusing the editor
		// body would pull the caret out of the slot's own contenteditable.
		const spot = await page.evaluate( () => {
			const p = document.querySelector( '.minn-cols-island .minn-slot p' );
			p.scrollIntoView( { block: 'center' } );
			const r = p.getBoundingClientRect();
			return { x: r.left + Math.min( 30, r.width / 2 ), y: r.top + r.height / 2 };
		} );
		await page.mouse.click( spot.x, spot.y );
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( '/col' );
		await page.waitForSelector( '.minn-slash-item', { timeout: 6000 } );
		const labels = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-slash-item' ) ].map( ( el ) => el.textContent.trim() ) );
		t.check( 'the menu offers Column inside a Columns block', labels.some( ( l ) => /^Column$/.test( l ) ), JSON.stringify( labels ) );
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( e ) => /^Column$/.test( e.textContent.trim() ) );
			el.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		} );
		await page.waitForTimeout( 1200 );
		const after = await page.evaluate( () => ( {
			slots: document.querySelectorAll( '.minn-cols-island .minn-slot' ).length,
			caretInNew: ( () => {
				const s = window.getSelection();
				const el = s.anchorNode && ( s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentElement );
				const slot = el && el.closest( '.minn-slot' );
				const slots = [ ...document.querySelectorAll( '.minn-cols-island .minn-slot' ) ];
				return slot ? slots.indexOf( slot ) : -1;
			} )(),
		} ) );
		t.check( 'a third column appears immediately', after.slots === 3, JSON.stringify( after ) );
		t.check( 'the caret lands in the new column', after.caretInNew === 2, JSON.stringify( after ) );

		await page.keyboard.type( 'Third.' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, id );
		const cols = ( raw.match( /<!-- wp:column -->/g ) || [] ).length;
		t.check( 'saved markup has three real columns', cols === 3, String( cols ) );
		t.check( 'the new column carries the typed text', /<!-- wp:column -->[\s\S]*Third\./.test( raw ), raw.slice( -320 ) );
		t.check( 'untouched columns kept their text', raw.includes( '<p>Left.</p>' ) && raw.includes( '<p>Right.</p>' ) );
		t.check( 'no width was invented for the new column', ! /wp:column \{/.test( raw ), raw.slice( 0, 160 ) );
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
