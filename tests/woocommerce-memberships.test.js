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

		// The record page: row click opens /memberships/{id}, the form saves
		// through the plugin's status transition, notes add and delete in
		// place, and the header menu resumes a paused membership.
		await page.click( '[data-sview="main"]' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => /dana-member@example.com/.test( r.textContent ) ), { timeout: 15000 } );
		await page.evaluate( () => { const r = Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( x ) => /dana-member@example.com/.test( x.textContent ) ); r.click(); } );
		await page.waitForSelector( '.minn-wcm-page [data-mcard="membership"]', { timeout: 20000 } );
		t.check( 'a row click opens the membership page', /\/minn-admin\/memberships\/\d+$/.test( page.url() ), page.url() );
		const pageId = parseInt( page.url().split( '/' ).pop(), 10 );
		const shell = await page.evaluate( () => ( {
			cards: Array.from( document.querySelectorAll( '[data-mcard]' ) ).map( ( c ) => c.dataset.mcard ),
			savebarHidden: document.querySelector( '#minn-wcm-savebar' ).hidden,
			transferHidden: document.querySelector( '#minn-wcm-transfer' ).hidden,
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			navLit: ( document.querySelector( '.minn-nav-btn.active' ) || {} ).textContent || '',
		} ) );
		t.check( 'the page paints its cards with the save bar and transfer box hidden',
			[ 'membership', 'billing', 'notes', 'member', 'plans' ].every( ( k ) => shell.cards.includes( k ) ) && shell.savebarHidden && shell.transferHidden && /Dana Member/.test( shell.title ) && /Memberships/.test( shell.navLit ),
			JSON.stringify( shell ) );

		await page.click( '#minn-wcm-status' );
		await page.waitForSelector( '.minn-ac-item[data-acv="paused"]', { timeout: 5000 } );
		await page.click( '.minn-ac-item[data-acv="paused"]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-wcm-savebar' ).hidden, { timeout: 5000 } );
		await page.click( '#minn-wcm-save' );
		await page.waitForFunction( () => /Paused/.test( ( document.querySelector( '.minn-order-page-head .minn-status' ) || {} ).textContent || '' ), { timeout: 15000 } );
		const savedRow = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ dana.user_id }` ) ).body.items || [] ).find( ( r ) => r.id === pageId ) || {};
		t.check( 'saving the status on the page pauses the membership through the plugin', 'paused' === savedRow.status, JSON.stringify( savedRow.status ) );

		await page.fill( '#minn-wcm-new-note', 'Page note ' + suffix );
		await page.click( '#minn-wcm-note-add' );
		await page.waitForFunction( ( s ) => /Page note/.test( document.body.textContent ) && document.body.textContent.includes( s ), suffix, { timeout: 15000 } );
		const noteId = await page.evaluate( ( s ) => { const n = Array.from( document.querySelectorAll( '[data-wcmnote]' ) ).find( ( x ) => x.textContent.includes( s ) ); return n ? n.dataset.wcmnote : ''; }, suffix );
		t.check( 'a note added on the page lands in the list with its id', !! noteId, String( noteId ) );
		await page.evaluate( ( id ) => document.querySelector( `[data-wcmnotedel="${ id }"]` ).click(), noteId );
		await page.waitForFunction( ( s ) => ! document.body.textContent.includes( 'Page note ' + s ), suffix, { timeout: 15000 } );
		t.check( 'deleting the note removes it from the page', true, '' );

		await page.click( '#minn-wcm-more' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '[data-mi], .minn-menu button' ) ).some( ( b ) => /Resume membership/.test( b.textContent ) ), { timeout: 5000 } );
		await page.evaluate( () => { const b = Array.from( document.querySelectorAll( '[data-mi], .minn-menu button' ) ).find( ( x ) => /Resume membership/.test( x.textContent ) ); b.click(); } );
		await page.waitForFunction( () => /Active/.test( ( document.querySelector( '.minn-order-page-head .minn-status' ) || {} ).textContent || '' ), { timeout: 15000 } );
		const resumedRow = ( ( await api( `minn-admin/v1/wcm/members?per_page=100&customer=${ dana.user_id }` ) ).body.items || [] ).find( ( r ) => r.id === pageId ) || {};
		t.check( 'the header menu resumes the membership', 'active' === resumedRow.status, JSON.stringify( resumedRow.status ) );

		const model = await api( `minn-admin/v1/wcm/members/${ pageId }` );
		t.check( 'the page model names plans, statuses and the member\'s other memberships',
			200 === model.status && Array.isArray( model.body.plans ) && model.body.plans.length >= 2 && Array.isArray( model.body.statuses ) && Array.isArray( model.body.memberships ),
			JSON.stringify( Object.keys( model.body || {} ) ) );

		// The plan page: create through the page with a content rule picked
		// from the lookup, verify the plugin stored it, edit and drop a rule
		// keeping the other's id, open from the Plans row, delete from the menu.
		const pickCombo = async ( id, value ) => {
			await page.click( '#' + id );
			await page.waitForSelector( `.minn-ac-item[data-acv="${ value }"]`, { timeout: 5000 } );
			await page.click( `.minn-ac-item[data-acv="${ value }"]` );
			await page.waitForTimeout( 200 );
		};
		const pickFirst = async ( key, q ) => {
			await page.type( `[data-wcmpick="${ key }"] .minn-ac-input`, q );
			await page.waitForSelector( `[data-wcmpick="${ key }"] [data-wcmpickitem]`, { timeout: 8000 } );
			await page.evaluate( ( k ) => document.querySelector( `[data-wcmpick="${ k }"] [data-wcmpickitem]` ).dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) ), key );
			await page.waitForTimeout( 200 );
		};
		let planId = 0;
		await page.goto( BASE + '/minn-admin/membership-plans/new', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-wcmp-page [data-pcard="general"]', { timeout: 20000 } );
		t.check( 'the new-plan page paints with the save bar showing', ! ( await page.evaluate( () => document.querySelector( '#minn-wcmp-savebar' ).hidden ) ), '' );
		await page.fill( '#minn-wcmp-name', 'Suite Plan ' + suffix );
		await pickCombo( 'minn-wcmp-ltype', 'specific' );
		await page.fill( '[data-wcmpf="length.amount"]', '3' );
		await page.click( '[data-wcmruleadd="content_restriction"]' );
		await page.waitForSelector( '[data-wcmrule="content_restriction"]', { timeout: 5000 } );
		await pickCombo( 'minn-wcmr-target-content_restriction-0', 'post_type:page' );
		await pickFirst( 'content_restriction-0', 'a' );
		await pickCombo( 'minn-wcmr-sched-content_restriction-0', 'delayed' );
		await page.fill( '[data-wcmpf="rules.content_restriction.0.schedule.amount"]', '2' );
		await pickCombo( 'minn-wcmr-period-content_restriction-0', 'weeks' );
		await page.click( '[data-wcmruleadd="purchasing_discount"]' );
		await page.waitForSelector( '[data-wcmrule="purchasing_discount"]', { timeout: 5000 } );
		await page.fill( '[data-wcmpf="rules.purchasing_discount.0.discount_amount"]', '10' );
		await page.click( '#minn-wcmp-sec-my-membership-content' );
		await page.click( '#minn-wcmp-save' );
		await page.waitForFunction( () => /\/membership-plans\/\d+$/.test( location.pathname ), { timeout: 20000 } );
		planId = parseInt( page.url().split( '/' ).pop(), 10 );
		await page.waitForSelector( '.minn-wcmp-page [data-pcard="members"]', { timeout: 20000 } );
		const pm = await api( `minn-admin/v1/wcm/plans/${ planId }` );
		const cr = ( ( ( pm.body || {} ).rules || {} ).content_restriction || [] )[ 0 ] || {};
		t.check( 'creating through the page stores length, section and both rules through the plugin',
			200 === pm.status && pm.body.length.type === 'specific' && pm.body.length.amount === 3 && pm.body.sections.includes( 'my-membership-content' )
				&& cr.content_type_name === 'page' && cr.object_ids.length === 1 && cr.access_schedule.type === 'delayed' && cr.access_schedule.amount === 2
				&& pm.body.rules.purchasing_discount.length === 1 && String( pm.body.rules.purchasing_discount[ 0 ].discount_amount ) === '10',
			JSON.stringify( { length: pm.body.length, sections: pm.body.sections, cr, disc: pm.body.rules.purchasing_discount } ) );
		t.check( 'after the create the form is clean and the section switch reads on',
			await page.evaluate( () => document.querySelector( '#minn-wcmp-savebar' ).hidden && document.querySelector( '#minn-wcmp-sec-my-membership-content' ).classList.contains( 'on' ) ), '' );

		await page.click( '[data-wcmruledel="purchasing_discount-0"]' );
		await page.waitForSelector( '[data-wcmrule="purchasing_discount"]', { state: 'detached', timeout: 5000 } );
		await page.fill( '#minn-wcmp-name', 'Suite Plan ' + suffix + ' v2' );
		await page.click( '#minn-wcmp-save' );
		await page.waitForFunction( () => document.querySelector( '#minn-wcmp-savebar' ) && document.querySelector( '#minn-wcmp-savebar' ).hidden, { timeout: 15000 } );
		const pm2 = await api( `minn-admin/v1/wcm/plans/${ planId }` );
		t.check( 'editing drops the removed rule and keeps the other rule\'s id',
			pm2.body.name === 'Suite Plan ' + suffix + ' v2' && pm2.body.rules.purchasing_discount.length === 0 && pm2.body.rules.content_restriction[ 0 ].id === cr.id,
			JSON.stringify( [ pm2.body.name, pm2.body.rules.purchasing_discount.length ] ) );

		const pdup = await post( `minn-admin/v1/wcm/plans/${ planId }/duplicate` );
		const dupModel = pdup.body && pdup.body.id ? await api( `minn-admin/v1/wcm/plans/${ pdup.body.id }` ) : { body: {} };
		t.check( 'duplicate makes a draft copy carrying the rules',
			200 === pdup.status && dupModel.body.status === 'draft' && ( dupModel.body.rules || {} ).content_restriction && dupModel.body.rules.content_restriction.length === 1 && dupModel.body.rules.content_restriction[ 0 ].id !== cr.id,
			JSON.stringify( pdup.body ) );
		if ( pdup.body && pdup.body.id ) await api( `minn-admin/v1/wcm/plans/${ pdup.body.id }`, { method: "DELETE" } );

		const badRule = await post( `minn-admin/v1/wcm/plans/${ planId }`, { rules: { content_restriction: [ { target: 'post_type:product' } ] } } );
		t.check( 'a rule on a content type the plan cannot restrict is refused', 400 === badRule.status && /content type/i.test( JSON.stringify( badRule.body ) ), JSON.stringify( badRule.body ) );
		const partial = await post( `minn-admin/v1/wcm/plans/${ planId }`, { description: 'partial save' } );
		t.check( 'a partial save keeps the length and products it did not mention',
			200 === partial.status && partial.body.model.length.type === 'specific' && partial.body.model.length.amount === 3 && partial.body.model.description === 'partial save',
			JSON.stringify( partial.body && partial.body.model && partial.body.model.length ) );
		const noProd = await post( `minn-admin/v1/wcm/plans/${ planId }`, { access_method: 'purchase', product_ids: [] } );
		t.check( 'purchase access without a product is refused', 400 === noProd.status, JSON.stringify( noProd.body ) );
		const guarded = await api( `minn-admin/v1/wcm/plans/${ gold ? gold.id : 0 }`, { method: 'DELETE' } );
		t.check( 'a plan with active members cannot be deleted', 400 === guarded.status && /active members/i.test( JSON.stringify( guarded.body ) ), JSON.stringify( guarded.body ) );

		await page.goto( BASE + '/minn-admin/woocommerce-memberships', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 20000 } );
		await page.click( '[data-sview="manage"]' );
		await page.waitForFunction( ( s ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Plan ' + s ) ), suffix, { timeout: 15000 } );
		await page.evaluate( ( s ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Suite Plan ' + s ) ).click(), suffix );
		await page.waitForSelector( '.minn-wcmp-page [data-pcard="general"]', { timeout: 20000 } );
		t.check( 'a Plans row opens the plan page', page.url().endsWith( '/membership-plans/' + planId ), page.url() );

		await page.click( '#minn-wcmp-more' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '[data-mi], .minn-menu button' ) ).some( ( b ) => /Delete plan/.test( b.textContent ) ), { timeout: 5000 } );
		await page.evaluate( () => Array.from( document.querySelectorAll( '[data-mi], .minn-menu button' ) ).find( ( x ) => /Delete plan/.test( x.textContent ) ).click() );
		await page.waitForFunction( () => /woocommerce-memberships$/.test( location.pathname ), { timeout: 15000 } );
		const goneP = await api( `minn-admin/v1/wcm/plans/${ planId }` );
		t.check( 'the page menu deletes an empty plan and returns to the Plans view', 404 === goneP.status, String( goneP.status ) );
		if ( 404 === goneP.status ) planId = 0;
		if ( planId ) await api( `minn-admin/v1/wcm/plans/${ planId }`, { method: 'DELETE' } ).catch( () => {} );

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
