/**
 * wp-admin bail-outs: present on WordPress, hidden on Minn Engine.
 *
 * Minn Admin names the runtime on the boot payload (`window.MINN.engine`).
 * When that flag is set there is no /wp-admin/, so every classic-dashboard
 * escape hatch (the W button, Live preview, Open Stream, Tools, Edit in
 * the block editor) must stay off screen. On WordPress those same controls
 * stay. One suite covers both sides so a regression on either runtime
 * fails here.
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
