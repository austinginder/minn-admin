/**
 * The YITH Gift Cards surface: list, filter bar, search, detail, create and
 * the three verbs. YITH exposes no REST of its own, so everything here rides
 * Minn's shim (minn-admin/v1/ywgc/…) over the gift_card CPT.
 *
 * The fixture is the honest one: a gift card is born by buying it. The suite
 * creates its own gift-card product, completes an order holding it, and YITH
 * issues the card, exactly as a customer's purchase would.
 *
 * Every write is verified against what got STORED, and through YITH's own
 * object rather than the row builder that wrote it: the balance check reads
 * the detail's sectionsRoute, which hydrates YITH_YWGC_Gift_Card, so a shim
 * that lied to itself would still fail here.
 *
 * SKIPs cleanly when YITH WooCommerce Gift Cards is not installed.
 *
 * KNOWN GAP: the gift cards this suite issues are left behind, both the one it
 * buys and the ones it creates by hand. The adapter has no delete verb
 * (deliberately out of scope), and the CPT is not in REST, so there is no way
 * to remove them from here. The product and the order are cleaned up.
 *
 * One branch is deliberately uncovered: resend refusing a card that carries a
 * recipient but is not digital. Create cannot produce that combination (it
 * sets is_digital from the recipient), and only YITH itself writes it, from a
 * physical gift-card purchase.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'yith-gift-cards' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	// The list request carries the filters, so assert the query string and not
	// only the rows: filtering happens on the server or it is not filtering.
	const sent = [];
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( /\/minn-admin\/v1\/ywgc\/gift-cards\?/.test( u ) ) sent.push( decodeURIComponent( u ) );
	} );
	const lastQuery = () => ( sent.length ? sent[ sent.length - 1 ] : '' );
	const waitForQuery = async ( re ) => {
		for ( let i = 0; i < 60; i++ ) {
			if ( sent.some( ( u ) => re.test( u ) ) ) return true;
			await new Promise( ( r ) => setTimeout( r, 250 ) );
		}
		return false;
	};

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

	// The adapter registers its routes only while YITH is active, so a 404
	// here IS "the plugin is not on this site". Skip with exit 0 (the
	// multisite suite's convention) so run-all is unaffected.
	const probe = await api( 'minn-admin/v1/ywgc/status' );
	if ( 404 === probe.status ) {
		console.log( 'SKIP yith-gift-cards: YITH WooCommerce Gift Cards is not active on this site.' );
		await browser.close();
		process.exit( 0 );
	}
	t.check( 'YITH WooCommerce Gift Cards available', true, '' );

	const suffix = Date.now().toString( 36 );
	let productId = null;
	let orderId = null;
	let card = null;

	const rows = () => page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row[data-sitem]' ) )
		.map( ( r ) => r.textContent.replace( /\s+/g, ' ' ).trim() ) );
	const waitRows = ( predicate, arg ) => page.waitForFunction( ( a ) => {
		if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
		const texts = Array.from( document.querySelectorAll( '.minn-table-row[data-sitem]' ) )
			.map( ( r ) => r.textContent.replace( /\s+/g, ' ' ).trim() );
		return a.want ? texts.some( ( x ) => x.includes( a.code ) ) : ! texts.some( ( x ) => x.includes( a.code ) );
	}, { want: predicate, code: arg }, { timeout: 15000 } ).then( () => true ).catch( () => false );

	// The shared bar's controls, driven exactly as the orders suite drives
	// them: same ids, same popover, because it is the same bar.
	const pickPreset = async ( slug ) => {
		await page.click( '#minn-order-preset' );
		await page.waitForSelector( `.minn-of-pop [data-opreset="${ slug }"]`, { timeout: 8000 } );
		await page.click( `.minn-of-pop [data-opreset="${ slug }"]` );
	};
	const presetLabel = () => page.evaluate( () => {
		const b = document.querySelector( '#minn-order-preset' );
		return b ? b.textContent.replace( /\s+/g, ' ' ).trim() : '';
	} );
	const openFilterMenu = async ( which ) => {
		await page.click( '#minn-order-addfilter' );
		await page.waitForSelector( `[data-offilter="${ which }"]`, { timeout: 8000 } );
		await page.click( `[data-offilter="${ which }"]` );
		await page.waitForSelector( '.minn-of-pop', { timeout: 8000 } );
	};
	// Multi-select lives behind Add filter → Status (the dropdown is the
	// single-status shortcut), and it waits for Apply because it is multi.
	const pickStatuses = async ( slugs ) => {
		await openFilterMenu( 'status' );
		for ( const slug of slugs ) {
			await page.click( `.minn-of-pop [data-ofval="${ slug }"]` );
		}
		await page.click( '.minn-of-pop [data-ofapply]' );
	};
	const chipLabels = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '[data-ofchip]' ) ).map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) );
	const settled = () => page.waitForFunction(
		() => ! document.querySelector( '#minn-view .minn-loading' ), null, { timeout: 15000 } ).catch( () => null );
	const clearFilters = async () => {
		// Clicking while a reload is in flight lands on a toolbar that is
		// about to be replaced, so the click is lost. Let the list settle.
		await settled();
		if ( ! await page.$( '#minn-order-clearfilters' ) ) return;
		await page.click( '#minn-order-clearfilters' );
		await page.waitForFunction( () => ! document.querySelector( '[data-ofchip]' ), null, { timeout: 15000 } ).catch( () => null );
		await settled();
	};

	// Re-read one card through the shim's own list, which reads the database
	// fresh on every call.
	const readCard = async ( id ) => {
		const r = await api( 'minn-admin/v1/ywgc/gift-cards?per_page=100' );
		return ( ( r.body && r.body.items ) || [] ).find( ( c ) => c.id === id ) || null;
	};

	try {
		// --- fixtures: a gift-card product, then a completed order holding it ---
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Suite Gift Card ' + suffix,
				type: 'gift-card',
				status: 'publish',
				virtual: true,
			} ),
		} );
		productId = prod.body && prod.body.id;
		t.check( 'a gift-card product can be created', 'gift-card' === ( prod.body || {} ).type, JSON.stringify( prod.body && prod.body.type ) );

		const order = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'completed',
				billing: { first_name: 'Suite', last_name: 'Buyer', email: `gc-${ suffix }@example.com` },
				line_items: [ { product_id: productId, quantity: 1, total: '40.00', subtotal: '40.00' } ],
			} ),
		} );
		orderId = order.body && order.body.id;

		// YITH issues the card on the status transition; give it a beat.
		for ( let i = 0; i < 20 && ! card; i++ ) {
			const list = await api( 'minn-admin/v1/ywgc/gift-cards?per_page=100' );
			card = ( ( list.body && list.body.items ) || [] ).find( ( c ) => c.order === '#' + orderId ) || null;
			if ( ! card ) await page.waitForTimeout( 500 );
		}
		t.check( 'buying the product issues a gift card', !! card, 'order #' + orderId );
		if ( ! card ) throw new Error( 'no gift card issued' );

		t.check( 'the issued card carries the order amount', '$40.00' === card.amount, JSON.stringify( card ) );
		t.check( 'a fresh card reads as active', 'active' === card.status && true === card.enabled, JSON.stringify( card.status ) );

		// --- the list ---
		await page.goto( BASE + '/minn-admin/yith-gift-cards', { waitUntil: 'domcontentloaded' } );
		t.check( 'the card is listed', await waitRows( true, card.code ), card.code );

		const statusText = await page.evaluate( () =>
			( document.querySelector( '.minn-surface-status' ) || {} ).textContent || '' );
		t.check( 'the status card reports the outstanding balance',
			/Outstanding balance/i.test( statusText ) && /active gift card/i.test( statusText ), statusText.slice( 0, 120 ) );

		// --- the shared orders bar, not a pill strip ---
		t.check( 'the surface wears the orders filter bar',
			await page.evaluate( () => !! document.querySelector( '.minn-order-bar #minn-order-preset' )
				&& !! document.querySelector( '#minn-order-addfilter' )
				&& ! document.querySelector( '[data-stab]' ) ), '' );

		// --- status narrows on the server ---
		await pickPreset( 'disabled' );
		t.check( 'picking Disabled hides an active card', await waitRows( false, card.code ), lastQuery() );
		t.check( 'the status pick is a server query', /status(\[\])?=disabled/.test( lastQuery() ), lastQuery() );
		t.check( 'the status pick lands in the URL',
			/status=disabled/.test( await page.evaluate( () => location.search ) ),
			await page.evaluate( () => location.search ) );
		t.check( 'the chip names the filter',
			/Status: Disabled/.test( ( await chipLabels() ).join( ' ' ) ), JSON.stringify( await chipLabels() ) );

		// The thing a tab strip could never say: two statuses at once. Start
		// clean, because the popover TOGGLES each slug and Disabled is
		// already on from the pick above.
		await clearFilters();
		await pickStatuses( [ 'disabled', 'dismissed' ] );
		const twoOk = await waitForQuery( /status\[\]=disabled(&|.)*status\[\]=dismissed/ );
		t.check( 'two statuses ride one query', twoOk, lastQuery() );
		// The label is painted by the re-render, which lands after the request
		// goes out. A surface's soft reload dims the list instead of showing
		// .minn-loading, so wait for the label itself rather than for a
		// loading node that never appears here.
		const counted = await page.waitForFunction(
			() => /\d+ statuses/i.test( ( document.querySelector( '#minn-order-preset' ) || {} ).textContent || '' ),
			null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'the dropdown says how many', counted, await presetLabel() );

		// --- a date window, through Add filter ---
		await clearFilters();
		await openFilterMenu( 'date' );
		await page.click( '.minn-of-pop [data-ofval="30"]' );
		t.check( 'a date window is a server query', await waitForQuery( /[?&]after=/ ), lastQuery() );
		t.check( 'the card issued today survives the window', await waitRows( true, card.code ), lastQuery() );

		// --- the filters survive a reload, because a filtered list is a place ---
		await pickPreset( 'active' );
		await waitForQuery( /status(\[\])?=active/ );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-order-bar', { timeout: 30000 } );
		t.check( 'the filters come back from the URL', 'Active' === await presetLabel(), await presetLabel() );

		await clearFilters();
		t.check( 'Clear all shows it again', await waitRows( true, card.code ), lastQuery() );

		// --- search reaches the code ---
		// The search box debounces 350ms before it reloads, so asserting on
		// the rows alone passes on the UNFILTERED list (the card is in both).
		// Wait for the request that carries the term.
		await page.fill( '#minn-order-search', card.code );
		const searched = await waitForQuery( new RegExp( 'search=' + card.code ) );
		t.check( 'search is a server query', searched, lastQuery() );
		t.check( 'search finds the card by its code', await waitRows( true, card.code ), lastQuery() );
		// The response lands after the request, so wait for the narrowed list
		// rather than reading whatever the last render left on screen.
		const narrowed = await page.waitForFunction(
			() => ! document.querySelector( '#minn-view .minn-loading' )
				&& 1 === document.querySelectorAll( '.minn-table-row[data-sitem]' ).length,
			null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'search narrows to the one card', narrowed, JSON.stringify( await rows() ) );

		// --- the detail ---
		await page.click( '.minn-table-row[data-sitem]' );
		await page.waitForSelector( '.minn-modal .minn-side-row', { timeout: 15000 } );
		const detail = await page.evaluate( () =>
			( document.querySelector( '.minn-modal' ) || {} ).textContent || '' );
		t.check( 'the detail shows the code and the balance',
			detail.includes( card.code ) && detail.includes( '$40.00' ), detail.slice( 0, 200 ) );
		t.check( 'the modal is titled with the code, not the surface label',
			card.code === await page.evaluate( () =>
				( ( document.querySelector( '.minn-modal-title' ) || {} ).textContent || '' ).trim() ),
			await page.evaluate( () => ( ( document.querySelector( '.minn-modal-title' ) || {} ).textContent || '' ).trim() ) );
		const copyBtn = await page.$( '.minn-modal [data-scopy]' );
		t.check( 'the code row offers a copy button', !! copyBtn, '' );
		if ( copyBtn ) {
			await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
			await copyBtn.click();
			const copiedToast = await page.waitForFunction(
				() => /code copied/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
				null, { timeout: 8000 } ).then( () => true ).catch( () => false );
			t.check( 'copying the code says so', copiedToast,
				await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ) );
			const clip = await page.evaluate( () => navigator.clipboard.readText() ).catch( () => '' );
			t.check( 'the clipboard holds the code', clip === card.code, clip );
		}
		// View order is gated on has_order, and points into Minn's own order
		// page rather than wp-admin.
		t.check( 'the detail offers the order it came from',
			await page.evaluate( ( id ) => !! document.querySelector( `.minn-modal a[href*="/minn-admin/orders/${ id }"]` ), orderId ),
			'order #' + orderId );

		// --- verb: adjust balance (parameterized action) ---
		const balanceBtn = await page.evaluateHandle( () =>
			Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
				.find( ( b ) => /Adjust balance/i.test( b.textContent ) ) );
		await balanceBtn.asElement().click();
		await page.waitForSelector( '.minn-modal [data-actfield]', { timeout: 10000 } );
		await page.fill( '.minn-modal [data-actfield]', '12.5' );
		await page.click( '.minn-modal [data-actgo]' );
		// Wait for the toast that carries this message, not whichever toast is
		// up: an earlier one can still be on screen.
		const adjusted = await page.waitForFunction(
			() => /now holds/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
			null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'adjusting the balance reports what it now holds', adjusted,
			await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ) );

		const afterBalance = await readCard( card.id );
		t.check( 'the new balance is what got stored', afterBalance && '$12.50' === afterBalance.balance,
			JSON.stringify( afterBalance && afterBalance.balance ) );

		// Read it back through YITH's OWN object (the sections route hydrates
		// YITH_YWGC_Gift_Card), so the shim cannot agree with itself.
		const sections = await api( `minn-admin/v1/ywgc/gift-cards/${ card.id }/view` );
		const flat = JSON.stringify( sections.body );
		t.check( 'YITH\'s own object reports the new balance', flat.includes( '$12.50' ), flat.slice( 0, 300 ) );

		// --- verb: disable ---
		await page.waitForSelector( '.minn-table-row[data-sitem]', { timeout: 15000 } );
		await page.click( '.minn-table-row[data-sitem]' );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		const disableBtn = await page.evaluateHandle( () =>
			Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
				.find( ( b ) => /^Disable$/i.test( b.textContent.trim() ) ) );
		t.check( 'an active card offers Disable and not Enable',
			!! disableBtn.asElement() && ! await page.evaluate( () =>
				Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) ).some( ( b ) => /^Enable$/i.test( b.textContent.trim() ) ) ),
			'' );
		await disableBtn.asElement().click();
		const disabledToast = await page.waitForFunction(
			() => /no longer be spent/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
			null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'disabling says the balance can no longer be spent', disabledToast,
			await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ) );

		const afterDisable = await readCard( card.id );
		t.check( 'the card is stored as disabled',
			afterDisable && 'disabled' === afterDisable.status && false === afterDisable.enabled,
			JSON.stringify( afterDisable && afterDisable.status ) );

		// The string "false" is truthy in PHP. Without rest_sanitize_boolean
		// this would flip the card back on.
		const stillOff = await api( `minn-admin/v1/ywgc/gift-cards/${ card.id }/status`, {
			method: 'POST',
			body: JSON.stringify( { enabled: 'false' } ),
		} );
		const afterFalse = await readCard( card.id );
		t.check( 'the string "false" does not re-enable a disabled card',
			200 === stillOff.status && afterFalse && 'disabled' === afterFalse.status,
			JSON.stringify( stillOff.body ) + ' / ' + JSON.stringify( afterFalse && afterFalse.status ) );

		// --- verb: resend refuses honestly when there is no recipient ---
		const resend = await api( `minn-admin/v1/ywgc/gift-cards/${ card.id }/resend`, { method: 'POST' } );
		t.check( 'resending a card with no recipient refuses, and says why',
			400 === resend.status && /nothing to resend/i.test( ( resend.body || {} ).message || '' ),
			JSON.stringify( resend.body ) );

		// --- create: the modal issues a card by hand ---
		await page.goto( BASE + '/minn-admin/yith-gift-cards', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-add', { timeout: 20000 } );
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '.minn-modal [data-createfield="amount"]', { timeout: 15000 } );

		const madeCode = `SUITE-${ suffix.toUpperCase() }-0001`;
		const madeMail = `gc-made-${ suffix }@example.com`;
		await page.fill( '.minn-modal [data-createfield="amount"]', '25' );
		await page.fill( '.minn-modal [data-createfield="code"]', madeCode );
		await page.fill( '.minn-modal [data-createfield="recipient"]', madeMail );
		await page.fill( '.minn-modal [data-createfield="recipient_name"]', 'Suite Recipient' );
		await page.fill( '.minn-modal [data-createfield="sender_name"]', 'Suite Sender' );
		await page.fill( '.minn-modal [data-createfield="message"]', 'Thanks for waiting.' );
		await page.click( '#minn-surface-create' );

		t.check( 'a hand-issued card lands in the list', await waitRows( true, madeCode ), madeCode );

		const madeList = await api( 'minn-admin/v1/ywgc/gift-cards?per_page=100&search=' + encodeURIComponent( madeCode ) );
		const made = ( ( madeList.body || {} ).items || [] ).find( ( c ) => c.code === madeCode ) || null;
		t.check( 'it is stored active, with the typed amount as both amount and balance',
			!! made && 'active' === made.status && '$25.00' === made.amount && '$25.00' === made.balance,
			JSON.stringify( made ) );
		t.check( 'it carries the recipient and belongs to no order',
			!! made && madeMail === made.recipient && false === made.has_order,
			JSON.stringify( made && { r: made.recipient, o: made.has_order } ) );

		// Read back through YITH's OWN object, as everywhere else here. Digital
		// is the one that matters: YITH's mailer refuses a card that is not,
		// and refuses it silently.
		const madeView = await api( `minn-admin/v1/ywgc/gift-cards/${ made.id }/view` );
		const madeFlat = JSON.stringify( madeView.body );
		t.check( "YITH's own object reads it as digital, with the sender and message",
			/Digital/.test( madeFlat ) && madeFlat.includes( 'Suite Sender' ) && madeFlat.includes( 'Thanks for waiting.' ),
			madeFlat.slice( 0, 400 ) );

		const madeResend = await api( `minn-admin/v1/ywgc/gift-cards/${ made.id }/resend`, { method: 'POST' } );
		t.check( 'a hand-issued card with a recipient can actually be emailed',
			200 === madeResend.status && String( ( madeResend.body || {} ).message || '' ).includes( madeMail ),
			JSON.stringify( madeResend.body ) );

		// --- create: a blank code is the store's own generator ---
		const auto = await api( 'minn-admin/v1/ywgc/gift-cards', {
			method: 'POST',
			body: JSON.stringify( { amount: 5 } ),
		} );
		const autoCard = auto.body && auto.body.id ? await readCard( auto.body.id ) : null;
		t.check( "a blank code is filled in to the store's own pattern",
			!! autoCard && /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/.test( autoCard.code ),
			JSON.stringify( autoCard && autoCard.code ) );

		// --- create: what it refuses, and that it refuses BEFORE writing ---
		const countBefore = ( ( await api( 'minn-admin/v1/ywgc/gift-cards?per_page=1' ) ).body || {} ).total;
		const post = ( body ) => api( 'minn-admin/v1/ywgc/gift-cards', { method: 'POST', body: JSON.stringify( body ) } );

		const dup = await post( { amount: 10, code: madeCode } );
		t.check( 'a code already in use is refused',
			409 === dup.status && /already in use/i.test( ( dup.body || {} ).message || '' ),
			JSON.stringify( dup.body ) );

		const zero = await post( { amount: 0 } );
		t.check( 'a card worth nothing is refused', 400 === zero.status, JSON.stringify( zero.body ) );

		const noAmount = await post( { code: 'SUITE-NO-AMOUNT' } );
		t.check( 'a card with no amount at all is refused', 400 === noAmount.status, JSON.stringify( noAmount.body ) );

		const badMail = await post( { amount: 10, recipient: 'not-an-address' } );
		t.check( 'an invalid recipient address is refused', 400 === badMail.status, JSON.stringify( badMail.body ) );

		const sendNoOne = await post( { amount: 10, send: 'yes' } );
		t.check( 'sending with nobody to send it to is refused',
			400 === sendNoOne.status && /recipient email/i.test( ( sendNoOne.body || {} ).message || '' ),
			JSON.stringify( sendNoOne.body ) );

		const markup = await post( { amount: 10, code: '<script>alert(1)</script>' } );
		t.check( 'a code that is not plain text is refused',
			400 === markup.status && /not valid/i.test( ( markup.body || {} ).message || '' ),
			JSON.stringify( markup.body ) );

		const huge = await post( { amount: '1e20' } );
		t.check( 'a scientifically huge amount is refused',
			400 === huge.status,
			JSON.stringify( huge.body ) );

		const countAfter = ( ( await api( 'minn-admin/v1/ywgc/gift-cards?per_page=1' ) ).body || {} ).total;
		t.check( 'not one of those refusals left a card behind', countBefore === countAfter,
			countBefore + ' -> ' + countAfter );

		// The Send now field is declared a select and the core form engine
		// upgrades every select to its themed combobox, so the value reaches
		// the body through dataset.acValue rather than a <select>. Asking to
		// send with the recipient left blank proves the picked value made the
		// trip: nothing else could produce that refusal.
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '.minn-modal [data-createfield="amount"]', { timeout: 15000 } );
		await page.fill( '.minn-modal [data-createfield="amount"]', '10' );
		await page.click( '[data-createfield="send"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="send"] .minn-ac-item[data-acv="yes"]', { timeout: 8000 } );
		await page.click( '[data-createfield="send"] .minn-ac-item[data-acv="yes"]' );
		await page.click( '#minn-surface-create' );
		const sendPicked = await page.waitForFunction(
			() => /recipient email/i.test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ),
			null, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'the Send now picker carries its value to the server', sendPicked,
			await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ) );
		await page.click( '#minn-modal-close' );

		// A refusal keeps the modal open with what was typed still in it, so
		// the merchant fixes one field rather than retyping the form.
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '.minn-modal [data-createfield="amount"]', { timeout: 15000 } );
		await page.fill( '.minn-modal [data-createfield="amount"]', '15' );
		await page.fill( '.minn-modal [data-createfield="code"]', madeCode );
		await page.click( '#minn-surface-create' );
		const keptForm = await page.waitForFunction( ( wanted ) => {
			const toastEl = document.querySelector( '.minn-toast' );
			const codeEl = document.querySelector( '.minn-modal [data-createfield="code"]' );
			return !! toastEl && /already in use/i.test( toastEl.textContent || '' ) && !! codeEl && codeEl.value === wanted;
		}, madeCode, { timeout: 15000 } ).then( () => true ).catch( () => false );
		t.check( 'a refused create keeps the form open, as typed', keptForm, madeCode );
		await page.click( '#minn-modal-close' );

		// --- the shim is gated, not just hidden ---
		const forbidden = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/ywgc/gift-cards' );
			return r.status;
		} );
		t.check( 'the list route refuses an unauthenticated request', 401 === forbidden || 403 === forbidden, String( forbidden ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, String( e && e.message ? e.message : e ) );
	} finally {
		if ( orderId ) await api( `wc/v3/orders/${ orderId }?force=true`, { method: 'DELETE' } );
		if ( productId ) await api( `wc/v3/products/${ productId }?force=true`, { method: 'DELETE' } );
		await t.done( browser, errors );
	}
} )();
