/**
 * Wordfence security posture rows on the System page (adapters/wordfence.php:
 * minn_admin_wordfence_checks). Firewall mode and last-scan/issue-count read
 * through Wordfence's own public APIs and surface as System health checks.
 *
 * Wordfence is the non-resident activity-log provider (WSAL is resident), so
 * it's normally inactive; this activates it, asserts the rows, and restores
 * it inactive in the finally (the security-log-suite convention). The exact
 * firewall mode and scan recency are live Wordfence state, so the suite
 * asserts the ROWS exist with a valid status rather than exact wording.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'security-posture' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setPlugin = ( plugin, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.plugin, {
			method: 'PUT', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return r.ok;
	}, { plugin, status } );

	const sysChecks = async () => {
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sys-check', { timeout: 20000 } );
		return page.$$eval( '.minn-sys-check', ( els ) => els.map( ( e ) => ( {
			text: e.textContent.replace( /\s+/g, ' ' ).trim(),
			status: e.className.replace( /.*minn-sys-check\s*/, '' ).trim(),
		} ) ) );
	};

	try {
		t.check( 'Wordfence activates over REST', await setPlugin( 'wordfence/wordfence', 'active' ) );
		// Wordfence loads its firewall/scanner classes on init; give the
		// activation a beat before reading the posture.
		await page.waitForTimeout( 1000 );

		const checks = await sysChecks();
		const fw = checks.find( ( c ) => /Wordfence firewall/.test( c.text ) );
		const scan = checks.find( ( c ) => /Wordfence scan/.test( c.text ) );
		t.check( 'Wordfence firewall row present', !! fw, JSON.stringify( fw ) );
		t.check( 'firewall row has a valid status', fw && [ 'pass', 'warn', 'fail' ].includes( fw.status ), fw && fw.status );
		t.check( 'Wordfence scan row present', !! scan, JSON.stringify( scan ) );
		t.check( 'scan row has a valid status', scan && [ 'pass', 'warn', 'fail' ].includes( scan.status ), scan && scan.status );

		// With no scan run on this fresh install, the scan row warns.
		t.check( 'a never-scanned site warns on the scan row', scan && ( /no malware scan/i.test( scan.text ) ? scan.status === 'warn' : true ), JSON.stringify( scan ) );

		// The rows are gone when Wordfence is inactive.
		await setPlugin( 'wordfence/wordfence', 'inactive' );
		const after = await sysChecks();
		t.check( 'no Wordfence rows when it is inactive', ! after.some( ( c ) => /Wordfence/.test( c.text ) ) );

	} finally {
		await setPlugin( 'wordfence/wordfence', 'inactive' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
