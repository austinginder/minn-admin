/**
 * Collapsible sidebar cards: every editor sidebar card collapses from its
 * title, per-card state persists across editor loads (localStorage map),
 * and collapsing the tall cards lets the Outline lead the column.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const CONTENT = '<!-- wp:heading --><h2 class="wp-block-heading">Section one</h2><!-- /wp:heading -->'
	+ '<!-- wp:paragraph --><p>Prose.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:heading --><h2 class="wp-block-heading">Section two</h2><!-- /wp:heading -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'sidebar-collapse' );
	await login( page );

	const id = await createPost( page, { title: 'Collapse probe', content: CONTENT, status: 'draft' } );
	await openEditor( page, id );
	await page.waitForSelector( '#minn-outline-card:not([hidden])' );

	const state = () => page.evaluate( () => {
		const cards = {};
		document.querySelectorAll( '#minn-editor-side .minn-side-card.collapsible' ).forEach( ( c ) => {
			const key = c.querySelector( '.minn-side-title' ).textContent.trim();
			cards[ key ] = c.classList.contains( 'collapsed' );
		} );
		return cards;
	} );

	const initial = await state();
	t.check( 'cards are collapsible and default expanded', Object.keys( initial ).length >= 4 && Object.values( initial ).every( ( v ) => ! v ), JSON.stringify( initial ) );

	/* ===== Collapse Settings + Featured image from their titles ===== */
	for ( const name of [ 'Settings', 'Featured image' ] ) {
		await page.locator( `#minn-editor-side .minn-side-title:text-is("${ name }")` ).click();
	}
	await page.waitForTimeout( 200 );
	const after = await state();
	t.check( 'clicked cards collapse, others stay open', after.Settings === true && after[ 'Featured image' ] === true && after.Publish === false, JSON.stringify( after ) );
	// checkVisibility, not offsetParent — SVG elements report offsetParent
	// undefined even when display:none'd via an ancestor.
	const hidden = await page.evaluate( () =>
		! [ ...document.querySelectorAll( '#minn-editor-side .minn-side-card.collapsed *' ) ]
			.filter( ( el ) => ! el.closest( '.minn-side-title' ) )
			.some( ( el ) => el.checkVisibility && el.checkVisibility() ) );
	t.check( 'collapsed card bodies are hidden', hidden, '' );

	/* ===== Fold everything but Outline → it leads the column ===== */
	const foldAllBut = ( keep, collapsed ) => page.evaluate( ( args ) => {
		document.querySelectorAll( '#minn-editor-side .minn-side-card.collapsible' ).forEach( ( c ) => {
			const title = c.querySelector( '.minn-side-title' );
			const key = title.textContent.trim();
			const want = ! args.keep.includes( key ) === args.collapsed;
			if ( c.classList.contains( 'collapsed' ) !== want ) title.click();
		} );
	}, { keep, collapsed } );
	await foldAllBut( [ 'Outline' ], true );
	await page.waitForTimeout( 200 );
	const outlineTop = await page.evaluate( () => Math.round( document.querySelector( '#minn-outline-card' ).getBoundingClientRect().top ) );
	t.check( 'outline leads the column with everything folded', outlineTop < 700, String( outlineTop ) );
	// Persistence check below expects exactly Settings + Featured image
	// collapsed — restore that arrangement.
	await foldAllBut( [ 'Settings', 'Featured image' ], false );
	await page.waitForTimeout( 200 );

	/* ===== Persists across an editor load ===== */
	await openEditor( page, id );
	await page.waitForTimeout( 400 );
	const reloaded = await state();
	t.check( 'collapse state persists across loads', reloaded.Settings === true && reloaded[ 'Featured image' ] === true && reloaded.Publish === false, JSON.stringify( reloaded ) );

	/* ===== Expand again + storage cleanup ===== */
	await page.locator( '#minn-editor-side .minn-side-title:text-is("Settings")' ).click();
	await page.locator( '#minn-editor-side .minn-side-title:text-is("Featured image")' ).click();
	await page.waitForTimeout( 200 );
	const restored = await page.evaluate( () => ( {
		open: ! document.querySelector( '#minn-editor-side .minn-side-card.collapsed' ),
		stored: localStorage.getItem( 'minn-side-collapsed' ),
	} ) );
	t.check( 'expanding restores and empties the stored map', restored.open && restored.stored === '{}', JSON.stringify( restored ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
