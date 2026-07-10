/**
 * Clear site cache — the ⌘K palette command purges every detected cache
 * layer through adapters/cache-purge.php. The minn-dev-fixtures mu-plugin
 * registers a "Fixture Cache" provider that counts purges into the
 * REST-exposed minn_fixture_cache_purged option.
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

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
