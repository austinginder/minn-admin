/**
 * When "Update everything" (notifications panel) includes Minn Admin in the
 * bulk plugin batch, the SPA must hard-reload so the new app.js / version
 * badge land. Extensions single-plugin and bulk paths already did this;
 * the notif-panel path did not (Austin, 2026-07-12).
 *
 * No real update runs: update-all is stubbed via page.route. The 700ms
 * setTimeout that schedules location.reload is intercepted (Chromium will
 * not let tests assign to location.reload).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'self-update-reload' );
	const { browser, page, errors } = await launch();

	// Routes before any SPA boot that fetches plugin-updates.
	await page.route( '**/minn-admin/v1/plugin-updates**', async ( route ) => {
		if ( route.request().method() !== 'GET' ) return route.continue();
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				updates: { 'minn-admin/minn-admin.php': '9.9.9' },
				themes: {},
			} ),
		} );
	} );
	await page.route( '**/minn-admin/v1/plugins/update-all**', async ( route ) => {
		if ( route.request().method() !== 'POST' ) return route.continue();
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				updated: [ 'minn-admin/minn-admin.php' ],
				failed: [],
				errors: [],
			} ),
		} );
	} );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	try {
		await login( page );
		// Re-goto so boot's loadPlugins hits the stubbed offer.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		await page.waitForSelector( '#minn-notif-btn', { timeout: 15000 } );
		await page.waitForFunction( () => {
			const dot = document.querySelector( '#minn-plugin-dot' );
			return dot && ! dot.hidden;
		}, null, { timeout: 15000 } );

		await page.evaluate( () => {
			window.__minnReloadScheduled = 0;
			window.__minnReloadMsgs = [];
			const orig = window.setTimeout.bind( window );
			window.setTimeout = ( fn, ms, ...args ) => {
				if ( ms === 700 && typeof fn === 'function' ) {
					let src = '';
					try { src = Function.prototype.toString.call( fn ); } catch ( e ) { /* ignore */ }
					if ( /reload/i.test( src ) ) {
						window.__minnReloadScheduled++;
						return 0; // swallow — do not navigate
					}
				}
				return orig( fn, ms, ...args );
			};
			const obs = new MutationObserver( () => {
				document.querySelectorAll( '.minn-toast-msg' ).forEach( ( el ) => {
					const text = el.textContent.trim();
					if ( text && ! window.__minnReloadMsgs.includes( text ) ) {
						window.__minnReloadMsgs.push( text );
					}
				} );
			} );
			obs.observe( document.body, { childList: true, subtree: true } );
		} );

		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '.minn-notif-panel', { timeout: 10000 } );
		await page.click( '.minn-notif-tab[data-tab="updates"]' );
		await page.waitForSelector( '#minn-update-all', { timeout: 15000 } );

		const sub = await page.evaluate( () => ( document.querySelector( '.minn-update-all-sub' ) || {} ).textContent || '' );
		t.check( 'Update everything sees the stubbed plugin offer', /plugin/i.test( sub ), sub );

		await page.click( '#minn-update-all' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 8000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );

		await page.waitForFunction(
			() => ( window.__minnReloadScheduled > 0 )
				|| ( window.__minnReloadMsgs || [] ).some( ( m ) => /Minn Admin updated/i.test( m ) ),
			null,
			{ timeout: 8000 }
		);

		const result = await page.evaluate( () => ( {
			scheduled: window.__minnReloadScheduled,
			msgs: window.__minnReloadMsgs.slice(),
		} ) );

		t.check(
			'reload toast names the update',
			result.msgs.some( ( m ) => /Minn Admin updated/i.test( m ) && /reload/i.test( m ) ),
			result.msgs.join( ' | ' ) || '(no toasts)'
		);
		t.check(
			'toast includes the offered version when known',
			result.msgs.some( ( m ) => /v9\.9\.9/.test( m ) ),
			result.msgs.join( ' | ' ) || '(no toasts)'
		);
		t.check(
			'hard-reload scheduled after Minn is in the bulk updated list',
			result.scheduled >= 1,
			`scheduled=${ result.scheduled }`
		);

		// Control: bulk without Minn must NOT schedule a reload.
		await page.evaluate( () => {
			window.__minnReloadScheduled = 0;
			window.__minnReloadMsgs = [];
		} );
		await page.unroute( '**/minn-admin/v1/plugins/update-all**' );
		await page.route( '**/minn-admin/v1/plugins/update-all**', async ( route ) => {
			if ( route.request().method() !== 'POST' ) return route.continue();
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					updated: [ 'akismet/akismet.php' ],
					failed: [],
					errors: [],
				} ),
			} );
		} );

		if ( ! await page.$( '#minn-update-all' ) ) {
			await page.click( '#minn-notif-btn' );
			await page.waitForSelector( '.minn-notif-panel', { timeout: 10000 } );
			await page.click( '.minn-notif-tab[data-tab="updates"]' );
			await page.waitForSelector( '#minn-update-all', { timeout: 15000 } );
		}
		await page.click( '#minn-update-all' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 8000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		// "Minn Admin updated" also contains "updated" — match the normal
		// completion toast specifically ("Updated N plugins. Everything is current.").
		await page.waitForFunction(
			() => ( window.__minnReloadMsgs || [] ).some( ( m ) => /^Updated\b/i.test( m ) && /Everything is current/i.test( m ) ),
			null,
			{ timeout: 8000 }
		);
		const control = await page.evaluate( () => ( {
			scheduled: window.__minnReloadScheduled,
			msgs: window.__minnReloadMsgs.slice(),
		} ) );
		t.check(
			'non-Minn bulk does not schedule a hard-reload',
			control.scheduled === 0,
			`scheduled=${ control.scheduled } msgs=${ control.msgs.join( ' | ' ) }`
		);
		t.check(
			'non-Minn bulk still toasts a normal completion',
			control.msgs.some( ( m ) => /^Updated\b/i.test( m ) && /Everything is current/i.test( m ) ),
			control.msgs.join( ' | ' ) || '(no toasts)'
		);

		await t.done( browser, errors );
	} catch ( e ) {
		console.error( e );
		await t.done( browser, errors.concat( [ String( e ) ] ) );
		process.exit( 1 );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
