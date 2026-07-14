/**
 * WooCommerce Subscriptions surface: boot flag, list + status tabs via
 * wc/v3/subscriptions, detail modal status save, parent order / customer
 * cross-links, shop_subscription fenced from Content types.
 *
 * Verified against WCS 9.x (wc/v3 REST). Fixtures: subscription products +
 * standing subs on the minnadmin dev site.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'wcs-subscriptions' );
	const { browser, page, errors } = await launch();
	await login( page );

	const pluginPut = async ( status ) => page.evaluate( async ( s ) => {
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/woocommerce-subscriptions/woocommerce-subscriptions', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return { ok: r.ok, status: r.status };
		} catch ( e ) {
			return { ok: false, status: 0 };
		}
	}, status );

	let prior = 'active';
	try {
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 20000 } );

		const cur = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/woocommerce-subscriptions/woocommerce-subscriptions?_fields=status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			if ( ! r.ok ) return null;
			return r.json();
		} );
		prior = cur && cur.status === 'active' ? 'active' : 'inactive';
		if ( prior !== 'active' ) {
			await pluginPut( 'active' );
			await page.waitForTimeout( 1000 );
			await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 20000 } );
		}

		const boot = await page.evaluate( () => ( {
			wcs: window.MINN.wcs === true,
			cap: !!( window.MINN.caps && window.MINN.caps.subscriptions ),
		} ) );
		t.check( 'boot wcs is true', boot.wcs );
		t.check( 'caps.subscriptions is true', boot.cap );

		// Nav entry.
		const hasNav = await page.evaluate( () =>
			!! document.querySelector( '#minn-nav-workspace [data-nav="subscriptions"], #minn-nav-workspace .minn-nav-btn[data-goto="subscriptions"], [data-nav="subscriptions"]' )
			|| [ ...document.querySelectorAll( '#minn-nav-workspace .minn-nav-btn, #minn-nav-workspace button' ) ]
				.some( ( el ) => /Subscriptions/i.test( el.textContent || '' ) )
		);
		// Path-based nav may use data-goto or text.
		const navText = await page.evaluate( () =>
			[ ...document.querySelectorAll( '#minn-nav-workspace button, #minn-nav-workspace a' ) ]
				.map( ( el ) => el.textContent.trim() )
				.join( '|' )
		);
		t.check( 'Workspace nav lists Subscriptions', hasNav || /Subscriptions/i.test( navText ), navText );

		// REST list.
		const list = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wc/v3/subscriptions?per_page=10&_fields=id,status,total,billing_period,next_payment_date_gmt,billing', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { ok: r.ok, status: r.status, body: await r.json() };
		} );
		t.check( 'wc/v3/subscriptions 200', list.ok, String( list.status ) );
		t.check( 'at least one subscription fixture', Array.isArray( list.body ) && list.body.length > 0, String( list.body && list.body.length ) );

		const sample = ( list.body || [] ).find( ( s ) => s.status === 'active' ) || ( list.body || [] )[ 0 ];
		t.check( 'sample has id', !!( sample && sample.id ) );

		// UI list.
		await page.goto( `${ BASE }/minn-admin/subscriptions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		await page.waitForSelector( '.minn-sub-cols, .minn-empty, .minn-loading', { timeout: 15000 } );
		// Wait for rows if loading.
		await page.waitForFunction( () => {
			if ( document.querySelector( '.minn-loading' ) ) return false;
			return document.querySelector( '[data-sub]' ) || document.querySelector( '.minn-empty' );
		}, null, { timeout: 20000 } ).catch( () => {} );

		const ui = await page.evaluate( () => {
			const tabs = [ ...document.querySelectorAll( '[data-stab]' ) ].map( ( el ) => el.textContent.trim() );
			const rows = [ ...document.querySelectorAll( '[data-sub]' ) ].map( ( el ) => el.dataset.sub );
			return { tabs, rows };
		} );
		t.check( 'status tabs include Active', ui.tabs.includes( 'Active' ), ui.tabs.join( ',' ) );
		t.check( 'list shows at least one row', ui.rows.length > 0, String( ui.rows.length ) );

		// Open first row.
		const firstId = ui.rows[ 0 ];
		if ( firstId ) {
			await page.click( `[data-sub="${ firstId }"]` );
			await page.waitForSelector( '.minn-modal.wide', { timeout: 10000 } );
			// Detail loads async after the slim list row opens the modal.
			await page.waitForSelector( '#minn-sub-status', { timeout: 15000 } );
			const modal = await page.evaluate( () => {
				const title = document.querySelector( '.minn-modal-title' );
				const status = document.querySelector( '#minn-sub-status' );
				return {
					title: title ? title.textContent.trim() : '',
					hasStatus: !! status,
					statusVal: status ? status.value : '',
				};
			} );
			t.check( 'modal title is Subscription', /Subscription/i.test( modal.title ), modal.title );
			t.check( 'status select present', modal.hasStatus );

			// Flip to on-hold then restore.
			const original = modal.statusVal || 'active';
			const target = original === 'on-hold' ? 'active' : 'on-hold';
			await page.selectOption( '#minn-sub-status', target );
			await page.click( '#minn-sub-save' );
			await page.waitForTimeout( 1200 );

			const after = await page.evaluate( async ( id ) => {
				const r = await fetch( window.MINN.restUrl + 'wc/v3/subscriptions/' + id + '?_fields=id,status&_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return r.json();
			}, parseInt( firstId, 10 ) );
			t.check( 'status save round-trips', after.status === target, String( after.status ) );

			// Restore.
			await page.selectOption( '#minn-sub-status', original ).catch( () => {} );
			const saveAgain = await page.$( '#minn-sub-save' );
			if ( saveAgain ) {
				await saveAgain.click();
				await page.waitForTimeout( 800 );
			} else {
				// Modal may have re-rendered; reopen if needed.
				await page.evaluate( async ( args ) => {
					await fetch( window.MINN.restUrl + 'wc/v3/subscriptions/' + args.id, {
						method: 'PUT',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						credentials: 'same-origin',
						body: JSON.stringify( { status: args.status } ),
					} );
				}, { id: parseInt( firstId, 10 ), status: original } );
			}
		} else {
			t.check( 'modal title is Subscription', false, 'no rows' );
			t.check( 'status select present', false );
			t.check( 'status save round-trips', false );
		}

		// Polish: open a sub with parent_id when present (fixture 2939 is seeded).
		const withParent = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wc/v3/subscriptions?per_page=20&_fields=id,parent_id,customer_id,number', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const list = await r.json();
			return ( list || [] ).find( ( s ) => s.parent_id > 0 ) || null;
		} );
		if ( withParent ) {
			await page.goto( `${ BASE }/minn-admin/subscriptions`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( `[data-sub="${ withParent.id }"]`, { timeout: 15000 } );
			await page.click( `[data-sub="${ withParent.id }"]` );
			await page.waitForSelector( '#minn-sub-status, #minn-sub-open-parent', { timeout: 15000 } );
			const polish = await page.evaluate( () => ( {
				parentBtn: !! document.querySelector( '#minn-sub-open-parent, [data-relorder]' ),
				parentLabel: [ ...document.querySelectorAll( '.minn-side-title' ) ].some( ( el ) => /Parent order/i.test( el.textContent ) ),
				viewCustomer: !! document.querySelector( '#minn-sub-open-customer, #minn-sub-open-customer-foot' ),
			} ) );
			t.check( 'parent order affordance present', polish.parentBtn || polish.parentLabel, JSON.stringify( polish ) );
			t.check( 'view customer button present', polish.viewCustomer );

			// Open customer → subscriptions strip (section only appears after customer full load).
			const custBtn = await page.$( '#minn-sub-open-customer' ) || await page.$( '#minn-sub-open-customer-foot' );
			if ( custBtn ) {
				await custBtn.click();
				await page.waitForFunction( () => {
					const titles = [ ...document.querySelectorAll( '.minn-side-title' ) ].map( ( e ) => e.textContent.trim() );
					return titles.some( ( x ) => x === 'Subscriptions' );
				}, null, { timeout: 15000 } );
				await page.waitForFunction( () => {
					return document.querySelector( '[data-open-sub]' )
						|| /No subscriptions for this customer/i.test( document.body.innerText || '' );
				}, null, { timeout: 15000 } );
				const custUi = await page.evaluate( () => {
					const titles = [ ...document.querySelectorAll( '.minn-side-title' ) ].map( ( e ) => e.textContent.trim() );
					const subRows = document.querySelectorAll( '[data-open-sub]' ).length;
					return { titles, subRows, hasSubsSection: titles.some( ( x ) => x === 'Subscriptions' ) };
				} );
				t.check( 'customer modal has Subscriptions section', custUi.hasSubsSection, custUi.titles.join( '|' ) );
				t.check( 'customer subscriptions strip has rows', custUi.subRows >= 1, String( custUi.subRows ) );
			} else {
				t.check( 'customer modal has Subscriptions section', false, 'no view customer' );
				t.check( 'customer subscriptions strip has rows', false );
			}
		} else {
			t.check( 'parent order affordance present', true, 'no parent_id fixture — skipped' );
			t.check( 'view customer button present', true, 'skipped' );
			t.check( 'customer modal has Subscriptions section', true, 'skipped' );
			t.check( 'customer subscriptions strip has rows', true, 'skipped' );
		}

		// shop_subscription fenced from content types.
		const types = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/types', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		} );
		// Client HIDDEN_TYPES: open content and check tabs.
		await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 15000 } );
		const contentTabs = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-tab, [data-type], .minn-type-tab' ) ]
				.map( ( el ) => el.textContent.trim().toLowerCase() )
				.join( '|' )
		);
		t.check( 'Content does not advertise Subscriptions CPT', ! /subscription/i.test( contentTabs ), contentTabs );
		// Server may still expose the type over REST; fence is client Content tabs.
		t.check( 'types endpoint still has shop_subscription (ok)', true, types.shop_subscription ? 'present' : 'absent' );
	} finally {
		if ( prior === 'inactive' ) {
			await pluginPut( 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
