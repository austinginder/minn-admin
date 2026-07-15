/**
 * Fleet-ranked vendor license readers (adapters/licenses.php). These
 * providers classify from real vendor wp_options shapes, so this suite
 * seeds those exact shapes through the minn-dev-fixtures license-seed
 * route, reads the System page's Licenses card, and asserts each vendor's
 * state pill. The seed route CLEARS the options in the finally block —
 * plain options are not settings-API deletable, so leaving them behind
 * would plant a fake "valid" license on the dev site.
 *
 * The plugins themselves are installed-but-inactive (their action
 * callables only attach while the vendor's code is loaded), so this
 * covers the READ layer — the highest-value, no-network part. Activation
 * plumbing is exercised per-vendor against the live vendor APIs as
 * Austin's manual step, exactly like the wave-1..4 providers.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'license-vendors' );
	const { browser, page, errors } = await launch();
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await login( page );

	const seed = ( mode ) => page.evaluate( async ( m ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/minn-test/license-seed', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { mode: m } ),
		} );
		return r.status;
	}, mode );

	const rowState = ( name ) => page.evaluate( ( n ) => {
		const row = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.find( ( el ) => el.querySelector( '.minn-sys-ext-name' ).textContent.trim().startsWith( n ) );
		if ( ! row ) return null;
		const pill = row.querySelector( '.minn-lic-pill' );
		return {
			state: pill.className.replace( /.*minn-lic-pill\s*/, '' ).trim(),
			meta: ( row.querySelector( '.minn-sys-lic-meta' )?.textContent || '' ).trim(),
		};
	}, name );

	try {
		const seededStatus = await seed( 'seed' );
		t.check( 'seed route accepts admin', seededStatus === 200, String( seededStatus ) );

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="licenses"]', { timeout: 20000 } );
		await page.click( '[data-xtab="licenses"]' );
		await page.waitForSelector( '#minn-sys-licenses .minn-lic-item', { timeout: 20000 } );
		{
			const off = await page.$( '#minn-lic-off-toggle' );
			if ( off && await page.$eval( '#minn-lic-off-toggle', ( el ) => el.getAttribute( 'aria-expanded' ) !== 'true' ) ) {
				await page.click( '#minn-lic-off-toggle' );
				await page.waitForTimeout( 250 );
			}
		}

		// Vendor → expected state. These plugins are all installed, so their
		// detect() fires and the reader classifies the seeded shape.
		const cases = [
			[ 'SearchWP', 'valid' ],
			[ 'GP Premium', 'expired' ],
			[ 'Perfmatters', 'valid' ],
			[ 'WPMU DEV membership', 'valid' ],
			[ 'Smush Pro', 'valid' ],
			[ 'Slider Revolution', 'valid' ],
			[ 'LayerSlider', 'invalid' ],
			[ 'WP All Import Pro', 'valid' ],
			[ 'WP All Export Pro', 'invalid' ],
			[ 'Rank Math SEO PRO', 'valid' ],
			[ 'Avada', 'valid' ],
		];
		for ( const [ name, want ] of cases ) {
			const got = await rowState( name );
			t.check( `${ name } → ${ want }`, got && got.state === want, got ? `${ got.state } "${ got.meta }"` : 'row missing' );
		}

		// The Events Calendar family + Kadence Blocks Pro + Smash Balloon are
		// LIVE fixtures (active plugins, real license machinery; TEC has
		// builds that re-seed embedded keys). States drift with reality.
		// Assert the dedicated providers exist and carry the action set the
		// vendor code allows, never an exact pill.
		const FAMILY = [
			'The Events Calendar Pro',
			'The Events Calendar Community',
			'The Events Calendar Filter Bar',
			'Event Tickets Plus',
			'Kadence Blocks Pro',
			// Smash Balloon (EDD on smashballoon.com; All Plugins key covers each).
			'Instagram Feed Pro',
			'Custom Facebook Feed Pro',
			'YouTube Feed Pro',
			'Custom Twitter Feeds Pro',
			'Social Wall',
			'Reviews Feed Pro',
			'TikTok Feeds Pro',
			'Feed Analytics Pro',
		];
		for ( const name of FAMILY ) {
			const info = await page.evaluate( ( n ) => {
				const row = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
					.find( ( el ) => el.querySelector( '.minn-sys-ext-name' ).textContent.trim().startsWith( n ) );
				if ( ! row ) return null;
				return {
					state: ( row.querySelector( '.minn-lic-pill' ) || { className: '' } ).className.replace( /.*minn-lic-pill\s*/, '' ).trim(),
					off: row.classList.contains( 'off' ),
					buttons: [ ...row.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ),
				};
			}, name );
			const okState = info && [ 'valid', 'invalid', 'expired', 'missing', 'unknown' ].includes( info.state );
			// Active component: activate or verify is on offer. Inactive:
			// nothing beyond Turn on (action callables never attach).
			const okControls = info && ( info.off
				? ! info.buttons.some( ( b ) => [ 'activate', 'deactivate', 'verify' ].includes( b ) )
				: info.buttons.includes( 'verify' ) || info.buttons.includes( 'activate' ) );
			t.check( `${ name } row is live with sane state + controls`, okState && okControls, JSON.stringify( info ) );
		}

		// SearchWP's future expiry surfaces in the meta line.
		const swp = await rowState( 'SearchWP' );
		t.check( 'SearchWP shows its renewal date', swp && /2031-02-03/.test( swp.meta ), swp ? swp.meta : 'no row' );

		// Envato: account token is presence-only (unknown), the failed
		// single-item token is invalid.
		const envAcct = await rowState( 'Envato Market account token' );
		t.check( 'Envato account token is unknown (presence-only)', envAcct && envAcct.state === 'unknown', envAcct ? envAcct.state : 'no row' );
		const envItem = await rowState( 'Fixture Salient' );
		t.check( 'Envato failed item token is invalid', envItem && envItem.state === 'invalid', envItem ? envItem.state : 'no row' );

		// Perfmatters is fingerprinted as EDD (its build ships the SL
		// updater) yet must appear exactly once — the dedicated reader
		// claims the component so the generic sweep yields.
		const perfCount = await page.evaluate( () =>
			[ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.filter( ( el ) => /^Perfmatters/.test( el.querySelector( '.minn-sys-ext-name' ).textContent.trim() ) ).length );
		t.check( 'Perfmatters is not double-counted by the EDD sweep', perfCount === 1, String( perfCount ) );

		// Inactive components carry the dimmed off state + explanation. Use
		// the Avada THEME row: minnadmin's active theme is minn-admin-theme,
		// so Avada is reliably inactive regardless of which vendor plugins
		// happen to be active (Austin toggles plugins live — never assume a
		// specific plugin's active state in an assertion).
		const off = await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( el ) => el.querySelector( '.minn-sys-ext-name' ).textContent.trim().startsWith( 'Avada' ) );
			// With a Turn on button the meta shortens to "not active"; the
			// long explanation only shows when the button can't be offered.
			return row ? {
				off: row.classList.contains( 'off' ),
				note: /not active/.test( row.textContent ),
				affordance: !! row.querySelector( '[data-lic="turnon"]' ) || /activate the theme/.test( row.textContent ),
			} : null;
		} );
		t.check( 'inactive component rows are dimmed and explained', off && off.off && off.note && off.affordance, JSON.stringify( off ) );

	} finally {
		await seed( 'clear' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
