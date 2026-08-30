/**
 * Query Loop (core/query) — bundled insert template + query dataForm
 * (adapters/core-query.php). The bare block renders nothing (its list comes
 * from inner blocks), so the auto-insert probe rightly excludes it; the
 * descriptor's insert.template is the way in. Verifies: "/query" surfaces
 * the entry, insertion lands canonical Gutenberg markup as an island with a
 * REAL rendered preview, saved markup round-trips reload, and the ⚙
 * inspector's query subform writes real JSON types (perPage stays a number)
 * while preserving unmapped query keys and the inner template.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'query-loop' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Query loop suite',
		content: '<!-- wp:paragraph -->\n<p>Before the loop.</p>\n<!-- /wp:paragraph -->',
	} );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async () => {
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 400 );
			const raw = await rawContent().catch( () => '' );
			if ( /<!-- wp:query/.test( raw ) ) return raw;
		}
		return rawContent();
	};

	try {
		await openEditor( page, id );

		t.check( 'boot descriptor carries the insert template', await page.evaluate( () =>
			!! ( ( window.MINN.blockForms || {} )[ 'core/query' ] || {} ).insert ) );

		// --- Slash search surfaces the entry (blockForms loads async, so
		// jiggle: a zero-match query closes the menu until the next keyup) ---
		let entry = false;
		for ( let i = 0; i < 20 && ! entry; i++ ) {
			await freshParagraph( page );
			await page.keyboard.type( '/query', { delay: 30 } );
			entry = await page.waitForFunction( () =>
				[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => /Query Loop/.test( el.textContent ) ),
				null, { timeout: 900 } ).then( () => true ).catch( () => false );
			if ( ! entry ) {
				await page.keyboard.press( 'Escape' );
				await page.evaluate( () => { if ( window.__minnTestPara ) window.__minnTestPara.remove(); } );
				await page.waitForTimeout( 500 );
			}
		}
		t.check( '"/query" surfaces Query Loop', entry );

		// --- Insert: island with a real rendered preview ---
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( n ) => /Query Loop/.test( n.textContent ) );
			el.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
			el.click();
		} );
		await page.waitForSelector( '.minn-block-island[data-block$="query"]', { timeout: 10000 } );
		t.check( 'inserts as an island', true );
		await page.keyboard.press( 'Escape' ); // template inserts auto-open the inspector

		const previewed = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block$="query"] .minn-island-preview' );
			return p && p.querySelectorAll( '.wp-block-post-title' ).length > 0;
		}, null, { timeout: 45000 } ).then( () => true ).catch( () => false );
		t.check( 'preview renders real posts', previewed );

		// Frontend CSS arrives after the HTML: :root tokens from a dark-default
		// theme used to invert title color while the matching background was
		// stripped, so the list painted then vanished. Wait for that sheet
		// and pin contrast against the editor theme.
		await page.waitForSelector( '#minn-frontend-css', { timeout: 30000 } ).catch( () => {} );
		await page.waitForTimeout( 600 );
		const afterCss = await page.evaluate( () => {
			const island = document.querySelector( '.minn-block-island[data-block$="query"]' );
			const title = island && island.querySelector( '.wp-block-post-title' );
			if ( ! title ) return { ok: false, why: 'no title' };
			const c = getComputedStyle( title ).color;
			const m = c.match( /rgba?\((\d+),\s*(\d+),\s*(\d+)/ );
			const lum = m ? ( 0.2126 * +m[ 1 ] + 0.7152 * +m[ 2 ] + 0.0722 * +m[ 3 ] ) / 255 : -1;
			const theme = document.documentElement.getAttribute( 'data-theme' );
			const readable = theme === 'light' ? lum < 0.55 : lum > 0.45;
			return {
				ok: readable, color: c, lum, theme,
				cted: island.getAttribute( 'data-cted' ),
				shell: ( island.querySelector( '.minn-island-preview' ) || {} ).getAttribute( 'data-root-theme' ),
			};
		} );
		t.check( 'frontend CSS keeps titles readable against the editor theme', afterCss.ok, JSON.stringify( afterCss ) );
		t.check( 'Query Loop is not a content-editor card', ! afterCss.cted, String( afterCss.cted ) );

		// --- Saved markup is the canonical shape ---
		let raw = await save();
		t.check( 'saved markup carries the full loop',
			/<!-- wp:query \{/.test( raw ) && /wp:post-template/.test( raw )
				&& /wp:query-pagination-numbers/.test( raw ) && /wp:query-no-results/.test( raw ), raw.slice( 0, 200 ) );

		// --- Survives reload as an island ---
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block$="query"]', { timeout: 20000 } );
		t.check( 'island survives reload', true );

		// --- Inspector: query subform + honest locked note ---
		await page.click( '.minn-block-island[data-block$="query"] .minn-island-chip' );
		const formUp = await page.waitForSelector( '.minn-inspector [data-inspdf]', { timeout: 45000 } )
			.then( () => true ).catch( () => false );
		t.check( 'query subform renders', formUp );
		const insp = await page.evaluate( () => {
			const el = document.querySelector( '.minn-inspector' );
			const inherit = el.querySelector( '[data-inspdf="own:inherit"]' );
			const pag = el.querySelector( '[data-insp="own:enhancedPagination"]' );
			return {
				df: [ ...el.querySelectorAll( '[data-inspdf]' ) ].map( ( n ) => n.dataset.inspdf ),
				locked: /block editor/.test( el.textContent ),
				inheritSwitch: !!( inherit && inherit.getAttribute( 'role' ) === 'switch' ),
				pagSwitch: !!( pag && pag.getAttribute( 'role' ) === 'switch' ),
				nativeChecks: [ ...el.querySelectorAll( 'input[type="checkbox"]' ) ].map( ( n ) => n.closest( 'label' )?.textContent?.trim() ),
			};
		} );
		t.check( 'all eight query fields on offer',
			[ 'postType', 'perPage', 'orderBy', 'order', 'sticky', 'search', 'offset', 'inherit' ]
				.every( ( f ) => insp.df.includes( 'own:' + f ) ), JSON.stringify( insp.df ) );
		t.check( 'locked note names the block editor', insp.locked );
		t.check( 'Inherit is a Minn switch', insp.inheritSwitch );
		t.check( 'Instant pagination is a Minn switch', insp.pagSwitch );
		t.check( 'no native checkboxes in the popover', insp.nativeChecks.length === 0, JSON.stringify( insp.nativeChecks ) );

		// --- perPage edit applies, re-renders, and stores a NUMBER ---
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-inspector [data-inspdf]' ) ].find( ( n ) => n.dataset.inspdf === 'own:perPage' );
			el.value = '2';
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-inspector button' ) ].find( ( b ) => /^Apply$/.test( b.textContent.trim() ) ).click();
		} );
		const two = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island[data-block$="query"] .minn-island-preview' );
			return p && p.querySelectorAll( '.wp-block-post-title' ).length === 2;
		}, null, { timeout: 45000 } ).then( () => true ).catch( () => false );
		t.check( 'preview re-renders with two posts', two );

		raw = await save();
		const m = raw.match( /wp:query (\{.*?\}) -->/s );
		const q = m ? JSON.parse( m[ 1 ] ).query : null;
		t.check( 'perPage stored as a JSON number', !! q && q.perPage === 2 && typeof q.perPage === 'number', m && m[ 1 ] );
		t.check( 'unmapped query keys preserved', !! q && Array.isArray( q.exclude ) && 'author' in q );
		t.check( 'inner template intact after the attr edit',
			/wp:query-pagination-numbers/.test( raw ) && /wp:query-no-results/.test( raw ) );

		// --- Inherit switch round-trips a real boolean ---
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( '.minn-block-island[data-block$="query"] .minn-island-chip' );
				await page.waitForSelector( '[data-inspdf="own:inherit"]', { timeout: 6000 } );
				break;
			} catch ( e ) { await page.waitForTimeout( 800 ); }
		}
		await page.click( '[data-inspdf="own:inherit"]' );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-inspector button' ) ].find( ( b ) => /^Apply$/.test( b.textContent.trim() ) ).click();
		} );
		await page.waitForTimeout( 800 );
		raw = await save();
		const m2 = raw.match( /wp:query (\{.*?\}) -->/s );
		const q2 = m2 ? JSON.parse( m2[ 1 ] ).query : null;
		t.check( 'inherit toggle stores a boolean true', !! q2 && q2.inherit === true, m2 && m2[ 1 ] );
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
