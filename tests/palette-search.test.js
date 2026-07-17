/**
 * ⌘K content search: typing in the palette surfaces your own posts, pages
 * and CPTs below the command matches (core wp/v2/search, debounced, _fields
 * allowlist — never rendered content), and picking a row opens the Minn
 * editor. Commands stay first and keep working; unknown subtypes drop;
 * results ride the same arrow/Enter machinery as commands.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'palette-search' );
	const { browser, page, errors } = await launch();
	await login( page );

	const TITLE = 'Palette Probe Nightjar ZQX';
	let postId = null;
	try {
		postId = await createPost( page, {
			title: TITLE,
			content: '<!-- wp:paragraph --><p>Search me from the palette.</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );

		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );

		/* ===== Commands still filter as before ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'Go to Overview' );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-palette-item' ) ].some( ( e ) => e.textContent.includes( 'Go to Overview' ) ),
		null, { timeout: 5000 } );
		t.check( 'command filtering unchanged', true );

		/* ===== Typing a title fragment surfaces the post ===== */
		await page.fill( '#minn-palette-input', '' );
		await page.evaluate( () => document.querySelector( '#minn-palette-input' ).dispatchEvent( new Event( 'input', { bubbles: true } ) ) );
		await page.keyboard.type( 'Nightjar ZQX' );
		await page.waitForFunction( ( title ) =>
			[ ...document.querySelectorAll( '.minn-palette-item' ) ].some( ( e ) => e.textContent.includes( title ) ),
		TITLE, { timeout: 15000 } );
		const rowState = await page.evaluate( ( title ) => {
			const items = [ ...document.querySelectorAll( '.minn-palette-item' ) ];
			const row = items.find( ( e ) => e.textContent.includes( title ) );
			return {
				kind: row.querySelector( '.minn-palette-kind' ).textContent.trim(),
				divider: !! document.querySelector( '.minn-palette-sec' ),
				dividerText: ( document.querySelector( '.minn-palette-sec' ) || {} ).textContent || '',
			};
		}, TITLE );
		t.check( 'draft result carries its status as the kind', /draft/i.test( rowState.kind ), rowState.kind );
		t.check( 'Your content section label renders', rowState.divider && /Your content/.test( rowState.dividerText ) );

		/* ===== Enter on the selected result opens the Minn editor ===== */
		// Arrow down until the content row is selected, then Enter.
		const picked = await page.evaluate( ( title ) => {
			const items = [ ...document.querySelectorAll( '.minn-palette-item' ) ];
			return items.findIndex( ( e ) => e.textContent.includes( title ) );
		}, TITLE );
		for ( let i = 0; i < picked; i++ ) {
			await page.keyboard.press( 'ArrowDown' );
		}
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		const loc = await page.evaluate( () => location.pathname );
		t.check( 'Enter opens the Minn editor for the found post', loc.includes( `/editor/posts/${ postId }` ), loc );
		t.check( 'editor loads the found post', await page.evaluate( ( title ) =>
			( document.querySelector( '#minn-editor-title, .minn-editor-title, textarea' ) || {} ).value?.includes?.( title )
			|| document.body.textContent.includes( title ), TITLE ) );

		/* ===== Gibberish query: no content rows, honest empty state ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'zzqqxxnothingmatches' );
		await page.waitForFunction( () => {
			const list = document.querySelector( '#minn-palette-list' );
			return list && /No results|Searching/.test( list.textContent ) === false
				? false
				: list && ! document.querySelector( '.minn-palette-sec' );
		}, null, { timeout: 15000 } );
		// Let the debounce land and confirm it settles on No results.
		await page.waitForFunction( () =>
			/No results/.test( document.querySelector( '#minn-palette-list' ).textContent ),
		null, { timeout: 15000 } );
		t.check( 'gibberish settles on the empty state with no content rows',
			! ( await page.$( '.minn-palette-sec' ) ) );
		await page.keyboard.press( 'Escape' );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
