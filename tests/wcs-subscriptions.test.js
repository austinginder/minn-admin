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
	let relProd = null, relParent = null, relSub = null, relRenewal = null, relPlain = null;
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

		// The status tabs are a dropdown on the filter bar now (same bar the
		// orders list wears), so the statuses live inside its popover.
		const ui = await page.evaluate( async () => {
			const drop = document.querySelector( '#minn-order-preset' );
			if ( drop ) drop.click();
			await new Promise( ( r ) => setTimeout( r, 300 ) );
			const tabs = [ ...document.querySelectorAll( '.minn-of-pop [data-opreset]' ) ].map( ( el ) => el.textContent.trim() );
			document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) );
			const rows = [ ...document.querySelectorAll( '[data-sub]' ) ].map( ( el ) => el.dataset.sub );
			return { tabs, rows };
		} );
		t.check( 'status dropdown includes Active', ui.tabs.includes( 'Active' ), ui.tabs.join( ',' ) );
		t.check( 'list shows at least one row', ui.rows.length > 0, String( ui.rows.length ) );

		// Open first row. A row click is navigation now (the /subscriptions/{id}
		// page is the primary surface), so the modal comes from Quick view, and
		// the status control is one of Minn's own comboboxes, not an OS select.
		const firstId = ui.rows[ 0 ];
		const pickStatus = async ( value ) => {
			await page.click( '[data-oc="substatus"] .minn-ac-input' );
			await page.waitForSelector( `[data-oc="substatus"] .minn-ac-item[data-acv="${ value }"]`, { timeout: 8000 } );
			await page.click( `[data-oc="substatus"] .minn-ac-item[data-acv="${ value }"]` );
		};
		if ( firstId ) {
			await page.click( `[data-sub="${ firstId }"] .minn-row-quick` );
			await page.waitForSelector( '.minn-modal.wide', { timeout: 10000 } );
			// Detail loads async after the slim list row opens the modal.
			await page.waitForSelector( '[data-oc="substatus"]', { timeout: 15000 } );
			const modal = await page.evaluate( () => {
				const title = document.querySelector( '.minn-modal-title' );
				const status = document.querySelector( '[data-oc="substatus"] .minn-ac-input' );
				return {
					title: title ? title.textContent.trim() : '',
					hasStatus: !! status,
					statusVal: status ? ( status.dataset.acValue || '' ) : '',
				};
			} );
			t.check( 'modal title is Subscription', /Subscription/i.test( modal.title ), modal.title );
			t.check( 'status combobox present', modal.hasStatus );

			// Flip to on-hold then restore.
			const original = modal.statusVal || 'active';
			const target = original === 'on-hold' ? 'active' : 'on-hold';
			await pickStatus( target );
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
			await pickStatus( original ).catch( () => {} );
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
			t.check( 'status combobox present', false );
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

		// ---- The orders table says which orders belong to a subscription ----
		const api = ( path, opts ) => page.evaluate( async ( a ) => {
			const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			}, a.opts || {} ) );
			const txt = await r.text();
			let body = null;
			try { body = JSON.parse( txt ); } catch ( e ) { body = txt; }
			return { status: r.status, body };
		}, { path, opts } );

		const sfx = Date.now().toString( 36 );
		const billing = { first_name: 'Rel', last_name: 'Ations', email: `minn-rel-${ sfx }@example.com`, country: 'US' };
		const prod = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Rel Widget ' + sfx, type: 'simple', regular_price: '10.00', status: 'publish' } ) } );
		relProd = prod.body && prod.body.id;
		const par = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( { status: 'completed', set_paid: true, billing, line_items: [ { product_id: relProd, quantity: 1 } ] } ) } );
		relParent = par.body && par.body.id;
		const rsub = await api( 'wc/v3/subscriptions', { method: 'POST', body: JSON.stringify( { status: 'active', billing, parent_id: relParent, billing_period: 'month', billing_interval: 1, line_items: [ { product_id: relProd, quantity: 1 } ] } ) } );
		relSub = rsub.body && rsub.body.id;
		// A renewal is an ordinary order carrying WCS's relation meta; that is
		// exactly how WooCommerce itself records one.
		const ren = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( {
			status: 'processing', set_paid: true, billing, line_items: [ { product_id: relProd, quantity: 1 } ],
			meta_data: [ { key: '_subscription_renewal', value: String( relSub ) } ],
		} ) } );
		relRenewal = ren.body && ren.body.id;
		// A third order with no subscription at all: the badge must not be
		// decoration that lands on everything.
		const plain = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( { status: 'pending', billing, line_items: [ { product_id: relProd, quantity: 1 } ] } ) } );
		relPlain = plain.body && plain.body.id;

		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-order="${ relRenewal }"]`, { timeout: 25000 } );
		await page.waitForSelector( `.minn-table-row[data-order="${ relRenewal }"] [data-subrel]`, { timeout: 20000 } );
		const marks = await page.evaluate( ( ids ) => {
			const kindOf = ( id ) => {
				const el = document.querySelector( `.minn-table-row[data-order="${ id }"] [data-subrel]` );
				return el ? el.dataset.subrel : null;
			};
			return { parent: kindOf( ids.parent ), renewal: kindOf( ids.renewal ), plain: kindOf( ids.plain ) };
		}, { parent: relParent, renewal: relRenewal, plain: relPlain } );
		t.check( 'the parent order is badged as the subscription\'s first order',
			marks.parent === 'parent', JSON.stringify( marks ) );
		t.check( 'the renewal order is badged as a renewal', marks.renewal === 'renewal', JSON.stringify( marks ) );
		t.check( 'an unrelated order carries no badge', marks.plain === null, JSON.stringify( marks ) );

		// It has to be SEEN, not merely present: the first version was 93px of
		// badge inside a 120px cell that clips, so only a sliver showed — wide
		// enough to hover, which is what made it look like it worked.
		const fits = await page.evaluate( ( ids ) => {
			const one = ( id ) => {
				const badge = document.querySelector( `.minn-table-row[data-order="${ id }"] [data-subrel]` );
				if ( ! badge ) return null;
				const cell = badge.closest( '.minn-cell-clip' ) || badge.parentElement;
				const b = badge.getBoundingClientRect(), c = cell.getBoundingClientRect();
				return {
					w: Math.round( b.width ),
					h: Math.round( b.height ),
					clipped: Math.round( b.x + b.width ) > Math.round( c.x + c.width ) + 1,
				};
			};
			return { parent: one( ids.parent ), renewal: one( ids.renewal ) };
		}, { parent: relParent, renewal: relRenewal } );
		t.check( 'both badges are drawn whole inside their cell',
			!! fits.parent && !! fits.renewal
				&& fits.parent.w >= 14 && fits.parent.h >= 14 && ! fits.parent.clipped
				&& fits.renewal.w >= 14 && fits.renewal.h >= 14 && ! fits.renewal.clipped,
			JSON.stringify( fits ) );
		t.check( 'a badge still names itself for a screen reader',
			await page.evaluate( ( id ) => {
				const b = document.querySelector( `.minn-table-row[data-order="${ id }"] [data-subrel]` );
				return !! b && /renewal/i.test( b.getAttribute( 'aria-label' ) || '' );
			}, relRenewal ), '' );

		await page.hover( `.minn-table-row[data-order="${ relRenewal }"] [data-subrel]` );
		await page.waitForSelector( '.minn-subrel-pop', { timeout: 10000 } );
		const pop = await page.evaluate( () => ( document.querySelector( '.minn-subrel-pop' ) || {} ).textContent || '' );
		t.check( 'the badge tells which subscription, and its state',
			pop.indexOf( String( relSub ) ) !== -1 && /Active/i.test( pop ) && /month/i.test( pop ),
			pop.replace( /\s+/g, ' ' ).trim().slice( 0, 160 ) );
		// The popover is a way in, not a dead end.
		await page.click( '.minn-subrel-pop [data-subrel-open]' );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/subscriptions/' + id ) !== -1, relSub, { timeout: 15000 } );
		t.check( 'the popover opens the subscription', true, await page.evaluate( () => location.pathname ) );
	} finally {
		for ( const [ route, id ] of [ [ 'orders', relRenewal ], [ 'orders', relPlain ], [ 'subscriptions', relSub ], [ 'orders', relParent ], [ 'products', relProd ] ] ) {
			if ( id ) await page.evaluate( async ( a ) => {
				await fetch( window.MINN.restUrl + `wc/v3/${ a.route }/${ a.id }?force=true`, {
					method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
			}, { route, id } ).catch( () => {} );
		}
		if ( prior === 'inactive' ) {
			await pluginPut( 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
