/**
 * Installed languages + removal (Extensions → Translations). WordPress keeps
 * every installed language pack updated forever and ships no way to remove
 * one, so the tab lists what is on disk and offers Remove for the languages
 * nothing uses. The site language and any user's personal locale are locked.
 *
 * The suite installs a throwaway locale (nl_NL by default), removes it through
 * the UI, and verifies the files are gone and the row disappears. It restores
 * the locale afterwards so the dev site's fixture set is unchanged.
 */
const { launch, login, reporter, BASE, WP } = require( './helpers' );
const { execFileSync } = require( 'child_process' );

// A locale to sacrifice: reinstalled in finally, so pick one the dev site
// already carries rather than downloading something new.
const VICTIM = process.env.MINN_TEST_LOCALE || 'nl_NL';
// The locale the site runs in, and the one a user reads in, for the lock
// assertions. Both are installed by the baseline below.
const SITE_LOCALE = 'de_DE';
const USER_LOCALE = 'fr_FR';

// stdio 'pipe' on stderr: deleting a meta key that is already absent is a
// normal no-op here, and its WP-CLI warning would otherwise bury the results.
const wp = ( args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], {
	encoding: 'utf8',
	stdio: [ 'ignore', 'pipe', 'pipe' ],
} ).trim();

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'language-remove' );
	await login( page );

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { ok: r.ok, status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );

	try {
		// Baseline: English site, no personal locales, and every locale this
		// suite names installed. The site language and user locale below were
		// hardcoded on the assumption that de_DE and fr_FR happen to be
		// present, which is true of a site that has been experimented on for
		// years and false of a fresh one — there the user-locale row simply did
		// not exist and the lock could not be asserted. Install them like the
		// victim, so the suite provisions everything it asserts about.
		try { wp( [ 'option', 'delete', 'WPLANG' ] ); } catch ( e ) {}
		try { wp( [ 'user', 'meta', 'delete', 'minn-editor', 'locale' ] ); } catch ( e ) {}
		for ( const loc of [ VICTIM, SITE_LOCALE, USER_LOCALE ] ) {
			try { wp( [ 'language', 'core', 'install', loc ] ); } catch ( e ) {}
		}

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );

		/* ===== The list reports what is installed, all removable ===== */
		const first = await rest( 'GET', 'minn-admin/v1/translations/installed' );
		const victimRow = ( first.data.languages || [] ).find( ( l ) => l.locale === VICTIM );
		t.check( 'installed list includes the victim locale with counts',
			!! victimRow && victimRow.total > 0 && victimRow.removable === true,
			JSON.stringify( victimRow && { locale: victimRow.locale, total: victimRow.total, removable: victimRow.removable } ) );
		t.check( 'no language is locked on an English site with no personal locales',
			( first.data.languages || [] ).every( ( l ) => l.removable ),
			String( ( first.data.languages || [] ).filter( ( l ) => ! l.removable ).length ) );

		/* ===== Site language and a user locale lock their rows ===== */
		wp( [ 'option', 'update', 'WPLANG', SITE_LOCALE ] );
		wp( [ 'user', 'meta', 'update', 'minn-editor', 'locale', USER_LOCALE ] );
		const locked = await rest( 'GET', 'minn-admin/v1/translations/installed' );
		const de = ( locked.data.languages || [] ).find( ( l ) => l.locale === SITE_LOCALE );
		const fr = ( locked.data.languages || [] ).find( ( l ) => l.locale === USER_LOCALE );
		t.check( 'site language is locked with reason "site"',
			!! de && de.removable === false && de.reason === 'site' && de.site === true,
			JSON.stringify( de && { removable: de.removable, reason: de.reason } ) );
		t.check( 'a user\'s locale is locked with reason "users" and a count',
			!! fr && fr.removable === false && fr.reason === 'users' && fr.users === 1,
			JSON.stringify( fr && { removable: fr.removable, reason: fr.reason, users: fr.users } ) );

		/* ===== Protected locales are refused by the endpoint too ===== */
		const refuseSite = await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: SITE_LOCALE } );
		const refuseUser = await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: USER_LOCALE } );
		const refuseEn = await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: 'en_US' } );
		const refuseAbsent = await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: 'xx_ZZ' } );
		t.check( 'site language refused (400 minn_admin_locale_site)',
			refuseSite.status === 400 && refuseSite.data.code === 'minn_admin_locale_site', JSON.stringify( refuseSite.data && refuseSite.data.code ) );
		t.check( 'user locale refused (400 minn_admin_locale_in_use)',
			refuseUser.status === 400 && refuseUser.data.code === 'minn_admin_locale_in_use', JSON.stringify( refuseUser.data && refuseUser.data.code ) );
		t.check( 'en_US refused', refuseEn.status === 400, String( refuseEn.status ) );
		t.check( 'a locale that is not installed is refused, not "removed nothing"',
			refuseAbsent.status === 404, String( refuseAbsent.status ) );

		// Back to the clean baseline so the victim renders with a Remove button.
		wp( [ 'option', 'delete', 'WPLANG' ] );
		wp( [ 'user', 'meta', 'delete', 'minn-editor', 'locale' ] );

		/* ===== The UI renders lock states and Remove buttons ===== */
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
		await page.click( '[data-xtab="translations"]' );
		await page.waitForSelector( `[data-lang-remove="${ VICTIM }"]`, { timeout: 20000 } );
		const beforeRows = await page.evaluate( () => document.querySelectorAll( '.minn-lang-row' ).length );
		t.check( 'the Translations tab lists installed languages with Remove actions', beforeRows > 1, String( beforeRows ) );

		/* ===== Remove through the UI: confirm dialog, then the row goes ===== */
		await page.click( `[data-lang-remove="${ VICTIM }"]` );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 10000 } );
		const dialog = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-confirm-title' ).textContent.trim(),
			keeps: [ ...document.querySelectorAll( '.minn-confirm-scope li' ) ].length,
		} ) );
		t.check( 'confirm names the language and discloses scope',
			dialog.title.includes( 'Remove' ) && dialog.keeps >= 4, JSON.stringify( dialog ) );

		const removeRes = page.waitForResponse( ( r ) =>
			r.url().includes( 'translations/remove' ) && r.request().method() === 'POST' );
		await page.click( '.minn-confirm-modal [data-ok]' );
		const rr = await removeRes;
		t.check( 'remove POST answers 200', rr.status() === 200, String( rr.status() ) );
		const payload = await rr.json().catch( () => ( {} ) );
		t.check( 'the response reports files actually deleted', ( payload.removed || 0 ) > 0, String( payload.removed ) );

		await page.waitForFunction( ( loc ) => ! document.querySelector( `[data-lang-row="${ loc }"]` ), VICTIM, { timeout: 15000 } );
		t.check( 'the row disappears from the list without a reload', true );

		/* ===== The files are really gone (and the cached list agrees) ===== */
		const after = await rest( 'GET', 'minn-admin/v1/translations/installed' );
		t.check( 'the removed locale is gone from a fresh read (registry cache invalidated)',
			! ( after.data.languages || [] ).some( ( l ) => l.locale === VICTIM ),
			JSON.stringify( ( after.data.languages || [] ).map( ( l ) => l.locale ).slice( 0, 4 ) ) );

		const onDisk = wp( [ 'eval', `$n=0; foreach ( array("","/plugins","/themes") as $d ) { foreach ( (array) glob( WP_LANG_DIR . $d . "/*" ) as $f ) { if ( preg_match( '/(^|-)${ VICTIM }(\\.|-)/', basename( $f ) ) ) $n++; } } echo $n;` ] );
		t.check( 'no files for the removed locale remain on disk', onDisk === '0', onDisk );

		const otherIntact = wp( [ 'eval', '$n=0; foreach ( (array) glob( WP_LANG_DIR . "/plugins/*" ) as $f ) { if ( strpos( basename( $f ), "de_DE" ) !== false ) $n++; } echo $n;' ] );
		t.check( 'other locales are untouched by the removal', parseInt( otherIntact, 10 ) > 0, otherIntact );
	} finally {
		// Restore the dev site's fixture state.
		try { wp( [ 'option', 'delete', 'WPLANG' ] ); } catch ( e ) {}
		try { wp( [ 'user', 'meta', 'delete', 'minn-editor', 'locale' ] ); } catch ( e ) {}
		try { wp( [ 'language', 'core', 'install', VICTIM ] ); } catch ( e ) {}
		try { wp( [ 'language', 'plugin', 'install', '--all', VICTIM ] ); } catch ( e ) {}
		try { wp( [ 'language', 'theme', 'install', '--all', VICTIM ] ); } catch ( e ) {}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
