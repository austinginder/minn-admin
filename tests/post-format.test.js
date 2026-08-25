/**
 * Per-post format picker (core-gaps bundle). post_format sits in TAX_SKIP, so
 * format-driven themes couldn't be worked in Minn; only the site default was
 * settable. The editor sidebar now has a Format select, gated on the theme
 * declaring post-format support (the minn-dev-fixtures mu-plugin declares a
 * representative set), that saves through wp/v2's native `format` field.
 *
 * The blank-document path is covered separately below. The saved-post path
 * derived post-format support from the REST response (`format` is exposed
 * exactly when the type supports post-formats), but a new post never set the
 * flag at all, so the picker appeared only once a post had been saved. That
 * section also pins the seeding half: a new post starts on Settings > Writing's
 * "Default Post Format" and stores it on first save, which core applies in
 * wp-admin only (get_default_post_to_edit writes it onto the auto-draft), so a
 * REST create keeps 'standard' unless the create payload carries it.
 *
 * Uses throwaway drafts; deletes them in the finally.
 */
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { launch, login, reporter, BASE } = require( './helpers' );

// MINN_TEST_WP first: on core-latest runs the harness points at the bare
// site, and a __dirname-derived path silently writes the DEV site's
// database instead — the browser then reads a value the CLI never wrote.
const WP_PATH = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const wpEval = ( code ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } eval ${ JSON.stringify( code ) } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();

