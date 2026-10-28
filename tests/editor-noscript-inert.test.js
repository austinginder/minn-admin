/**
 * A <noscript> in stored content stays inert in the editor. The inert parse
 * (scripting off) reads its inside as markup, the live page (scripting on)
 * reads it as raw text that ends at the first "</noscript", so an attribute
 * value carrying that string was harmless on load and broke out into a live
 * <img onerror> the moment the editor re-parsed its own markup: every table
 * op does, as does a "/" insert. Verified against SAVED markup: the writer's
 * noscript comes back with its markup.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PAYLOAD = '<noscript><span title="</noscript><img src=x onerror=window.__minnNoscript=1>"></span></noscript>';
const TABLE = '<!-- wp:table --><figure class="wp-block-table"><table><tbody>'
	+ '<tr><td>a1' + PAYLOAD + '</td><td>a2</td></tr>'
	+ '<tr><td>b1</td><td>b2</td></tr>'
	+ '</tbody></table></figure><!-- /wp:table -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-noscript-inert' );
	await login( page );

	const id = await createPost( page, { title: 'Noscript inert probe', content: TABLE, status: 'draft' } );
	const stored = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'the administrator\'s noscript is stored as written (premise)', stored.includes( PAYLOAD ), stored.slice( 0, 200 ) );

	await openEditor( page, id );
	await page.waitForSelector( '#minn-editor-body table td', { timeout: 15000 } );
	t.check( 'nothing runs on load', await page.evaluate( () => undefined === window.__minnNoscript ), '' );
	t.check( 'the live noscript holds no markup', await page.$eval( '#minn-editor-body noscript', ( n ) => '' === n.innerHTML ), await page.$eval( '#minn-editor-body noscript', ( n ) => n.innerHTML ) );

	/* ===== A table op re-parses the table's own markup ===== */
	await page.click( 'td >> text=b1', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu', { timeout: 5000 } );
	await page.click( '.minn-table-menu button[data-op="row-below"]' );
	await page.waitForTimeout( 800 );
	t.check( 'nothing runs after a table op', await page.evaluate( () => undefined === window.__minnNoscript ), '' );
	t.check( 'no live img from the noscript', await page.$$eval( '#minn-editor-body img', ( els ) => els.length ) === 0, '' );

	/* ===== The save puts the writer's noscript back ===== */
	const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && res.url().includes( 'wp/v2/posts/' + id ), { timeout: 60000 } );
	await page.keyboard.press( 'Meta+s' );
	await wait;
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	// Chrome's serializer writes < and > inside attribute values as &lt; and
	// &gt; on every save, so the check is the parsed value, not the bytes.
	const kept = await page.evaluate( ( html ) => {
		const doc = new DOMParser().parseFromString( html, 'text/html' );
		const span = doc.querySelector( 'td > noscript > span' );
		return span ? span.getAttribute( 'title' ) : null;
	}, saved );
	t.check( 'saved markup keeps the noscript and its attribute', kept === '</noscript><img src=x onerror=window.__minnNoscript=1>', String( kept ) );
	t.check( 'no parking token reaches the database', ! /data-minn-inert-/.test( saved ), '' );
	t.check( 'the added row is saved', ( saved.match( /<tr>/g ) || [] ).length === 3, saved.slice( 0, 400 ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
