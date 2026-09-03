/**
 * Pasted block markup arrives INERT.
 *
 * islandHtml() and editableSegmentHtml() hand back a block's stored markup
 * verbatim — they have to, because the serializers read it back out of the DOM
 * at save and rewriting it on the way in would change bytes the writer never
 * touched. Parking is therefore the caller's job, and every caller that
 * reaches a live element obeys that. pasteBlocksInsert did not: it fed the
 * payload straight to execCommand('insertHTML'), so an event-handler attribute
 * in pasted markup fired in the pasting user's session with the REST nonce in
 * scope.
 *
 * Both clipboard branches are covered: text/plain that merely begins with
 * `<!-- wp:` (the "paste this block from our tutorial" case) and the
 * text/x-minn-blocks flavor Minn's own copy handler writes.
 *
 * The round-trip half matters as much as the parking half: parking is only
 * acceptable because rtUnpark restores the writer's bytes on the serializer's
 * clone, so each case also asserts the SAVED markup still carries the original
 * attribute.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const PAYLOADS = [
	{
		label: 'text/plain beginning with <!-- wp:',
		flavors: ( markup ) => ( { 'text/plain': markup } ),
		probe: '__minnPastePlain',
	},
	{
		label: 'text/x-minn-blocks',
		flavors: ( markup ) => ( { 'text/x-minn-blocks': markup, 'text/plain': 'fallback' } ),
		probe: '__minnPasteFlavor',
	},
];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'paste-park' );
	await login( page );

	const paste = ( flavors ) => page.evaluate( ( f ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const dt = new DataTransfer();
		for ( const [ k, v ] of Object.entries( f ) ) dt.setData( k, v );
		body.dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
	}, flavors );

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

	const ids = [];

	for ( const p of PAYLOADS ) {
		// `data:,` is a well-formed URL carrying an empty body, so the fetch
		// succeeds and the DECODE fails — which fires onerror synchronously
		// off the parse, with no network request and nothing for the console
		// error gate to see. A relative src is no good here: the SPA route is
		// a catch-all rewrite, so it answers 200 with HTML and the error can
		// arrive late enough to read as a pass on unfixed code.
		const markup = `<!-- wp:html -->\n<img src="data:," onerror="window.${ p.probe }=1">\n<!-- /wp:html -->`;

		const id = await createPost( page, {
			title: `Paste park: ${ p.label }`,
			content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->',
		} );
		ids.push( id );
		await openEditor( page, id );
		await freshParagraph( page );
		await paste( p.flavors( markup ) );
		await page.waitForTimeout( 800 );

		const state = await page.evaluate( ( probe ) => {
			const img = document.querySelector( '#minn-editor-body img' );
			return {
				fired: !! window[ probe ],
				present: !! img,
				parked: img ? img.hasAttribute( 'data-minn-inert-onerror' ) : false,
				live: img ? img.hasAttribute( 'onerror' ) : false,
			};
		}, p.probe );

		t.check( `${ p.label }: the handler never fires`, ! state.fired, JSON.stringify( state ) );
		t.check( `${ p.label }: the image is still inserted`, state.present, JSON.stringify( state ) );
		t.check( `${ p.label }: onerror is parked, not live`,
			state.parked && ! state.live, JSON.stringify( state ) );

		const raw = await save( id );
		t.check( `${ p.label }: saved markup restores the writer's bytes`,
			/onerror="window\./.test( raw ) && ! /data-minn-inert-/.test( raw ), raw.slice( 0, 300 ) );
	}

	for ( const id of ids ) await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
