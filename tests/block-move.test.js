/**
 * Moving blocks in the editor (issue #69): a pasted image had no way to go up
 * or down between paragraphs. Dragging it dropped a copy and left the
 * original; its popover had no arrows.
 *
 * - The image, code and table popovers carry ↑ ↓; each press moves the block
 *   past its neighbour and the popover stays open.
 * - Dragging an image (or an island by its picture) moves it: one copy,
 *   landing above or below the block under the pointer.
 * - ⌘⇧⌥T inside a Columns slot is saved (the slot is re-spliced).
 * Every case reads the SAVED content, not just the editor DOM.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const P = ( t ) => `<!-- wp:paragraph -->\n<p>${ t }</p>\n<!-- /wp:paragraph -->`;
const IMG = '<!-- wp:image -->\n<figure class="wp-block-image"><img src="https://example.com/move.jpg" alt="Mover"/></figure>\n<!-- /wp:image -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'block-move' );
	await login( page );
	const made = [];

	const savedRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async ( id ) => {
		const before = await savedRaw( id );
		await page.keyboard.press( 'Meta+s' );
		let raw = before;
		for ( let i = 0; i < 120 && raw === before; i++ ) {
			await page.waitForTimeout( 500 );
			raw = await savedRaw( id );
		}
		return raw;
	};
	// The order of markers in saved content.
	const orderOf = ( raw, marks ) => marks.map( ( m ) => [ m, raw.indexOf( m ) ] ).filter( ( x ) => x[ 1 ] >= 0 ).sort( ( a, b ) => a[ 1 ] - b[ 1 ] ).map( ( x ) => x[ 0 ] ).join( ',' );
	const fresh = async ( title, content ) => {
		const id = await createPost( page, { title, content } );
		made.push( id );
		await openEditor( page, id );
		return id;
	};
	const popClick = async ( popSel, dir ) => {
		await page.click( `${ popSel } [data-blockmove="${ dir }"]` );
		await page.waitForTimeout( 250 );
	};

	try {
		/* Image popover arrows. */
		const imgId = await fresh( 'Move: image arrows', [ P( 'Alpha' ), IMG, P( 'Beta' ), P( 'Gamma' ) ].join( '\n\n' ) );
		await page.click( '#minn-editor-body figure img' );
		const arrows = await page.waitForSelector( '.minn-img-pop [data-blockmove="down"]', { timeout: 10000 } ).catch( () => null );
		t.check( 'the image popover has ↑ ↓', !! arrows );
		if ( arrows ) await popClick( '.minn-img-pop', 'down' );
		t.check( 'the image popover stays open after a move', !! await page.$( '.minn-img-pop' ) );
		if ( arrows ) await popClick( '.minn-img-pop', 'down' );
		let raw = await save( imgId );
		t.check( 'image ↓ ↓ lands it after Gamma, saved', 'Alpha,Beta,Gamma,move.jpg' === orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ), orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ) );
		if ( arrows ) {
			await popClick( '.minn-img-pop', 'up' );
			await popClick( '.minn-img-pop', 'up' );
			await popClick( '.minn-img-pop', 'up' );
		}
		raw = await save( imgId );
		t.check( 'image ↑ ↑ ↑ lands it first, saved', 'move.jpg,Alpha,Beta,Gamma' === orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ), orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ) );
		t.check( 'and the image is saved once', 1 === ( raw.match( /move\.jpg/g ) || [] ).length );

		/* Dragging the image moves it: one copy, below the block under the pointer. */
		const dragId = await fresh( 'Move: image drag', [ P( 'Alpha' ), IMG, P( 'Beta' ), P( 'Gamma' ) ].join( '\n\n' ) );
		const drag = ( fromSel, toText, below ) => page.evaluate( ( a ) => {
			const body = document.querySelector( '#minn-editor-body' );
			const src = body.querySelector( a.fromSel );
			const dst = [ ...body.querySelectorAll( 'p' ) ].find( ( p ) => p.textContent.trim() === a.toText );
			const r = dst.getBoundingClientRect();
			const y = a.below ? r.bottom - 2 : r.top + 2;
			const dt = new DataTransfer();
			const fire = ( el, type, x, yy ) => el.dispatchEvent( new DragEvent( type, { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: yy } ) );
			const sr = src.getBoundingClientRect();
			fire( src, 'dragstart', sr.left + 5, sr.top + 5 );
			fire( dst, 'dragover', r.left + 10, y );
			const line = !! document.querySelector( '.minn-block-drop-line' );
			fire( dst, 'drop', r.left + 10, y );
			fire( src, 'dragend', r.left + 10, y );
			return { line, lineAfter: !! document.querySelector( '.minn-block-drop-line' ), figures: body.querySelectorAll( 'figure' ).length };
		}, { fromSel: fromSel, toText, below } );
		let d = await drag( 'figure img', 'Gamma', true );
		t.check( 'a drop line shows while dragging, and goes after', d.line && ! d.lineAfter, JSON.stringify( d ) );
		t.check( 'dragging the image moves it (no copy)', 1 === d.figures, JSON.stringify( d ) );
		raw = await save( dragId );
		t.check( 'dragged below Gamma, saved', 'Alpha,Beta,Gamma,move.jpg' === orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ), orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ) );
		d = await drag( 'figure img', 'Alpha', false );
		raw = await save( dragId );
		t.check( 'dragged above Alpha, saved once', 'move.jpg,Alpha,Beta,Gamma' === orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ) && 1 === ( raw.match( /move\.jpg/g ) || [] ).length, orderOf( raw, [ 'Alpha', 'Beta', 'Gamma', 'move.jpg' ] ) );

		/* An island moves by dragging its picture. */
		const islId = await fresh( 'Move: island drag', [ P( 'Alpha' ), '<!-- wp:acme/pic -->\n<div class="acme-pic"><img src="https://example.com/island.jpg" alt=""/></div>\n<!-- /wp:acme/pic -->', P( 'Beta' ) ].join( '\n\n' ) );
		await page.waitForSelector( '#minn-editor-body .minn-block-island img', { timeout: 20000 } );
		d = await drag( '.minn-block-island img', 'Beta', true );
		raw = await save( islId );
		t.check( 'an island dragged by its picture moves, saved', 'Alpha,Beta,acme/pic' === orderOf( raw, [ 'Alpha', 'Beta', 'acme/pic' ] ) && 1 === ( raw.match( /island\.jpg/g ) || [] ).length, orderOf( raw, [ 'Alpha', 'Beta', 'acme/pic' ] ) );

		/* Code and table popovers. */
		const codeId = await fresh( 'Move: code and table', [ P( 'Alpha' ), '<!-- wp:code -->\n<pre class="wp-block-code"><code>CODEBLOCK</code></pre>\n<!-- /wp:code -->', P( 'Beta' ), '<!-- wp:table -->\n<figure class="wp-block-table"><table><tbody><tr><td>TABLECELL</td></tr></tbody></table></figure>\n<!-- /wp:table -->' ].join( '\n\n' ) );
		// Each chip opens its block's popover (code, table, image), clicked
		// in the page the way a reader's click reaches it.
		const openChip = ( kind ) => page.evaluate( ( k ) => {
			const c = [ ...document.querySelectorAll( '.minn-code-chip' ) ].find( ( x ) => x._kind === k );
			if ( c ) c.click();
			return !! c;
		}, kind );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-code-chip' ) ].some( ( x ) => x._kind === 'code' ), null, { timeout: 15000 } ).catch( () => null );
		if ( await openChip( 'code' ) ) {
			if ( await page.waitForSelector( '[data-blockmove="down"]', { timeout: 10000 } ).catch( () => null ) ) {
				await page.click( '[data-blockmove="down"]' );
				await page.waitForTimeout( 300 );
			}
		}
		raw = await save( codeId );
		t.check( 'code ↓ moves it past Beta, saved', 'Alpha,Beta,CODEBLOCK,TABLECELL' === orderOf( raw, [ 'Alpha', 'Beta', 'CODEBLOCK', 'TABLECELL' ] ), orderOf( raw, [ 'Alpha', 'Beta', 'CODEBLOCK', 'TABLECELL' ] ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );
		if ( await openChip( 'table' ) ) {
			if ( await page.waitForSelector( '.minn-table-pop [data-blockmove="up"]', { timeout: 10000 } ).catch( () => null ) ) {
				await popClick( '.minn-table-pop', 'up' );
				await popClick( '.minn-table-pop', 'up' );
			}
		}
		raw = await save( codeId );
		t.check( 'table ↑ ↑ moves it above the code and Beta, saved', 'Alpha,TABLECELL,Beta,CODEBLOCK' === orderOf( raw, [ 'Alpha', 'Beta', 'CODEBLOCK', 'TABLECELL' ] ), orderOf( raw, [ 'Alpha', 'Beta', 'CODEBLOCK', 'TABLECELL' ] ) );

		/* ⌘⇧⌥T inside a Columns slot is saved. */
		const colsId = await fresh( 'Move: in a column', '<!-- wp:columns -->\n<div class="wp-block-columns"><!-- wp:column -->\n<div class="wp-block-column"><!-- wp:paragraph -->\n<p>ColOne</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>ColTwo</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column -->\n<div class="wp-block-column"><!-- wp:paragraph -->\n<p>ColThree</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->' );
		await page.waitForSelector( '#minn-editor-body .minn-slot p', { timeout: 20000 } );
		await page.evaluate( () => {
			const p = [ ...document.querySelectorAll( '#minn-editor-body .minn-slot p' ) ].find( ( x ) => x.textContent.trim() === 'ColTwo' );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			getSelection().removeAllRanges();
			getSelection().addRange( r );
			p.closest( '[contenteditable="true"]' ).focus();
		} );
		await page.keyboard.press( 'Meta+Shift+Alt+T' );
		await page.waitForTimeout( 300 );
		// A typed character makes the save carry a change either way.
		raw = await save( colsId );
		t.check( '⌘⇧⌥T in a column is saved', 'ColTwo,ColOne,ColThree' === orderOf( raw, [ 'ColOne', 'ColTwo', 'ColThree' ] ), orderOf( raw, [ 'ColOne', 'ColTwo', 'ColThree' ] ) );
		/* Review 13 M-1: a list wearing .minn-slot is prose, not a root. */
		const lookId = await fresh( 'Move: look-alike slot', [ P( 'Alpha' ), IMG, '<!-- wp:list -->\n<ul class="wp-block-list minn-slot"><!-- wp:list-item -->\n<li>Listed</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->' ].join( '\n\n' ) );
		d = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const src = body.querySelector( 'figure img' );
			const li = body.querySelector( 'li' );
			const r = li.getBoundingClientRect();
			const dt = new DataTransfer();
			const fire = ( el, type, y ) => el.dispatchEvent( new DragEvent( type, { bubbles: true, cancelable: true, dataTransfer: dt, clientX: r.left + 10, clientY: y } ) );
			fire( src, 'dragstart', r.top );
			fire( li, 'dragover', r.bottom - 2 );
			fire( li, 'drop', r.bottom - 2 );
			fire( src, 'dragend', r.bottom - 2 );
			return { inList: !! body.querySelector( 'ul figure, li figure' ) };
		} );
		raw = await save( lookId );
		t.check( 'a drop on a list wearing .minn-slot lands beside the list, not in it', ! d.inList && /<!-- \/wp:list -->\s*<!-- wp:image/.test( raw ), JSON.stringify( d ) + ' ' + raw.slice( -260 ) );

		/* Review 13 L-4: a drag whose dragend never came does not take a later drop. */
		const staleId = await fresh( 'Move: stale drag', [ P( 'Alpha' ), IMG, P( 'Beta' ) ].join( '\n\n' ) );
		d = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const src = body.querySelector( 'figure img' );
			const beta = [ ...body.querySelectorAll( 'p' ) ].find( ( p ) => p.textContent.trim() === 'Beta' );
			const r = beta.getBoundingClientRect();
			src.dispatchEvent( new DragEvent( 'dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer(), clientX: r.left, clientY: r.top } ) );
			// No dragend. Later, a plain text drag drops on Beta.
			const dt2 = new DataTransfer();
			dt2.setData( 'text/plain', 'dragged words' );
			beta.dispatchEvent( new DragEvent( 'dragover', { bubbles: true, cancelable: true, dataTransfer: dt2, clientX: r.left + 5, clientY: r.bottom - 2 } ) );
			const ev = new DragEvent( 'drop', { bubbles: true, cancelable: true, dataTransfer: dt2, clientX: r.left + 5, clientY: r.bottom - 2 } );
			beta.dispatchEvent( ev );
			const order = [ ...body.children ].map( ( n ) => n.tagName === 'FIGURE' ? 'IMG' : n.textContent.trim() ).filter( Boolean ).join( ',' );
			return { order, line: !! document.querySelector( '.minn-block-drop-line' ) };
		} );
		t.check( 'a stale drag does not move the image on a later text drop', 'Alpha,IMG,Beta' === d.order && ! d.line, JSON.stringify( d ) );

		/* Review 13 L-3: the table's ↑ ↓ keep the caret in its cell. */
		const tcId = await fresh( 'Move: table caret', [ P( 'Alpha' ), '<!-- wp:table -->\n<figure class="wp-block-table"><table><tbody><tr><td>RowOne</td></tr><tr><td>RowTwo</td></tr></tbody></table></figure>\n<!-- /wp:table -->' ].join( '\n\n' ) );
		await page.evaluate( () => {
			const td = [ ...document.querySelectorAll( '#minn-editor-body td' ) ].find( ( x ) => x.textContent === 'RowTwo' );
			const r = document.createRange();
			r.setStart( td.firstChild, 3 );
			r.collapse( true );
			getSelection().removeAllRanges();
			getSelection().addRange( r );
			document.querySelector( '#minn-editor-body' ).focus();
		} );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-code-chip' ) ].some( ( x ) => x._kind === 'table' ), null, { timeout: 15000 } ).catch( () => null );
		if ( await openChip( 'table' ) && await page.waitForSelector( '.minn-table-pop [data-blockmove="up"]', { timeout: 10000 } ).catch( () => null ) ) {
			await popClick( '.minn-table-pop', 'up' );
		}
		const caretCell = await page.evaluate( () => {
			const s = getSelection();
			const n = s.rangeCount ? s.anchorNode : null;
			const el = n && ( n.nodeType === 1 ? n : n.parentElement );
			return el && el.closest ? ( el.closest( 'td' ) || {} ).textContent || null : null;
		} );
		t.check( 'the caret stays in its table cell through a move', 'RowTwo' === caretCell, String( caretCell ) );

		/* Review 13 L-6: the chip hover's border never reaches a save. */
		const hoverId = await fresh( 'Move: hover border', [ P( 'Alpha' ), '<!-- wp:image {"id":42468} -->\n<figure class="wp-block-image"><img src="https://example.com/hover.jpg" alt="" class="wp-image-42468"/></figure>\n<!-- /wp:image -->', P( 'Beta' ) ].join( '\n\n' ) );
		await page.evaluate( () => {
			const fig = document.querySelector( '#minn-editor-body figure' );
			fig.style.borderColor = 'var(--accent)';
			const b = [ ...document.querySelectorAll( '#minn-editor-body p' ) ].find( ( p ) => p.textContent.trim() === 'Beta' );
			b.textContent = 'Beta edited';
		} );
		raw = await save( hoverId );
		t.check( 'a hovered image saves without the hover border', /hover\.jpg/.test( raw ) && ! /border-color/.test( raw ), raw.slice( 0, 300 ) );
	} finally {
		for ( const id of made ) await deletePost( page, id ).catch( () => {} );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
