/**
 * The × on every list search.
 *
 * Extensions and surface lists grew a clear button; the five core lists
 * (Content, Media, Users, Customers, Database) still had none, so emptying
 * a search meant selecting the text and deleting it. They share one helper
 * now (searchFieldHtml / bindSearchClear), and this suite drives each one
 * the way a person does: type, expect the ×, click it, expect an empty box
 * with the caret still in it and the full list back.
 *
 * Read-only. Nothing is created, and the searches are for a token that
 * matches nothing, so the assertion is about the chrome, not the rows.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

// A string no row on any list can match, so "more rows after the clear"
// is a real signal rather than an accident of the fixture data.
const MISS = 'zzqq' + Date.now();

( async () => {
	const t = reporter( 'search-clear' );
	const { browser, page, errors } = await launch();
	await login( page );

	const boot = await page.evaluate( () => ( {
		wc: !! window.MINN.wc,
		orders: !! ( window.MINN.caps && window.MINN.caps.orders ),
		settings: !! ( window.MINN.caps && window.MINN.caps.settings ),
	} ) ).catch( () => ( {} ) );

	// route: the hash to land on. box: the search input's id. The helper
	// stamps the button with data-searchclear="<box id>", so one selector
	// finds every one of them.
	const LISTS = [
		{ name: 'Content', route: 'content', box: 'minn-content-search', rows: '.minn-table-row' },
		{ name: 'Media', route: 'media', box: 'minn-media-search', rows: '[data-media]' },
		{ name: 'Users', route: 'users', box: 'minn-user-search', rows: '.minn-table-row' },
		{ name: 'Extensions', route: 'extensions', box: 'minn-ext-search', rows: '.minn-card.minn-plugin' },
	];
	if ( boot.wc && boot.orders ) {
		LISTS.push( { name: 'Customers', route: 'customers', box: 'minn-customer-search', rows: '.minn-table-row' } );
	}
	if ( boot.settings ) {
		LISTS.push( { name: 'Database', route: 'database', box: 'minn-db-search', rows: '.minn-table-row' } );
	}

	const xOf = ( box ) => `[data-searchclear="${ box }"]`;

	for ( const list of LISTS ) {
		await page.goto( BASE + '/minn-admin/' + list.route, { waitUntil: 'domcontentloaded' } );
		const there = await page.waitForSelector( '#' + list.box, { timeout: 25000 } ).catch( () => null );
		if ( ! there ) {
			t.check( list.name + ': search box renders', false, 'no #' + list.box );
			continue;
		}
		// Wait for the list to settle before counting: a disabled shell paints
		// first on Media and Users while the rows load.
		await page.waitForFunction( ( sel ) => ! document.querySelector( sel + ':disabled' ),
			'#' + list.box, { timeout: 25000 } ).catch( () => null );
		await page.waitForTimeout( 400 );

		const before = await page.evaluate( ( l ) => ( {
			rows: document.querySelectorAll( l.rows ).length,
			xHidden: ( () => {
				const b = document.querySelector( `[data-searchclear="${ l.box }"]` );
				return ! b || b.hidden;
			} )(),
		} ), list );
		t.check( list.name + ': the × is hidden on an empty search', before.xHidden,
			JSON.stringify( before ) );

		await page.click( '#' + list.box );
		await page.keyboard.type( MISS, { delay: 25 } );
		const shown = await page.waitForFunction( ( box ) => {
			const b = document.querySelector( `[data-searchclear="${ box }"]` );
			return !! ( b && ! b.hidden );
		}, list.box, { timeout: 10000 } ).then( () => true ).catch( () => false );
		t.check( list.name + ': the × appears with the first letter', shown );

		// Wait for the SEARCH to land, never a flat timeout: the fixture site
		// is slow enough that a clear can otherwise race an in-flight search
		// and read the previous list back.
		const narrowed = await page.waitForFunction( ( sel ) => ! document.querySelector( sel ),
			list.rows, { timeout: 30000 } ).then( () => true ).catch( () => false );
		t.check( list.name + ': a search that matches nothing empties the list', narrowed );

		await page.click( xOf( list.box ) );
		const emptied = await page.waitForFunction( ( box ) => {
			const b = document.querySelector( '#' + box );
			return b && b.value === '';
		}, list.box, { timeout: 20000 } ).then( () => true ).catch( () => false );
		t.check( list.name + ': the × empties the box', emptied );

		// Same again for the reload the clear kicks off.
		await page.waitForFunction( ( l ) => document.querySelectorAll( l.rows ).length >= l.want,
			{ rows: list.rows, want: before.rows }, { timeout: 30000 } ).catch( () => null );
		const after = await page.evaluate( ( l ) => ( {
			value: ( document.querySelector( '#' + l.box ) || {} ).value,
			focused: document.activeElement && document.activeElement.id === l.box,
			rows: document.querySelectorAll( l.rows ).length,
			xHidden: ( () => {
				const b = document.querySelector( `[data-searchclear="${ l.box }"]` );
				return ! b || b.hidden;
			} )(),
		} ), list );
		t.check( list.name + ': clearing restores the list and hides the ×',
			after.value === '' && after.xHidden && after.rows >= before.rows,
			JSON.stringify( { before: before.rows, after } ) );
		t.check( list.name + ': the caret stays in the search box', after.focused,
			JSON.stringify( after ) );
	}

	// Escape is the keyboard path to the same clear. Content stands in for
	// the set: they all run through bindSearchClear's one handler.
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-content-search', { timeout: 25000 } );
	await page.click( '#minn-content-search' );
	await page.keyboard.type( MISS, { delay: 25 } );
	await page.waitForFunction( () => ! document.querySelector( '.minn-table-row' ),
		null, { timeout: 30000 } ).catch( () => null );
	await page.keyboard.press( 'Escape' );
	const esc = await page.waitForFunction( () => {
		const b = document.querySelector( '#minn-content-search' );
		return b && b.value === '';
	}, null, { timeout: 20000 } ).then( () => true ).catch( () => false );
	t.check( 'Escape in the box clears the search too', esc );

	// Escape must not also close something behind the field. The editor's
	// modal layer listens on document, so a stopped-propagation clear is
	// the difference between emptying a box and losing a dialog.
	const stillThere = await page.evaluate( () => ( {
		route: location.pathname,
		modal: !! document.querySelector( '.minn-modal' ),
	} ) );
	t.check( 'Escape stays inside the search field',
		/content/.test( stillThere.route ) && ! stillThere.modal, JSON.stringify( stillThere ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
