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

		// Simulated keyboard: 300px inset lifts the stats pill.
		const lift = await page.evaluate( () => {
			const el = document.getElementById( 'minn-editor-stats' );
			if ( ! el ) return null;
			const before = el.getBoundingClientRect().bottom;
			document.documentElement.style.setProperty( '--minn-kb-inset', '300px' );
			document.body.classList.add( 'minn-kb-open' );
			// Force layout.
			void el.offsetHeight;
			const after = el.getBoundingClientRect().bottom;
			return { before, after, movedUp: after < before - 100 };
		} );
		t.check( 'stats pill rises when --minn-kb-inset grows',
			!! ( lift && lift.movedUp ), JSON.stringify( lift ) );

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

		// Body bottom padding so the last line clears the pill.
		const pad = await page.evaluate( () => {
			const body = document.getElementById( 'minn-editor-body' );
			return body ? parseFloat( getComputedStyle( body ).paddingBottom ) : 0;
		} );
		t.check( 'editor body has bottom padding under the stats pill', pad >= 60, String( pad ) );

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
