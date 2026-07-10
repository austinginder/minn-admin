/**
 * Duplicate row action — the content row menu's Duplicate creates a new
 * draft owned by the current user with content, excerpt and terms intact.
 */
const { launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'duplicate' );

	await login( page );

	const title = 'Dup test ' + Date.now();
	const content = '<!-- wp:paragraph -->\n<p>Original body for duplication.</p>\n<!-- /wp:paragraph -->';
	const srcId = await createPost( page, { title, content, status: 'publish' } );
	let copyId = 0;
	try {
		// A tag proves taxonomy terms ride along.
		const tagId = await page.evaluate( async ( pid ) => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			const tag = await ( await fetch( window.MINN.restUrl + 'wp/v2/tags', {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { name: 'dup-tag-' + pid } ),
			} ) ).json();
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { tags: [ tag.id ], excerpt: 'Dup excerpt' } ),
			} );
			return tag.id;
		}, srcId );

		await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-id="${ srcId }"]`, { timeout: 15000 } );
		await page.click( `.minn-table-row[data-id="${ srcId }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-row-menu', { timeout: 5000 } );
		// Right-click's mousedown+contextmenu can re-open the menu and detach
		// the first node — evaluate-click the item (tests/README convention).
		await page.evaluate( () => {
			const b = Array.from( document.querySelectorAll( '.minn-row-menu [data-ract="duplicate"]' ) ).pop();
			b.click();
		} );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Duplicated as draft/.test( x.textContent ) ),
			null, { timeout: 15000 }
		);
		t.check( 'Duplicate toast shown', true );

		const copy = await page.evaluate( async ( args ) => {
			const h = { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' };
			const list = await ( await fetch( window.MINN.restUrl + `wp/v2/posts?status=draft&search=${ encodeURIComponent( args.title ) }&context=edit`, h ) ).json();
			return list.find( ( p ) => p.id !== args.srcId ) || null;
		}, { title, srcId } );
		copyId = copy ? copy.id : 0;
		t.check( 'Copy exists as a draft', !! copy && copy.status === 'draft' );
		t.check( 'Copy keeps the title', !! copy && copy.title.raw === title );
		t.check( 'Copy keeps the content', !! copy && copy.content.raw === content, copy && copy.content.raw );
		t.check( 'Copy keeps the excerpt', !! copy && copy.excerpt.raw === 'Dup excerpt' );
		t.check( 'Copy keeps taxonomy terms', !! copy && ( copy.tags || [] ).includes( tagId ), copy && JSON.stringify( copy.tags ) );

		// The list refreshed and shows the new draft row.
		const rowShown = await page.evaluate( ( id ) => !! document.querySelector( `.minn-table-row[data-id="${ id }"]` ), copyId );
		t.check( 'Content list shows the new draft', rowShown );
	} finally {
		await deletePost( page, srcId ).catch( () => {} );
		if ( copyId ) await deletePost( page, copyId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
