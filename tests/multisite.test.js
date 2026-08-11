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

		// 5b) Scale: the switcher is CAPPED and offers search past the cap, so
		//     a network with hundreds of sites cannot produce an unusable menu
		//     (or pay per-site work on every page load).
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'overview' );
			const cap = await page.evaluate( () => ( {
				shown: ( window.MINN.sites || [] ).length,
				total: window.MINN.sitesTotal || 0,
			} ) );
			check( 'boot payload caps the switcher list', cap.shown <= 8, `${ cap.shown } shown of ${ cap.total }` );
			if ( cap.total > cap.shown ) {
				const btnBox = await page.evaluate( () => {
					const r = document.querySelector( '#minn-site-switch' ).getBoundingClientRect();
					return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
				} );
				await page.mouse.click( btnBox.x, btnBox.y );
				await page.waitForTimeout( 400 );
				const searchBox = await page.evaluate( () => {
					const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => /Search all/.test( x.textContent ) );
					if ( ! btn ) return null;
					const r = btn.getBoundingClientRect();
					return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
				} );
				check( 'a capped menu offers "Search all N sites…"', !! searchBox );
				if ( searchBox ) {
					await page.mouse.click( searchBox.x, searchBox.y );
					await page.waitForSelector( '#minn-sp-q', { timeout: 8000 } );
					await page.waitForTimeout( 1500 );
					// Typing must not destroy the field it is typed into: the
					// modal re-renders on the server response, so this pins
					// value, caret and focus after a full word.
					await page.click( '#minn-sp-q' );
					await page.keyboard.type( 'team1' );
					await page.waitForTimeout( 1200 );
					const typed = await page.evaluate( () => {
						const i = document.querySelector( '#minn-sp-q' );
						const rows = [ ...document.querySelectorAll( '.minn-sp-row' ) ].filter( ( r ) => ! r.hidden );
						return {
							value: i.value,
							caret: i.selectionStart,
							focused: document.activeElement === i,
							names: rows.map( ( r ) => r.querySelector( '.minn-sp-name' ).textContent.trim() ),
						};
					} );
					check( 'the picker keeps every keystroke', typed.value === 'team1', JSON.stringify( typed.value ) );
					check( 'the picker keeps focus and caret while searching', typed.focused && typed.caret === 5 );
					check( 'the picker filters to matching sites', typed.names.length > 0 && typed.names.every( ( n ) => /team ?1/i.test( n ) ), typed.names.join( ', ' ) );
					await page.keyboard.press( 'Escape' );
				}
			} else {
				check( 'lab has enough sites to exercise the cap', false, `${ cap.total } total` );
			}
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5c) Network group: the Sites surface, its guards, and the fact that
		//     it does not exist for a site administrator.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'network-sites' );
			await page.waitForSelector( '#minn-view [data-sitem]', { timeout: 15000 } );
			const sites = await page.evaluate( () => ( {
				navGroup: !! document.querySelector( '#minn-navgrp-network:not([hidden])' ),
				navItems: [ ...document.querySelectorAll( '#minn-navgrp-network .minn-nav-btn' ) ].map( ( b ) => b.textContent.trim() ),
				rows: document.querySelectorAll( '#minn-view [data-sitem]' ).length,
				tabs: [ ...document.querySelectorAll( '#minn-view .minn-tab' ) ].map( ( t ) => t.textContent.trim() ),
				status: !! document.querySelector( '#minn-view' ).textContent.match( /Subdomain network|Subdirectory network/ ),
			} ) );
			check( 'Network nav group renders for a super admin', sites.navGroup && sites.navItems.includes( 'Sites' ), sites.navItems.join( ', ' ) );
			check( 'Sites lists the network', sites.rows > 1, `${ sites.rows } rows` );
			check( 'Sites offers status tabs', sites.tabs.some( ( t ) => /Archived/.test( t ) ) && sites.tabs.some( ( t ) => /Spam/.test( t ) ), sites.tabs.join( ' | ' ) );
			check( 'Sites shows the network status card', sites.status );

			// Row verbs: an ordinary site offers the lifecycle; the MAIN site
			// never offers archive, spam or delete (its row menu is read-only).
			const rowMenu = async ( match ) => {
				await page.evaluate( () => document.querySelectorAll( '.minn-ctx-menu' ).forEach( ( m ) => m.remove() ) );
				const ok = await page.evaluate( ( mm ) => {
					const row = [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].find( ( r ) => new RegExp( mm ).test( r.textContent ) );
					if ( ! row ) return false;
					const more = row.querySelector( '.minn-row-more' );
					if ( ! more ) return false;
					more.click();
					return true;
				}, match );
				if ( ! ok ) return null;
				await page.waitForTimeout( 500 );
				return page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu > *' ) ].map( ( b ) => b.textContent.trim() ) );
			};
			const mainName = await page.evaluate( () => {
				const s = ( window.MINN.sites || [] ).find( ( x ) => x.id === 1 );
				return s ? s.name : '';
			} );
			const ordinary = await rowMenu( 'Team' );
			check( 'an ordinary site offers the lifecycle verbs',
				ordinary && ordinary.some( ( e ) => /Archive site/.test( e ) ) && ordinary.some( ( e ) => /Delete site/.test( e ) ),
				( ordinary || [] ).join( ' | ' ) );
			if ( mainName ) {
				const main = await rowMenu( mainName.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );
				check( 'the main site offers NO archive, spam or delete',
					main && ! main.some( ( e ) => /(Archive site|Mark as spam|Delete site)/.test( e ) ),
					( main || [] ).join( ' | ' ) );
			}
			await page.keyboard.press( 'Escape' );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5c2) Network users: the list, and the two guards that matter — a
		//      network administrator is never offered "make one", and the
		//      person doing the looking can never revoke their own status.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'network-users' );
			await page.waitForSelector( '#minn-view [data-sitem]', { timeout: 15000 } );
			const nu = await page.evaluate( () => ( {
				navItems: [ ...document.querySelectorAll( '#minn-navgrp-network .minn-nav-btn' ) ].map( ( b ) => b.textContent.trim() ),
				rows: document.querySelectorAll( '#minn-view [data-sitem]' ).length,
				supers: [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].filter( ( r ) => /network admin/.test( r.textContent ) ).length,
				tabs: [ ...document.querySelectorAll( '#minn-view .minn-tab' ) ].map( ( t ) => t.textContent.trim() ),
			} ) );
			check( 'Network group carries a users surface', nu.navItems.some( ( n ) => /users/i.test( n ) ), nu.navItems.join( ', ' ) );
			check( 'network users lists every account', nu.rows > 1, `${ nu.rows } rows` );
			check( 'network administrators are marked', nu.supers >= 1 );
			check( 'network users offers an admins tab', nu.tabs.some( ( t ) => /admin/i.test( t ) ), nu.tabs.join( ' | ' ) );

			const rowMenu = async ( pick ) => {
				await page.evaluate( () => document.querySelectorAll( '.minn-ctx-menu' ).forEach( ( m ) => m.remove() ) );
				const ok = await page.evaluate( ( wantSuper ) => {
					const rows = [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ];
					const row = rows.find( ( r ) => /network admin/.test( r.textContent ) === wantSuper );
					if ( ! row ) return false;
					const more = row.querySelector( '.minn-row-more' );
					if ( ! more ) return false;
					more.click();
					return true;
				}, pick );
				if ( ! ok ) return null;
				await page.waitForTimeout( 500 );
				return page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu > *' ) ].map( ( b ) => b.textContent.trim() ) );
			};
			const member = await rowMenu( false );
			check( 'an ordinary account can be promoted', member && member.some( ( e ) => /Make network administrator/.test( e ) ), ( member || [] ).join( ' | ' ) );
			const superRow = await rowMenu( true );
			check( 'a network administrator is not offered promotion again',
				superRow && ! superRow.some( ( e ) => /Make network administrator/.test( e ) ), ( superRow || [] ).join( ' | ' ) );
			check( 'the only network administrator cannot be demoted here',
				superRow && ! superRow.some( ( e ) => /Remove network administrator/.test( e ) ), ( superRow || [] ).join( ' | ' ) );

			// The server refuses a self-revoke even when asked directly.
			const direct = await page.evaluate( async () => {
				const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/network/users/' + window.MINN.user.id + '/super', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { on: false } ),
				} );
				const j = await r.json();
				return { status: r.status, code: j.code };
			} );
			check( 'the server refuses a self-revoke', direct.status === 400 && direct.code === 'cannot_revoke_self', JSON.stringify( direct ) );
			await page.keyboard.press( 'Escape' );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5c3) Network activation from Extensions: a separate switch from this
		//      site's own, and Minn never offers to pull itself out from under
		//      the person using it.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'extensions' );
			await page.waitForSelector( '.minn-plugin', { timeout: 15000 } );
			const caps = await page.evaluate( () => ( {
				plugins: !! window.MINN.caps.networkPlugins,
				themes: !! window.MINN.caps.networkThemes,
			} ) );
			check( 'super admin gets network activation capabilities', caps.plugins && caps.themes );

			const cardMenu = async ( name ) => {
				await page.evaluate( () => document.querySelectorAll( '.minn-ctx-menu' ).forEach( ( m ) => m.remove() ) );
				const pt = await page.evaluate( ( n ) => {
					const card = [ ...document.querySelectorAll( '.minn-plugin' ) ].find( ( c ) => ( c.querySelector( '.minn-plugin-name' )?.textContent || '' ).includes( n ) );
					if ( ! card ) return null;
					const b = card.getBoundingClientRect();
					return { x: b.x + b.width / 2, y: b.y + 40 };
				}, name );
				if ( ! pt ) return null;
				await page.mouse.click( pt.x, pt.y, { button: 'right' } );
				await page.waitForTimeout( 450 );
				return page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu > *' ) ].map( ( b ) => b.textContent.trim() ) );
			};
			const perSite = await cardMenu( 'WooCommerce' );
			check( 'a per-site plugin can be activated network-wide',
				perSite && perSite.some( ( e ) => /Activate for the whole network/.test( e ) ), ( perSite || [] ).join( ' | ' ) );
			await page.keyboard.press( 'Escape' );
			const self = await cardMenu( 'Minn Admin' );
			check( 'Minn never offers to deactivate ITSELF network-wide',
				self && ! self.some( ( e ) => /Deactivate across the network/.test( e ) ), ( self || [] ).join( ' | ' ) );
			await page.keyboard.press( 'Escape' );

			// The server refuses it too, not just the menu.
			const direct = await page.evaluate( async () => {
				const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/network/plugins/activate', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { plugin: 'minn-admin/minn-admin.php', on: false } ),
				} );
				return { status: r.status, code: ( await r.json() ).code };
			} );
			check( 'the server refuses a network self-deactivation', direct.status === 400 && direct.code === 'self', JSON.stringify( direct ) );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5c4) Network settings: a settings-only surface (no list), a real
		//      save round trip, and the refusal to write anything outside its
		//      own spec.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'network-settings' );
			await page.waitForTimeout( 2500 );
			const ns = await page.evaluate( () => ( {
				tabs: [ ...document.querySelectorAll( '#minn-view [data-ssettab]' ) ].map( ( t ) => t.textContent.trim() ),
				controls: document.querySelectorAll( '#minn-view input, #minn-view select, #minn-view textarea, #minn-view .minn-switch' ).length,
				broke: /Something went wrong/.test( document.querySelector( '#minn-view' ).textContent ),
			} ) );
			check( 'network settings renders as its own page', ! ns.broke && ns.controls > 0, `${ ns.controls } controls` );
			check( 'network settings offers its sections', ns.tabs.length >= 3, ns.tabs.join( ' | ' ) );

			// Round trip through the endpoint the form posts to, then restore.
			const trip = await page.evaluate( async () => {
				const post = async ( value ) => {
					const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/network/settings/registration', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						credentials: 'same-origin',
						body: JSON.stringify( { values: { registration: value } } ),
					} );
					return ( await r.json() ).values;
				};
				const before = ( await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/network/settings/registration', {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ) ).json() ).values.registration;
				const saved = await post( 'user' );
				// An unknown key must be ignored rather than written anywhere.
				const r2 = await fetch( window.MINN.restUrl + 'minn-admin/v1/network/settings/registration', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { values: { registration: 'not-a-real-value', siteurl: 'http://evil.test' } } ),
				} );
				const after = ( await r2.json() ).values.registration;
				await post( before );
				return { saved: saved.registration, afterBogus: after, restored: before };
			} );
			check( 'a network setting saves', trip.saved === 'user', JSON.stringify( trip ) );
			check( 'an out-of-vocabulary value is refused', trip.afterBogus === 'user', JSON.stringify( trip ) );
			allErrors.push( ...errors );
			await ctx.close();
		}

		// 5d) THE AUTHORIZATION MATRIX. A site administrator holds
		//     manage_options on their own subsite, which is the realistic
		//     attacker on a network: every network route must refuse them,
		//     and nothing may change as a result. This is the durable form of
		//     the Phase 3 security audit — extend it whenever a network route
		//     is added, or the route ships unproven.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, STORE, SUBSITE_ADMIN.user, SUBSITE_ADMIN.pass );
			await gotoRoute( page, STORE, 'overview' );
			const denied = await page.evaluate( async () => {
				const call = async ( method, path, body ) => {
					const r = await fetch( window.MINN.restUrl + path, {
						method,
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						credentials: 'same-origin',
						body: body ? JSON.stringify( body ) : undefined,
					} );
					return { path: method + ' ' + path.replace( 'minn-admin/v1/network/', '' ), status: r.status };
				};
				const me = window.MINN.user.id;
				const probes = await Promise.all( [
					call( 'GET', 'minn-admin/v1/network/sites' ),
					call( 'POST', 'minn-admin/v1/network/sites', { address: 'pwned', title: 'P', email: 'admin@minnms.localhost' } ),
					call( 'POST', 'minn-admin/v1/network/sites/3/flag', { flag: 'archived', on: true } ),
					call( 'DELETE', 'minn-admin/v1/network/sites/3' ),
					call( 'GET', 'minn-admin/v1/network/status' ),
					call( 'GET', 'minn-admin/v1/network/users' ),
					call( 'GET', 'minn-admin/v1/network/users/status' ),
					call( 'POST', 'minn-admin/v1/network/users/' + me + '/super', { on: true } ),
					call( 'POST', 'minn-admin/v1/network/plugins/activate', { plugin: 'woocommerce/woocommerce.php', on: true } ),
					call( 'POST', 'minn-admin/v1/network/themes/enable', { theme: 'twentytwentyfour', on: true } ),
					call( 'GET', 'minn-admin/v1/network/settings/registration' ),
					call( 'POST', 'minn-admin/v1/network/settings/registration', { values: { registration: 'all' } } ),
					call( 'POST', 'minn-admin/v1/network/settings/sites', { values: { admin_email: 'attacker@evil.test' } } ),
				] );
				const grp = document.querySelector( '#minn-navgrp-network' );
				return { navHidden: ! grp || grp.hidden, probes };
			} );
			check( 'site administrator sees no Network group', denied.navHidden );
			const allowed = denied.probes.filter( ( p ) => p.status < 400 );
			check( 'EVERY network route refuses a site administrator',
				allowed.length === 0,
				allowed.length ? allowed.map( ( p ) => `${ p.path } → ${ p.status }` ).join( '; ' ) : `${ denied.probes.length } routes refused` );
			// The refusals above are the point of the check, not app errors,
			// so this context's console output stays out of the error gate.
			await ctx.close();
		}

		// 5e) The control for 5d: the same reads MUST work for the network
		//     administrator. A permission wall that also blocks the person it
		//     exists for is a regression, not a fix.
		{
			const { ctx, page, errors } = await ctxFor( browser );
			await loginApp( page, MAIN, SUPER_USER, SUPER_PASS );
			await gotoRoute( page, MAIN, 'overview' );
			const allowed = await page.evaluate( async () => {
				const get = async ( path ) => {
					const r = await fetch( window.MINN.restUrl + path, {
						headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
					} );
					return { path, status: r.status };
				};
				return Promise.all( [
					get( 'minn-admin/v1/network/sites' ),
					get( 'minn-admin/v1/network/status' ),
					get( 'minn-admin/v1/network/users' ),
					get( 'minn-admin/v1/network/users/status' ),
					get( 'minn-admin/v1/network/settings/registration' ),
					get( 'minn-admin/v1/my-sites' ),
				] );
			} );
			const blocked = allowed.filter( ( p ) => p.status >= 400 );
			check( 'the network administrator is not over-blocked',
				blocked.length === 0,
				blocked.length ? blocked.map( ( p ) => `${ p.path } → ${ p.status }` ).join( '; ' ) : `${ allowed.length } routes allowed` );
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
