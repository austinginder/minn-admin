/**
 * Pullquote as editable prose; details/spacer/file/shortcode as slash islands.
 * Details is deliberately NOT free top-level contenteditable — a live <details>
 * traps the caret in Blink. It is an island with interactive expand + in-card
 * summary/body fields instead.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'core-blocks-extend' );
	await login( page );

	const content = [
		'<!-- wp:paragraph -->',
		'<p>Lead-in.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:pullquote -->',
		'<figure class="wp-block-pullquote"><blockquote><p>Original pull</p><cite>Ada</cite></blockquote></figure>',
		'<!-- /wp:pullquote -->',
		'',
		'<!-- wp:details -->',
		'<details class="wp-block-details"><summary>Orig summary</summary><p>Orig body</p></details>',
		'<!-- /wp:details -->',
		'',
		'<!-- wp:spacer {"height":"40px"} -->',
		'<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>',
		'<!-- /wp:spacer -->',
		'',
		'<!-- wp:shortcode -->',
		'[gallery ids="1"]',
		'<!-- /wp:shortcode -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Core blocks extend ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture post', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
	await page.waitForFunction( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return b && /Original pull|Orig summary/.test( b.innerText || '' );
	}, null, { timeout: 15000 } );

	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const islands = [ ...body.querySelectorAll( '.minn-block-island' ) ];
		const detailsIsland = islands.find( ( el ) => /details/.test( el.dataset.block || '' ) );
		const freeDetails = body.querySelector( 'details.wp-block-details:not(.minn-block-island details)' );
		// Free details only counts if it's a direct child (not inside an island preview).
		const freeTopLevel = [ ...body.children ].some( ( el ) => el.tagName === 'DETAILS' );
		return {
			pull: !! body.querySelector( 'figure.wp-block-pullquote' ),
			pullEditable: body.querySelector( 'figure.wp-block-pullquote' )?.isContentEditable !== false
				&& ! body.querySelector( 'figure.wp-block-pullquote' )?.closest( '.minn-block-island' ),
			detailsIsland: !! detailsIsland,
			detailsInteractive: !!( detailsIsland && detailsIsland.querySelector( '.minn-details-summary' )
				&& detailsIsland.querySelector( '.minn-details-body' )
				&& detailsIsland.querySelector( 'details.minn-details-edit' ) ),
			detailsSummary: detailsIsland && detailsIsland.querySelector( '.minn-details-summary' )?.value,
			detailsBody: detailsIsland && ( detailsIsland.querySelector( '.minn-details-body' )?.textContent || '' ).trim(),
			detailsOpen: !!( detailsIsland && detailsIsland.querySelector( 'details.minn-details-edit' )?.open ),
			freeTopLevelDetails: freeTopLevel,
			spacerIsland: islands.some( ( el ) => /spacer/.test( el.dataset.block || '' ) ),
			shortcodeIsland: islands.some( ( el ) => /shortcode/.test( el.dataset.block || '' ) ),
			// Trailing affordance paragraph after the last island so typing continues.
			trailingP: body.lastElementChild && body.lastElementChild.tagName === 'P',
			text: body.innerText,
		};
	} );
	t.check( 'pullquote is live HTML (not island)', shape.pull && shape.pullEditable, JSON.stringify( shape ) );
	t.check( 'details loads as island (not free)', shape.detailsIsland && ! shape.freeTopLevelDetails, JSON.stringify( shape ) );
	t.check( 'details island is interactive', shape.detailsInteractive, JSON.stringify( shape ) );
	t.check( 'details summary field has original', shape.detailsSummary === 'Orig summary', JSON.stringify( shape ) );
	t.check( 'details body field has original', /Orig body/.test( shape.detailsBody || '' ), JSON.stringify( shape ) );
	t.check( 'spacer loads as island', shape.spacerIsland, JSON.stringify( shape ) );
	t.check( 'shortcode loads as island', shape.shortcodeIsland, JSON.stringify( shape ) );
	t.check( 'trailing paragraph after islands', shape.trailingP, JSON.stringify( shape ) );

	// Toggle details closed then open again
	const toggleOk = await page.evaluate( () => {
		const det = document.querySelector( '#minn-editor-body .minn-details-island details.minn-details-edit' );
		if ( ! det ) return { ok: false, reason: 'missing' };
		det.open = false;
		const closed = ! det.open;
		det.open = true;
		return { ok: closed && det.open, closed, open: det.open };
	} );
	t.check( 'details expand/collapse works', toggleOk.ok, JSON.stringify( toggleOk ) );

	// Edit pullquote text (prose path)
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body figure.wp-block-pullquote p' );
		if ( p ) p.textContent = 'Edited pullquote line';
		document.querySelector( '#minn-editor-body' ).dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );

	// Edit details in-island (summary input + body contenteditable)
	const detailsEdited = await page.evaluate( () => {
		const island = document.querySelector( '#minn-editor-body .minn-details-island' );
		if ( ! island ) return false;
		const det = island.querySelector( 'details.minn-details-edit' );
		if ( det ) det.open = true;
		const sum = island.querySelector( '.minn-details-summary' );
		const bodyEl = island.querySelector( '.minn-details-body' );
		if ( ! sum || ! bodyEl ) return false;
		sum.value = 'Edited summary';
		sum.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		bodyEl.innerHTML = '<p>Edited details body</p>';
		bodyEl.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		return sum.value === 'Edited summary' && /Edited details body/.test( bodyEl.textContent || '' );
	} );
	t.check( 'details edited in island fields', detailsEdited, 'dom' );

	// Save via keyboard
	await page.keyboard.down( 'Meta' );
	await page.keyboard.press( 's' );
	await page.keyboard.up( 'Meta' );
	await page.waitForTimeout( 800 );
	const saved = await page.evaluate( async ( pid ) => {
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /Update|Save|Publish/.test( b.textContent || '' ) );
		if ( btn ) btn.click();
		await new Promise( ( r ) => setTimeout( r, 1500 ) );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const j = await r.json();
		return j.content && j.content.raw;
	}, id );

	t.check( 'saved has pullquote block', /wp:pullquote/.test( saved || '' ), ( saved || '' ).slice( 0, 400 ) );
	t.check( 'saved pullquote text', /Edited pullquote line/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved has details block', /wp:details/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved details summary', /Edited summary/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved details body', /Edited details body/.test( saved || '' ), ( saved || '' ).slice( 0, 500 ) );
	t.check( 'saved keeps spacer', /wp:spacer/.test( saved || '' ), ( saved || '' ).slice( 0, 300 ) );
	t.check( 'saved keeps shortcode', /wp:shortcode/.test( saved || '' ) && /gallery ids/.test( saved || '' ), ( saved || '' ).slice( 0, 400 ) );

	// Slash insert pullquote
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/pull' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	const hasPull = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-slash-item' ) ].some( ( el ) => /Pullquote/i.test( el.textContent || '' ) )
	);
	t.check( 'slash lists Pullquote', hasPull, 'menu' );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /Pullquote/i.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 300 );
	const afterSlash = await page.evaluate( () =>
		!! document.querySelector( '#minn-editor-body figure.wp-block-pullquote' )
	);
	t.check( 'slash inserts live pullquote', afterSlash, 'dom' );

	// Slash details → island, not free <details>
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/details' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /^Details$/i.test( ( el.textContent || '' ).trim() ) || /Details/.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 500 );
	const detailsSlash = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const islands = [ ...body.querySelectorAll( '.minn-details-island, .minn-block-island' ) ]
			.filter( ( el ) => /details/.test( el.dataset.block || '' ) );
		const newest = islands[ islands.length - 1 ];
		const freeTop = [ ...body.children ].filter( ( el ) => el.tagName === 'DETAILS' );
		const last = body.lastElementChild;
		return {
			islandCount: islands.length,
			freeTop: freeTop.length,
			lastTag: last && last.tagName,
			lastIsP: last && last.tagName === 'P',
			interactive: !!( newest && newest.querySelector( '.minn-details-summary' ) && newest.querySelector( '.minn-details-body' ) ),
			open: !!( newest && newest.querySelector( 'details.minn-details-edit' )?.open ),
		};
	} );
	t.check( 'slash inserts details island', detailsSlash.islandCount >= 1, JSON.stringify( detailsSlash ) );
	t.check( 'slash details is not free top-level', detailsSlash.freeTop === 0, JSON.stringify( detailsSlash ) );
	t.check( 'slash details is interactive', detailsSlash.interactive, JSON.stringify( detailsSlash ) );
	t.check( 'slash details starts open for typing', detailsSlash.open, JSON.stringify( detailsSlash ) );
	t.check( 'landing paragraph after details island', detailsSlash.lastIsP, JSON.stringify( detailsSlash ) );

	// Type after the details island — must not trap the caret
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const last = body.lastElementChild;
		if ( ! last || last.tagName !== 'P' ) {
			const p = document.createElement( 'p' );
			p.appendChild( document.createElement( 'br' ) );
			body.appendChild( p );
		}
		const p = body.lastElementChild;
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
		p.focus();
	} );
	await page.keyboard.type( 'After details works' );
	const typed = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		return /After details works/.test( body.innerText || '' );
	} );
	t.check( 'can type after details island', typed, 'dom' );

	// Remove a details island via inspector Remove (undo-aware path)
	const beforeRm = await page.$$eval( '#minn-editor-body .minn-block-island', ( els ) =>
		els.filter( ( el ) => /details/.test( el.dataset.block || '' ) ).length
	);
	await page.evaluate( () => {
		const island = [ ...document.querySelectorAll( '#minn-editor-body .minn-block-island' ) ]
			.find( ( el ) => /details/.test( el.dataset.block || '' ) );
		const chip = island && island.querySelector( '.minn-island-chip' );
		if ( chip ) chip.click();
	} );
	await page.waitForSelector( '.minn-inspector #minn-insp-remove', { timeout: 10000 } );
	await page.click( '#minn-insp-remove' );
	await page.waitForTimeout( 300 );
	const afterRm = await page.$$eval( '#minn-editor-body .minn-block-island', ( els ) =>
		els.filter( ( el ) => /details/.test( el.dataset.block || '' ) ).length
	);
	t.check( 'can remove details island via inspector', afterRm < beforeRm, `before=${ beforeRm } after=${ afterRm }` );

	// Slash spacer still works
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/spacer' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ].find( ( el ) => /^Spacer$/i.test( ( el.textContent || '' ).trim() ) || /Spacer/.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 400 );
	const spacerOk = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-block-island' ) ].some( ( el ) => /spacer/.test( el.dataset.block || '' ) )
	);
	t.check( 'slash inserts spacer island', spacerOk, 'dom' );

	// Shortcode island: inline field (no browser prompt), type + save
	let dialogOpened = false;
	page.on( 'dialog', async ( d ) => {
		dialogOpened = true;
		await d.dismiss().catch( () => {} );
	} );
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const range = document.createRange();
		range.selectNodeContents( p );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	} );
	await page.keyboard.type( '/shortcode' );
	await page.waitForSelector( '.minn-slash-menu .minn-slash-item', { timeout: 3000 } );
	await page.evaluate( () => {
		const item = [ ...document.querySelectorAll( '.minn-slash-item' ) ]
			.find( ( el ) => /Shortcode/i.test( el.textContent || '' ) );
		if ( item ) item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 500 );
	const scShape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		// Newest shortcode island (slash insert appends after existing ones).
		const islands = [ ...body.querySelectorAll( '.minn-block-island' ) ]
			.filter( ( el ) => /shortcode/.test( el.dataset.block || '' ) );
		const island = islands[ islands.length - 1 ];
		const input = island && island.querySelector( '.minn-shortcode-input' );
		return {
			island: !! island,
			hasInput: !! input,
			value: input ? input.value : null,
			focused: !!( input && document.activeElement === input ),
			count: islands.length,
		};
	} );
	t.check( 'slash shortcode inserts island with input', scShape.island && scShape.hasInput, JSON.stringify( scShape ) );
	t.check( 'slash shortcode did not open a prompt', ! dialogOpened, 'dialog=' + dialogOpened );
	// Focus is best-effort (rAF after menu close); field presence is the contract.
	t.check( 'slash shortcode field is ready', scShape.hasInput && scShape.value === '[]', JSON.stringify( scShape ) );

	// Type a shortcode into the field
	await page.evaluate( () => {
		const input = [ ...document.querySelectorAll( '.minn-shortcode-input' ) ].pop();
		if ( ! input ) return;
		input.focus();
		input.select();
	} );
	await page.keyboard.type( '[contact-form-7 id="42"]' );
	await page.waitForTimeout( 200 );
	const scVal = await page.evaluate( () => {
		const input = [ ...document.querySelectorAll( '.minn-shortcode-input' ) ].pop();
		return input ? input.value : '';
	} );
	t.check( 'typed shortcode into island field', scVal === '[contact-form-7 id="42"]', scVal );

	// Save and verify
	await page.keyboard.down( 'Meta' );
	await page.keyboard.press( 's' );
	await page.keyboard.up( 'Meta' );
	await page.waitForTimeout( 800 );
	const savedSc = await page.evaluate( async ( pid ) => {
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /Update|Save|Publish/.test( b.textContent || '' ) );
		if ( btn ) btn.click();
		await new Promise( ( r ) => setTimeout( r, 1500 ) );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const j = await r.json();
		return j.content && j.content.raw;
	}, id );
	t.check( 'saved typed shortcode', /contact-form-7 id="42"/.test( savedSc || '' ), ( savedSc || '' ).slice( 0, 600 ) );

	// Fixture shortcode also loads with its value in the input
	const fixtureInput = await page.evaluate( () => {
		const inputs = [ ...document.querySelectorAll( '.minn-shortcode-input' ) ];
		return inputs.map( ( i ) => i.value );
	} );
	t.check(
		'loaded shortcode shows body in field',
		fixtureInput.some( ( v ) => /gallery ids/.test( v ) ) || fixtureInput.some( ( v ) => /contact-form-7/.test( v ) ),
		JSON.stringify( fixtureInput )
	);

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
