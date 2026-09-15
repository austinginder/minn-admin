/**
 * Mobile Safari editor pass (Horizon 1 input long tail).
 *
 * Fixed bottom chrome sits on the layout viewport; the software keyboard
 * shrinks only the visual viewport. --minn-kb-inset + body.minn-kb-open
 * lift the stats pill / toasts. Phones also need larger tool/chip hit
 * targets and 16px find inputs (no focus-zoom). Real iOS isn't in
 * headless Chrome — this suite pins the contract with a phone viewport
 * and a simulated keyboard inset.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'mobile-editor' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'Mobile editor pass',
		content: '<!-- wp:paragraph -->\n<p>Phone writing.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second block for scroll room.</p>\n<!-- /wp:paragraph -->',
	} );

	try {
		// iPhone-ish layout viewport before editor load.
		await page.setViewportSize( { width: 390, height: 844 } );
		await openEditor( page, id );
		await page.waitForSelector( '#minn-editor-stats', { timeout: 10000 } );
		await page.waitForSelector( '.minn-editor-toolbar .minn-tool', { timeout: 5000 } );

		t.check( 'viewport meta asks for viewport-fit=cover', await page.evaluate( () => {
			const m = document.querySelector( 'meta[name="viewport"]' );
			return !! ( m && /viewport-fit\s*=\s*cover/i.test( m.content ) );
		} ) );

		t.check( 'visualViewport sync wrote --minn-kb-inset', await page.evaluate( () => {
			const v = getComputedStyle( document.documentElement ).getPropertyValue( '--minn-kb-inset' ).trim();
			// Desktop headless usually 0px; the property must exist.
			return v === '0px' || /^\d+px$/.test( v );
		} ) );

		// On a phone the word count is IN FLOW under the text, not a fixed
		// pill: a fixed pill covered the last lines being written and caught
		// the thumb on every scroll. It must sit below the body, never over it.
		const pill = await page.evaluate( () => {
			const el = document.getElementById( 'minn-editor-stats' );
			const body = document.getElementById( 'minn-editor-body' );
			if ( ! el || ! body ) return null;
			const cs = getComputedStyle( el );
			const r = el.getBoundingClientRect();
			const b = body.getBoundingClientRect();
			return { position: cs.position, belowBody: r.top >= b.bottom - 1, visible: r.width > 0 && r.height > 0 };
		} );
		t.check( 'stats pill sits in flow under the text on a phone',
			!! pill && pill.position === 'static' && pill.belowBody && pill.visible, JSON.stringify( pill ) );
		// Simulated keyboard (inset + class) for the checks that still ride it.
		await page.evaluate( () => {
			document.documentElement.style.setProperty( '--minn-kb-inset', '300px' );
			document.body.classList.add( 'minn-kb-open' );
		} );

		t.check( 'minn-kb-open hides session delta on the pill', await page.evaluate( () => {
			const sess = document.querySelector( '#minn-editor-stats .minn-stats-session' );
			// Absent when session is 0, or display:none when present + kb open.
			if ( ! sess ) return true;
			return getComputedStyle( sess ).display === 'none';
		} ) );

		// Clear simulated keyboard.
		await page.evaluate( () => {
			document.documentElement.style.setProperty( '--minn-kb-inset', '0px' );
			document.body.classList.remove( 'minn-kb-open' );
		} );

		const tool = await page.evaluate( () => {
			const el = document.querySelector( '.minn-editor-toolbar .minn-tool' );
			if ( ! el ) return null;
			const r = el.getBoundingClientRect();
			return { w: r.width, h: r.height };
		} );
		t.check( 'toolbar tools are finger-sized on phone viewport',
			!! tool && tool.w >= 36 && tool.h >= 36, JSON.stringify( tool ) );

		// One scrolling row of tools, not three stacked: with the keyboard up
		// a phone has ~350px left and a wrapped toolbar spent half of it.
		const bar = await page.evaluate( () => {
			const el = document.querySelector( '.minn-editor-toolbar' );
			if ( ! el ) return null;
			const tools = [ ...el.querySelectorAll( '.minn-tool' ) ];
			const tops = new Set( tools.map( ( b ) => Math.round( b.getBoundingClientRect().top ) ) );
			return { rows: tops.size, height: el.getBoundingClientRect().height, scrolls: el.scrollWidth > el.clientWidth + 4, overflowX: getComputedStyle( el ).overflowX };
		} );
		t.check( 'toolbar is a single row that scrolls sideways',
			!! bar && bar.rows === 1 && bar.height < 70 && bar.scrolls, JSON.stringify( bar ) );
		const pageFits = await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth );
		t.check( 'the scrolling toolbar does not widen the page', pageFits );

		// Find bar: open and check 16px inputs + phone width.
		await page.keyboard.press( 'Meta+Shift+f' );
		await page.waitForSelector( '#minn-find-bar', { timeout: 5000 } ).catch( () => null );
		const find = await page.evaluate( () => {
			const bar = document.getElementById( 'minn-find-bar' );
			const input = document.getElementById( 'minn-find-input' );
			if ( ! bar || ! input ) return null;
			const br = bar.getBoundingClientRect();
			return {
				barW: br.width,
				fontSize: parseFloat( getComputedStyle( input ).fontSize ),
				btnH: document.querySelector( '.minn-find-btn' )?.getBoundingClientRect().height || 0,
			};
		} );
		t.check( 'find bar opens on phone', !! find, JSON.stringify( find ) );
		if ( find ) {
			t.check( 'find input is 16px (no iOS focus-zoom)', find.fontSize >= 15.5, String( find.fontSize ) );
			t.check( 'find bar fits the phone width', find.barW <= 390 - 16, String( find.barW ) );
			t.check( 'find buttons are larger hit targets', find.btnH >= 32, String( find.btnH ) );
		} else {
			t.check( 'find input is 16px (no iOS focus-zoom)', false );
			t.check( 'find bar fits the phone width', false );
			t.check( 'find buttons are larger hit targets', false );
		}
		await page.keyboard.press( 'Escape' );

		// Toast also uses kb inset — pin the CSS contract via a temp toast node.
		const toastBottom = await page.evaluate( () => {
			const el = document.createElement( 'div' );
			el.className = 'minn-toast';
			el.textContent = 'probe';
			document.body.appendChild( el );
			document.documentElement.style.setProperty( '--minn-kb-inset', '200px' );
			void el.offsetHeight;
			const cs = getComputedStyle( el );
			const bottom = cs.bottom;
			el.remove();
			document.documentElement.style.setProperty( '--minn-kb-inset', '0px' );
			// Resolved bottom should be > 200px when inset is 200 (24 + safe + 200).
			const px = parseFloat( bottom );
			return { bottom, px, ok: Number.isFinite( px ) && px >= 200 };
		} );
		t.check( 'toast bottom tracks --minn-kb-inset', toastBottom.ok, JSON.stringify( toastBottom ) );

		// Typing still works at phone width (sanity after CSS churn).
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' ok' );
		t.check( 'typing works at phone viewport', await page.evaluate( () =>
			document.getElementById( 'minn-editor-body' ).textContent.includes( 'ok' ) ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
