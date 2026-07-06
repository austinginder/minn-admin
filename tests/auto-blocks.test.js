/**
 * Auto-registered block inserts — dynamic third-party blocks appear in the
 * slash menu with NO adapter code (search-only entries from B.insertBlocks).
 *
 * Verifies the boot-payload gate (dynamic-only, adapter dedup), search-only
 * menu behavior (the default list stays curated), namespace search
 * ("/stackable" lists a plugin's blocks), insertion as an island, and that
 * the SAVED markup is a valid self-closing block comment that survives
 * reload and an unrelated edit + save.
 *
 * Fixture expectations (minnadmin dev site): anchor-blocks active (dynamic
 * blocks registered without PHP titles — exercises the humanized-slug
 * fallback, e.g. anchor/report-card → "Report Card"; anchor/callout has an
 * adapter insert template so it must NOT be duplicated) and Stackable active
 * (stackable/posts is a hybrid — render_callback plus a JS save() — so it
 * renders empty from a bare comment and pins the render-probe exclusion).
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'auto-blocks' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Auto-block insert test',
		content: '<!-- wp:paragraph -->\n<p>Auto blocks.</p>\n<!-- /wp:paragraph -->',
	} );
	let emptyId = null;

	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 2000 ); };
	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const menuLabels = () => page.$$eval( '.minn-slash-item', ( els ) => els.map( ( e ) => e.textContent.trim() ) );

	try {
		await openEditor( page, id );

		// --- Boot payload gate ---
		const boot = await page.evaluate( () => ( window.MINN.insertBlocks || [] ) );
		const names = boot.map( ( b ) => b.name );
		t.check( 'insertBlocks in boot payload', boot.length > 0, boot.length + ' blocks' );
		t.check( 'dynamic block without adapter listed', names.includes( 'anchor/report-card' ) );
		t.check( 'humanized title for JS-only-titled block',
			( boot.find( ( b ) => b.name === 'anchor/report-card' ) || {} ).title === 'Report Card' );
		t.check( 'adapter insert template supersedes auto entry', ! names.includes( 'anchor/callout' ) );
		t.check( 'static-save blocks excluded', ! names.some( ( n ) => n === 'stackable/icon-box' || n === 'stackable/heading' ) );
		t.check( 'hybrid dynamic blocks excluded (render probe)',
			! names.includes( 'stackable/posts' ) && ! names.includes( 'yoast/faq-block' ), names.join( ', ' ) );

		// --- Search-only: default menu stays curated ---
		await freshParagraph( page );
		await page.keyboard.type( '/', { delay: 30 } );
		await page.waitForSelector( '.minn-slash-menu' );
		let labels = await menuLabels();
		t.check( 'default menu hides auto blocks',
			! labels.some( ( l ) => l.includes( 'Report Card' ) || l.includes( 'Featured Product' ) ),
			labels.join( ', ' ) );
		const hint = await page.$eval( '.minn-slash-hint', ( e ) => e.textContent ).catch( () => '' );
		t.check( 'default menu shows keep-typing hint', /more blocks/.test( hint ), hint );

		// --- Query surfaces an auto block, with its namespace badge ---
		await page.keyboard.type( 'report card', { delay: 30 } );
		await page.waitForTimeout( 250 );
		labels = await menuLabels();
		t.check( 'query surfaces auto block', labels.some( ( l ) => l.includes( 'Report Card' ) ), labels.join( ', ' ) );
		const ns = await page.$$eval( '.minn-slash-item .minn-slash-ns', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'namespace badge rendered', ns.includes( 'anchor' ), ns.join( ', ' ) );
		t.check( 'hint gone once typing', ( await page.$( '.minn-slash-hint' ) ) === null );

		// --- Insert: lands as an island, self-closing comment registered ---
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block="anchor/report-card"]', { timeout: 5000 } );
		t.check( 'inserted as island', true );
		// Dismiss the auto-opened inspector (outside mousedown closes it).
		await page.click( '#minn-editor-title' );
		await page.waitForTimeout( 300 );

		// --- Namespace search lists a whole plugin's blocks ---
		await freshParagraph( page );
		await page.keyboard.type( '/anchor', { delay: 30 } );
		await page.waitForTimeout( 250 );
		labels = await page.$$( '.minn-slash-menu' ) .then( ( m ) => m.length ? menuLabels() : [] );
		t.check( 'namespace query lists plugin blocks', labels.some( ( l ) => l.includes( 'Term List' ) ), labels.join( ', ' ) );
		await page.keyboard.press( 'Escape' );

		// --- No duplicate entry for adapter-templated blocks ---
		await freshParagraph( page );
		await page.keyboard.type( '/callout', { delay: 30 } );
		await page.waitForTimeout( 250 );
		labels = await menuLabels();
		// Adapter entries carry the ❖ text-glyph icon inside textContent.
		t.check( 'adapter block listed exactly once', labels.filter( ( l ) => l.includes( 'Callout' ) ).length === 1, labels.join( ', ' ) );
		await page.keyboard.press( 'Escape' );

		// Drop the leftover "/query" paragraphs so saves stay clean.
		await page.evaluate( () => {
			document.querySelectorAll( '#minn-editor-body > p' ).forEach( ( p ) => {
				if ( /^\/(anchor|callout)/.test( p.textContent.trim() ) ) p.remove();
			} );
		} );

		// --- Saved markup: valid self-closing block comment ---
		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup is a self-closing block comment',
			raw1.includes( '<!-- wp:anchor/report-card /-->' ), raw1 );

		// --- Survives reload, and an unrelated edit + save leaves it intact ---
		await openEditor( page, id );
		t.check( 'island survives reload',
			( await page.$( '.minn-block-island[data-block="anchor/report-card"]' ) ) !== null );
		await freshParagraph( page );
		await page.keyboard.type( 'Round two.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		t.check( 'island round-trips through a second save',
			raw2.includes( '<!-- wp:anchor/report-card /-->' ) && raw2.includes( 'Round two.' ), raw2 );

		// --- Empty posts open in blocks mode ---
		// The classic-degradation trap: a title-only autosave draft reloaded
		// before its first content save used to reopen as classic, hiding
		// embeds/galleries/custom blocks forever (editorModeFor empty → blocks).
		emptyId = await createPost( page, { title: 'Auto-block empty fixture', content: '' } );
		await openEditor( page, emptyId );
		await freshParagraph( page );
		await page.keyboard.type( '/', { delay: 30 } );
		await page.waitForSelector( '.minn-slash-menu' );
		labels = await menuLabels();
		t.check( 'empty post opens in blocks mode (Embed offered, hint shown)',
			labels.some( ( l ) => l.startsWith( 'Embed' ) ) && !! ( await page.$( '.minn-slash-hint' ) ),
			labels.join( ', ' ) );
		await page.keyboard.press( 'Escape' );
	} finally {
		await deletePost( page, id );
		await deletePost( page, emptyId );
	}

	await t.done( browser, errors );
} )();
