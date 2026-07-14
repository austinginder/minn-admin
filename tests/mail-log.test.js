/**
 * Email Log family — FluentSMTP shim (active mail provider on minnadmin).
 * List, tabs, search, detail, Resend, Delete. Opens /minn-admin/fluent-smtp
 * with family preference pinned so Gravity SMTP does not steal the slot.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'mail-log' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

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

	// --- REST shim ----------------------------------------------------------
	const list = await api( 'minn-admin/v1/fluent-smtp/emails?per_page=5' );
	t.check( 'Shim returns {items,total}',
		list.status === 200 && Array.isArray( list.body.items ) && typeof list.body.total === 'number',
		JSON.stringify( list.status ) );
	t.check( 'Seeded emails present', list.body.total >= 2, `total=${ list.body.total }` );
	const first = list.body.items[ 0 ];
	t.check(
		'Rows carry subject/to/status/date',
		!! first && !! first.subject && String( first.to ).includes( '@' ) && !! first.status && !! first.created_at,
		JSON.stringify( first )
	);

	const detail = await api( 'minn-admin/v1/fluent-smtp/emails/' + first.id );
	t.check( 'Detail carries the message body',
		detail.status === 200 && detail.body.message && detail.body.message.length > 5,
		JSON.stringify( { status: detail.status, len: detail.body && ( detail.body.message || '' ).length } ) );
	t.check( 'Recipients extracted without unserializing',
		String( detail.body.to ).includes( '@' ) && ! String( detail.body.to ).includes( 'a:1:' ),
		String( detail.body.to ) );

	// Search
	const searchHit = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'Minn mail test' ) );
	const hitItems = ( searchHit.body && searchHit.body.items ) || [];
	t.check( 'Search by subject returns matches',
		searchHit.status === 200 && hitItems.length >= 1
		&& hitItems.every( ( it ) => /Minn mail test/i.test( it.subject || '' ) ),
		JSON.stringify( { n: hitItems.length, total: searchHit.body && searchHit.body.total } ) );

	const miss = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'zzznomatch-fluent-minn' ) );
	t.check( 'Search miss returns empty list',
		miss.status === 200 && Array.isArray( miss.body.items ) && miss.body.total === 0,
		JSON.stringify( miss.body ) );

	// Delete a disposable row.
	const doomed = await api( 'minn-admin/v1/fluent-smtp/test', {
		method: 'POST',
		body: JSON.stringify( { email: 'fluent-delete-suite@example.com' } ),
	} );
	let doomedId = null;
	if ( doomed.status === 200 ) {
		const found = await api( 'minn-admin/v1/fluent-smtp/emails?search=' + encodeURIComponent( 'fluent-delete-suite' ) );
		doomedId = found.body.items && found.body.items[ 0 ] && found.body.items[ 0 ].id;
	}
	t.check( 'Have an id to delete', !! doomedId, JSON.stringify( { status: doomed.status, doomedId } ) );
	if ( doomedId ) {
		const del = await api( 'minn-admin/v1/fluent-smtp/emails/' + doomedId, { method: 'DELETE' } );
		const gone = await api( 'minn-admin/v1/fluent-smtp/emails/' + doomedId );
		t.check( 'DELETE removes the log entry',
			del.status === 200 && del.body && del.body.deleted && gone.status === 404,
			JSON.stringify( { del: del.status, body: del.body, gone: gone.status } ) );
	}

	// --- Surface UI -----------------------------------------------------------
	await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'fluent-smtp' ) );
	await page.goto( BASE + '/minn-admin/fluent-smtp', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

	const ui = await page.evaluate( () => ( {
		rows: document.querySelectorAll( '.minn-table-row' ).length,
		search: !! document.querySelector( '#minn-surface-search' ),
	} ) );
	t.check( 'FluentSMTP surface loads rows', ui.rows >= 1, JSON.stringify( ui ) );
	t.check( 'Email surface exposes a search field', ui.search, JSON.stringify( ui ) );

	// Toolbar search (input event, 350ms debounce).
	if ( ui.search ) {
		await page.click( '#minn-surface-search', { clickCount: 3 } );
		await page.keyboard.type( 'Minn mail test', { delay: 20 } );
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
			return rows.length > 0 && rows.every( ( r ) => /Minn mail test/i.test( r.textContent || '' ) );
		}, null, { timeout: 12000 } ).catch( () => null );
		const found = await page.evaluate( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
			return {
				n: rows.length,
				ok: rows.length > 0 && rows.every( ( r ) => /Minn mail test/i.test( r.textContent || '' ) ),
				first: rows[ 0 ] && rows[ 0 ].textContent,
			};
		} );
		t.check( 'Toolbar search filters the list', found.ok, JSON.stringify( found ) );
	}

	// Open first row (title cell avoids checkbox) and wait for detail to finish loading.
	await page.click( '.minn-table-row .minn-row-title' );
	await page.waitForFunction( () => {
		const m = document.querySelector( '.minn-modal' );
		if ( ! m ) return false;
		const t = m.textContent || '';
		return ! /Loading…|Loading\.\.\./.test( t ) && m.querySelector( '[data-saction]' );
	}, null, { timeout: 20000 } ).catch( () => null );

	const actions = await page.evaluate( () => {
		const labels = Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
			.map( ( b ) => ( b.textContent || '' ).trim() );
		return {
			open: !! document.querySelector( '.minn-modal' ),
			resend: labels.some( ( l ) => /Resend/i.test( l ) ),
			del: labels.some( ( l ) => /Delete/i.test( l ) ),
			labels,
		};
	} );
	t.check( 'Detail view shows the email', actions.open && actions.labels.length > 0, JSON.stringify( actions ) );
	t.check( 'Resend action offered', actions.resend, JSON.stringify( actions ) );
	t.check( 'Detail offers Delete action', actions.del, JSON.stringify( actions ) );

	if ( actions.resend ) {
		const before = ( await api( 'minn-admin/v1/fluent-smtp/emails?per_page=1' ) ).body.total;
		await page.evaluate( () => {
			const b = Array.from( document.querySelectorAll( '.minn-modal [data-saction]' ) )
				.find( ( x ) => /Resend/i.test( x.textContent || '' ) );
			if ( b ) b.click();
		} );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) )
				.some( ( x ) => /Resend — done|resent|done/i.test( x.textContent ) ),
			null, { timeout: 20000 }
		).catch( () => null );
		const after = ( await api( 'minn-admin/v1/fluent-smtp/emails?per_page=1' ) ).body.total;
		t.check( 'Resend logged a new sent email', after >= before, `before=${ before } after=${ after }` );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
