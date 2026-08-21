/**
 * Slash-menu synonyms (GH #43 follow-on, #44). Typing /hr found nothing:
 * the Divider entry matched on its label only. Basic blocks carry a keywords
 * slot, and the block picker now reads it too, so a synonym finds the block
 * in both places. Typing --- already inserts a divider; that stays covered.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'slash-keywords' );
	await login( page );

	const id = await createPost( page, {
		title: 'Slash keywords probe',
		content: '<!-- wp:paragraph -->\n<p>First.</p>\n<!-- /wp:paragraph -->',
		status: 'draft',
	} );
	await openEditor( page, id );

	const freshLine = async () => {
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Enter' );
	};

	/* ===== /hr finds the Divider in the slash menu ===== */
	await freshLine();
	await page.keyboard.type( '/hr' );
	await page.waitForTimeout( 600 );
	const menu = await page.evaluate( () => {
		const items = [ ...document.querySelectorAll( '.minn-slash-item' ) ];
		return { count: items.length, labels: items.map( ( i ) => i.textContent.trim() ).slice( 0, 6 ) };
	} );
	t.check( '/hr matches at least one block', menu.count > 0, JSON.stringify( menu ) );
	t.check( '/hr offers the Divider', menu.labels.some( ( l ) => /Divider/i.test( l ) ), JSON.stringify( menu ) );

	/* ===== Enter inserts a real separator block ===== */
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 500 );
	t.check( 'a divider lands in the body', await page.evaluate( () => !! document.querySelector( '#minn-editor-body hr' ) ), '' );

	const saveAndRead = async () => {
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
	};
	let saved = await saveAndRead();
	t.check( 'it saves as a separator block', /<!-- wp:separator/.test( saved ), saved.slice( 0, 300 ) );

	/* ===== Other synonyms reach it too ===== */
	for ( const q of [ 'rule', 'separator' ] ) {
		await freshLine();
		await page.keyboard.type( '/' + q );
		await page.waitForTimeout( 500 );
		const hit = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( i ) => /Divider/i.test( i.textContent ) ) );
		t.check( `/${ q } also finds the Divider`, hit, '' );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );
	}

	/* ===== The block picker searches the same synonyms ===== */
	await freshLine();
	await page.keyboard.press( 'Meta+/' );
	await page.waitForSelector( '.minn-block-picker', { timeout: 8000 } );
	await page.keyboard.type( 'hr' );
	await page.waitForTimeout( 600 );
	const picker = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-block-picker [data-bp], .minn-block-picker .minn-bp-item' ) ]
			.map( ( i ) => i.textContent.trim() ).filter( Boolean ).slice( 0, 8 ) );
	t.check( 'the block picker finds Divider by "hr"', picker.some( ( l ) => /Divider/i.test( l ) ), JSON.stringify( picker ) );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== --- still converts, so the documented shortcut keeps working ===== */
	await freshLine();
	await page.keyboard.type( '---' );
	await page.waitForTimeout( 400 );
	const dashes = await page.evaluate( () => document.querySelectorAll( '#minn-editor-body hr' ).length );
	t.check( 'typing --- inserts a divider', dashes >= 2, String( dashes ) );
	saved = await saveAndRead();
	t.check( 'both dividers save as separator blocks', ( saved.match( /<!-- wp:separator/g ) || [] ).length >= 2, saved.slice( 0, 400 ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
