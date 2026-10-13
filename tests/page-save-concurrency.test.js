/**
 * Minn's own pages keep changes made after they opened.
 *
 * Each page opens, then something changes the record elsewhere (a payment
 * completing, an import, another tab), then the person saves an unrelated
 * edit on the page. The other change must survive. Order: paid and its
 * billing city changed while the page is open, then the customer note is
 * edited here. Product: the price and short description changed, then the
 * name. Coupon: its amount lowered (a store-credit plugin), then the
 * description. User: the last name changed (their own profile), then the
 * bio. Widget: its text changed (the Customizer), then the title.
 * Settings: the tagline changed, then the site title.
 *
 * Every clean save sending nothing is roundtrip-pages-capture.js's check;
 * this suite saves real edits end to end.
 *
 * SKIPs WooCommerce cases when WooCommerce is not active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'page-save-concurrency' );
	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: ( a.opts && a.opts.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			body: a.opts && a.opts.body ? a.opts.body : undefined,
		} );
		let body = null;
		try { body = await r.json(); } catch ( e ) { /* empty */ }
		return { status: r.status, body };
	}, { path, opts } );
	const suffix = Date.now().toString( 36 );
	let pid = 0;
	let oid = 0;
	let settingsWas = null;
	let couponId = 0;
	let userId = 0;
	let widgetId = '';
	try {
		await login( page );

		const wc = await api( 'wc/v3/products?per_page=1&_fields=id' );
		if ( 200 !== wc.status ) {
			console.log( 'SKIP  WooCommerce not active' );
		} else {
			/* ===== Order ===== */
			const prod = await api( 'wc/v3/products', {
				method: 'POST',
				body: JSON.stringify( { name: 'Minn conc product ' + suffix, type: 'simple', regular_price: '10.00', status: 'publish', short_description: 'as the page opened' } ),
			} );
			pid = prod.body && prod.body.id;
			const order = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( { status: 'pending', billing: { first_name: 'Conc', last_name: 'Tester', email: `conc-${ suffix }@example.com`, city: 'Loaded City', country: 'US' }, line_items: [ { product_id: pid, quantity: 1 } ] } ),
			} );
			oid = order.body && order.body.id;
			t.check( 'Order: fixtures created', !! ( pid && oid ), JSON.stringify( { pid, oid } ) );
			if ( oid ) {
				await page.goto( `${ BASE }/minn-admin/orders/${ oid }`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '.minn-order-page [data-oedit="note"]', { timeout: 25000 } );
				const elsewhere = await api( `wc/v3/orders/${ oid }`, { method: 'PUT', body: JSON.stringify( { status: 'processing', billing: { city: 'Changed City' } } ) } );
				t.check( 'Order: paid and moved elsewhere', 200 === elsewhere.status && elsewhere.body.status === 'processing', String( elsewhere.status ) );
				await page.click( '.minn-order-page [data-oedit="note"]' );
				await page.waitForSelector( '#minn-o-note', { timeout: 10000 } );
				await page.fill( '#minn-o-note', 'Note from the page ' + suffix );
				await page.click( '[data-esave]' );
				await page.waitForFunction( () => ! document.querySelector( '#minn-o-note' ), null, { timeout: 15000 } ).catch( () => null );
				const after = ( await api( `wc/v3/orders/${ oid }?_fields=status,customer_note,billing` ) ).body || {};
				t.check( 'Order: the note saved', after.customer_note === 'Note from the page ' + suffix, after.customer_note );
				t.check( 'Order: the payment\'s status is kept', after.status === 'processing', after.status );
				t.check( 'Order: the billing city changed elsewhere is kept', after.billing && after.billing.city === 'Changed City', after.billing && after.billing.city );
			}

			/* ===== Product ===== */
			if ( pid ) {
				await page.goto( `${ BASE }/minn-admin/products/${ pid }`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
				await page.waitForLoadState( 'networkidle' ).catch( () => {} );
				const elsewhere = await api( `wc/v3/products/${ pid }`, { method: 'PUT', body: JSON.stringify( { regular_price: '22.00', short_description: 'changed elsewhere' } ) } );
				t.check( 'Product: repriced elsewhere', 200 === elsewhere.status, String( elsewhere.status ) );
				await page.fill( '#minn-p-name', 'Minn conc renamed ' + suffix );
				await page.click( '#minn-product-save' );
				await page.waitForFunction( () => {
					const b = document.querySelector( '#minn-product-save' );
					return ! b || ( ! b.disabled && /Save/.test( b.textContent ) );
				}, null, { timeout: 20000 } ).catch( () => null );
				await page.waitForTimeout( 500 );
				const after = ( await api( `wc/v3/products/${ pid }?_fields=name,regular_price,short_description` ) ).body || {};
				t.check( 'Product: the name saved', after.name === 'Minn conc renamed ' + suffix, after.name );
				t.check( 'Product: the price changed elsewhere is kept', String( after.regular_price ) === '22.00', String( after.regular_price ) );
				t.check( 'Product: the short description changed elsewhere is kept', /changed elsewhere/.test( after.short_description || '' ), after.short_description );

				// The quick view from the list saves the same way.
				await page.goto( `${ BASE }/minn-admin/products`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( `.minn-table-row[data-product="${ pid }"] [data-pqv]`, { timeout: 20000 } );
				await page.click( `.minn-table-row[data-product="${ pid }"] [data-pqv]` );
				await page.waitForFunction( () => {
					const m = document.querySelector( '.minn-modal' );
					return m && ! m.textContent.includes( 'Loading product' ) && document.querySelector( '.minn-modal #minn-p-name' );
				}, null, { timeout: 20000 } );
				await page.waitForLoadState( 'networkidle' ).catch( () => {} );
				const qvElsewhere = await api( `wc/v3/products/${ pid }`, { method: 'PUT', body: JSON.stringify( { regular_price: '33.00' } ) } );
				t.check( 'Product quick view: repriced elsewhere', 200 === qvElsewhere.status, String( qvElsewhere.status ) );
				await page.fill( '.minn-modal #minn-p-name', 'Minn conc quick ' + suffix );
				const qvPut = page.waitForResponse( ( r ) => 'PUT' === r.request().method() && new RegExp( `/wc/v3/products/${ pid }$` ).test( r.url() ), { timeout: 20000 } ).catch( () => null );
				await page.click( '.minn-modal #minn-product-save' );
				const qvSent = await qvPut;
				await page.waitForTimeout( 500 );
				const qvAfter = ( await api( `wc/v3/products/${ pid }?_fields=name,regular_price` ) ).body || {};
				t.check( 'Product quick view: the name saved', qvAfter.name === 'Minn conc quick ' + suffix, qvAfter.name );
				t.check( 'Product quick view: the price changed elsewhere is kept', String( qvAfter.regular_price ) === '33.00',
					JSON.stringify( { price: qvAfter.regular_price, sent: qvSent && qvSent.request().postData() } ) );
			}
		}

		/* ===== Coupon ===== */
		if ( 200 === wc.status ) {
			const code = 'minnconc' + suffix;
			const made = await api( 'wc/v3/coupons', { method: 'POST', body: JSON.stringify( { code, amount: '10', discount_type: 'fixed_cart', description: 'as the dialog opened' } ) } );
			couponId = made.body && made.body.id;
			t.check( 'Coupon: fixture created', !! couponId, String( made.status ) );
			if ( couponId ) {
				await page.goto( `${ BASE }/minn-admin/coupons`, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( `.minn-table-row[data-coupon="${ couponId }"]`, { timeout: 20000 } );
				await page.click( `.minn-table-row[data-coupon="${ couponId }"]` );
				await page.waitForSelector( '#minn-c-desc', { timeout: 15000 } );
				await page.waitForTimeout( 400 );
				const elsewhere = await api( `wc/v3/coupons/${ couponId }`, { method: 'PUT', body: JSON.stringify( { amount: '4' } ) } );
				t.check( 'Coupon: balance lowered elsewhere', 200 === elsewhere.status, String( elsewhere.status ) );
				await page.fill( '#minn-c-desc', 'described here ' + suffix );
				const couponSaved = page.waitForResponse( ( r ) => r.url().includes( `coupons/${ couponId }` ) && 'PUT' === r.request().method(), { timeout: 30000 } );
				await page.click( '#minn-coupon-save' );
				await couponSaved;
				const after = ( await api( `wc/v3/coupons/${ couponId }?_fields=amount,description` ) ).body || {};
				t.check( 'Coupon: the description saved', after.description === 'described here ' + suffix, after.description );
				t.check( 'Coupon: the lowered amount is kept', parseFloat( after.amount ) === 4, String( after.amount ) );
			}
		}

		/* ===== User ===== */
		const uname = 'minn-conc-' + suffix;
		const user = await api( 'wp/v2/users', { method: 'POST', body: JSON.stringify( { username: uname, email: uname + '@example.com', password: 'Conc-' + suffix + '-pw-1!', first_name: 'Conc', last_name: 'Loaded', roles: [ 'subscriber' ] } ) } );
		userId = user.body && user.body.id;
		t.check( 'User: fixture created', !! userId, String( user.status ) );
		if ( userId ) {
			await page.goto( `${ BASE }/minn-admin/users/${ userId }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-ue-bio', { timeout: 15000 } );
			await page.waitForLoadState( 'networkidle' ).catch( () => {} );
			const elsewhere = await api( `wp/v2/users/${ userId }`, { method: 'POST', body: JSON.stringify( { last_name: 'ChangedElsewhere' } ) } );
			t.check( 'User: last name changed elsewhere', 200 === elsewhere.status, String( elsewhere.status ) );
			await page.fill( '#minn-ue-bio', 'Bio from the page ' + suffix );
			const userSaved = page.waitForResponse( ( r ) => r.url().includes( `users/${ userId }` ) && 'POST' === r.request().method(), { timeout: 30000 } );
			await page.click( '[data-ue-save]' );
			await userSaved;
			const after = ( await api( `wp/v2/users/${ userId }?context=edit&_fields=last_name,description` ) ).body || {};
			t.check( 'User: the bio saved', after.description === 'Bio from the page ' + suffix, after.description );
			t.check( 'User: the last name changed elsewhere is kept', after.last_name === 'ChangedElsewhere', after.last_name );
		}

		/* ===== Widget ===== */
		const widget = await api( 'wp/v2/widgets', { method: 'POST', body: JSON.stringify( { id_base: 'text', sidebar: 'minn-dev-sidebar', instance: { raw: { title: 'Conc title ' + suffix, text: 'as the dialog opened', filter: false, visual: false } } } ) } );
		widgetId = widget.body && widget.body.id;
		if ( ! widgetId ) {
			console.log( 'SKIP  Widget: no classic sidebar to hold a text widget' );
		} else {
			await page.goto( `${ BASE }/minn-admin/widgets`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( `[data-wedit="${ widgetId }"]`, { timeout: 20000 } );
			await page.click( `[data-wedit="${ widgetId }"]` );
			await page.waitForSelector( '[data-wfield="title"]', { timeout: 10000 } );
			const elsewhere = await api( `wp/v2/widgets/${ widgetId }`, { method: 'POST', body: JSON.stringify( { instance: { raw: { title: 'Conc title ' + suffix, text: 'changed elsewhere', filter: false, visual: false } } } ) } );
			t.check( 'Widget: text changed elsewhere', 200 === elsewhere.status, String( elsewhere.status ) );
			await page.fill( '[data-wfield="title"]', 'Retitled ' + suffix );
			// The save reads the widget fresh first, then writes it.
			const widgetSaved = page.waitForResponse( ( r ) => r.url().includes( `widgets/${ widgetId }` ) && 'POST' === r.request().method(), { timeout: 30000 } );
			await page.click( '#minn-widget-save' );
			await widgetSaved;
			const after = ( ( await api( `wp/v2/widgets/${ widgetId }?context=edit` ) ).body || {} ).instance || {};
			const raw = after.raw || {};
			t.check( 'Widget: the title saved', raw.title === 'Retitled ' + suffix, raw.title );
			t.check( 'Widget: the text changed elsewhere is kept', raw.text === 'changed elsewhere', raw.text );
		}

		/* ===== Settings ===== */
		settingsWas = ( await api( 'wp/v2/settings' ) ).body;
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-key="title"]', { timeout: 20000 } );
		await page.waitForLoadState( 'networkidle' ).catch( () => {} );
		const tagline = 'Tagline changed elsewhere ' + suffix;
		const elsewhere = await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( { description: tagline } ) } );
		t.check( 'Settings: tagline changed elsewhere', 200 === elsewhere.status, String( elsewhere.status ) );
		await page.fill( '[data-key="title"]', settingsWas.title + ' ' + suffix );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-save-settings' );
			return b && ! b.disabled;
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 500 );
		const after = ( await api( 'wp/v2/settings' ) ).body || {};
		t.check( 'Settings: the title saved', after.title === settingsWas.title + ' ' + suffix, after.title );
		t.check( 'Settings: the tagline changed elsewhere is kept', after.description === tagline, after.description );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( settingsWas ) {
			await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( { title: settingsWas.title, description: settingsWas.description } ) } ).catch( () => {} );
		}
		if ( widgetId ) await api( `wp/v2/widgets/${ widgetId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( userId ) await api( `wp/v2/users/${ userId }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
		if ( couponId ) await api( `wc/v3/coupons/${ couponId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( oid ) await api( `wc/v3/orders/${ oid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )();
