/**
 * WP Migrate license reader (adapters/licenses.php).
 *
 * WP Migrate is the odd one in the licenses card: its key lives in USER
 * META rather than an option (their own activation writes there and
 * deliberately clears the global option), and validity is whatever their
 * API last answered, cached per user in the wpmdb_licence_response_{uid}
 * site transient. So a state is reproducible by writing that pair, which
 * is what the minn-test/wpm-license fixture route does. No network is
 * involved: the reader never calls the licensing API, and proving that is
 * half the point of seeding the cache directly.
 *
 * Their API returns no expiry date in any response, so rows state the
 * condition without a renewal date and this suite asserts no date appears.
 *
 * The finally block restores the site's real (expired) fixture key so the
 * dev site keeps the state the rest of the licenses work expects.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

// Every state here is seeded straight into the vendor's own cache, so no
// real license is needed and none belongs in the repo: the key is only a
// string that has to be present. MINN_TEST_WPM_KEY overrides it for a
// run against a real subscription.
const REAL_KEY = process.env.MINN_TEST_WPM_KEY || 'fixture-1111-2222-3333-444455556666';

( async () => {
	const t = reporter( 'wp-migrate-license' );
	const { browser, page, errors } = await launch();
	await login( page );

	const seed = ( state, key ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/minn-test/wpm-license', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( args ),
		} );
		return r.status;
	}, { state, key: key || '' } );

	const openLicenses = async () => {
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="licenses"]', { timeout: 20000 } );
		await page.click( '[data-xtab="licenses"]' );
		await page.waitForSelector( '#minn-sys-licenses .minn-lic-item', { timeout: 20000 } );
		const off = await page.$( '#minn-lic-off-toggle' );
		if ( off && await page.$eval( '#minn-lic-off-toggle', ( el ) => el.getAttribute( 'aria-expanded' ) !== 'true' ) ) {
			await page.click( '#minn-lic-off-toggle' );
			await page.waitForTimeout( 250 );
		}
	};

	const row = () => page.evaluate( () => {
		const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.find( ( x ) => x.querySelector( '.minn-sys-ext-name' ).textContent.trim().startsWith( 'WP Migrate' ) );
		if ( ! el ) return null;
		const pill = el.querySelector( '.minn-lic-pill' ) || { className: '', textContent: '' };
		return {
			state: pill.className.replace( /.*minn-lic-pill\s*/, '' ).trim(),
			pill: pill.textContent.trim(),
			meta: ( el.querySelector( '.minn-sys-lic-meta' ) || { textContent: '' } ).textContent.trim(),
			buttons: [ ...el.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ),
		};
	} );

	const at = async ( state, key ) => {
		await seed( state, key );
		await openLicenses();
		return row();
	};

	try {
		// The row only exists when the plugin is installed. Everything below
		// depends on that, so fail loudly rather than silently passing.
		await seed( 'nocheck', REAL_KEY );
		await openLicenses();
		const present = await row();
		t.check( 'WP Migrate has a license row', !! present, JSON.stringify( present ) );
		if ( ! present ) {
			await seed( 'clear' ).catch( () => {} );
			await t.done( browser, errors );
			return;
		}

		// A stored key with nothing cached is honestly unknown, not valid.
		t.check( 'key with no recorded check reads unknown',
			present.state === 'unknown' && /no check recorded/i.test( present.meta ),
			JSON.stringify( present ) );

		const valid = await at( 'valid', REAL_KEY );
		t.check( 'an error-free answer reads valid', valid.state === 'valid', JSON.stringify( valid ) );
		// Their API sends no expiry in any response; a date here would be invented.
		t.check( 'no renewal date is invented', ! /\d{4}-\d{2}-\d{2}|renews/i.test( valid.meta ), valid.meta );

		const expired = await at( 'subscription_expired', REAL_KEY );
		t.check( 'subscription_expired reads expired',
			expired.state === 'expired' && /migrations still run/i.test( expired.meta ),
			JSON.stringify( expired ) );

		const cancelled = await at( 'subscription_cancelled', REAL_KEY );
		t.check( 'subscription_cancelled reads expired', cancelled.state === 'expired', JSON.stringify( cancelled ) );

		const notFound = await at( 'licence_not_found', REAL_KEY );
		t.check( 'licence_not_found reads invalid',
			notFound.state === 'invalid' && /does not recognise/i.test( notFound.meta ),
			JSON.stringify( notFound ) );

		const deactivated = await at( 'activation_deactivated', REAL_KEY );
		t.check( 'activation_deactivated reads invalid and says how to fix it',
			deactivated.state === 'invalid' && /activating again/i.test( deactivated.meta ),
			JSON.stringify( deactivated ) );

		const seats = await at( 'no_activations_left', REAL_KEY );
		t.check( 'no_activations_left reads invalid', seats.state === 'invalid', JSON.stringify( seats ) );

		// An unreachable API must never be reported as a bad license.
		const down = await at( 'api_down', REAL_KEY );
		t.check( 'an unreachable API reads unknown, not invalid',
			down.state === 'unknown' && /could not reach/i.test( down.meta ),
			JSON.stringify( down ) );

		// No key at all: missing, and the only control is Activate.
		const none = await at( 'clear' );
		t.check( 'no key reads missing', none.state === 'missing', JSON.stringify( none ) );
		t.check( 'a missing license offers only Activate',
			none.buttons.includes( 'activate' ) && ! none.buttons.includes( 'deactivate' ),
			JSON.stringify( none.buttons ) );

		// The card's own rule, which this provider inherits rather than
		// bends: Deactivate is offered only on a working license, and a
		// license that is not working offers Activate to replace the key.
		// So an expired key gets activate + verify, and the valid state
		// above got deactivate + verify.
		const back = await at( 'subscription_expired', REAL_KEY );
		t.check( 'an expired key offers activate and verify, not deactivate',
			back.buttons.includes( 'activate' ) && back.buttons.includes( 'verify' )
				&& ! back.buttons.includes( 'deactivate' ),
			JSON.stringify( back.buttons ) );
		t.check( 'a valid license is the one that offers deactivate',
			valid.buttons.includes( 'deactivate' ) && ! valid.buttons.includes( 'activate' ),
			JSON.stringify( valid.buttons ) );

		// The key itself must never reach the browser.
		const leaked = await page.evaluate( ( k ) => document.body.innerHTML.includes( k ), REAL_KEY );
		t.check( 'the license key is never rendered into the page', leaked === false, String( leaked ) );

	} finally {
		// Leave the dev site holding the real expired key + its cached answer.
		await seed( 'subscription_expired', REAL_KEY ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
