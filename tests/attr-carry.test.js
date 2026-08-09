/**
 * Attribute carry for text-flow blocks (nested-content plan, phase 1):
 * styled paragraphs/headings/lists (fontSize, style, className…) load as
 * editable PROSE with the comment JSON parked on the element, instead of
 * islanding. Saves re-emit the carried JSON byte-faithfully; Enter
 * mid-splits duplicate attrs (Gutenberg split semantics) while end-splits
 * yield a default paragraph; merges keep the first block's attrs;
 * block-TYPE conversions (markdown prefixes, toolbar) are refused.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'attr-carry' );
	await login( page );

	const P_ATTRS = '{"style":{"color":{"text":"#cf2e2e"}},"fontSize":"large"}';
	const H_ATTRS = '{"level":3,"fontSize":"x-large"}';
	const L_ATTRS = '{"className":"is-style-checkmark-list"}';
	const LI_ATTRS = '{"className":"fancy-item"}';
	const content = [
		`<!-- wp:paragraph ${ P_ATTRS } -->`,
		'<p class="has-text-color has-large-font-size" style="color:#cf2e2e">Styled words here.</p>',
		'<!-- /wp:paragraph -->',
		'',
		`<!-- wp:heading ${ H_ATTRS } -->`,
		'<h3 class="wp-block-heading has-x-large-font-size">Styled heading</h3>',
		'<!-- /wp:heading -->',
		'',
		`<!-- wp:list ${ L_ATTRS } -->`,
		'<ul class="wp-block-list is-style-checkmark-list"><!-- wp:list-item -->',
		'<li>Plain item</li>',
		'<!-- /wp:list-item -->',
		'',
		`<!-- wp:list-item ${ LI_ATTRS } -->`,
		'<li class="fancy-item">Fancy item</li>',
		'<!-- /wp:list-item --></ul>',
		'<!-- /wp:list -->',
		'',
		'<!-- wp:paragraph -->',
		'<p>Plain closer paragraph.</p>',
		'<!-- /wp:paragraph -->',
	].join( '\n' );

	const id = await createPost( page, {
		title: 'Attr carry ' + Date.now(),
		content,
		status: 'draft',
	} );
	t.check( 'created fixture', !! id, String( id ) );

	await page.goto( BASE + '/minn-admin/editor/posts/' + id, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body', { timeout: 25000 } );
	await page.waitForTimeout( 1200 );

	const shape = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		return {
			islands: body.querySelectorAll( '.minn-block-island' ).length,
			pMarker: body.querySelector( ':scope > p[data-minn-attrs]' )?.dataset.minnAttrs || '',
			pStyle: body.querySelector( ':scope > p[data-minn-attrs]' )?.getAttribute( 'style' ) || '',
			hMarker: body.querySelector( ':scope > h3[data-minn-attrs]' )?.dataset.minnAttrs || '',
			listMarker: body.querySelector( ':scope > ul[data-minn-attrs]' )?.dataset.minnAttrs || '',
			liMarker: body.querySelector( ':scope > ul li[data-minn-attrs]' )?.dataset.minnAttrs || '',
			pEditable: !! body.querySelector( ':scope > p[data-minn-attrs]' )?.isContentEditable,
		};
	} );
	t.check( 'no islands — everything loads as prose', shape.islands === 0, JSON.stringify( shape ) );
	t.check( 'paragraph carries marker + keeps inline style', shape.pMarker === P_ATTRS && /cf2e2e/.test( shape.pStyle ), JSON.stringify( shape ) );
	t.check( 'heading + list + list-item carry markers', shape.hMarker === H_ATTRS && shape.listMarker === L_ATTRS && shape.liMarker === LI_ATTRS, JSON.stringify( shape ) );
	t.check( 'styled paragraph is editable', shape.pEditable, '' );

	const caretEnd = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	}, sel );
	const rawOf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).content.raw;
	}, id );
	// A flat wait races the save under load (rule-77 class) — poll the raw
	// until the expected substring lands, then return it.
	const save = async ( expect ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 900 );
			const raw = await rawOf();
			if ( ! expect || raw.includes( expect ) || ( expect instanceof RegExp && expect.test( raw ) ) ) return raw;
		}
		return rawOf();
	};

	// 1. Edit text in the styled paragraph, save, verify carried JSON intact.
	await page.click( '#minn-editor-body > p[data-minn-attrs]' );
	await caretEnd( '#minn-editor-body > p[data-minn-attrs]' );
	await page.keyboard.type( ' Edited.' );
	let raw = await save( 'Styled words here. Edited.' );
	t.check( 'edit saved with carried attrs byte-identical', raw.includes( `<!-- wp:paragraph ${ P_ATTRS } -->` ) && raw.includes( 'Styled words here. Edited.' ), raw.slice( 0, 160 ) );
	t.check( 'inline style survives the save', raw.includes( 'style="color:#cf2e2e"' ), '' );
	t.check( 'heading attrs byte-identical', raw.includes( `<!-- wp:heading ${ H_ATTRS } -->` ), '' );
	t.check( 'list + list-item attrs byte-identical', raw.includes( `<!-- wp:list ${ L_ATTRS } -->` ) && raw.includes( `<!-- wp:list-item ${ LI_ATTRS } -->` ), '' );
	t.check( 'no marker leaked to saved markup', ! raw.includes( 'data-minn-attrs' ), '' );

	// 2. Markdown block prefix stays literal on a marker paragraph.
	await page.evaluate( () => {
		const el = document.querySelector( '#minn-editor-body > p[data-minn-attrs]' );
		const r = document.createRange();
		r.setStart( el.firstChild, 0 );
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.type( '# ' );
	const afterMd = await page.evaluate( () => ( {
		stillP: !! document.querySelector( '#minn-editor-body > p[data-minn-attrs]' ),
		h1: !! document.querySelector( '#minn-editor-body > h1' ),
		text: document.querySelector( '#minn-editor-body > p[data-minn-attrs]' )?.textContent || '',
	} ) );
	t.check( 'markdown heading prefix refused on marker block', afterMd.stillP && ! afterMd.h1 && afterMd.text.startsWith( '# ' ), JSON.stringify( afterMd ) );
	await page.keyboard.press( 'Backspace' );
	await page.keyboard.press( 'Backspace' );

	// 3. Toolbar block conversion refused with a toast.
	await page.evaluate( () => {
		const b = document.querySelector( '.minn-tool[data-block="h2"], .minn-tool[data-block="blockquote"]' );
		b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	await page.waitForTimeout( 300 );
	const afterTool = await page.evaluate( () => ( {
		stillP: !! document.querySelector( '#minn-editor-body > p[data-minn-attrs]' ),
		toast: ( document.querySelector( '.minn-toast' )?.textContent || '' ),
	} ) );
	t.check( 'toolbar conversion refused on marker block', afterTool.stillP && /block editor/i.test( afterTool.toast ), JSON.stringify( afterTool ) );

	// 4. MID-split: Enter inside the styled text → both halves styled.
	await page.evaluate( () => {
		const el = document.querySelector( '#minn-editor-body > p[data-minn-attrs]' );
		const r = document.createRange();
		r.setStart( el.firstChild, 6 ); // "Styled| words here. Edited."
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 200 );
	const midSplit = await page.evaluate( () => {
		const ps = [ ...document.querySelectorAll( '#minn-editor-body > p[data-minn-attrs]' ) ];
		return { count: ps.length, texts: ps.map( ( p ) => p.textContent.slice( 0, 12 ) ) };
	} );
	t.check( 'mid-split duplicates the marker (Gutenberg semantics)', midSplit.count === 2, JSON.stringify( midSplit ) );
	raw = await save( '<p class="has-text-color has-large-font-size" style="color:#cf2e2e">Styled</p>' );
	t.check( 'both split halves saved styled', ( raw.match( /<!-- wp:paragraph \{"style":\{"color":\{"text":"#cf2e2e"\}\},"fontSize":"large"\} -->/g ) || [] ).length === 2, '' );

	// 5. END-split: Enter at the very end → default paragraph, not a clone.
	await page.evaluate( () => {
		const ps = document.querySelectorAll( '#minn-editor-body > p[data-minn-attrs]' );
		const el = ps[ ps.length - 1 ];
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
		el.focus?.();
	} );
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 300 ); // rAF normalization
	await page.keyboard.type( 'Continuation text.' );
	const endSplit = await page.evaluate( () => {
		const marked = document.querySelectorAll( '#minn-editor-body > p[data-minn-attrs]' ).length;
		const cont = [ ...document.querySelectorAll( '#minn-editor-body > p' ) ].find( ( p ) => p.textContent === 'Continuation text.' );
		return { marked, contPlain: !! cont && ! cont.dataset.minnAttrs && ! cont.getAttribute( 'class' ) && ! cont.getAttribute( 'style' ) };
	} );
	t.check( 'end-split yields a default paragraph', endSplit.marked === 2 && endSplit.contPlain, JSON.stringify( endSplit ) );

	// 6. MERGE: Backspace at start of the plain continuation merges it into
	// the styled block; the styled block's attrs win (Gutenberg semantics).
	await page.evaluate( () => {
		const cont = [ ...document.querySelectorAll( '#minn-editor-body > p' ) ].find( ( p ) => p.textContent === 'Continuation text.' );
		const r = document.createRange();
		r.setStart( cont.firstChild, 0 );
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Backspace' );
	const merged = await page.evaluate( () => {
		const ps = [ ...document.querySelectorAll( '#minn-editor-body > p[data-minn-attrs]' ) ];
		return { count: ps.length, lastText: ps[ ps.length - 1 ]?.textContent || '' };
	} );
	t.check( 'merge keeps the styled block, pulls text in', merged.count === 2 && /Continuation text\.$/.test( merged.lastText ), JSON.stringify( merged ) );

	// 7. Alignment merges into the carried JSON (Minn-editable attr).
	await page.click( '#minn-editor-body > p[data-minn-attrs]' );
	await page.evaluate( () => {
		const b = document.querySelector( '.minn-tool[data-align="center"]' );
		b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
	} );
	raw = await save( '"align":"center"' );
	t.check( 'alignment merged into carried JSON', /<!-- wp:paragraph \{"style":\{"color":\{"text":"#cf2e2e"\}\},"fontSize":"large","align":"center"\} -->/.test( raw ), '' );
	t.check( 'list survives the session byte-identical', raw.includes( `<!-- wp:list ${ L_ATTRS } -->` ) && raw.includes( `<!-- wp:list-item ${ LI_ATTRS } -->` ) && raw.includes( '<li class="fancy-item">Fancy item</li>' ), '' );

	// 8. li mid-split duplicates the item marker.
	await page.evaluate( () => {
		const li = document.querySelector( '#minn-editor-body > ul li[data-minn-attrs]' );
		const r = document.createRange();
		r.setStart( li.firstChild, 5 ); // "Fancy| item"
		r.collapse( true );
		const ws = window.getSelection();
		ws.removeAllRanges();
		ws.addRange( r );
	} );
	await page.keyboard.press( 'Enter' );
	raw = await save();
	for ( let i = 0; i < 10 && ( raw.match( /<!-- wp:list-item \{"className":"fancy-item"\} -->/g ) || [] ).length < 2; i++ ) {
		await page.waitForTimeout( 900 );
		raw = await rawOf();
	}
	t.check( 'li mid-split saves two styled list-items', ( raw.match( /<!-- wp:list-item \{"className":"fancy-item"\} -->/g ) || [] ).length === 2, '' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
