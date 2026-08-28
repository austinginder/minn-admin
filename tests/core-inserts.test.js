/**
 * Core-block inserts beyond the basics — the widget allowlist through the
 * auto-insert probe (Minn_Admin::insertable_blocks) and the tabs/accordion
 * insert templates (adapters/core-blocks.php). Verifies: the boot payload
 * carries the surviving core widget entries (probe-gated, so Tag Cloud may
 * honestly be absent), Latest Posts inserts as an island with a REAL post
 * list, Tabs and Accordion insert their harvested Gutenberg markup, and the
 * tabs preview is VISIBLE — its panels ship with a literal hidden attribute
 * that data-wp-bind--hidden lifts at runtime, which previews (no third-party
 * JS) lift themselves in revealJsGatedPreview.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'core-inserts' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Core inserts suite',
		content: '<!-- wp:paragraph -->\n<p>Widgets below.</p>\n<!-- /wp:paragraph -->',
	} );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	const insertVia = async ( term, label ) => {
		for ( let i = 0; i < 20; i++ ) {
			await page.evaluate( () => {
				const body = document.querySelector( '#minn-editor-body' );
				const p = document.createElement( 'p' );
				p.appendChild( document.createElement( 'br' ) );
				body.appendChild( p );
				const r = document.createRange();
				r.selectNodeContents( p );
				r.collapse( true );
				const s = getSelection();
				s.removeAllRanges();
				s.addRange( r );
				body.focus();
			} );
			await page.keyboard.type( '/' + term, { delay: 25 } );
			const hit = await page.waitForFunction( ( l ) =>
				[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => el.textContent.includes( l ) ), label, { timeout: 900 } )
				.then( () => true ).catch( () => false );
			if ( hit ) {
				await page.evaluate( ( l ) => {
					const el = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( n ) => n.textContent.includes( l ) );
					el.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
					el.click();
				}, label );
				await page.keyboard.press( 'Escape' ).catch( () => {} );
				return true;
			}
			await page.keyboard.press( 'Escape' );
			await page.waitForTimeout( 400 );
		}
		return false;
	};

	try {
		await openEditor( page, id );

		// --- Boot payload: the probe-surviving core widget entries ---
		const core = await page.evaluate( () =>
			( window.MINN.insertBlocks || [] ).filter( ( b ) => b.ns === 'core' ).map( ( b ) => b.name ) );
		t.check( 'core widget entries ride insertBlocks', core.length >= 6, JSON.stringify( core ) );
		t.check( 'Latest Posts is among them', core.includes( 'core/latest-posts' ) );
		t.check( 'template-context core blocks stay out',
			! core.some( ( n ) => /post-title|site-title|navigation|template-part/.test( n ) ) );

		// --- Latest Posts: bare-comment island with a REAL post list ---
		t.check( 'Latest Posts inserts from the slash menu', await insertVia( 'latest', 'Latest Posts' ) );
		const lp = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block$="latest-posts"] .minn-island-preview' );
			return p && /wp-block-latest-posts/.test( p.innerHTML );
		}, null, { timeout: 45000 } ).then( () => true ).catch( () => false );
		t.check( 'Latest Posts preview renders the list', lp );

		// --- Tabs + Accordion: harvested Gutenberg templates ---
		t.check( 'Tabs inserts from the slash menu', await insertVia( 'tabs', 'Tabs' ) );
		t.check( 'Accordion inserts from the slash menu', await insertVia( 'accord', 'Accordion' ) );
		// Tabs panels ship [hidden] for the Interactivity API — the preview
		// must lift them or it collapses to nothing.
		const tabsVisible = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block$="tabs"] .minn-island-preview' );
			return p && /First tab content/.test( p.textContent ) && p.getBoundingClientRect().height >= 24;
		}, null, { timeout: 45000 } ).then( () => true ).catch( () => false );
		t.check( 'tabs preview is visible (hidden panels lifted)', tabsVisible );
		const accVisible = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block$="accordion"] .minn-island-preview' );
			return p && /First question/.test( p.textContent ) && p.getBoundingClientRect().height >= 24;
		}, null, { timeout: 45000 } ).then( () => true ).catch( () => false );
		t.check( 'accordion preview is visible', accVisible );

		// --- Saved markup: bare comment + full inner structures ---
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'Meta+s' );
		let raw = '';
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 500 );
			raw = await rawContent().catch( () => '' );
			if ( /wp:latest-posts/.test( raw ) && /wp:accordion/.test( raw ) ) break;
		}
		t.check( 'Latest Posts saves as a bare comment', /<!-- wp:latest-posts \/-->/.test( raw ) );
		t.check( 'tabs saves with panels and labels',
			/wp:tab-list/.test( raw ) && /wp:tab-panel \{"label":"Tab 1"\}/.test( raw ), raw.slice( 0, 120 ) );
		t.check( 'accordion saves with items and headings',
			/wp:accordion-item/.test( raw ) && /First question/.test( raw ) );
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
