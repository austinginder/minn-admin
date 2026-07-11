/**
 * Gravity Forms entry workflow + the generic surface bulk primitive.
 *
 * Star/unstar and spam ride gf/v2/entries/{id}/properties (GF's own
 * workflow endpoint), opening an entry marks it read like GF's own screen,
 * notes render as a detail section, and the new `bulk` collection key gets
 * its first consumer: checkbox column, shift-range, Select page, per-item
 * application with when-gates (a mixed selection reports skips).
 *
 * Fixtures: GF form 1 "Contact Form" with 2 STANDING entries that must
 * survive; the suite creates its own disposable entries over gf/v2 and
 * force-deletes them in finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-workflow' );
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

	// Three disposable entries on form 1, written into its REAL first
	// text-ish field (an unknown field id would store invisibly and the
	// list would show "(empty entry)").
	const form = ( await gf( 'gf/v2/forms/1' ) ).body;
	const field = ( form.fields || [] ).find( ( f ) => [ 'text', 'name', 'email', 'textarea' ].includes( f.type ) ) || { id: 1 };
	const fieldKey = String( field.type === 'name' ? field.id + '.3' : field.id );
	const ids = [];
	for ( const label of [ 'wf-one', 'wf-two', 'wf-three' ] ) {
		const r = await gf( 'gf/v2/entries', { method: 'POST', body: { form_id: 1, [ fieldKey ]: 'gf workflow ' + label } } );
		if ( r.body && r.body.id ) ids.push( r.body.id );
	}

	const entry = async ( id ) => ( await gf( `gf/v2/entries/${ id }` ) ).body;

	try {
		t.check( 'disposable entries created', ids.length === 3, ids.join( ',' ) );

		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		t.check( 'bulk checkboxes render on the entries list', true );

		/* ===== Detail: star + mark-read-on-open + resend offered ===== */
		// Forms family renders the contact-card layout — wait for the entry
		// card, not raw section titles (the card doesn't print them).
		const openEntry = async () => {
			await page.$$eval( '.minn-table-row', ( rows, target ) => {
				const row = rows.find( ( r ) => r.textContent.includes( target ) );
				if ( row ) row.click();
			}, 'gf workflow wf-one' );
			await page.waitForFunction( () => {
				const m = document.querySelector( '.minn-modal' );
				return m && m.querySelector( '.minn-entry' );
			}, { timeout: 25000 } );
		};
		await openEntry();
		t.check( 'star + resend actions offered', await page.evaluate( () => {
			const labels = [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() );
			return labels.includes( 'Star' ) && labels.includes( 'Resend notifications' ) && labels.includes( 'Mark as spam' );
		} ) );
		// Opening marked it read (GF's own screen semantics).
		let e0 = await entry( ids[ 0 ] );
		t.check( 'opening the entry marked it read', String( e0.is_read ) === '1', String( e0.is_read ) );

		// Star it.
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Star' );
			btn.click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Star — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		e0 = await entry( ids[ 0 ] );
		t.check( 'star persisted through GF properties PUT', String( e0.is_starred ) === '1', String( e0.is_starred ) );

		// Reopen: the when-gate flips to Unstar.
		await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		await openEntry();
		t.check( 'when-gate flips to Unstar', await page.evaluate( () => {
			const labels = [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() );
			return labels.includes( 'Unstar' ) && ! labels.includes( 'Star' );
		} ) );
		await page.click( '#minn-modal-close' );

		/* ===== Notes section ===== */
		await gf( `minn-admin/v1/gf/entries/${ ids[ 0 ] }/notes`, { method: 'POST', body: { value: 'Followed up by phone.' } } );
		await openEntry();
		const modalText = await page.$eval( '.minn-modal', ( el ) => el.textContent );
		t.check( 'notes render as a detail section', /Notes/.test( modalText ) && /Followed up by phone/.test( modalText ) );

		/* ===== Parameterized action: Add note through the inline form ===== */
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Add note' );
			btn.click();
		} );
		await page.waitForSelector( '[data-actfield="value"]', { timeout: 10000 } );
		t.check( 'action fields swap in as an inline form', true );
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		await page.click( '[data-actgo]' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Fill in all fields/.test( tEl.textContent );
		}, { timeout: 10000 } );
		t.check( 'empty required field refuses to fire', true );
		await page.fill( '[data-actfield="value"]', 'Added from the Minn inline form.' );
		await page.click( '[data-actgo]' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Add note — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const notes = ( await gf( `gf/v2/entries/${ ids[ 0 ] }/notes` ) ).body;
		t.check( 'note created through the parameterized action', JSON.stringify( notes ).includes( 'Added from the Minn inline form.' ) );

		/* ===== Bulk: mixed-selection skip semantics ===== */
		// The Add note action closed the modal and reloaded the list.
		await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		// Select wf-one (starred) + wf-two (not starred), run Star: 1 done, 1 skipped.
		const selectByText = ( needle ) => page.$$eval( '.minn-table-row', ( rows, n ) => {
			const row = rows.find( ( r ) => r.textContent.includes( n ) );
			if ( row ) {
				const cb = row.querySelector( '[data-scheck]' );
				cb.click();
			}
		}, needle );
		await selectByText( 'gf workflow wf-one' );
		await selectByText( 'gf workflow wf-two' );
		await page.waitForSelector( '.minn-bulkbar', { timeout: 10000 } );
		t.check( 'bulk bar counts the selection', await page.$eval( '.minn-bulk-count', ( el ) => el.textContent === '2 selected' ) );
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-sbulk]' ) ].find( ( b ) => b.textContent.trim() === 'Star' );
			btn.click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Star: 1 done, 1 skipped/.test( tEl.textContent );
		}, { timeout: 25000 } );
		t.check( 'mixed selection: eligible starred, ineligible skipped', true );
		const e1 = await entry( ids[ 1 ] );
		t.check( 'bulk star persisted on the eligible entry', String( e1.is_starred ) === '1' );

		/* ===== Bulk trash ===== */
		await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		await selectByText( 'gf workflow wf-two' );
		await selectByText( 'gf workflow wf-three' );
		await page.waitForSelector( '.minn-bulkbar', { timeout: 10000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-sbulk]' ) ].find( ( b ) => b.textContent.trim() === 'Trash' );
			btn.click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Trash: 2 done/.test( tEl.textContent );
		}, { timeout: 25000 } );
		const [ e1b, e2b ] = [ await entry( ids[ 1 ] ), await entry( ids[ 2 ] ) ];
		t.check( 'bulk trash landed on both entries', e1b.status === 'trash' && e2b.status === 'trash', e1b.status + ',' + e2b.status );
		t.check( 'trashed entries left the list', await page.evaluate( () =>
			! document.querySelector( '.minn-table' ).textContent.includes( 'wf-two' ) ) );

		/* ===== Standing fixtures untouched ===== */
		const standing = ( await gf( 'gf/v2/forms/1/entries?paging[page_size]=50' ) ).body;
		const standingActive = ( standing.entries || [] ).filter( ( e ) => ! String( e[ fieldKey ] || '' ).startsWith( 'gf workflow' ) );
		t.check( 'standing fixture entries survive', standingActive.length >= 2, String( standingActive.length ) );
	} finally {
		for ( const id of ids ) {
			await gf( `gf/v2/entries/${ id }?force=1`, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
