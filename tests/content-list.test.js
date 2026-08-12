/**
 * Content list: date labels include a year when the date is not this
 * calendar year (GH #13), and Title / Date headers sort server-side
 * (GH #14). Default stays newest first.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'content-list' );
	await login( page );

	const stamp = Date.now();
	const ids = [];

	const localIso = ( d ) => {
		const y = d.getFullYear();
		const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		const day = String( d.getDate() ).padStart( 2, '0' );
		return `${ y }-${ m }-${ day }T12:00:00`;
	};

	const waitTable = async () => {
		await page.waitForFunction( () => ! document.querySelector( '.minn-table.minn-busy' ), { timeout: 15000 } );
		await page.waitForTimeout( 250 );
	};

	const searchFor = async ( q ) => {
		await page.fill( '#minn-content-search', q );
		await page.waitForTimeout( 500 );
		await waitTable();
	};

	const rowDate = ( id ) => page.$eval(
		`.minn-table-row[data-id="${ id }"] .minn-row-date`,
		( el ) => ( el.textContent || '' ).trim()
	);

	const rowOrder = ( want ) => page.evaluate( ( idsWanted ) =>
		[ ...document.querySelectorAll( '.minn-table-row[data-id]' ) ]
			.map( ( el ) => parseInt( el.dataset.id, 10 ) )
			.filter( ( id ) => idsWanted.includes( id ) ), want );

	const now = new Date();
	const lastYear = new Date( now.getFullYear() - 1, 2, 4, 12 ); // Mar 4 last year
	const twentyDaysAgo = new Date( now.getFullYear(), now.getMonth(), now.getDate() - 20, 12 );
	const sameYearOld = twentyDaysAgo.getFullYear() === now.getFullYear();

	try {
		const lastYearId = await createPost( page, {
			title: `DateYear old ${ stamp }`,
			content: '<!-- wp:paragraph --><p>Last year.</p><!-- /wp:paragraph -->',
			status: 'publish',
			date: localIso( lastYear ),
		} );
		ids.push( lastYearId );

		let sameYearId = 0;
		if ( sameYearOld ) {
			sameYearId = await createPost( page, {
				title: `DateYear now ${ stamp }`,
				content: '<!-- wp:paragraph --><p>This year.</p><!-- /wp:paragraph -->',
				status: 'publish',
				date: localIso( twentyDaysAgo ),
			} );
			ids.push( sameYearId );
		}

		const alphaId = await createPost( page, {
			title: `ContentSort ${ stamp } Alpha`,
			content: '<!-- wp:paragraph --><p>Older alpha.</p><!-- /wp:paragraph -->',
			status: 'publish',
			date: localIso( new Date( now.getFullYear(), now.getMonth(), now.getDate() - 1, 12 ) ),
		} );
		const zebraId = await createPost( page, {
			title: `ContentSort ${ stamp } Zebra`,
			content: '<!-- wp:paragraph --><p>Newer zebra.</p><!-- /wp:paragraph -->',
			status: 'publish',
			date: localIso( new Date( now.getFullYear(), now.getMonth(), now.getDate(), 15 ) ),
		} );
		ids.push( alphaId, zebraId );
		t.check( 'fixtures created', ids.length >= 3, String( ids ) );

		await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-content-search', { timeout: 15000 } );
		await page.waitForSelector( '.minn-table-row[data-id], .minn-empty', { timeout: 15000 } );

		/* ===== #13 year on other-calendar-year dates ===== */
		await searchFor( `DateYear old ${ stamp }` );
		await page.waitForSelector( `.minn-table-row[data-id="${ lastYearId }"] .minn-row-date`, { timeout: 15000 } );
		const oldText = await rowDate( lastYearId );
		t.check( 'other-year date shows the full year', oldText.includes( String( lastYear.getFullYear() ) ), oldText );

		if ( sameYearId ) {
			await searchFor( `DateYear now ${ stamp }` );
			await page.waitForSelector( `.minn-table-row[data-id="${ sameYearId }"] .minn-row-date`, { timeout: 15000 } );
			const nowText = await rowDate( sameYearId );
			t.check( 'current-year date omits the year', ! nowText.includes( String( now.getFullYear() ) ), nowText );
		}

		/* ===== #14 Title / Date sort ===== */
		await searchFor( `ContentSort ${ stamp }` );
		await page.waitForSelector( `.minn-table-row[data-id="${ zebraId }"]`, { timeout: 15000 } );
		t.check( 'Date header is active by default', await page.$eval( '[data-csort="date"]', ( el ) => el.classList.contains( 'is-active' ) ), '' );
		t.check( 'Title header is not active by default', await page.$eval( '[data-csort="title"]', ( el ) => ! el.classList.contains( 'is-active' ) ), '' );

		const defaultOrder = await rowOrder( [ alphaId, zebraId ] );
		t.check( 'default order is newest first', defaultOrder[ 0 ] === zebraId && defaultOrder[ 1 ] === alphaId, JSON.stringify( defaultOrder ) );

		await page.click( '[data-csort="title"]' );
		await waitTable();
		t.check( 'Title header becomes active', await page.$eval( '[data-csort="title"]', ( el ) => el.classList.contains( 'is-active' ) ), '' );
		const titleAsc = await rowOrder( [ alphaId, zebraId ] );
		t.check( 'Title first click is A to Z', titleAsc[ 0 ] === alphaId && titleAsc[ 1 ] === zebraId, JSON.stringify( titleAsc ) );

		await page.click( '[data-csort="title"]' );
		await waitTable();
		const titleDesc = await rowOrder( [ alphaId, zebraId ] );
		t.check( 'Title second click flips to Z to A', titleDesc[ 0 ] === zebraId && titleDesc[ 1 ] === alphaId, JSON.stringify( titleDesc ) );

		await page.click( '[data-csort="date"]' );
		await waitTable();
		t.check( 'switching to Date uses newest-first', await page.$eval( '[data-csort="date"]', ( el ) => el.classList.contains( 'is-active' ) ), '' );
		const dateDesc = await rowOrder( [ alphaId, zebraId ] );
		t.check( 'Date from Title is newest first again', dateDesc[ 0 ] === zebraId && dateDesc[ 1 ] === alphaId, JSON.stringify( dateDesc ) );
	} finally {
		for ( const id of ids ) {
			await deletePost( page, id ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
