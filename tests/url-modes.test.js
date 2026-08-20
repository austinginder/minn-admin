/**
 * URL-triggered view modes (GH #41): ?focus=1 / ?outline=1 on an editor URL
 * enter the mode for that visit; ?focus=0 / ?outline=0 force an exit. The
 * params are one-shot per editor open and NEVER write localStorage — a shared
 * link must not flip the reader's own standing preference, and a manual exit
 * afterwards must survive re-renders.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

// Headings included so outline mode's ToC card has something to list.
const PARAS = `<!-- wp:heading --><h2 class="wp-block-heading">Probe section one</h2><!-- /wp:heading -->`
	+ Array.from( { length: 3 }, ( _, i ) =>
		`<!-- wp:paragraph --><p>URL mode probe paragraph ${ i + 1 } with a few words in it.</p><!-- /wp:paragraph -->` ).join( '' )
	+ `<!-- wp:heading --><h2 class="wp-block-heading">Probe section two</h2><!-- /wp:heading -->`
	+ Array.from( { length: 3 }, ( _, i ) =>
		`<!-- wp:paragraph --><p>URL mode probe paragraph ${ i + 4 } with a few words in it.</p><!-- /wp:paragraph -->` ).join( '' );

async function openWith( page, id, query ) {
	for ( let i = 0; i < 4; i++ ) {
		try {
			await page.goto( `${ BASE }/minn-admin/editor/posts/${ id }${ query }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
			await page.waitForTimeout( 800 );
			return;
		} catch ( e ) {
			console.log( '  (editor load retry)' );
			await page.waitForTimeout( 3000 );
		}
	}
	throw new Error( 'editor never loaded for post ' + id );
}

const modeState = ( page ) => page.evaluate( () => ( {
	zen: document.body.classList.contains( 'minn-focus-zen' ),
	outline: document.body.classList.contains( 'minn-outline-mode' ),
	lsFocus: ( () => { try { return localStorage.getItem( 'minn-focus' ) || ''; } catch ( e ) { return ''; } } )(),
	lsOutline: ( () => { try { return localStorage.getItem( 'minn-outline-mode' ) || ''; } catch ( e ) { return ''; } } )(),
} ) );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'url-modes' );
	await login( page );

	const id = await createPost( page, { title: 'URL modes probe', content: PARAS, status: 'draft' } );

	/* ===== Clean slate ===== */
	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-sidebar', { timeout: 15000 } );
	await page.evaluate( () => { localStorage.removeItem( 'minn-focus' ); localStorage.removeItem( 'minn-outline-mode' ); } );

	/* ===== ?focus=1 enters focus mode without persisting ===== */
	await openWith( page, id, '?focus=1' );
	let s = await modeState( page );
	t.check( '?focus=1 enters focus mode', s.zen && ! s.outline, JSON.stringify( s ) );
	t.check( '?focus=1 never writes localStorage', s.lsFocus === '' && s.lsOutline === '', JSON.stringify( s ) );
	const toastText = await page.evaluate( () => {
		const el = document.querySelector( '.minn-toast' );
		return el ? el.textContent : '';
	} );
	t.check( 'entry toast names the exit shortcut', /⌘⇧D/.test( toastText ), toastText );

	/* ===== Manual exit sticks while the param is still in the URL ===== */
	await page.click( '#minn-editor-body p:nth-of-type(2)' );
	await page.keyboard.press( 'Meta+Shift+D' );
	await page.waitForTimeout( 500 );
	s = await modeState( page );
	t.check( 'manual exit works with ?focus=1 still in the URL', ! s.zen, JSON.stringify( s ) );
	// Keep working in the editor and confirm nothing on the editing cadence
	// (stats, autosave scheduling) re-asserts the mode from the URL.
	await page.keyboard.type( ' more' );
	await page.waitForTimeout( 800 );
	s = await modeState( page );
	t.check( 'exit survives further activity (one-shot param)', ! s.zen, JSON.stringify( s ) );

	/* ===== ?outline=1 enters outline mode without persisting ===== */
	await openWith( page, id, '?outline=1' );
	s = await modeState( page );
	t.check( '?outline=1 enters outline mode', s.outline && ! s.zen, JSON.stringify( s ) );
	t.check( '?outline=1 never writes localStorage', s.lsFocus === '' && s.lsOutline === '', JSON.stringify( s ) );
	const outlineVisible = await page.evaluate( () => {
		const card = document.querySelector( '#minn-outline-card' );
		return !! ( card && card.checkVisibility && card.checkVisibility() );
	} );
	t.check( 'outline card is the visible sidebar card', outlineVisible, '' );

	/* ===== Conflict: focus wins ===== */
	await openWith( page, id, '?focus=1&outline=1' );
	s = await modeState( page );
	t.check( '?focus=1&outline=1 resolves to focus (focus wins)', s.zen && ! s.outline, JSON.stringify( s ) );

	/* ===== ?focus=0 forces an exit past a persisted preference ===== */
	await page.evaluate( () => { try { localStorage.setItem( 'minn-focus', '1' ); } catch ( e ) {} } );
	await openWith( page, id, '?focus=0' );
	s = await modeState( page );
	t.check( '?focus=0 overrides a persisted focus preference', ! s.zen, JSON.stringify( s ) );
	t.check( '?focus=0 leaves the stored preference untouched', s.lsFocus === '1', JSON.stringify( s ) );

	/* ===== A plain visit still honors the stored preference ===== */
	await openWith( page, id, '' );
	s = await modeState( page );
	t.check( 'param-less visit restores the persisted mode', s.zen, JSON.stringify( s ) );

	/* ===== Cleanup: modes off for whoever runs next in this profile ===== */
	await page.evaluate( () => { localStorage.removeItem( 'minn-focus' ); localStorage.removeItem( 'minn-outline-mode' ); } );
	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
