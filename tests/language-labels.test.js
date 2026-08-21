/**
 * Language naming and removal follow-through (GH #45, #46, #47).
 *
 * A locale's human name came only from WordPress's cached list of
 * downloadable translations. That cache legitimately expires, and when it had,
 * every installed language showed as a bare locale code ("pl_PL") while the
 * pending-updates list beside it still showed a name, because only that list
 * had a browser-side fallback. Both now share one resolver.
 *
 * Removing a language also left it listed under packs ready to update: the
 * plugin and theme caches were cleared before re-asking, but core's were not,
 * and core skips its check when it ran in the last minute (which this admin
 * makes it do constantly).
 */
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8' } ).trim();

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'language-labels' );
	await login( page );

	// Pick an installed locale that isn't the site language.
	const installed = wp( [ 'core', 'language', 'list', '--status=installed', '--field=language' ] )
		.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );
	const site = wp( [ 'option', 'get', 'WPLANG' ] ) || 'en_US';
	const victim = installed.find( ( l ) => l !== site && l !== 'en_US' );
	t.check( 'the site has an installed language to inspect', !! victim, JSON.stringify( { installed: installed.length, site } ) );
	if ( ! victim ) {
		await t.done( browser, errors );
		return;
	}

	/* ===== The cold-cache case: no catalog, so the server can only send codes ===== */
	wp( [ 'transient', 'delete', 'available_translations', '--network' ] );
	wp( [ 'transient', 'delete', 'available_translations' ] );

	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.click( '[data-xtab="translations"]' ).catch( () => {} );
	await page.waitForSelector( '.minn-lang-row', { timeout: 25000 } );
	await page.waitForTimeout( 500 );

	const rows = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-lang-row' ) ].map( ( r ) => ( {
		locale: r.dataset.langRow,
		name: ( r.querySelector( '.minn-lang-name' ) || {} ).textContent?.trim(),
		meta: ( r.querySelector( '.minn-lang-meta code' ) || {} ).textContent?.trim(),
	} ) ) );
	const row = rows.find( ( r ) => r.locale === victim ) || rows[ 0 ];
	t.check( 'an installed language is listed', !! row, JSON.stringify( rows.slice( 0, 4 ) ) );
	t.check( 'its heading is a language name, not the locale code', row && row.name && row.name !== row.locale, JSON.stringify( row ) );
	t.check( 'the locale code still appears as the secondary line', row && row.meta === row.locale, JSON.stringify( row ) );

	/* ===== The same name in the site-language selector (#47) ===== */
	await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-combo="minn_language"] .minn-ac-input', { timeout: 25000 } );
	await page.click( '[data-combo="minn_language"] .minn-ac-input' );
	await page.waitForSelector( '[data-combo="minn_language"] .minn-ac-item', { timeout: 10000 } );
	const opts = await page.evaluate( () => [ ...document.querySelectorAll( '[data-combo="minn_language"] .minn-ac-item' ) ]
		.map( ( i ) => ( { value: i.dataset.acv, label: i.textContent.trim() } ) ) );
	const pick = opts.find( ( o ) => o.value === victim );
	t.check( 'the selector offers the installed language', !! pick, JSON.stringify( opts.slice( 0, 6 ) ) );
	t.check( 'the selector names it rather than showing its code', !! pick && !! pick.label && pick.label !== victim, JSON.stringify( pick ) );
	await page.keyboard.press( 'Escape' ).catch( () => {} );

	/* ===== Removing a language clears it from packs ready to update (#45) =====
	   Seed core's transient with a pending pack for the victim, the state a
	   real site is in when an update is waiting, then remove and re-read. */
	const seeded = wp( [ 'eval', `
		$t = get_site_transient( 'update_core' );
		if ( ! is_object( $t ) ) { $t = new stdClass(); }
		$t->last_checked = time();
		$t->translations = array( array(
			'type' => 'core', 'slug' => 'default', 'language' => '${ victim }',
			'version' => '9.9', 'updated' => '2026-01-01 00:00:00', 'package' => 'https://example.invalid/x.zip',
			'autoupdate' => true,
		) );
		set_site_transient( 'update_core', $t );
		$p = wp_get_translation_updates();
		echo count( array_filter( $p, function ( $u ) { return '${ victim }' === $u->language; } ) );
	` ] );
	t.check( 'a pending pack is waiting for that language', seeded === '1', seeded );

	const removal = await page.evaluate( async ( loc ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/translations/remove', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { locale: loc } ),
		} );
		return { status: r.status, body: await r.json() };
	}, victim );
	t.check( 'the language is removed', removal.status === 200, JSON.stringify( removal.status ) );
	const stillPending = ( removal.body.groups || [] ).some( ( g ) => g.locale === victim );
	t.check( 'it no longer appears under packs ready to update', ! stillPending,
		JSON.stringify( ( removal.body.groups || [] ).map( ( g ) => g.locale ) ) );
	t.check( 'it no longer appears under installed languages',
		! ( removal.body.languages || [] ).some( ( l ) => l.locale === victim ),
		JSON.stringify( ( removal.body.languages || [] ).map( ( l ) => l.locale ) ) );

	// Put the fixture site back the way it was.
	wp( [ 'language', 'core', 'install', victim ] );
	wp( [ 'eval', "delete_site_transient( 'update_core' );" ] );
	const back = wp( [ 'core', 'language', 'list', '--status=installed', '--field=language' ] ).split( '\n' ).map( ( s ) => s.trim() );
	t.check( 'the removed language is reinstalled for the next run', back.includes( victim ), victim );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