( async () => {
	const t = reporter( 'post-format' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	const readDefault = async () => ( await rest( 'wp/v2/settings?_cb=' + Math.random() ) ).body.default_post_format;

	// Write-then-verify with retries (REST settings writes can race boot).
	const setDefault = async ( fmt ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await rest( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( { default_post_format: fmt } ) } );
			if ( await readDefault() === fmt ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const formatOf = async ( pid ) => ( await rest( `wp/v2/posts/${ pid }?context=edit&_fields=format` ) ).body.format;
	const pickerValue = () => page.evaluate( () => {
		const el = document.querySelector( '#minn-post-format' );
		return el ? el.value : null;
	} );

	// A new document of the given type, with its Settings door open. Waits on
	// the slug field, which the door always renders — waiting on the format
	// picker itself would make an absent picker look like a slow one.
	const openNew = async ( type ) => {
		await page.goto( `${ BASE }/minn-admin/editor/${ type }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 600 );
		await page.evaluate( () => document.querySelector( '[data-side-door="settings"]' ).click() );
		await page.waitForSelector( '#minn-slug-input', { timeout: 15000 } );
	};

	let postId = null;
	let newId = 0;
	let origDefault = null;
	try {
		const created = await rest( 'wp/v2/posts', { method: 'POST', body: JSON.stringify( {
			title: 'Minn format picker test', status: 'draft', content: 'Format test body.',
		} ) } );
		postId = created.body && created.body.id;
		t.check( 'draft post created', !! postId, String( postId ) );
		t.check( 'new post defaults to standard format', created.body && created.body.format === 'standard', created.body && created.body.format );

		// v0.16: the format picker moved into the Settings door modal. Open it
		// to reach #minn-post-format; the door closes with #minn-modal-close.
		const openSettingsDoor = async () => {
			await page.waitForSelector( '[data-side-door="settings"]', { timeout: 20000 } );
			await page.evaluate( () => document.querySelector( '[data-side-door="settings"]' ).click() );
			await page.waitForSelector( '#minn-post-format', { timeout: 15000 } );
		};
		const closeDoor = async () => {
			await page.evaluate( () => { const x = document.querySelector( '#minn-modal-close' ); if ( x ) x.click(); } );
			await page.waitForFunction( () => ! document.querySelector( '#minn-post-format' ), { timeout: 5000 } ).catch( () => {} );
		};

		await page.goto( BASE + `/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await openSettingsDoor();

		// Boot payload exposes the theme's supported formats.
		const bootFormats = await page.evaluate( () => Object.keys( window.MINN.postFormats || {} ) );
		t.check( 'boot payload lists supported formats incl. standard + aside', bootFormats.includes( 'standard' ) && bootFormats.includes( 'aside' ), bootFormats.join( ',' ) );

		const initial = await page.$eval( '#minn-post-format', ( s ) => s.value );
		t.check( 'picker starts on the post\'s current format', initial === 'standard', initial );

		// Choose Aside in the door (marks dirty), close it, then save on the rail.
		await page.selectOption( '#minn-post-format', 'aside' );
		await closeDoor();
		await page.click( '#minn-save-draft-btn' );

		// Poll the server for the saved format.
		let saved = '';
		for ( let i = 0; i < 20; i++ ) {
			const r = await rest( `wp/v2/posts/${ postId }?context=edit&_fields=format` );
			saved = r.body && r.body.format;
			if ( saved === 'aside' ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'chosen format saved to the server', saved === 'aside', saved );

		// Reload and confirm the picker reflects the saved value.
		await page.goto( BASE + `/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await openSettingsDoor();
		const reloaded = await page.$eval( '#minn-post-format', ( s ) => s.value );
		t.check( 'picker reflects the saved format after reload', reloaded === 'aside', reloaded );

		/* ===== A blank document offers the picker too ===== */
		origDefault = await readDefault();
		t.check( 'site default set to link', await setDefault( 'link' ) );

		await openNew( 'posts' );
		const bootDefault = await page.evaluate( () => window.MINN.defaultPostFormat );
		t.check( 'boot payload carries the default format', bootDefault === 'link', String( bootDefault ) );
		t.check( 'standard leads the format list',
			( await page.evaluate( () => Object.keys( window.MINN.postFormats )[ 0 ] ) ) === 'standard' );

		// The regression this section exists for: no picker on a blank document.
		t.check( 'new post shows the Format picker', !! ( await page.$( '#minn-post-format' ) ) );
		t.check( 'new post picker starts on the site default', await pickerValue() === 'link', String( await pickerValue() ) );
		const counts = await page.evaluate( () => ( {
			options: document.querySelectorAll( '#minn-post-format option' ).length,
			formats: Object.keys( window.MINN.postFormats ).length,
		} ) );
		t.check( 'picker lists every supported format', counts.options === counts.formats, `${ counts.options }/${ counts.formats }` );

		// An untouched new post STORES that default: REST creates ignore the
		// option, so the create payload has to carry it.
		await closeDoor();
		await page.fill( '#minn-editor-title', 'Minn format new-post test' );
		await page.click( '#minn-save-draft-btn' );
		await page.waitForFunction( () => /editor\/posts\/\d+/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => {} );
		await page.waitForTimeout( 2000 );
		newId = await page.evaluate( () => {
			const m = location.pathname.match( /editor\/posts\/(\d+)/ );
			return m ? parseInt( m[ 1 ], 10 ) : 0;
		} );
		t.check( 'new draft created', newId > 0, String( newId ) );
		t.check( 'untouched new post stores the site default', await formatOf( newId ) === 'link', await formatOf( newId ) );

		/* ===== A type without post-format support offers nothing ===== */
		await openNew( 'pages' );
		t.check( 'new page hides the Format picker', ! ( await page.$( '#minn-post-format' ) ) );
		await closeDoor();

		/* ===== A default the theme does not support falls back to standard ===== */
		// Written straight to the option: the REST setting is enum-validated,
		// and this replays a theme that dropped support for the stored format.
		// Write-verify-rewrite: belt and braces against the REST-settings
		// write-visibility heisenbug (an earlier settings POST committing
		// late would clobber this CLI write). Re-assert until it holds.
		for ( let i = 0; i < 5; i++ ) {
			wpEval( 'update_option( "default_post_format", "not-a-format" );' );
			await page.waitForTimeout( 400 );
			if ( wpEval( 'echo get_option( "default_post_format" );' ) === 'not-a-format' ) break;
		}
		t.check( 'unsupported default resolves to standard',
			wpEval( 'echo Minn_Admin::default_post_format();' ) === 'standard' );
		let fallback = null;
		for ( let i = 0; i < 3; i++ ) {
			await openNew( 'posts' );
			fallback = await pickerValue();
			if ( fallback === 'standard' ) break;
			// A stale read means the clobber landed after our verify — put
			// the option back and load once more.
			wpEval( 'update_option( "default_post_format", "not-a-format" );' );
			await page.waitForTimeout( 400 );
		}
		t.check( 'new post picker falls back to standard', fallback === 'standard', String( fallback ) );
		await closeDoor();
	} finally {
		if ( postId ) await rest( `wp/v2/posts/${ postId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( newId ) await rest( `wp/v2/posts/${ newId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( origDefault !== null ) await setDefault( origDefault || 'standard' );
	}
	await t.done( browser, errors );
} )();
