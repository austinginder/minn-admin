/**
 * Stackable design library inserts (adapters/stackable.php).
 *
 * Free-tier designs from Stackable's CDN library (full serialized save()
 * markup — valid by construction) surface as search-only slash entries and
 * insert as islands with CDN images sideloaded to the media library.
 *
 * NETWORK DEPENDENCY: the design library lives on stackable-files.pages.dev
 * (server-cached 7 days in Stackable's own transient). If the designs
 * endpoint is unreachable the suite SKIPS (exit 0) rather than failing.
 *
 * Fixture note: the sideloaded design image (stk-design-library-image-*) is
 * deduped by filename, so it persists on the dev site as a fixture — same
 * convention as the gal-red/green/blue gallery images.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'stackable-designs' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Offline / library-unreachable guard.
	const probe = await page.evaluate( async () => {
		try {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/stackable/designs', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			if ( ! r.ok ) return { ok: false };
			const j = await r.json();
			return { ok: true, count: ( j.designs || [] ).length };
		} catch ( e ) {
			return { ok: false };
		}
	} );
	if ( ! probe.ok || ! probe.count ) {
		console.log( 'SKIP  design library unreachable (offline?) — suite not run' );
		await browser.close();
		process.exit( 0 );
	}

	const id = await createPost( page, {
		title: 'Stackable design insert test',
		content: '<!-- wp:paragraph -->\n<p>Design test.</p>\n<!-- /wp:paragraph -->',
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

		t.check( 'boot payload flags Stackable', await page.evaluate( () => window.MINN.stackable === true ) );
		t.check( 'designs endpoint lists free tier', probe.count > 50, probe.count + ' designs' );

		// Search surfaces a design (list loads lazily — poll for it).
		await freshParagraph( page );
		await page.keyboard.type( '/call to action', { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 20 && ! found; i++ ) {
			await page.waitForTimeout( 250 );
			found = await page.$$eval( '.minn-slash-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Call to Action 1' ) && e.textContent.includes( 'stackable' ) )
			).catch( () => false );
			// Re-trigger the query so late-arriving designs get filtered in.
			if ( ! found && i === 8 ) { await page.keyboard.press( 'Backspace' ); await page.keyboard.type( 'n', { delay: 30 } ); }
		}
		t.check( 'design entry surfaces with namespace badge', found );

		// Insert — async fetch + image sideload, allow a generous window.
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block^="stackable/"]', { timeout: 45000 } );
		t.check( 'design inserted as island', true );
		const preview = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island .minn-island-preview' );
			return p && p.innerHTML.includes( 'stk-block' ) ? p.innerHTML.length : false;
		}, null, { timeout: 20000 } ).then( ( h ) => h.jsonValue() ).catch( () => 0 );
		t.check( 'island preview renders real Stackable markup', preview > 200, preview + ' bytes' );
		// Stackable enqueues its CSS lazily from render_block — render-blocks
		// reports it and the client scopes it into previews.
		const cssInjected = await page.waitForFunction( () =>
			[ ...document.querySelectorAll( 'style.minn-preview-css' ) ]
				.some( ( s ) => s.textContent.includes( '.minn-island-preview' ) && s.textContent.includes( 'stk-' ) ),
		null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'lazy Stackable CSS scoped into previews', cssInjected );

		// --- Inspector text runs: edit the design's placeholder copy ---
		// The design insert auto-opens the inspector; its generic text-run
		// fields expose every text node in the saved HTML, however deep.
		await page.waitForSelector( '.minn-block-inspector [data-insprun], .minn-insp-body [data-insprun]', { timeout: 10000 } ).catch( () => null );
		const runInputs = await page.$$( '[data-insprun]' );
		t.check( 'inspector exposes text-run fields', runInputs.length >= 3, runInputs.length + ' fields' );
		let edited = false;
		for ( const input of runInputs ) {
			const v = await input.inputValue();
			if ( v === 'heading_placeholder' ) {
				await input.fill( 'Launch week' );
				edited = true;
				break;
			}
		}
		t.check( 'placeholder heading field found', edited );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1500 );
		const previewText = await page.$eval( '.minn-block-island .minn-island-preview', ( e ) => e.textContent ).catch( () => '' );
		t.check( 'preview reflects the text edit', previewText.includes( 'Launch week' ) );

		// --- Inspector image swap: replace the design's background image ---
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-inspimg]', { timeout: 10000 } );
		t.check( 'inspector lists the island image', ( await page.$$( '[data-inspimg]' ) ).length === 1 );
		await page.click( '[data-inspimg]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 15000 } );
		// Pick the gal-red fixture (find it by thumbnail title).
		const picked = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-picker-item' ) ].find( ( e ) => /gal-red/i.test( e.title ) );
			if ( ! el ) return false;
			el.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			return true;
		} );
		t.check( 'gal-red fixture picked from media library', picked );
		await page.waitForTimeout( 1500 );

		// Saved markup: real template, images localized.
		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup contains the design template', /<!-- wp:stackable\/[a-z-]+ {"uniqueId"/.test( raw1 ) );
		t.check( 'CDN image URLs localized', ! raw1.includes( 'stackable-files.pages.dev' ), raw1.slice( 0, 300 ) );
		t.check( 'text edit persisted, splice byte-exact',
			raw1.includes( 'Launch week' ) && ! raw1.includes( 'heading_placeholder' )
			&& raw1.includes( 'description_placeholder' ) && raw1.includes( 'btn-1_placeholder' ) );
		t.check( 'image swap persisted in attr + style',
			raw1.includes( 'gal-red' ) && ! raw1.includes( 'stk-design-library-image' )
			&& /"blockBackgroundMediaUrl":"[^"]*gal-red/.test( raw1 ) );

		// Round-trip: reload, unrelated edit, save again — island byte-stable.
		await openEditor( page, id );
		t.check( 'island survives reload',
			( await page.$( '.minn-block-island[data-block^="stackable/"]' ) ) !== null );
		const islandBefore = raw1.match( /<!-- wp:stackable\/[\s\S]*\/wp:stackable\/[a-z-]+ -->/ );
		await freshParagraph( page );
		await page.keyboard.type( 'After the section.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		const islandAfter = raw2.match( /<!-- wp:stackable\/[\s\S]*\/wp:stackable\/[a-z-]+ -->/ );
		t.check( 'design round-trips byte-identical through a second save',
			!! islandBefore && !! islandAfter && islandBefore[ 0 ] === islandAfter[ 0 ] && raw2.includes( 'After the section.' ) );

		// --- Structural add clones a static sibling (never an empty comment) ---
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '#minn-insp-add', { timeout: 10000 } );
		t.check( 'block editor escape hatch shown',
			( await page.$( '#minn-insp-gutenberg[target="_blank"]' ) ) !== null );
		await page.click( '#minn-insp-add' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1500 );
		await save();
		const raw3 = await rawContent();
		const colOpens = ( raw3.match( /<!-- wp:stackable\/column [\{]/g ) || [] ).length;
		t.check( 'add clones the static column, no empty self-closing',
			colOpens === 2 && ! raw3.includes( '<!-- wp:stackable/column /-->' ), colOpens + ' columns' );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
