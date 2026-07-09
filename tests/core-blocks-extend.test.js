/**
 * Pullquote + details as editable prose; spacer/file/shortcode as slash islands.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'core-blocks-extend' );
	await login( page );

	const content = [
		'<!-- wp:paragraph -->',
		'<p>Lead-in.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:pullquote -->',
		'<figure class="wp-block-pullquote"><blockquote><p>Original pull</p><cite>Ada</cite></blockquote></figure>',
		'<!-- /wp:pullquote -->',
		'',
		'<!-- wp:details -->',
		'<details class="wp-block-details"><summary>Orig summary</summary><p>Orig body</p></details>',
		'<!-- /wp:details -->',
		'',
		'<!-- wp:spacer {"height":"40px"} -->',
		'<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>',
		'<!-- /wp:spacer -->',
		'',
		'<!-- wp:shortcode -->',
		'[gallery ids="1"]',
		'<!-- /wp:shortcode -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Core blocks extend ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture post', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
	await page.waitForFunction( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return b && /Original pull|Orig summary/.test( b.innerText || '' );
	}, null, { timeout: 15000 } );

	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		return {
			pull: !! body.querySelector( 'figure.wp-block-pullquote' ),
			pullEditable: body.querySelector( 'figure.wp-block-pullquote' )?.isContentEditable !== false
				&& ! body.querySelector( 'figure.wp-block-pullquote' )?.closest( '.minn-block-island' ),
			details: !! body.querySelector( 'details.wp-block-details' ),
			detailsNotIsland: ! body.querySelector( 'details.wp-block-details' )?.closest( '.minn-block-island' ),
			spacerIsland: [ ...body.querySelectorAll( '.minn-block-island' ) ].some( ( el ) => /spacer/.test( el.dataset.block || '' ) ),
			shortcodeIsland: [ ...body.querySelectorAll( '.minn-block-island' ) ].some( ( el ) => /shortcode/.test( el.dataset.block || '' ) ),
			text: body.innerText,
		};
	} );
	t.check( 'pullquote is live HTML (not island)', shape.pull && shape.pullEditable, JSON.stringify( shape ) );
	t.check( 'details is live HTML (not island)', shape.details && shape.detailsNotIsland, JSON.stringify( shape ) );
	t.check( 'spacer loads as island', shape.spacerIsland, JSON.stringify( shape ) );
	t.check( 'shortcode loads as island', shape.shortcodeIsland, JSON.stringify( shape ) );

	// Edit pullquote text
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body figure.wp-block-pullquote p' );
		if ( p ) p.textContent = 'Edited pullquote line';
		const sum = document.querySelector( '#minn-editor-body details.wp-block-details summary' );
		if ( sum ) sum.textContent = 'Edited summary';
		const bodyP = document.querySelector( '#minn-editor-body details.wp-block-details > p' );
		if ( bodyP ) bodyP.textContent = 'Edited details body';
		// Mark dirty the way typing would
		if ( window.MINN && document.querySelector( '#minn-editor-body' ) ) {
			document.querySelector( '#minn-editor-body' ).dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	} );

	// Save via keyboard
	await page.keyboard.down( 'Meta' );
	await page.keyboard.press( 's' );
	await page.keyboard.up( 'Meta' );
	// Fallback: click Update if present
	await page.waitForTimeout( 800 );
	const saved = await page.evaluate( async ( pid ) => {
		// Force a save through the app API with serializer if needed
		const body = document.querySelector( '#minn-editor-body' );
		// Trigger save button if any
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /Update|Save|Publish/.test( b.textContent || '' ) );
		if ( btn ) btn.click();
		// Wait a beat then fetch raw content
		await new Promise( ( r ) => setTimeout( r, 1500 ) );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const j = await r.json();
		return j.content && j.content.raw;
	}, id );

	t.check( 'saved has pullquote block', /wp:pullquote/.test( saved || '' ), ( saved || '' ).slice( 0, 400 ) );
	t.check( 'saved pullquote text', /Edited pullquote line/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved has details block', /wp:details/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved details summary', /Edited summary/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved details body', /Edited details body/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved keeps spacer', /wp:spacer/.test( saved || '' ), ( saved || '' ).slice( 0, 300 ) );
	t.check( 'saved keeps shortcode', /wp:shortcode/.test( saved || '' ) && /gallery ids/.test( saved || '' ), ( saved || '' ).slice( 0, 400 ) );

	// Slash insert pullquote + spacer
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/pull' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	const hasPull = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => /Pullquote/i.test( el.textContent || '' ) )
	);
	t.check( 'slash lists Pullquote', hasPull, 'menu' );
	// Pick it
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /Pullquote/i.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 300 );
	const afterSlash = await page.evaluate( () =>
		!! document.querySelector( '#minn-editor-body figure.wp-block-pullquote' )
	);
	t.check( 'slash inserts live pullquote', afterSlash, 'dom' );

	// Slash spacer
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/spacer' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /^Spacer$/i.test( ( el.textContent || '' ).trim() ) || /Spacer/.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 400 );
	const spacerOk = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-block-island' ) ].some( ( el ) => /spacer/.test( el.dataset.block || '' ) )
	);
	t.check( 'slash inserts spacer island', spacerOk, 'dom' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
