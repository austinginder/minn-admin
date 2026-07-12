/**
 * Surface list row actions — the content-list ⋯ / right-click menu, driven
 * by collection.actions (when-gated; fields-actions stay detail-only).
 *
 * Reference consumer: Gravity Forms entries (Star / Mark as spam / Trash).
 * Fixtures: disposable entry on form 1, force-deleted in finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'surface-row-actions' );
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

	const form = ( await gf( 'gf/v2/forms/1' ) ).body;
	const field = ( form.fields || [] ).find( ( f ) => [ 'text', 'name', 'email', 'textarea' ].includes( f.type ) ) || { id: 1 };
	const fieldKey = String( field.type === 'name' ? field.id + '.3' : field.id );
	const created = await gf( 'gf/v2/entries', {
		method: 'POST',
		body: { form_id: 1, [ fieldKey ]: 'surface row actions fixture' },
	} );
	const id = created.body && created.body.id;

	try {
		t.check( 'disposable entry created', !! id, JSON.stringify( created ) );

		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

		// Find the fixture row and assert the ⋯ control is present.
		const rowReady = await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /surface row actions fixture/.test( r.textContent ) );
			if ( ! row ) return { found: false };
			return {
				found: true,
				hasMore: !! row.querySelector( '.minn-row-more' ),
				idx: row.getAttribute( 'data-sitem' ),
			};
		} );
		t.check( 'fixture row renders with a ⋯ action button', rowReady.found && rowReady.hasMore, JSON.stringify( rowReady ) );

		// Open the menu via the ⋯ button (evaluate-click to avoid the
		// right-click detach gotcha from content-list suites).
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /surface row actions fixture/.test( r.textContent ) );
			const more = row && row.querySelector( '.minn-row-more' );
			if ( more ) more.click();
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const labels = await page.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( els ) =>
			els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'row menu lists Open + workflow verbs',
			labels.includes( 'Open' )
			&& labels.includes( 'Star' )
			&& labels.includes( 'Mark as spam' )
			&& labels.includes( 'Trash entry' )
			&& ! labels.includes( 'Add note' ), // fields-action stays detail-only
			JSON.stringify( labels ) );

		// Star via the menu (evaluate-click by text — rule-31).
		await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
				.find( ( el ) => el.textContent.trim() === 'Star' );
			if ( b ) b.click();
		} );
		await page.waitForFunction( () => {
			const toast = document.querySelector( '.minn-toast-msg' );
			return toast && /Star/.test( toast.textContent );
		}, { timeout: 15000 } );
		const starred = await gf( `gf/v2/entries/${ id }` );
		t.check( 'Star from the list menu sticks on the entry',
			String( starred.body && starred.body.is_starred ) === '1',
			JSON.stringify( starred.body && { is_starred: starred.body.is_starred } ) );

		// Right-click also opens the menu (with Unstar now that it's starred).
		await page.waitForTimeout( 300 );
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /surface row actions fixture/.test( r.textContent ) );
			if ( ! row ) return;
			row.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 200, clientY: 200 } ) );
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const afterStar = await page.$$eval( '.minn-ctx-menu button', ( els ) =>
			els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'when-gate flips Star → Unstar on the list menu',
			afterStar.includes( 'Unstar' ) && ! afterStar.includes( 'Star' ),
			JSON.stringify( afterStar ) );

		// Open still works from the menu.
		await page.evaluate( () => {
			const b = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
				.find( ( el ) => el.textContent.trim() === 'Open' );
			if ( b ) b.click();
		} );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ( m.querySelector( '.minn-entry' ) || m.querySelector( '[data-saction]' ) );
		}, { timeout: 15000 } );
		t.check( 'Open from the row menu opens the detail modal', true );

	} finally {
		if ( id ) {
			await gf( `gf/v2/entries/${ id }?force=1`, { method: 'DELETE' } ).catch( () => {} );
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
