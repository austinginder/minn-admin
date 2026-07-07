/**
 * Server-registered block patterns in the slash menu (docs/block-suites.md
 * roadmap #1): ready-made valid saved markup from WP_Block_Patterns_Registry
 * surfaces as search-only entries and inserts as one island per top-level
 * block — multi-root patterns become sibling islands.
 *
 * Fixture: the minn-dev-fixtures mu-plugin registers
 * `minn-test/feature-box` — deliberately multi-root (group + paragraph).
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'patterns' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Pattern insert test',
		content: '<!-- wp:paragraph -->\n<p>Patterns.</p>\n<!-- /wp:paragraph -->',
	} );

	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 2000 ); };
	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	try {
		await openEditor( page, id );

		// Endpoint sanity through the app's own credentials.
		const list = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/patterns', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).patterns || [];
		} );
		t.check( 'patterns endpoint lists registered patterns', list.length > 0, list.length + ' patterns' );
		t.check( 'fixture pattern listed with source ns',
			list.some( ( p ) => p.name === 'minn-test/feature-box' && p.ns === 'minn-test' ) );

		// Search-only slash entry (lazy list — poll while it loads).
		await freshParagraph( page );
		await page.keyboard.type( '/feature box', { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 16 && ! found; i++ ) {
			await page.waitForTimeout( 250 );
			found = await page.$$eval( '.minn-slash-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Minn Test Feature Box' ) && e.textContent.includes( 'minn-test' ) )
			).catch( () => false );
			if ( ! found && i === 6 ) { await page.keyboard.press( 'Backspace' ); await page.keyboard.type( 'x', { delay: 30 } ); }
		}
		t.check( 'pattern surfaces on search with ns badge', found );

		// Insert: multi-root pattern → one island per top-level block.
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block="core/group"]', { timeout: 15000 } );
		const islands = await page.$$eval( '.minn-block-island', ( els ) => els.map( ( e ) => e.dataset.block ) );
		t.check( 'multi-root pattern lands as sibling islands',
			islands.includes( 'core/group' ) && islands.includes( 'core/paragraph' ), islands.join( ', ' ) );
		const preview = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block="core/group"] .minn-island-preview' );
			return p && p.textContent.includes( 'Feature box' );
		}, null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'island preview renders the pattern content', preview );

		// Saved markup is the pattern's own, verbatim.
		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup contains pattern blocks',
			raw1.includes( '<!-- wp:group' ) && raw1.includes( 'Feature box footnote.' ), raw1.slice( 0, 200 ) );

		// Round-trip through reload + unrelated edit. On reload the load
		// pipeline upgrades the pattern's SIMPLE blocks to editable prose —
		// only the complex group stays an island. That's the desired end
		// state, not a loss.
		await openEditor( page, id );
		const reloaded = await page.evaluate( () => ( {
			islands: document.querySelectorAll( '.minn-block-island' ).length,
			prose: document.querySelector( '#minn-editor-body' ).textContent.includes( 'Feature box footnote.' ),
		} ) );
		t.check( 'group survives as island, paragraph upgrades to editable prose',
			reloaded.islands >= 1 && reloaded.prose, JSON.stringify( reloaded ) );
		await freshParagraph( page );
		await page.keyboard.type( 'After the pattern.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		const g1 = raw1.match( /<!-- wp:group[\s\S]*?\/wp:group -->/ );
		const g2 = raw2.match( /<!-- wp:group[\s\S]*?\/wp:group -->/ );
		t.check( 'pattern round-trips byte-identical',
			!! g1 && !! g2 && g1[ 0 ] === g2[ 0 ] && raw2.includes( 'After the pattern.' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
