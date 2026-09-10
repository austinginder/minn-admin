/**
 * Template history in the editor (core-gaps "template REVISIONS/history").
 *
 * A block template opens in Minn's own editor, but its History door was the
 * one part of the sidebar that never appeared: the revisions route was never
 * called for templates, and every revision path put the id in unencoded,
 * which a template id ("theme//slug") does not survive.
 *
 * What this pins: the door appears once the site has saved its own copy of a
 * template, lists that template's revisions, opens one as a diff against what
 * the writer currently sees, and restores it. A template the site has not
 * customized has no post row behind it, so it must show no door at all rather
 * than an error.
 *
 * Activates twentytwentyfive and restores the previous theme in finally.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

const SLUG = 'minn-suite-tplrev';
const V1 = '<!-- wp:paragraph --><p>Revision one from the suite.</p><!-- /wp:paragraph -->';
const V2 = '<!-- wp:paragraph --><p>Revision two from the suite.</p><!-- /wp:paragraph -->';
const V3 = '<!-- wp:paragraph --><p>Revision three from the suite.</p><!-- /wp:paragraph -->';
const V4 = '<!-- wp:paragraph --><p>Revision four from the suite.</p><!-- /wp:paragraph -->';

( async () => {
	const t = reporter( 'template-revisions' );
	const { browser, page, errors } = await launch();
	await login( page );
	await autoConfirm( page );
	// Restore still asks with a NATIVE confirm(), which Playwright dismisses
	// unless something answers it; autoConfirm only clicks Minn's own overlay.
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	// Activating a theme recycles the PHP worker, so the next request over a
	// kept-alive socket can die instantly with a TypeError while the server is
	// perfectly fine. One delayed replay, the same rule the app itself follows.
	const rest = async ( path, opts = {} ) => {
		try {
			return await restOnce( path, opts );
		} catch ( e ) {
			await page.waitForTimeout( 1500 );
			return restOnce( path, opts );
		}
	};
	const restOnce = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	// /editor/templates/<theme>/<slug>: the two halves of the id ride as two
	// path parts, because a decoded "//" collapses.
	const openTemplateEditor = async ( id ) => {
		const [ owner, slug ] = String( id ).split( '//' );
		await page.goto( `${ BASE }/minn-admin/editor/templates/${ owner }/${ slug }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 30000 } );
		await page.waitForTimeout( 1200 );
	};
	const historyDoor = () => page.evaluate( () => {
		const d = document.querySelector( '[data-side-door="history"]' );
		return d ? { there: true, sum: d.querySelector( '.minn-side-door-sum' ).textContent.trim() } : { there: false };
	} );

	let prevTheme = '';
	let tplId = '';

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;
		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		/* ===== A theme template the site has never touched offers no history ===== */
		const themeTpl = ( await rest( 'wp/v2/templates?context=edit&_fields=id,slug,source' ) ).body
			.find( ( x ) => x.source === 'theme' );
		if ( themeTpl ) {
			await openTemplateEditor( themeTpl.id );
			const door = await historyDoor();
			t.check( 'a template still coming from the theme shows no History door',
				! door.there, JSON.stringify( { id: themeTpl.id, door } ) );
		} else {
			t.check( 'no untouched theme template to check', true, 'skipped' );
		}

		/* ===== A template this site owns, with a history ===== */
		const made = await rest( 'wp/v2/templates', { method: 'POST', body: {
			slug: SLUG, theme: 'twentytwentyfive', title: 'Minn suite revisions', content: V1, status: 'publish',
		} } );
		t.check( 'a template is created for the suite', made.status === 201 || made.status === 200,
			JSON.stringify( { status: made.status } ) );
		tplId = made.body && made.body.id;

		// Each UPDATE snapshots the state BEFORE it, so two updates after the
		// create give two revisions with distinguishable content.
		await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }`, { method: 'POST', body: { content: V2 } } );
		await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }`, { method: 'POST', body: { content: V3 } } );
		await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }`, { method: 'POST', body: { content: V4 } } );

		const revs = await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }/revisions?context=edit&_fields=id,modified` );
		t.check( 'core keeps revisions for a customized template',
			revs.status === 200 && Array.isArray( revs.body ) && revs.body.length >= 2,
			JSON.stringify( { status: revs.status, n: Array.isArray( revs.body ) ? revs.body.length : revs.body } ) );

		await openTemplateEditor( tplId );
		const door = await page.waitForFunction( () => {
			const d = document.querySelector( '[data-side-door="history"]' );
			return d ? { there: true, sum: d.querySelector( '.minn-side-door-sum' ).textContent.trim() } : false;
		}, null, { timeout: 20000 } ).then( ( h ) => h.jsonValue() ).catch( () => ( { there: false } ) );
		t.check( 'the History door appears on a template the site owns', door.there, JSON.stringify( door ) );
		t.check( 'the door counts the revisions', /revision/i.test( door.sum || '' ), JSON.stringify( door ) );

		/* ===== The door opens the list ===== */
		await page.click( '[data-side-door="history"]' );
		// The dialog paints its short list first and fills in from the paginated
		// route: wait for the full set, never the first row.
		await page.waitForFunction( () => document.querySelectorAll( '[data-revlist]' ).length >= 2,
			null, { timeout: 25000 } ).catch( () => null );
		const listed = await page.evaluate( () => document.querySelectorAll( '[data-revlist]' ).length );
		t.check( 'the list shows this template’s revisions', listed >= 2,
			`${ listed } rows (the newest revision is hidden while the editor is clean)` );

		/* ===== One revision opens as a diff ===== */
		await page.evaluate( () => document.querySelectorAll( '[data-revlist]' )[ 0 ].click() );
		await page.waitForSelector( '#minn-restore-rev', { timeout: 30000 } );
		const diff = await page.evaluate( () => ( {
			text: ( document.querySelector( '.minn-modal' ) || {} ).textContent || '',
		} ) );
		t.check( 'the revision loads instead of erroring on the template id',
			/Revision (one|two|three|four)/.test( diff.text ), diff.text.slice( 0, 200 ) );

		/* ===== Restore writes it back through the template route ===== */
		await page.click( '#minn-restore-rev' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ),
			null, { timeout: 30000 } ).catch( () => null );
		await page.waitForTimeout( 1500 );
		const now = await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }?context=edit&_fields=content` );
		const stored = ( now.body && now.body.content && now.body.content.raw ) || '';
		t.check( 'restoring a revision writes it back to the template',
			/Revision (one|two|three)/.test( stored ) && ! /Revision four/.test( stored ),
			stored.slice( 0, 200 ) );
	} finally {
		if ( tplId ) {
			await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( prevTheme ) {
			await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
