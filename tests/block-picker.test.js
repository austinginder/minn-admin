/**
 * Block picker — the full library browser (⌘/ or the slash menu's
 * "Browse all" row). Groups everything by source (Basics, per-plugin
 * blocks, design libraries, patterns) with a search box; picking inserts
 * through the same dispatch as the slash menu.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'block-picker' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Block picker test',
		content: '<!-- wp:paragraph -->\n<p>Picker.</p>\n<!-- /wp:paragraph -->',
	} );

	try {
		await openEditor( page, id );

		// ⌘/ opens the picker; groups stream in as the async lists land.
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+/' );
		await page.waitForSelector( '.minn-block-picker', { timeout: 5000 } );
		let groups = 0;
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 400 );
			groups = await page.$$eval( '.minn-bp-group', ( els ) => els.length ).catch( () => 0 );
			if ( groups >= 4 ) break;
		}
		t.check( 'picker opens via ⌘/ with grouped sources', groups >= 4, groups + ' groups' );
		const titles = await page.$$eval( '.minn-bp-group h3', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'groups labeled by source', titles.some( ( x ) => /Basics/.test( x ) )
			&& titles.some( ( x ) => /designs/.test( x ) ) && titles.some( ( x ) => /patterns/.test( x ) ),
			titles.slice( 0, 8 ).join( ' | ' ) );

		// Search narrows across every source.
		await page.fill( '#minn-bp-search', 'callout' );
		await page.waitForTimeout( 300 );
		const calloutTiles = await page.$$eval( '.minn-bp-item', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'search narrows to matching tiles', calloutTiles.some( ( x ) => x.includes( 'Callout' ) ), calloutTiles.join( ' | ' ) );

		// Escape closes.
		await page.keyboard.press( 'Escape' );
		t.check( 'Escape closes the picker', ( await page.$( '.minn-block-picker' ) ) === null );

		// The slash menu's Browse row opens it too.
		await freshParagraph( page );
		await page.keyboard.type( '/', { delay: 30 } );
		await page.waitForSelector( '.minn-slash-hint[data-browse]', { timeout: 5000 } );
		await page.dispatchEvent( '.minn-slash-hint[data-browse]', 'mousedown' );
		await page.waitForSelector( '.minn-block-picker', { timeout: 5000 } );
		t.check( 'Browse-all row opens the picker', true );

		// Pick a custom block: lands as an island via the shared dispatch.
		await page.fill( '#minn-bp-search', 'callout' );
		await page.waitForTimeout( 300 );
		await page.evaluate( () => {
			const tile = [ ...document.querySelectorAll( '.minn-bp-item' ) ].find( ( e ) => e.textContent.includes( 'Callout' ) );
			if ( tile ) tile.click();
		} );
		await page.waitForSelector( '.minn-block-island[data-block="anchor/callout"]', { timeout: 10000 } );
		t.check( 'picked block inserts as island', true );
		await page.click( '#minn-editor-title' ); // dismiss auto-opened inspector
		await page.waitForTimeout( 300 );

		// Pick a multi-root pattern via ⌘/ (caret path, no "/" block).
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+/' );
		await page.waitForSelector( '.minn-block-picker', { timeout: 5000 } );
		await page.fill( '#minn-bp-search', 'feature box' );
		let tileFound = false;
		for ( let i = 0; i < 12 && ! tileFound; i++ ) {
			await page.waitForTimeout( 400 );
			tileFound = await page.evaluate( () => {
				const tile = [ ...document.querySelectorAll( '.minn-bp-item' ) ].find( ( e ) => e.textContent.includes( 'Minn Test Feature Box' ) );
				if ( tile ) { tile.click(); return true; }
				return false;
			} );
		}
		t.check( 'pattern tile found and picked', tileFound );
		await page.waitForSelector( '.minn-block-island[data-block="core/group"]', { timeout: 10000 } );
		t.check( 'pattern inserted from picker', true );

		// Saved content carries both inserts.
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).content.raw;
		}, id );
		t.check( 'saved markup carries both inserts',
			raw.includes( 'wp:anchor/callout' ) && raw.includes( 'wp:group' ) && raw.includes( 'Picker.' ),
			raw.slice( 0, 160 ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
