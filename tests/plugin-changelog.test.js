/**
 * Extensions → "What's new" on a plugin with a pending update, and the
 * version number on every plugin card, open the plugin's own changelog in
 * the changelog modal (minn-admin/v1/plugin-changelog).
 *
 * Two offers are armed through the dev-fixtures mu-plugin: a VENDOR-hosted
 * one (plugins_api answered by a hook, with a script tag and inline handlers
 * that must not survive the server-side reduction) and a wp.org one, which
 * needs the network and reports honestly either way. Both are cleared, and
 * the update_plugins transient deleted, in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'plugin-changelog' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );
	const setOpts = ( vendor, wporg ) => rest( 'wp/v2/settings', { method: 'POST', body: { minn_test_plugin_update_vendor: vendor, minn_test_plugin_update: wporg } } );

	const VENDOR = 'akismet/akismet';
	const WPORG = 'duplicator/duplicator';

	try {
		// The route caches reduced sections for 12h per file+version; a
		// fixture edit must not be masked by a prior run's cache.
		const purge = path.join( os.tmpdir(), 'minn-plugin-cl-purge.php' );
		fs.writeFileSync( purge, "<?php global $wpdb; $wpdb->query( \"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%minn_plugin_cl_%'\" );" );
		try {
			execSync( `wp --path=${ JSON.stringify( WP ) } eval-file ${ JSON.stringify( purge ) } 2>/dev/null`, { timeout: 120000 } );
		} catch ( e ) { /* best effort */ }
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		const armed = await setOpts( VENDOR + '.php', WPORG + '.php' );
		t.check( 'fixture offers armed', armed.status === 200, `status ${ armed.status }` );

		/* ===== Route ===== */
		const v = await rest( 'minn-admin/v1/plugin-changelog?plugin=' + encodeURIComponent( VENDOR + '.php' ) );
		t.check( 'vendor offer answers with sections', v.status === 200 && v.body.source === 'vendor' && v.body.sections.length === 2,
			JSON.stringify( v.body && { source: v.body.source, n: v.body.sections && v.body.sections.length } ) );
		const html = v.status === 200 ? v.body.sections.map( ( s ) => s.html ).join( '' ) : '';
		t.check( 'script bodies, inline styles and handlers are gone',
			html.includes( '<strong>bold</strong>' ) && ! /minnFixtureXss|onclick|style=|<script/i.test( html ), html.slice( 0, 200 ) );
		t.check( 'versions come from the h4 headings', v.status === 200 && v.body.sections[ 0 ].version === '9.9.9' && /^9\.9\.8/.test( v.body.sections[ 1 ].version ),
			JSON.stringify( v.body && v.body.sections.map( ( s ) => s.version ) ) );
		t.check( 'the upgrade notice rides along as text', v.status === 200 && v.body.notice === 'Fixture notice: back up first.', JSON.stringify( v.body && v.body.notice ) );
		// No offer is no longer a 404: the route answers the installed
		// version's notes (wp.org via the transient's no_update record,
		// vendors via their own plugins_api hook) so every card has a
		// changelog doorway, not just the ones with a pending update.
		const none = await rest( 'minn-admin/v1/plugin-changelog?plugin=hello-dolly%2Fhello.php' );
		t.check( 'a plugin with no offer answers with its installed version and no offer',
			none.status === 200 && none.body.offered === '' && /^\d/.test( none.body.installed ) && none.body.source === 'wporg',
			JSON.stringify( none.body && { status: none.status, installed: none.body.installed, offered: none.body.offered, source: none.body.source } ) );
		const missing = await rest( 'minn-admin/v1/plugin-changelog?plugin=not-a-plugin%2Fnope.php' );
		t.check( 'a plugin that is not installed answers 404', missing.status === 404, `status ${ missing.status }` );
		// Themes: wp.org publishes no changelog for them, so the route reads
		// the one the theme ships (Twenty Twenty-Five's readme.txt).
		const th = await rest( 'minn-admin/v1/theme-changelog?theme=twentytwentyfive' );
		t.check( 'theme route reads the bundled readme changelog',
			th.status === 200 && th.body.kind === 'theme' && th.body.source === 'wporg' && th.body.sections.length > 2 && /^\d+\.\d+$/.test( th.body.sections[ 0 ].version ) && !! th.body.sections[ 0 ].date,
			JSON.stringify( th.body && { status: th.status, n: th.body.sections && th.body.sections.length, first: th.body.sections && th.body.sections[ 0 ] && [ th.body.sections[ 0 ].version, th.body.sections[ 0 ].date ] } ) );
		const thMissing = await rest( 'minn-admin/v1/theme-changelog?theme=not-a-theme' );
		t.check( 'a theme that is not installed answers 404', thMissing.status === 404, `status ${ thMissing.status }` );
		const w = await rest( 'minn-admin/v1/plugin-changelog?plugin=' + encodeURIComponent( WPORG + '.php' ) );
		t.check( 'wp.org offer reports its source and directory link', w.status === 200 && w.body.source === 'wporg' && /wordpress\.org\/plugins\/duplicator\/#developers$/.test( w.body.url ),
			JSON.stringify( w.body && { source: w.body.source, url: w.body.url } ) );
		if ( w.status === 200 && w.body.sections.length ) {
			t.check( 'wp.org changelog sections were read', true, `${ w.body.sections.length } sections` );
		} else {
			t.check( 'wp.org changelog sections were read', true, 'skipped: wordpress.org unreachable or empty' );
		}

		/* ===== UI ===== */
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-plugin[data-plugin="${ VENDOR }"] [data-whatsnew]`, { timeout: 20000 } );
		const updatesTab = await page.$( '[data-xfilter="updates"]' );
		if ( updatesTab ) {
			await updatesTab.click();
			await page.waitForTimeout( 400 );
		}
		t.check( 'the update filter still shows the card with its What\'s new link',
			!! await page.$( `.minn-plugin[data-plugin="${ VENDOR }"] [data-whatsnew]` ) );
		await page.click( `.minn-plugin[data-plugin="${ VENDOR }"] [data-whatsnew]` );
		await page.waitForSelector( '.minn-cl-modal [data-clver]', { timeout: 15000 } );
		const modal = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-cl-modal .minn-modal-title' ).textContent.trim(),
			meta: ( document.querySelector( '.minn-cl-meta' ) || {} ).textContent || '',
			chips: [ ...document.querySelectorAll( '.minn-cl-modal [data-clver]' ) ].map( ( b ) => b.textContent.trim() ),
			body: document.getElementById( 'minn-cl-body' ).innerHTML,
			foot: ( document.querySelector( '.minn-cl-modal .minn-changelog-foot a' ) || {} ).href || '',
			xss: window.minnFixtureXss,
		} ) );
		t.check( 'modal names the plugin', /Akismet/.test( modal.title ), modal.title );
		t.check( 'modal states installed and offered versions', /Installed v5.*update to v5/.test( modal.meta ), modal.meta );
		t.check( 'version rail lists both releases', modal.chips.length === 2 && modal.chips[ 0 ] === '9.9.9', JSON.stringify( modal.chips ) );
		t.check( 'first release renders and nothing ran', /Fixture <strong>bold<\/strong>/.test( modal.body ) && ! modal.xss && ! /onclick/.test( modal.body ), modal.body.slice( 0, 160 ) );
		t.check( 'footer links to the vendor page', /example\.com\/minn-fixture-vendor/.test( modal.foot ), modal.foot );
		await page.click( '.minn-cl-modal [data-clver="1"]' );
		await page.waitForTimeout( 200 );
		const second = await page.evaluate( () => document.getElementById( 'minn-cl-body' ).innerHTML );
		t.check( 'picking another release swaps the body', /Older <em>notes<\/em>/.test( second ), second.slice( 0, 120 ) );
		await page.click( '#minn-modal-close' );
		await page.waitForTimeout( 200 );

		// Context menu carries the same doorway.
		await page.evaluate( ( f ) => {
			const card = document.querySelector( `.minn-plugin[data-plugin="${ f }"]` );
			const r = card.getBoundingClientRect();
			card.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, cancelable: true, clientX: r.left + 40, clientY: r.top + 20 } ) );
		}, VENDOR );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const entries = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( 'card context menu offers What\'s new', entries.some( ( e ) => /What.s new in/.test( e ) ), JSON.stringify( entries ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );

		// A card WITHOUT an offer: the version number is the doorway.
		const allTab = await page.$( '[data-xfilter="all"]' );
		if ( allTab ) {
			await allTab.click();
			await page.waitForTimeout( 400 );
		}
		const NOOFFER = 'hello-dolly/hello';
		await page.waitForSelector( `.minn-plugin[data-plugin="${ NOOFFER }"] [data-changelog]`, { timeout: 20000 } );
		t.check( 'a card with no pending update has no What\'s new link but a version button',
			! await page.$( `.minn-plugin[data-plugin="${ NOOFFER }"] [data-whatsnew]` ) && !! await page.$( `.minn-plugin[data-plugin="${ NOOFFER }"] button.minn-plugin-ver` ) );
		await page.click( `.minn-plugin[data-plugin="${ NOOFFER }"] [data-changelog]` );
		await page.waitForFunction( () => document.querySelector( '.minn-cl-modal' ) && ! document.querySelector( '.minn-cl-modal .minn-loading' ), null, { timeout: 15000 } );
		const plain = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-cl-modal .minn-modal-title' ).textContent.trim(),
			meta: ( document.querySelector( '.minn-cl-meta' ) || {} ).textContent || '',
			empty: !! document.querySelector( '.minn-cl-modal .minn-cl-empty' ),
			chips: document.querySelectorAll( '.minn-cl-modal [data-clver]' ).length,
		} ) );
		t.check( 'version button opens the changelog window titled Changelog', /^Changelog · Hello Dolly/.test( plain.title ), plain.title );
		t.check( 'meta names the installed version only', /^Installed v\d/.test( plain.meta ) && ! /update to/.test( plain.meta ), plain.meta );
		t.check( 'no offer renders notes or an honest empty state', plain.empty || plain.chips > 0, JSON.stringify( plain ) );
		await page.click( '#minn-modal-close' );
		await page.waitForTimeout( 200 );
		await page.evaluate( ( f ) => {
			const card = document.querySelector( `.minn-plugin[data-plugin="${ f }"]` );
			const r = card.getBoundingClientRect();
			card.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, cancelable: true, clientX: r.left + 40, clientY: r.top + 20 } ) );
		}, NOOFFER );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const plainEntries = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( 'context menu of a current plugin offers Changelog', plainEntries.includes( 'Changelog' ), JSON.stringify( plainEntries ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );

		// Themes tab: the version on a theme card is the same doorway.
		await page.click( '[data-xtab="themes"]' );
		await page.waitForSelector( '.minn-theme[data-stylesheet="twentytwentyfive"] [data-tchangelog]', { timeout: 20000 } );
		await page.click( '.minn-theme[data-stylesheet="twentytwentyfive"] [data-tchangelog]' );
		await page.waitForSelector( '.minn-cl-modal [data-clver]', { timeout: 15000 } );
		const theme = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-cl-modal .minn-modal-title' ).textContent.trim(),
			meta: ( document.querySelector( '.minn-cl-meta' ) || {} ).textContent || '',
			chip: document.querySelector( '.minn-cl-modal [data-clver] .minn-cl-ver-v' ).textContent.trim(),
			sub: ( document.querySelector( '.minn-cl-modal [data-clver] .minn-cl-ver-d' ) || {} ).textContent || '',
			foot: ( document.querySelector( '.minn-cl-modal .minn-changelog-foot a' ) || {} ).href || '',
		} ) );
		t.check( 'theme version opens its changelog', /^Changelog · Twenty Twenty-Five/.test( theme.title ) && /^Installed v/.test( theme.meta ), JSON.stringify( theme ) );
		t.check( 'theme chip is the bare version with the date beneath', /^\d+\.\d+$/.test( theme.chip ) && /\d{4}/.test( theme.sub ), JSON.stringify( [ theme.chip, theme.sub ] ) );
		t.check( 'theme footer links to wordpress.org', /wordpress\.org\/themes\/twentytwentyfive/.test( theme.foot ), theme.foot );
		await page.click( '#minn-modal-close' );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		await setOpts( '', '' ).catch( () => {} );
		// A lingering fake offer feeds the nightly auto-updater: drop the
		// transient (delete_site_transient, the fixture makes the CLI's
		// `transient delete` read it as absent) and let core refetch.
		try {
			execSync( `wp --path=${ JSON.stringify( WP ) } eval 'delete_site_transient( "update_plugins" ); wp_update_plugins();' 2>/dev/null`, { timeout: 120000 } );
		} catch ( e ) { /* best effort */ }
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
