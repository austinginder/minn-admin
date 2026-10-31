/**
 * Stored and pasted markup stays inert in the editor whenever the editor
 * re-parses it.
 *
 * A <noscript>'s inside is parsed two ways: as markup by the inert parse
 * (scripting off) and as raw text that ends at the first "</noscript" by the
 * live page, so an attribute value carrying that string was harmless on load
 * and broke out into a live <img onerror> the moment the editor re-parsed its
 * own markup: every table op does, as does a "/" insert.
 *
 * Parse, serialize and parse again is not the same tree for other shapes
 * either. A nested form plus a MathML namespace flip
 * (form > math > mtext, </form>, form > mglyph > xmp|iframe|noembed) keeps
 * "</math><img onerror>" as raw text on the first parse, so nothing is there
 * to park; the serializer writes that text out raw, and the second parse
 * moves mglyph into MathML, closes math at "</math>" and builds a real img.
 * Each carrier is tried in a div, a table cell and a figure, through a table
 * op on stored content and through a plain-text block-markup paste.
 *
 * Verified against SAVED markup: the writer's noscript comes back with its
 * markup, and nothing an administrator saves parses into a live handler.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const PAYLOAD = '<noscript><span title="</noscript><img src=x onerror=window.__minnNoscript=1>"></span></noscript>';
// `data:,` fetches an empty body and fails to decode, so onerror fires off
// the parse with no network request.
const FLIP = ( carrier, probe ) => `<form><math><mtext></form><form><mglyph><${ carrier }></math><img src="data:," onerror="window.${ probe }=(window.${ probe }||0)+1"></${ carrier }></mglyph></form></mtext></math></form>`;
const CARRIERS = [ 'xmp', 'iframe', 'noembed', 'noframes' ];
const cellsRow = ( cells ) => '<tr>' + cells.map( ( c ) => `<td>${ c }</td>` ).join( '' ) + '</tr>';
const TABLE = '<!-- wp:table --><figure class="wp-block-table"><table><tbody>'
	+ cellsRow( [ 'a1' + PAYLOAD, 'a2' ] )
	+ CARRIERS.map( ( c ) => cellsRow( [ 'c-' + c + FLIP( c, '__minnFlipTd' ), 'x' ] ) ).join( '' )
	+ cellsRow( [ 'b1', 'b2' ] )
	+ '</tbody></table></figure><!-- /wp:table -->';
const ROWS = 2 + CARRIERS.length;

// What a single parse of saved markup builds: the front end parses it once.
const liveHandlers = ( page, html ) => page.evaluate( ( h ) => {
	const doc = new DOMParser().parseFromString( h, 'text/html' );
	return Array.from( doc.querySelectorAll( '*' ) ).filter( ( el ) => el.hasAttribute( 'onerror' ) && 'noscript' !== ( el.parentElement && el.parentElement.localName ) ).length;
}, html );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-noscript-inert' );
	await login( page );
	const ids = [];

	const rawOf = ( pid ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p + '?context=edit&_fields=content.raw&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, pid );
	const save = async ( pid ) => {
		const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && res.url().includes( 'wp/v2/posts/' + pid ), { timeout: 60000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		return rawOf( pid );
	};

	try {
		const id = await createPost( page, { title: 'Noscript inert probe', content: TABLE, status: 'draft' } );
		ids.push( id );
		const stored = await rawOf( id );
		t.check( 'the administrator\'s noscript is stored as written (premise)', stored.includes( PAYLOAD ), stored.slice( 0, 200 ) );
		t.check( 'the namespace-flip cells are stored as written (premise)', CARRIERS.every( ( c ) => stored.includes( FLIP( c, '__minnFlipTd' ) ) ), '' );
		t.check( 'a single parse of the stored markup builds no handler (premise)', 0 === await liveHandlers( page, stored ), '' );

		await openEditor( page, id );
		await page.waitForSelector( '#minn-editor-body table td', { timeout: 15000 } );
		t.check( 'nothing runs on load', await page.evaluate( () => undefined === window.__minnNoscript && undefined === window.__minnFlipTd ), '' );
		t.check( 'the live noscript holds no markup', await page.$eval( '#minn-editor-body noscript', ( n ) => '' === n.innerHTML ), await page.$eval( '#minn-editor-body noscript', ( n ) => n.innerHTML ) );
		t.check( 'every live raw-text carrier is empty', await page.evaluate( ( cs ) => cs.every( ( c ) => Array.from( document.querySelectorAll( '#minn-editor-body ' + c ) ).every( ( n ) => '' === n.innerHTML ) ), CARRIERS ), '' );

		/* ===== A table op re-parses the table's own markup ===== */
		await page.click( 'td >> text=b1', { button: 'right' } );
		await page.waitForSelector( '.minn-table-menu', { timeout: 5000 } );
		await page.click( '.minn-table-menu button[data-op="row-below"]' );
		await page.waitForTimeout( 1000 );
		t.check( 'nothing runs after a table op (noscript)', await page.evaluate( () => undefined === window.__minnNoscript ), '' );
		t.check( 'nothing runs after a table op (namespace flip)', await page.evaluate( () => undefined === window.__minnFlipTd ), String( await page.evaluate( () => window.__minnFlipTd ) ) );
		t.check( 'no live img handler in the table', await page.$$eval( '#minn-editor-body img', ( els ) => els.filter( ( el ) => el.hasAttribute( 'onerror' ) ).length ) === 0, '' );
		t.check( 'the row was added', await page.$$eval( '#minn-editor-body table tr', ( els ) => els.length ) === ROWS + 1, '' );

		/* ===== The save puts the writer's noscript back ===== */
		const saved = await save( id );
		// Chrome's serializer writes < and > inside attribute values as &lt; and
		// &gt; on every save, so the check is the parsed value, not the bytes.
		const kept = await page.evaluate( ( html ) => {
			const doc = new DOMParser().parseFromString( html, 'text/html' );
			const span = doc.querySelector( 'td > noscript > span' );
			return span ? span.getAttribute( 'title' ) : null;
		}, saved );
		t.check( 'saved markup keeps the noscript and its attribute', kept === '</noscript><img src=x onerror=window.__minnNoscript=1>', String( kept ) );
		t.check( 'no parking token reaches the database', ! /data-minn-inert-/.test( saved ), '' );
		t.check( 'the added row is saved', ( saved.match( /<tr>/g ) || [] ).length === ROWS + 1, saved.slice( 0, 400 ) );
		t.check( 'a single parse of the saved markup builds no handler', 0 === await liveHandlers( page, saved ), '' );

		/* ===== Plain-text block markup pasted in each context ===== */
		const contexts = {
			div: ( p ) => `<!-- wp:html -->\n<div>${ p }</div>\n<!-- /wp:html -->`,
			td: ( p ) => `<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>${ p }</td></tr></tbody></table></figure><!-- /wp:table -->`,
			figure: ( p ) => `<!-- wp:html -->\n<figure>${ p }</figure>\n<!-- /wp:html -->`,
		};
		const pid = await createPost( page, { title: 'Namespace flip paste probe', content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->', status: 'draft' } );
		ids.push( pid );
		await openEditor( page, pid );
		for ( const [ ctx, wrap ] of Object.entries( contexts ) ) {
			for ( const c of CARRIERS ) {
				const probe = `__minnFlipPaste_${ ctx }_${ c }`;
				await freshParagraph( page );
				await page.evaluate( ( markup ) => {
					const body = document.querySelector( '#minn-editor-body' );
					const dt = new DataTransfer();
					dt.setData( 'text/plain', markup );
					body.dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
				}, wrap( FLIP( c, probe ) ) );
				await page.waitForTimeout( 500 );
				t.check( `paste in a ${ ctx }: nothing runs (${ c })`, await page.evaluate( ( p ) => undefined === window[ p ], probe ), '' );
			}
		}
		t.check( 'no live img handler after the pastes', await page.$$eval( '#minn-editor-body img', ( els ) => els.filter( ( el ) => el.hasAttribute( 'onerror' ) ).length ) === 0, '' );
		const pasted = await save( pid );
		t.check( 'no parking token reaches the database (paste)', ! /data-minn-inert-/.test( pasted ), '' );
		t.check( 'a single parse of the saved paste builds no handler', 0 === await liveHandlers( page, pasted ), '' );
	} finally {
		for ( const id of ids ) await deletePost( page, id );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
