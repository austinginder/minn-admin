/**
 * Buttons live island: load, edit label/URL, new tab, outline, add/remove,
 * slash insert, save round-trip.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'buttons-island' );
	await login( page );

	const content = [
		'<!-- wp:paragraph -->',
		'<p>Before buttons.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:buttons -->',
		'<div class="wp-block-buttons"><!-- wp:button {"url":"https://example.com/start","backgroundColor":"vivid-cyan-blue"} -->',
		'<div class="wp-block-button"><a class="wp-block-button__link has-vivid-cyan-blue-background-color has-background wp-element-button" href="https://example.com/start">Get started</a></div>',
		'<!-- /wp:button -->',
		'',
		'<!-- wp:button {"className":"is-style-outline","url":"https://example.com/more","linkTarget":"_blank","rel":"noreferrer noopener"} -->',
		'<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://example.com/more" target="_blank" rel="noreferrer noopener">Learn more</a></div>',
		'<!-- /wp:button --></div>',
		'<!-- /wp:buttons -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Buttons island ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-buttons-island', { timeout: 20000 } );

	const shape = await page.evaluate( () => {
		const island = document.querySelector( '#minn-editor-body .minn-buttons-island' );
		const rows = [ ...document.querySelectorAll( '#minn-editor-body .minn-btn-row' ) ];
		return {
			island: !! island,
			rowCount: rows.length,
			labels: rows.map( ( r ) => r.querySelector( '.minn-btn-label' )?.value ),
			urls: rows.map( ( r ) => r.querySelector( '.minn-btn-url' )?.value ),
			newTabs: rows.map( ( r ) => !! r.querySelector( '.minn-btn-newtab' )?.checked ),
			outlines: rows.map( ( r ) => !! r.querySelector( '.minn-btn-outline' )?.checked ),
			// First button had a color attr — parked on the row for preserve.
			attrs0: rows[ 0 ]?.dataset.btnAttrs || '',
			freeButtons: [ ...document.querySelectorAll( '#minn-editor-body > .wp-block-buttons' ) ].length,
		};
	} );
	t.check( 'loads as buttons island', shape.island && shape.freeButtons === 0, JSON.stringify( shape ) );
	t.check( 'two button rows', shape.rowCount === 2, JSON.stringify( shape ) );
	t.check( 'labels from fixture', shape.labels[ 0 ] === 'Get started' && shape.labels[ 1 ] === 'Learn more', JSON.stringify( shape ) );
	t.check( 'urls from fixture', /example\.com\/start/.test( shape.urls[ 0 ] ) && /example\.com\/more/.test( shape.urls[ 1 ] ), JSON.stringify( shape ) );
	t.check( 'new-tab on second only', shape.newTabs[ 0 ] === false && shape.newTabs[ 1 ] === true, JSON.stringify( shape ) );
	t.check( 'outline on second only', shape.outlines[ 0 ] === false && shape.outlines[ 1 ] === true, JSON.stringify( shape ) );
	t.check( 'preserves backgroundColor attr', /vivid-cyan-blue|backgroundColor/.test( shape.attrs0 ), shape.attrs0 );

	// Edit first row
	await page.evaluate( () => {
		const row = document.querySelector( '#minn-editor-body .minn-btn-row' );
		const label = row.querySelector( '.minn-btn-label' );
		const url = row.querySelector( '.minn-btn-url' );
		label.value = 'Buy now';
		label.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		url.value = 'https://shop.example.com/buy';
		url.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		const nt = row.querySelector( '.minn-btn-newtab' );
		nt.checked = true;
		nt.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// Add a third button
	await page.click( '#minn-editor-body .minn-buttons-add' );
	await page.waitForTimeout( 200 );
	const afterAdd = await page.evaluate( () => {
		const rows = document.querySelectorAll( '#minn-editor-body .minn-btn-row' );
		const last = rows[ rows.length - 1 ];
		const lab = last.querySelector( '.minn-btn-label' );
		lab.value = 'Contact';
		lab.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		const url = last.querySelector( '.minn-btn-url' );
		url.value = 'https://example.com/contact';
		url.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		return rows.length;
	} );
	t.check( 'add button makes 3 rows', afterAdd === 3, String( afterAdd ) );

	// Save
	await page.keyboard.down( 'Meta' );
	await page.keyboard.press( 's' );
	await page.keyboard.up( 'Meta' );
	await page.waitForTimeout( 600 );
	const saved = await page.evaluate( async ( pid ) => {
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /Update|Save|Publish/.test( b.textContent || '' ) );
		if ( btn ) btn.click();
		await new Promise( ( r ) => setTimeout( r, 1500 ) );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const j = await r.json();
		return j.content && j.content.raw;
	}, id );

	t.check( 'saved has buttons block', /wp:buttons/.test( saved || '' ), ( saved || '' ).slice( 0, 400 ) );
	t.check( 'saved has three buttons', ( saved.match( /wp:button/g ) || [] ).length >= 3, ( saved || '' ).slice( 0, 600 ) );
	t.check( 'saved Buy now label', /Buy now/.test( saved || '' ), ( saved || '' ).slice( 0, 600 ) );
	t.check( 'saved shop URL', /shop\.example\.com\/buy/.test( saved || '' ), ( saved || '' ).slice( 0, 600 ) );
	t.check( 'saved Contact button', /Contact/.test( saved || '' ) && /contact/.test( saved || '' ), ( saved || '' ).slice( 0, 700 ) );
	t.check( 'saved new-tab on Buy now', /Buy now[\s\S]{0,200}target="_blank"|target="_blank"[\s\S]{0,200}Buy now/.test( saved || '' )
		|| ( /Buy now/.test( saved || '' ) && /"linkTarget":"_blank"/.test( saved || '' ) ), ( saved || '' ).slice( 0, 800 ) );
	// Color attr on first button should survive the label edit
	t.check( 'saved keeps backgroundColor', /vivid-cyan-blue|backgroundColor/.test( saved || '' ), ( saved || '' ).slice( 0, 800 ) );
	t.check( 'saved keeps outline on Learn more', /is-style-outline/.test( saved || '' ) && /Learn more/.test( saved || '' ), ( saved || '' ).slice( 0, 800 ) );

	// Remove middle row
	const afterRm = await page.evaluate( () => {
		const rows = [ ...document.querySelectorAll( '#minn-editor-body .minn-btn-row' ) ];
		const del = rows[ 1 ]?.querySelector( '.minn-btn-row-del' );
		if ( del ) del.click();
		return document.querySelectorAll( '#minn-editor-body .minn-btn-row' ).length;
	} );
	t.check( 'remove drops to 2 rows', afterRm === 2, String( afterRm ) );

	// Slash insert buttons
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
	await page.keyboard.type( '/button' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	const hasItem = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => /^Buttons$/i.test( ( el.textContent || '' ).trim() ) || /Buttons/.test( el.textContent || '' ) )
	);
	t.check( 'slash lists Buttons', hasItem, 'menu' );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ]
			.find( ( el ) => /Buttons/i.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 400 );
	const slashShape = await page.evaluate( () => {
		const islands = [ ...document.querySelectorAll( '#minn-editor-body .minn-buttons-island' ) ];
		const newest = islands[ islands.length - 1 ];
		const label = newest && newest.querySelector( '.minn-btn-label' );
		return {
			count: islands.length,
			hasLabel: !! label,
			value: label ? label.value : null,
			focused: !!( label && document.activeElement === label ),
		};
	} );
	t.check( 'slash inserts buttons island', slashShape.count >= 2, JSON.stringify( slashShape ) );
	t.check( 'slash buttons field ready', slashShape.hasLabel && slashShape.value === 'Button', JSON.stringify( slashShape ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
