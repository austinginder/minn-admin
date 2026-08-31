/**
 * Design → Templates (/minn-admin/templates): the block theme's templates and
 * template parts, with the question the Site Editor's flat list does not
 * answer — which of them this site has actually changed.
 *
 * The load-bearing checks are the status vocabulary (from theme / customized /
 * added here) and Reset to theme, which is a DELETE that hands the theme's own
 * file back rather than removing anything.
 *
 * The dev site runs a classic theme, so this activates twentytwentyfive for
 * the run and restores the previous theme in finally.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

( async () => {
	const t = reporter( 'templates' );
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

	const open = async () => {
		await page.goto( BASE + '/minn-admin/templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-tpl]', { timeout: 20000 } );
		await page.waitForTimeout( 300 );
	};
	const rows = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-tpl]' ) ].map( ( r ) => ( {
		id: r.dataset.tpl,
		title: r.querySelector( '.minn-row-title' ).textContent.trim(),
		status: r.querySelector( '.minn-menu-kind' ).textContent.trim(),
		meta: r.querySelector( '.minn-row-slug' ).textContent.trim(),
	} ) ) );
	const rowMenuAction = async ( id, label ) => {
		await page.evaluate( ( i ) => document.querySelector( `[data-tplmenu="${ CSS.escape( i ) }"]` ).click(), id );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.waitForTimeout( 120 );
		return page.evaluate( ( l ) => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => x.textContent.trim().startsWith( l ) );
			if ( b ) b.click();
			return !! b;
		}, label );
	};
	const tplBySlug = async ( slug ) =>
		( await rest( 'wp/v2/templates?context=edit&_fields=id,slug,source,has_theme_file' ) ).body.find( ( x ) => x.slug === slug );

	let prevTheme = '';
	let customized = false;
	let indexId = '';
	let tplId = '';
	let pageId = 0;
	const suiteSlug = 'minn-suite-tpl';

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;

		/* ===== Classic theme says so instead of listing nothing ===== */
		await page.goto( BASE + '/minn-admin/templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-empty', { timeout: 20000 } );
		t.check( 'a classic theme explains that its templates are PHP files',
			await page.evaluate( () => /classic theme/i.test( document.querySelector( '#minn-view' ).textContent )
				&& ! document.querySelector( '[data-tpl]' ) ) );

		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		/* ===== Untouched theme templates ===== */
		await open();
		const clean = await rows();
		t.check( 'the theme’s templates are listed', clean.length > 5, `${ clean.length } rows` );
		t.check( 'an untouched template reads as coming from the theme',
			clean.some( ( r ) => r.status === 'From theme' ), JSON.stringify( clean.slice( 0, 2 ) ) );
		t.check( 'the Design nav item is the one highlighted',
			await page.evaluate( () => !! document.querySelector( '.minn-nav-btn[data-nav="templates"].active' ) ) );

		/* ===== Templates a plugin registered are not "yours" =====
		 * The Events Calendar registers templates whose is_custom/origin/author
		 * are indistinguishable from hand-made ones; only the "<owner>//<slug>"
		 * id says otherwise. Deleting one answers 200 and the plugin registers
		 * it again, so no destructive action is offered. */
		const pluginRow = clean.find( ( r ) => r.status === 'From a plugin' );
		if ( pluginRow ) {
			t.check( 'a plugin’s own template is labelled as coming from a plugin',
				pluginRow.id.split( '//' )[ 0 ] !== 'twentytwentyfive', pluginRow.id );
			await page.evaluate( ( i ) => document.querySelector( `[data-tplmenu="${ CSS.escape( i ) }"]` ).click(), pluginRow.id );
			await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
			const pluginEntries = await page.evaluate( () =>
				[ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( e ) => e.textContent.trim() ) );
			t.check( 'a plugin’s template offers no Delete it cannot honour',
				! pluginEntries.some( ( e ) => /Delete|Reset/i.test( e ) ), JSON.stringify( pluginEntries ) );
			await page.keyboard.press( 'Escape' );
			await page.waitForTimeout( 150 );
		} else {
			t.check( 'no plugin-registered templates on this site to check', true, 'skipped' );
			t.check( 'no plugin-registered templates on this site to check', true, 'skipped' );
		}

		/* ===== The row itself opens the Site Editor ===== */
		const opened = await page.evaluate( () => {
			let url = '';
			window.open = ( u ) => { url = u; return null; };
			document.querySelector( '[data-tpl] .minn-menu-info' ).click();
			return url;
		} );
		t.check( 'clicking a row opens that template in the Site Editor',
			opened.includes( 'site-editor.php' ) && opened.includes( 'canvas=edit' ), opened );
		t.check( 'the row carries no redundant Edit button',
			await page.evaluate( () => ! document.querySelector( '[data-tpl] a[href*="site-editor"]' ) ) );
		t.check( 'the row marks that its click leaves Minn',
			await page.evaluate( () => !! document.querySelector( '[data-tpl] .minn-row-ext' ) ) );

		/* ===== Tabs ===== */
		await page.click( '[data-tplkind="wp_template_part"]' );
		await page.waitForTimeout( 400 );
		const parts = await rows();
		t.check( 'the Parts tab lists template parts', parts.length > 0 && parts.every( ( p ) => p.id.length ), `${ parts.length } parts` );
		await page.click( '[data-tplkind="wp_template"]' );
		await page.waitForTimeout( 400 );

		await page.click( '[data-designtab="navigation"]' );
		await page.waitForFunction( () => location.pathname.endsWith( '/navigation' ), null, { timeout: 10000 } );
		t.check( 'the Navigation tab is one click away and keeps Design highlighted',
			await page.evaluate( () => !! document.querySelector( '.minn-nav-btn[data-nav="templates"].active' ) ) );
		await page.click( '[data-designtab="templates"]' );
		await page.waitForFunction( () => location.pathname.endsWith( '/templates' ), null, { timeout: 10000 } );

		/* ===== Customizing one is reflected, and sorts to the top ===== */
		const idx = await tplBySlug( 'index' );
		indexId = idx.id;
		t.check( 'the index template starts out unmodified', idx.source === 'theme', idx.source );
		await rest( `wp/v2/templates/${ encodeURIComponent( idx.id ) }`, {
			method: 'POST',
			body: { content: '<!-- wp:paragraph --><p>suite override</p><!-- /wp:paragraph -->' },
		} );
		customized = true;
		await open();
		const afterEdit = await rows();
		const idxRow = afterEdit.find( ( r ) => r.id === idx.id );
		t.check( 'a changed template reads as customized', idxRow && idxRow.status === 'Customized', JSON.stringify( idxRow ) );
		t.check( 'it names who changed it and when', /admin/.test( idxRow.meta ), idxRow.meta );
		t.check( 'changed templates sort above untouched ones',
			afterEdit.findIndex( ( r ) => r.status === 'Customized' ) < afterEdit.findIndex( ( r ) => r.status === 'From theme' ),
			JSON.stringify( afterEdit.slice( 0, 3 ).map( ( r ) => r.status ) ) );
		t.check( 'the toolbar counts what this site changed',
			/changed of/.test( await page.evaluate( () => document.querySelector( '.minn-toolbar-meta' ).textContent ) ) );

		/* ===== Usage: what actually reaches each template =====
		 * A page picks its template explicitly, so a custom template's usage is
		 * a count of pages. Hierarchy templates are reached by rule and must
		 * NOT claim to be unused, which is the whole point of the distinction. */
		const madeTpl = await rest( 'wp/v2/templates', {
			method: 'POST',
			body: {
				slug: suiteSlug,
				theme: 'twentytwentyfive',
				title: 'Suite Custom Template',
				content: '<!-- wp:paragraph --><p>suite</p><!-- /wp:paragraph -->',
			},
		} );
		t.check( 'seeded a custom template', madeTpl.status === 200 || madeTpl.status === 201, `status ${ madeTpl.status }` );
		tplId = madeTpl.body && madeTpl.body.id;

		await open();
		const unusedRow = ( await rows() ).find( ( r ) => r.title === 'Suite Custom Template' );
		t.check( 'a custom template nothing uses says so', unusedRow && /Not used by any page/.test( unusedRow.meta ), JSON.stringify( unusedRow ) );

		const pg = await rest( 'wp/v2/pages', {
			method: 'POST',
			body: { title: 'Suite template page', status: 'draft', template: suiteSlug },
		} );
		pageId = pg.body && pg.body.id;
		t.check( 'seeded a page that chooses it', pg.body && pg.body.template === suiteSlug, JSON.stringify( pg.body && pg.body.template ) );

		await open();
		const usedRow = ( await rows() ).find( ( r ) => r.title === 'Suite Custom Template' );
		t.check( 'the template now reports the page that uses it',
			usedRow && /Used by 1 page/.test( usedRow.meta ), JSON.stringify( usedRow ) );

		// The template behind every post must never read as unused.
		const hierarchyRow = ( await rows() ).find( ( r ) => r.title === 'Index' );
		t.check( 'a hierarchy template makes no usage claim at all',
			hierarchyRow && ! /Not used|Used by/.test( hierarchyRow.meta ), JSON.stringify( hierarchyRow ) );

		/* ===== Parts count the templates that pull them in ===== */
		await page.click( '[data-tplkind="wp_template_part"]' );
		await page.waitForTimeout( 500 );
		const partRows = await rows();
		t.check( 'a part reports how many templates include it',
			partRows.some( ( r ) => /In \d+ template/.test( r.meta ) ), JSON.stringify( partRows.map( ( r ) => r.meta ).slice( 0, 3 ) ) );
		await page.click( '[data-tplkind="wp_template"]' );
		await page.waitForTimeout( 400 );

		/* ===== Reset hands the theme's own file back ===== */
		t.check( 'a customized template offers Reset to theme', await rowMenuAction( idx.id, 'Reset to theme' ) );
		await page.waitForFunction( () => {
			const r = [ ...document.querySelectorAll( '[data-tpl] .minn-menu-kind' ) ].map( ( e ) => e.textContent.trim() );
			return r.length && ! r.includes( 'Customized' );
		}, null, { timeout: 20000 } );
		const reset = await tplBySlug( 'index' );
		customized = false;
		t.check( 'reset gives the theme version back rather than deleting anything',
			reset && reset.source === 'theme' && reset.has_theme_file === true, JSON.stringify( reset ) );

		/* ===== An untouched template offers no destructive action ===== */
		await open();
		const themeRow = ( await rows() ).find( ( r ) => r.status === 'From theme' );
		await page.evaluate( ( i ) => document.querySelector( `[data-tplmenu="${ CSS.escape( i ) }"]` ).click(), themeRow.id );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const entries = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( e ) => e.textContent.trim() ) );
		t.check( 'an untouched template offers no Reset and no Delete',
			! entries.some( ( e ) => /Reset|Delete/i.test( e ) ), JSON.stringify( entries ) );
		t.check( 'it still offers the Site Editor', entries.some( ( e ) => /Site Editor/i.test( e ) ), JSON.stringify( entries ) );
		await page.keyboard.press( 'Escape' );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		// Leave no override behind if the reset check never ran.
		if ( customized && indexId ) {
			await rest( `wp/v2/templates/${ encodeURIComponent( indexId ) }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( pageId ) await rest( `wp/v2/pages/${ pageId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( tplId ) await rest( `wp/v2/templates/${ encodeURIComponent( tplId ) }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
