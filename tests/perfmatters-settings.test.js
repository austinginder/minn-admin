/**
 * Perfmatters adapter — settings-only surface (the Rung-2 shape over the
 * core WP Settings API).
 *
 * Proves: a surface with `settings` and NO collection is legal and renders
 * its settings view as the whole page (no view switcher); the schema is
 * read from Perfmatters' live Settings API registrations (9 tabs); toggle /
 * select / one-per-line textarea edits save through Perfmatters' own
 * registered sanitizer (the empty-line filter proves the string → array
 * normalization ran); unknown keys never write; bespoke-callback fields
 * surface as a locked count with the wp-admin escape.
 *
 * State: every value this suite touches is restored through the same save
 * route in `finally`. Perfmatters stays active (resident fixture).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'perfmatters-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	const K_EMOJI = 'perfmatters_options::disable_emojis';
	const K_DNS = 'perfmatters_options:preload:dns_prefetch';
	const K_DELAY = 'perfmatters_options:assets:delay_js_behavior';

	const getTab = ( tab ) => page.evaluate( async ( tb ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/perfmatters/settings/' + tb + '?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return r.json();
	}, tab );

	const postTab = ( tab, values ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/perfmatters/settings/' + a.tab, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { values: a.values } ),
		} );
		return { ok: r.ok, body: await r.json() };
	}, { tab, values } );

	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /perfmatters\/settings\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForTimeout( 400 );
		return res.status();
	};

	try {
		/* ===== Boot payload + nav ===== */
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-tools', { state: 'attached', timeout: 20000 } );
		const surf = await page.evaluate( () =>
			( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'perfmatters' ) || null );
		t.check( 'surface in boot payload', !! surf );
		if ( ! surf ) throw new Error( 'Perfmatters surface missing — is the plugin active?' );
		t.check( 'settings-only: no collection in the descriptor', ! surf.collection && !! surf.settings );
		t.check( 'nav shows Performance under Tools', await page.$$eval( '#minn-nav-tools .minn-nav-btn', ( els ) =>
			els.some( ( b ) => /Performance/.test( b.textContent ) ) ) );

		/* ===== The settings view IS the surface ===== */
		await page.goto( `${ BASE }/minn-admin/perfmatters`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-settings', { timeout: 20000 } );
		const tabs = await page.$$eval( '[data-ssettab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'nine schema tabs render', tabs.length === 9, tabs.join( '|' ) );
		t.check( 'no view switcher on a settings-only surface', ! ( await page.$( '.minn-view-switch' ) ) );
		t.check( 'toggles carry Perfmatters help text', await page.$$eval( '.minn-toggle-desc', ( els ) =>
			els.some( ( e ) => /wp-emoji-release\.min\.js/.test( e.textContent ) ) ) );

		/* ===== Toggle save in Perfmatters' own storage shape ===== */
		const emojiBefore = ( await getTab( 'general' ) ).values[ K_EMOJI ];
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-toggle-row' ) ]
				.find( ( r ) => r.querySelector( '.minn-toggle-label' ).textContent.trim() === 'Disable Emojis' );
			row.querySelector( '[data-sset]' ).click();
		} );
		t.check( 'toggle save 200', ( await clickSave() ) === 200 );
		const emojiAfter = ( await getTab( 'general' ) ).values[ K_EMOJI ];
		t.check( 'toggle round-trips flipped', emojiAfter === ! emojiBefore, String( emojiAfter ) );

		/* ===== One-per-line textarea runs Perfmatters' own sanitizer ===== */
		await page.click( '[data-ssettab="preload"]' );
		await page.waitForSelector( `[data-sset="${ K_DNS }"]`, { timeout: 15000 } );
		// A blank line + padding: the round-trip comes back clean ONLY if
		// perfmatters_sanitize_options split, trimmed and filtered the lines
		// (string → array) exactly like its own settings form.
		await page.fill( `[data-sset="${ K_DNS }"]`, '//fonts.googleapis.com\n\n   //example.com   ' );
		t.check( 'textarea save 200', ( await clickSave() ) === 200 );
		const dns = ( await getTab( 'preload' ) ).values[ K_DNS ];
		t.check( 'one-per-line normalized by their sanitizer', dns === '//fonts.googleapis.com\n//example.com', JSON.stringify( dns ) );

		/* ===== Select + locked escape on the JS tab ===== */
		await page.click( '[data-ssettab="js"]' );
		await page.waitForSelector( `[data-sset="${ K_DELAY }"]`, { timeout: 15000 } );
		await page.selectOption( `[data-sset="${ K_DELAY }"]`, 'all' );
		await page.$eval( `[data-sset="${ K_DELAY }"]`, ( el ) => el.dispatchEvent( new Event( 'input', { bubbles: true } ) ) );
		t.check( 'select save 200', ( await clickSave() ) === 200 );
		t.check( 'select round-trips', ( await getTab( 'js' ) ).values[ K_DELAY ] === 'all' );
		t.check( 'bespoke fields surface as locked with wp-admin escape', await page.$$eval( '.minn-panel-locked a', ( els ) =>
			els.some( ( a ) => /page=perfmatters/.test( a.href ) ) ) );

		/* ===== Unknown keys never write ===== */
		const rogue = await postTab( 'general', { 'perfmatters_options::not_a_real_key': 'x', 'active_plugins::0': 'evil' } );
		t.check( 'rogue keys accepted-but-ignored', rogue.ok && ! ( 'perfmatters_options::not_a_real_key' in ( rogue.body.values || {} ) ) );
	} finally {
		await postTab( 'general', { [ K_EMOJI ]: false } ).catch( () => {} );
		await postTab( 'preload', { [ K_DNS ]: '' } ).catch( () => {} );
		await postTab( 'js', { [ K_DELAY ]: '' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
