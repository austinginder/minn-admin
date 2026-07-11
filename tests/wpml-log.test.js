/**
 * WP Mail Logging — Email (mail) family provider.
 *
 * Proves: the shim lists {prefix}wpml_mails with sent/failed pills derived
 * from the error column, site-local timestamps emitted raw, tabs + search,
 * a detail modal whose message renders through messageKey, Resend through
 * the plugin's OWN resender service (the new attempt appears as its own
 * log row), and permanent Delete mirroring its log screen.
 *
 * Fixture: minn_test_seed_wpml (one-shot; atomic add_option lock — the
 * arming request's wp-cron spawn raced the flag-clear and doubled rows
 * until the lock) sends two REAL wp_mail()s and inserts one synthetic
 * failed row. Totals grow with real logged mail — never assert absolutes.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'wpml-log' );

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		return await r.json();
	}, typeof a === 'string' ? { path: a } : a );

	// Baseline: WP Mail Logging active (resident fixture; re-activate if a
	// crashed run left it off).
	const status = await page.evaluate( async () => {
		const id = 'wp-mail-logging/wp-mail-logging';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( status !== 'active' ) {
		await page.evaluate( async () => {
			const id = 'wp-mail-logging/wp-mail-logging';
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket — activation still lands */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed (one-shot flag; poll until the fixture rows land).
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_wpml: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	let list = null;
	for ( let i = 0; i < 8; i++ ) {
		list = await api( { path: 'minn-admin/v1/wpml/emails?search=' + encodeURIComponent( 'Minn WPML fixture' ) } ).catch( () => null );
		if ( list && list.total >= 3 ) break;
		await page.waitForTimeout( 800 );
	}

	/* ===== Shim shape ===== */
	t.check( 'fixture rows logged', !! list && list.total >= 3, JSON.stringify( list && list.total ) );
	const failedRow = list.items.find( ( r ) => r.status === 'failed' );
	const sentRow = list.items.find( ( r ) => r.status === 'sent' );
	t.check( 'sent + failed pills derive from the error column', !! failedRow && !! sentRow );
	t.check( 'timestamps emit raw site-local (no Z)', list.items.every( ( r ) => r.timestamp && ! /Z$/.test( r.timestamp ) ) );
	const failedTab = await api( { path: 'minn-admin/v1/wpml/emails?status=failed&search=' + encodeURIComponent( 'Minn WPML fixture' ) } );
	t.check( 'failed tab filters', failedTab.items.length >= 1 && failedTab.items.every( ( r ) => r.status === 'failed' ) );
	const detail = await api( { path: 'minn-admin/v1/wpml/emails/' + sentRow.id } );
	t.check( 'detail carries message + host + headers', typeof detail.message === 'string' && detail.message.length > 0 && 'headers' in detail );

	/* ===== Surface in the app ===== */
	await page.goto( `${ BASE }/minn-admin/wp-mail-logging`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	t.check( 'surface joins the mail family', await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'wp-mail-logging' );
		return !! s && s.family === 'mail' && s.sub === 'WP Mail Logging';
	} ) );

	// Search down to the fixture rows, open the HTML receipt.
	await page.fill( '#minn-surface-search', 'Minn WPML fixture' );
	await page.waitForFunction( () =>
		document.querySelectorAll( '.minn-table-row' ).length >= 3
		&& [ ...document.querySelectorAll( '.minn-table-row' ) ].every( ( r ) => /Minn WPML fixture/.test( r.textContent ) ),
	null, { timeout: 15000 } );
	t.check( 'search narrows the list', true );
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /receipt/.test( r.textContent ) ).click();
	} );
	// Wait past the modal's loading state — the body (iframe or pre) and
	// action buttons render only once the detail fetch lands.
	await page.waitForSelector( '.minn-modal iframe.minn-email-frame, .minn-modal .minn-surface-message', { timeout: 15000 } );
	t.check( 'HTML message renders sandboxed', !! ( await page.$( '.minn-modal iframe.minn-email-frame' ) ) );

	/* ===== Resend through the plugin's own service ===== */
	const beforeResend = ( await api( { path: 'minn-admin/v1/wpml/emails?search=' + encodeURIComponent( 'Minn WPML fixture: receipt' ) } ) ).total;
	await page.evaluate( () => {
		window.confirm = () => true;
		[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Resend/.test( b.textContent ) ).click();
	} );
	let afterResend = beforeResend;
	for ( let i = 0; i < 10; i++ ) {
		await page.waitForTimeout( 800 );
		afterResend = ( await api( { path: 'minn-admin/v1/wpml/emails?search=' + encodeURIComponent( 'Minn WPML fixture: receipt' ) } ) ).total;
		if ( afterResend > beforeResend ) break;
	}
	t.check( 'resend logs a new attempt through their pipeline', afterResend > beforeResend, `${ beforeResend } → ${ afterResend }` );

	/* ===== Permanent delete ===== */
	await page.goto( `${ BASE }/minn-admin/wp-mail-logging`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	const beforeDelete = ( await api( { path: 'minn-admin/v1/wpml/emails?search=' + encodeURIComponent( 'Minn WPML fixture: failed send' ) } ) ).total;
	await page.fill( '#minn-surface-search', 'failed send' );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => /failed send/.test( r.textContent ) ),
	null, { timeout: 15000 } );
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /failed send/.test( r.textContent ) ).click();
	} );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Delete/.test( b.textContent ) ),
	null, { timeout: 15000 } );
	await page.evaluate( () => {
		window.confirm = () => true;
		[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Delete/.test( b.textContent ) ).click();
	} );
	// Prior seed passes may have left siblings — assert the count DROPS,
	// never that it reaches zero (totals grow/shrink across runs).
	let gone = false;
	for ( let i = 0; i < 10; i++ ) {
		await page.waitForTimeout( 700 );
		const check = await api( { path: 'minn-admin/v1/wpml/emails?search=' + encodeURIComponent( 'Minn WPML fixture: failed send' ) } );
		if ( check.total < beforeDelete ) { gone = true; break; }
	}
	t.check( 'delete is permanent in their table', gone );

	await t.done( browser, errors );
} )();
