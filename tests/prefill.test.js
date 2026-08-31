/**
 * New-document prefill (GH #58): a launcher hands a blank editor its opening
 * document in the link — /minn-admin/editor/posts?title=…&content=…&tags=…
 * Everything is plain text (a crafted link can never inject markup), terms
 * resolve to existing ones and are never created, and the params are ignored
 * on a saved post so they can never overwrite real content.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

async function openNew( page, query ) {
	for ( let i = 0; i < 4; i++ ) {
		try {
			await page.goto( `${ BASE }/minn-admin/editor/posts${ query }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
			await page.waitForTimeout( 700 );
			return;
		} catch ( e ) {
			console.log( '  (editor load retry)' );
			await page.waitForTimeout( 3000 );
		}
	}
	throw new Error( 'new-post editor never loaded' );
}

const shape = ( page ) => page.evaluate( () => {
	const t = document.querySelector( '#minn-editor-title' );
	const b = document.querySelector( '#minn-editor-body' );
	return {
		title: t ? t.value : null,
		bodyText: b ? b.innerText.trim() : '',
		bodyHtml: b ? b.innerHTML : '',
		paras: b ? Array.from( b.querySelectorAll( ':scope > p' ) ).map( ( p ) => p.textContent ) : [],
		imgs: b ? b.querySelectorAll( 'img' ).length : 0,
		activeId: document.activeElement ? document.activeElement.id : '',
	};
} );

const rest = ( page, path, opts ) => page.evaluate( async ( a ) => {
	const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
	}, a.opts || {} ) );
	const j = await r.json().catch( () => null );
	if ( ! r.ok ) throw new Error( ( j && j.message ) || 'request failed' );
	return j;
}, { path, opts } );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'prefill' );
	await login( page );

	let savedId = null;
	let existingId = null;
	let tagId = null;
	let catId = null;

	try {
		/* ===== Title, with the special characters the issue calls out ===== */
		const PROMPT = "What gave you energy today, and what drained it? (Don't overthink it) & why";
		await openNew( page, '?title=' + encodeURIComponent( PROMPT ) );
		let s = await shape( page );
		t.check( 'title arrives verbatim, apostrophes and ? and & intact', s.title === PROMPT, s.title );
		t.check( 'a prefilled title puts the caret in the body', s.activeId === 'minn-editor-body', s.activeId );

		/* ===== Typing lands in the body and the pair saves together ===== */
		await page.keyboard.type( 'Slept well, so the morning was easy.' );
		await page.waitForTimeout( 300 );
		await page.keyboard.press( 'Meta+s' );
		// A first save on a cold session can queue behind the boot fetches.
		await page.waitForFunction( () => /\/editor\/posts\/\d+/.test( location.pathname ), null, { timeout: 60000 } );
		savedId = await page.evaluate( () => parseInt( location.pathname.match( /\/editor\/posts\/(\d+)/ )[ 1 ], 10 ) );
		const saved = await rest( page, `wp/v2/posts/${ savedId }?context=edit&_fields=title,content` );
		t.check( 'prefilled title is what got saved', saved.title.raw === PROMPT, saved.title.raw );
		t.check( 'typed body saved alongside it', /Slept well, so the morning was easy\./.test( saved.content.raw ), saved.content.raw.slice( 0, 120 ) );

		/* ===== Body prefill: blank lines become paragraphs ===== */
		await openNew( page, '?title=Body+probe&content=' + encodeURIComponent( 'First line.\n\nSecond paragraph.' ) );
		s = await shape( page );
		t.check( 'content prefill splits blank lines into paragraphs', s.paras.length >= 2 && s.paras[ 0 ] === 'First line.' && s.paras[ 1 ] === 'Second paragraph.', JSON.stringify( s.paras ) );
		t.check( 'caret sits at the end of the prefilled body', s.activeId === 'minn-editor-body', s.activeId );
		const atEnd = await page.evaluate( () => {
			const sel = window.getSelection();
			const p = document.querySelectorAll( '#minn-editor-body > p' );
			const last = p[ p.length - 1 ];
			return !! ( sel.rangeCount && last.contains( sel.anchorNode ) && sel.anchorOffset === last.textContent.length );
		} );
		t.check( 'caret is at the end of the last paragraph', atEnd );

		/* ===== Markup in the link stays text ===== */
		const NASTY = '<img src=x onerror="window.__minnPwned=1"> <!-- wp:paragraph --> <b>bold?</b>';
		await openNew( page, '?title=' + encodeURIComponent( '<b>t</b>' ) + '&content=' + encodeURIComponent( NASTY ) );
		s = await shape( page );
		const pwned = await page.evaluate( () => !! window.__minnPwned );
		t.check( 'no image element is created from link markup', s.imgs === 0, s.bodyHtml.slice( 0, 160 ) );
		t.check( 'no handler from the link ever ran', ! pwned );
		t.check( 'markup shows as literal text in the body', s.bodyText.indexOf( '<img src=x' ) === 0, s.bodyText.slice( 0, 60 ) );
		t.check( 'a title never renders as markup', s.title === '<b>t</b>', s.title );

		/* ===== Excerpt and terms (all live behind the Settings door) ===== */
		const tag = await rest( page, 'wp/v2/tags', { method: 'POST', body: JSON.stringify( { name: 'Prefill Probe Tag' } ) } );
		tagId = tag.id;
		const cat = await rest( page, 'wp/v2/categories', { method: 'POST', body: JSON.stringify( { name: 'Prefill Probe Cat' } ) } );
		catId = cat.id;
		const ghost = 'Definitely Not A Real Tag ' + Date.now();
		await openNew( page, '?title=Sidebar+probe'
			+ '&excerpt=' + encodeURIComponent( 'A short summary.' )
			+ '&tags=' + encodeURIComponent( 'Prefill Probe Tag, ' + ghost )
			+ '&categories=' + encodeURIComponent( cat.slug ) );
		// Term lookups answer in the background; the closed door's summary is
		// the first place they show up.
		await page.waitForFunction( () => {
			const d = document.querySelector( '[data-side-door="settings"]' );
			return d && /Prefill Probe Cat/.test( d.textContent ) && /1 tag/.test( d.textContent );
		}, null, { timeout: 25000 } ).catch( () => {} );
		const summary = await page.evaluate( () => {
			const d = document.querySelector( '[data-side-door="settings"]' );
			return d ? d.textContent.replace( /\s+/g, ' ' ).trim() : '';
		} );
		t.check( 'prefilled terms show on the closed Settings door', /Prefill Probe Cat/.test( summary ) && /1 tag/.test( summary ), summary );

		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '#minn-editor-excerpt', { timeout: 10000 } );
		// The ghost name costs a second lookup; wait for the real tag's chip
		// rather than a flat pause.
		await page.waitForSelector( '#minn-editor-tags [data-tagchip]', { timeout: 20000 } ).catch( () => {} );
		await page.waitForTimeout( 300 );
		const sideVals = await page.evaluate( ( id ) => ( {
			excerpt: ( document.querySelector( '#minn-editor-excerpt' ) || {} ).value,
			chips: Array.from( document.querySelectorAll( '#minn-editor-tags [data-tagchip]' ) ).map( ( c ) => c.textContent.trim() ),
			catSel: ( () => {
				const el = document.querySelector( `#minn-editor-cats [data-cat="${ id }"]` );
				return el ? el.classList.contains( 'sel' ) : null;
			} )(),
		} ), catId );
		t.check( 'excerpt prefill fills the sidebar field', sideVals.excerpt === 'A short summary.', String( sideVals.excerpt ) );
		t.check( 'an existing tag resolves onto the document', sideVals.chips.some( ( c ) => /Prefill Probe Tag/.test( c ) ), JSON.stringify( sideVals.chips ) );
		t.check( 'an unknown tag is skipped, never created', ! sideVals.chips.some( ( c ) => /Definitely Not A Real/.test( c ) ), JSON.stringify( sideVals.chips ) );
		t.check( 'an existing category resolves onto the document', sideVals.catSel === true, String( sideVals.catSel ) );
		const ghosts = await rest( page, 'wp/v2/tags?search=' + encodeURIComponent( 'Definitely Not A Real Tag' ) + '&_fields=id' );
		t.check( 'the link created no taxonomy term', Array.isArray( ghosts ) && ghosts.length === 0, JSON.stringify( ghosts ) );

		/* ===== A saved post is never overwritten by the same params ===== */
		existingId = await createPost( page, { title: 'Prefill must not touch me', content: '<!-- wp:paragraph --><p>Original body.</p><!-- /wp:paragraph -->', status: 'draft' } );
		for ( let i = 0; i < 4; i++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/editor/posts/${ existingId }?title=Hijacked&content=Replaced`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
				break;
			} catch ( e ) { await page.waitForTimeout( 3000 ); }
		}
		await page.waitForTimeout( 900 );
		s = await shape( page );
		t.check( 'prefill params are ignored on a saved post (title)', s.title === 'Prefill must not touch me', s.title );
		t.check( 'prefill params are ignored on a saved post (body)', /Original body\./.test( s.bodyText ), s.bodyText.slice( 0, 80 ) );

		/* ===== Nothing is written until the writer engages ===== */
		const before = await rest( page, 'wp/v2/posts?status=draft&per_page=1&_fields=id&search=' + encodeURIComponent( 'Inert probe' ) );
		await openNew( page, '?title=' + encodeURIComponent( 'Inert probe title' ) + '&content=' + encodeURIComponent( 'Inert probe body' ) );
		await page.waitForTimeout( 2500 );
		const after = await rest( page, 'wp/v2/posts?per_page=20&status=draft,publish,pending&_fields=id,title&search=' + encodeURIComponent( 'Inert probe' ) );
		t.check( 'a prefilled document that is never touched saves nothing', Array.isArray( after ) && after.length === 0, JSON.stringify( after ) + ' / ' + JSON.stringify( before ) );
	} finally {
		await deletePost( page, savedId );
		await deletePost( page, existingId );
		if ( tagId ) await rest( page, `wp/v2/tags/${ tagId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( catId ) await rest( page, `wp/v2/categories/${ catId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
