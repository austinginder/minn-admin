/**
 * Object-attribute subforms — the `dataForm` descriptor contract
 * (minn_admin_block_forms). ACF-shaped blocks keep their content nested in
 * one object attribute ({ field: value, _field: field_key }); the descriptor
 * names the attr and its subfields so the inspector renders a real form
 * instead of skipping the object (and instead of exposing the raw name/mode
 * wrapper attrs, which corrupt the block when edited).
 *
 * Fixture: the minn-dev-fixtures mu-plugin registers minn-test/data-form
 * (name/data/mode attrs, dynamic render) plus a descriptor with three fields
 * (text/select/checkbox), an alias map and locked: 1. The bundled ACF
 * adapter emits the same descriptor shape from real ACF Pro field groups.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'data-form' );
	const { browser, page, errors } = await launch();
	await login( page );

	const seeded = '<!-- wp:minn-test/data-form {"name":"minn-test/data-form","data":{"headline":"Hello","_headline":"field_fxhead","legacy_key":"keep-me"},"mode":"preview"} /-->';
	const id = await createPost( page, {
		title: 'Data form test',
		content: seeded + '\n\n<!-- wp:minn-test/data-form /-->',
	} );

	const readRaw = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 500 );
	};
	const openChip = async ( islandIdx ) => {
		await page.evaluate( ( n ) => {
			const island = document.querySelectorAll( '.minn-block-island[data-block="minn-test/data-form"]' )[ n ];
			island.scrollIntoView( { block: 'center' } );
		}, islandIdx );
		await page.waitForTimeout( 400 );
		await page.evaluate( ( n ) => {
			document.querySelectorAll( '.minn-block-island[data-block="minn-test/data-form"]' )[ n ]
				.querySelector( '.minn-island-chip' ).click();
		}, islandIdx );
		await page.waitForSelector( '.minn-insp-body', { timeout: 10000 } );
	};

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="minn-test/data-form"]', { timeout: 10000 } );

		// --- Seeded island: form renders, wrapper attrs stay hidden ---
		await openChip( 0 );
		t.check( 'subform fields render', await page.evaluate( () =>
			[ 'own:headline', 'own:style', 'own:featured' ].every( ( k ) =>
				!! document.querySelector( `[data-inspdf="${ k }"]` ) ) ) );
		t.check( 'headline seeds from the data object',
			await page.$eval( '[data-inspdf="own:headline"]', ( e ) => e.value ) === 'Hello' );
		t.check( 'hidden wrapper attrs render no form rows', await page.evaluate( () =>
			! document.querySelector( '[data-insp="own:name"]' ) && ! document.querySelector( '[data-insp="own:mode"]' )
			&& ! document.querySelector( '[data-insp="own:data"]' ) ) );
		t.check( 'locked count renders as a note', await page.$eval( '.minn-insp-body', ( e ) =>
			/1 advanced field lives in the block editor/.test( e.textContent ) ) );
		t.check( 'gallery field renders its editor doorway', await page.evaluate( () =>
			!! document.querySelector( '[data-inspdfgal="own:pics"]' )
			&& /0 images|Add images/.test( document.querySelector( '.minn-insp-dfimg-id' )?.textContent + document.querySelector( '[data-inspdfgal="own:pics"]' ).textContent ) ) );

		// Edit all three controls, Apply, save, verify the stored comment.
		await page.fill( '[data-inspdf="own:headline"]', 'Hello edited' );
		await page.selectOption( '[data-inspdf="own:style"]', 'boxed' );
		await page.click( '[data-inspdf="own:featured"]' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1200 );
		await save();
		let raw = await readRaw();
		t.check( 'text edit persisted', raw.includes( '"headline":"Hello edited"' ), raw.slice( 0, 260 ) );
		t.check( 'select persisted with its alias key', raw.includes( '"style":"boxed"' ) && raw.includes( '"_style":"field_fxstyle"' ) );
		t.check( 'checkbox persisted as 1', raw.includes( '"featured":1' ) );
		t.check( 'unknown keys in the object survive untouched', raw.includes( '"legacy_key":"keep-me"' ) );
		t.check( 'existing alias keys stay', raw.includes( '"_headline":"field_fxhead"' ) );
		t.check( 'wrapper attrs survive the rewrite', raw.includes( '"name":"minn-test/data-form"' ) && raw.includes( '"mode":"preview"' ) );

		// --- Bare island (no data attr): empty fields inject nothing; a
		// filled field creates the object with its alias ---
		await openChip( 1 );
		await page.fill( '[data-inspdf="own:headline"]', 'Fresh' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1200 );
		await save();
		raw = await readRaw();
		const bare = raw.split( '\n\n' ).find( ( s ) => s.includes( 'wp:minn-test/data-form' ) && s.includes( '"headline":"Fresh"' ) );
		t.check( 'bare island gained a data object with the typed value + alias',
			!! bare && bare.includes( '"_headline":"field_fxhead"' ), String( bare ).slice( 0, 200 ) );
		t.check( 'untouched empty fields injected nothing',
			!! bare && ! bare.includes( '"style"' ) && ! bare.includes( '"featured"' ), String( bare ).slice( 0, 200 ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
