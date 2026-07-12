/**
 * License activation, Phase 1 (paste-to-activate on the System Licenses
 * card). Driven entirely through the minn-fixture-activatable provider
 * (mu-fixtures, gated on minn_test_license) so no vendor API is ever
 * called: magic keys cover success, invalid, and the first-class
 * site_limit result. Also proves the guardrails: the pasted secret never
 * appears in any GET response, deactivation asks first, editors get 403,
 * and the real Elementor Pro row (active on this site) advertises its
 * Activate control without being clicked.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'license-activate' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_license: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_license;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// The license manager lives on Extensions -> Licenses now; inactive
	// components render collapsed, so expand them for off-row checks.
	const openSystem = async () => {
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
	const fixtureRow = () => page.evaluateHandle( () =>
		[ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.find( ( el ) => el.textContent.includes( 'Fixture Activatable Pro' ) ) );
	const rowState = async () => {
		const row = await fixtureRow();
		return row.evaluate( ( el ) => ( {
			pill: el.querySelector( '.minn-lic-pill' ).textContent,
			buttons: [ ...el.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ),
		} ) );
	};
	const clickActivate = async () => {
		const row = await fixtureRow();
		await row.evaluate( ( el ) => el.querySelector( '[data-lic="activate"]' ).click() );
	};
	const submitKey = async ( key ) => {
		const row = await fixtureRow();
		// preventScroll: a bare focus() reveals the input by scrolling the
		// panel — the no-scroll-jump check would measure the HELPER's scroll,
		// not the app's (a real user focuses by click, which doesn't scroll).
		await row.evaluate( ( el ) => { el.querySelector( '.minn-lic-key' ).focus( { preventScroll: true } ); } );
		await page.keyboard.type( key );
		await row.evaluate( ( el ) => el.querySelector( '[data-lic-go]' ).click() );
	};
	const waitToast = ( text ) => page.waitForFunction(
		( s ) => document.body.textContent.includes( s ), text, { timeout: 10000 } );
	// Every action nulls the system cache and re-renders; wait for the
	// fixture row (with its controls) to be back before touching the DOM.
	const cardReady = () => page.waitForFunction( () => {
		const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
			.find( ( r ) => r.textContent.includes( 'Fixture Activatable Pro' ) );
		return el && el.querySelector( '[data-lic]' );
	}, null, { timeout: 20000 } );

	try {
		if ( ! await setOpt( true ) ) throw new Error( 'could not enable minn_test_license' );
		await openSystem();

		/* ===== Controls present per provider capability ===== */
		let st = await rowState();
		t.check( 'unlicensed fixture offers Activate only', st.pill === 'No license' && st.buttons.join() === 'activate', JSON.stringify( st ) );
		const epRow = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Elementor Pro' ) );
			return el ? [ ...el.querySelectorAll( '[data-lic]' ) ].map( ( b ) => b.dataset.lic ) : null;
		} );
		t.check( 'real Elementor Pro row advertises Activate (vendor code loaded)', epRow && epRow.includes( 'activate' ), JSON.stringify( epRow ) );
		const staticRow = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Missing Pro' ) );
			return el ? el.querySelectorAll( '[data-lic]' ).length : -1;
		} );
		t.check( 'action-less provider rows draw no controls', staticRow === 0, String( staticRow ) );
		const linked = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Linked Pro' ) );
			if ( ! el ) return null;
			return {
				href: !! el.querySelector( '[data-lic="href"]' ),
				paste: !! el.querySelector( '[data-lic="activate"]' ),
				label: el.querySelector( '[data-lic="href"]' )?.textContent || '',
			};
		} );
		t.check( 'portal-handshake vendors get a link, not a paste field', linked && linked.href && ! linked.paste && /Activate ↗/.test( linked.label ), JSON.stringify( linked ) );

		/* ===== Paste field appears; wrong key fails IN PLACE ===== */
		await clickActivate();
		// Plain text on purpose: a license key isn't a credential and the
		// password type summoned 1Password over the field (Austin's report);
		// the data-*-ignore trio is each manager's documented opt-out.
		const field = await page.$eval( '#minn-sys-licenses .minn-lic-key', ( el ) => ( {
			type: el.type,
			onep: el.hasAttribute( 'data-1p-ignore' ),
			lp: el.dataset.lpignore === 'true',
			bw: el.dataset.bwignore === 'true',
		} ) );
		t.check( 'paste field is plain text with password-manager opt-outs', field.type === 'text' && field.onep && field.lp && field.bw, JSON.stringify( field ) );
		// Type FIRST, then take the scroll baseline: Chrome's caret reveal
		// scrolls the input into view on the first keystroke (real typing
		// does this too) — the check guards the FAILURE path, so the
		// baseline is the position the user typed at.
		await page.evaluate( () => document.querySelector( '#minn-sys-licenses' ).scrollIntoView() );
		const rowT = await fixtureRow();
		await rowT.evaluate( ( el ) => { el.querySelector( '.minn-lic-key' ).focus( { preventScroll: true } ); } );
		await page.keyboard.type( 'totally-wrong-key' );
		const topBefore = await page.evaluate( () => document.querySelector( '.minn-scroll' ).scrollTop );
		const rowGo = await fixtureRow();
		await rowGo.evaluate( ( el ) => el.querySelector( '[data-lic-go]' ).click() );
		await waitToast( 'That key is not recognized' );
		const afterFail = await page.evaluate( () => {
			const i = document.querySelector( '#minn-sys-licenses .minn-lic-key' );
			return {
				top: document.querySelector( '.minn-scroll' ).scrollTop,
				form: !! i,
				selected: !! i && i.value.length > 0 && i.selectionEnd - i.selectionStart === i.value.length,
			};
		} );
		t.check( 'failure keeps the paste field for a retry', afterFail.form );
		t.check( 'typed key is selected for a quick retype', afterFail.selected );
		t.check( 'no scroll jump on failure', Math.abs( afterFail.top - topBefore ) < 4, `${ topBefore } -> ${ afterFail.top }` );

		/* ===== Site limit is a first-class, no-retry result (retry in place) ===== */
		await page.keyboard.type( 'fixture-limit-key' );
		await page.evaluate( () => document.querySelector( '#minn-sys-licenses [data-lic-go]' ).click() );
		await waitToast( 'No activations left on this license' );
		t.check( 'site-limit result names the seat problem', true );

		/* ===== The happy path (third try, same field) ===== */
		await page.evaluate( () => {
			const i = document.querySelector( '#minn-sys-licenses .minn-lic-key' );
			i.focus();
			i.select();
		} );
		await page.keyboard.type( 'fixture-valid-key' );
		await page.evaluate( () => document.querySelector( '#minn-sys-licenses [data-lic-go]' ).click() );
		// Post-activate force-checks updates (commercial plugins only report
		// once a key is stored); toast is either plain or "· checked for
		// updates" / "· Name version is available".
		await waitToast( 'License activated' );
		await page.waitForFunction( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Activatable Pro' ) );
			return el && el.querySelector( '.minn-lic-pill' ).textContent === 'Valid';
		}, null, { timeout: 15000 } );
		st = await rowState();
		t.check( 'activated row flips to Valid with Deactivate + Re-verify', st.buttons.includes( 'deactivate' ) && st.buttons.includes( 'verify' ) && ! st.buttons.includes( 'activate' ), JSON.stringify( st ) );

		// Server-side shape: successful activate/verify carry updates_checked
		// + plugin/theme maps (the force-check that unlocks commercial updates).
		const forceShape = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { provider: 'minn-fixture-activatable', action: 'verify' } ),
			} );
			const j = await r.json();
			return {
				ok: j.ok === true,
				checked: j.updates_checked === true,
				pluginsMap: j.pluginUpdates && typeof j.pluginUpdates === 'object',
				themesMap: j.themeUpdates && typeof j.themeUpdates === 'object',
			};
		} );
		t.check( 'verify force-checks updates and returns maps', forceShape.ok && forceShape.checked && forceShape.pluginsMap && forceShape.themesMap, JSON.stringify( forceShape ) );

		/* ===== The secret never comes back ===== */
		const echoes = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.text() ).includes( 'fixture-valid-key' );
		} );
		t.check( 'pasted key never appears in GET /licenses', ! echoes );

		/* ===== Re-verify via the ⋯ menu (the real UI path) =====
		 * Direct-clicking the hidden .lic-menu button still works; the
		 * regression was openMinnMenu looking for entry.run while the
		 * license ⋯ menu shipped entry.fn — a silent no-op. Drive both
		 * actions through the menu so that bug can't return. */
		const openLicMenu = async ( label ) => {
			const row = await fixtureRow();
			await row.evaluate( ( el ) => el.querySelector( '[data-licmore]' ).click() );
			await page.waitForSelector( '.minn-ctx-menu button', { timeout: 5000 } );
			await page.evaluate( ( lab ) => {
				const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
					.find( ( x ) => x.textContent.trim() === lab );
				if ( ! b ) throw new Error( 'menu missing ' + lab );
				b.click();
			}, label );
		};
		await openLicMenu( 'Re-verify' );
		await waitToast( 'License re-verified' );
		t.check( 'verify round-trips through the ⋯ menu', true );
		await cardReady();

		/* ===== Deactivate asks first, then frees the row ===== */
		let dialogText = '';
		page.once( 'dialog', ( d ) => { dialogText = d.message(); d.accept(); } );
		await openLicMenu( 'Deactivate' );
		await waitToast( 'License deactivated' );
		t.check( 'deactivate confirmed first', /frees the seat|seat frees/i.test( dialogText ), dialogText );
		await page.waitForFunction( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Activatable Pro' ) );
			return el && el.querySelector( '.minn-lic-pill' ).textContent === 'No license';
		}, null, { timeout: 15000 } );
		t.check( 'deactivated row returns to No license', true );

		/* ===== Multi-secret provider renders both fields and activates ===== */
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Two-Field Pro' ) );
			el.querySelector( '[data-lic="activate"]' ).click();
		} );
		const twoFields = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Two-Field Pro' ) );
			return [ ...el.querySelectorAll( '.minn-lic-key' ) ].map( ( i ) => i.placeholder );
		} );
		t.check( 'two-secret vendor renders both labeled fields', twoFields.length === 2 && twoFields[ 0 ] === 'Fixture username' && twoFields[ 1 ] === 'Fixture API key', JSON.stringify( twoFields ) );
		await page.keyboard.type( 'fixture-user' );
		await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Two-Field Pro' ) );
			el.querySelectorAll( '.minn-lic-key' )[ 1 ].focus();
		} );
		await page.keyboard.type( 'fixture-api' );
		await page.keyboard.press( 'Enter' );
		await waitToast( 'License activated' );
		await page.waitForFunction( () => {
			const el = [ ...document.querySelectorAll( '#minn-sys-licenses .minn-lic-item' ) ]
				.find( ( r ) => r.textContent.includes( 'Fixture Two-Field Pro' ) );
			return el && el.querySelector( '.minn-lic-pill' ).textContent === 'Valid';
		}, null, { timeout: 15000 } );
		t.check( 'two-secret activation lands as Valid', true );

		/* ===== manage_options gate on the action route ===== */
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		const editorStatus = await p2.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { provider: 'minn-fixture-activatable', action: 'activate', secret: 'fixture-valid-key' } ),
			} );
			return r.status;
		} );
		t.check( 'editors get 403 from the action route', editorStatus === 403, String( editorStatus ) );
		await ctx2.close();
	} finally {
		await page.evaluate( async () => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			for ( const provider of [ 'minn-fixture-activatable', 'minn-fixture-twofield' ] ) {
				await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { provider, action: 'deactivate' } ),
				} ).catch( () => {} );
			}
		} ).catch( () => {} );
		await setOpt( false ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
