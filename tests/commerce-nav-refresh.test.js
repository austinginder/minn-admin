/**
 * Activating WooCommerce mid-session must add Commerce nav items
 * (Orders, Products, Customers, Coupons) without a hard reload.
 *
 * Proves minn-admin/v1/editor-blocks carries the live wc/caps flags and
 * that the Extensions toggle path (refreshAfterPluginChange) applies them
 * to the sidebar. WooCommerce is a standing fixture: the suite deactivates
 * it and MUST reactivate in finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

const WC = 'woocommerce/woocommerce';

( async () => {
	const t = reporter( 'commerce-nav-refresh' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );

	const setWc = async ( status ) => {
		await page.evaluate( async ( a ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.file, {
				method: 'PUT', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: a.status } ),
			} );
		}, { file: WC, status } );
	};

	const commerceNav = () => page.evaluate( () => {
		const wrap = document.getElementById( 'minn-navgrp-commerce' );
		const ids = Array.from( document.querySelectorAll( '#minn-nav-commerce .minn-nav-btn' ) )
			.map( ( b ) => b.dataset.nav );
		return {
			hidden: ! wrap || wrap.hidden || wrap.offsetParent === null,
			ids,
			wc: !! window.MINN.wc,
			products: !! ( window.MINN.caps && window.MINN.caps.products ),
			orders: !! ( window.MINN.caps && window.MINN.caps.orders ),
		};
	} );

	const installed = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + 'woocommerce/woocommerce', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( r.status !== 200 ) return null;
		const j = await r.json();
		return j.status || null;
	} );
	if ( ! installed ) {
		console.log( 'SKIP: WooCommerce is not installed on this site' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}

	try {
		await setWc( 'active' );
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin[data-plugin="woocommerce/woocommerce"]', { timeout: 20000 } );

		const liveOn = await rest( 'GET', 'minn-admin/v1/editor-blocks' );
		t.check( 'editor-blocks reports WooCommerce while it is active',
			liveOn.status === 200 && liveOn.data && liveOn.data.wc === true
			&& liveOn.data.caps && liveOn.data.caps.products === true && liveOn.data.caps.orders === true,
			JSON.stringify( { wc: liveOn.data && liveOn.data.wc, caps: liveOn.data && liveOn.data.caps && {
				products: liveOn.data.caps.products, orders: liveOn.data.caps.orders,
			} } ) );

		const before = await commerceNav();
		t.check( 'Commerce nav lists store items while WooCommerce is active',
			! before.hidden && before.ids.includes( 'orders' ) && before.ids.includes( 'products' )
			&& before.ids.includes( 'customers' ) && before.ids.includes( 'coupons' ),
			JSON.stringify( before ) );

		/* ===== Deactivate through the Extensions UI ===== */
		await page.$eval( '.minn-plugin[data-plugin="woocommerce/woocommerce"]', ( el ) =>
			el.scrollIntoView( { block: 'center' } ) );
		await page.click( '.minn-plugin[data-plugin="woocommerce/woocommerce"] .minn-switch' );
		await page.waitForFunction( () => {
			const c = document.querySelector( '.minn-plugin[data-plugin="woocommerce/woocommerce"]' );
			return c && ! c.querySelector( '.minn-switch.on' ) && ! c.classList.contains( 'minn-busy' );
		}, null, { timeout: 60000 } );
		await page.waitForFunction( () => {
			const wrap = document.getElementById( 'minn-navgrp-commerce' );
			const ids = Array.from( document.querySelectorAll( '#minn-nav-commerce .minn-nav-btn' ) )
				.map( ( b ) => b.dataset.nav );
			const store = [ 'orders', 'products', 'customers', 'coupons' ];
			return ! window.MINN.wc && store.every( ( id ) => ! ids.includes( id ) )
				&& ( ! wrap || wrap.hidden || wrap.offsetParent === null || ids.length === 0 || store.every( ( id ) => ! ids.includes( id ) ) );
		}, null, { timeout: 20000 } );
		const off = await commerceNav();
		t.check( 'deactivating WooCommerce drops store items without a reload',
			off.wc === false && ! off.ids.includes( 'orders' ) && ! off.ids.includes( 'products' ),
			JSON.stringify( off ) );

		const liveOff = await rest( 'GET', 'minn-admin/v1/editor-blocks' );
		t.check( 'editor-blocks reports WooCommerce off after deactivate',
			liveOff.status === 200 && liveOff.data && liveOff.data.wc === false,
			JSON.stringify( liveOff.data && { wc: liveOff.data.wc } ) );

		/* ===== Activate again through the same switch ===== */
		await page.click( '.minn-plugin[data-plugin="woocommerce/woocommerce"] .minn-switch' );
		await page.waitForFunction( () => {
			const c = document.querySelector( '.minn-plugin[data-plugin="woocommerce/woocommerce"]' );
			return c && c.querySelector( '.minn-switch.on' ) && ! c.classList.contains( 'minn-busy' );
		}, null, { timeout: 60000 } );
		await page.waitForFunction( () => {
			const ids = Array.from( document.querySelectorAll( '#minn-nav-commerce .minn-nav-btn' ) )
				.map( ( b ) => b.dataset.nav );
			return window.MINN.wc === true && ids.includes( 'orders' ) && ids.includes( 'products' )
				&& ids.includes( 'customers' ) && ids.includes( 'coupons' );
		}, null, { timeout: 20000 } );
		const on = await commerceNav();
		t.check( 'activating WooCommerce restores Commerce nav without a reload',
			on.wc === true && on.products && on.orders
			&& on.ids.includes( 'orders' ) && on.ids.includes( 'products' )
			&& on.ids.includes( 'customers' ) && on.ids.includes( 'coupons' ),
			JSON.stringify( on ) );
	} finally {
		try { await setWc( 'active' ); } catch ( e ) { /* restore best-effort */ }
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
