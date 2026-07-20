/**
 * Search autofocus — fresh list navigations put the caret in the view's
 * filter/search box so typing filters immediately (v0.21.0).
 *
 * Covers: hard-load autofocus (boot path, input appears AFTER the async
 * load), SPA-nav autofocus (focus taken from the clicked nav button),
 * once-per-navigation semantics (re-renders never re-steal), and the
 * typing-elsewhere disarm (a focused text field is never robbed).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'search-autofocus' );
	const { browser, page, errors } = await launch();
	await login( page );

	const focusedId = () => page.evaluate( () => document.activeElement && document.activeElement.id );

	// 1. Hard load of Extensions: the search input only exists after the
	// plugin list loads — autofocus must survive the cold-paint shell.
	await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-ext-search', { timeout: 15000 } );
	await page.waitForTimeout( 400 );
	t.check( 'Extensions hard load focuses the filter box', ( await focusedId() ) === 'minn-ext-search' );

	// 2. Typing immediately filters (no click needed).
	await page.keyboard.type( 'akismet' );
	await page.waitForTimeout( 400 );
	const filtered = await page.evaluate( () => ( {
		value: document.querySelector( '#minn-ext-search' ).value,
		focused: document.activeElement && document.activeElement.id,
	} ) );
	t.check( 'Typing lands in the box and survives the re-render', filtered.value === 'akismet' && filtered.focused === 'minn-ext-search' );

	// 3. Once per navigation: clicking a filter pill re-renders the grid but
	// must NOT yank focus back into the search box.
	await page.evaluate( () => { document.querySelector( '#minn-ext-search' ).value = ''; } );
	await page.evaluate( () => {
		const s = document.querySelector( '#minn-ext-search' );
		s.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 300 );
	await page.click( '[data-xfilter="active"]' );
	await page.waitForTimeout( 500 );
	t.check( 'Filter-pill re-render does not re-steal focus', ( await focusedId() ) !== 'minn-ext-search' );

	// 4. SPA navigation: click the Content nav button. Focus sits on the
	// button at render time and must still hand off to the content search.
	await page.click( '.minn-nav-btn[data-nav="content"]' );
	await page.waitForSelector( '#minn-content-search', { timeout: 15000 } );
	await page.waitForTimeout( 600 );
	t.check( 'SPA nav to Content focuses its search box', ( await focusedId() ) === 'minn-content-search' );

	// 5. SPA nav to Media covers a second list view generically.
	await page.click( '.minn-nav-btn[data-nav="media"]' );
	await page.waitForSelector( '#minn-media-search', { timeout: 15000 } );
	await page.waitForTimeout( 600 );
	t.check( 'SPA nav to Media focuses its search box', ( await focusedId() ) === 'minn-media-search' );

	// 6. Typing-elsewhere disarm: navigate via JS with a text field already
	// focused — the armed autofocus must back off and never rob the caret.
	await page.evaluate( () => {
		const probe = document.createElement( 'input' );
		probe.id = 'minn-test-probe';
		document.body.appendChild( probe );
	} );
	await page.focus( '#minn-test-probe' );
	await page.evaluate( () => {
		history.pushState( null, '', '/minn-admin/extensions' );
		window.dispatchEvent( new PopStateEvent( 'popstate' ) );
	} );
	await page.waitForSelector( '#minn-ext-search', { timeout: 15000 } );
	await page.waitForTimeout( 600 );
	t.check( 'A focused text field is never robbed', ( await focusedId() ) === 'minn-test-probe' );
	await page.evaluate( () => document.querySelector( '#minn-test-probe' ).remove() );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
