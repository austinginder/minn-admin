/**
 * Contact Form 7 + Flamingo — the forms-family adapter for the largest
 * install base in the ecosystem. CF7 stores nothing; Flamingo does, so the
 * surface reads through Flamingo's own model class: messages as contact
 * cards (spam pill, per-form channel tabs, WP_Query search), spam/unspam
 * and trash through Flamingo's own handlers, and CF7 forms in the Manage
 * view with shortcode and message counts.
 *
 * Fixtures: three standing messages seeded on the dev site (sent, failed,
 * spam) plus a disposable one from the minn_test_seed_flamingo one-shot
 * mu-fixture that this suite spams, unspams, and finally trashes.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'cf7-flamingo' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	// One-shot seed (self-clearing flag): '' read-back after writing '1'
	// means a racing init consumed it, which is also success.
	const seed = async () => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async () => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_flamingo: '1' } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_seed_flamingo;
			} );
			if ( stored === '1' || stored === '' ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const rows = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row' ) ).map( ( r ) => r.textContent.replace( /\s+/g, ' ' ).trim() )
	);
	const clickRow = ( text ) => page.evaluate( ( s ) => {
		const row = Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( s ) );
		if ( row ) row.click();
		return !! row;
	}, text );
	const clickModalBtn = ( label ) => page.evaluate( ( l ) => {
		const btn = Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => b.textContent.trim() === l );
		if ( btn ) btn.click();
		return !! btn;
	}, label );
	const waitToast = ( re ) => page.waitForFunction(
		( src ) => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => new RegExp( src ).test( x.textContent ) ),
		re, { timeout: 10000 }
	);
	const waitRow = ( text, present = true ) => page.waitForFunction(
		( [ s, want ] ) => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( s ) ) === want,
		[ text, present ], { timeout: 10000 }
	);

	try {
		t.check( 'Disposable fixture seeded', await seed() );

		await page.goto( BASE + '/minn-admin/cf7', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.waitForTimeout( 300 );

		// --- List + tabs --------------------------------------------------------
		const tabs = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-tab' ) ).map( ( b ) => b.textContent.trim() )
		);
		t.check( 'Channel tabs render (All messages + the form)', tabs.includes( 'All messages' ) && tabs.includes( 'Contact form 1' ), JSON.stringify( tabs ) );

		const list = await rows();
		t.check( 'Standing messages listed with sender + subject', list.some( ( r ) => r.includes( 'Dana Tester' ) && r.includes( 'Question about pricing' ) ), list[ 0 ] );
		t.check( 'Disposable message listed', list.some( ( r ) => r.includes( 'Minn Fixture Disposable' ) ) );
		// Standing spam lives under the Spam filter (no longer mixed into Received).
		await page.click( '[data-sfilter="spam"]' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Casino Bonanza' ) ),
		null, { timeout: 10000 } );
		const spamList = await rows();
		t.check( 'Spam filter lists standing spam with the spam pill', spamList.some( ( r ) => r.includes( 'Casino Bonanza' ) && r.includes( 'spam' ) ), spamList[ 0 ] );
		await page.click( '[data-sfilter="inbox"]' );
		await waitRow( 'Dana Tester' );

		// --- Entry detail ------------------------------------------------------
		t.check( 'Row opens detail', await clickRow( 'Question about pricing' ) );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		const detail = await page.evaluate( () => ( {
			name: ( document.querySelector( '.minn-entry-name' ) || {} ).textContent || '',
			email: ( document.querySelector( '.minn-entry-email' ) || {} ).textContent || '',
			body: document.querySelector( '.minn-entry' ).textContent.replace( /\s+/g, ' ' ),
		} ) );
		t.check( 'Contact card hero shows name + email', detail.name === 'Dana Tester' && detail.email === 'dana@example.com', JSON.stringify( detail.name + ' / ' + detail.email ) );
		t.check( 'Message body renders', detail.body.includes( 'nonprofit discount' ) );
		t.check( 'CF7 field names humanized (no your- prefix)', ! /your-name|your_message/.test( detail.body ) );
		t.check( 'Submission meta carries form + IP', detail.body.includes( 'Contact form 1' ) && detail.body.includes( '203.0.113.9' ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		// --- Spam → unspam (status filter moves the row between buckets) ---------
		await clickRow( 'Minn Fixture Disposable' );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		t.check( 'Fresh message offers Mark as spam', await clickModalBtn( 'Mark as spam' ) );
		await waitToast( 'Marked as spam' );
		// Spam leaves the Received bucket — open the Spam filter to see it.
		await page.click( '[data-sfilter="spam"]' );
		await waitRow( 'Minn Fixture Disposable' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) =>
				r.textContent.includes( 'Minn Fixture Disposable' ) && r.textContent.includes( 'spam' ) ),
		null, { timeout: 10000 } );
		t.check( 'Spam filter lists the marked message', true );

		await clickRow( 'Minn Fixture Disposable' );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		t.check( 'Spam message offers Not spam instead', await clickModalBtn( 'Not spam' ) );
		await waitToast( 'Marked not spam' );
		// Back on Received after unspam.
		await page.click( '[data-sfilter="inbox"]' );
		await waitRow( 'Minn Fixture Disposable' );
		t.check( 'Unspam restores the message to Received', true );

		// --- Trash ---------------------------------------------------------------
		// v0.16 soft reload keeps the pre-switch rows painted while the inbox
		// collection loads, so waitRow above can match a stale row whose item
		// still carries bucket:'spam' — its detail then offers Not-spam, not
		// Trash. Open-and-verify with a retry: the Trash action (gated on
		// bucket:'inbox') appears once the fresh inbox item is what's mounted.
		let trashOffered = false;
		for ( let i = 0; i < 8 && ! trashOffered; i++ ) {
			await clickRow( 'Minn Fixture Disposable' );
			await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
			trashOffered = await clickModalBtn( 'Trash message' );
			if ( ! trashOffered ) {
				await page.keyboard.press( 'Escape' );
				await page.waitForTimeout( 700 );
			}
		}
		t.check( 'Trash action offered', trashOffered );
		await waitToast( 'Moved to trash' );
		await waitRow( 'Minn Fixture Disposable', false );
		t.check( 'Trashed message leaves the Received list', true );

		// --- Search --------------------------------------------------------------
		await page.type( '#minn-surface-search', 'nonprofit' );
		await page.waitForFunction( () => {
			const r = document.querySelectorAll( '.minn-table-row' );
			return r.length === 1 && r[ 0 ].textContent.includes( 'Dana Tester' );
		}, null, { timeout: 10000 } );
		t.check( 'Search narrows to the matching message', true );

		// --- Manage view: CF7 forms ----------------------------------------------
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-view-switch .minn-tab' ) ).find( ( b ) => b.textContent.trim() === 'Forms' ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( '[contact-form-7 id=' ) ),
		null, { timeout: 10000 } );
		const formRow = ( await rows() ).find( ( r ) => r.includes( 'Contact form 1' ) );
		t.check( 'Forms view lists the CF7 form with shortcode + count', !! formRow && /\[contact-form-7 id="\d+"\]/.test( formRow ) && /\d/.test( formRow ), formRow );
	} finally {
		// The disposable message was trashed by the test; clear any leftover
		// seed flag so the next pageload doesn't recreate it.
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_flamingo: '' } ),
			} ).catch( () => {} );
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
