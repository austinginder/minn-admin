/**
 * Post Tags: a comma-separated paste (Gutenberg's behaviour) must create
 * one chip per name, not one mashed-together tag (GH #56).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'tag-paste' );
	await login( page );

	const stamp = Date.now();
	const names = [ `tp-${ stamp }-crystal-healing`, `tp-${ stamp }-energy-grids`, `tp-${ stamp }-holistic-healing` ];
	const mashed = names.join( ' ' ); // what the old replace(/,/g,'') path produced
	const createdTagIds = [];

	const openSettings = async () => {
		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '.minn-editor-side-modal #minn-editor-tag-input', { timeout: 10000 } );
	};
	const chipNames = () => page.evaluate( () =>
		[ ...document.querySelectorAll( '#minn-editor-tags [data-tagchip]' ) ]
			.map( ( c ) => c.textContent.replace( /\s*×\s*$/, '' ).trim() )
	);
	const pasteIntoTags = ( text ) => page.evaluate( ( clip ) => {
		const el = document.querySelector( '#minn-editor-tag-input' );
		el.focus();
		const dt = new DataTransfer();
		dt.setData( 'text/plain', clip );
		el.dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
	}, text );
	const savedTags = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=tags`, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const ids = ( await r.json() ).tags || [];
		if ( ! ids.length ) return [];
		const t = await fetch( window.MINN.restUrl + 'wp/v2/tags?include=' + ids.join( ',' ) + '&per_page=100&_fields=id,name', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await t.json() ).map( ( x ) => x.name );
	}, id );
	const save = async ( id ) => {
		await page.keyboard.press( 'Meta+s' );
		const start = Date.now();
		let tags = await savedTags( id );
		while ( tags.length < 3 && Date.now() - start < 12000 ) {
			await page.waitForTimeout( 400 );
			tags = await savedTags( id );
		}
		return tags;
	};

	const id = await createPost( page, {
		title: 'Tag paste',
		content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( page, id );
	await openSettings();

	await pasteIntoTags( names.join( ', ' ) );
	const start = Date.now();
	let chips = await chipNames();
	while ( ! names.every( ( n ) => chips.includes( n ) ) && Date.now() - start < 15000 ) {
		await page.waitForTimeout( 250 );
		chips = await chipNames();
	}
	t.check( 'paste splits a comma list into separate chips',
		names.every( ( n ) => chips.includes( n ) ) && chips.length >= 3,
		JSON.stringify( chips ) );
	t.check( 'paste does not mash the list into one tag',
		! chips.includes( mashed ) && ! chips.some( ( c ) => c.includes( ',' ) ),
		JSON.stringify( chips ) );
	t.check( 'the tag field is empty after a split paste',
		( await page.inputValue( '#minn-editor-tag-input' ) ) === '' );

	createdTagIds.push( ...( await page.evaluate( () =>
		[ ...document.querySelectorAll( '#minn-editor-tags [data-tagchip]' ) ].map( ( c ) => parseInt( c.dataset.tagchip, 10 ) )
	) ) );

	await page.keyboard.press( 'Escape' );
	const saved = await save( id );
	t.check( 'saved post carries each pasted tag',
		names.every( ( n ) => saved.includes( n ) ),
		JSON.stringify( saved ) );
	t.check( 'saved post has no mashed tag name',
		! saved.includes( mashed ),
		JSON.stringify( saved ) );

	/* Enter on a comma list (the path if paste landed as text first). */
	const enterNames = [ `tp-${ stamp }-enter-one`, `tp-${ stamp }-enter-two` ];
	await openEditor( page, id );
	await openSettings();
	await page.fill( '#minn-editor-tag-input', enterNames.join( ', ' ) );
	await page.keyboard.press( 'Enter' );
	const enterStart = Date.now();
	let enterChips = await chipNames();
	while ( ! enterNames.every( ( n ) => enterChips.includes( n ) ) && Date.now() - enterStart < 15000 ) {
		await page.waitForTimeout( 250 );
		enterChips = await chipNames();
	}
	t.check( 'Enter on a comma list also splits into chips',
		enterNames.every( ( n ) => enterChips.includes( n ) ),
		JSON.stringify( enterChips ) );
	createdTagIds.push( ...( await page.evaluate( () =>
		[ ...document.querySelectorAll( '#minn-editor-tags [data-tagchip]' ) ].map( ( c ) => parseInt( c.dataset.tagchip, 10 ) )
	) ) );

	await deletePost( page, id );
	await page.evaluate( async ( ids ) => {
		await Promise.all( [ ...new Set( ids ) ].filter( Boolean ).map( ( tid ) =>
			fetch( window.MINN.restUrl + 'wp/v2/tags/' + tid + '?force=true', {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} ).catch( () => {} )
		) );
	}, createdTagIds );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
