/**
 * Multisite (network) coverage — runs against a Cove subdomain-multisite lab,
 * NOT the single-site minnadmin dev site, so it stands apart from the shared
 * harness (which assumes one site + one admin). SKIPs cleanly (exit 0) when the
 * lab is unreachable, the way the CDN-dependent design suites do — the lab is a
 * disposable fixture (`cove add minnms --multisite=subdomain`), not a standing
 * dependency.
 *
 * What it pins (Phase 1 of multisite support):
 *   - /minn-admin/ boots on the main site AND a subsite created before the
 *     plugin was network-activated (the rewrite self-heal).
 *   - The whole role matrix renders with ZERO console/page errors.
 *   - A network-activated plugin shows as "Network active" with no switch.
 *   - A subsite admin sees the whole site's user list (not a one-row "list"),
 *     can add an existing network account and remove a member, and gets no
 *     role/removal controls against a network administrator's row.
 *   - The System Debug card (network-shared logs) is super-admin only.
 *   - A user with no role on a site is denied that site's Minn entirely.
 *
 * Env overrides (defaults match the documented lab):
 *   MINN_MS_URL         https://minnms.localhost
 *   MINN_MS_STORE       https://store.minnms.localhost   (WooCommerce subsite)
 *   MINN_MS_BLOG        https://blog.minnms.localhost     (pre-activation subsite)
 *   MINN_MS_SUPER_USER  admin
 *   MINN_MS_SUPER_PASS  (required to run; else SKIP)
 */
const { chromium } = require( 'playwright-core' );

const MAIN  = process.env.MINN_MS_URL   || 'https://minnms.localhost';
const STORE = process.env.MINN_MS_STORE || 'https://store.minnms.localhost';
const BLOG  = process.env.MINN_MS_BLOG  || 'https://blog.minnms.localhost';
const SUPER_USER = process.env.MINN_MS_SUPER_USER || 'admin';
const SUPER_PASS = process.env.MINN_MS_SUPER_PASS || '';
const CHROME = process.env.MINN_TEST_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

// Subsite role accounts seeded by the lab (see project_minn_multisite_lab).
const SUBSITE_ADMIN = { user: 'minnsiteadmin', pass: 'minnms-siteadmin-pass-1' };
const BLOG_EDITOR   = { user: 'minneditor', pass: 'minnms-editor-pass-1' };

const results = [];
function check( label, ok, detail = '' ) {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
}

async function reachable( url ) {
	try {
		const r = await fetch( url, { method: 'GET' } );
		return r.status > 0;
	} catch ( e ) {
		return false;
	}
}

async function ctxFor( browser ) {
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text().slice( 0, 160 ) );
		}
	} );
	return { ctx, page, errors };
}

async function loginApp( page, site, user, pass ) {
	const dest = site + '/minn-admin/overview';
	await page.goto( site + '/wp-login.php?redirect_to=' + encodeURIComponent( dest ), { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded', timeout: 60000 } ),
		page.click( '#wp-submit' ),
	] );
}

async function gotoRoute( page, site, route ) {
	await page.goto( site + '/minn-admin/' + route, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } ).catch( () => {} );
	await page.waitForTimeout( 2500 );
}

