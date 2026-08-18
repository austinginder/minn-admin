/**
 * Brainstorm Force license family (adapters/licenses.php).
 *
 * minnadmin runs seven real BSF products (Convert Pro, Schema Pro, WP
 * Portfolio, Premium Starter Templates, Spectra Blocks Pro, and the two
 * Ultimate Addons), each licensed with a real key, so this suite is
 * deliberately LIVE-ROBUST: it asserts the shape of the family (a row per
 * registry product, a sane state, the controls that state is allowed to
 * carry) and never an exact pill. Real licenses drift — a key can be
 * deactivated from the vendor's store between runs, and products get licensed
 * and unlicensed by hand between runs.
 *
 * Nothing here calls the Brainstorm Force API: activation is proven
 * against the live service by hand (docs/license-manager.md), and a suite
 * that activated on every run would hammer a real vendor endpoint. The
 * paste form is opened and cancelled, never submitted.
 */
const { launch, login, loginAs, reporter, BASE } = require( './helpers' );

// Every product the registry should carry on this site. Named products
// rather than a count, so a newly installed BSF plugin does not fail the
// suite and a silently DROPPED one does.
const PRODUCTS = [
	'Convert Pro',
	'Schema Pro',
	'WP Portfolio',
	'Premium Starter Templates',
	'Spectra Blocks Pro',
	'Ultimate Addons for Beaver Builder',
	'Ultimate Addons for Elementor',
];

const STATES = [ 'valid', 'expired', 'invalid', 'missing', 'unknown' ];

