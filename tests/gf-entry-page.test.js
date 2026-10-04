/**
 * The entry page (/gravity-forms/entry/{id}): a Forms family entry on its
 * own page instead of a modal.
 *
 * The contact card (name, email, Reply with the form in the subject), the
 * message with its line breaks, the remaining answers with the form's own
 * labels, the submission card, previous / next through the list (buttons
 * and ← →, never while typing), a link reaching the page with no list
 * loaded, an entry that does not exist, spam from the side card, and the
 * form name opening the form builder.
 *
 * Fixtures: GF form 1 "Contact Form" (fields: name 1, email 2, select 3,
 * textarea 4, checkbox 5). The suite adds two disposable entries over
 * gf/v2 and force-deletes them in finally.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-entry-page' );
	const { browser, page, errors } = await launch();
	await login( page );

	const gf = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );

	const stamp = String( Date.now() ).slice( -6 );
	const ids = [];
	for ( const [ first, msg ] of [ [ 'Paige', 'Line one.\nLine two.' ], [ 'Quinn', 'Second entry.' ] ] ) {
		const r = await gf( 'gf/v2/entries', { method: 'POST', body: {
			form_id: 1,
			'1.3': first, '1.6': 'Suite' + stamp,
			2: first.toLowerCase() + stamp + '@example.org',
			3: 'sales',
			4: msg,
			'5.1': 'Hosting',
		} } );
		if ( r.body && r.body.id ) ids.push( r.body.id );
	}

	try {
		t.check( 'two disposable entries', ids.length === 2, ids.join( ',' ) );
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( ( n ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( n ) ), 'Paige', { timeout: 30000 } );
		await page.$$eval( '.minn-table-row', ( rows ) => rows.find( ( r ) => r.textContent.includes( 'Paige' ) ).click() );
		await page.waitForSelector( '.minn-entry-page .minn-ep-contact', { timeout: 25000 } );
		t.check( 'the row opens the entry page', page.url().endsWith( '/gravity-forms/entry/' + ids[ 0 ] ), page.url() );

		const view = await page.$eval( '.minn-entry-page', ( el ) => ( {
			title: el.querySelector( '.minn-modal-title' ).textContent.trim(),
			name: el.querySelector( '.minn-ep-name' ).textContent.trim(),
			mail: ( el.querySelector( '.minn-ep-reach a[href^="mailto:"]' ) || {} ).textContent,
			reply: ( el.querySelector( '.minn-ep-reply' ) || {} ).href || '',
			message: ( el.querySelector( '.minn-ep-message' ) || {} ).textContent || '',
			answers: [ ...el.querySelectorAll( '.minn-order-main .minn-ep-answer' ) ].map( ( a ) => a.querySelector( 'dt' ).textContent.trim() + '=' + a.querySelector( 'dd' ).textContent.trim() ),
			submitted: !! [ ...el.querySelectorAll( '.minn-ep-side-meta dt' ) ].find( ( d ) => d.textContent.trim() === 'Submitted' ),
		} ) );
		t.check( 'the title is the sender', view.title === 'Paige Suite' + stamp && view.name === view.title, JSON.stringify( view.title ) );
		t.check( 'the email links and Reply carries the form', view.mail === 'paige' + stamp + '@example.org' && /^mailto:paige\d+@example\.org\?subject=Re%3A%20Contact%20Form$/.test( view.reply ), view.reply );
		t.check( 'the message keeps its line breaks', view.message === 'Line one.\nLine two.', JSON.stringify( view.message ) );
		t.check( 'other answers show with the form’s labels', view.answers.includes( 'Topic=Sales question' ) && view.answers.some( ( a ) => /^Interests=Hosting/.test( a ) ), JSON.stringify( view.answers ) );
		t.check( 'the submission card sits at the side', view.submitted );

		// Previous / next through the list: buttons, arrow keys, never while typing.
		const count = await page.$eval( '.minn-entry-page .minn-modal-count', ( el ) => el.textContent.trim() ).catch( () => '' );
		t.check( 'the page shows where it sits in the list', /^\d+ \/ \d+$/.test( count ), count );
		const before = page.url();
		await page.click( '[data-epnote]' );
		await page.keyboard.press( 'ArrowLeft' );
		await page.keyboard.press( 'ArrowRight' );
		await page.waitForTimeout( 400 );
		t.check( 'arrow keys move the cursor while typing a note, not the page', page.url() === before );
		await page.click( '.minn-ep-name' );
		const order = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-ep-prev, #minn-ep-next' ) ].map( ( b ) => b.disabled ) );
		const key = order[ 1 ] ? 'ArrowLeft' : 'ArrowRight';
		await page.keyboard.press( key );
		await page.waitForFunction( ( was ) => location.href !== was, before, { timeout: 15000 } );
		await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 25000 } );
		t.check( 'an arrow key steps to the neighbouring entry', /\/gravity-forms\/entry\/\d+$/.test( page.url() ) && page.url() !== before, page.url() );
		await page.keyboard.press( 'ArrowLeft' === key ? 'ArrowRight' : 'ArrowLeft' );
		await page.waitForFunction( ( was ) => location.href === was, before, { timeout: 15000 } );
		t.check( 'and the other arrow steps back', true );

		// Reached by a link: no list loaded, the page still knows its actions.
		await page.goto( `${ BASE }/minn-admin/gravity-forms/entry/${ ids[ 1 ] }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-entry-page .minn-ep-actlist', { timeout: 30000 } );
		const linked = await page.evaluate( () => [ ...document.querySelectorAll( '[data-epact]' ) ].map( ( b ) => b.textContent.trim() || b.getAttribute( 'aria-label' ) ) );
		t.check( 'a direct link shows the entry with its actions', linked.includes( 'Mark as spam' ) && linked.includes( 'Star' ), JSON.stringify( linked ) );
		t.check( 'a direct link has no list to step through', ! await page.$( '#minn-ep-next' ) );
		const stored = ( await gf( `gf/v2/entries/${ ids[ 1 ] }` ) ).body;
		t.check( 'opening the page marked the entry read', String( stored.is_read ) === '1' );

		// Spam from the side card, through Minn's own confirm.
		await page.evaluate( () => [ ...document.querySelectorAll( '[data-epact]' ) ].find( ( b ) => b.textContent.trim() === 'Mark as spam' ).click() );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 10000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-epact]' ) ].some( ( b ) => b.textContent.trim() === 'Not spam' ), null, { timeout: 25000 } );
		t.check( 'spam lands and the page offers Not spam', ( await gf( `gf/v2/entries/${ ids[ 1 ] }` ) ).body.status === 'spam' );
		t.check( 'the status pill follows', /spam/i.test( await page.$eval( '.minn-entry-page .minn-order-head-row .minn-status', ( el ) => el.textContent ) ) );

		// The form name opens the form builder.
		await page.click( '.minn-ep-formlink' );
		await page.waitForSelector( '.minn-gfb-grid', { timeout: 30000 } );
		t.check( 'the form name opens its builder', /\/gravity-forms\/form\/1$/.test( page.url() ) );

		// An entry that does not exist says so.
		await page.goto( `${ BASE }/minn-admin/gravity-forms/entry/99999999`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-entry-page .minn-empty', { timeout: 30000 } );
		t.check( 'a missing entry says so', /not found/i.test( await page.$eval( '.minn-entry-page .minn-empty', ( el ) => el.textContent ) ) );
	} finally {
		for ( const id of ids ) {
			await gf( `gf/v2/entries/${ id }?force=1`, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
