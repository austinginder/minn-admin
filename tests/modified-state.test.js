/**
 * The "Modified" state — a live post carrying unsaved edits (an autosave
 * newer than the saved copy) is named in the content list: an amber pencil
 * on the row (hover tip) and a quiet Modified toolbar filter backed by
 * ?minn_modified=1.
 *
 * Fixtures: two published posts; one gets a REST autosave so it enters the
 * state deterministically. Both deleted on the way out.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'modified-state' );

	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	let idA = null;
	let idB = null;
	try {
		idA = await createPost( page, { title: 'Modified state fixture A', content: '<p>v1</p>', status: 'publish' } );
		idB = await createPost( page, { title: 'Modified state fixture B', content: '<p>clean</p>', status: 'publish' } );
		t.check( 'fixture posts created', !! idA && !! idB, `${ idA } / ${ idB }` );

		// The state test is strictly-newer — never race the create second.
		await page.waitForTimeout( 1500 );
		const auto = await api( `wp/v2/posts/${ idA }/autosaves`, {
			method: 'POST',
			body: JSON.stringify( { content: '<p>v2 sitting unsaved</p>' } ),
		} );
		t.check( 'autosave created for A', auto.status === 201 || auto.status === 200, String( auto.status ) );

		const fields = await api( `wp/v2/posts?include=${ idA },${ idB }&context=edit&_fields=id,minn_modified` );
		const byId = {};
		( fields.body || [] ).forEach( ( p ) => { byId[ p.id ] = p.minn_modified; } );
		t.check( 'field true for the autosaved post, false for the clean one',
			byId[ idA ] === true && byId[ idB ] === false, JSON.stringify( byId ) );

		const filtered = await api( 'wp/v2/posts?minn_modified=1&per_page=100&context=edit&_fields=id' );
		const ids = ( filtered.body || [] ).map( ( p ) => p.id );
		t.check( 'minn_modified=1 filters to the modified post only',
			ids.includes( idA ) && ! ids.includes( idB ), JSON.stringify( ids ) );

		// The list: pencil flag on A's row, nothing on B's.
		await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-id="${ idA }"]`, { timeout: 20000 } );
		t.check( 'Modified mark on the autosaved row',
			!! ( await page.$( `.minn-table-row[data-id="${ idA }"] .minn-row-modified` ) ), '' );
		t.check( 'no mark on the clean row',
			! ( await page.$( `.minn-table-row[data-id="${ idB }"] .minn-status.modified, .minn-table-row[data-id="${ idB }"] .minn-row-modified` ) ), '' );

		const flag = page.locator( `.minn-table-row[data-id="${ idA }"] .minn-row-modified` );
		await flag.hover();
		await page.waitForSelector( '#minn-float-tip', { timeout: 5000 } );
		const tipText = await page.$eval( '#minn-float-tip', ( el ) => ( el.textContent || '' ).trim() );
		t.check( 'hover shows the unsaved-edits tip', /unsaved edits/i.test( tipText ), tipText );
		const tipHost = await page.$eval( '#minn-float-tip', ( el ) => ( el.parentElement && el.parentElement.className ) || '' );
		t.check( 'tip is mounted in the list scroller', /\bminn-scroll\b/.test( tipHost ), tipHost );
		const gapBefore = await page.evaluate( () => {
			const tip = document.getElementById( 'minn-float-tip' );
			const mark = document.querySelector( '.minn-row-modified' );
			return tip.getBoundingClientRect().top - mark.getBoundingClientRect().top;
		} );
		await page.evaluate( () => {
			const sc = document.querySelector( '.minn-scroll' );
			if ( sc ) sc.scrollTop += 80;
		} );
		const afterScroll = await page.evaluate( () => {
			const tip = document.getElementById( 'minn-float-tip' );
			const mark = document.querySelector( '.minn-row-modified' );
			if ( ! tip || ! mark ) return { gone: ! tip };
			return { gone: false, gap: tip.getBoundingClientRect().top - mark.getBoundingClientRect().top };
		} );
		t.check(
			'tip rides the list scroll with the icon',
			afterScroll.gone || Math.abs( afterScroll.gap - gapBefore ) < 2,
			JSON.stringify( afterScroll )
		);
		await page.mouse.move( 0, 0 );

		// The toolbar filter: only modified rows remain.
		await page.click( '#minn-content-modified' );
		await page.waitForFunction( ( b ) => {
			const active = document.querySelector( '#minn-content-modified.active' );
			const busy = document.querySelector( '#minn-view .minn-busy' );
			return active && ! busy && ! document.querySelector( `.minn-table-row[data-id="${ b }"]` );
		}, idB, { timeout: 20000 } );
		t.check( 'Modified filter keeps the modified post and drops the clean one',
			!! ( await page.$( `.minn-table-row[data-id="${ idA }"]` ) ), '' );
		const allMarked = await page.evaluate( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
			return rows.length > 0 && rows.every( ( r ) => !! r.querySelector( '.minn-row-modified' ) );
		} );
		t.check( 'every filtered row carries the mark', allMarked, '' );

		// Toggle off restores the full list.
		await page.click( '#minn-content-modified' );
		await page.waitForSelector( `.minn-table-row[data-id="${ idB }"]`, { timeout: 20000 } );
		t.check( 'filter off restores the clean post', true, '' );
	} finally {
		await deletePost( page, idA );
		await deletePost( page, idB );
	}

	await t.done( browser, errors );
} )();