( async () => {
	const t = reporter( 'bsf-licenses' );
	const { browser, page, errors } = await launch();
	await login( page );

	const licenses = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return { status: r.status, text: await r.text() };
	} );

	try {
		const raw = await licenses();
		t.check( 'licenses endpoint answers', raw.status === 200, String( raw.status ) );
		const rows = JSON.parse( raw.text ).items.filter( ( r ) => String( r.source || '' ).startsWith( 'bsf-' ) );

		// One provider per product: the family used to be a single
		// read-only provider, so a collapse back to one row is a
		// regression, not a fixture drift.
		t.check( 'every BSF product has its own provider row',
			rows.length >= PRODUCTS.length,
			`${ rows.length } rows: ${ rows.map( ( r ) => r.source ).join( ', ' ) }` );

		for ( const name of PRODUCTS ) {
			const row = rows.find( ( r ) => r.name.startsWith( name ) );
			t.check( `${ name } is listed`, !! row, row ? row.source : 'missing' );
		}

		// State vocabulary and the controls each state may carry. Active
		// components offer activation, while deactivate and verify need a
		// stored key to act on. Inactive components can still report their
		// stored state, but the vendor code that supplies those actions is not
		// loaded, so they intentionally expose no license actions.
		let sane = true;
		let detail = '';
		for ( const row of rows ) {
			const can = row.can || [];
			const actionsOk = row.off
				? can.length === 0
				: can.includes( 'activate' ) && ( row.key
					? can.includes( 'deactivate' ) && can.includes( 'verify' )
					: ! can.includes( 'deactivate' ) && ! can.includes( 'verify' ) );
			const ok = STATES.includes( row.state )
				&& actionsOk
				&& ( row.key || row.state === 'missing' );
			if ( ! ok ) {
				sane = false;
				detail = `${ row.name }: state=${ row.state } key=${ row.key } can=${ can.join( '+' ) }`;
			}
		}
		t.check( 'every row carries a sane state and the controls it allows', sane, detail || 'all rows consistent' );

		// A licensed product reports its renewal date. BSF only learns the
		// expiry from a verify (their activation response omits it), so an
		// empty expiry on a valid row is legitimate — assert only that a
		// populated one is a real date or 'lifetime'.
		const badExpiry = rows.find( ( r ) => r.expires && r.expires !== 'lifetime' && ! /^\d{4}-\d{2}-\d{2}$/.test( r.expires ) );
		t.check( 'expiry values are normalized', ! badExpiry, badExpiry ? `${ badExpiry.name }: ${ badExpiry.expires }` : 'ok' );

		// The guardrail that matters most: a stored purchase key must never
		// ride a GET. BSF keys are 32-char hex, so look for the SHAPE
		// rather than hardcoding real keys into the repo.
		const leaked = raw.text.match( /\b[0-9a-f]{32}\b/i );
		t.check( 'no license key is echoed in the licenses response', ! leaked, leaked ? leaked[ 0 ].slice( 0, 6 ) + '…' : 'clean' );

		// Registry rows must name a real component so the card can dim them
		// when the plugin is switched off. The old single provider pointed
		// at a pseudo-component and could never do this, so cross-check the
		// off flags against the real plugin states: a row is off exactly
		// when its plugin is inactive.
		const inactiveDirs = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).filter( ( p ) => p.status !== 'active' )
				.map( ( p ) => p.plugin.split( '/' )[ 0 ] );
		} );
		t.check( 'plugin states read for the component cross-check', Array.isArray( inactiveDirs ), typeof inactiveDirs );
		// Map product -> plugin directory for the products this site runs.
		const DIRS = {
			'Convert Pro': 'convertpro',
			'Schema Pro': 'wp-schema-pro',
			'WP Portfolio': 'astra-portfolio',
			'Premium Starter Templates': 'astra-pro-sites',
			'Spectra Blocks Pro': 'spectra-blocks-pro',
			'Ultimate Addons for Beaver Builder': 'bb-ultimate-addon',
			'Ultimate Addons for Elementor': 'ultimate-elementor',
		};
		let offWrong = '';
		for ( const [ name, dir ] of Object.entries( DIRS ) ) {
			const row = rows.find( ( r ) => r.name.startsWith( name ) );
			if ( ! row ) continue;
			const shouldBeOff = inactiveDirs.includes( dir );
			if ( !! row.off !== shouldBeOff ) {
				offWrong = `${ name }: off=${ !! row.off } plugin inactive=${ shouldBeOff }`;
			}
		}
		t.check( 'off flags track the real plugin state', ! offWrong, offWrong || 'all rows match' );

		// Empty-secret activation is refused before any vendor call. When the
		// whole family is installed-inactive, no vendor action is registered;
		// prove that boundary instead through one known provider id.
		const activatable = rows.find( ( r ) => ! r.off && ( r.can || [] ).includes( 'activate' ) );
		const empty = await page.evaluate( async ( provider ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { provider, action: 'activate', secret: '' } ),
			} );
			return r.status;
		}, activatable ? activatable.source : 'bsf-wp-schema-pro' );
		t.check( activatable ? 'empty key is refused before the vendor is called' : 'inactive products expose no vendor action',
			empty === ( activatable ? 400 : 404 ), String( empty ) );

		// Unknown BSF provider ids 404 rather than falling through to some
		// other product's key.
		const bogus = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { provider: 'bsf-not-a-product', action: 'verify' } ),
			} );
			return r.status;
		} );
		t.check( 'an unknown BSF product 404s', bogus === 404, String( bogus ) );

		// UI: the row renders on Extensions -> Licenses and its Activate
		// control opens a key field. Cancel without submitting — a real
		// vendor call does not belong in a suite.
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

		const shown = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.map( ( el ) => el.querySelector( '.minn-sys-ext-name' ).textContent.trim() ) );
		t.check( 'BSF products render on the Licenses tab',
			[ 'Schema Pro', 'WP Portfolio' ].every( ( n ) => shown.some( ( s ) => s.startsWith( n ) ) ),
			shown.filter( ( s ) => /Schema|Portfolio|Convert/.test( s ) ).join( ' | ' ) );

		// Controls a row draws follow its own state: a keyless row offers
		// the paste form (that flow is covered generically in
		// license-activate.test.js against the fixture provider), while a
		// licensed one offers seat release and re-verification instead of
		// asking for a key it already holds.
		const controls = await page.evaluate( () => {
			const out = {};
			for ( const el of document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ) {
				const name = el.querySelector( '.minn-sys-ext-name' ).textContent.trim();
				if ( ! /^(Schema Pro|WP Portfolio|Convert Pro|Spectra Blocks Pro|Premium Starter|Ultimate Addons)/.test( name ) ) continue;
				out[ name.split( '\n' )[ 0 ].trim() ] = {
					// Hidden ⋯-menu buttons stay in the DOM by design.
					lic: [ ...el.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ),
					pill: ( el.querySelector( '.minn-lic-pill' )?.className || '' ).replace( /.*minn-lic-pill\s*/, '' ).trim(),
				};
			}
			return out;
		} );
		const byName = ( n ) => Object.entries( controls ).find( ( [ k ] ) => k.startsWith( n ) )?.[ 1 ];
		let controlsWrong = '';
		for ( const name of Object.keys( DIRS ) ) {
			const ui = byName( name );
			const row = rows.find( ( r ) => r.name.startsWith( name ) );
			if ( ! ui || ! row ) continue;
			const want = row.off
				? ui.lic.includes( 'turnon' ) && ! ui.lic.some( ( action ) => [ 'activate', 'deactivate', 'verify' ].includes( action ) )
				: row.key
					? ui.lic.includes( 'deactivate' ) && ui.lic.includes( 'verify' )
					: ui.lic.includes( 'activate' );
			if ( ! want || ui.pill !== row.state ) {
				controlsWrong = `${ name }: pill=${ ui.pill } state=${ row.state } lic=${ ui.lic.join( '+' ) }`;
			}
		}
		t.check( 'each row draws the controls and pill its own state calls for',
			! controlsWrong && Object.keys( controls ).length >= PRODUCTS.length,
			controlsWrong || `${ Object.keys( controls ).length } rows checked` );

		// Editors never reach the action route.
		const { ctx: ctx2, page: p2 } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const editorStatus = await p2.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { provider: 'bsf-wp-schema-pro', action: 'verify' } ),
			} );
			return r.status;
		} );
		t.check( 'editors get 403 from the action route', editorStatus === 403, String( editorStatus ) );
		await ctx2.close();
	} finally {
		// Nothing to restore: the suite never writes license state.
	}
	await t.done( browser, errors );
} )();
