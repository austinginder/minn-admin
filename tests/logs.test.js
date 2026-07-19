/**
 * Multi-source log viewer — the /system/logs registry (debug log + WooCommerce
 * channels), the System card's source rows, the overlay's source picker,
 * collapse-repeats mode, and per-source clearability.
 *
 * Fixture: minn_test_seed_logs (mu-plugin, one-shot) appends three debug-log
 * lines identical except digits and writes one entry to a dedicated
 * wc:minn-suite channel. Appends only — the suite never clears real logs.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'logs' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	// Arm the one-shot seeder; a dropped socket still seeds server-side, so
	// poll the registry for the wc:minn-suite source (rule-47 pattern).
	await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( { minn_test_seed_logs: '1' } ) } ).catch( () => {} );
	let sources = null;
	for ( let i = 0; i < 10; i++ ) {
		const r = await api( 'minn-admin/v1/system/logs' ).catch( () => null );
		sources = r && r.body && r.body.sources;
		if ( Array.isArray( sources ) && sources.some( ( s ) => s.id === 'wc:minn-suite' ) ) break;
		await page.waitForTimeout( 800 );
	}

	const hasWc = await page.evaluate( () => !! ( window.MINN && window.MINN.wc ) );
	t.check( 'registry lists sources', Array.isArray( sources ) && sources.length >= 1, String( sources && sources.length ) );
	const debugSrc = ( sources || [] ).find( ( s ) => s.id === 'debug' );
	t.check( 'debug source present and clearable', !! debugSrc && debugSrc.clearable === true, JSON.stringify( debugSrc ) );
	const suiteSrc = ( sources || [] ).find( ( s ) => s.id === 'wc:minn-suite' );
	if ( hasWc ) {
		t.check( 'seeded WC channel appears as wc:minn-suite (read-only)', !! suiteSrc && suiteSrc.clearable === false && suiteSrc.group === 'WooCommerce', JSON.stringify( suiteSrc ) );
		const read = await api( 'minn-admin/v1/system/logs/wc:minn-suite' );
		t.check( 'WC channel read carries the seeded entry + note', read.status === 200 && /Minn logs suite entry/.test( read.body.content || '' ) && !! read.body.note, String( read.status ) );
	} else {
		t.check( 'WC inactive — channel checks skipped', true, '' );
		t.check( 'WC inactive — read check skipped', true, '' );
	}
	const unknown = await api( 'minn-admin/v1/system/logs/no-such-source' );
	t.check( 'unknown source answers 404', unknown.status === 404, String( unknown.status ) );

	// The System card renders source rows.
	await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-logsrc]', { timeout: 20000 } );
	const rowInfo = await page.evaluate( () => ( {
		rows: document.querySelectorAll( '[data-logsrc]' ).length,
		hasDebugRow: !! document.querySelector( '#minn-view-log' ),
	} ) );
	t.check( 'card lists log rows with the debug row first-class', rowInfo.rows >= 1 && rowInfo.hasDebugRow, JSON.stringify( rowInfo ) );

	// Overlay: open the debug log, wait for content.
	await page.click( '#minn-view-log' );
	await page.waitForSelector( '.minn-log-modal', { timeout: 10000 } );
	await page.waitForFunction( () => {
		const b = document.querySelector( '#minn-log-body' );
		return b && ( b.textContent.length > 60 || /empty/.test( b.textContent ) );
	}, null, { timeout: 15000 } );
	t.check( 'overlay opens on the debug source', true, '' );
	t.check( 'meta line carries path or size', await page.evaluate( () => document.querySelector( '#minn-log-meta' ).textContent.length > 3 ), '' );
	t.check( 'clear offered for the writable debug log', await page.evaluate( () => ! document.querySelector( '#minn-log-clear' ).hidden ), '' );
	t.check( 'action row holds the full toolset', await page.evaluate( () => document.querySelectorAll( '.minn-log-actions button' ).length >= 5 ), '' );

	// Collapse repeats: the three seeded lines differ only in digits.
	await page.waitForFunction( () => /Minn logs suite repeated marker/.test( document.querySelector( '#minn-log-body' ).textContent ), null, { timeout: 15000 } );
	await page.click( '#minn-log-collapse' );
	const collapsedOk = await page.evaluate( () => {
		const txt = document.querySelector( '#minn-log-body' ).textContent;
		return /×\d+\s+.*Minn logs suite repeated marker/.test( txt );
	} );
	t.check( 'collapse groups digit-variant repeats with a count', collapsedOk, '' );
	await page.click( '#minn-log-collapse' ); // back to raw

	// Source picker: switch to the seeded WC channel.
	if ( hasWc && suiteSrc ) {
		await page.click( '.minn-log-src .minn-ac-input' );
		await page.waitForSelector( '.minn-log-src .minn-ac-item', { timeout: 8000 } );
		await page.evaluate( () => {
			const item = Array.from( document.querySelectorAll( '.minn-log-src .minn-ac-item' ) )
				.find( ( el ) => /minn-suite/.test( el.textContent ) );
			if ( item ) {
				item.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
				item.click();
			}
		} );
		await page.waitForFunction( () => /Minn logs suite entry/.test( document.querySelector( '#minn-log-body' ).textContent ), null, { timeout: 15000 } );
		t.check( 'picker switches to the WC channel and loads it', true, '' );
		t.check( 'clear hidden on the read-only WC channel', await page.evaluate( () => document.querySelector( '#minn-log-clear' ).hidden ), '' );
	} else {
		t.check( 'WC inactive — picker switch skipped', true, '' );
		t.check( 'WC inactive — clear-hidden check skipped', true, '' );
	}

	await page.keyboard.press( 'Escape' );
	t.check( 'Escape closes the overlay', await page.waitForFunction( () => ! document.querySelector( '.minn-log-modal' ), null, { timeout: 5000 } ).then( () => true ).catch( () => false ), '' );

	await t.done( browser, errors );
} )();
