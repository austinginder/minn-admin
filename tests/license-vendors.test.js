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

		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-licenses', { timeout: 20000 } );

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
			[ 'The Events Calendar Pro', 'missing' ], // ECP has no PUE key seeded
			[ 'Event Tickets Plus', 'unknown' ],       // PUE key, no local validity
			[ 'Kadence Blocks Pro', 'valid' ],         // uplink status = valid
		];
		for ( const [ name, want ] of cases ) {
			const got = await rowState( name );
			t.check( `${ name } → ${ want }`, got && got.state === want, got ? `${ got.state } "${ got.meta }"` : 'row missing' );
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
			return row ? { off: row.classList.contains( 'off' ), note: /not active; activate the theme/.test( row.textContent ) } : null;
		} );
		t.check( 'inactive component rows are dimmed and explained', off && off.off && off.note, JSON.stringify( off ) );

	} finally {
		await seed( 'clear' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
