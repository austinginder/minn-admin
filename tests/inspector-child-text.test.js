/**
 * Inspector child text: core paragraph/heading children keep their text in
 * saved HTML (sourced attrs the schema form skips), which made InnerBlocks
 * islands like anchor/report-card uneditable in the body. The child section
 * now leads with a "text" field over the inner HTML — verbatim write-back
 * (inline marks survive), byte-identical when untouched. Verified against
 * SAVED markup.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const CARD = `<!-- wp:anchor/report-card {"tag":"FORK","tagColor":"purple","title":"Minnow (2026)"} -->
<!-- wp:paragraph -->
<p>Original body with <code>die()</code> inline.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Second paragraph stays untouched.</p>
<!-- /wp:paragraph -->
<!-- /wp:anchor/report-card -->`;

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'inspector-child-text' );
	await login( page );

	const id = await createPost( page, { title: 'Report card probe', content: CARD, status: 'draft' } );
	await openEditor( page, id );
	await page.waitForSelector( '.minn-block-island', { timeout: 15000 } );

	/* ===== Open the inspector via the island chip ===== */
	await page.click( '.minn-island-chip' );
	await page.waitForSelector( '.minn-inspector [data-insptext]', { timeout: 10000 } );
	const fields = await page.evaluate( () => ( {
		texts: [ ...document.querySelectorAll( '[data-insptext]' ) ].map( ( x ) => x.value ),
		title: document.querySelector( '[data-insp="own:title"]' ) && document.querySelector( '[data-insp="own:title"]' ).value,
	} ) );
	t.check( 'child text fields carry the paragraphs\' inner HTML', fields.texts.length === 2 && fields.texts[ 0 ] === 'Original body with <code>die()</code> inline.' && fields.texts[ 1 ] === 'Second paragraph stays untouched.', JSON.stringify( fields.texts ) );
	t.check( 'own attrs still form-edit alongside', fields.title === 'Minnow (2026)', String( fields.title ) );

	/* ===== Edit the first paragraph's text + the title, Apply ===== */
	await page.fill( '[data-insptext="0"]', 'Rebuilt body keeps <code>die()</code> and adds more.' );
	await page.fill( '[data-insp="own:title"]', 'Minnow (updated)' );
	await page.click( '#minn-insp-apply' );
	await page.waitForFunction( () => {
		const p = document.querySelector( '.minn-island-preview' );
		return p && /Rebuilt body keeps/.test( p.textContent ) && /Minnow \(updated\)/.test( p.textContent );
	}, { timeout: 10000 } );
	t.check( 'preview re-renders with the new text and title', true, '' );

	/* ===== Saved markup: edited child rebuilt, untouched child byte-identical ===== */
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const raw = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'edited text persists inside the paragraph child', raw.includes( '<p>Rebuilt body keeps <code>die()</code> and adds more.</p>' ), raw.slice( 0, 200 ) );
	t.check( 'edited title persists in the comment attrs', raw.includes( '"title":"Minnow (updated)"' ), '' );
	t.check( 'untouched sibling stays byte-identical', raw.includes( '<!-- wp:paragraph -->\n<p>Second paragraph stays untouched.</p>\n<!-- /wp:paragraph -->' ), '' );
	t.check( 'block shape intact (open/close comments)', raw.includes( '<!-- wp:anchor/report-card' ) && raw.includes( '<!-- /wp:anchor/report-card -->' ), '' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
