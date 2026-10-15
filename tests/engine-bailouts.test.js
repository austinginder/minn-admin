/**
 * wp-admin bail-outs: present on WordPress, hidden on Minn Engine.
 *
 * Minn Admin names the runtime on the boot payload (`window.MINN.engine`).
 * When that flag is set there is no /wp-admin/, so every classic-dashboard
 * escape hatch (the W button, Live preview, Open Stream, Tools, Edit in
 * the block editor) must stay off screen. On WordPress those same controls
 * stay. One suite covers both sides so a regression on either runtime
 * fails here. Add plugin is the other way round: the engine runs WordPress
 * plugins, so its dialog searches the directory as WordPress's does.
 *
 * Run: MINN_TEST_PASS=… node engine-bailouts.test.js
 * Engine: MINN_TEST_URL=https://mmonroe-minn.localhost MINN_TEST_USER=austin …
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'engine-bailouts' );
	await login( page );

	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-view', { timeout: 15000 } );

	const boot = await page.evaluate( () => ( {
		engine: !!( window.MINN && window.MINN.engine ),
		engineValue: window.MINN && window.MINN.engine,
		w: !! document.querySelector( '#minn-wp-admin-link' ),
	} ) );
	t.check( 'boot payload names whether this is the engine',
		boot.engine ? typeof boot.engineValue !== 'undefined' : boot.engineValue === false,
		JSON.stringify( boot.engineValue ) );

	if ( boot.engine ) {
		t.check( 'no WordPress button in the sidebar', ! boot.w );
	} else {
		t.check( 'WordPress button is in the sidebar', boot.w );
	}

	const collectBailouts = () => page.evaluate( () => {
		const skip = ( a ) => a.closest( '#minn-editor-body, .minn-island-preview, .minn-email-frame' );
		const isAdmin = ( href ) => {
			if ( ! href ) return false;
			try {
				const u = new URL( href, location.href );
				const file = ( u.pathname.split( '/' ).pop() || '' ).toLowerCase();
				if ( file === 'admin-ajax.php' ) return false;
				if ( /\/wp-admin(?:\/|$)/i.test( u.pathname ) ) return true;
				return /^(admin|post|post-new|user-edit|users|customize|site-editor|site-health|export|import|export-personal-data|erase-personal-data|options-permalink|options-general|plugins|plugin-install|themes|theme-install|tools|nav-menus|widgets|update-core|index)\.php$/.test( file )
					&& ( file !== 'index.php' || /\/(wp-admin|minn-admin)\//i.test( u.pathname ) );
			} catch ( e ) {
				return /\/wp-admin\//.test( String( href ) ) && ! /admin-ajax\.php/.test( String( href ) );
			}
		};
		return [ ...document.querySelectorAll( 'a[href]' ) ]
			.filter( ( a ) => ! skip( a ) && isAdmin( a.getAttribute( 'href' ) ) )
			.map( ( a ) => ( { href: a.getAttribute( 'href' ), text: ( a.textContent || '' ).trim().slice( 0, 40 ) } ) );
	} );

	/* ===== System: Tools card ===== */
	await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-sys-jump, .minn-sys-grid', { timeout: 20000 } );
	await page.waitForTimeout( 800 );
	const tools = await page.evaluate( () => !! document.getElementById( 'minn-sys-tools' ) );
	if ( boot.engine ) {
		t.check( 'Tools card is hidden', ! tools );
	} else {
		t.check( 'Tools card deep-links wp-admin jobs', tools );
	}

	/* ===== Extensions: Live preview on an inactive theme ===== */
	await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-plugin, [data-xtab="themes"]', { timeout: 20000 } );
	await page.click( '[data-xtab="themes"]' );
	await page.waitForSelector( '.minn-theme', { timeout: 15000 } );
	const preview = await page.evaluate( () => {
		const links = [ ...document.querySelectorAll( 'a.minn-theme-preview' ) ];
		return {
			count: links.length,
			hrefs: links.map( ( a ) => a.getAttribute( 'href' ) ),
		};
	} );
	if ( boot.engine ) {
		t.check( 'no Live preview links on theme cards', preview.count === 0, JSON.stringify( preview.hrefs ) );
	} else {
		t.check( 'inactive theme cards offer Live preview',
			preview.count > 0 && preview.hrefs.every( ( h ) => /(customize\.php\?theme=|site-editor\.php\?wp_theme_preview=)/.test( h ) ),
			JSON.stringify( preview.hrefs ) );
	}

	/* ===== Extensions: Add plugin searches WordPress.org on both
	   runtimes (the engine reads the directory through its update
	   service and runs WordPress plugins). Only the engine adds the zip
	   URL / GitHub row. ===== */
	await page.click( '[data-xtab="plugins"]' );
	const addPlugin = await page.waitForSelector( '#minn-add-plugin', { timeout: 15000 } ).catch( () => null );
	if ( addPlugin ) {
		await addPlugin.click();
		await page.waitForSelector( '#minn-pi-search', { state: 'attached', timeout: 15000 } );
		const dialog = await page.evaluate( () => {
			const search = document.getElementById( 'minn-pi-search' );
			return {
				search: !! search && ! search.hidden && search.offsetParent !== null,
				focused: document.activeElement === search,
				cards: document.querySelectorAll( '.minn-pi-card' ).length,
				urlRow: !! document.getElementById( 'minn-pi-url' ),
				staleNote: /never run here/.test( ( document.querySelector( '.minn-modal-pi' ) || document.body ).textContent || '' ),
			};
		} );
		t.check( 'Add plugin shows the directory search, focused', dialog.search && dialog.focused, JSON.stringify( dialog ) );
		t.check( 'Add plugin shows the plugin catalog', dialog.cards > 0, String( dialog.cards ) );
		t.check( 'Add plugin never says plugins will not run', ! dialog.staleNote );
		if ( boot.engine ) {
			t.check( 'Add plugin on the engine offers a zip URL or GitHub release', dialog.urlRow );
		} else {
			t.check( 'Add plugin on WordPress has no zip URL row', ! dialog.urlRow );
		}
		await page.click( '#minn-modal-close' );
	} else {
		t.check( 'no Add plugin for this user', true );
	}

	/* ===== A page builder's own editor: its content row menu offers
	   "Edit in <builder>", and the editor's banner links there. On the
	   engine a wp-admin link stays only when the engine opens that page
	   (MINN.adminActions, while the Minn Legacy Pack is active). ===== */
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row[data-id]', { timeout: 20000 } );
	const built = await page.evaluate( () => {
		const row = document.querySelector( '.minn-table-row[data-builder-edit]' );
		return row ? { id: row.dataset.id, type: row.dataset.type, name: row.dataset.builder, href: row.dataset.builderEdit } : null;
	} );
	if ( built ) {
		const action = new URL( built.href, BASE ).searchParams.get( 'action' ) || '';
		const opens = ! boot.engine || ( await page.evaluate( ( a ) => ( window.MINN.adminActions || [] ).includes( a ), action ) );
		const more = await page.$( `.minn-row-more[data-more="${ built.id }"]` );
		await more.click( { force: true } );
		await page.waitForSelector( '.minn-row-menu', { timeout: 10000 } );
		const offered = await page.evaluate( ( name ) => [ ...document.querySelectorAll( '.minn-row-menu a' ) ]
			.filter( ( a ) => a.textContent.includes( 'Edit in ' + name ) ).map( ( a ) => a.getAttribute( 'href' ) ), built.name );
		await page.keyboard.press( 'Escape' );
		if ( opens ) {
			t.check( `the row menu offers Edit in ${ built.name }`, offered.length === 1 && offered[ 0 ] === built.href, JSON.stringify( offered ) );
		} else {
			t.check( `the engine drops Edit in ${ built.name }, which it cannot open`, offered.length === 0, JSON.stringify( offered ) );
		}
		await page.goto( BASE + '/minn-admin/editor/' + built.type + '/' + built.id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view', { timeout: 20000 } );
		await page.waitForTimeout( 2500 );
		const banner = await page.evaluate( () => {
			const note = document.querySelector( '.minn-builder-note' );
			return note ? note.getAttribute( 'href' ) : null;
		} );
		t.check( opens ? `the editor's banner links to ${ built.name }` : `the editor's banner on the engine has no link it cannot open`,
			opens ? banner === built.href : ! banner, String( banner ) );
	} else {
		t.check( 'no builder-managed post on this site', true );
	}

	/* ===== Activity Log: Open Stream is Stream-adapter copy. Hidden on
	   the engine; on WordPress it only appears when Stream is the active
	   family member, so the WordPress side of this check is optional. ===== */
	const activitySel = await page.evaluate( () => {
		const btn = [ ...document.querySelectorAll( '.minn-nav-btn' ) ]
			.find( ( b ) => /Activity Log/.test( b.textContent || '' ) );
		if ( ! btn ) return null;
		return btn.dataset.nav || null;
	} );
	if ( activitySel ) {
		await page.goto( BASE + '/minn-admin/' + activitySel, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 2500 );
		const stream = await page.evaluate( () => ( {
			open: [ ...document.querySelectorAll( 'a' ) ].some( ( a ) => /Open Stream/.test( a.textContent || '' ) ),
			wpAdmin: [ ...document.querySelectorAll( '#minn-view a[href]' ) ]
				.filter( ( a ) => /\/wp-admin\/|admin\.php/i.test( a.getAttribute( 'href' ) || '' ) )
				.map( ( a ) => ( a.textContent || '' ).trim() ),
		} ) );
		if ( boot.engine ) {
			t.check( 'Activity Log has no Open Stream bail-out', ! stream.open );
			t.check( 'Activity Log has no wp-admin anchors', stream.wpAdmin.length === 0, JSON.stringify( stream.wpAdmin ) );
		} else {
			t.check( 'Activity Log still renders on WordPress', true );
		}
	} else {
		t.check( 'no Activity Log nav on this site', true );
	}

	/* ===== Whole-app sweep of remaining wp-admin anchors ===== */
	const bailouts = await collectBailouts();
	if ( boot.engine ) {
		t.check( 'no wp-admin bail-out anchors on the current view',
			bailouts.length === 0,
			JSON.stringify( bailouts.slice( 0, 8 ) ) );
	} else {
		t.check( 'WordPress still has at least one wp-admin deep link on this visit',
			bailouts.length > 0 || tools || preview.count > 0,
			JSON.stringify( bailouts.slice( 0, 5 ) ) );
	}

	await t.done( browser, errors );
} )();
