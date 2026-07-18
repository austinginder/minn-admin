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

		// Reopen: the when-gate flips to Unstar. The action clears the list
		// cache and reloads, but v0.16 soft-reload keeps the old rows painted
		// until the fresh fetch lands, so reopening can race a stale row —
		// retry the reopen until the detail reflects the committed star.
		await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		let flipped = false;
		for ( let i = 0; i < 6 && ! flipped; i++ ) {
			await openEntry();
			flipped = await page.evaluate( () => {
				const labels = [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() );
				return labels.includes( 'Unstar' ) && ! labels.includes( 'Star' );
			} );
			if ( ! flipped ) {
				await page.click( '#minn-modal-close' ).catch( () => {} );
				await page.waitForTimeout( 800 );
			}
		}
		t.check( 'when-gate flips to Unstar', flipped );
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

		/* ===== Status filter: trash view, restore, delete permanently ===== */
		// wf-two and wf-three sit in trash from the bulk step.
		t.check( 'filter pills render with Received active', await page.$eval( '[data-sfilter="active"]', ( el ) => el.classList.contains( 'active' ) ) );
		// Bulk-bar declutter: on the Received view no page item is spam/trash,
		// so Restore and Not spam are not offered.
		await selectByText( 'gf workflow wf-one' );
		await page.waitForSelector( '.minn-bulkbar', { timeout: 10000 } );
		const offeredReceived = await page.$$eval( '[data-sbulk]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'bulk bar hides verbs no page item can take',
			! offeredReceived.includes( 'Restore' ) && ! offeredReceived.includes( 'Not spam' ) && offeredReceived.includes( 'Spam' ),
			offeredReceived.join( ',' ) );
		await page.click( '#minn-sbulk-clear' );

		await page.click( '[data-sfilter="trash"]' );
		await page.waitForFunction( () => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && tbl.textContent.includes( 'wf-two' );
		}, { timeout: 20000 } );
		t.check( 'trash filter lists the trashed entries', await page.evaluate( () =>
			document.querySelector( '.minn-table' ).textContent.includes( 'wf-three' ) ) );

		// Restore wf-two through the detail actions.
		await page.$$eval( '.minn-table-row', ( rows ) => {
			const row = rows.find( ( r ) => r.textContent.includes( 'wf-two' ) );
			if ( row ) row.click();
		} );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && [ ...m.querySelectorAll( '[data-saction]' ) ].some( ( b ) => b.textContent.trim() === 'Restore' );
		}, { timeout: 25000 } );
		t.check( 'trash view offers Restore + Delete permanently', await page.evaluate( () => {
			const labels = [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() );
			return labels.includes( 'Restore' ) && labels.includes( 'Delete permanently' )
				&& ! labels.includes( 'Trash entry' ) && ! labels.includes( 'Mark as spam' );
		} ) );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Restore' ).click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Restore — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const restored = await entry( ids[ 1 ] );
		t.check( 'restore lands the entry back in active', restored.status === 'active', restored.status );

		// Delete wf-three permanently from the trash view.
		await page.waitForFunction( () => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && ! tbl.classList.contains( 'minn-busy' );
		}, { timeout: 20000 } );
		await page.$$eval( '.minn-table-row', ( rows ) => {
			const row = rows.find( ( r ) => r.textContent.includes( 'wf-three' ) );
			if ( row ) row.click();
		} );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && [ ...m.querySelectorAll( '[data-saction]' ) ].some( ( b ) => b.textContent.trim() === 'Delete permanently' );
		}, { timeout: 25000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Delete permanently' ).click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Delete permanently — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const gone = await gf( `gf/v2/entries/${ ids[ 2 ] }` );
		t.check( 'permanent delete really deletes', gone.status === 404 || ( gone.body && gone.body.code ), String( gone.status ) );

		// Back on Received, the restored entry lists again.
		await page.click( '[data-sfilter="active"]' );
		await page.waitForFunction( () => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && tbl.textContent.includes( 'wf-two' );
		}, { timeout: 20000 } );
		t.check( 'restored entry back on the Received view', true );

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
