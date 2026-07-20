/**
 * Terms manager (/minn-admin/terms): tree rendering for hierarchical
 * taxonomies, inline create/edit (name, slug, parent), delete, MERGE
 * (minn-admin/v1/terms/merge — posts move, source term deleted), search,
 * the taxonomy switcher, and capability gating. Everything verifies SAVED
 * state over REST, not just the DOM. Fixtures are suite-created and
 * suite-deleted; the standing demo terms (Projects tree) are untouched.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

( async () => {
	const t = reporter( 'terms' );
	const { browser, page, errors } = await launch();
	await login( page );
	await autoConfirm( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	const made = { cats: [], tags: [], posts: [] };
	const openTerms = async () => {
		await page.goto( BASE + '/minn-admin/terms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-terms-table', { timeout: 20000 } );
	};
	const rowFor = ( name ) => page.evaluate( ( n ) => {
		const row = [ ...document.querySelectorAll( '[data-term]' ) ]
			.find( ( r ) => r.querySelector( '.minn-row-title' ).textContent.includes( n ) );
		return row ? { id: parseInt( row.dataset.term, 10 ), padded: row.querySelector( '.minn-row-title' ).style.paddingLeft || '' } : null;
	}, name );
	const menuAction = async ( name, label ) => {
		await page.evaluate( ( n ) => {
			const row = [ ...document.querySelectorAll( '[data-term]' ) ]
				.find( ( r ) => r.querySelector( '.minn-row-title' ).textContent.includes( n ) );
			row.querySelector( '.minn-row-more' ).click();
		}, name );
		await page.waitForSelector( '.minn-row-menu, .minn-new-menu, [class*="minn-menu"]', { timeout: 5000 } ).catch( () => {} );
		await page.waitForTimeout( 150 );
		const clicked = await page.evaluate( ( l ) => {
			const btn = [ ...document.querySelectorAll( 'button' ) ]
				.find( ( b ) => ! b.closest( '#minn-terms-table' ) && b.textContent.trim().startsWith( l ) );
			if ( btn ) btn.click();
			return !! btn;
		}, label );
		return clicked;
	};

	try {
		/* ===== Route + tree render ===== */
		await openTerms();
		// Terms folded into the Structure page: admins get a "Structure" nav
		// item and a Terms tab (no standalone Terms item).
		t.check( 'Structure nav item present with a Terms tab',
			!! ( await page.$( '.minn-nav-btn[data-nav="posttypes"]' ) )
			&& ! ( await page.$( '.minn-nav-btn[data-nav="terms"]' ) )
			&& !! ( await page.$( '[data-structtab="terms"]' ) ) );
		const projects = await rowFor( 'Projects' );
		const child = await rowFor( 'Sailing' );
		t.check( 'hierarchical taxonomy renders as an indented tree', projects && child && ! projects.padded && /px/.test( child.padded ), JSON.stringify( { projects, child } ) );

		/* ===== Create via the UI ===== */
		await page.click( '#minn-add-term' );
		await page.waitForSelector( '.minn-term-edit' );
		await page.type( '.minn-term-edit [data-tf="name"]', 'Terms Suite Parent' );
		await page.click( '.minn-term-edit [data-tsave]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-term] .minn-row-title' ) ]
			.some( ( el ) => el.textContent.includes( 'Terms Suite Parent' ) ), null, { timeout: 15000 } );
		const created = await rowFor( 'Terms Suite Parent' );
		made.cats.push( created.id );
		t.check( 'Add category creates and re-renders', !! created, JSON.stringify( created ) );

		/* ===== Edit: rename + reparent under the new parent ===== */
		const kid = await rest( 'wp/v2/categories', { method: 'POST', body: { name: 'Terms Suite Kid' } } );
		made.cats.push( kid.body.id );
		await openTerms();
		await page.evaluate( ( n ) => {
			[ ...document.querySelectorAll( '[data-term]' ) ]
				.find( ( r ) => r.querySelector( '.minn-row-title' ).textContent.includes( n ) ).click();
		}, 'Terms Suite Kid' );
		await page.waitForSelector( '.minn-term-edit' );
		await page.evaluate( () => {
			const i = document.querySelector( '.minn-term-edit [data-tf="name"]' );
			i.focus();
			i.select();
		} );
		await page.keyboard.type( 'Terms Suite Kid Renamed' );
		// pick the new parent in the combobox
		await page.click( '.minn-term-edit [data-parentcombo] .minn-ac-input' );
		await page.evaluate( () => {
			const panel = document.querySelector( '.minn-term-edit [data-parentcombo] .minn-ac-panel' );
			const opt = [ ...panel.querySelectorAll( '.minn-ac-item' ) ].find( ( o ) => o.textContent.includes( 'Terms Suite Parent' ) );
			opt.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		} );
		await page.click( '.minn-term-edit [data-tsave]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-term] .minn-row-title' ) ]
			.some( ( el ) => el.textContent.includes( 'Terms Suite Kid Renamed' ) ), null, { timeout: 15000 } );
		const savedKid = await rest( `wp/v2/categories/${ kid.body.id }?context=edit` );
		t.check( 'rename + reparent persist over REST', savedKid.body.name === 'Terms Suite Kid Renamed' && savedKid.body.parent === created.id, JSON.stringify( { name: savedKid.body.name, parent: savedKid.body.parent } ) );
		const kidRow = await rowFor( 'Terms Suite Kid Renamed' );
		t.check( 'reparented term renders indented', kidRow && /px/.test( kidRow.padded ), JSON.stringify( kidRow ) );

		/* ===== Merge: posts move, source dies ===== */
		const src = await rest( 'wp/v2/tags', { method: 'POST', body: { name: 'Terms Suite Source' } } );
		const dst = await rest( 'wp/v2/tags', { method: 'POST', body: { name: 'Terms Suite Target' } } );
		made.tags.push( dst.body.id );
		const post = await rest( 'wp/v2/posts', { method: 'POST', body: { title: 'terms merge probe', status: 'publish', tags: [ src.body.id ] } } );
		made.posts.push( post.body.id );
		await openTerms();
		// switch to Tags
		await page.click( '[data-taxcombo] .minn-ac-input' );
		await page.evaluate( () => {
			const opt = [ ...document.querySelectorAll( '[data-taxcombo] .minn-ac-item' ) ].find( ( o ) => o.textContent.startsWith( 'Tags' ) );
			opt.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		} );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-term] .minn-row-title' ) ]
			.some( ( el ) => el.textContent.includes( 'Terms Suite Source' ) ), null, { timeout: 15000 } );
		t.check( 'taxonomy switcher lands on Tags', true );
		const openedMerge = await menuAction( 'Terms Suite Source', 'Merge into' );
		t.check( 'row menu offers Merge into…', openedMerge );
		await page.waitForSelector( '.minn-term-edit [data-mergecombo]' );
		await page.type( '.minn-term-edit [data-mergecombo] .minn-ac-input', 'Terms Suite Target' );
		await page.waitForSelector( '.minn-term-edit [data-mid]', { timeout: 10000 } );
		await page.evaluate( () => {
			document.querySelector( '.minn-term-edit [data-mid]' )
				.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		} );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.click( '.minn-term-edit [data-tmerge]' );
		await page.waitForFunction( () => document.body.textContent.includes( 'Merged into' ), null, { timeout: 15000 } );
		const postAfter = await rest( `wp/v2/posts/${ post.body.id }?context=edit&_fields=tags` );
		const srcAfter = await rest( `wp/v2/tags/${ src.body.id }` );
		t.check( 'merge moved the post to the target', postAfter.body.tags.includes( dst.body.id ) && ! postAfter.body.tags.includes( src.body.id ), JSON.stringify( postAfter.body.tags ) );
		t.check( 'merge deleted the source term', srcAfter.status === 404, String( srcAfter.status ) );

		/* ===== Search ===== */
		await page.type( '#minn-term-search', 'Suite Target' );
		await page.waitForFunction( () => {
			const rows = [ ...document.querySelectorAll( '[data-term] .minn-row-title' ) ];
			return rows.length >= 1 && rows.every( ( el ) => el.textContent.includes( 'Suite Target' ) );
		}, null, { timeout: 15000 } );
		t.check( 'search filters the list', true );

		/* ===== Delete via the UI ===== */
		page.once( 'dialog', ( d ) => d.accept() );
		const openedDelete = await menuAction( 'Terms Suite Target', 'Delete' );
		t.check( 'row menu offers Delete', openedDelete );
		await page.waitForFunction( () => document.body.textContent.includes( 'Deleted “Terms Suite Target”' ), null, { timeout: 15000 } );
		const dstAfter = await rest( `wp/v2/tags/${ dst.body.id }` );
		t.check( 'delete removes the term', dstAfter.status === 404, String( dstAfter.status ) );
		made.tags = made.tags.filter( ( id ) => id !== dst.body.id );

		/* ===== Author gating ===== */
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-author' );
		await p2.fill( '#user_pass', 'minn-author-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/terms', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && document.querySelector( '#minn-view' )?.textContent.length > 10, null, { timeout: 15000 } );
		const authorView = await p2.evaluate( () => ( {
			nav: !! document.querySelector( '.minn-nav-btn[data-nav="terms"]' ),
			blocked: document.querySelector( '#minn-view' ).textContent.includes( 'permission' ),
		} ) );
		t.check( 'authors see no Terms nav and a permission message', ! authorView.nav && authorView.blocked, JSON.stringify( authorView ) );
		await ctx2.close();
	} finally {
		for ( const id of made.posts ) await rest( `wp/v2/posts/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		for ( const id of made.tags ) await rest( `wp/v2/tags/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		for ( const id of made.cats.reverse() ) await rest( `wp/v2/categories/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