( async () => {
	if ( ! SUPER_PASS ) {
		console.log( 'SKIP multisite: set MINN_MS_SUPER_PASS (super-admin password for the lab).' );
		process.exit( 0 );
	}
	if ( ! ( await reachable( MAIN + '/minn-admin/' ) ) ) {
		console.log( `SKIP multisite: lab ${ MAIN } unreachable (cove add minnms --multisite=subdomain to create it).` );
		process.exit( 0 );
	}

	const browser = await chromium.launch( {
		executablePath: CHROME,
		args: [ '--ignore-certificate-errors', '--disable-http2', '--disable-features=MacAppCodeSignClone' ],
	} );
	const allErrors = [];

	try {
		// 1) Super admin on the MAIN site: app boots, multisite flag set.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'overview' );
			const boot = await page.evaluate( () => ( {
				booted: !! window.MINN,
				multisite: !! ( window.MINN && window.MINN.multisite ),
				networkUrl: !! ( window.MINN && window.MINN.site && window.MINN.site.networkAdminUrl ),
			} ) );
			check( 'super admin boots Minn on the main site', boot.booted );
			check( 'boot payload carries the multisite flag', boot.multisite );
			check( 'super admin gets a Network Admin link-out', boot.networkUrl );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 2) The rewrite self-heal: a subsite that existed before network
		//    activation still boots at /minn-admin/ (route present, no 404).
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, BLOG, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, BLOG, 'overview' );
			const booted = await page.evaluate( () => !! window.MINN );
			check( 'pre-activation subsite serves /minn-admin/ (rewrite healed)', booted );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 3) Subsite admin on the STORE subsite: full degraded walk, no errors.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, STORE, SUBSITE_ADMIN.user, SUBSITE_ADMIN.pass );
			await gotoRoute( page, STORE, 'overview' );
			const boot = await page.evaluate( () => ( {
				booted: !! window.MINN,
				caps: window.MINN ? window.MINN.caps : {},
			} ) );
			check( 'subsite admin boots Minn', boot.booted );
			check( 'subsite admin lacks network-only caps (install/core/editCss)',
				boot.booted && ! boot.caps.install && ! boot.caps.core && ! boot.caps.editCss );

			// Extensions: network-activated plugin reads as locked-active.
			await gotoRoute( page, STORE, 'extensions' );
			const ext = await page.evaluate( () => {
				const cards = [ ...document.querySelectorAll( '.minn-plugin' ) ].map( ( c ) => ( {
					name: c.querySelector( '.minn-plugin-name' )?.textContent.trim(),
					label: c.querySelector( '.minn-state-label' )?.textContent.trim(),
					hasSwitch: !! c.querySelector( '.minn-switch' ),
				} ) );
				const minn = cards.find( ( c ) => /Minn Admin/.test( c.name || '' ) );
				return { minn };
			} );
			check( 'network-activated Minn shows a "Network active" label', ext.minn && ext.minn.label === 'Network active', JSON.stringify( ext.minn ) );
			check( 'network-activated Minn has no toggle switch', ext.minn && ! ext.minn.hasSwitch );

			// System: the Debug card (network-shared logs) is hidden.
			await gotoRoute( page, STORE, 'system' );
			const sys = await page.evaluate( () => ( {
				debugCard: !! document.querySelector( '#minn-sys-debug' ),
				logRows: document.querySelectorAll( '.minn-sys-logrow' ).length,
			} ) );
			check( 'subsite admin sees no Debug card (network-shared logs)', ! sys.debugCard && sys.logRows === 0 );

			allErrors.push( ...errors );
			await ctx.close();
		}

		// 4) Users flow as the subsite admin: full list, add-existing, remove,
		//    super-admin row is off limits.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, STORE, SUBSITE_ADMIN.user, SUBSITE_ADMIN.pass );
			await gotoRoute( page, STORE, 'users' );
			await page.waitForSelector( '[data-user]', { timeout: 15000 } );

			const rows0 = await page.evaluate( () => [ ...document.querySelectorAll( '[data-user]' ) ].length );
			check( 'subsite admin sees the whole site user list (not one row)', rows0 >= 2, `${ rows0 } rows` );
			check( 'Add existing user control is present', !! ( await page.$( '#minn-add-existing-user' ) ) );

			// Add BLOG_EDITOR (a network account with no role on store) by email.
			await page.click( '#minn-add-existing-user' );
			await page.waitForSelector( '#minn-uae-who' );
			await page.fill( '#minn-uae-who', 'editor@minnms.localhost' );
			await page.click( '#minn-uae-add' );
			await page.waitForTimeout( 2500 );
			const rows1 = await page.evaluate( () => [ ...document.querySelectorAll( '[data-user]' ) ].map( ( r ) => r.dataset.uname ) );
			check( 'adding an existing account attaches it to the site', rows1.includes( BLOG_EDITOR.user ), rows1.join( ',' ) );

			// Row menu on a normal user offers role + remove; the super-admin
			// row offers neither (only Copy email).
			const menuFor = async ( name ) => {
				await page.reload( { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '[data-user]' );
				await page.waitForTimeout( 1000 );
				const box = await page.evaluate( ( n ) => {
					const r = [ ...document.querySelectorAll( '[data-user]' ) ].find( ( x ) => x.dataset.uname === n );
					if ( ! r ) return null;
					const b = r.getBoundingClientRect();
					return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
				}, name );
				if ( ! box ) return [];
				await page.mouse.click( box.x, box.y, { button: 'right' } );
				await page.waitForTimeout( 500 );
				return page.evaluate( () => [ ...document.querySelectorAll( '.minn-new-menu button, .minn-menu button, [class*="minn"][class*="menu"] button' ) ]
					.map( ( b ) => b.textContent.trim() ).filter( ( t ) => t && t.length < 45 ) );
			};
			const normalMenu = await menuFor( BLOG_EDITOR.user );
			check( 'normal user row offers a role change', normalMenu.some( ( e ) => /Editor|Author|Administrator/.test( e ) ), normalMenu.join( ' | ' ) );
			check( 'normal user row offers Remove from this site', normalMenu.some( ( e ) => /Remove from this site/.test( e ) ) );

			const superMenu = await menuFor( SUPER_USER );
			check( 'network-admin row offers NO role controls', ! superMenu.some( ( e ) => /^(Editor|Author|Administrator|Contributor|Subscriber)/.test( e ) ), superMenu.join( ' | ' ) );
			check( 'network-admin row offers NO Remove', ! superMenu.some( ( e ) => /Remove from this site/.test( e ) ) );

			// Clean up: remove the account we added, back to lab baseline.
			await page.evaluate( async ( name ) => {
				const row = [ ...document.querySelectorAll( '[data-user]' ) ].find( ( x ) => x.dataset.uname === name );
				if ( ! row ) return;
				await fetch( window.MINN.restUrl + 'minn-admin/v1/users/' + row.dataset.user + '/remove', {
					method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => {} );
			}, BLOG_EDITOR.user );

			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5) Site switcher (Phase 2 glue): the super admin's list carries every
		//    site, marks the current one, and a REAL click navigates there.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'overview' );

			const boot = await page.evaluate( () => ( {
				sites: ( window.MINN.sites || [] ).map( ( s ) => s.name ),
				current: ( window.MINN.sites || [] ).filter( ( s ) => s.current ).length,
				hasBtn: !! document.querySelector( '#minn-site-switch' ),
			} ) );
			check( 'switcher lists more than one site', boot.sites.length > 1, boot.sites.join( ', ' ) );
			check( 'exactly one site is marked current', boot.current === 1 );
			check( 'switcher control renders in the sidebar', boot.hasBtn );

			// Open with a real mouse click — a synthetic .click() would pass
			// even on an unhittable control.
			const btnBox = await page.evaluate( () => {
				const r = document.querySelector( '#minn-site-switch' ).getBoundingClientRect();
				return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
			} );
			await page.mouse.click( btnBox.x, btnBox.y );
			await page.waitForTimeout( 400 );
			const menu = await page.evaluate( () => {
				const m = document.querySelector( '.minn-ctx-menu' );
				if ( ! m ) return null;
				const kids = [ ...m.children ];
				return {
					count: kids.length,
					firstIsHeading: kids[ 0 ] && kids[ 0 ].classList.contains( 'minn-new-menu-label' ),
					firstBorder: kids[ 0 ] && getComputedStyle( kids[ 0 ] ).borderTopWidth,
					current: kids.filter( ( el ) => el.classList.contains( 'is-on' ) ).length,
					hasNetwork: kids.some( ( el ) => /Network Admin/.test( el.textContent ) ),
				};
			} );
			check( 'switcher opens a menu', !! menu );
			check( 'menu marks the current site', menu && menu.current === 1 );
			check( 'super admin gets a Network Admin entry', menu && menu.hasNetwork );
			// Regression: a heading that OPENS a menu draws no separator.
			check( 'no stranded divider above the leading heading',
				menu && menu.firstIsHeading && menu.firstBorder === '0px', menu && menu.firstBorder );

			// Real navigation to another site's Minn.
			const target = await page.evaluate( () => {
				const m = document.querySelector( '.minn-ctx-menu' );
				const btn = [ ...m.querySelectorAll( 'button' ) ].find( ( x ) => ! x.classList.contains( 'is-on' ) );
				if ( ! btn ) return null;
				const r = btn.getBoundingClientRect();
				return { x: r.x + r.width / 2, y: r.y + r.height / 2, name: btn.textContent.trim() };
			} );
			if ( target ) {
				await page.mouse.click( target.x, target.y );
				await page.waitForTimeout( 4000 );
				const landed = await page.evaluate( () => ( {
					url: location.href,
					name: window.MINN && window.MINN.site ? window.MINN.site.name : '',
				} ) );
				check( 'clicking a site opens that site\'s Minn', /\/minn-admin\/?$/.test( landed.url ) && landed.name === target.name,
					`${ landed.url } (${ landed.name })` );
			} else {
				check( 'a non-current site was offered to click', false );
			}
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 6) The switcher HIDES for a user who belongs to only one site —
		//    a menu with nothing to switch to is worse than no control.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, STORE, SUBSITE_ADMIN.user, SUBSITE_ADMIN.pass );
			await gotoRoute( page, STORE, 'overview' );
			const one = await page.evaluate( () => ( {
				sites: ( window.MINN.sites || [] ).length,
				hasBtn: !! document.querySelector( '#minn-site-switch' ),
			} ) );
			check( 'single-site member gets no switcher', one.sites === 0 && ! one.hasBtn );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 7) Cross-site denial: the blog editor has no role on store.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, STORE, BLOG_EDITOR.user, BLOG_EDITOR.pass );
			await page.goto( STORE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
			await page.waitForTimeout( 2500 );
			const denied = await page.evaluate( () => ( {
				booted: !! window.MINN,
				text: document.body ? document.body.innerText.slice( 0, 120 ) : '',
			} ) );
			check( 'user with no role on the site is denied Minn there', ! denied.booted, denied.text.replace( /\n+/g, ' ' ) );
			// A clean 403 here is expected — do NOT fold this context's errors
			// into the zero-error gate (the denial fetch logs a 403).
			await ctx.close();
		}
	} catch ( e ) {
		check( 'suite ran without throwing', false, e.message.split( '\n' )[ 0 ] );
	}

	check( 'No console/page errors across the walk', allErrors.length === 0, [ ...new Set( allErrors ) ].slice( 0, 8 ).join( ' | ' ) );
	const failed = results.filter( ( r ) => ! r ).length;
	console.log( `\nmultisite: ${ results.length - failed }/${ results.length } passed` );
	await Promise.race( [ browser.close(), new Promise( ( r ) => setTimeout( r, 5000 ) ) ] );
	process.exit( failed ? 1 : 0 );
} )();
