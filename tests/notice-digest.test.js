/**
 * Admin-notice digest — extraction into the notification panel.
 *
 * The capture endpoint boots a real wp-admin dashboard pageload as the
 * current user, renders every registered admin-notice callback in an
 * isolated buffer, and stores structured data (severity, text, links,
 * owner) — never third-party HTML. Links that a notice built from the
 * current request URI (allow/dismiss/opt-in actions) are flagged `action`
 * and run in the BACKGROUND from the panel; plain links open a new tab.
 *
 * Fixtures (minn-dev-fixtures mu-plugin): a dismissible warning with an
 * external link, a non-dismissible error, one callback emitting TWO notices
 * (split test), a plugins.php-gated notice the dashboard capture must skip,
 * and an "allow tracking" action notice whose admin_init handler sets the
 * REST-exposed minn_fixture_action_done option.
 */
const { launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'notice-digest' );

	await login( page );

	const resetAction = () => page.evaluate( async () => ( await fetch( window.MINN.restUrl + 'wp/v2/settings', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
		credentials: 'same-origin',
		body: JSON.stringify( {
			minn_fixture_action_done: '',
			minn_fixture_hash_dismissed: '',
			minn_fixture_hash_allowed: '',
		} ),
	} ) ).status );

	const fetchNoticeItems = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/notifications', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		return ( await r.json() ).items.filter( ( n ) => n.kind === 'notices' );
	} );

	let errorHash = null;
	try {
		t.check( 'Action fixture reset', ( await resetAction() ) === 200 );

		// --- Capture endpoint -------------------------------------------------
		const boot = await page.evaluate( () => window.MINN.notices || null );
		t.check( 'Boot payload carries notices.url + nonce', !! ( boot && boot.url && boot.url.includes( 'minn_notices=1' ) && boot.nonce ) );

		const cap = await page.evaluate( async () => {
			const r = await fetch( window.MINN.notices.url, { credentials: 'same-origin' } );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'Capture responds ok JSON (page chrome swallowed)', cap.status === 200 && cap.body && cap.body.ok === true );
		t.check( 'Capture found the fixture notices', cap.body.count >= 6, `count=${ cap.body.count }` );

		// --- Notifications endpoint -------------------------------------------
		const items = await fetchNoticeItems();
		const byText = ( s ) => items.find( ( n ) => n.title.includes( s ) );

		const warning = byText( 'license expires soon' );
		t.check( 'Warning fixture extracted', !! warning );
		t.check( 'Attribution names the owning plugin', !! warning && warning.title.startsWith( 'Minn Dev Fixtures:' ), warning && warning.title );
		t.check( 'Severity icon rides the item', !! warning && warning.icon === '⚠️' && warning.severity === 'warning' );
		const wLink = warning && ( warning.links || [] )[ 0 ];
		t.check( 'External link extracted as non-action', !! wLink && wLink.url === 'https://example.com/renew' && ! wLink.action, JSON.stringify( wLink ) );

		const error = byText( 'nightly backup failed' );
		t.check( 'Error fixture extracted', !! error && error.severity === 'error' );
		t.check( 'Link-less notice has no links', !! error && ( error.links || [] ).length === 0 );

		const split1 = byText( 'settings were imported' );
		const split2 = byText( 'integrations catalog is available' );
		t.check( 'One callback, two notices → two entries', !! split1 && !! split2 && split1.id !== split2.id );
		const sLink = split2 && ( split2.links || [] )[ 0 ];
		t.check( 'Relative admin link absolutized, non-action', !! sLink && /\/wp-admin\/plugins\.php$/.test( sLink.url ) && ! sLink.action, JSON.stringify( sLink ) );
		t.check( 'Screen-gated notice NOT captured on dashboard', ! byText( 'gated notice' ) );

		// Action link: flagged, capture params stripped.
		const actionItem = byText( 'allow anonymous usage tracking' );
		const aLink = actionItem && ( actionItem.links || [] )[ 0 ];
		t.check( 'Action notice extracted', !! aLink, actionItem && actionItem.title );
		t.check(
			'Action link flagged and stripped of capture params',
			!! aLink && aLink.action && aLink.url.includes( 'minn_fixture_action=yes' )
				&& ! aLink.url.includes( 'minn_notices' ) && ! aLink.url.includes( '_wpnonce' ),
			aLink && aLink.url
		);

		// Hash-button CTAs (Everest "No, Thanks" shape): href="#" with .button.
		const hashItem = byText( 'contribute telemetry' );
		const hashLinks = ( hashItem && hashItem.links ) || [];
		const noThanks = hashLinks.find( ( l ) => /No,?\s*Thanks/i.test( l.text || '' ) );
		const allowBtn = hashLinks.find( ( l ) => /^Allow$/i.test( ( l.text || '' ).trim() ) );
		t.check( 'Hash-button notice extracted', !! hashItem, hashItem && hashItem.title );
		t.check( 'No, Thanks is a clickable action button',
			!! noThanks && noThanks.action && noThanks.button && noThanks.ajax && noThanks.ajax.action === 'minn_fixture_hash_dismiss',
			JSON.stringify( noThanks ) );
		t.check( 'Allow is a clickable action button',
			!! allowBtn && allowBtn.action && allowBtn.button,
			JSON.stringify( allowBtn ) );
		t.check( 'Button labels stripped from notice body text',
			!! hashItem && ! /No,?\s*Thanks/i.test( hashItem.title ) && ! /\bAllow\b/.test( hashItem.title.replace( /^[^:]+:\s*/, '' ) ),
			hashItem && hashItem.title );

		// --- Panel UI -----------------------------------------------------------
		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '.minn-notif-panel', { timeout: 5000 } );
		await page.click( '.minn-notif-tab[data-tab="notices"]' );
		// The panel first paints from the boot-time cache (fetched before this
		// suite's capture); wait for the refresh fetch to re-render.
		const rowsOk = await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.filter( ( r ) => r.textContent.includes( 'Minn Fixture' ) ).length >= 6,
			null, { timeout: 10000 }
		).then( () => true ).catch( () => false );
		const rowCount = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.filter( ( r ) => r.textContent.includes( 'Minn Fixture' ) ).length
		);
		t.check( 'Fixture rows render under the Notices tab', rowsOk, `rows=${ rowCount }` );

		// External link button opens a new tab (stubbed); row click stays put.
		await page.evaluate( () => {
			window.__minnOpened = null;
			window.open = ( u ) => { window.__minnOpened = u; return null; };
		} );
		await page.evaluate( () => {
			const btn = Array.from( document.querySelectorAll( '.minn-notif-link' ) )
				.find( ( b ) => b.textContent.includes( 'Renew now' ) );
			btn.click();
		} );
		await page.waitForTimeout( 200 );
		t.check( 'External button opens its link', ( await page.evaluate( () => window.__minnOpened ) ) === 'https://example.com/renew' );
		t.check( 'Panel stays open', await page.evaluate( () => !! document.querySelector( '.minn-notif-panel' ) ) );

		await page.evaluate( () => {
			window.__minnOpened = null;
			const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.find( ( r ) => r.textContent.includes( 'license expires soon' ) );
			row.click();
		} );
		await page.waitForTimeout( 300 );
		const afterRowClick = await page.evaluate( () => ( {
			opened: window.__minnOpened,
			panel: !! document.querySelector( '.minn-notif-panel' ),
			unread: ( () => {
				const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
					.find( ( r ) => r.textContent.includes( 'license expires soon' ) );
				return row ? row.classList.contains( 'unread' ) : null;
			} )(),
		} ) );
		t.check( 'Row click marks read without navigating', afterRowClick.opened === null && afterRowClick.panel && afterRowClick.unread === false, JSON.stringify( afterRowClick ) );

		// --- Background action --------------------------------------------------
		await page.evaluate( () => {
			const btn = Array.from( document.querySelectorAll( '.minn-notif-link' ) )
				.find( ( b ) => b.textContent.includes( 'Allow tracking' ) );
			btn.click();
		} );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Done: Allow tracking/.test( x.textContent ) ),
			null, { timeout: 20000 }
		);
		t.check( 'Action ran in the background with a Done toast', true );
		await page.waitForTimeout( 500 );
		const gone = await page.evaluate( () =>
			! Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.some( ( r ) => r.textContent.includes( 'allow anonymous usage tracking' ) )
		);
		t.check( 'Actioned notice left the digest', gone );
		const itemsAfter = await fetchNoticeItems();
		t.check( 'Fresh capture no longer holds the notice', ! itemsAfter.some( ( n ) => n.title.includes( 'allow anonymous usage tracking' ) ) );

		// --- Hash-button "No, Thanks" (Everest-style ajax dismiss) ------------
		await page.evaluate( () => {
			const btn = Array.from( document.querySelectorAll( '.minn-notif-link' ) )
				.find( ( b ) => /No,?\s*Thanks/i.test( b.textContent || '' ) );
			if ( btn ) btn.click();
		} );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) )
				.some( ( x ) => /Done:.*No,?\s*Thanks/i.test( x.textContent || '' ) ),
			null, { timeout: 20000 }
		).catch( () => null );
		t.check( 'No, Thanks action toast', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) )
				.some( ( x ) => /Done:.*No,?\s*Thanks/i.test( x.textContent || '' ) )
		) );
		const hashGone = await page.waitForFunction(
			() => ! Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.some( ( r ) => r.textContent.includes( 'contribute telemetry' ) ),
			null, { timeout: 10000 }
		).then( () => true ).catch( () => false );
		t.check( 'No, Thanks removed the hash-button notice', hashGone );
		const hashOpt = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_fields=minn_fixture_hash_dismissed', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const b = await r.json();
			return b.minn_fixture_hash_dismissed;
		} );
		t.check( 'No, Thanks ran the plugin dismiss handler', hashOpt === '1', String( hashOpt ) );

		// --- Hide (Minn-side dismissal) ---------------------------------------
		// The error fixture has no links — the same shape as notices whose only
		// dismissal is plugin-specific admin-ajax JS Minn can't replay.
		errorHash = error.id.replace( /^notice-/, '' );
		const hasHideBtn = await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.find( ( r ) => r.textContent.includes( 'nightly backup failed' ) );
			const btn = row && row.querySelector( '.minn-notif-hide' );
			if ( btn ) btn.click();
			return !! btn;
		} );
		t.check( 'Notice row renders a Hide button', hasHideBtn );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Notice hidden/.test( x.textContent ) ),
			null, { timeout: 8000 }
		);
		t.check( 'Hide removes the row with an Undo toast', await page.evaluate( () =>
			! Array.from( document.querySelectorAll( '.minn-notif-row' ) ).some( ( r ) => r.textContent.includes( 'nightly backup failed' ) )
		) );

		// Undo restores it (panel re-renders from a fresh fetch).
		await page.click( '.minn-toast-btn' );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-notif-row' ) ).some( ( r ) => r.textContent.includes( 'nightly backup failed' ) ),
			null, { timeout: 10000 }
		);
		t.check( 'Undo unhides the notice in the panel', true );

		// Hide again, then prove suppression is server-side and survives a
		// fresh capture (ids are content-stable across re-captures).
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
				.find( ( r ) => r.textContent.includes( 'nightly backup failed' ) );
			row.querySelector( '.minn-notif-hide' ).click();
		} );
		await page.waitForFunction(
			() => ! Array.from( document.querySelectorAll( '.minn-notif-row' ) ).some( ( r ) => r.textContent.includes( 'nightly backup failed' ) ),
			null, { timeout: 8000 }
		);
		await page.evaluate( async () => { await fetch( window.MINN.notices.url, { credentials: 'same-origin' } ); } );
		const afterHide = await fetchNoticeItems();
		t.check( 'Hidden notice stays suppressed across a fresh capture', ! afterHide.some( ( n ) => n.title.includes( 'nightly backup failed' ) ) );

		// REST unhide (the Undo endpoint) restores it in the digest.
		const unhideStatus = await page.evaluate( async ( hash ) => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/notices/unhide', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { id: hash } ),
		} ) ).status, errorHash );
		const afterUnhide = await fetchNoticeItems();
		t.check( 'REST unhide restores the notice', unhideStatus === 200 && afterUnhide.some( ( n ) => n.title.includes( 'nightly backup failed' ) ) );
	} finally {
		await resetAction().catch( () => {} );
		// A failure between hide and unhide must not leave the fixture hidden.
		if ( errorHash ) {
			await page.evaluate( async ( hash ) => { await fetch( window.MINN.restUrl + 'minn-admin/v1/notices/unhide', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { id: hash } ),
			} ); }, errorHash ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
