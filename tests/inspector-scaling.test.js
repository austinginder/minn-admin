/**
 * Inspector form scaling (docs/block-suites.md roadmap #4): design suites
 * register hundreds of attributes (Spectra's post-grid: 315) — the generic
 * form keeps explicitly-set fields in view and collapses the rest behind a
 * "More settings" expander with a filter box.
 *
 * Fixture: the minn-dev-fixtures mu-plugin registers minn-test/big-schema
 * (31 string attributes, dynamic render).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'inspector-scaling' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Inspector scaling test',
		content: '<!-- wp:minn-test/big-schema {"title":"hello","setting3":"three"} /-->',
	} );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="minn-test/big-schema"]', { timeout: 10000 } );
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-inspmore]', { timeout: 10000 } );

		// Explicitly-set attrs stay in view; the rest collapse.
		const visible = await page.$$eval( '.minn-insp-body [data-insp]', ( els ) =>
			els.filter( ( e ) => e.offsetParent !== null ).map( ( e ) => e.dataset.insp ) );
		t.check( 'only explicitly-set fields visible', visible.length === 2
			&& visible.includes( 'own:title' ) && visible.includes( 'own:setting3' ), visible.join( ', ' ) );
		// The fixture registers 31 attrs (29 collapse), but plugins that inject
		// attributes into EVERY block schema (Otter's customCSS/hasCustomCSS)
		// legitimately raise the count — assert the button's number against the
		// really rendered rows instead of a hard-coded 29 (rule: suites seed or
		// tolerate the live plugin baseline, never assume it).
		const btnText = await page.$eval( '[data-inspmore]', ( e ) => e.textContent );
		const rowCount = await page.$$eval( '.minn-insp-more .minn-insp-row', ( els ) => els.length );
		t.check( 'More settings shows the collapsed count',
			rowCount >= 29 && new RegExp( 'More settings \\(' + rowCount + '\\)' ).test( btnText ),
			btnText + ' / ' + rowCount + ' rows' );
		t.check( 'panel starts hidden', await page.$eval( '.minn-insp-more', ( e ) => e.hidden ) );

		// Expand and filter.
		await page.click( '[data-inspmore]' );
		t.check( 'expand reveals the panel', await page.$eval( '.minn-insp-more', ( e ) => ! e.hidden ) );
		await page.fill( '[data-inspmore-filter]', 'setting12' );
		const shown = await page.$$eval( '.minn-insp-row', ( els ) =>
			els.filter( ( e ) => e.style.display !== 'none' ).length );
		t.check( 'filter narrows to the match', shown === 1, shown + ' rows' );

		// Edit a collapsed field, Apply, verify persistence.
		await page.fill( '[data-insp="own:setting12"]', 'twelve' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1200 );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).content.raw;
		}, id );
		t.check( 'filtered field edit persisted', raw.includes( '"setting12":"twelve"' )
			&& raw.includes( '"title":"hello"' ) && raw.includes( '"setting3":"three"' ), raw.slice( 0, 200 ) );
		t.check( 'untouched collapsed fields injected nothing', ! raw.includes( 'setting20' ) );

		// The newly-set field is promoted to the visible tier on reopen.
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-inspmore]', { timeout: 10000 } );
		const visible2 = await page.$$eval( '.minn-insp-body [data-insp]', ( els ) =>
			els.filter( ( e ) => e.offsetParent !== null ).map( ( e ) => e.dataset.insp ) );
		t.check( 'set field promoted to visible tier', visible2.includes( 'own:setting12' ) && visible2.length === 3, visible2.join( ', ' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
