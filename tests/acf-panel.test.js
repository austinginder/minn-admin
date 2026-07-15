/**
 * ACF editor panel through the shared form engine: the true_false switch,
 * the select's "—" clear option, and the false-sentinel regression.
 *
 * ACF answers `false` over REST for ANY field with no value — including
 * selects and text fields. Panel saves round-trip the whole values object,
 * so before the seed normalization that sentinel made ACF's own REST schema
 * reject every panel save on the post with a 400 ("acf[layout] must contain
 * at least 1 item"). The suite drives a post whose select is untouched and
 * empty, which is exactly the shape that used to fail.
 *
 * Fixture: ACF free with the group_minn_test "Post details" group
 * (subtitle text, layout select, featured_story true_false, editor_notes
 * textarea, photo_gallery gallery — the gallery only feeds the locked count).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-panel' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, { title: 'ACF panel test', content: '<p>x</p>' } );

	// Watch the post saves — the sentinel bug surfaced as a 400 here while
	// the UI still looked fine.
	const saves = [];
	page.on( 'response', ( res ) => {
		if ( res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ) ) {
			saves.push( res.status() );
		}
	} );

	const readAcf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?_fields=acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).acf;
	}, id );
	// Wait on the real save response, not a flat delay — a slow worker makes
	// a fixed wait race the request (the rule-51 shortcuts lesson).
	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 400 );
	};

	try {
		await openEditor( page, id );
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const toggleSel = '[data-pf$=":featured_story"][data-ftype="toggle"]';
		const selectSel = '[data-pf$=":layout"][data-ftype="select"]';
		await page.waitForSelector( toggleSel, { timeout: 15000 } );

		t.check( 'select renders with the "—" clear option',
			await page.$eval( selectSel, ( e ) => e.options[ 0 ].value === '' ) );

		// Toggle on → save with the untouched empty select in the payload.
		await page.click( toggleSel );
		t.check( 'switch flips on with aria state', await page.$eval( toggleSel,
			( e ) => e.classList.contains( 'on' ) && e.getAttribute( 'aria-checked' ) === 'true' ) );
		await save();
		t.check( 'save with the empty-select sentinel is not rejected',
			saves.length > 0 && saves.every( ( s ) => s < 400 ), saves.join( ',' ) );
		let acf = await readAcf();
		t.check( 'toggle=on persisted', acf && acf.featured_story === true, JSON.stringify( acf ) );

		// Set the select, save, verify.
		await page.selectOption( selectSel, 'wide' );
		await save();
		acf = await readAcf();
		t.check( 'select value persisted', acf && acf.layout === 'wide', JSON.stringify( acf ) );

		// Clear the select via "—" and toggle back off in one save.
		await page.selectOption( selectSel, '' );
		await page.click( toggleSel );
		await save();
		acf = await readAcf();
		t.check( 'select cleared via "—"', acf && ( acf.layout === false || acf.layout === '' || acf.layout === null ), JSON.stringify( acf ) );
		t.check( 'toggle=off persisted', acf && acf.featured_story === false, JSON.stringify( acf ) );
		t.check( 'no save was rejected across the run', saves.every( ( s ) => s < 400 ), saves.join( ',' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
