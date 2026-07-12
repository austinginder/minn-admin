/**
 * IME / composition safety (Horizon 1 input long tail).
 *
 * Markdown wraps, island Backspace arming, slash-menu Enter and figcaption
 * Enter all fire on keydown and preventDefault. During IME composition
 * (CJK, dead keys) those keydowns must be ignored so candidates aren't
 * stolen. isComposing is the standard signal; keyCode 229 is the legacy
 * Blink/WebKit "IME processing" value.
 *
 * Real IME engines aren't available in headless Chrome, so this suite
 * synthesizes composition-shaped KeyboardEvents against a live caret.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'ime' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, {
		title: 'IME composition test',
		content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->',
	} );

	const lastHtml = () => page.evaluate( () => {
		const p = window.__minnTestPara || document.querySelector( '#minn-editor-body' ).lastElementChild;
		return p ? p.innerHTML : '';
	} );

	// Place the caret at the end of __minnTestPara and set its text content
	// (one text node) so markdown's upto-slice sees a known string.
	const seedPara = async ( text ) => {
		await freshParagraph( page );
		await page.evaluate( ( s ) => {
			const p = window.__minnTestPara;
			p.textContent = s;
			const tn = p.firstChild;
			const r = document.createRange();
			r.setStart( tn, tn.textContent.length );
			r.collapse( true );
			const sel = getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			document.getElementById( 'minn-editor-body' ).focus( { preventScroll: true } );
		}, text );
	};

	// Fire a keydown on the editor body that bubbles like a real key.
	const fireKey = async ( init ) => page.evaluate( ( opts ) => {
		const body = document.getElementById( 'minn-editor-body' );
		const e = new KeyboardEvent( 'keydown', {
			bubbles: true,
			cancelable: true,
			key: opts.key,
			code: opts.code || '',
			keyCode: opts.keyCode != null ? opts.keyCode : 0,
			which: opts.keyCode != null ? opts.keyCode : 0,
			// KeyboardEventInit supports isComposing in modern engines.
		} );
		// isComposing is often read-only on the constructed event in older
		// engines — defineProperty so the handler always sees it.
		Object.defineProperty( e, 'isComposing', { get: () => !! opts.isComposing } );
		return body.dispatchEvent( e );
	}, init );

	try {
		await openEditor( page, id );

		// --- Markdown: closing * would wrap **bold* + * → <strong> ---
		await seedPara( 'go **big*' );
		await fireKey( { key: '*', isComposing: true } );
		let h = await lastHtml();
		t.check( 'markdown ignores * keydown while isComposing',
			! h.includes( '<strong>' ) && h.includes( '**big*' ), h );

		// Same shape with legacy keyCode 229 (IME processing).
		await seedPara( 'go **big*' );
		await fireKey( { key: '*', keyCode: 229, isComposing: false } );
		h = await lastHtml();
		t.check( 'markdown ignores keyCode 229 even without isComposing',
			! h.includes( '<strong>' ) && h.includes( '**big*' ), h );

		// Control: without composition the wrap still fires.
		await seedPara( 'go **big*' );
		await fireKey( { key: '*', isComposing: false, keyCode: 56 } );
		h = await lastHtml();
		t.check( 'markdown still wraps * when not composing',
			h.includes( '<strong>big</strong>' ), h );

		// Block prefix on space during composition must not promote a heading.
		await seedPara( '##' );
		await fireKey( { key: ' ', isComposing: true } );
		const tag = await page.evaluate( () => window.__minnTestPara.tagName );
		const text = await page.evaluate( () => window.__minnTestPara.textContent );
		t.check( '## + space during composition stays a paragraph',
			tag === 'P' && text.replace( /\u00a0/g, ' ' ).includes( '##' ),
			JSON.stringify( { tag, text } ) );

		// --- Slash menu: Enter during composition must not run an action ---
		await freshParagraph( page );
		await page.keyboard.type( '/', { delay: 40 } );
		await page.waitForSelector( '.minn-slash-menu', { timeout: 5000 } );
		const menuBefore = await page.$( '.minn-slash-menu' );
		t.check( 'slash menu opened for Enter test', !! menuBefore );

		const cancelled = await page.evaluate( () => {
			const body = document.getElementById( 'minn-editor-body' );
			const e = new KeyboardEvent( 'keydown', {
				bubbles: true, cancelable: true, key: 'Enter', keyCode: 13,
			} );
			Object.defineProperty( e, 'isComposing', { get: () => true } );
			body.dispatchEvent( e );
			return !! document.querySelector( '.minn-slash-menu' );
		} );
		t.check( 'slash Enter during composition leaves the menu open', cancelled );

		// Escape closes for cleanup.
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );

		// --- Island guard: Backspace mid-composition must not arm ---
		// Insert an embed-like island, put caret in the next empty p, fire
		// composing Backspace — island should not gain .minn-island-armed.
		// Island arming only needs the DOM class + caret adjacency — no
		// islands[] store required until an actual remove.
		await page.evaluate( () => {
			const body = document.getElementById( 'minn-editor-body' );
			const island = document.createElement( 'div' );
			island.className = 'minn-block-island';
			island.contentEditable = 'false';
			island.dataset.island = '0';
			island.dataset.block = 'core/embed';
			island.innerHTML = '<div class="minn-island-preview">fixture island</div>';
			const p = document.createElement( 'p' );
			p.appendChild( document.createElement( 'br' ) );
			body.appendChild( island );
			body.appendChild( p );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( true );
			const s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
			body.focus( { preventScroll: true } );
			window.__minnTestIsland = island;
			window.__minnTestPara = p;
		} );
		await page.evaluate( () => {
			const body = document.getElementById( 'minn-editor-body' );
			const e = new KeyboardEvent( 'keydown', {
				bubbles: true, cancelable: true, key: 'Backspace', keyCode: 8,
			} );
			Object.defineProperty( e, 'isComposing', { get: () => true } );
			// Capture listeners also see bubble:true events on the way down
			// when the target is body… dispatch on the body itself.
			body.dispatchEvent( e );
		} );
		const armed = await page.evaluate( () =>
			!! ( window.__minnTestIsland && window.__minnTestIsland.classList.contains( 'minn-island-armed' ) ) );
		t.check( 'island Backspace during composition does not arm', ! armed );

		// Control: same Backspace without composition arms (or removes empty p first).
		// Empty paragraph next to island: first Backspace removes empty p OR arms.
		// Seed again with empty p after island.
		await page.evaluate( () => {
			const body = document.getElementById( 'minn-editor-body' );
			const island = window.__minnTestIsland;
			island.classList.remove( 'minn-island-armed' );
			// Ensure island still before an empty p with caret at start.
			let p = window.__minnTestPara;
			if ( ! p.isConnected ) {
				p = document.createElement( 'p' );
				p.appendChild( document.createElement( 'br' ) );
				island.after( p );
				window.__minnTestPara = p;
			}
			p.textContent = '';
			p.appendChild( document.createElement( 'br' ) );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( true );
			const s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
			body.focus( { preventScroll: true } );
		} );
		// Non-composing Backspace at empty p edge next to island → arm island
		// (bindIslandGuards: empty block may be removed first; second press arms).
		await page.keyboard.press( 'Backspace' );
		await page.waitForTimeout( 100 );
		// If empty p was removed, caret may be on island or previous — press again.
		const armedAfter = await page.evaluate( () => {
			const island = window.__minnTestIsland;
			if ( island && island.classList.contains( 'minn-island-armed' ) ) return true;
			// Try a second Backspace if still at edge.
			return false;
		} );
		if ( ! armedAfter ) {
			await page.keyboard.press( 'Backspace' );
			await page.waitForTimeout( 100 );
		}
		const armedControl = await page.evaluate( () =>
			!! ( window.__minnTestIsland && (
				window.__minnTestIsland.classList.contains( 'minn-island-armed' )
				|| ! window.__minnTestIsland.isConnected
			) ) );
		t.check( 'island Backspace without composition still arms or removes', armedControl );

		// Helper is on the closed-over scope — verify via behavior already covered.
		// Pin that Latin markdown still works end-to-end after the guards.
		await freshParagraph( page );
		await page.keyboard.type( 'still **works** here', { delay: 20 } );
		h = await lastHtml();
		t.check( 'latin markdown still works after IME guards',
			/<strong>works<\/strong>/.test( h ), h );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
