/**
 * Per-extension auto-update toggles + theme live preview links.
 *
 * Auto-update pills on plugin and theme cards flip core's
 * auto_update_plugins / auto_update_themes site options through
 * minn-admin/v1/auto-updates (the same writes as wp-admin's toggles, with
 * stale entries pruned). Pills render only when core says per-item
 * auto-updates apply (wp_is_auto_update_enabled_for_type) — true on the
 * dev site. Inactive theme cards also carry a Live preview link:
 * Customizer for classic themes, Site Editor preview for block themes.
 *
 * The suite restores every option it touches; the standing
 * gravityforms/gravityforms.php entry (GF's own setting) is never touched.
 *
 * Run: MINN_TEST_PASS=... node auto-updates.test.js
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const rest = ( page, route, opts = {} ) => page.evaluate( async ( a ) => {
	const r = await fetch( window.MINN.restUrl + a.route, {
		method: a.method || 'GET',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
		body: a.body ? JSON.stringify( a.body ) : undefined,
	} );
	return { ok: r.ok, status: r.status, data: await r.json() };
}, { route, ...opts } );

( async () => {
	const t = reporter( 'auto-updates' );
	const { browser, page, errors } = await launch();
	let pluginAsset = null;
	let themeAsset = null;

	try {
		await login( page );

		/* ===== Endpoint state ===== */
		const upd = await rest( page, 'minn-admin/v1/plugin-updates' );
		t.check( 'plugin-updates carries auto + autoAllowed',
			Array.isArray( upd.data.auto ) && upd.data.autoAllowed === true,
			JSON.stringify( { auto: upd.data.auto, allowed: upd.data.autoAllowed } ) );
		const themesRes = await rest( page, 'minn-admin/v1/themes' );
		t.check( 'themes response carries auto_updates + per-item flags',
			themesRes.data.auto_updates === true
			&& themesRes.data.themes.every( ( th ) => typeof th.auto_update === 'boolean' && typeof th.block === 'boolean' ) );

		const badAsset = await rest( page, 'minn-admin/v1/auto-updates', {
			method: 'POST', body: { type: 'plugin', asset: 'not-a-plugin/nope.php', enabled: true },
		} );
		t.check( 'unknown asset refused with 404', badAsset.status === 404 );

		/* ===== Plugin cards ===== */
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin .minn-auto-toggle', { timeout: 15000 } );

		// Pick a card whose pill is OFF and that is not minn-admin itself.
		const pick = await page.evaluate( () => {
			const card = [ ...document.querySelectorAll( '.minn-plugin' ) ].find( ( c ) => {
				const b = c.querySelector( '.minn-auto-toggle' );
				return b && ! b.classList.contains( 'on' ) && ! /minn-admin/.test( c.dataset.plugin );
			} );
			return card ? card.dataset.plugin : null;
		} );
		t.check( 'found a plugin card with an off pill', !! pick );
		pluginAsset = pick + '.php';

		await page.click( `.minn-plugin[data-plugin="${ pick }"] .minn-auto-toggle` );
		await page.waitForFunction( ( sel ) => {
			const b = document.querySelector( sel );
			return b && b.classList.contains( 'on' ) && ! b.disabled;
		}, `.minn-plugin[data-plugin="${ pick }"] .minn-auto-toggle`, { timeout: 10000 } );
		let after = await rest( page, 'minn-admin/v1/plugin-updates' );
		t.check( 'toggle ON stored in auto_update_plugins', after.data.auto.includes( pluginAsset ) );
		t.check( 'GF standing entry untouched', after.data.auto.includes( 'gravityforms/gravityforms.php' ) );

		await page.click( `.minn-plugin[data-plugin="${ pick }"] .minn-auto-toggle` );
		await page.waitForFunction( ( sel ) => {
			const b = document.querySelector( sel );
			return b && ! b.classList.contains( 'on' ) && ! b.disabled;
		}, `.minn-plugin[data-plugin="${ pick }"] .minn-auto-toggle`, { timeout: 10000 } );
		after = await rest( page, 'minn-admin/v1/plugin-updates' );
		t.check( 'toggle OFF removed from auto_update_plugins', ! after.data.auto.includes( pluginAsset ) );
		pluginAsset = null; // round-tripped clean

		/* ===== Theme cards: auto pill + live preview link ===== */
		await page.click( '[data-xtab="themes"]' );
		await page.waitForSelector( '.minn-theme .minn-auto-toggle', { timeout: 15000 } );

		const themeCheck = await page.evaluate( () => {
			const cards = [ ...document.querySelectorAll( '.minn-theme' ) ];
			const activeCard = cards.find( ( c ) => c.classList.contains( 'is-active' ) );
			const inactive = cards.find( ( c ) => ! c.classList.contains( 'is-active' ) );
			const link = inactive && inactive.querySelector( 'a.minn-theme-preview' );
			return {
				activeHasNoPreview: !! activeCard && ! activeCard.querySelector( 'a.minn-theme-preview' ),
				inactiveStylesheet: inactive ? inactive.dataset.stylesheet : null,
				previewHref: link ? link.getAttribute( 'href' ) : null,
				previewTarget: link ? link.getAttribute( 'target' ) : null,
			};
		} );
		t.check( 'active theme card has no preview link', themeCheck.activeHasNoPreview );
		t.check( 'inactive theme card links a live preview',
			!! themeCheck.previewHref
			&& /(customize\.php\?theme=|site-editor\.php\?wp_theme_preview=)/.test( themeCheck.previewHref ),
			themeCheck.previewHref || 'no link' );
		const previewedTheme = ( themesRes.data.themes || [] ).find( ( th ) => th.stylesheet === themeCheck.inactiveStylesheet );
		t.check( 'preview URL matches the theme kind (block vs classic)',
			!! previewedTheme && ( previewedTheme.block
				? /wp_theme_preview=/.test( themeCheck.previewHref )
				: /customize\.php\?theme=/.test( themeCheck.previewHref ) ) );

		themeAsset = themeCheck.inactiveStylesheet;
		const themePill = `.minn-theme[data-stylesheet="${ themeAsset }"] .minn-auto-toggle`;
		await page.click( themePill );
		await page.waitForFunction( ( sel ) => {
			const b = document.querySelector( sel );
			return b && b.classList.contains( 'on' ) && ! b.disabled;
		}, themePill, { timeout: 10000 } );
		let themesAfter = await rest( page, 'minn-admin/v1/themes' );
		t.check( 'theme toggle ON stored', themesAfter.data.themes.some( ( th ) => th.stylesheet === themeAsset && th.auto_update ) );

		await page.click( themePill );
		await page.waitForFunction( ( sel ) => {
			const b = document.querySelector( sel );
			return b && ! b.classList.contains( 'on' ) && ! b.disabled;
		}, themePill, { timeout: 10000 } );
		themesAfter = await rest( page, 'minn-admin/v1/themes' );
		t.check( 'theme toggle OFF removed', themesAfter.data.themes.every( ( th ) => th.stylesheet !== themeAsset || ! th.auto_update ) );
		themeAsset = null;
	} finally {
		// Belt-and-suspenders: undo anything a mid-run failure left behind.
		if ( pluginAsset ) {
			await rest( page, 'minn-admin/v1/auto-updates', {
				method: 'POST', body: { type: 'plugin', asset: pluginAsset, enabled: false },
			} ).catch( () => {} );
		}
		if ( themeAsset ) {
			await rest( page, 'minn-admin/v1/auto-updates', {
				method: 'POST', body: { type: 'theme', asset: themeAsset, enabled: false },
			} ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
