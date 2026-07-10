/**
 * Structural undo (docs/editor-roadmap.md "Undo completeness"):
 *  - Island deletions are direct-DOM → "Removed — Undo" toast button
 *  - Table row/col/header/delete ops go through execCommand → real ⌘Z
 * Each case deletes, undoes, and verifies the SAVED markup is restored.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const EMBED = '<!-- wp:embed {"url":"https://youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube"} -->\n<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">https://youtube.com/watch?v=abc</div></figure>\n<!-- /wp:embed -->';
const P = ( h ) => `<!-- wp:paragraph -->\n<p>${ h }</p>\n<!-- /wp:paragraph -->`;
const TABLE = '<!-- wp:table -->\n<figure class="wp-block-table"><table><tbody><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></tbody></table></figure>\n<!-- /wp:table -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'undo-toast' );
	page.on( 'dialog', ( d ) => d.accept() );
	await login( page );

	const ids = [];
	const raw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 1500 ); };
	const toastUndo = async () => { await page.waitForSelector( '.minn-toast-btn', { timeout: 4000 } ); await page.click( '.minn-toast-btn' ); await page.waitForTimeout( 300 ); };
	const cmdZ = async () => {
		await page.focus( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 300 );
	};
	const islands = () => page.evaluate( () => document.querySelectorAll( '#minn-editor-body .minn-block-island' ).length );
	const rows = () => page.evaluate( () => document.querySelectorAll( '#minn-editor-body table tr' ).length );
	const cells = () => page.evaluate( () => document.querySelectorAll( '#minn-editor-body table tr:first-child td, #minn-editor-body table tr:first-child th' ).length );
	const openTablePop = () => page.evaluate( () => {
		const cell = document.querySelector( '#minn-editor-body table td' );
		const r = document.createRange(); r.selectNodeContents( cell ); r.collapse( true );
		const s = getSelection(); s.removeAllRanges(); s.addRange( r ); document.querySelector( '#minn-editor-body' ).focus();
		const chips = document.querySelectorAll( '#minn-table-chips .minn-code-chip' );
		for ( const c of chips ) if ( c._kind === 'table' ) { c.click(); return; }
	} );
	const fresh = async ( c ) => { const id = await createPost( page, { title: 'undo toast', content: c } ); ids.push( id ); await openEditor( page, id ); await page.waitForTimeout( 400 ); return id; };

	/* ===== Island delete via backspace guard ===== */
	const id1 = await fresh( P( 'before' ) + '\n\n' + EMBED + '\n\n' + P( 'after' ) );
	await page.evaluate( () => {
		const ps = document.querySelectorAll( '#minn-editor-body > p' ); const el = ps[ ps.length - 1 ];
		const r = document.createRange(); r.setStart( el.firstChild || el, 0 ); r.collapse( true );
		const s = getSelection(); s.removeAllRanges(); s.addRange( r ); document.querySelector( '#minn-editor-body' ).focus();
	} );
	await page.keyboard.press( 'Backspace' ); await page.waitForTimeout( 150 );
	await page.keyboard.press( 'Backspace' ); await page.waitForTimeout( 200 );
	t.check( 'backspace removes the embed island', ( await islands() ) === 0 );
	await toastUndo();
	t.check( 'Undo restores the island in the DOM', ( await islands() ) === 1 );
	await save();
	let r = await raw( id1 );
	t.check( 'restored embed saves with its original markup', /wp:embed/.test( r ) && /watch\?v=abc/.test( r ), r.slice( 0, 120 ) );

	// The async island-preview swap can replace the island (closing a
	// just-opened inspector) right under an early chip click — a slow oEmbed
	// fetch makes that window seconds wide. Re-click until it settles.
	const openInspector = async () => {
		for ( let i = 0; ; i++ ) {
			await page.click( '#minn-editor-body .minn-island-chip' );
			try {
				await page.waitForSelector( '#minn-insp-remove', { timeout: 4000 } );
				return;
			} catch ( e ) {
				if ( i >= 3 ) throw e;
				await page.waitForTimeout( 1500 );
			}
		}
	};

	/* ===== Island delete via the inspector ===== */
	const id2 = await fresh( P( 'x' ) + '\n\n' + EMBED );
	await openInspector();
	await page.click( '#minn-insp-remove' );
	await page.waitForTimeout( 200 );
	t.check( 'inspector remove deletes the island (no confirm)', ( await islands() ) === 0 );
	await toastUndo();
	await save();
	r = await raw( id2 );
	t.check( 'inspector-removed island restores + saves', ( await islands() ) === 1 && /wp:embed/.test( r ), '' );

	/* ===== Island delete — ⌘Z restores (same as toast Undo) ===== */
	const id2b = await fresh( P( 'x' ) + '\n\n' + EMBED );
	await openInspector();
	await page.click( '#minn-insp-remove' );
	await page.waitForTimeout( 200 );
	t.check( 'inspector remove for ⌘Z test', ( await islands() ) === 0 );
	await page.waitForSelector( '.minn-toast-action', { timeout: 4000 } );
	await cmdZ();
	t.check( '⌘Z restores the island (toast Undo path)', ( await islands() ) === 1 );
	await save();
	r = await raw( id2b );
	t.check( '⌘Z-restored island saves', /wp:embed/.test( r ) && /watch\?v=abc/.test( r ), r.slice( 0, 160 ) );

	/* ===== Table row delete — ⌘Z ===== */
	const id3 = await fresh( TABLE );
	await openTablePop();
	await page.waitForTimeout( 200 );
	await page.click( '[data-op="row-del"]' ); await page.waitForTimeout( 250 );
	t.check( 'row-del removes a row', ( await rows() ) === 1 );
	await cmdZ();
	t.check( '⌘Z restores the row', ( await rows() ) === 2 );
	await save();
	r = await raw( id3 );
	t.check( 'both rows saved after restore', /<td>a<\/td><td>b<\/td>/.test( r ) && /<td>c<\/td><td>d<\/td>/.test( r ), '' );

	/* ===== Table column delete — ⌘Z ===== */
	const id4 = await fresh( TABLE );
	await openTablePop();
	await page.waitForTimeout( 200 );
	await page.click( '[data-op="col-del"]' ); await page.waitForTimeout( 250 );
	t.check( 'col-del removes a column', ( await cells() ) === 1 );
	await cmdZ();
	t.check( '⌘Z restores the column', ( await cells() ) === 2 );
	await save();
	r = await raw( id4 );
	t.check( 'both columns saved after restore', /<td>a<\/td><td>b<\/td>/.test( r ), '' );

	/* ===== Whole table delete — ⌘Z ===== */
	const id5 = await fresh( TABLE + '\n\n' + P( 'keep' ) );
	await openTablePop();
	await page.waitForTimeout( 200 );
	await page.click( '[data-op="table-del"]' ); await page.waitForTimeout( 250 );
	t.check( 'table-del removes the figure', ( await page.evaluate( () => document.querySelectorAll( '#minn-editor-body figure' ).length ) ) === 0 );
	await cmdZ();
	await save();
	r = await raw( id5 );
	t.check( 'deleted table restores + saves via ⌘Z', ( await page.evaluate( () => document.querySelectorAll( '#minn-editor-body figure' ).length ) ) === 1 && /wp:table/.test( r ), '' );

	/* ===== Add row / add column also undo via ⌘Z ===== */
	const id6 = await fresh( TABLE );
	await openTablePop();
	await page.waitForTimeout( 200 );
	await page.click( '[data-op="row-below"]' ); await page.waitForTimeout( 250 );
	t.check( 'row-below adds a row', ( await rows() ) === 3 );
	await cmdZ();
	t.check( '⌘Z undoes add-row', ( await rows() ) === 2 );
	await openTablePop();
	await page.waitForTimeout( 150 );
	await page.click( '[data-op="col-right"]' ); await page.waitForTimeout( 250 );
	t.check( 'col-right adds a column', ( await cells() ) === 3 );
	await cmdZ();
	t.check( '⌘Z undoes add-column', ( await cells() ) === 2 );
	await save();
	r = await raw( id6 );
	t.check( 'table back to 2×2 after add undos', /<td>a<\/td><td>b<\/td>/.test( r ) && /<td>c<\/td><td>d<\/td>/.test( r ), r.slice( 0, 160 ) );

	for ( const id of ids ) await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
