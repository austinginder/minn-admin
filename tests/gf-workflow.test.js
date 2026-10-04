/**
 * Gravity Forms entry workflow + the generic surface bulk primitive.
 *
 * Star/unstar and spam ride gf/v2/entries/{id}/properties (GF's own
 * workflow endpoint), an entry opens on its own page (/gravity-forms/entry/
 * {id}) and opening it marks it read like GF's own screen,
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

		/* ===== The entry page: star + mark-read-on-open + resend offered ===== */
		// A row opens the entry on its own page (/gravity-forms/entry/{id}).
		// Its actions: the star button in the title, the side card's list.
		const actionLabels = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-epact]' ) ]
			.map( ( b ) => b.textContent.trim() || b.getAttribute( 'aria-label' ) ) );
		const openRow = async ( target ) => {
			await page.waitForFunction( () => {
				const tbl = document.querySelector( '.minn-table' );
				return tbl && ! tbl.classList.contains( 'minn-busy' );
			}, null, { timeout: 30000 } );
			await page.$$eval( '.minn-table-row', ( rows, n ) => {
				const row = rows.find( ( r ) => r.textContent.includes( n ) );
				if ( row ) row.click();
			}, target );
			await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 25000 } );
		};
		const openEntry = () => openRow( 'gf workflow wf-one' );
		const backToList = async () => {
			await page.click( '#minn-ep-back' );
			await page.waitForSelector( '[data-scheck]', { timeout: 20000 } );
		};
		await openEntry();
		t.check( 'a row opens the entry on its own page', /\/gravity-forms\/entry\/\d+/.test( page.url() ) );
		t.check( 'star + resend actions offered', await actionLabels().then( ( labels ) =>
			labels.includes( 'Star' ) && labels.includes( 'Resend notifications' ) && labels.includes( 'Mark as spam' ) ) );
		// Opening marked it read (GF's own screen semantics).
		let e0 = await entry( ids[ 0 ] );
		t.check( 'opening the entry marked it read', String( e0.is_read ) === '1', String( e0.is_read ) );

		// Star it from the title: the page reloads in place and the button
		// flips to Unstar (the when-gate reading the entry's own state).
		await page.click( '.minn-ep-star' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Star — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		e0 = await entry( ids[ 0 ] );
		t.check( 'star persisted through GF properties PUT', String( e0.is_starred ) === '1', String( e0.is_starred ) );
		await page.waitForFunction( () => {
			const b = document.querySelector( '.minn-ep-star' );
			return b && b.classList.contains( 'on' ) && 'Unstar' === b.getAttribute( 'aria-label' );
		}, null, { timeout: 20000 } ).catch( () => {} );
		t.check( 'when-gate flips to Unstar', await actionLabels().then( ( l ) => l.includes( 'Unstar' ) && ! l.includes( 'Star' ) ) );

		/* ===== Notes timeline ===== */
		await gf( `minn-admin/v1/gf/entries/${ ids[ 0 ] }/notes`, { method: 'POST', body: { value: 'Followed up by phone.' } } );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-entry-page .minn-ep-notes', { timeout: 25000 } );
		t.check( 'a reloaded entry page still knows its state', await actionLabels().then( ( l ) => l.includes( 'Unstar' ) ) );
		t.check( 'notes render in the timeline', /Followed up by phone/.test( await page.$eval( '.minn-ep-notes', ( el ) => el.textContent ) ) );

		/* ===== Add note through the inline composer ===== */
		t.check( 'the note action is the timeline composer', !! await page.$( '.minn-ep-notes [data-epnote]' ) && ! ( await actionLabels() ).includes( 'Add note' ) );
		await page.click( '[data-epnoteadd]' );
		await page.waitForTimeout( 400 );
		t.check( 'an empty note does not fire', await page.evaluate( () => document.activeElement && document.activeElement.hasAttribute( 'data-epnote' ) ) );
		await page.fill( '[data-epnote]', 'Added from the Minn inline form.' );
		await page.keyboard.press( 'Meta+Enter' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Add note — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const notes = ( await gf( `gf/v2/entries/${ ids[ 0 ] }/notes` ) ).body;
		t.check( 'note created through the composer', JSON.stringify( notes ).includes( 'Added from the Minn inline form.' ) );
		await page.waitForFunction( () => /Added from the Minn inline form/.test( ( document.querySelector( '.minn-ep-notes' ) || {} ).textContent || '' ), null, { timeout: 20000 } );
		t.check( 'the new note joins the timeline in place', true );

		/* ===== Bulk: mixed-selection skip semantics ===== */
		await backToList();
		// Select wf-one (starred) + wf-two (not starred), run Star: 1 done, 1 skipped.
		const selectByText = ( needle ) => page.$$eval( '.minn-table-row', ( rows, n ) => {
			const row = rows.find( ( r ) => r.textContent.includes( n ) );
			if ( row ) {
				const cb = row.querySelector( '[data-scheck]' );
				cb.click();
			}
		}, needle );
		// The list soft-reloads after an action; rows checked mid-reload are
		// replaced and the selection evaporates. Wait for the table to settle
		// (no busy marker, rows present) before each pass, and re-select if
		// the count never reached what was asked for.
		const listSettled = () => page.waitForFunction( () => {
			const tbl = document.querySelector( '.minn-table' );
			return !! tbl && ! tbl.classList.contains( 'minn-busy' ) && !! document.querySelector( '[data-scheck]' );
		}, null, { timeout: 30000 } );
		const selectRows = async ( ...needles ) => {
			for ( let attempt = 0; attempt < 3; attempt++ ) {
				await listSettled();
				for ( const n of needles ) await selectByText( n );
				const ok = await page.waitForFunction( ( want ) => {
					const c = document.querySelector( '.minn-bulk-count' );
					return !! c && c.textContent.trim() === want;
				}, `${ needles.length } selected`, { timeout: 8000 } ).then( () => true ).catch( () => false );
				if ( ok ) return true;
				await page.evaluate( () => document.querySelectorAll( '[data-scheck]:checked' ).forEach( ( cb ) => cb.click() ) ).catch( () => {} );
			}
			return false;
		};
		t.check( 'bulk bar counts the selection', await selectRows( 'gf workflow wf-one', 'gf workflow wf-two' ) );
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
		t.check( 'bulk bar counts the trash selection', await selectRows( 'gf workflow wf-two', 'gf workflow wf-three' ) );
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

		// Restore wf-two from its page.
		await openRow( 'wf-two' );
		t.check( 'trash view offers Restore + Delete permanently', await actionLabels().then( ( labels ) =>
			labels.includes( 'Restore' ) && labels.includes( 'Delete permanently' )
				&& ! labels.includes( 'Trash entry' ) && ! labels.includes( 'Mark as spam' ) ) );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '[data-epact]' ) ].find( ( b ) => b.textContent.trim() === 'Restore' ).click();
		} );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Restore — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const restored = await entry( ids[ 1 ] );
		t.check( 'restore lands the entry back in active', restored.status === 'active', restored.status );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-epact]' ) ].some( ( b ) => b.textContent.trim() === 'Trash entry' ), null, { timeout: 20000 } ).catch( () => {} );
		t.check( 'the page reloads in place with the received actions', await actionLabels().then( ( l ) => l.includes( 'Trash entry' ) && ! l.includes( 'Restore' ) ) );
		await backToList();

		// Delete wf-three permanently from its page: Minn's confirm, then the list.
		await openRow( 'wf-three' );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '[data-epact]' ) ].find( ( b ) => b.textContent.trim() === 'Delete permanently' ).click();
		} );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 10000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Delete permanently — done/.test( tEl.textContent );
		}, { timeout: 20000 } );
		const gone = await gf( `gf/v2/entries/${ ids[ 2 ] }` );
		t.check( 'permanent delete really deletes', gone.status === 404 || ( gone.body && gone.body.code ), String( gone.status ) );
		// The trash view may be empty now (wf-two restored, wf-three gone),
		// so wait for the list's filters, not a row checkbox.
		await page.waitForSelector( '[data-sfilter="active"]', { timeout: 20000 } );
		t.check( 'a permanent delete returns to the list', ! /\/entry\//.test( page.url() ) );

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
