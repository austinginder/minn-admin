/**
 * JS-gated preview reveal: blocks that hide their markup until a view
 * script boots (Jetpack slideshow keeps its container at opacity:0 until
 * Swiper adds wp-swiper-initialized) render as invisible boxes in island
 * previews, which never run third-party JS. The reveal pass un-hides
 * opacity-0 wrappers when a sized preview has no visible content — on the
 * preview DOM only, so the stored raw stays byte-identical.
 *
 * Fixture: an unregistered block whose wrapper carries inline opacity:0
 * (computed style is what the pass reads, so inline works without any CSS
 * pipeline) around a real fixture image + text. A visible control island
 * proves the pass leaves normal previews untouched.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const GATED = '<!-- wp:acme/gated -->\n<div class="acme-gated" style="opacity:0"><figure><img src="' + BASE + '/wp-content/uploads/gal-red.png" alt=""/></figure><p>Hidden until JS</p></div>\n<!-- /wp:acme/gated -->';
const CONTROL = '<!-- wp:acme/plain -->\n<div class="acme-plain"><p>Always visible</p></div>\n<!-- /wp:acme/plain -->';
const CONTENT = GATED + '\n\n' + CONTROL + '\n\n<!-- wp:paragraph -->\n<p>Tail paragraph.</p>\n<!-- /wp:paragraph -->';

( async () => {
	const t = reporter( 'preview-reveal' );
	const { browser, page, errors } = await launch();
	await login( page );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Preview reveal probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );

		await openEditor( page, id );
		await page.waitForSelector( '.minn-island-preview .acme-gated', { timeout: 20000 } );

		// The reveal pass rides behind the styles promise + one rAF; poll.
		const revealed = await page.waitForFunction( () => {
			const w = document.querySelector( '.minn-island-preview .acme-gated' );
			return w && getComputedStyle( w ).opacity === '1';
		}, null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'gated wrapper revealed to opacity 1', revealed );

		const vis = await page.evaluate( () => {
			const img = document.querySelector( '.minn-island-preview .acme-gated img' );
			const p = document.querySelector( '.minn-island-preview .acme-gated p' );
			return {
				img: img ? img.checkVisibility( { opacityProperty: true, visibilityProperty: true } ) : null,
				text: p ? p.checkVisibility( { opacityProperty: true, visibilityProperty: true } ) : null,
			};
		} );
		t.check( 'gated image visible', vis.img === true );
		t.check( 'gated text visible', vis.text === true );

		// Control island: the pass must not touch previews that were already
		// visible (no stray inline opacity on its elements).
		const control = await page.evaluate( () => {
			const w = document.querySelector( '.minn-island-preview .acme-plain' );
			return w ? { inline: w.style.opacity, pInline: ( w.querySelector( 'p' ) || {} ).style ? w.querySelector( 'p' ).style.opacity : '' } : null;
		} );
		t.check( 'control island untouched', control && control.inline === '' && control.pInline === '' , JSON.stringify( control ) );

		// Byte-identity: save and confirm the stored raw still carries the
		// original inline opacity:0 (reveal never reaches serialized content).
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return j.content && j.content.raw || '';
		}, id );
		t.check( 'stored raw keeps opacity:0', raw.indexOf( 'style="opacity:0"' ) !== -1 );
		t.check( 'stored raw keeps gated block verbatim', raw.indexOf( '<!-- wp:acme/gated -->' ) !== -1 );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
