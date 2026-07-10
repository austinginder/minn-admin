/**
 * wrapperText fields + schema-enum selects (the item-4 registration story).
 *
 * The minn-dev-fixtures mu-plugin layers a wrapperText "Header" field onto
 * anchor/conversation (Anchor Blocks itself moved to schema enums + generic
 * runs). Proves: the labeled field edits the wrapper header in place, the
 * overlapping generic text run is SUPPRESSED (the field used to render
 * twice — labeled + generic "Text" run — and the two edits raced), the
 * child's `role` renders as a select from the registered schema enum with
 * no descriptor, and untouched markup stays byte-identical through the edit.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'wrapper-text' );
	const { browser, page, errors } = await launch();
	await login( page );

	const HEADER = 'Session header text';
	const MSG = '<!-- wp:anchor/conversation-message {"content":"Hello from the user."} /-->';
	const id = await createPost( page, {
		title: 'wrapperText test',
		content: `<!-- wp:anchor/conversation -->\n<div class="wp-block-anchor-conversation"><div class="ab-conv-header">${ HEADER }</div>${ MSG }</div>\n<!-- /wp:anchor/conversation -->`,
	} );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="anchor/conversation"]', { timeout: 15000 } );
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-insp="wt:0"]', { timeout: 10000 } );

		const wtValue = await page.$eval( '[data-insp="wt:0"]', ( el ) => el.value );
		t.check( 'labeled wrapperText field carries the header', wtValue === HEADER, wtValue );

		// The header text must NOT also appear as a generic text run — the
		// double-render bug this suite pins.
		const dupRuns = await page.$$eval( '[data-insprun]', ( els, h ) =>
			els.filter( ( el ) => ( el.value || '' ).includes( h ) ).length, HEADER );
		t.check( 'no generic run duplicates the wrapperText field', dupRuns === 0, String( dupRuns ) );

		// Child role: a select from the registered schema enum, no descriptor.
		const roleSelect = await page.$$eval( '.minn-inspector select', ( els ) =>
			els.some( ( el ) => Array.from( el.options ).some( ( o ) => o.value === 'assistant' ) ) );
		t.check( 'schema enum renders a select generically', roleSelect );

		// Edit the header through the labeled field and apply.
		await page.fill( '[data-insp="wt:0"]', 'Renamed header' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 800 );
		await page.click( '#minn-editor-title' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2000 );

		const raw = await rawContent();
		t.check( 'header replaced in place', raw.includes( '>Renamed header<' ) && ! raw.includes( HEADER ) );
		t.check( 'untouched child markup byte-identical', raw.includes( MSG ) );

		// Reload: the field reflects the stored value.
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="anchor/conversation"]', { timeout: 15000 } );
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-insp="wt:0"]', { timeout: 10000 } );
		const after = await page.$eval( '[data-insp="wt:0"]', ( el ) => el.value );
		t.check( 'field round-trips the stored header', after === 'Renamed header', after );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
