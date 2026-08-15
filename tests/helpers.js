/**
 * Shared harness for Minn Admin's browser tests.
 *
 * Tests are plain node scripts driving a real Chrome via playwright-core —
 * no test framework, no build step, mirroring the plugin's own architecture.
 * Every test is self-contained: it creates its own posts over REST (using the
 * app's own nonce) and deletes them on the way out.
 *
 * Configuration via environment:
 *   MINN_TEST_URL          base URL of a dev site (default https://minnadmin.localhost)
 *   MINN_TEST_USER         admin username        (default admin)
 *   MINN_TEST_PASS         admin password        (required)
 *   MINN_TEST_WP           WordPress root for wp-cli (default four levels up from tests/)
 *   MINN_TEST_CHROME       Chrome binary path    (default macOS system Chrome)
 *   MINN_TEST_FRESH_LOGIN  set to 1 to skip the shared cookie and form-login
 *   MINN_TEST_AUTH_DIR     where to store Playwright storageState (default tests/.auth)
 */
const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );
const { chromium } = require( 'playwright-core' );

const BASE = process.env.MINN_TEST_URL || 'https://minnadmin.localhost';
const USER = process.env.MINN_TEST_USER || 'admin';
const PASS = process.env.MINN_TEST_PASS || '';
const WP = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const CHROME = process.env.MINN_TEST_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const AUTH_DIR = process.env.MINN_TEST_AUTH_DIR || path.join( __dirname, '.auth' );

function authPath() {
	const key = crypto.createHash( 'sha1' ).update( BASE + '\0' + USER ).digest( 'hex' ).slice( 0, 12 );
	return path.join( AUTH_DIR, key + '.json' );
}

function cookieAlive( cookie ) {
	// Playwright writes session cookies as expires: -1. That is not "already
	// expired" — it means the cookie lasts for the restored context.
	if ( cookie.expires == null || cookie.expires === -1 ) return true;
	return cookie.expires > Date.now() / 1000 + 60;
}

function loadAuthState() {
	if ( process.env.MINN_TEST_FRESH_LOGIN === '1' ) return null;
	try {
		const state = JSON.parse( fs.readFileSync( authPath(), 'utf8' ) );
		const loggedIn = ( state.cookies || [] ).some( ( c ) =>
			/wordpress_logged_in_/.test( c.name ) && cookieAlive( c )
		);
		return loggedIn ? state : null;
	} catch ( e ) {
		return null;
	}
}

async function saveAuthState( ctx ) {
	fs.mkdirSync( AUTH_DIR, { recursive: true } );
	await ctx.storageState( { path: authPath() } );
}

async function launch( opts = {} ) {
	if ( ! PASS ) {
		console.error( 'Set MINN_TEST_PASS (admin password for the dev site).' );
		process.exit( 2 );
	}
	// --disable-http2 matters: Chrome intermittently fails against local dev
	// servers with ERR_INCOMPLETE_CHUNKED_ENCODING over HTTP/2.
	// --disable-features=MacAppCodeSignClone: each launch otherwise leaves a
	// ~1.4GB clone under /var/folders/.../X/com.google.Chrome.code_sign_clone/
	// that often is never cleaned up (agent Playwright runs can pile up 100s of GB).
	const browser = await chromium.launch( {
		executablePath: CHROME,
		args: [
			'--ignore-certificate-errors',
			'--disable-http2',
			'--disable-features=MacAppCodeSignClone',
		],
	} );
	const stored = Object.prototype.hasOwnProperty.call( opts, 'storageState' )
		? opts.storageState
		: loadAuthState();
	const ctx = await browser.newContext( {
		ignoreHTTPSErrors: true,
		...( stored ? { storageState: stored } : {} ),
	} );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		// Resource 404s (test fixtures reference throwaway images) aren't app errors.
		if ( m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	return { browser, page, errors, ctx };
}

async function login( page ) {
	const already = await page.evaluate( () => !!( window.MINN && window.MINN.nonce ) ).catch( () => false );
	if ( already ) return;

	const dest = BASE + '/minn-admin/overview';
	// Reuse a stored wordpress_logged_in_* cookie: skip wp-login AND the
	// default post-login landing on wp-admin (a full dashboard render of
	// every active plugin). The REST nonce is minted on this page load.
	if ( loadAuthState() ) {
		await page.goto( dest, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		if ( ! /wp-login\.php/.test( page.url() ) ) {
			const reused = await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 45000 } )
				.then( () => true )
				.catch( () => false );
			if ( reused ) {
				console.log( '  (reusing stored login cookie)' );
				await saveAuthState( page.context() );
				return;
			}
		}
	}

	await page.goto( BASE + '/wp-login.php?redirect_to=' + encodeURIComponent( dest ), { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	const remember = await page.$( '#rememberme' );
	if ( remember ) await remember.check().catch( () => {} );
	// noWaitAfter: the post-login page (wp-admin, or Minn via redirect_to) can
	// take longer than Playwright's default click-nav timeout on a heavy
	// fixture. Wait for window.MINN instead of the navigation itself.
	await page.click( '#wp-submit', { noWaitAfter: true } );
	const landed = await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } )
		.then( () => true )
		.catch( () => false );
	if ( ! landed ) {
		await page.goto( dest, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
	}
	await saveAuthState( page.context() );
}

/* Log a SECOND account in, in its own context, for role-boundary checks.
 *
 * Two things this does that a hand-rolled form login must not skip. It hands
 * wp-login a redirect_to so the account lands on Minn rather than wp-admin:
 * the dashboard on a site carrying dozens of plugins can take longer to reach
 * DOMContentLoaded than the default 30s navigation timeout allows, which is a
 * timeout in a suite that has nothing to do with what it is testing. And it
 * waits on window.MINN rather than on the navigation alone, so a rejected
 * password fails as a clear timeout here instead of as a puzzling 403 later.
 *
 * Role accounts on the dev site: minn-editor / minn-editor-pass-1 and
 * minn-author / minn-author-pass-1.
 */
async function loginAs( browser, user, pass ) {
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();
	const dest = BASE + '/minn-admin/overview';
	await page.goto( BASE + '/wp-login.php?redirect_to=' + encodeURIComponent( dest ), { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded', timeout: 60000 } ),
		page.click( '#wp-submit' ),
	] );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
	return { ctx, page };
}

// Create a post through the app's own REST credentials. Returns the post ID.
async function createPost( page, { title, content, status = 'draft', ...extra } ) {
	return page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( args ),
		} );
		const j = await r.json();
		if ( ! r.ok ) throw new Error( j.message || 'createPost failed' );
		return j.id;
	}, { title, content, status, ...extra } );
}

