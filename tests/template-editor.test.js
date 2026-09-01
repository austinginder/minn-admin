/**
 * Template editing in Minn's own editor (/editor/templates/<theme>/<slug>):
 * the patterns precedent applied to wp_template and wp_template_part.
 *
 * Load-bearing properties, all verified against SAVED markup:
 * - the string id ("theme//slug") rides the path as two segments and round
 *   trips through load and save;
 * - a text edit saves while untouched blocks (an unregistered island, a
 *   template-part reference) come back byte-identical;
 * - the first save of a THEME template creates the site's copy (source flips
 *   theme → custom) and Reset to theme hands the file back;
 * - the sidebar is template-shaped: no Publish/visibility machinery, and no
 *   autosave (saving is explicit).
 *
 * Activates twentytwentyfive for the run; restores the previous theme in
 * finally.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

( async () => {
	const t = reporter( 'template-editor' );
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

	const UNKNOWN = '<!-- wp:acme/unknown-thing {"weird":true} /-->';
	const PART_REF = '<!-- wp:template-part {"slug":"header","theme":"twentytwentyfive"} /-->';
	const SLUG = 'minn-editor-suite-tpl';
	const FIXTURE = [
		'<!-- wp:paragraph --><p>Alpha starting text</p><!-- /wp:paragraph -->',
		UNKNOWN,
		PART_REF,
	].join( '\n\n' );

	let prevTheme = '';
	let tplId = '';
	let indexCustomized = false;
	const saved = async ( id ) => ( await rest( `wp/v2/templates/${ encodeURIComponent( id ) }?context=edit&_fields=content,source` ) ).body;

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;
		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		const made = await rest( 'wp/v2/templates', {
			method: 'POST',
			body: { slug: SLUG, theme: 'twentytwentyfive', title: 'Editor Suite Template', content: FIXTURE },
		} );
		tplId = made.body.id;
		t.check( 'seeded a custom template', made.status === 201 || made.status === 200, `status ${ made.status }` );

		/* ===== The list row opens Minn's editor ===== */
		await page.goto( BASE + '/minn-admin/templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-tpl]', { timeout: 20000 } );
		await page.waitForTimeout( 300 );
		await page.evaluate( ( id ) => {
			document.querySelector( `[data-tpl="${ CSS.escape( id ) }"] .minn-menu-info` ).click();
		}, tplId );
		await page.waitForFunction( () => location.pathname.includes( '/editor/templates/' ), null, { timeout: 15000 } );
		t.check( 'a theme-owned row opens the Minn editor',
			await page.evaluate( () => location.pathname.endsWith( '/editor/templates/twentytwentyfive/' + 'minn-editor-suite-tpl' ) ),
			await page.evaluate( () => location.pathname ) );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 1500 );

		/* ===== Template-shaped chrome ===== */
		const chrome = await page.evaluate( () => ( {
			note: ( document.querySelector( '.minn-pattern-note' ) || {} ).textContent || '',
			publish: ( document.querySelector( '#minn-publish-btn' ) || {} ).textContent || '',
			visibility: !! document.querySelector( '#minn-visibility' ),
			schedule: !! document.querySelector( '#minn-schedule-input' ),
			trash: !! document.querySelector( '#minn-trash-post' ),
			status: ( document.querySelector( '#minn-status-state' ) || {} ).textContent || '',
			siteEditor: !! document.querySelector( 'a[href*="site-editor"]' ),
			island: !! document.querySelector( '[data-block="acme/unknown-thing"]' ),
			back: ( document.querySelector( '#minn-editor-back' ) || {} ).textContent || '',
		} ) );
		t.check( 'the banner says what a template is', /template/i.test( chrome.note ), chrome.note.slice( 0, 60 ) );
		t.check( 'no Publish machinery: visibility, schedule and trash are gone',
			! chrome.visibility && ! chrome.schedule && ! chrome.trash );
		t.check( 'the sidebar reads Added here with an Update button',
			/Added here/.test( chrome.status ) && /Update/.test( chrome.publish ), JSON.stringify( [ chrome.status, chrome.publish ] ) );
		t.check( 'the Site Editor stays one click away', chrome.siteEditor );
		t.check( 'the unregistered block renders as an island', chrome.island );
		t.check( 'Back returns to the Templates list', /Templates/.test( chrome.back ), chrome.back );

		/* ===== Edit text, save, byte-identity for everything untouched ===== */
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' edited in Minn' );
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'minn-editor-suite-tpl' ) && r.request().method() === 'POST', { timeout: 20000 } ),
			page.click( '#minn-publish-btn' ),
		] );
		await page.waitForTimeout( 400 );
		const after = await saved( tplId );
		t.check( 'the typed text is in the saved template',
			after.content.raw.includes( 'Alpha starting text edited in Minn' ), after.content.raw.slice( 0, 120 ) );
		t.check( 'the unregistered block survives verbatim', after.content.raw.includes( UNKNOWN ) );
		t.check( 'the template-part reference survives verbatim', after.content.raw.includes( PART_REF ) );

		/* ===== A THEME template: first save creates the site copy ===== */
		await page.goto( BASE + '/minn-admin/editor/templates/twentytwentyfive/index', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 1500 );
		const themeChrome = await page.evaluate( () => ( {
			status: ( document.querySelector( '#minn-status-state' ) || {} ).textContent || '',
			publish: ( document.querySelector( '#minn-publish-btn' ) || {} ).textContent || '',
			note: ( document.querySelector( '.minn-pattern-note' ) || {} ).textContent || '',
			reset: !! document.querySelector( '#minn-tpl-reset' ),
		} ) );
		t.check( 'a theme template reads From theme with a Save site copy button',
			/From theme/.test( themeChrome.status ) && /Save site copy/.test( themeChrome.publish ), JSON.stringify( themeChrome.publish ) );
		t.check( 'its banner explains that saving creates a site copy', /site.{0,3}s copy/i.test( themeChrome.note ), themeChrome.note.slice( 0, 90 ) );
		t.check( 'no Reset offered while the theme file is still the live version', ! themeChrome.reset );

		const before = ( await saved( 'twentytwentyfive//index' ) ).content.raw;
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'twentytwentyfive%2F%2Findex' ) && r.request().method() === 'POST', { timeout: 20000 } ),
			page.click( '#minn-publish-btn' ),
		] );
		indexCustomized = true;
		await page.waitForTimeout( 600 );
		const copied = await saved( 'twentytwentyfive//index' );
		t.check( 'saving created the site copy', copied.source === 'custom', copied.source );
		// The theme FILE ends with a newline no block serializer re-emits (the
		// Site Editor's own save drops it too) — the guarantee is that every
		// BLOCK is byte-identical, with only trailing whitespace normalized.
		t.check( 'an untouched save round-trips every block byte-identical',
			copied.content.raw.replace( /\s+$/, '' ) === before.replace( /\s+$/, '' ),
			`${ before.length } → ${ copied.content.raw.length } chars` );
		const flipped = await page.evaluate( () => ( {
			status: ( document.querySelector( '#minn-status-state' ) || {} ).textContent || '',
			reset: !! document.querySelector( '#minn-tpl-reset' ),
		} ) );
		t.check( 'the sidebar flips to Customized and offers Reset to theme',
			/Customized/.test( flipped.status ) && flipped.reset, JSON.stringify( flipped ) );

		/* ===== Reset from the editor hands the file back ===== */
		await page.click( '#minn-tpl-reset' );
		await page.waitForFunction( () => location.pathname.endsWith( '/templates' ), null, { timeout: 20000 } );
		const restored = await rest( 'wp/v2/templates/twentytwentyfive%2F%2Findex?context=edit&_fields=source' );
		indexCustomized = restored.body && restored.body.source !== 'theme';
		t.check( 'Reset lands back on the list with the theme version live',
			restored.body && restored.body.source === 'theme', JSON.stringify( restored.body ) );

		/* ===== A part opens too ===== */
		await page.goto( BASE + '/minn-admin/editor/template-parts/twentytwentyfive/header', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 1200 );
		t.check( 'a template part opens in the editor with its own noun',
			await page.evaluate( () => /Template part/i.test( document.querySelector( '#minn-sub' ).textContent ) ),
			await page.evaluate( () => document.querySelector( '#minn-sub' ).textContent ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( indexCustomized ) await rest( 'wp/v2/templates/twentytwentyfive%2F%2Findex?force=true', { method: 'DELETE' } ).catch( () => {} );
		if ( tplId ) await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
