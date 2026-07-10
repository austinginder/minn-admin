/**
 * CFDB7 (Contact Form 7 Database Addon) — the second CF7-entries provider.
 *
 * CFDB7 stores each submission as ONE PHP-serialized blob; the shim reads it
 * with a byte-length token scanner (never unserialize) — this suite proves
 * the parsing end-to-end in a real browser: string fields, checkbox list
 * arrays joined, read/unread pills, and CFDB7's own open-marks-read
 * semantics via fixed-token blob surgery. Delete is permanent (CFDB7 has no
 * trash), so it runs against a disposable one-shot fixture entry.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'cfdb7' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const seed = async () => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async () => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_cfdb7: '1' } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_seed_cfdb7;
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

	try {
		t.check( 'Disposable fixture seeded', await seed() );

		await page.goto( BASE + '/minn-admin/cfdb7', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.waitForTimeout( 300 );

		const list = await rows();
		t.check( 'Entries parsed from serialized blobs', list.some( ( r ) => r.includes( 'Priya Nair' ) && r.includes( 'Speaking inquiry' ) ), list[ 0 ] );
		// The disposable entry reseeds unread every run; Sam is a standing
		// read fixture (Priya's status depends on earlier runs — not asserted).
		t.check( 'Unread/read pills from cfdb7_status', list.some( ( r ) => r.includes( 'Cast Off' ) && r.includes( 'unread' ) ) && list.some( ( r ) => r.includes( 'Sam Field' ) && ! r.includes( 'unread' ) ) );

		// --- Detail: scanner correctness + open-marks-read ------------------------
		t.check( 'Row opens detail', await clickRow( 'Priya Nair' ) );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		const detail = await page.evaluate( () => ( {
			name: ( document.querySelector( '.minn-entry-name' ) || {} ).textContent || '',
			email: ( document.querySelector( '.minn-entry-email' ) || {} ).textContent || '',
			body: document.querySelector( '.minn-entry' ).textContent.replace( /\s+/g, ' ' ),
		} ) );
		t.check( 'Contact card hero from parsed fields', detail.name === 'Priya Nair' && detail.email === 'priya@example.org' );
		t.check( 'Checkbox list array joins its members', detail.body.includes( 'Workshops, Keynote' ) );
		t.check( 'Blob status token never leaks into the card', ! detail.body.includes( 'cfdb7_status' ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		// Opening marks a message read server-side (their own semantics, via
		// token surgery); the cached list shows it after a fresh load. The
		// disposable entry is the repeatable subject.
		await clickRow( 'Cast Off' );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		await page.goto( BASE + '/minn-admin/cfdb7', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) =>
				r.textContent.includes( 'Cast Off' ) && ! r.textContent.includes( 'unread' ) ),
		null, { timeout: 10000 } );
		t.check( 'Opening a message marks it read', true );

		// --- Apostrophes/quotes survive the byte-length scanner -------------------
		await clickRow( 'Sam Field' );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		const sam = await page.evaluate( () => document.querySelector( '.minn-entry' ).textContent );
		t.check( 'Escaped punctuation survives parsing', sam.includes( '#204' ) && /can.t find it/.test( sam ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		// --- Delete (permanent, disposable fixture only) ---------------------------
		t.check( 'Disposable entry listed', ( await rows() ).some( ( r ) => r.includes( 'Cast Off' ) ) );
		await clickRow( 'Cast Off' );
		await page.waitForSelector( '.minn-entry', { timeout: 8000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => b.textContent.trim() === 'Delete entry' ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Delete entry — done/.test( x.textContent ) ),
		null, { timeout: 10000 } );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Cast Off' ) ),
		null, { timeout: 10000 } );
		t.check( 'Delete removes the entry', true );

		// --- Search + tabs ----------------------------------------------------------
		await page.type( '#minn-surface-search', 'WordCamp' );
		await page.waitForFunction( () => {
			const r = document.querySelectorAll( '.minn-table-row' );
			return r.length === 1 && r[ 0 ].textContent.includes( 'Priya Nair' );
		}, null, { timeout: 10000 } );
		t.check( 'Search narrows on blob content', true );
		const tabs = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-tab' ) ).map( ( b ) => b.textContent.trim() )
		);
		t.check( 'Per-form tabs render', tabs.includes( 'All messages' ) && tabs.includes( 'Contact form 1' ), JSON.stringify( tabs ) );
	} finally {
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_cfdb7: '' } ),
			} ).catch( () => {} );
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
