/**
 * Island hover hint: locked blocks explain themselves.
 *
 * A core text block that islands because of styling attrs gets the
 * "Styled block" variant; any other island gets the generic "block editor"
 * variant. The hint is editor chrome: hidden until hover/selection, never
 * counted by the word-count pill, never copied with a selection.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'island-hint' );
	const { browser, page, errors } = await launch();
	await login( page );

	const content = [
		'<!-- wp:paragraph -->',
		'<p>Plain control paragraph.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:paragraph {"style":{"typography":{"fontSize":"22px"}}} -->',
		'<p style="font-size:22px">Styled island text.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:acme/hero {"x":1} -->',
		'<div class="wp-block-acme-hero"><p>Hero island body.</p></div>',
		'<!-- /wp:acme/hero -->',
	].join( '\n' );

	const id = await createPost( page, { title: 'Island hint probe', content } );
	try {
		await openEditor( page, id );

		const state = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const islands = Array.from( body.querySelectorAll( '.minn-block-island' ) );
			return {
				islands: islands.map( ( el ) => ( {
					block: el.dataset.block,
					hint: ( el.querySelector( '.minn-island-hint' ) || {} ).textContent || '',
				} ) ),
				proseParas: Array.from( body.querySelectorAll( ':scope > p' ) ).length,
			};
		} );

		t.check( 'two islands render', state.islands.length === 2, JSON.stringify( state.islands ) );
		t.check( 'control paragraph stays prose', state.proseParas >= 1 );
		const styled = state.islands.find( ( i ) => i.block === 'paragraph' );
		const hero = state.islands.find( ( i ) => i.block === 'acme/hero' );
		t.check( 'styled paragraph gets the styled-block hint', !! styled && /^Styled block/.test( styled.hint ), styled && styled.hint );
		t.check( 'third-party island gets the generic hint', !! hero && /block editor/.test( hero.hint ), hero && hero.hint );

		// Hidden at rest, revealed on hover.
		const restOpacity = await page.evaluate( () =>
			getComputedStyle( document.querySelector( '.minn-block-island .minn-island-hint' ) ).opacity );
		t.check( 'hint hidden at rest', restOpacity === '0', restOpacity );

		await page.hover( '.minn-block-island[data-block="acme/hero"]' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '.minn-block-island[data-block="acme/hero"] .minn-island-hint' );
			return el && getComputedStyle( el ).opacity === '1';
		}, null, { timeout: 4000 } );
		t.check( 'hint revealed on hover', true );

		// Word count ignores hint chrome: 3 + 3 + 3 = 9 content words.
		const words = await page.evaluate( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			const m = ( el && el.textContent || '' ).match( /(\d+)\s*words?/ );
			return m ? parseInt( m[ 1 ], 10 ) : -1;
		} );
		t.check( 'word count excludes hint text', words === 9, String( words ) );

		// Selection copy across the island drops the hint chrome.
		const copied = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const sel = window.getSelection();
			const range = document.createRange();
			range.selectNodeContents( body );
			sel.removeAllRanges();
			sel.addRange( range );
			// editorSelectionParts path: use the app's copy handler via a synthetic event.
			const dt = new DataTransfer();
			const ev = new ClipboardEvent( 'copy', { clipboardData: dt, bubbles: true, cancelable: true } );
			body.dispatchEvent( ev );
			return { html: dt.getData( 'text/html' ), text: dt.getData( 'text/plain' ) };
		} );
		const blob = ( copied.html || '' ) + ( copied.text || '' );
		t.check( 'copied selection carries no hint text', blob.length > 0 && ! /Styled block: edit text/.test( blob ) && ! /or the block editor/.test( blob ), blob.slice( 0, 200 ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
