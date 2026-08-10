/**
 * Column editing: /column, the right-click column menu (add before / add
 * after / remove, with Undo), and inserting a whole Columns row.
 * Verifies the SAVED markup: the new column is a real core/column, the rest
 * of the block keeps its text, and no width is invented for the new column
 * (which would silently squeeze the row).
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

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
	const saveAndRead = async ( pid ) => {
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		return page.evaluate( async ( p2 ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p2 + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, pid );
	};

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
		// It lands NEXT TO the column you were in, not at the far end.
		t.check( 'the caret lands in the new column, beside the one you were in', after.caretInNew === 1, JSON.stringify( after ) );

		await page.keyboard.type( 'Third.' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		let raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, id );
		const cols = ( raw.match( /<!-- wp:column -->/g ) || [] ).length;
		t.check( 'saved markup has three real columns', cols === 3, String( cols ) );
		t.check( 'the new column carries the typed text', /<p>Left\.<\/p>[\s\S]*Third\.[\s\S]*<p>Right\.<\/p>/.test( raw ), raw.slice( 0, 400 ) );
		t.check( 'untouched columns kept their text', raw.includes( '<p>Left.</p>' ) && raw.includes( '<p>Right.</p>' ) );
		t.check( 'no width was invented for the new column', ! /wp:column \{/.test( raw ), raw.slice( 0, 160 ) );

		// --- Right-click menu: add before / add after / remove ---
		const colBox = ( i ) => page.evaluate( ( n ) => {
			const cols = [ ...document.querySelectorAll( '.minn-cols-island .minn-slot' ) ].map( ( s2 ) => s2.parentElement );
			const el = cols[ n ];
			el.scrollIntoView( { block: 'center' } );
			const r = el.getBoundingClientRect();
			return { x: r.left + r.width / 2, y: r.top + 12 };
		}, i );
		const menuLabels = async ( i ) => {
			const at = await colBox( i );
			await page.mouse.click( at.x, at.y, { button: 'right' } );
			await page.waitForSelector( '.minn-ctx-menu', { timeout: 6000 } );
			return page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].map( ( b ) => b.textContent.trim() ) );
		};
		const runMenu = ( label ) => page.evaluate( ( l ) => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => x.textContent.trim() === l );
			if ( ! b ) return false;
			b.click();
			return true;
		}, label );

		const ops = await menuLabels( 0 );
		t.check( 'right-click in a column offers the column ops',
			[ 'Add column before', 'Add column after', 'Remove column' ].every( ( l ) => ops.includes( l ) ), JSON.stringify( ops ) );
		t.check( 'add column before ran', await runMenu( 'Add column before' ) );
		await page.waitForTimeout( 800 );
		let count = await page.evaluate( () => document.querySelectorAll( '.minn-cols-island .minn-slot' ).length );
		t.check( 'a fourth column appears, before the first', count === 4, String( count ) );
		raw = await saveAndRead( id );
		t.check( 'the new column is first in the saved markup',
			/<!-- wp:column -->\s*<div class="wp-block-column"><\/div>[\s\S]*<p>Left\.<\/p>/.test( raw ), raw.slice( 0, 260 ) );

		// Remove it again, then take the Undo.
		await menuLabels( 0 );
		t.check( 'remove column ran', await runMenu( 'Remove column' ) );
		await page.waitForTimeout( 800 );
		count = await page.evaluate( () => document.querySelectorAll( '.minn-cols-island .minn-slot' ).length );
		t.check( 'the column is gone', count === 3, String( count ) );
		const undo = await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-toast button' ) ].find( ( x ) => /undo/i.test( x.textContent ) );
			if ( ! b ) return false;
			b.click();
			return true;
		} );
		t.check( 'removing a column offers Undo', undo );
		await page.waitForTimeout( 800 );
		count = await page.evaluate( () => document.querySelectorAll( '.minn-cols-island .minn-slot' ).length );
		t.check( 'Undo puts the column back', count === 4, String( count ) );
		raw = await saveAndRead( id );
		t.check( 'the restored column survives a save', ( raw.match( /<!-- wp:column -->/g ) || [] ).length === 4, String( ( raw.match( /<!-- wp:column -->/g ) || [] ).length ) );
		t.check( 'the columns that were never touched still read the same', raw.includes( '<p>Left.</p>' ) && raw.includes( '<p>Right.</p>' ) && /Third\./.test( raw ) );
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => {} );
	}
	// --- Inserting a whole row of columns (the group case) ---
	{
		const gid = await createPost( page, { title: 'Columns row probe', content: '<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Inside the group.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->' } );
		try {
			await openEditor( page, gid );
			await page.waitForSelector( '.minn-slot-island', { timeout: 20000 } );
			await page.waitForTimeout( 1500 );
			const spot = await page.evaluate( () => {
				const p = document.querySelector( '.minn-slot p' );
				p.scrollIntoView( { block: 'center' } );
				const r = p.getBoundingClientRect();
				return { x: r.left + Math.min( 40, r.width / 2 ), y: r.top + r.height / 2 };
			} );
			await page.mouse.click( spot.x, spot.y );
			// Park the caret at the paragraph's END: a click lands wherever it
			// lands, and End scrolls rather than moving the caret on macOS.
			// Focus the SLOT, never the editor body — that pulls the caret out
			// of the container's own contenteditable.
			await page.evaluate( () => {
				const p = document.querySelector( '.minn-slot p' );
				p.closest( '.minn-slot' ).focus( { preventScroll: true } );
				const r = document.createRange();
				r.selectNodeContents( p );
				r.collapse( false );
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( r );
			} );
			await page.keyboard.press( 'Enter' );
			await page.keyboard.type( '/columns' );
			await page.waitForSelector( '.minn-slash-item', { timeout: 6000 } );
			const found = await page.evaluate( () => {
				const el = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( e ) => /^Columns$/.test( e.textContent.trim() ) );
				if ( ! el ) return false;
				el.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
				return true;
			} );
			t.check( 'the menu offers a Columns row inside a group', found );
			await page.waitForTimeout( 1500 );
			const cols = await page.evaluate( () => document.querySelectorAll( '.minn-cols-island .minn-slot' ).length );
			t.check( 'the row lands as two writable columns', cols === 2, String( cols ) );
			await page.keyboard.press( 'Meta+s' );
			await page.waitForTimeout( 3000 );
			const raw2 = await page.evaluate( async ( pid ) => {
				const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
				return ( await r.json() ).content.raw;
			}, gid );
			t.check( 'the saved row is a real columns block inside the group',
				/<!-- wp:group[\s\S]*<!-- wp:columns -->[\s\S]*<!-- wp:column -->[\s\S]*<!-- \/wp:columns -->[\s\S]*<!-- \/wp:group -->/.test( raw2 ),
				raw2.slice( 0, 300 ) );
			t.check( 'the group keeps its own paragraph', raw2.includes( '<p>Inside the group.</p>' ) );
		} finally {
			await deletePost( page, gid ).catch( () => {} );
		}
	}

	// --- A photo in a narrow column gets the space, not the frame ---
	{
		const cols = Array.from( { length: 4 }, () =>
			'<!-- wp:column -->\n<div class="wp-block-column"><!-- wp:image {"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="' + BASE + '/wp-content/uploads/gal-red.png" alt=""/></figure>\n<!-- /wp:image --></div>\n<!-- /wp:column -->' ).join( '\n' );
		const iid = await createPost( page, { title: 'Column image probe', content: '<!-- wp:columns -->\n<div class="wp-block-columns">' + cols + '</div>\n<!-- /wp:columns -->' } );
		try {
			await openEditor( page, iid );
			// Wait on the LOADED image rather than Playwright's visibility check:
			// a fresh <img> has no box until it decodes.
			await page.waitForFunction( () => {
				const im = document.querySelector( '.minn-cols-island .minn-slot img' );
				return im && im.complete && im.getBoundingClientRect().width > 1;
			}, null, { timeout: 25000 } );
			const fit = await page.evaluate( () => {
				const img = document.querySelector( '.minn-cols-island .minn-slot img' );
				const fig = img.closest( 'figure' );
				return {
					img: Math.round( img.getBoundingClientRect().width ),
					figure: Math.round( fig.getBoundingClientRect().width ),
					column: Math.round( fig.closest( '.wp-block-column' ).getBoundingClientRect().width ),
				};
			} );
			// The editor frame around an image is generous at full width and
			// used to eat a THIRD of a four-up column.
			t.check( 'a photo keeps most of its column', fit.img >= fit.figure * 0.85, JSON.stringify( fit ) );
			t.check( 'the column itself is not squeezed', fit.figure >= fit.column * 0.8, JSON.stringify( fit ) );
		} finally {
			await deletePost( page, iid ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
