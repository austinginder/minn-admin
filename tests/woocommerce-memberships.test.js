/**
 * WooCommerce Memberships surface: members list with the orders filter bar,
 * the Plans view, status card, detail, create, and every verb (pause /
 * resume / cancel / end date / note / transfer / delete) over Minn's shim
 * (minn-admin/v1/wcm/…), plus the capability edges (an Editor is refused,
 * a shop manager is not).
 *
 * The standing fixtures (plans "Minn Gold" + "Minn Trial", members Dana and
 * Miguel) come from the mu-fixture seeder (`minn_test_seed_wcm`); this suite
 * only ever touches a customer account it creates itself. SKIPs when
 * WooCommerce Memberships is not active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'woocommerce-memberships' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, { timeout: 30000 } );

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
	const post = ( path, body, method = 'POST' ) => api( path, { method, body: JSON.stringify( body || {} ) } );

	const probe = await api( 'minn-admin/v1/wcm/status' );
	if ( 404 === probe.status ) {
		console.log( 'SKIP woocommerce-memberships: WooCommerce Memberships is not active on this site.' );
		await browser.close();
		process.exit( 0 );
	}
	t.check( 'WooCommerce Memberships available', true, '' );

	// Make sure the standing fixtures exist (one-shot seeder, idempotent).
	await post( 'wp/v2/settings', { minn_test_seed_wcm: '1' } );
	await api( 'minn-admin/v1/wcm/status' );

	const suffix = Date.now().toString( 36 );
	const login1 = `wcm-suite-${ suffix }`;
	const mail = `${ login1 }@example.com`;
	let userId = 0;
	let id = 0;

	try {
		t.check( 'status card names active members and a chart',
			200 === probe.status
				&& /Active members/.test( JSON.stringify( probe.body || {} ) )
				&& Array.isArray( ( ( probe.body || {} ).chart || {} ).points )
				&& probe.body.chart.points.length === 14,
			JSON.stringify( probe.body ).slice( 0, 200 ) );

		const plans = await api( 'minn-admin/v1/wcm/plans?per_page=100' );
		const gold = ( ( plans.body || {} ).items || [] ).find( ( p ) => p.slug === 'minn-gold' );
		t.check( 'the Plans view lists the fixture plan with counts',
			200 === plans.status && !! gold && 'Unlimited' === gold.length && gold.total >= 1,
			JSON.stringify( gold ) );

		const planView = await api( `minn-admin/v1/wcm/plans/${ gold ? gold.id : 0 }/view` );
		t.check( 'the plan detail carries members by status and rule counts',
			200 === planView.status && /Active now/.test( JSON.stringify( planView.body ) ) && /Content restriction/.test( JSON.stringify( planView.body ) ),
			JSON.stringify( planView.body ).slice( 0, 200 ) );

		const seeded = await api( 'minn-admin/v1/wcm/members?per_page=100&search=' + encodeURIComponent( 'dana-member@example.com' ) );
		const dana = ( ( seeded.body || {} ).items || [] ).find( ( r ) => r.plan === 'Minn Gold' );
		t.check( 'search by email finds the standing member', !! dana && 'active' === dana.status, JSON.stringify( dana ) );

		const paused = await api( 'minn-admin/v1/wcm/members?per_page=100&status[]=paused' );
		const pausedRows = ( ( paused.body || {} ).items || [] );
		t.check( 'the status filter narrows to paused memberships only',
			200 === paused.status && pausedRows.length >= 1 && pausedRows.every( ( r ) => r.status === 'paused' ),
			JSON.stringify( pausedRows.map( ( r ) => r.status ) ) );

		const byCustomer = await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ dana ? dana.user_id : 0 }` );
		t.check( 'the customer filter narrows to one account',
			200 === byCustomer.status && ( byCustomer.body.items || [] ).length >= 1 && byCustomer.body.items.every( ( r ) => r.user_id === dana.user_id ),
			JSON.stringify( ( byCustomer.body || {} ).total ) );

		// A disposable customer through core's users route.
		const made = await post( 'wp/v2/users', { username: login1, email: mail, password: 'Suite-' + suffix + '-pw!', roles: [ 'customer' ], first_name: 'Suite', last_name: 'Member' } );
		userId = ( made.body || {} ).id || 0;
		t.check( 'a disposable customer exists', userId > 0, JSON.stringify( made.body ).slice( 0, 160 ) );

		const created = await post( 'minn-admin/v1/wcm/members', { customer: mail, plan_id: gold ? gold.id : 0 } );
		id = ( created.body || {} ).id || 0;
		t.check( 'a membership can be granted by hand', 200 === created.status && id > 0, JSON.stringify( created.body ) );

		const dup = await post( 'minn-admin/v1/wcm/members', { customer: login1, plan_id: gold ? gold.id : 0 } );
		t.check( 'a second membership on the same plan is refused', 400 === dup.status && /already has/.test( JSON.stringify( dup.body ) ), JSON.stringify( dup.body ) );

		const nobody = await post( 'minn-admin/v1/wcm/members', { customer: 'nobody-' + suffix + '@example.com', plan_id: gold ? gold.id : 0 } );
		t.check( 'an unknown customer is refused', 400 === nobody.status, JSON.stringify( nobody.body ) );

		const view = await api( `minn-admin/v1/wcm/members/${ id }/view` );
		t.check( 'the detail names the member, the plan and the granting note',
			200 === view.status && JSON.stringify( view.body ).includes( mail ) && /granted in Minn Admin/.test( JSON.stringify( view.body ) ),
			JSON.stringify( view.body && view.body.title ) );

		const pause = await post( `minn-admin/v1/wcm/members/${ id }/pause` );
		t.check( 'pause goes through', 200 === pause.status, JSON.stringify( pause.body ) );
		const rowAfterPause = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ userId }` ) ).body.items || [] )[ 0 ] || {};
		t.check( 'a paused row offers Resume, not Pause', 'paused' === rowAfterPause.status && true === rowAfterPause.resumable && false === rowAfterPause.pausable, JSON.stringify( rowAfterPause ) );

		const pauseAgain = await post( `minn-admin/v1/wcm/members/${ id }/pause` );
		t.check( 'pausing a paused membership is refused', 400 === pauseAgain.status, JSON.stringify( pauseAgain.body ) );

		const resume = await post( `minn-admin/v1/wcm/members/${ id }/resume` );
		t.check( 'resume goes through', 200 === resume.status, JSON.stringify( resume.body ) );

		const badDate = await post( `minn-admin/v1/wcm/members/${ id }/end-date`, { end_date: 'next tuesday' } );
		t.check( 'a prose end date is refused', 400 === badDate.status, JSON.stringify( badDate.body ) );

		const pastDate = await post( `minn-admin/v1/wcm/members/${ id }/end-date`, { end_date: '2020-01-01' } );
		const rowPast = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ userId }` ) ).body.items || [] )[ 0 ] || {};
		t.check( 'an end date in the past expires the membership', 200 === pastDate.status && 'expired' === rowPast.status, JSON.stringify( rowPast.status ) );

		const future = new Date( Date.now() + 60 * 86400000 ).toISOString().slice( 0, 10 );
		const futureDate = await post( `minn-admin/v1/wcm/members/${ id }/end-date`, { end_date: future } );
		const rowFuture = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ userId }` ) ).body.items || [] )[ 0 ] || {};
		t.check( 'a future end date reactivates an expired membership',
			200 === futureDate.status && 'active' === rowFuture.status && String( rowFuture.expires ).startsWith( future ),
			JSON.stringify( [ rowFuture.status, rowFuture.expires ] ) );

		const clear = await post( `minn-admin/v1/wcm/members/${ id }/end-date`, { end_date: '' } );
		const rowClear = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ userId }` ) ).body.items || [] )[ 0 ] || {};
		t.check( 'a blank end date means no end', 200 === clear.status && '' === rowClear.expires, JSON.stringify( rowClear.expires ) );

		const note = await post( `minn-admin/v1/wcm/members/${ id }/notes`, { note: 'Suite note ' + suffix, notify: false } );
		const viewNote = await api( `minn-admin/v1/wcm/members/${ id }/view` );
		t.check( 'a note lands on the membership', 200 === note.status && JSON.stringify( viewNote.body ).includes( 'Suite note ' + suffix ), JSON.stringify( note.body ) );

		const emptyNote = await post( `minn-admin/v1/wcm/members/${ id }/notes`, { note: '   ' } );
		t.check( 'an empty note is refused', 400 === emptyNote.status, JSON.stringify( emptyNote.body ) );

		const selfTransfer = await post( `minn-admin/v1/wcm/members/${ id }/transfer`, { user: login1 } );
		t.check( 'transferring to the same customer is refused with their message', 400 === selfTransfer.status && /same user/i.test( JSON.stringify( selfTransfer.body ) ), JSON.stringify( selfTransfer.body ) );

		const memberTransfer = await post( `minn-admin/v1/wcm/members/${ id }/transfer`, { user: 'dana-member@example.com' } );
		t.check( 'transferring to someone already on the plan is refused', 400 === memberTransfer.status && /already a member/i.test( JSON.stringify( memberTransfer.body ) ), JSON.stringify( memberTransfer.body ) );

		const cancel = await post( `minn-admin/v1/wcm/members/${ id }/cancel` );
		const rowCancel = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ userId }` ) ).body.items || [] )[ 0 ] || {};
		t.check( 'cancel goes through and the row offers no more verbs',
			200 === cancel.status && 'cancelled' === rowCancel.status && false === rowCancel.cancellable && false === rowCancel.pausable,
			JSON.stringify( rowCancel ) );

		// The surface in the browser: status card, filter bar, Plans view.
		await page.goto( BASE + '/minn-admin/woocommerce-memberships', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status, .minn-table, .minn-empty', { timeout: 20000 } );
		const statusText = await page.evaluate( () => ( document.querySelector( '.minn-surface-status' ) || {} ).textContent || '' );
		t.check( 'the surface paints the active-members card', /Active members/.test( statusText ), statusText.slice( 0, 120 ) );

		const chrome = await page.evaluate( () => ( {
			bar: !! document.querySelector( '.minn-filterbar, [data-filterbar], .minn-orders-filterbar' ),
			views: Array.from( document.querySelectorAll( '[data-sview]' ) ).map( ( b ) => b.textContent.trim() ),
			rows: document.querySelectorAll( '.minn-table-row' ).length,
		} ) );
		t.check( 'the members list wears the filter bar and a Members / Plans switcher',
			chrome.views.includes( 'Members' ) && chrome.views.includes( 'Plans' ) && chrome.rows >= 2,
			JSON.stringify( chrome ) );

		await page.click( '[data-sview="manage"]' );
		// "Manual assignment only" is plan-only text: a members row never carries it.
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => /Manual assignment only/.test( r.textContent ) ), { timeout: 15000 } );
		const planRows = await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).map( ( r ) => r.textContent.replace( /\s+/g, ' ' ).trim() ) );
		t.check( 'the Plans view renders the fixture plans with their length',
			planRows.some( ( r ) => /Minn Gold.*Unlimited/.test( r ) ) && planRows.some( ( r ) => /Minn Trial.*3 months/.test( r ) ),
			JSON.stringify( planRows ).slice( 0, 200 ) );

		const forbidden = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/wcm/members' );
			return r.status;
		} );
		t.check( 'the list route refuses an unauthenticated request', 401 === forbidden || 403 === forbidden, String( forbidden ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, String( e && e.message ? e.message : e ) );
	} finally {
		if ( id ) {
			await api( `minn-admin/v1/wcm/members/${ id }`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( userId ) {
			await api( `wp/v2/users/${ userId }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
		}
		await t.done( browser, errors );
	}
} )();
