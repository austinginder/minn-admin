/**
 * Shared harness for Minn Admin's browser tests.
 *
 * Tests are plain node scripts driving a real Chrome via playwright-core —
 * no test framework, no build step, mirroring the plugin's own architecture.
 * Every test is self-contained: it creates its own posts over REST (using the
 * app's own nonce) and deletes them on the way out.
 *
 * Configuration via environment:
 *   MINN_TEST_URL     base URL of a dev site (default https://minnadmin.localhost)
 *   MINN_TEST_USER    admin username        (default admin)
 *   MINN_TEST_PASS    admin password        (required)
 *   MINN_TEST_CHROME  Chrome binary path    (default macOS system Chrome)
 */
const { chromium } = require( 'playwright-core' );

const BASE = process.env.MINN_TEST_URL || 'https://minnadmin.localhost';
const USER = process.env.MINN_TEST_USER || 'admin';
const PASS = process.env.MINN_TEST_PASS || '';
const CHROME = process.env.MINN_TEST_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function launch() {
	if ( ! PASS ) {
		console.error( 'Set MINN_TEST_PASS (admin password for the dev site).' );
		process.exit( 2 );
	}
	// --disable-http2 matters: Chrome intermittently fails against local dev
	// servers with ERR_INCOMPLETE_CHUNKED_ENCODING over HTTP/2.
	const browser = await chromium.launch( {
		executablePath: CHROME,
		args: [ '--ignore-certificate-errors', '--disable-http2' ],
	} );
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		// Resource 404s (test fixtures reference throwaway images) aren't app errors.
		if ( m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	return { browser, page, errors };
}

async function login( page ) {
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	// Never wait for networkidle here: plugins that poll from wp-admin
	// (Site Kit) keep the network busy forever.
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.click( '#wp-submit' ),
	] );
	// Land in the app so window.MINN (restUrl + nonce) is available to helpers.
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
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

module.exports = { BASE, launch, login, createPost, deletePost, openEditor, freshParagraph, autoConfirm, reporter };
