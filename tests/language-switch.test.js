'use strict';
/**
 * Changing your language repaints the app in place — no reload.
 *
 * The thing this actually guards is that a language switch is not ONE change
 * but three, and only the first is obvious:
 *
 *   1. the JED catalog the client renders from,
 *   2. text the SERVER translated before it reached the boot payload (role
 *      names, surface labels) — none of which re-translates on the client,
 *   3. writing direction, which the shell only sets on the initial render.
 *
 * Miss (2) and you get a German page inside an English sidebar. Miss (3) and
 * Persian stays left-to-right until a reload. So the assertions below check
 * the SIDEBAR and the `dir` attribute, not just the view.
 *
 * The no-reload claim is checked by stamping the window and asserting the
 * stamp survives: a reload would clear it.
 *
 * Usage: MINN_TEST_PASS=… node language-switch.test.js
 */
const { chromium } = require( 'playwright-core' );
const { execSync } = require( 'child_process' );

const { BASE, WP } = require( './helpers' );
const PASS = process.env.MINN_TEST_PASS;

if ( ! PASS ) {
	console.log( 'Set MINN_TEST_PASS (admin password for the dev site).' );
	process.exit( 1 );
}

let pass = 0, fail = 0;
const check = ( name, ok, detail ) => {
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ name }${ ok || ! detail ? '' : `\n      ${ detail }` }` );
	ok ? pass++ : fail++;
};

( async () => {
	execSync( `wp --path=${ WP } user meta delete admin locale 2>/dev/null || true` );

	const browser = await chromium.launch( {
		executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		args: [ '--ignore-certificate-errors', '--disable-http2', '--disable-features=MacAppCodeSignClone' ],
	} );
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } } );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => { if ( m.type() === 'error' ) errors.push( 'console: ' + m.text() ); } );

	try {
		await page.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', PASS );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.click( '#wp-submit' ) ] );

		await page.goto( BASE + '/minn-admin/profile', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-pf-lang', { timeout: 15000 } );
		await page.waitForTimeout( 800 );

		// A stamp a reload would wipe. This is the whole point of the feature.
		await page.evaluate( () => { window.__minnNoReload = 'alive'; } );

		const before = await page.evaluate( () => ( {
			dir: document.documentElement.getAttribute( 'dir' ),
			nav: document.querySelector( '#minn-app' ).innerText,
		} ) );

		// Pick German through the real combobox, then save. #minn-pf-lang is
		// the INPUT; the autocomplete state lives on its .minn-ac parent.
		const picked = await page.evaluate( () => {
			// _acSet lives on the .minn-ac wrapper; the chosen value lands on
			// the INPUT's dataset, which is what the save handler reads.
			const input = document.querySelector( '#minn-pf-lang' );
			if ( ! input.parentElement._acSet ) return false;
			input.parentElement._acSet( 'de_DE' );
			return input.dataset.acValue === 'de_DE';
		} );
		check( 'German is selectable in the profile language picker', picked );

		await page.click( '#minn-pf-save' );
		await page.waitForFunction( () => window.MINN && window.MINN.locale === 'de_DE', null, { timeout: 60000 } ).catch( () => null );

		const after = await page.evaluate( () => ( {
			stamp: window.__minnNoReload,
			lang: document.documentElement.getAttribute( 'lang' ),
			dir: document.documentElement.getAttribute( 'dir' ),
			nav: document.querySelector( '#minn-app' ).innerText,
			bootLocale: window.MINN.locale,
		} ) );

		check( 'The page never reloaded', after.stamp === 'alive',
			'window stamp was cleared, so the app reloaded rather than repainting' );
		check( 'window.MINN.locale advanced to de_DE', after.bootLocale === 'de_DE',
			`got ${ JSON.stringify( after.bootLocale ) }` );
		check( '<html lang> follows the new locale', after.lang === 'de-DE',
			`got ${ JSON.stringify( after.lang ) }` );
		check( 'The sidebar repainted into German',
			after.nav !== before.nav && /Einstellungen|Beiträge|Medien/.test( after.nav ),
			'sidebar text did not change — server-translated boot labels went stale' );

		// --- RTL: Persian must flip direction without a reload ---
		await page.evaluate( () => { window.__minnNoReload2 = 'alive'; } );
		const swapped = await page.evaluate( () => {
			const input = document.querySelector( '#minn-pf-lang' );
			if ( ! input.parentElement._acSet ) return false;
			input.parentElement._acSet( 'fa_IR' );
			return input.dataset.acValue === 'fa_IR';
		} );
		check( 'Persian is selectable in the picker', swapped );
		await page.click( '#minn-pf-save' );
		await page.waitForFunction( () =>
			window.MINN && window.MINN.locale === 'fa_IR' && document.documentElement.getAttribute( 'dir' ) === 'rtl',
			null, { timeout: 60000 } ).catch( () => null );

		const rtl = await page.evaluate( () => ( {
			stamp: window.__minnNoReload2,
			dir: document.documentElement.getAttribute( 'dir' ),
		} ) );
		check( 'Persian flips dir to rtl in place', rtl.dir === 'rtl',
			`dir is ${ JSON.stringify( rtl.dir ) }, was ${ JSON.stringify( before.dir ) }` );
		check( 'The RTL flip also avoided a reload', rtl.stamp === 'alive' );

		check( 'No console or page errors', errors.length === 0, errors.slice( 0, 4 ).join( '\n      ' ) );
	} finally {
		execSync( `wp --path=${ WP } user meta delete admin locale 2>/dev/null || true` );
		await browser.close();
	}

	console.log( `\nlanguage-switch: ${ pass }/${ pass + fail } passed` );
	process.exit( fail ? 1 : 0 );
} )();
