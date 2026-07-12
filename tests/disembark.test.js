/**
 * Disembark backups adapter — the connector answer to the Backups family.
 *
 * Disembark pulls backups off-site from the CLI, so the surface shows a
 * status card (last scan, database size, working files), the exact
 * `disembark connect` command with copy-to-clipboard, the scan sessions on
 * disk with per-session delete, workspace cleanup, and token regeneration.
 * This suite also proves the new generic surface `status` card primitive:
 * server-built rows/command/actions rendering above a surface list.
 *
 * Fixture: minn-dev-fixtures' minn_test_seed_disembark option writes a fake
 * finished scan session into uploads/disembark/ and stamps the
 * last-scan-stats option.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'disembark' );

	// Status-card actions and detail actions confirm() natively.
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

	// Write-then-verify-then-retry — REST settings writes can read stale
	// right after (the settings-visibility heisenbug; see site-kit suite).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_disembark: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_seed_disembark;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};
	// One-shot: the mu-fixture seeds on the NEXT request's init and clears
	// the flag itself, so cleanup actions can't trigger a silent re-seed.
	// That makes the read-back racy BY DESIGN: the verification GET's own
	// init can consume the flag, so a read-back of '' after writing '1'
	// also means success (consumed = seeded). The session-row assertions
	// downstream are the real gate.
	const seed = async () => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async () => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_disembark: '1' } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_seed_disembark;
			} );
			if ( stored === '1' || stored === '' ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const openSurface = async () => {
		await page.goto( BASE + '/minn-admin/disembark', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 15000 } );
		await page.waitForTimeout( 300 );
	};
	const statusText = () => page.evaluate( () => {
		const rows = {};
		document.querySelectorAll( '.minn-sstat' ).forEach( ( el ) => {
			rows[ el.querySelector( '.minn-sstat-label' ).textContent ] = el.querySelector( '.minn-sstat-value' ).textContent;
		} );
		const cmdEl = document.querySelector( '.minn-sstat-cmd-box code' );
		return { rows, cmd: cmdEl ? cmdEl.textContent : null };
	} );
	const sessionRows = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row' ) ).filter( ( r ) => r.textContent.includes( 'Session minntest' ) ).length
	);
	const clickStatusAction = ( label ) => page.evaluate( ( l ) => {
		const btn = Array.from( document.querySelectorAll( '.minn-sstat-actions button' ) ).find( ( b ) => b.textContent.trim() === l );
		if ( btn ) btn.click();
		return !! btn;
	}, label );
	const waitToast = ( re ) => page.waitForFunction(
		( src ) => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => new RegExp( src ).test( x.textContent ) ),
		re, { timeout: 10000 }
	);

	try {
		// Baseline: a REAL Disembark scan on the dev site leaves session dirs
		// that break the "Working files: None" asserts below (Austin tests
		// live). The workspace is disposable — the suite's own teardown wipes
		// it — so establish the empty baseline up front the same way.
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'minn-admin/v1/disembark/cleanup', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ).catch( () => {} );
		} ).catch( () => {} );

		t.check( 'Fixture seeded', await seed() );
		await openSurface();

		// --- Status card ------------------------------------------------------
		const st = await statusText();
		t.check( 'Status card shows a real last scan', /ago/.test( st.rows['Last scan'] || '' ), JSON.stringify( st.rows ) );
		t.check( 'Database size renders', /\d/.test( st.rows.Database || '' ), st.rows.Database );
		t.check( 'Working files show the seeded workspace', /(KB|MB|B)/.test( st.rows['Working files'] || '' ), st.rows['Working files'] );
		t.check( 'Connect command carries site + token', !! st.cmd && st.cmd.startsWith( 'disembark connect ' + 'https://minnadmin.localhost ' ) && st.cmd.trim().split( ' ' ).length === 4, st.cmd );

		// Copy — stub the clipboard, click the box.
		await page.evaluate( () => {
			window.__minnCopied = null;
			navigator.clipboard.writeText = async ( s ) => { window.__minnCopied = s; };
		} );
		await page.click( '#minn-sstat-copy' );
		await waitToast( 'Command copied' );
		t.check( 'Command box copies the command', ( await page.evaluate( () => window.__minnCopied ) ) === st.cmd );

		// --- Sessions list + per-session delete --------------------------------
		t.check( 'Seeded session listed', ( await sessionRows() ) === 1 );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Session minntest' ) ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-modal button' ) ).some( ( b ) => /Delete session files/.test( b.textContent ) ),
		null, { timeout: 8000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => /Delete session files/.test( b.textContent ) ).click();
		} );
		await waitToast( 'Delete session files — done' );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Session minntest' ) ),
		null, { timeout: 8000 } );
		t.check( 'Session delete clears the row', true );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-sstat' ) ).some( ( el ) =>
				el.textContent.includes( 'Working files' ) && el.textContent.includes( 'None' ) ),
		null, { timeout: 8000 } );
		t.check( 'Status card refreshes after the row action', true );

		// --- Workspace cleanup (status action) ----------------------------------
		t.check( 'Fixture reseeded', await seed() );
		await openSurface();
		t.check( 'Reseeded session listed again', ( await sessionRows() ) === 1 );
		t.check( 'Cleanup action offered', await clickStatusAction( 'Clean up working files' ) );
		await waitToast( 'Clean up working files — done' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-sstat' ) ).some( ( el ) =>
				el.textContent.includes( 'Working files' ) && el.textContent.includes( 'None' ) ),
		null, { timeout: 8000 } );
		t.check( 'Cleanup empties the workspace', ( await sessionRows() ) === 0 );

		// --- Regenerate token ----------------------------------------------------
		const before = ( await statusText() ).cmd;
		t.check( 'Regenerate action offered', await clickStatusAction( 'Regenerate token' ) );
		await waitToast( 'Regenerate token — done' );
		await page.waitForFunction( ( old ) => {
			const el = document.querySelector( '.minn-sstat-cmd-box code' );
			return el && el.textContent !== old;
		}, before, { timeout: 8000 } );
		const after = ( await statusText() ).cmd;
		t.check( 'Token regeneration changes the command', !! after && after !== before && after.startsWith( 'disembark connect ' ) );

		// --- ⌘K copy command ------------------------------------------------------
		await page.evaluate( () => {
			window.__minnCopied = null;
			navigator.clipboard.writeText = async ( s ) => { window.__minnCopied = s; };
		} );
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.type( '#minn-palette-input', 'disembark' );
		await page.waitForTimeout( 300 );
		await page.keyboard.press( 'Enter' );
		await waitToast( 'Disembark backup command copied' );
		t.check( 'Palette copies the connect command on demand', ( await page.evaluate( () => window.__minnCopied ) ) === after );
	} finally {
		// Leave the dev site clean: workspace emptied, seed flag off. The
		// last-scan stats option stays — a truthful-looking status card is a
		// nice standing fixture.
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'minn-admin/v1/disembark/cleanup', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ).catch( () => {} );
		} ).catch( () => {} );
		await setOpt( '' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