async function deletePost( page, id ) {
	if ( ! id ) return;
	await page.evaluate( async ( pid ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?force=true', {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} ).catch( () => {} );
	}, id ).catch( () => {} );
}

// Editor loads occasionally flake right after server-side churn — always retry.
async function openEditor( page, id ) {
	for ( let i = 0; i < 4; i++ ) {
		try {
			await page.goto( `${ BASE }/minn-admin/editor/posts/${ id }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
			await page.waitForTimeout( 800 );
			return;
		} catch ( e ) {
			console.log( '  (editor load retry)' );
			await page.waitForTimeout( 3000 );
		}
	}
	throw new Error( 'editor never loaded for post ' + id );
}

// Append a fresh empty paragraph at the end of the body and put the caret in it.
async function freshParagraph( page ) {
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = document.createElement( 'p' );
		p.appendChild( document.createElement( 'br' ) );
		body.appendChild( p );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		body.focus();
		window.__minnTestPara = p;
	} );
}

// Auto-accepts Minn confirm dialogs (the minnConfirm modal) the way
// page.on('dialog', d => d.accept()) auto-accepts native ones. Runs now and
// survives navigations. Suites asserting confirm behavior (copy, Cancel)
// must NOT call this — interact with .minn-confirm-overlay explicitly.
async function autoConfirm( page ) {
	const arm = () => {
		setInterval( () => {
			const ok = document.querySelector( '.minn-confirm-overlay [data-ok]:not([disabled])' );
			if ( ok ) ok.click();
		}, 120 );
	};
	await page.addInitScript( arm );
	await page.evaluate( arm ).catch( () => {} );
}

// Minimal reporter: PASS/FAIL lines, non-zero exit when anything failed.
function reporter( name ) {
	const results = [];
	return {
		check( label, ok, detail = '' ) {
			results.push( ok );
			console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
		},
		async done( browser, errors ) {
			this.check( 'No console/page errors', errors.length === 0, errors.join( ' | ' ) );
			const failed = results.filter( ( r ) => ! r ).length;
			console.log( `\n${ name }: ${ results.length - failed }/${ results.length } passed` );
			// Close can hang on plugins with long-lived admin connections
			// (Site Kit) — never let it eat a finished run's exit code.
			await Promise.race( [ browser.close(), new Promise( ( r ) => setTimeout( r, 5000 ) ) ] );
			process.exit( failed ? 1 : 0 );
		},
	};
}


/**
 * Pick a value in a Minn combobox. The product page (and the adapter forms)
 * use strict autocompletes instead of native selects, so page.selectOption
 * does not apply: open the panel, then click the item carrying the value.
 */
async function pickCombo( page, inputSel, value ) {
	await page.click( inputSel );
	const item = `.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="${ value }"]`;
	await page.waitForSelector( item, { timeout: 10000 } );
	await page.click( item );
	await page.waitForTimeout( 120 );
}

/** Read a combobox's machine value (its .value is the human label). */
function comboValue( page, inputSel ) {
	return page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		return el ? ( el.dataset.acValue != null ? el.dataset.acValue : el.value ) : null;
	}, inputSel );
}

/** Drive a Minn switch to an explicit state (it is a button, not a checkbox). */
async function setSwitch( page, sel, on ) {
	const cur = await page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		return el ? el.classList.contains( 'on' ) : null;
	}, sel );
	if ( cur === null || cur === on ) return cur !== null;
	await page.click( sel );
	await page.waitForTimeout( 120 );
	return true;
}

function switchOn( page, sel ) {
	return page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		return el ? el.classList.contains( 'on' ) : null;
	}, sel );
}

module.exports = { BASE, WP, launch, login, loginAs, createPost, deletePost, openEditor, freshParagraph, autoConfirm, reporter, pickCombo, comboValue, setSwitch, switchOn, loadAuthState, saveAuthState, authPath };
