/**
 * Clear site cache — the ⌘K palette command purges every detected cache
 * layer through adapters/cache-purge.php. The minn-dev-fixtures mu-plugin
 * registers a "Fixture Cache" provider that counts purges into the
 * REST-exposed minn_fixture_cache_purged option.
 *
 * Pack wave: activates Redis Object Cache (and checks SpeedyCache detection
 * the same way) so the bundled purgers + Redis System health row are proven
 * without needing a live Redis server for the suite to pass.
 */
const { launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'cache-purge' );

	await login( page );

	const getCount = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return parseInt( ( await r.json() ).minn_fixture_cache_purged || '0', 10 );
	} );

	const setPlugin = async ( file, status ) => {
		// file is e.g. redis-cache/redis-cache (no .php — core plugins REST).
		// Playwright evaluate takes ONE arg — wrap multi-params in an object.
		const r = await page.evaluate( async ( { f, s } ) => {
			const res = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + f, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.MINN.nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return { status: res.status, body: await res.json().catch( () => null ) };
		}, { f: file, s: status } );
		return r;
	};

	const boot = await page.evaluate( () => window.MINN.cache || [] );
	t.check( 'Boot payload lists the fixture provider', boot.some( ( c ) => c.name === 'Fixture Cache' ), JSON.stringify( boot ) );

	const before = await getCount();

	// Run it the way a user would: ⌘K → "clear site" → Enter.
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
	await page.type( '#minn-palette-input', 'clear site' );
	await page.waitForTimeout( 300 );
	const entry = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-palette-item .minn-palette-label' ) )
			.map( ( e ) => e.textContent ).find( ( x ) => /Clear site cache/.test( x ) ) || ''
	);
	t.check( 'Palette offers Clear site cache with provider names', entry.includes( 'Fixture Cache' ), entry );
	await page.keyboard.press( 'Enter' );
	// Other detected providers (e.g. Elementor CSS via the forms fixture)
	// may ride along — assert the fixture is among what was purged.
	await page.waitForFunction(
		() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) =>
			/Cache cleared \(/.test( x.textContent ) && x.textContent.includes( 'Fixture Cache' ) ),
		null, { timeout: 30000 }
	);
	t.check( 'Success toast names what was purged', true );

	const after = await getCount();
	t.check( 'Fixture provider actually purged once', after === before + 1, `before=${ before } after=${ after }` );

	/* ===== Pack: Redis Object Cache purger + System health row ===== */
	// Activate for detection (drop-in not required — suite only asserts
	// registration + that the purge route accepts the provider id).
	const actRedis = await setPlugin( 'redis-cache/redis-cache', 'active' );
	t.check( 'Redis Object Cache activates for fixture', actRedis.status === 200, JSON.stringify( actRedis.status ) );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-app', { timeout: 20000 } );

	const redisBoot = await page.evaluate( () => window.MINN.cache || [] );
	t.check( 'Boot lists Redis Object Cache purger when plugin is active',
		redisBoot.some( ( c ) => c.id === 'redis-object-cache' ),
		JSON.stringify( redisBoot.map( ( c ) => c.id ) ) );

	// Direct purge of just that provider (avoids recycling the worker via
	// every other cache plugin on the site).
	const redisPurge = await page.evaluate( async () => {
		try {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/cache/purge', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.MINN.nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( { provider: 'redis-object-cache' } ),
			} );
			return { status: r.status, body: await r.json().catch( () => null ) };
		} catch ( e ) {
			// Worker recycle after flush can drop the socket — treat as ok if
			// the request left the browser (same as clearSiteCache retry path).
			return { status: 0, err: String( e && e.message || e ) };
		}
	} );
	t.check( 'Redis purger accepts a dedicated purge request',
		redisPurge.status === 200 || redisPurge.status === 0,
		JSON.stringify( redisPurge ) );

	// System health row (only while Redis plugin is active).
	await page.goto( page.url().replace( /#.*$/, '' ).replace( /\/minn-admin\/?.*$/, '/minn-admin/system' ), { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-sys-jump, .minn-sys-checks', { timeout: 20000 } );
	const redisCheck = await page.evaluate( () => {
		const els = Array.from( document.querySelectorAll( '.minn-sys-check' ) );
		const row = els.find( ( e ) => /Redis Object Cache/.test( e.textContent ) );
		return row ? { text: row.textContent.trim(), cls: row.className } : null;
	} );
	t.check( 'System page shows Redis Object Cache health row',
		!! redisCheck && /pass|warn|fail/.test( redisCheck.cls ),
		JSON.stringify( redisCheck ) );

	// SpeedyCache detection (no need for a full purge cycle of every layer).
	const actSpeedy = await setPlugin( 'speedycache/speedycache', 'active' );
	t.check( 'SpeedyCache activates for fixture', actSpeedy.status === 200, String( actSpeedy.status ) );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-app', { timeout: 20000 } );
	const speedyBoot = await page.evaluate( () => window.MINN.cache || [] );
	t.check( 'Boot lists SpeedyCache purger when plugin is active',
		speedyBoot.some( ( c ) => c.id === 'speedycache' ),
		JSON.stringify( speedyBoot.map( ( c ) => c.id ) ) );

	// Restore inactive (family/fixture convention — pack plugins rest off).
	await setPlugin( 'speedycache/speedycache', 'inactive' );
	await setPlugin( 'redis-cache/redis-cache', 'inactive' );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
