/**
 * Store settings — WooCommerce's settings pages inside Minn (phase 1: the
 * option-shaped sections + the email notifications list).
 *
 * Proves: the Commerce nav carries Store settings for a manager and not for
 * an editor; the section list is read from WooCommerce's own registry
 * (pages, sections, extension pages); every field class round-trips through
 * WooCommerce's own save pipeline, verified through WooCommerce's OWN REST
 * (`wc/v3/settings`), never Minn's read: text, switch, select-as-combobox,
 * the country/state combobox, the multi-country picker, number, the
 * retention number+unit pair; an untouched switch is never flipped by a save
 * that did not name it (the absent-checkbox trap); a page-specific save
 * override runs (Advanced's terms-page-equals-checkout rule); rogue keys
 * never write; the emails list switches an email on in place and the edit
 * modal saves an email's subject through WC_Settings_API.
 *
 * State: every value is captured first and restored in `finally` through
 * the same routes.
 */
const { launch, login, loginAs, reporter, BASE, pickCombo } = require( './helpers' );

( async () => {
	const t = reporter( 'wc-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	// A settings save can recycle the PHP worker (rewrite flush, cache
	// version bumps), which drops the NEXT request's socket on this stack;
	// a dropped fetch retries a few times rather than failing the run.
	const rest = ( path, opts ) => page.evaluate( async ( a ) => {
		const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
		for ( let attempt = 0; ; attempt++ ) {
			try {
				const r = await fetch( window.MINN.restUrl + a.path + ( a.path.indexOf( '?' ) === -1 ? '?' : '&' ) + '_cb=' + Math.random(), Object.assign( {
					credentials: 'same-origin',
					headers: Object.assign( { 'X-WP-Nonce': window.MINN.nonce }, a.opts && a.opts.body ? { 'Content-Type': 'application/json' } : {} ),
				}, a.opts || {} ) );
				let body = null;
				try { body = await r.json(); } catch ( e ) {}
				return { status: r.status, body };
			} catch ( e ) {
				if ( attempt >= 8 ) throw e;
				await sleep( 3000 );
			}
		}
	}, { path, opts } );
	const wcGet = async ( group, id ) => ( await rest( `wc/v3/settings/${ group }/${ id }` ) ).body;
	const minnGet = ( page_, section ) => rest( `minn-admin/v1/wc/settings/${ page_ }/${ section || 'default' }` );
	const minnPost = ( page_, section, values ) => rest( `minn-admin/v1/wc/settings/${ page_ }/${ section || 'default' }`, { method: 'POST', body: JSON.stringify( { values } ) } );

	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /minn-admin\/v1\/wc\/settings\//.test( res.url() ), { timeout: 30000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForSelector( '#minn-store-form [data-sset]', { timeout: 20000 } );
		await page.waitForTimeout( 300 );
		return res.status();
	};
	const openSection = async ( id ) => {
		await page.click( `[data-storesec="${ id }"]` );
		await page.waitForSelector( '#minn-store-form [data-sset]', { timeout: 20000 } );
	};

	const before = {};
	const captured = [];
	const capture = async ( group, id ) => {
		const v = await wcGet( group, id );
		before[ group + '/' + id ] = v ? v.value : undefined;
		captured.push( [ group, id ] );
	};

	try {
		/* ===== Nav + gate ===== */
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-commerce', { state: 'attached', timeout: 20000 } );
		t.check( 'boot cap storeSettings for an administrator', await page.evaluate( () => window.MINN.caps.storeSettings === true ) );
		const commerce = await page.$$eval( '#minn-nav-commerce .minn-nav-btn', ( els ) => els.map( ( b ) => b.textContent.trim() ) );
		t.check( 'Store settings closes the Commerce group', commerce[ commerce.length - 1 ] === 'Store settings', commerce.join( '|' ) );

		/* ===== Sections from WooCommerce's registry ===== */
		await page.goto( `${ BASE }/minn-admin/store-settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-store-form [data-sset]', { timeout: 20000 } );
		const secs = await page.$$eval( '[data-storesec]', ( els ) => els.map( ( e ) => e.dataset.storesec ) );
		t.check( 'registry sections: general, products×4, tax, shipping, account, email, advanced×3', [ 'general', 'products', 'products:inventory', 'products:downloadable', 'products:advanced', 'tax', 'shipping:options', 'account', 'email', 'advanced', 'advanced:features' ].every( ( id ) => secs.includes( id ) ), secs.join( '|' ) );
		t.check( 'an extension page rides along (Subscriptions)', secs.includes( 'subscriptions' ) );
		t.check( 'the URL names the first section', /store-settings\/general$/.test( page.url() ) );
		t.check( 'disabled fields count as locked with the wp-admin escape', await page.$$eval( '.minn-panel-locked a', ( els ) => els.some( ( a ) => /page=wc-settings/.test( a.href ) ) ) );

		/* ===== Text + switch + number save through WC's own pipeline ===== */
		await capture( 'general', 'woocommerce_store_city' );
		await capture( 'general', 'woocommerce_calc_discounts_sequentially' );
		await capture( 'general', 'woocommerce_enable_coupons' );
		await capture( 'general', 'woocommerce_price_num_decimals' );
		const couponsBefore = before[ 'general/woocommerce_enable_coupons' ];
		await page.fill( '[data-sset="woocommerce_store_city"]', 'Minn City' );
		await page.click( '[data-sset="woocommerce_calc_discounts_sequentially"]' );
		await page.fill( '[data-sset="woocommerce_price_num_decimals"]', '3' );
		t.check( 'general save 200', ( await clickSave() ) === 200 );
		t.check( 'text round-trips via wc/v3', ( await wcGet( 'general', 'woocommerce_store_city' ) ).value === 'Minn City' );
		const seqAfter = ( await wcGet( 'general', 'woocommerce_calc_discounts_sequentially' ) ).value;
		t.check( 'switch round-trips via wc/v3', seqAfter === ( before[ 'general/woocommerce_calc_discounts_sequentially' ] === 'yes' ? 'no' : 'yes' ), seqAfter );
		t.check( 'number round-trips via wc/v3', String( ( await wcGet( 'general', 'woocommerce_price_num_decimals' ) ).value ) === '3' );
		t.check( 'an untouched switch is not flipped by the save (absent-checkbox trap)', ( await wcGet( 'general', 'woocommerce_enable_coupons' ) ).value === couponsBefore );

		/* ===== Select as combobox, the country/state combobox, the country picker ===== */
		await capture( 'general', 'woocommerce_currency_pos' );
		await capture( 'general', 'woocommerce_default_country' );
		await capture( 'general', 'woocommerce_allowed_countries' );
		await capture( 'general', 'woocommerce_specific_allowed_countries' );
		t.check( 'selects render as themed comboboxes', await page.$eval( '[data-sset="woocommerce_currency_pos"]', ( el ) => el.dataset.ftype === 'combobox' ) );
		await pickCombo( page, '[data-sset="woocommerce_currency_pos"] .minn-ac-input', 'right_space' );
		await page.click( '[data-sset="woocommerce_default_country"] .minn-ac-input' );
		await page.keyboard.type( 'New York' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="US:NY"]', { timeout: 10000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="US:NY"]' );
		await pickCombo( page, '[data-sset="woocommerce_allowed_countries"] .minn-ac-input', 'specific' );
		t.check( 'dependent country picker shows for "specific"', await page.$eval( '[data-srow="woocommerce_specific_allowed_countries"]', ( el ) => ! el.hidden ) );
		// Clear whatever the picker holds, then pick two through the lookup route.
		await page.evaluate( () => document.querySelectorAll( '[data-sset="woocommerce_specific_allowed_countries"] [data-reldel]' ).forEach( ( b ) => b.click() ) );
		for ( const [ q, code ] of [ [ 'Canada', 'CA' ], [ 'Norway', 'NO' ] ] ) {
			await page.fill( '[data-sset="woocommerce_specific_allowed_countries"] .minn-ac-input', q );
			await page.waitForSelector( `[data-sset="woocommerce_specific_allowed_countries"] .minn-ac-item[data-acv="${ code }"]`, { timeout: 10000 } );
			await page.click( `[data-sset="woocommerce_specific_allowed_countries"] .minn-ac-item[data-acv="${ code }"]` );
		}
		t.check( 'general save 200 (comboboxes + picker)', ( await clickSave() ) === 200 );
		t.check( 'select round-trips', ( await wcGet( 'general', 'woocommerce_currency_pos' ) ).value === 'right_space' );
		t.check( 'country/state round-trips as CC:ST', ( await wcGet( 'general', 'woocommerce_default_country' ) ).value === 'US:NY' );
		const specific = ( await wcGet( 'general', 'woocommerce_specific_allowed_countries' ) ).value;
		t.check( 'multi-country picker round-trips as codes', Array.isArray( specific ) && specific.join() === 'CA,NO', JSON.stringify( specific ) );

		/* ===== Retention pair (relative_date_selector, not on wc/v3) ===== */
		const acct = ( await minnGet( 'account' ) ).body;
		const retBefore = { n: acct.values.woocommerce_delete_inactive_accounts, u: acct.values.woocommerce_delete_inactive_accounts__unit };
		await openSection( 'account' );
		await page.fill( '[data-sset="woocommerce_delete_inactive_accounts"]', '6' );
		await pickCombo( page, '[data-sset="woocommerce_delete_inactive_accounts__unit"] .minn-ac-input', 'weeks' );
		t.check( 'account save 200', ( await clickSave() ) === 200 );
		const acct2 = ( await minnGet( 'account' ) ).body;
		t.check( 'retention pair stored as { number, unit }', acct2.values.woocommerce_delete_inactive_accounts === 6 && acct2.values.woocommerce_delete_inactive_accounts__unit === 'weeks',
			JSON.stringify( [ acct2.values.woocommerce_delete_inactive_accounts, acct2.values.woocommerce_delete_inactive_accounts__unit ] ) );
		await minnPost( 'account', '', { woocommerce_delete_inactive_accounts: retBefore.n, woocommerce_delete_inactive_accounts__unit: retBefore.u } );

		/* ===== A page-specific save override runs (Advanced) ===== */
		await capture( 'advanced', 'woocommerce_terms_page_id' );
		const checkout = ( await wcGet( 'advanced', 'woocommerce_checkout_page_id' ) ).value;
		if ( checkout ) {
			const r = await minnPost( 'advanced', '', { woocommerce_terms_page_id: String( checkout ) } );
			t.check( 'advanced save 200', r.status === 200 );
			t.check( 'terms page = checkout page is cleared by WooCommerce\'s own rule', String( ( await wcGet( 'advanced', 'woocommerce_terms_page_id' ) ).value || '' ) === '' );
		} else {
			t.check( 'advanced override probe (no checkout page on this site, skipped)', true );
		}

		/* ===== Rogue keys never write ===== */
		const rogue = await minnPost( 'general', '', { active_plugins: 'evil', woocommerce_not_a_setting: 'x', woocommerce_store_city: 'Minn City' } );
		t.check( 'rogue keys accepted-but-ignored', rogue.status === 200 && ! ( 'woocommerce_not_a_setting' in ( rogue.body.values || {} ) ) );
		t.check( 'active_plugins untouched', await page.evaluate( () => Array.isArray( window.MINN.surfaces ) ) );

		/* ===== Emails: list, switch in place, edit modal ===== */
		await openSection( 'email' );
		await page.waitForSelector( '.minn-store-email', { timeout: 20000 } );
		const emailRows = await page.$$eval( '.minn-store-email', ( els ) => els.length );
		t.check( 'emails list from the mailer', emailRows >= 10, String( emailRows ) );
		t.check( 'customer emails say Customer', await page.$$eval( '.minn-store-email-to', ( els ) => els.some( ( e ) => e.textContent.trim() === 'Customer' ) ) );
		const cancelBefore = ( await wcGet( 'email_customer_cancelled_order', 'enabled' ) ).value;
		const sw = '[data-emailtog="customer_cancelled_order"]';
		await page.click( sw );
		await page.waitForFunction( ( s ) => ! document.querySelector( s ).disabled, sw, { timeout: 15000 } );
		const cancelAfter = ( await wcGet( 'email_customer_cancelled_order', 'enabled' ) ).value;
		t.check( 'email switch saves in place', cancelAfter === ( cancelBefore === 'yes' ? 'no' : 'yes' ), cancelAfter );
		await page.click( sw );
		await page.waitForFunction( ( s ) => ! document.querySelector( s ).disabled, sw, { timeout: 15000 } );
		t.check( 'email switch restores', ( await wcGet( 'email_customer_cancelled_order', 'enabled' ) ).value === cancelBefore );

		const subjBefore = ( await wcGet( 'email_new_order', 'subject' ) ).value;
		await page.click( '[data-emailedit="new_order"]' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="subject"]', { timeout: 20000 } );
		t.check( 'email modal renders the email\'s own form fields', await page.$$eval( '#minn-store-form-modal [data-sset]', ( els ) => els.map( ( e ) => e.dataset.sset ) ).then( ( keys ) => [ 'enabled', 'recipient', 'subject', 'heading' ].every( ( k ) => keys.includes( k ) ) ) );
		await page.fill( '#minn-store-form-modal [data-sset="subject"]', 'Minn test subject {order_number}' );
		const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /wc\/emails\/new_order/.test( res.url() ), { timeout: 30000 } );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		t.check( 'email save 200', ( await wait ).status() === 200 );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		t.check( 'email subject round-trips via wc/v3', ( await wcGet( 'email_new_order', 'subject' ) ).value === 'Minn test subject {order_number}' );
		await rest( 'minn-admin/v1/wc/emails/new_order', { method: 'POST', body: JSON.stringify( { values: { subject: subjBefore || '' } } ) } );
		t.check( 'email subject restores', ( await wcGet( 'email_new_order', 'subject' ) ).value === ( subjBefore || '' ) );

		/* ===== Payments: gateways in order, switch, form, drag order ===== */
		await page.click( '[data-storesec="checkout"]' );
		await page.waitForSelector( '.minn-store-gateway', { timeout: 20000 } );
		const gwRows = await page.$$eval( '.minn-store-gateway', ( els ) => els.map( ( e ) => e.dataset.gateway ) );
		const gwWc = ( await rest( 'wc/v3/payment_gateways' ) ).body.map( ( g ) => g.id );
		t.check( 'gateways list in WooCommerce\'s display order', gwRows.join() === gwWc.join(), gwRows.join() + ' vs ' + gwWc.join() );
		t.check( 'the three core gateways are there', [ 'bacs', 'cheque', 'cod' ].every( ( id ) => gwRows.includes( id ) ) );
		const bacsBefore = ( await rest( 'wc/v3/payment_gateways/bacs' ) ).body.enabled;
		const gsw = '[data-gwtog="bacs"]';
		await page.click( gsw );
		await page.waitForFunction( ( sel ) => ! document.querySelector( sel ).disabled, gsw, { timeout: 15000 } );
		t.check( 'gateway switch saves in place', ( await rest( 'wc/v3/payment_gateways/bacs' ) ).body.enabled === ! bacsBefore );
		await page.click( gsw );
		await page.waitForFunction( ( sel ) => ! document.querySelector( sel ).disabled, gsw, { timeout: 15000 } );
		t.check( 'gateway switch restores', ( await rest( 'wc/v3/payment_gateways/bacs' ) ).body.enabled === bacsBefore );

		const codTitle = ( await rest( 'wc/v3/payment_gateways/cod' ) ).body.title;
		await page.click( '[data-gwedit="cod"]' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="title"]', { timeout: 20000 } );
		t.check( 'COD\'s shipping-method choices load (route name passes its settings gate)', await page.$$eval( '#minn-store-form-modal [data-sset="enable_for_methods"] [data-mcv]', ( els ) => els.length > 0 ) );
		await page.fill( '#minn-store-form-modal [data-sset="title"]', 'Cash via Minn' );
		const gwait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /wc\/payment_gateways\/cod/.test( res.url() ), { timeout: 30000 } );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		t.check( 'gateway save 200', ( await gwait ).status() === 200 );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		t.check( 'gateway title round-trips via wc/v3', ( await rest( 'wc/v3/payment_gateways/cod' ) ).body.title === 'Cash via Minn' );
		await rest( 'minn-admin/v1/wc/payment_gateways/cod', { method: 'POST', body: JSON.stringify( { values: { title: codTitle } } ) } );
		t.check( 'gateway title restores', ( await rest( 'wc/v3/payment_gateways/cod' ) ).body.title === codTitle );
		// A one-field save must carry the gateway's OWN stored values for every
		// untouched field. The object keeps them on itself, not in wp_options
		// under the bare key, so a reader that fell through to get_option()
		// would post defaults back: the gateway switched off and its
		// credentials blanked by a title edit.
		const codBefore = ( await rest( 'wc/v3/payment_gateways/cod' ) ).body;
		const codInstr = codBefore.settings.instructions.value;
		await rest( 'wc/v3/payment_gateways/cod', { method: 'POST', body: JSON.stringify( { enabled: true, settings: { instructions: 'Minn custom instructions' } } ) } );
		await rest( 'minn-admin/v1/wc/payment_gateways/cod', { method: 'POST', body: JSON.stringify( { values: { title: codTitle } } ) } );
		const codAfter = ( await rest( 'wc/v3/payment_gateways/cod' ) ).body;
		t.check( 'one-field gateway save keeps the untouched enabled flag and instructions', codAfter.enabled === true && codAfter.settings.instructions.value === 'Minn custom instructions', JSON.stringify( [ codAfter.enabled, codAfter.settings.instructions.value ] ) );
		await rest( 'wc/v3/payment_gateways/cod', { method: 'POST', body: JSON.stringify( { enabled: codBefore.enabled, settings: { instructions: codInstr } } ) } );

		// Drag the first row below the last one (real mouse drag over HTML5 dnd).
		await page.waitForSelector( '.minn-store-gateway', { timeout: 20000 } );
		const first = gwRows[ 0 ];
		const last = gwRows[ gwRows.length - 1 ];
		const lastBox = await page.$eval( `.minn-store-gateway[data-gateway="${ last }"]`, ( el ) => { const r = el.getBoundingClientRect(); return { w: r.width, h: r.height }; } );
		const owait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /wc\/payment_gateways\/order/.test( res.url() ), { timeout: 30000 } );
		await page.dragAndDrop( `.minn-store-gateway[data-gateway="${ first }"]`, `.minn-store-gateway[data-gateway="${ last }"]`, { targetPosition: { x: Math.round( lastBox.w / 2 ), y: Math.round( lastBox.h - 3 ) } } );
		t.check( 'order save 200', ( await owait ).status() === 200 );
		await page.waitForFunction( ( id ) => { const r = document.querySelectorAll( '.minn-store-gateway' ); return r.length && r[ r.length - 1 ].dataset.gateway === id; }, first, { timeout: 15000 } );
		const expected = gwRows.slice( 1 ).concat( [ first ] );
		const gwAfter = ( await rest( 'wc/v3/payment_gateways' ) ).body.map( ( g ) => g.id );
		t.check( 'drag order lands in woocommerce_gateway_order', gwAfter.join() === expected.join(), gwAfter.join() );
		await rest( 'minn-admin/v1/wc/payment_gateways/order', { method: 'POST', body: JSON.stringify( { ids: gwRows } ) } );
		t.check( 'gateway order restores', ( await rest( 'wc/v3/payment_gateways' ) ).body.map( ( g ) => g.id ).join() === gwRows.join() );

		/* ===== Shipping: zone create/edit/delete, methods, classes ===== */
		await page.click( '[data-storesec="shipping"]' );
		await page.waitForSelector( '#minn-zone-add', { timeout: 20000 } );
		t.check( 'zones list carries the everywhere-else row', !! ( await page.$( '.minn-store-zone-rest [data-zoneopen="0"]' ) ) );
		await page.click( '#minn-zone-add' );
		await page.waitForSelector( '#minn-zone-save', { timeout: 10000 } );
		await page.fill( '#minn-zone-name', 'Minn test zone' );
		await page.fill( '[data-zonefield="regions"] .minn-ac-input', 'Norw' );
		await page.waitForSelector( '[data-zonefield="regions"] .minn-ac-item[data-acv="country:NO"]', { timeout: 10000 } );
		await page.click( '[data-zonefield="regions"] .minn-ac-item[data-acv="country:NO"]' );
		await page.fill( '#minn-zone-postcodes', '0150\n0151' );
		await page.click( '#minn-zone-save' );
		await page.waitForSelector( '#minn-method-add', { timeout: 20000 } );
		const zones = ( await rest( 'wc/v3/shipping/zones' ) ).body;
		const zone = zones.find( ( z ) => z.name === 'Minn test zone' );
		t.check( 'zone created (wc/v3 sees it)', !! zone );
		if ( ! zone ) throw new Error( 'zone missing' );
		const locs = ( await rest( `wc/v3/shipping/zones/${ zone.id }/locations` ) ).body;
		t.check( 'zone regions + postcodes land as locations', JSON.stringify( locs.map( ( l ) => l.type + ':' + l.code ).sort() ) === JSON.stringify( [ 'country:NO', 'postcode:0150', 'postcode:0151' ] ), JSON.stringify( locs ) );

		await page.click( '#minn-method-add' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="cost"]', { timeout: 20000 } );
		t.check( 'added method opens its own instance form (flat rate: title, tax status, cost)', await page.$$eval( '#minn-store-form-modal [data-sset]', ( els ) => els.map( ( e ) => e.dataset.sset ) ).then( ( k ) => k.includes( 'title' ) && k.includes( 'cost' ) ) );
		await page.fill( '#minn-store-form-modal [data-sset="cost"]', '12.50' );
		await page.fill( '#minn-store-form-modal [data-sset="title"]', 'Minn flat' );
		const mwait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /shipping\/zones\/\d+\/methods\/\d+/.test( res.url() ), { timeout: 30000 } );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		t.check( 'method settings save 200', ( await mwait ).status() === 200 );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-store-method .minn-store-email-title' ) ].some( ( e ) => /Minn flat/.test( e.textContent ) ), null, { timeout: 15000 } );
		const methods = ( await rest( `wc/v3/shipping/zones/${ zone.id }/methods` ) ).body;
		t.check( 'method instance settings round-trip via wc/v3', methods.length === 1 && methods[ 0 ].settings.cost.value === '12.50' && methods[ 0 ].settings.title.value === 'Minn flat', JSON.stringify( methods.map( ( m ) => m.settings.cost && m.settings.cost.value ) ) );
		const inst = methods[ 0 ].instance_id;
		await page.click( `[data-methodtog="${ inst }"]` );
		await page.waitForFunction( ( id ) => { const sw = document.querySelector( `[data-methodtog="${ id }"]` ); return sw && ! sw.disabled && ! sw.classList.contains( 'on' ); }, inst, { timeout: 15000 } );
		t.check( 'method switch off lands in is_enabled', ( await rest( `wc/v3/shipping/zones/${ zone.id }/methods/${ inst }` ) ).body.enabled === false );

		await page.fill( '#minn-zone-name', 'Minn test zone 2' );
		await page.click( '#minn-zone-save' );
		await page.waitForFunction( () => /Minn test zone 2/.test( ( document.querySelector( '.minn-store-zone-head .minn-fields-sub' ) || {} ).textContent || '' ), null, { timeout: 15000 } );
		t.check( 'zone rename round-trips', ( await rest( `wc/v3/shipping/zones/${ zone.id }` ) ).body.name === 'Minn test zone 2' );
		page.once( 'dialog', ( dlg ) => dlg.accept() );
		await page.click( '#minn-zone-delete' );
		await page.waitForSelector( '#minn-zone-add', { timeout: 15000 } );
		t.check( 'zone delete removes it', ( await rest( `wc/v3/shipping/zones/${ zone.id }` ) ).status === 404 );

		await page.click( '[data-storesec="shipping:classes"]' );
		await page.waitForSelector( '#minn-class-add', { timeout: 20000 } );
		await page.click( '#minn-class-add' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="name"]', { timeout: 10000 } );
		await page.fill( '#minn-store-form-modal [data-sset="name"]', 'Minn bulky' );
		await page.fill( '#minn-store-form-modal [data-sset="description"]', 'Big things' );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-store-classes .minn-store-email-title' ) ].some( ( e ) => /Minn bulky/.test( e.textContent ) ), null, { timeout: 15000 } );
		const cls = ( await rest( 'wc/v3/products/shipping_classes?per_page=100' ) ).body.find( ( x ) => x.name === 'Minn bulky' );
		t.check( 'shipping class created via wc/v3', !! cls && cls.description === 'Big things' );
		if ( cls ) {
			page.once( 'dialog', ( dlg ) => dlg.accept() );
			await page.click( `[data-classdel="${ cls.id }"]` );
			await page.waitForFunction( ( id ) => ! document.querySelector( `[data-classdel="${ id }"]` ), cls.id, { timeout: 15000 } );
			t.check( 'shipping class deleted', ! ( await rest( 'wc/v3/products/shipping_classes?per_page=100' ) ).body.some( ( x ) => x.id === cls.id ) );
		}

		/* ===== Tax rates: add, edit, delete over wc/v3/taxes ===== */
		await page.click( '[data-storesec="tax:standard"]' );
		await page.waitForSelector( '#minn-rate-add', { timeout: 20000 } );
		await page.click( '#minn-rate-add' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="rate"]', { timeout: 20000 } );
		await pickCombo( page, '#minn-store-form-modal [data-sset="country"] .minn-ac-input', 'US' );
		await page.fill( '#minn-store-form-modal [data-sset="state"]', 'PA' );
		await page.fill( '#minn-store-form-modal [data-sset="postcodes"]', '17601\n17602' );
		await page.fill( '#minn-store-form-modal [data-sset="rate"]', '6.0000' );
		await page.fill( '#minn-store-form-modal [data-sset="name"]', 'Minn PA tax' );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-store-tax' ) ].some( ( e ) => /Minn PA tax/.test( e.textContent ) ), null, { timeout: 15000 } );
		const rate = ( await rest( 'wc/v3/taxes?class=standard&per_page=100' ) ).body.find( ( r ) => r.name === 'Minn PA tax' );
		t.check( 'tax rate created via wc/v3 with postcodes split', !! rate && rate.country === 'US' && rate.state === 'PA' && rate.postcodes.join() === '17601,17602' && rate.rate === '6.0000', JSON.stringify( rate && [ rate.country, rate.state, rate.postcodes, rate.rate ] ) );
		if ( rate ) {
			await page.click( `[data-rateedit="${ rate.id }"]` );
			await page.waitForSelector( '#minn-store-form-modal [data-sset="rate"]', { timeout: 20000 } );
			await page.fill( '#minn-store-form-modal [data-sset="rate"]', '7.2500' );
			await page.click( '#minn-store-form-modal [data-sset="compound"]' );
			await page.click( '#minn-store-form-modal #minn-sset-save' );
			await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
			await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-store-tax' ) ].some( ( e ) => /7\.2500/.test( e.textContent ) ), null, { timeout: 15000 } );
			const after = ( await rest( `wc/v3/taxes/${ rate.id }` ) ).body;
			t.check( 'tax rate edit round-trips (rate + compound)', after.rate === '7.2500' && after.compound === true );
			page.once( 'dialog', ( dlg ) => dlg.accept() );
			await page.click( `[data-ratedel="${ rate.id }"]` );
			await page.waitForFunction( ( id ) => ! document.querySelector( `[data-ratedel="${ id }"]` ), rate.id, { timeout: 15000 } );
			t.check( 'tax rate deleted', ( await rest( `wc/v3/taxes/${ rate.id }` ) ).status === 404 );
		}

		/* ===== Webhooks over wc/v3, API keys through the shim ===== */
		await page.click( '[data-storesec="advanced:webhooks"]' );
		await page.waitForSelector( '#minn-hook-add', { timeout: 20000 } );
		await page.click( '#minn-hook-add' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="name"]', { timeout: 20000 } );
		await page.fill( '#minn-store-form-modal [data-sset="name"]', 'Minn hook' );
		await pickCombo( page, '#minn-store-form-modal [data-sset="topic"] .minn-ac-input', 'action' );
		t.check( 'action topic reveals the event field', await page.$eval( '#minn-store-form-modal [data-srow="action_event"]', ( el ) => ! el.hidden ) );
		await page.fill( '#minn-store-form-modal [data-sset="action_event"]', 'woocommerce_minn_test' );
		await page.fill( '#minn-store-form-modal [data-sset="delivery_url"]', 'https://example.com/minn-hook' );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-webhook] .minn-store-email-title' ) ].some( ( e ) => /Minn hook/.test( e.textContent ) ), null, { timeout: 15000 } );
		const hook = ( await rest( 'wc/v3/webhooks?per_page=100' ) ).body.find( ( w ) => w.name === 'Minn hook' );
		t.check( 'webhook created via wc/v3 with action.{event} topic', !! hook && hook.topic === 'action.woocommerce_minn_test' && hook.delivery_url === 'https://example.com/minn-hook', JSON.stringify( hook && [ hook.topic, hook.status ] ) );
		if ( hook ) {
			await page.click( `[data-hookedit="${ hook.id }"]` );
			await page.waitForSelector( '#minn-store-form-modal [data-sset="status"]', { timeout: 20000 } );
			await pickCombo( page, '#minn-store-form-modal [data-sset="status"] .minn-ac-input', 'paused' );
			await page.click( '#minn-store-form-modal #minn-sset-save' );
			await page.waitForSelector( '#minn-modal-overlay', { state: 'detached', timeout: 15000 } );
			await page.waitForFunction( ( id ) => { const r = document.querySelector( `[data-webhook="${ id }"]` ); return r && /Paused/.test( r.textContent ); }, hook.id, { timeout: 15000 } );
			t.check( 'webhook status edit round-trips', ( await rest( `wc/v3/webhooks/${ hook.id }` ) ).body.status === 'paused' );
			page.once( 'dialog', ( dlg ) => dlg.accept() );
			await page.click( `[data-hookdel="${ hook.id }"]` );
			await page.waitForFunction( ( id ) => ! document.querySelector( `[data-hookdel="${ id }"]` ), hook.id, { timeout: 15000 } );
			t.check( 'webhook deleted', ( await rest( `wc/v3/webhooks/${ hook.id }` ) ).status === 404 );
		}

		await page.click( '[data-storesec="advanced:keys"]' );
		await page.waitForSelector( '#minn-key-add', { timeout: 20000 } );
		await page.click( '#minn-key-add' );
		await page.waitForSelector( '#minn-store-form-modal [data-sset="description"]', { timeout: 20000 } );
		await page.fill( '#minn-store-form-modal [data-sset="description"]', 'Minn key' );
		await pickCombo( page, '#minn-store-form-modal [data-sset="permissions"] .minn-ac-input', 'read_write' );
		await page.click( '#minn-store-form-modal #minn-sset-save' );
		await page.waitForSelector( '#minn-secret-ck', { timeout: 20000 } );
		const secret = await page.evaluate( () => ( { ck: document.querySelector( '#minn-secret-ck' ).value, cs: document.querySelector( '#minn-secret-cs' ).value } ) );
		t.check( 'new key shows consumer key + secret once', /^ck_[0-9a-f]{40}$/.test( secret.ck ) && /^cs_[0-9a-f]{40}$/.test( secret.cs ) );
		await page.click( '#minn-secret-done' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-apikey] .minn-store-email-title' ) ].some( ( e ) => /Minn key/.test( e.textContent ) ), null, { timeout: 15000 } );
		const keyRow = ( await rest( 'minn-admin/v1/wc/api-keys' ) ).body.keys.find( ( k ) => k.description === 'Minn key' );
		t.check( 'key row lists the truncated key, never the secret', !! keyRow && keyRow.truncated === secret.ck.slice( -7 ) && keyRow.permissions === 'read_write' && ! JSON.stringify( keyRow ).includes( secret.cs ) );
		// The key works against WooCommerce's own REST as the owning user (basic auth over HTTPS).
		const probe = await page.evaluate( async ( sec2 ) => {
			const r = await fetch( window.MINN.restUrl + 'wc/v3/system_status/tools?_cb=' + Math.random(), { headers: { Authorization: 'Basic ' + btoa( sec2.ck + ':' + sec2.cs ) }, credentials: 'omit' } );
			return r.status;
		}, secret );
		t.check( 'the new key authenticates against wc/v3', probe === 200, String( probe ) );
		// WooCommerce's Keys screen lets a caller edit or revoke a key only
		// when they can edit its owner or it is their own. A shop manager
		// holds manage_woocommerce but not edit_user on an administrator, so
		// the admin's key must be out of reach while their own is not.
		if ( keyRow ) {
			const mgr = await loginAs( browser, 'minn-shopmgr', 'minn-shopmgr-pass-1' );
			await mgr.page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
			await mgr.page.waitForSelector( '#minn-nav-workspace', { state: 'attached', timeout: 20000 } );
			const mrest = ( path, opts ) => mgr.page.evaluate( async ( a ) => {
				const r = await fetch( window.MINN.restUrl + a.path, Object.assign( { credentials: 'same-origin', headers: Object.assign( { 'X-WP-Nonce': window.MINN.nonce }, a.opts && a.opts.body ? { 'Content-Type': 'application/json' } : {} ) }, a.opts || {} ) );
				let body = null; try { body = await r.json(); } catch ( e ) {}
				return { status: r.status, body };
			}, { path, opts } );
			const steal = await mrest( `minn-admin/v1/wc/api-keys/${ keyRow.id }`, { method: 'POST', body: JSON.stringify( { description: 'Minn key', user: await mgr.page.evaluate( () => window.MINN.user.id ), permissions: 'read_write' } ) } );
			const kill = await mrest( `minn-admin/v1/wc/api-keys/${ keyRow.id }`, { method: 'DELETE' } );
			const still = ( await rest( 'minn-admin/v1/wc/api-keys' ) ).body.keys.find( ( k ) => k.id === keyRow.id );
			t.check( 'shop manager cannot reassign or revoke an administrator\'s key', steal.status === 403 && kill.status === 403 && !! still && still.user === keyRow.user, JSON.stringify( [ steal.status, kill.status, still && still.user, keyRow.user ] ) );
			const own = await mrest( 'minn-admin/v1/wc/api-keys', { method: 'POST', body: JSON.stringify( { description: 'Minn key', permissions: 'read' } ) } );
			const ownDel = own.status === 200 && own.body && own.body.id ? await mrest( `minn-admin/v1/wc/api-keys/${ own.body.id }`, { method: 'DELETE' } ) : { status: 0 };
			t.check( 'shop manager creates and revokes their own key', own.status === 200 && ownDel.status === 200, JSON.stringify( [ own.status, ownDel.status ] ) );
			await mgr.ctx.close();
		}
		if ( keyRow ) {
			page.once( 'dialog', ( dlg ) => dlg.accept() );
			await page.click( `[data-keydel="${ keyRow.id }"]` );
			await page.waitForFunction( ( id ) => ! document.querySelector( `[data-keydel="${ id }"]` ), keyRow.id, { timeout: 15000 } );
			const gone = await page.evaluate( async ( sec2 ) => {
				const r = await fetch( window.MINN.restUrl + 'wc/v3/system_status/tools?_cb=' + Math.random(), { headers: { Authorization: 'Basic ' + btoa( sec2.ck + ':' + sec2.cs ) }, credentials: 'omit' } );
				return r.status;
			}, secret );
			t.check( 'revoked key no longer authenticates', gone === 401, String( gone ) );
		}

		/* ===== An editor is refused ===== */
		const ed = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		await ed.page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await ed.page.waitForSelector( '#minn-nav-workspace', { state: 'attached', timeout: 20000 } );
		t.check( 'editor: no storeSettings cap, no nav item', await ed.page.evaluate( () => ! window.MINN.caps.storeSettings && ! [ ...document.querySelectorAll( '.minn-nav-btn' ) ].some( ( b ) => /Store settings/.test( b.textContent ) ) ) );
		const edStatus = await ed.page.evaluate( async () => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/wc/settings', { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status );
		t.check( 'editor: sections route 403', edStatus === 403, String( edStatus ) );
		await ed.ctx.close();
	} finally {
		// Leftovers from a crashed run: webhook + key, then zone, class, rate.
		try {
			const ws = ( await rest( 'wc/v3/webhooks?per_page=100' ) ).body || [];
			for ( const w of ws ) if ( w.name === 'Minn hook' ) await rest( `wc/v3/webhooks/${ w.id }?force=true`, { method: 'DELETE' } );
			const ks = ( ( await rest( 'minn-admin/v1/wc/api-keys' ) ).body || {} ).keys || [];
			for ( const k of ks ) if ( k.description === 'Minn key' ) await rest( `minn-admin/v1/wc/api-keys/${ k.id }`, { method: 'DELETE' } );
		} catch ( e ) { /* best effort */ }
		try {
			const rs = ( await rest( 'wc/v3/taxes?per_page=100' ) ).body || [];
			for ( const r of rs ) if ( r.name === 'Minn PA tax' ) await rest( `wc/v3/taxes/${ r.id }?force=true`, { method: 'DELETE' } );
		} catch ( e ) { /* best effort */ }
		try {
			const zs = ( await rest( 'wc/v3/shipping/zones' ) ).body || [];
			for ( const z of zs ) if ( /^Minn test zone/.test( z.name ) ) await rest( `wc/v3/shipping/zones/${ z.id }?force=true`, { method: 'DELETE' } );
			const cs = ( await rest( 'wc/v3/products/shipping_classes?per_page=100' ) ).body || [];
			for ( const x of cs ) if ( x.name === 'Minn bulky' ) await rest( `wc/v3/products/shipping_classes/${ x.id }?force=true`, { method: 'DELETE' } );
		} catch ( e ) { /* best effort */ }
		// Restore through WooCommerce's own batch route so a broken Minn save
		// can never leave the fixture mutated.
		const byGroup = {};
		captured.forEach( ( [ g, id ] ) => {
			const v = before[ g + '/' + id ];
			if ( v === undefined ) return;
			( byGroup[ g ] = byGroup[ g ] || [] ).push( { id, value: v } );
		} );
		for ( const g of Object.keys( byGroup ) ) {
			await rest( `wc/v3/settings/${ g }/batch`, { method: 'POST', body: JSON.stringify( { update: byGroup[ g ] } ) } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
