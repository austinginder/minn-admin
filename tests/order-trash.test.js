/**
 * Orders: Move to Trash (⋯ menu and right-click, with Undo), the Trash filter,
 * a trashed order's read-only page with Restore, and Delete permanently behind
 * its confirm. Trash and delete ride WooCommerce's own REST delete; restore is
 * Minn's route, gated like that delete (an Editor is refused, a Shop manager
 * is not).
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-trash' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce available', false, 'skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce available', true, '' );
	t.check( 'boot payload says the Trash is on', await page.evaluate( () => window.MINN.wcTrash === true ), '' );

	const apiOn = ( pg, path, opts ) => pg.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );
	const api = ( path, opts ) => apiOn( page, path, opts );
	const statusOf = async ( id ) => {
		const r = await api( `wc/v3/orders/${ id }?_fields=status` );
		return r.status === 200 ? r.body.status : r.status;
	};
	const waitStatus = async ( id, want ) => {
		let got = null;
		for ( let i = 0; i < 15; i++ ) {
			got = await statusOf( id );
			if ( got === want ) break;
			await page.waitForTimeout( 600 );
		}
		return got;
	};
	// Menu entries are clicked by evaluate: a right-click's mousedown can
	// re-open the menu and detach the node a pointer click would aim at.
	const menuEntry = ( label ) => page.evaluate( ( l ) => {
		const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => x.textContent.trim() === l );
		return b ? { danger: b.classList.contains( 'danger' ) } : null;
	}, label );
	const clickMenu = ( label ) => page.evaluate( ( l ) => {
		[ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => x.textContent.trim() === l ).click();
	}, label );
	const menuLabels = () => page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].map( ( b ) => b.textContent.trim() ) );
	const pageReady = async () => {
		await page.waitForSelector( '.minn-order-page .minn-order-body', { timeout: 25000 } );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-page .minn-order-payment .minn-loading' ), null, { timeout: 20000 } );
	};
	const rowMenu = async ( id ) => {
		await page.waitForSelector( `.minn-table-row[data-order="${ id }"]`, { timeout: 20000 } );
		await page.click( `.minn-table-row[data-order="${ id }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
	};

	const suffix = Date.now().toString( 36 );
	const ids = [];
	const subIds = [];
	let pid = null;

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Trash Test ' + suffix, type: 'simple', regular_price: '15.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		const mk = async ( status, paid ) => {
			const r = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( {
					status,
					set_paid: !! paid,
					billing: { first_name: 'Trash', last_name: 'Tester', email: `minn-trash-${ suffix }@example.com`, country: 'US' },
					line_items: [ { product_id: pid, quantity: 1 } ],
				} ),
			} );
			if ( r.body && r.body.id ) ids.push( r.body.id );
			return r.body && r.body.id;
		};
		const aId = await mk( 'pending', true ); // processing once paid
		const bId = await mk( 'on-hold', false );
		t.check( 'fixtures created', !! ( pid && aId && bId ), JSON.stringify( ids ) );

		// ---- ⋯ menu on the order page: Move to Trash, then Undo ----
		await page.goto( `${ BASE }/minn-admin/orders/${ aId }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		await page.click( '#minn-o-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const trashEntry = await menuEntry( 'Move to Trash' );
		t.check( '⋯ menu offers Move to Trash, styled as danger', !! trashEntry && trashEntry.danger, JSON.stringify( await menuLabels() ) );
		await clickMenu( 'Move to Trash' );
		await page.waitForFunction( () => location.pathname.replace( /\/$/, '' ).endsWith( '/minn-admin/orders' ), null, { timeout: 15000 } );
		t.check( 'trashing from the page goes back to Orders', true, '' );
		t.check( 'WooCommerce has the order in the Trash', await waitStatus( aId, 'trash' ) === 'trash', '' );
		const toastText = await page.evaluate( () => ( document.querySelector( '.minn-toast-action' ) || { textContent: '' } ).textContent );
		t.check( 'toast names the order and offers Undo', toastText.indexOf( '#' + aId ) !== -1 && /Undo/.test( toastText ), toastText.trim() );
		// ⌘Z while typing undoes the typing; it must not restore the order.
		await page.click( '#minn-order-search' );
		await page.keyboard.type( 'zz' );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 700 );
		t.check( '⌘Z in a text field leaves the order in the Trash', await statusOf( aId ) === 'trash', '' );
		await page.fill( '#minn-order-search', '' );
		await page.click( '.minn-toast-action .minn-toast-btn' );
		t.check( 'Undo restores the status it had', await waitStatus( aId, 'processing' ) === 'processing', '' );

		// ---- Right-click in the list: Move to Trash ----
		await page.fill( '#minn-order-search', String( bId ) );
		await page.keyboard.press( 'Enter' );
		await rowMenu( bId );
		const rowTrash = await menuEntry( 'Move to Trash' );
		t.check( 'row menu offers Move to Trash, styled as danger', !! rowTrash && rowTrash.danger, JSON.stringify( await menuLabels() ) );
		await clickMenu( 'Move to Trash' );
		t.check( 'row menu trashes the order', await waitStatus( bId, 'trash' ) === 'trash', '' );

		// ---- The Trash filter lists it; All does not ----
		const listed = async ( status, id ) => {
			const r = await api( `wc/v3/orders?status=${ status }&search=${ encodeURIComponent( 'minn-trash-' + suffix ) }&_fields=id` );
			return Array.isArray( r.body ) && r.body.some( ( o ) => o.id === id );
		};
		t.check( 'All leaves trashed orders out', ! await listed( 'any', bId ), '' );
		await page.goto( `${ BASE }/minn-admin/orders?status=trash`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-order="${ bId }"]`, { timeout: 25000 } );
		const rowInfo = await page.evaluate( ( id ) => {
			const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` );
			const pill = row.querySelector( '.minn-status' );
			return { label: pill.textContent.trim(), cls: pill.className, aListed: !! document.querySelector( '.minn-table-row[data-order]' ) };
		}, bId );
		t.check( 'Trash filter lists the trashed order with a Trash badge', rowInfo.label === 'Trash' && /trash-status/.test( rowInfo.cls ), JSON.stringify( rowInfo ) );
		const aInTrashView = await page.evaluate( ( id ) => !! document.querySelector( `.minn-table-row[data-order="${ id }"]` ), aId );
		t.check( 'Trash filter leaves live orders out', ! aInTrashView, '' );
		await rowMenu( bId );
		const trashLabels = await menuLabels();
		t.check( 'a trashed row offers Restore and Delete, no status moves', trashLabels.indexOf( 'Restore' ) !== -1 && trashLabels.indexOf( 'Delete permanently…' ) !== -1 && ! trashLabels.some( ( l ) => /^Mark |Put on hold/.test( l ) ), JSON.stringify( trashLabels ) );
		await page.keyboard.press( 'Escape' );

		// ---- The trashed order's page is read-only, with Restore ----
		await page.goto( `${ BASE }/minn-admin/orders/${ bId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-order-trashnote', { timeout: 25000 } );
		const ro = await page.evaluate( () => ( {
			note: document.querySelector( '.minn-order-trashnote' ).textContent.replace( /\s+/g, ' ' ).trim(),
			pill: document.querySelector( '.minn-order-page-head .minn-status' ).textContent.trim(),
			save: !! document.getElementById( 'minn-order-save' ),
			payment: !! document.querySelector( '.minn-order-payment' ),
			pencils: document.querySelectorAll( '.minn-order-editpen' ).length,
			trashAction: !! document.getElementById( 'minn-o-trash' ),
		} ) );
		t.check( 'trashed page says so and offers Restore', /in the Trash/.test( ro.note ) && /Restore/.test( ro.note ) && ro.pill === 'Trash', JSON.stringify( ro ) );
		t.check( 'trashed page has no edit controls', ! ro.save && ! ro.payment && ro.pencils === 0 && ! ro.trashAction, JSON.stringify( ro ) );
		await page.click( '#minn-o-restore' );
		await page.waitForSelector( '.minn-order-page #minn-order-save', { timeout: 25000 } );
		const back = await page.evaluate( () => ( {
			note: !! document.querySelector( '.minn-order-trashnote' ),
			pill: document.querySelector( '.minn-order-page-head .minn-status' ).textContent.trim(),
		} ) );
		t.check( 'Restore brings the editable page back in place', ! back.note && back.pill === 'On hold', JSON.stringify( back ) );
		t.check( 'WooCommerce has its old status back', await statusOf( bId ) === 'on-hold', '' );

		// ---- Restore is gated like WooCommerce's delete ----
		await api( `wc/v3/orders/${ bId }`, { method: 'DELETE' } );
		const editor = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const ed = await apiOn( editor.page, `minn-admin/v1/wc/orders/${ bId }/restore`, { method: 'POST' } );
		t.check( 'an Editor cannot restore an order', ed.status === 403 || ed.status === 401, String( ed.status ) );
		await editor.ctx.close();
		const mgr = await loginAs( browser, 'minn-shopmgr', 'minn-shopmgr-pass-1' );
		const sm = await apiOn( mgr.page, `minn-admin/v1/wc/orders/${ bId }/restore`, { method: 'POST' } );
		t.check( 'a Shop manager can restore one', sm.status === 200 && sm.body.status === 'on-hold', JSON.stringify( sm ) );
		await mgr.ctx.close();
		const twice = await api( `minn-admin/v1/wc/orders/${ bId }/restore`, { method: 'POST' } );
		t.check( 'restoring a live order is refused', twice.status === 400, String( twice.status ) );

		// ---- Delete permanently: Cancel keeps it, confirm removes it ----
		await api( `wc/v3/orders/${ bId }`, { method: 'DELETE' } );
		await page.goto( `${ BASE }/minn-admin/orders?status=trash`, { waitUntil: 'domcontentloaded' } );
		await rowMenu( bId );
		await clickMenu( 'Delete permanently…' );
		await page.waitForSelector( '.minn-confirm-overlay [data-cancel]', { timeout: 10000 } );
		const confirmText = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent );
		t.check( 'delete asks first, naming the order', confirmText.indexOf( '#' + bId ) !== -1 && /no undo/i.test( confirmText ), confirmText.replace( /\s+/g, ' ' ).trim() );
		await page.click( '.minn-confirm-overlay [data-cancel]' );
		await page.waitForTimeout( 800 );
		t.check( 'Cancel keeps the order', await statusOf( bId ) === 'trash', '' );
		await rowMenu( bId );
		await clickMenu( 'Delete permanently…' );
		await page.waitForSelector( '.minn-confirm-overlay [data-ok]', { timeout: 10000 } );
		await page.click( '.minn-confirm-overlay [data-ok]' );
		let gone = null;
		for ( let i = 0; i < 15; i++ ) {
			gone = await statusOf( bId );
			if ( gone === 404 ) break;
			await page.waitForTimeout( 600 );
		}
		t.check( 'confirmed delete removes the order', gone === 404, String( gone ) );
		await page.waitForFunction( ( id ) => ! document.querySelector( `.minn-table-row[data-order="${ id }"]` ), bId, { timeout: 15000 } )
			.then( () => true ).catch( () => false )
			.then( ( ok ) => t.check( 'the row leaves the Trash list', ok, '' ) );

		// ---- An order that started a subscription asks first ----
		const parentId = await mk( 'pending', true );
		const subRes = await api( 'wc/v3/subscriptions', {
			method: 'POST',
			body: JSON.stringify( { parent_id: parentId, customer_id: 0, status: 'active', billing_period: 'month', billing_interval: 1, billing: { email: `minn-trash-${ suffix }@example.com` } } ),
		} );
		const subId = subRes.body && subRes.body.id;
		if ( subId ) subIds.push( subId );
		t.check( 'subscription fixture created', !! subId, JSON.stringify( subRes.body && subRes.body.code ? subRes.body : subId ) );
		await page.goto( `${ BASE }/minn-admin/orders/${ parentId }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		await page.click( '#minn-o-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await clickMenu( 'Move to Trash' );
		await page.waitForSelector( '.minn-confirm-overlay [data-cancel]', { timeout: 10000 } );
		const warn = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent.replace( /\s+/g, ' ' ) );
		t.check( 'trashing a subscription\'s parent warns about the subscription', warn.indexOf( '#' + subId ) !== -1 && /cancels it/.test( warn ) && /back cancelled/.test( warn ), warn.trim() );
		await page.click( '.minn-confirm-overlay [data-cancel]' );
		await page.waitForTimeout( 700 );
		const keptBoth = ( await statusOf( parentId ) ) !== 'trash' && ( await api( `wc/v3/subscriptions/${ subId }?_fields=status` ) ).body.status === 'active';
		t.check( 'Cancel leaves the order and its subscription alone', keptBoth, '' );

		// ---- The restore route is for orders only ----
		await api( `wc/v3/subscriptions/${ subId }`, { method: 'DELETE' } );
		const subRestore = await api( `minn-admin/v1/wc/orders/${ subId }/restore`, { method: 'POST' } );
		t.check( 'restore refuses a trashed subscription', subRestore.status === 404, String( subRestore.status ) );

		// ---- Quick view: trash from the modal closes it ----
		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.fill( '#minn-order-search', String( aId ) );
		await page.keyboard.press( 'Enter' );
		await rowMenu( aId );
		await clickMenu( 'Quick view' );
		await page.waitForSelector( '#minn-modal-overlay #minn-o-more', { timeout: 20000 } );
		await page.click( '#minn-modal-overlay #minn-o-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await clickMenu( 'Move to Trash' );
		await page.waitForFunction( () => ! document.getElementById( 'minn-modal-overlay' ), null, { timeout: 15000 } )
			.then( () => true ).catch( () => false )
			.then( ( ok ) => t.check( 'trashing from Quick view closes it', ok, '' ) );
		t.check( 'and the order is in the Trash', await waitStatus( aId, 'trash' ) === 'trash', '' );
	} finally {
		for ( const id of subIds ) await api( `wc/v3/subscriptions/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		for ( const id of ids ) await api( `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
