/**
 * In-place island text editing (nested-content plan, phase 2): grouped /
 * columns core content arms editable text runs inside the island preview;
 * edits splice into the stored raw and save byte-faithfully. Guards: Enter,
 * markdown wraps, slash menu, toolbar marks and rich paste are all blocked
 * inside runs (text-only by design); ⌘A clamps to the run.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'island-runs' );
	await login( page );

	const content = [
		'<!-- wp:group {"layout":{"type":"constrained"}} -->',
		'<div class="wp-block-group"><!-- wp:heading -->',
		'<h2 class="wp-block-heading">Welcome, Michala!</h2>',
		'<!-- /wp:heading -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Reminders are in the Noted link.</p>',
		'<!-- /wp:paragraph -->',
		'',
		'<!-- wp:list -->',
		'<ul class="wp-block-list"><!-- wp:list-item -->',
		'<li>First reminder item</li>',
		'<!-- /wp:list-item --></ul>',
		'<!-- /wp:list -->',
		'',
		'<!-- wp:acme/badge -->',
		'<div class="acme-badge">Badge text</div>',
		'<!-- /wp:acme/badge --></div>',
		'<!-- /wp:group -->',
		'',
		'<!-- wp:columns -->',
		'<div class="wp-block-columns"><!-- wp:column -->',
		'<div class="wp-block-column"><!-- wp:paragraph -->',
		'<p>Left column text.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:column -->',
		'',
		'<!-- wp:column -->',
		'<div class="wp-block-column"><!-- wp:paragraph -->',
		'<p>Right column text stays untouched.</p>',
		'<!-- /wp:paragraph --></div>',
		'<!-- /wp:column --></div>',
		'<!-- /wp:columns -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Ungrouped paragraph after.</p>',
		'<!-- /wp:paragraph -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Island runs ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-block-island[data-block="group"] .minn-island-run', { timeout: 25000 } );

	const shape = await page.evaluate( () => {
		const g = document.querySelector( '.minn-block-island[data-block="group"]' );
		const c = document.querySelector( '.minn-block-island[data-block="columns"]' );
		return {
			groupRuns: g ? g.querySelectorAll( '.minn-island-run' ).length : 0,
			colRuns: c ? c.querySelectorAll( '.minn-island-run' ).length : 0,
			hint: g ? ( g.querySelector( '.minn-island-hint' ) || {} ).textContent : '',
			editable: g ? [ ...g.querySelectorAll( '.minn-island-run' ) ].every( ( s ) => s.isContentEditable ) : false,
		};
	} );
	t.check( 'group arms four runs (incl. unregistered child)', shape.groupRuns === 4, JSON.stringify( shape ) );
	t.check( 'columns arm two runs', shape.colRuns === 2, JSON.stringify( shape ) );
	t.check( 'runs are editable, hint updated', shape.editable && /editable in place/i.test( shape.hint ), shape.hint );

	// Place the caret at the END of a run (deterministic Range — the
	// selection-dependent-setup rule), then type with real keystrokes.
	const caretEnd = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
		el.focus();
	}, sel );

	// 1. Heading edit.
	await page.click( '.minn-block-island[data-block="group"] h2 .minn-island-run' );
	await caretEnd( '.minn-block-island[data-block="group"] h2 .minn-island-run' );
	await page.keyboard.type( ' and friends' );

	// 2. Markdown must stay literal inside a run (bindMarkdown is blocked).
	await page.click( '.minn-block-island[data-block="group"] p .minn-island-run' );
	await caretEnd( '.minn-block-island[data-block="group"] p .minn-island-run' );
	await page.keyboard.type( ' Stay **calm** here.' );
	const pHtml = await page.$eval( '.minn-block-island[data-block="group"] p .minn-island-run', ( el ) => el.innerHTML );
	t.check( 'markdown stays literal in run', pHtml.includes( '**calm**' ) && ! /<(strong|b)>/i.test( pHtml ), pHtml );

	// 3. Slash menu never opens from a run.
	await page.keyboard.type( ' /' );
	await page.waitForTimeout( 350 );
	const slashOpen = await page.evaluate( () => !! document.querySelector( '.minn-slash-menu' ) );
	t.check( 'slash menu blocked in run', ! slashOpen, '' );
	await page.keyboard.press( 'Backspace' );
	await page.keyboard.press( 'Backspace' );

	// 4. Enter is a no-op (would insert <br><br> — probed).
	await page.click( '.minn-block-island[data-block="group"] li .minn-island-run' );
	await caretEnd( '.minn-block-island[data-block="group"] li .minn-island-run' );
	await page.keyboard.press( 'Enter' );
	const liHtml = await page.$eval( '.minn-block-island[data-block="group"] li .minn-island-run', ( el ) => el.innerHTML );
	t.check( 'enter blocked in run', ! /<br/i.test( liHtml ), liHtml );

	// 5. ⌘A clamps to the run (natively it selects the whole outer body).
	await page.keyboard.press( 'Meta+a' );
	const selShape = await page.evaluate( () => {
		const sel = window.getSelection();
		const run = document.querySelector( '.minn-block-island[data-block="group"] li .minn-island-run' );
		return { text: sel.toString(), inRun: run.contains( sel.anchorNode ) };
	} );
	t.check( 'select-all clamped to run', selShape.inRun && selShape.text === 'First reminder item', JSON.stringify( selShape ) );

	// 6. Toolbar formatting refuses inside a run (selection still in the li).
	await page.evaluate( () => {
		const b = document.querySelector( '.minn-tool[data-cmd="bold"]' );
		b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 250 );
	const liAfterBold = await page.$eval( '.minn-block-island[data-block="group"] li .minn-island-run', ( el ) => el.innerHTML );
	t.check( 'toolbar marks refused in run', ! /<(strong|b)>/i.test( liAfterBold ), liAfterBold );

	// 7. Rich paste lands as plain text.
	await caretEnd( '.minn-block-island[data-block="group"] li .minn-island-run' );
	await page.evaluate( () => {
		const el = document.querySelector( '.minn-block-island[data-block="group"] li .minn-island-run' );
		const dt = new DataTransfer();
		dt.setData( 'text/html', '<b>RICH</b> paste' );
		dt.setData( 'text/plain', 'RICH paste' );
		el.dispatchEvent( new ClipboardEvent( 'paste', { clipboardData: dt, bubbles: true, cancelable: true } ) );
	} );
	const liAfterPaste = await page.$eval( '.minn-block-island[data-block="group"] li .minn-island-run', ( el ) => el.innerHTML );
	t.check( 'paste is plain text in run', liAfterPaste.includes( 'RICH paste' ) && ! /<b>/i.test( liAfterPaste ), liAfterPaste );

	// 8. Native undo tracks in-run typing: revert the paste + a column edit.
	await page.click( '.minn-block-island[data-block="columns"] .minn-island-run' );
	await caretEnd( '.minn-block-island[data-block="columns"] .minn-island-run' );
	await page.keyboard.type( ' XYZ' );
	// Undo until reverted, never past it — the stack is global, and surplus
	// presses would walk back into the li paste above (bit this suite once).
	let colText = '';
	for ( let i = 0; i < 8; i++ ) {
		await page.keyboard.press( 'Meta+z' );
		colText = await page.$eval( '.minn-block-island[data-block="columns"] .minn-island-run', ( el ) => el.textContent );
		if ( colText === 'Left column text.' ) break;
	}
	t.check( 'undo reverts run typing', colText === 'Left column text.', colText );

	// 9. Save and verify the STORED markup — the standing conventions: edited
	// text present, everything untouched byte-identical, no chrome leaks.
	await page.keyboard.press( 'Meta+s' );
	await page.waitForFunction( () => {
		const el = document.querySelector( '#minn-saved-state' );
		return el && /just now/i.test( el.textContent || '' );
	}, { timeout: 15000 } ).catch( () => {} );
	await page.waitForTimeout( 1500 );

	const raw = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id );

	t.check( 'heading edit saved', raw.includes( '<h2 class="wp-block-heading">Welcome, Michala! and friends</h2>' ), '' );
	t.check( 'paragraph edit saved literal', raw.includes( 'Stay **calm** here.' ), '' );
	t.check( 'list paste saved', raw.includes( 'First reminder itemRICH paste' ) || raw.includes( 'First reminder item RICH paste' ), '' );
	t.check( 'undone column edit NOT saved', raw.includes( '<p>Left column text.</p>' ) && ! raw.includes( 'XYZ' ), '' );
	t.check( 'group comment + attrs intact', raw.includes( '<!-- wp:group {"layout":{"type":"constrained"}} -->' ) && raw.includes( '<!-- /wp:group -->' ), '' );
	t.check( 'columns comments intact', ( raw.match( /<!-- wp:column -->/g ) || [] ).length === 2 && raw.includes( '<!-- /wp:columns -->' ), '' );
	t.check( 'untouched text byte-identical', raw.includes( '<p>Right column text stays untouched.</p>' ) && raw.includes( '<p>Ungrouped paragraph after.</p>' ), '' );
	t.check( 'no editor chrome leaked', ! raw.includes( 'minn-island-run' ) && ! raw.includes( 'contenteditable' ), '' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
