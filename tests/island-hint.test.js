/**
 * Island hover hint: a block locked for a REASON explains itself.
 *
 * A core text block that islands because of styling attrs looks typeable and
 * is not, so it says so. Every other card carries the ⚙ chip in view and gets
 * NO hint — the label only repeated it. The hint is editor
 * chrome: hidden until hover/selection, never counted by the word-count pill,
 * never copied with a selection.
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
		// Paragraphs, headings, lists and the passthrough blocks all carry
		// their styling and stay editable now, so the styled-island case needs
		// a block whose extra attrs the serializer can't reproduce.
		'<!-- wp:code {"style":{"color":{"background":"#eeeeee"}}} -->',
		'<pre class="wp-block-code has-background" style="background-color:#eeeeee"><code>Styled island text.</code></pre>',
		'<!-- /wp:code -->',
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

		// A styled code block can't be reproduced from the DOM, so it islands and
		// keeps the explaining hint; the third-party card gets none.
		t.check( 'the styled code block and the third-party block both island', state.islands.length === 2, JSON.stringify( state.islands ) );
		t.check( 'control paragraph stays prose', state.proseParas >= 1 );
		const hero = state.islands.find( ( i ) => i.block === 'acme/hero' );
		t.check( 'third-party island carries no hint', !! hero && hero.hint === '', hero && hero.hint );
		const styled = state.islands.find( ( i ) => /code/.test( i.block || '' ) );
		t.check( 'styled text island explains why it is locked', !! styled && /Styled block/.test( styled.hint ), styled && styled.hint );

		// Hidden at rest, revealed on hover.
		const restOpacity = await page.evaluate( () =>
			getComputedStyle( document.querySelector( '.minn-island-hint' ) ).opacity );
		t.check( 'hint hidden at rest', restOpacity === '0', restOpacity );

		await page.hover( '.minn-block-island[data-block="code"], .minn-block-island[data-block="core/code"]' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '.minn-island-hint' );
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

	// Container cards carry no hint: their affordance is that you can type in
	// them, so the label only restated the obvious.
	{
		const gid = await createPost( page, { title: 'Container hint probe', content: '<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Inside.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->' } );
		try {
			await openEditor( page, gid );
			await page.waitForSelector( '.minn-slot', { timeout: 15000 } );
			const hints = await page.evaluate( () => document.querySelectorAll( '.minn-slot-island > .minn-island-hint' ).length );
			t.check( 'container cards carry no hover hint', hints === 0, String( hints ) );
		} finally {
			await deletePost( page, gid );
		}
	}

	await t.done( browser, errors );
} )();
