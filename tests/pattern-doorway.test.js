/**
 * Synced-pattern doorway: a `wp:block` reference has nothing editable in
 * place (its content lives in another post), so the whole card is the way
 * in — hover dims it and names the action, and a click anywhere opens that
 * pattern in Minn's editor. The ⚙ chip keeps its own settings job above
 * the cover, and the cover is chrome: never counted, never copied.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'pattern-doorway' );
	const { browser, page, errors } = await launch();
	await login( page );

	let postId = 0;
	let blockId = 0;
	try {
		blockId = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/blocks', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {
					title: 'Doorway probe pattern',
					status: 'publish',
					content: '<!-- wp:paragraph -->\n<p>Pattern body text.</p>\n<!-- /wp:paragraph -->',
				} ),
			} );
			const j = await r.json();
			return j.id || 0;
		} );
		t.check( 'synced pattern created', blockId > 0, String( blockId ) );

		postId = await createPost( page, {
			title: 'Pattern doorway probe',
			content: `<!-- wp:block {"ref":${ blockId }} /-->\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->`,
		} );
		await openEditor( page, postId );
		await page.waitForSelector( `.minn-block-island[data-patternref="${ blockId }"]`, { timeout: 20000 } );
		await page.waitForTimeout( 2000 );

		const shape = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-patternref]' );
			const cover = isl.querySelector( '.minn-pattern-cover' );
			const chip = isl.querySelector( '.minn-island-chip' );
			const cr = cover.getBoundingClientRect();
			const ir = isl.getBoundingClientRect();
			return {
				hasCover: !! cover,
				badge: ( cover.querySelector( '.minn-pattern-badge' ) || {} ).textContent || '',
				fillsCard: Math.abs( cr.width - ir.width ) < 4 && Math.abs( cr.height - ir.height ) < 4,
				chipAbove: parseInt( getComputedStyle( chip ).zIndex, 10 ) > parseInt( getComputedStyle( cover ).zIndex, 10 ),
				restBg: getComputedStyle( cover ).backgroundColor,
			};
		} );
		t.check( 'card carries a full-size cover', shape.hasCover && shape.fillsCard, JSON.stringify( shape ) );
		t.check( 'cover names the action', /Edit pattern/.test( shape.badge ), shape.badge );
		t.check( 'settings chip stays clickable above it', shape.chipAbove );
		t.check( 'no dim at rest', /rgba\(0, 0, 0, 0\)|transparent/.test( shape.restBg ), shape.restBg );

		// Hover dims and reveals the pill.
		const spot = await page.evaluate( () => {
			const r = document.querySelector( '.minn-block-island[data-patternref]' ).getBoundingClientRect();
			return { x: r.left + r.width * 0.2, y: r.top + r.height * 0.7 };  // a corner, far from the pill
		} );
		await page.mouse.move( spot.x, spot.y );
		await page.waitForTimeout( 500 );
		const hovered = await page.evaluate( () => {
			const cover = document.querySelector( '.minn-pattern-cover' );
			const badge = cover.querySelector( '.minn-pattern-badge' );
			return { bg: getComputedStyle( cover ).backgroundColor, badge: getComputedStyle( badge ).opacity };
		} );
		t.check( 'hover dims the card', /rgba\(8, 8, 12/.test( hovered.bg ), hovered.bg );
		t.check( 'hover reveals the pill', hovered.badge === '1', hovered.badge );

		// The cover is chrome: it must not reach the word count.
		const counted = await page.evaluate( () => {
			const pill = document.querySelector( '.minn-stats-pill, #minn-editor-stats' );
			return pill ? pill.textContent : '';
		} );
		t.check( 'cover text is not counted as content', ! /Edit pattern/.test( counted ), counted.slice( 0, 60 ) );

		// A click anywhere on the card opens that pattern.
		await page.mouse.click( spot.x, spot.y );
		await page.waitForFunction( ( bid ) => location.pathname.indexOf( 'editor/blocks/' + bid ) !== -1, blockId, { timeout: 20000 } )
			.then( () => t.check( 'clicking the card opens the pattern', true ) )
			.catch( () => t.check( 'clicking the card opens the pattern', false, page.url() ) );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		const inPattern = await page.evaluate( () => ( document.querySelector( '#minn-editor-title' ) || {} ).value || '' );
		t.check( 'the pattern editor loaded that pattern', /Doorway probe pattern/.test( inPattern ), inPattern );
	} finally {
		await deletePost( page, postId );
		if ( blockId ) {
			await page.evaluate( async ( bid ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/blocks/' + bid + '?force=true', {
					method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce },
				} );
			}, blockId ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
