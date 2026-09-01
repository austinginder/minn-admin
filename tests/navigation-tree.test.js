/**
 * Navigation tree editor (/minn-admin/navigation/{id}): the block-theme menu
 * arranged in Minn instead of the Site Editor.
 *
 * A wp_navigation post is ONE serialized block document, so the load-bearing
 * property is that nodes Minn did not edit come back byte-identical: a
 * submenu's className, a block Minn has no idea about, and WP's own
 * hooked-block metadata all have to survive a reorder. Every check reads the
 * SAVED markup back, never just the DOM.
 *
 * Activates twentytwentyfive for the run and restores the previously active
 * theme in finally, so it runs on the dev site and the bare next-core site
 * alike.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'navigation-tree' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	// Fixture markup: a plain link carrying hooked-block metadata, a submenu
	// with a className and a child, an opaque core block, and a block that is
	// not registered at all. Everything Minn must not damage, in one document.
	const ALPHA = '<!-- wp:navigation-link {"label":"Alpha","url":"https://example.com/a","kind":"custom","metadata":{"ignoredHookedBlocks":["core/site-logo"]}} /-->';
	const CHILD = '<!-- wp:navigation-link {"label":"Child","url":"https://example.com/c"} /-->';
	const BETA_OPEN = '<!-- wp:navigation-submenu {"label":"Beta","url":"https://example.com/b","kind":"custom","className":"is-fancy"} -->';
	const PAGELIST = '<!-- wp:page-list /-->';
	const UNKNOWN = '<!-- wp:acme/unknown-thing {"weird":true} /-->';
	const FIXTURE = [ ALPHA, BETA_OPEN + '\n' + CHILD + '\n<!-- /wp:navigation-submenu -->', PAGELIST, UNKNOWN ].join( '\n\n' );

	let prevTheme = '';
	let id = 0;
	const made = [];

	const openTree = async ( navId ) => {
		await page.goto( BASE + '/minn-admin/navigation/' + navId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-navtoken], .minn-empty', { timeout: 20000 } );
		await page.waitForTimeout( 250 );
	};
	const rows = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-navtoken]' ) ].map( ( r ) => ( {
		token: r.dataset.navtoken,
		title: r.querySelector( '.minn-row-title' ).textContent.trim(),
		kind: r.querySelector( '.minn-menu-kind' ).textContent.trim(),
		depth: parseInt( r.style.paddingLeft, 10 ),
	} ) ) );
	const saved = async () => ( await rest( `wp/v2/navigation/${ id }?context=edit&_fields=content` ) ).body.content.raw;
	// Wait on the save request, never on toast text: consecutive actions each
	// toast, and a lingering toast satisfies a text wait before the next save.
	const act = async ( fn ) => {
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( `/wp/v2/navigation/${ id }` ) && r.request().method() === 'POST', { timeout: 20000 } ),
			fn(),
		] );
		await page.waitForTimeout( 300 );
	};
	const makeNav = async ( title, content ) => {
		const r = await rest( 'wp/v2/navigation', { method: 'POST', body: { title, status: 'publish', content } } );
		made.push( r.body.id );
		return r.body.id;
	};

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;
		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		id = await makeNav( 'Tree suite ' + Date.now(), FIXTURE );
		await openTree( id );

		/* ===== The tree reads the document ===== */
		const first = await rows();
		t.check( 'every top-level block gets a row, nesting included',
			first.length === 5 && first.map( ( r ) => r.token ).join( ',' ) === '0,1,1.0,2,3', JSON.stringify( first.map( ( r ) => r.token ) ) );
		t.check( 'the nested child is indented', first[ 2 ].depth > first[ 1 ].depth, `${ first[ 1 ].depth } → ${ first[ 2 ].depth }` );
		t.check( 'a core block Minn does not edit is still listed and named',
			first[ 3 ].title === 'All pages' && first[ 3 ].kind === 'Block', JSON.stringify( first[ 3 ] ) );
		t.check( 'an unregistered block is listed rather than dropped',
			/unknown/i.test( first[ 4 ].title ), JSON.stringify( first[ 4 ] ) );

		/* ===== Reorder keeps untouched nodes byte-identical ===== */
		await act( () => page.click( '[data-navtoken="0"] [data-navmove="down"]' ) );
		const afterMove = await saved();
		t.check( 'move down reorders the document',
			afterMove.indexOf( BETA_OPEN ) < afterMove.indexOf( ALPHA ), afterMove.slice( 0, 80 ) );
		t.check( 'hooked-block metadata survives verbatim', afterMove.includes( ALPHA ) );
		t.check( 'the submenu keeps its className verbatim', afterMove.includes( BETA_OPEN ) );
		t.check( 'the unregistered block survives verbatim', afterMove.includes( UNKNOWN ) );
		t.check( 'nothing was invented or lost',
			afterMove.split( '<!-- wp:' ).length === FIXTURE.split( '<!-- wp:' ).length, `${ afterMove.split( '<!-- wp:' ).length } blocks` );

		/* ===== Edit a label and URL, leaving other attributes alone ===== */
		await page.click( '[data-navtoken="1"] .minn-menu-info' );
		await page.waitForSelector( '#minn-navi-label', { timeout: 5000 } );
		await page.fill( '#minn-navi-label', 'Alpha renamed' );
		await page.fill( '#minn-navi-url', 'https://example.com/renamed' );
		await act( () => page.click( '[data-navsave]' ) );
		const afterEdit = await saved();
		t.check( 'the label and URL are saved',
			afterEdit.includes( '"label":"Alpha renamed"' ) && afterEdit.includes( '"url":"https://example.com/renamed"' ) );
		t.check( 'editing one attribute keeps the rest of that block',
			afterEdit.includes( 'ignoredHookedBlocks' ) && afterEdit.includes( '"kind":"custom"' ), afterEdit.slice( 0, 200 ) );

		/* ===== Opaque blocks refuse inline editing ===== */
		const opaqueToken = ( await rows() ).find( ( r ) => r.title === 'All pages' ).token;
		await page.click( `[data-navtoken="${ opaqueToken }"] .minn-menu-info` );
		await page.waitForTimeout( 400 );
		t.check( 'a block Minn does not own opens no editor',
			! ( await page.$( '#minn-navi-label' ) ) );

		/* ===== Indent converts a link into a submenu ===== */
		const linkId = await makeNav( 'Indent suite ' + Date.now(), [
			'<!-- wp:navigation-link {"label":"One","url":"https://example.com/1","kind":"custom"} /-->',
			'<!-- wp:navigation-link {"label":"Two","url":"https://example.com/2","kind":"custom"} /-->',
		].join( '\n\n' ) );
		const outer = id;
		id = linkId;
		await openTree( id );
		await act( () => page.click( '[data-navtoken="1"] [data-navmove="in"]' ) );
		const nested = await saved();
		t.check( 'nesting turns the item above into a submenu',
			nested.includes( '<!-- wp:navigation-submenu' ) && nested.includes( '<!-- /wp:navigation-submenu -->' ), nested );
		t.check( 'the promoted item keeps its own attributes',
			nested.includes( '"label":"One"' ) && nested.includes( '"url":"https://example.com/1"' ) );
		t.check( 'the nested item is inside the submenu',
			nested.indexOf( '"label":"Two"' ) > nested.indexOf( '<!-- wp:navigation-submenu' )
			&& nested.indexOf( '"label":"Two"' ) < nested.indexOf( '<!-- /wp:navigation-submenu -->' ) );
		const nestedRows = await rows();
		t.check( 'the tree shows it nested', nestedRows.length === 2 && nestedRows[ 1 ].token === '0.0', JSON.stringify( nestedRows.map( ( r ) => r.token ) ) );

		/* ===== Outdent empties the submenu, which becomes a link again ===== */
		await act( () => page.click( '[data-navtoken="0.0"] [data-navmove="out"]' ) );
		const flat = await saved();
		t.check( 'outdenting the last child collapses the empty submenu back to a link',
			! flat.includes( 'navigation-submenu' ) && flat.includes( '"label":"One"' ) && flat.includes( '"label":"Two"' ), flat );

		/* ===== Add ===== */
		await page.fill( '#minn-nav-link-label', 'Added link' );
		await page.fill( '#minn-nav-link-url', 'https://example.com/added' );
		await act( () => page.click( '#minn-nav-add-link' ) );
		const withLink = await saved();
		t.check( 'a custom link is added with the right shape',
			withLink.includes( '"label":"Added link"' ) && withLink.includes( '"kind":"custom"' ) );

		/* ===== Delete, then Undo puts it back where it was ===== */
		const before = ( await rows() ).length;
		await act( () => page.click( '[data-navtoken="0"] [data-navdel]' ) );
		t.check( 'delete removes the row', ( await rows() ).length === before - 1 );
		t.check( 'delete is offered back', !! ( await page.$( '.minn-toast button, [data-toast-action]' ) ) );
		await act( () => page.evaluate( () => {
			const b = document.querySelector( '.minn-toast button, [data-toast-action]' );
			if ( b ) b.click();
		} ) );
		const restored = await saved();
		t.check( 'undo restores the removed item at its old position',
			restored.includes( '"label":"One"' ) && restored.indexOf( '"label":"One"' ) < restored.indexOf( '"label":"Two"' ), restored );

		/* ===== Drag ===== */
		const dragRows = await page.$$( '[data-navtoken]' );
		const grip = await dragRows[ 0 ].$( '.minn-menu-grip' );
		const lastBox = await dragRows[ dragRows.length - 1 ].boundingBox();
		const gripBox = await grip.boundingBox();
		const titlesBefore = ( await rows() ).map( ( r ) => r.title );
		await page.mouse.move( gripBox.x + gripBox.width / 2, gripBox.y + gripBox.height / 2 );
		await page.mouse.down();
		await page.mouse.move( lastBox.x + 60, lastBox.y + lastBox.height * 0.8, { steps: 8 } );
		await page.mouse.move( lastBox.x + 60, lastBox.y + lastBox.height * 0.8 + 1 );
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( `/wp/v2/navigation/${ id }` ) && r.request().method() === 'POST', { timeout: 20000 } ),
			page.mouse.up(),
		] );
		await page.waitForTimeout( 400 );
		const titlesAfter = ( await rows() ).map( ( r ) => r.title );
		t.check( 'dragging a row past the last one moves it there',
			titlesAfter[ titlesAfter.length - 1 ] === titlesBefore[ 0 ] && titlesAfter[ 0 ] === titlesBefore[ 1 ],
			JSON.stringify( titlesAfter ) );

		/* ===== Markup Minn will not risk rewriting stays read-only ===== */
		id = await makeNav( 'Locked suite ' + Date.now(),
			'<p>loose html the tokenizer will not vouch for</p>\n\n<!-- wp:navigation-link {"label":"X","url":"https://example.com/x"} /-->' );
		await openTree( id );
		const lockedView = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '[data-navtoken]' ).length,
			text: document.querySelector( '#minn-view' ).textContent,
		} ) );
		t.check( 'unparseable markup is shown read-only rather than edited',
			lockedView.rows === 0 && /read-only/i.test( lockedView.text ) );
		t.check( 'read-only mode still offers the Site Editor',
			!! ( await page.$( 'a[href*="site-editor"]' ) ) );
		id = outer;
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const n of made ) await rest( `wp/v2/navigation/${ n }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
