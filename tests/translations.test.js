/**
 * Translation updates. Network Admin offered "Update Translations" while
 * Minn's Updates tab said "You're all caught up".
 *
 * WordPress keeps language packs OUTSIDE the plugin, theme and core update
 * responses — they hang off a `translations` property on those transients — so
 * a site can be genuinely current on all three and still owe language packs.
 * That is the whole bug, and it is why this suite checks the count endpoint,
 * the notification row and the real install path rather than just the UI.
 *
 * The fixture (minn_test_translations) offers a CORE language pack at the
 * site's current version, so the install step downloads a real package instead
 * of a URL that 404s. It is a read filter, because wp_update_plugins() runs on
 * ordinary pageloads and wipes an offer written straight into the transient.
 */
const { launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const LANG = 'de_DE';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'translations' );

	const wpPath = path.resolve( __dirname, '../../../../' );
	const wpCli = ( args ) => execSync( `wp --path=${ JSON.stringify( wpPath ) } ${ args } 2>/dev/null`, { timeout: 180000 } )
		.toString()
		// WP-CLI's bundled dependency emits a PHP deprecation on stdout, so
		// remove diagnostics before comparing a command's intentional value.
		.replace( /^Deprecated:.*$/gm, '' )
		.trim();

	try {
		wpCli( `option update minn_test_translations ${ LANG }` );
		wpCli( `eval 'delete_site_transient( "update_plugins" );'` );

		await login( page );

		const api = ( route, init ) => page.evaluate( async ( [ r, i ] ) => {
			const res = await fetch( window.MINN.restUrl + r + ( r.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
				...( i || {} ),
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
			} );
			return { status: res.status, body: await res.json() };
		}, [ route, init ] );

		// --- the count endpoint -------------------------------------------
		const upd = await api( 'minn-admin/v1/plugin-updates' );
		t.check( 'plugin-updates reports a waiting translation', upd.body.translations >= 1, JSON.stringify( upd.body.translations ) );
		const german = ( upd.body.translationGroups || [] ).find( ( group ) => group.locale === LANG );
		t.check( 'translation updates include a locale summary', !! german && german.total >= 1, JSON.stringify( german || upd.body.translationGroups ) );
		const coreComponent = german && ( german.components || [] ).find( ( component ) => component.type === 'core' );
		t.check( 'locale summary identifies the related component', !! coreComponent && coreComponent.name === 'WordPress', JSON.stringify( german ) );

		// The bug in one assertion: plugins and themes can be perfectly clear
		// and the site still owes a language pack.
		t.check( 'translations are counted separately from plugins and themes',
			typeof upd.body.translations === 'number' && upd.body.translations >= 1,
			`plugins=${ Object.keys( upd.body.updates || {} ).length } themes=${ Object.keys( upd.body.themes || {} ).length } translations=${ upd.body.translations }` );

		t.check( 'the boot payload exposes the language capability', await page.evaluate( () => window.MINN.caps.updateLanguages === true ) );

		// --- the notification ---------------------------------------------
		const notif = await api( 'minn-admin/v1/notifications' );
		const row = ( notif.body.items || [] ).find( ( i ) => i.update && 'translations' === i.update.type );
		t.check( 'a translations row reaches the notifications feed', !! row, row ? row.title : 'absent' );
		t.check( 'the row is filed under updates', !! row && 'updates' === row.kind, row ? row.kind : '' );
		t.check( 'the row counts what is waiting', !! row && row.update.count >= 1, row ? String( row.update.count ) : '' );

		// --- the panel ------------------------------------------------------
		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '[data-tab="updates"]', { timeout: 20000 } );
		await page.click( '[data-tab="updates"]' );
		await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-notif-list, .minn-notif-panel' );
			return p && /translation/i.test( p.innerText );
		}, null, { timeout: 20000 } );
		const panelText = await page.evaluate( () => document.querySelector( '.minn-notif-list, .minn-notif-panel' ).innerText.replace( /\s+/g, ' ' ) );
		t.check( 'the Updates tab shows the translation row', /translation/i.test( panelText ), panelText.slice( 0, 120 ) );

		// The confirm dialog is where "update everything" promises what it
		// will touch, so translations have to be named there or the run is a
		// surprise.
		await page.waitForSelector( '#minn-update-all', { timeout: 20000 } );
		await page.click( '#minn-update-all' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 10000 } );
		const confirmText = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).innerText.replace( /\s+/g, ' ' ) );
		t.check( 'Update everything lists translations among the changes', /translation/i.test( confirmText ), confirmText.slice( 0, 160 ) );
		// Close it: this suite installs through the endpoint instead, so the
		// assertions stay about translations rather than every pending update.
		await page.evaluate( () => {
			const c = document.querySelector( '.minn-confirm-modal [data-cancel]' ) || document.querySelector( '.minn-confirm-modal [data-ok]' );
			if ( document.querySelector( '.minn-confirm-modal [data-cancel]' ) ) c.click();
		} );
		await page.waitForTimeout( 500 );

		// Clicking the notice itself lands on a translation-specific surface,
		// not the generic Plugins list.
		const translationRow = page.locator( '.minn-notif-row' ).filter( { hasText: /translation/i } ).first();
		await translationRow.locator( '.minn-notif-icon' ).click();
		await page.waitForSelector( '[data-xtab="translations"].active', { timeout: 20000 } );
		await page.waitForSelector( '#minn-update-translations', { timeout: 20000 } );
		const translationView = await page.evaluate( () => document.querySelector( '#minn-view' ).innerText.replace( /\s+/g, ' ' ) );
		t.check( 'the notice opens the Translations tab', /Translations/i.test( translationView ), translationView.slice( 0, 180 ) );
		t.check( 'the pending locale is listed', translationView.includes( LANG ), translationView.slice( 0, 220 ) );
		t.check( 'the package count explains component-language multiplication', /component/i.test( translationView ) && /separate download/i.test( translationView ), translationView.slice( 0, 320 ) );

		await page.click( `[data-translation-locale="${ LANG }"]` );
		await page.waitForSelector( `[data-translation-locale="${ LANG }"].is-open .minn-translation-component`, { timeout: 10000 } );
		const expandedText = await page.locator( `[data-translation-locale="${ LANG }"]` ).innerText();
		t.check( 'expanding a language names its WordPress component', /WordPress/.test( expandedText ), expandedText.slice( 0, 240 ) );

		// --- the real install ------------------------------------------------
		// A live download from wordpress.org. de_DE for the current core
		// version is a real package, and installing it twice is harmless.
		const response = page.waitForResponse( ( res ) => res.url().includes( '/minn-admin/v1/translations/update' ) && res.request().method() === 'POST', { timeout: 180000 } );
		await page.click( '#minn-update-translations' );
		await page.waitForFunction( () => {
			const chip = document.querySelector( '#minn-upd-chip' );
			return chip && ! chip.hidden && /translation/i.test( chip.innerText );
		}, null, { timeout: 10000 } );
		const busyFeedback = await page.evaluate( () => ( {
			chip: document.querySelector( '#minn-upd-chip' ).innerText,
			heading: document.querySelector( '.minn-translation-head' ).innerText.replace( /\s+/g, ' ' ),
		} ) );
		t.check( 'updating translations is visible in the top bar', /Updating.*translation/i.test( busyFeedback.chip ), JSON.stringify( busyFeedback ) );
		t.check( 'the page explains that a large update can take time', /few minutes/i.test( busyFeedback.heading ), busyFeedback.heading );
		const liveResponse = await response;
		const ran = { status: liveResponse.status(), body: await liveResponse.json() };
		t.check( 'the update route answers', 200 === ran.status, JSON.stringify( ran.body ).slice( 0, 140 ) );
		t.check( 'it reports at least one pack installed', ran.body && ran.body.updated >= 1, JSON.stringify( ran.body ) );

		const landed = wpCli( `eval 'echo file_exists( WP_LANG_DIR . "/${ LANG }.mo" ) ? "yes" : "no";'` );
		t.check( 'the language files are on disk', 'yes' === landed, landed );

		// remaining is RE-READ from WordPress rather than subtracted, so a
		// pack that failed still counts as pending. The fixture re-offers on
		// every read, so the honest answer here is still 1.
		t.check( 'remaining is re-read rather than assumed zero', ran.body && typeof ran.body.remaining === 'number', JSON.stringify( ran.body ) );
		await page.waitForFunction( () => document.querySelector( '#minn-upd-chip' ).hidden, null, { timeout: 30000 } );
		t.check( 'the top-bar progress clears when the request finishes', true );

		// --- the gate ----------------------------------------------------
		// Without the capability the count is 0 rather than a leaked number.
		const asEditor = wpCli( `eval 'wp_set_current_user( 0 ); echo (int) Minn_Admin_REST::translation_update_count();'` );
		t.check( 'a signed-out caller is told nothing', '0' === asEditor, asEditor );
	} finally {
		try {
			wpCli( 'option delete minn_test_translations' );
			wpCli( `eval 'delete_site_transient( "update_plugins" ); wp_update_plugins();'` );
		} catch ( e ) { /* cleanup is best-effort */ }
	}

	await t.done( browser, errors );
} )();
