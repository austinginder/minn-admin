/**
 * Role defaults (Users → Role defaults): site-wide per-role policy for the
 * admin experience. An admin sets Author to "Always open Minn" + "Minn bar";
 * the author's profile switches become locked notes, the Minn bar renders on
 * the public site, and a plain sign-in lands the author in Minn. Clearing the
 * policy hands each person's own saved preference back — enforcement is an
 * overlay, never a write.
 */
const { launch, login, loginAs, reporter, BASE, pickCombo } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'role-defaults' );
	await login( page );

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { ok: r.ok, status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );

	let authorCtx = null;
	try {
		// Baseline: no stored policies.
		await rest( 'POST', 'minn-admin/v1/role-defaults', { policies: {} } );

		/* ===== Tab renders for an admin; table shape ===== */
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-utab="roles"]', { timeout: 15000 } );
		await page.click( '[data-utab="roles"]' );
		// Policy controls are the themed strict combobox (data-rdpol wraps).
		await page.waitForSelector( '[data-rdpol="author:signin"] .minn-ac-input', { timeout: 15000 } );
		t.check( 'Role defaults tab renders the policy table', true );
		const sub = await page.evaluate( () => {
			const row = document.querySelector( '[data-rdrow="subscriber"]' );
			if ( ! row ) return null;
			const toolbar = row.querySelector( '[data-rdpol="subscriber:toolbar"]' );
			let toolbarOpts = [];
			try { toolbarOpts = JSON.parse( toolbar.dataset.acopts ).map( ( o ) => o[ 0 ] ); } catch ( e ) { /* leave empty */ }
			return {
				na: !! row.querySelector( '.minn-role-na' ),
				signinSel: !! row.querySelector( '[data-rdpol="subscriber:signin"]' ),
				toolbarOpts,
			};
		} );
		t.check( 'subscriber sign-in is Not available; toolbar offers no Minn bar',
			!! sub && sub.na && ! sub.signinSel && sub.toolbarOpts.length > 0 && ! sub.toolbarOpts.includes( 'minn' ),
			JSON.stringify( sub ) );

		/* ===== Save enables on edit, persists on click ===== */
		const disabledFirst = await page.evaluate( () => document.querySelector( '#minn-rd-save' ).disabled );
		await pickCombo( page, '[data-rdpol="author:signin"] .minn-ac-input', 'minn' );
		await pickCombo( page, '[data-rdpol="author:toolbar"] .minn-ac-input', 'minn' );
		const enabledAfter = await page.evaluate( () => ! document.querySelector( '#minn-rd-save' ).disabled );
		t.check( 'Save is disabled until an edit', disabledFirst && enabledAfter,
			`before:${ disabledFirst } after:${ enabledAfter }` );
		const saveRes = page.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/role-defaults' ) && r.request().method() === 'POST' );
		await page.click( '#minn-rd-save' );
		const sr = await saveRes;
		t.check( 'save POST answers 200', sr.status() === 200, String( sr.status() ) );
		const stored = await rest( 'GET', 'minn-admin/v1/role-defaults' );
		t.check( 'author policy stored',
			stored.ok && stored.data && stored.data.policies && stored.data.policies.author
			&& stored.data.policies.author.signin === 'minn' && stored.data.policies.author.toolbar === 'minn',
			JSON.stringify( stored.data && stored.data.policies ) );

		/* ===== Author profile: locked notes replace the switches ===== */
		authorCtx = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const ap = authorCtx.page;
		await ap.goto( BASE + '/minn-admin/profile', { waitUntil: 'domcontentloaded' } );
		await ap.waitForSelector( '.minn-lock-pill', { timeout: 20000 } );
		const locked = await ap.evaluate( () => ( {
			pills: [ ...document.querySelectorAll( '.minn-lock-pill' ) ].map( ( p ) => p.textContent.trim() ),
			defaultSwitch: !! document.querySelector( '#minn-default-admin' ),
			barSwitch: !! document.querySelector( '#minn-front-bar' ),
			pfToolbar: !! document.querySelector( '#minn-pf-toolbar' ),
			policy: window.MINN.user && window.MINN.user.policy,
		} ) );
		t.check( 'three locked rows, no enforced switches',
			locked.pills.length === 3 && ! locked.defaultSwitch && ! locked.barSwitch && ! locked.pfToolbar,
			JSON.stringify( locked.pills ) );
		t.check( 'boot payload carries the policy',
			!! locked.policy && locked.policy.signin === 'minn' && locked.policy.toolbar === 'minn',
			JSON.stringify( locked.policy ) );

		/* ===== The policy API is admin-only (probe while MINN is on the page) ===== */
		const refused = await ap.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/role-defaults', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return r.status;
		} );
		t.check( 'author is refused the role-defaults API', refused === 403, String( refused ) );

		/* ===== Minn bar enforced on the public site ===== */
		await ap.goto( BASE + '/', { waitUntil: 'domcontentloaded' } );
		const bar = await ap.evaluate( () => ( {
			minnBar: document.body.classList.contains( 'minn-front-bar' ),
			coreBar: !! document.getElementById( 'wpadminbar' ),
		} ) );
		t.check( 'Minn bar renders for the author; core bar suppressed',
			bar.minnBar && ! bar.coreBar, JSON.stringify( bar ) );

		/* ===== A plain sign-in lands in Minn (login_redirect) ===== */
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const lp = await ctx2.newPage();
		await lp.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await lp.fill( '#user_login', 'minn-author' );
		await lp.fill( '#user_pass', 'minn-author-pass-1' );
		await lp.click( '#wp-submit', { noWaitAfter: true } );
		await lp.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 25000 } ).catch( () => {} );
		const landedUrl = lp.url();
		t.check( 'plain sign-in lands in Minn', landedUrl.includes( '/minn-admin' ), landedUrl );
		await ctx2.close();

		/* ===== Clearing the policy hands the preference back ===== */
		await rest( 'POST', 'minn-admin/v1/role-defaults', { policies: {} } );
		await ap.goto( BASE + '/minn-admin/profile', { waitUntil: 'domcontentloaded' } );
		await ap.waitForSelector( '#minn-front-bar', { timeout: 20000 } );
		const restored = await ap.evaluate( () => ( {
			pills: document.querySelectorAll( '.minn-lock-pill' ).length,
			barOn: document.querySelector( '#minn-front-bar' ).classList.contains( 'on' ),
		} ) );
		t.check( 'switches return with the saved preference intact (enforcement never wrote it)',
			restored.pills === 0 && restored.barOn === false, JSON.stringify( restored ) );
	} finally {
		await rest( 'POST', 'minn-admin/v1/role-defaults', { policies: {} } ).catch( () => {} );
		if ( authorCtx ) await authorCtx.ctx.close().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
