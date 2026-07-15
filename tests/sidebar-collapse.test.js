/**
 * Collapsible sidebar cards: Publish / Featured image / Outline still
 * collapse from their titles (localStorage map). Secondary meta is now
 * door rows (not collapsible cards) — Settings opens a modal instead.
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
	await page.waitForSelector( '[data-side-door="settings"]', { timeout: 10000 } );

	const state = () => page.evaluate( () => {
		const cards = {};
		document.querySelectorAll( '#minn-editor-side .minn-side-card.collapsible' ).forEach( ( c ) => {
			const key = c.querySelector( '.minn-side-title' ).textContent.trim();
			cards[ key ] = c.classList.contains( 'collapsed' );
		} );
		return cards;
	} );

	const initial = await state();
	t.check( 'inline cards are collapsible and default expanded',
		Object.keys( initial ).length >= 2
		&& Object.values( initial ).every( ( v ) => ! v )
		&& initial.Publish === false
		&& initial[ 'Featured image' ] === false,
		JSON.stringify( initial ) );
	t.check( 'Settings is a door, not a collapsible card',
		!! ( await page.$( '[data-side-door="settings"]' ) )
		&& ! Object.keys( initial ).some( ( k ) => k.startsWith( 'Settings' ) ),
		JSON.stringify( initial ) );

	/* ===== Collapse Featured image from its title ===== */
	await page.locator( '#minn-editor-side .minn-side-title:text-is("Featured image")' ).click();
	await page.waitForTimeout( 200 );
	const after = await state();
	t.check( 'Featured image collapses; Publish stays open',
		after[ 'Featured image' ] === true && after.Publish === false, JSON.stringify( after ) );
	const hidden = await page.evaluate( () =>
		! [ ...document.querySelectorAll( '#minn-editor-side .minn-side-card.collapsed *' ) ]
			.filter( ( el ) => ! el.closest( '.minn-side-title' ) )
			.some( ( el ) => el.checkVisibility && el.checkVisibility() ) );
	t.check( 'collapsed card bodies are hidden', hidden, '' );

	/* ===== Settings door opens a large modal ===== */
	await page.click( '[data-side-door="settings"]' );
	await page.waitForSelector( '.minn-editor-side-modal #minn-slug-input', { timeout: 10000 } );
	t.check( 'Settings door opens modal with fields', true );
	await page.keyboard.press( 'Escape' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-editor-side-modal' ), null, { timeout: 5000 } );

	/* ===== Persists across an editor load ===== */
	await openEditor( page, id );
	await page.waitForTimeout( 400 );
	const reloaded = await state();
	t.check( 'collapse state persists across loads',
		reloaded[ 'Featured image' ] === true && reloaded.Publish === false, JSON.stringify( reloaded ) );

	/* ===== Expand again + storage cleanup ===== */
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
