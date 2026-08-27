/**
 * Adjacent headings: Backspace/Delete at the boundary must not let Chrome
 * glue them together with a sized span, and an empty heading leftover after
 * deleting the title must actually go away (GH #55).
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const ISSUE_55 = [
	'# Crystalline Soma Healing  ',
	'## Awaken Inner Wisdom',
	'',
	'Crystalline Soma Healing is a gentle energy-healing practice that uses crystals.',
	'',
	'> “Your inner wisdom is still there.”',
	'',
	'## WordPress excerpt',
	'',
	'Explore Crystalline Soma Healing.',
].join( '\n' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'heading-merge' );
	await login( page );

	const paste = ( flavors ) => page.evaluate( ( f ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const dt = new DataTransfer();
		for ( const [ k, v ] of Object.entries( f ) ) dt.setData( k, v );
		const ev = new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } );
		body.dispatchEvent( ev );
		return ev.defaultPrevented;
	}, flavors );

	const blocks = () => page.evaluate( () => Array.from( document.querySelector( '#minn-editor-body' ).children ).map( ( el ) => ( {
		tag: el.tagName,
		text: el.textContent.replace( /\u00a0/g, ' ' ).trim(),
		html: el.innerHTML,
		hasStyleSpan: !! el.querySelector( 'span[style]' ),
		nestedHeading: !! el.querySelector( 'h1, h2, h3, h4, h5, h6' ),
	} ) ) );

	const caretIn = ( find ) => page.evaluate( ( fn ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const el = Array.from( body.children ).find( ( n ) => fn.tag === n.tagName && ( ! fn.text || n.textContent.indexOf( fn.text ) !== -1 ) );
		if ( ! el ) return false;
		const r = document.createRange();
		r.selectNodeContents( el );
		if ( fn.end ) r.collapse( false );
		else r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		body.focus();
		return true;
	}, find );

	const ids = [];
	const fresh = async ( content ) => {
		const id = await createPost( page, { title: 'Heading merge', content } );
		ids.push( id );
		await openEditor( page, id );
		return id;
	};
	const savedRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async ( id ) => {
		const before = await savedRaw( id );
		await page.keyboard.press( 'Meta+s' );
		const start = Date.now();
		let raw = await savedRaw( id );
		while ( raw === before && Date.now() - start < 12000 ) {
			await page.waitForTimeout( 400 );
			raw = await savedRaw( id );
		}
		return raw;
	};

	/* ===== Issue #55 paste then Backspace at H2 start ===== */
	const pasteId = await fresh( '<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->' );
	await freshParagraph( page );
	await paste( { 'text/plain': ISSUE_55 } );
	const modal = page.locator( '.minn-confirm-modal' );
	await modal.waitFor();
	await modal.getByRole( 'button', { name: 'Format Markdown' } ).click();
	await page.waitForTimeout( 400 );
	let b = await blocks();
	t.check( 'paste formats an H1 then H2',
		b.some( ( x ) => x.tag === 'H1' && /Crystalline Soma Healing/.test( x.text ) )
		&& b.some( ( x ) => x.tag === 'H2' && /Awaken Inner Wisdom/.test( x.text ) ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );

	t.check( 'caret lands at start of H2', await caretIn( { tag: 'H2', text: 'Awaken' } ) );
	await page.keyboard.press( 'Backspace' );
	await page.waitForTimeout( 200 );
	b = await blocks();
	const merged = b.find( ( x ) => x.tag === 'H1' && /Healing/.test( x.text ) && /Awaken/.test( x.text ) );
	t.check( 'Backspace at H2 start joins both titles into the H1', !! merged, JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );
	t.check( 'joined heading has no Chrome style-span leftover', !!( merged && ! merged.hasStyleSpan && ! merged.nestedHeading ), merged && merged.html );
	t.check( 'the H2 is gone after the join', ! b.some( ( x ) => x.tag === 'H2' && /Awaken/.test( x.text ) ), JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );

	await page.keyboard.press( 'Meta+z' );
	await page.waitForTimeout( 300 );
	b = await blocks();
	t.check( '⌘Z undoes the join back to H1 + H2',
		b.some( ( x ) => x.tag === 'H1' && x.text === 'Crystalline Soma Healing' )
		&& b.some( ( x ) => x.tag === 'H2' && x.text === 'Awaken Inner Wisdom' ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );

	/* ===== Empty heading leftover actually deletes ===== */
	t.check( 'caret in H1 for title delete', await caretIn( { tag: 'H1', text: 'Crystalline Soma Healing' } ) );
	await page.evaluate( () => {
		const h1 = Array.from( document.querySelector( '#minn-editor-body' ).children )
			.find( ( n ) => n.tagName === 'H1' && /Crystalline Soma Healing/.test( n.textContent ) );
		const r = document.createRange();
		r.selectNodeContents( h1 );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	await page.keyboard.press( 'Backspace' );
	await page.waitForTimeout( 150 );
	b = await blocks();
	t.check( 'deleting the H1 title leaves an empty heading husk',
		b.some( ( x ) => x.tag === 'H1' && ! x.text ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );
	await page.keyboard.press( 'Backspace' );
	await page.waitForTimeout( 200 );
	b = await blocks();
	t.check( 'Backspace on the empty H1 removes it',
		! b.some( ( x ) => x.tag === 'H1' ) && b.some( ( x ) => x.tag === 'H2' && /Awaken/.test( x.text ) ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );

	/* ===== Delete at end of H1 merges the following H2 the same way ===== */
	const delId = await fresh(
		'<!-- wp:heading {"level":1} -->\n<h1 class="wp-block-heading">Alpha</h1>\n<!-- /wp:heading -->\n\n'
		+ '<!-- wp:heading {"level":2} -->\n<h2 class="wp-block-heading">Beta</h2>\n<!-- /wp:heading -->\n\n'
		+ '<!-- wp:paragraph -->\n<p>Gamma</p>\n<!-- /wp:paragraph -->'
	);
	t.check( 'caret at end of H1', await caretIn( { tag: 'H1', text: 'Alpha', end: true } ) );
	await page.keyboard.press( 'Delete' );
	await page.waitForTimeout( 200 );
	b = await blocks();
	const delMerged = b.find( ( x ) => x.tag === 'H1' && x.text === 'AlphaBeta' );
	t.check( 'Delete at end of H1 joins H2 as plain text',
		!! delMerged && ! delMerged.hasStyleSpan && ! b.some( ( x ) => x.tag === 'H2' ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text + ':' + x.html ) ) );
	t.check( 'the paragraph after the headings is untouched',
		b.some( ( x ) => x.tag === 'P' && x.text === 'Gamma' ),
		JSON.stringify( b.map( ( x ) => x.tag + ':' + x.text ) ) );

	const raw = await save( delId );
	t.check( 'saved markup is one heading, no span style',
		/<!-- wp:heading \{"level":1\} -->/.test( raw )
		&& /<h1[^>]*>AlphaBeta<\/h1>/.test( raw )
		&& ! /<span/.test( raw )
		&& ! /<!-- wp:heading \{"level":2\} -->/.test( raw )
		&& /<p>Gamma<\/p>/.test( raw ),
		raw );

	await Promise.all( ids.map( ( id ) => deletePost( page, id ) ) );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
