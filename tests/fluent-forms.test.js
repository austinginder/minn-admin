/**
 * Fluent Forms adapter — forms family (entries + forms).
 *
 * Proves: submissions list as entry cards (summary from response JSON,
 * form title, unread/read/spam/trashed status, site-local dates), form
 * tabs + search over the response blob, status filters (Received / Spam /
 * Trash), sectionsRoute detail with labels from form_fields + open marks
 * read, Trash → permanent delete through the status/delete routes, Forms
 * manage view with live entry counts and the edit-in-Fluent escape hatch.
 *
 * Fixture: standing "Minn Contact" form (names/email/message) +
 * minn_test_seed_fluent_forms (upsert by email into fluentform_submissions).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'fluent-forms' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v || ( v === '1' && ( stored === '' || stored == null ) ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		return await r.json();
	}, path );

	const restTotal = ( status = 'inbox' ) => page.evaluate( async ( st ) => {
		const r = await fetch(
			window.MINN.restUrl + 'minn-admin/v1/fluent-forms/entries?status=' + st + '&_cb=' + Math.random(),
			{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
		);
		return ( await r.json() ).total;
	}, status );

	try {
		// Resident fixture; ensure active (forms family convention).
		await page.evaluate( async () => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/fluentform/fluentform', {
					method: 'PUT', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket ok */ }
		} );

		t.check( 'entry seeder armed', await setOpt( 'minn_test_seed_fluent_forms', '1' ) );

		// Poll until the three fixture emails show in the inbox list.
		let list = null;
		for ( let i = 0; i < 10; i++ ) {
			list = await api( 'minn-admin/v1/fluent-forms/entries?status=inbox&per_page=50&_cb=' + Math.random() ).catch( () => null );
			if ( list && list.items && [ 'dana.fluent@', 'miguel.fluent@', 'priya.fluent@' ].every( ( n ) =>
				list.items.some( ( r ) => ( r.summary || '' ).includes( n ) ) ) ) break;
			// Re-arm if the first write raced; seeder is one-shot.
			if ( i === 3 ) await setOpt( 'minn_test_seed_fluent_forms', '1' );
			await page.waitForTimeout( 700 );
		}
		t.check( 'fixture entries listed', !! list && list.total >= 3, JSON.stringify( list && { total: list.total, n: ( list.items || [] ).length } ) );

		const dana = list.items.find( ( r ) => ( r.summary || '' ).includes( 'dana.fluent@' ) );
		t.check( 'entry cards carry summary/form/status/local date',
			!! dana && /Dana Tester/.test( dana.summary ) && /dana\.fluent@/.test( dana.summary )
			&& dana.form_title && ! /Z$/.test( dana.date || '' ),
			JSON.stringify( dana ) );

		const searched = await api( 'minn-admin/v1/fluent-forms/entries?status=inbox&search=' + encodeURIComponent( 'popup' ) );
		t.check( 'search matches response JSON', searched.total >= 1
			&& searched.items.every( ( r ) => /miguel\.fluent@/.test( r.summary || '' ) ),
		JSON.stringify( { total: searched.total, first: searched.items && searched.items[ 0 ] } ) );

		const detail = await api( 'minn-admin/v1/fluent-forms/entries/' + dana.id );
		t.check( 'detail sections carry labeled answers', detail.kind === 'entry'
			&& ( detail.sections || [] )[ 0 ] && detail.sections[ 0 ].rows.some( ( r ) =>
				/Message/i.test( r.label ) && /exports are slow/.test( r.value ) ) );
		t.check( 'detail carries submission meta + adminUrl',
			( detail.sections || [] ).some( ( s ) => s.rows && s.rows.some( ( r ) => /Form/i.test( r.label ) ) )
			&& /page=fluent_forms/.test( detail.adminUrl || '' ) );
		// Opening marks unread → read.
		const afterOpen = await api( 'minn-admin/v1/fluent-forms/entries?status=inbox&per_page=50' );
		const danaAfter = ( afterOpen.items || [] ).find( ( r ) => r.id === dana.id );
		t.check( 'opening an unread entry marks it read', !! danaAfter && danaAfter.status === 'read',
			JSON.stringify( danaAfter && danaAfter.status ) );

		const forms = await api( 'minn-admin/v1/fluent-forms/forms?manage=1' );
		t.check( 'forms manage rows carry live entry counts', Array.isArray( forms )
			&& forms.some( ( f ) => /Minn Contact|Contact Form/.test( f.title || '' ) && f.entries >= 3 ),
		JSON.stringify( forms ) );

		/* ===== Surface in the app ===== */
		await page.goto( BASE + '/minn-admin/fluent-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

		t.check( 'surface joins the forms family in the workspace', await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'fluent-forms' );
			return !! s && s.family === 'forms' && s.group === 'workspace' && s.sub === 'Fluent Forms';
		} ) );

		const body = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'seeded entries render with answer summaries',
			body.includes( 'Dana Tester' ) && body.includes( 'dana.fluent@' ) );
		t.check( 'form column names a form',
			body.includes( 'Minn Contact' ) || body.includes( 'Contact Form' ) );

		const tabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'form tabs render', tabs[ 0 ] === 'All entries' && tabs.some( ( x ) => /Minn Contact|Contact/.test( x ) ),
			tabs.join( ' · ' ) );

		const filters = await page.$$eval( '[data-sfilter]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'status filter renders Received/Spam/Trash',
			filters.includes( 'Received' ) && filters.includes( 'Spam' ) && filters.includes( 'Trash' ),
			filters.join( ' · ' ) );

		/* ===== Search ===== */
		await page.fill( '#minn-surface-search', 'popup' );
		await page.waitForFunction( () => {
			const rows = document.querySelectorAll( '.minn-table-row' );
			return rows.length >= 1 && [ ...rows ].every( ( r ) => /Miguel/.test( r.textContent ) );
		}, null, { timeout: 20000 } );
		t.check( 'search filters entries in the UI', true );
		await page.fill( '#minn-surface-search', '' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length >= 3, null, { timeout: 20000 } );

		/* ===== Detail modal ===== */
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /Priya/.test( r.textContent ) ).click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		const modal = await page.$eval( '.minn-modal', ( el ) => el.textContent );
		t.check( 'answers wear their field labels',
			/Message/i.test( modal ) && /thank-you page 404s/.test( modal ) );
		t.check( 'entry renders as a contact card', !! ( await page.$( '.minn-modal.entry' ) ) );
		t.check( 'card links out to Fluent Forms', await page.$$eval( '.minn-modal a[href]', ( els ) =>
			els.some( ( a ) => /page=fluent_forms/.test( a.href ) ) ) );

		/* ===== Trash through status route ===== */
		const inboxBefore = await restTotal( 'inbox' );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			window.confirm = () => true;
			[ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ]
				.find( ( b ) => /Trash entry/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), null, { timeout: 15000 } );
		t.check( 'trash left fewer received entries', ( await restTotal( 'inbox' ) ) === inboxBefore - 1,
			`before=${ inboxBefore } after=${ await restTotal( 'inbox' ) }` );
		t.check( 'trash bucket has the entry', ( await restTotal( 'trashed' ) ) >= 1 );

		/* ===== Trash filter + permanent delete ===== */
		// Evaluate-click: the form-tab strip's scroll chevron can intercept a
		// Playwright locator click on the Trash status filter.
		await page.evaluate( () => {
			const btn = document.querySelector( '[data-sfilter="trashed"]' );
			if ( btn ) btn.click();
		} );
		await page.waitForFunction( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			return rows.some( ( r ) => /Priya/.test( r.textContent ) );
		}, null, { timeout: 20000 } );
		t.check( 'trash filter lists the trashed entry', true );

		await page.evaluate( () => {
			[ ...document.querySelectorAll( '.minn-table-row' ) ]
				.find( ( r ) => /Priya/.test( r.textContent ) ).click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			window.confirm = () => true;
			[ ...document.querySelectorAll( '.minn-modal [data-saction]' ) ]
				.find( ( b ) => /Delete permanently/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), null, { timeout: 15000 } );
		t.check( 'permanent delete removed the entry', ( await restTotal( 'trashed' ) ) === 0 );

		/* ===== Forms manage view ===== */
		await page.click( '[data-sview="manage"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const formRow = await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			const row = rows.find( ( r ) => /Minn Contact/.test( r.textContent ) )
				|| rows.find( ( r ) => /Contact Form Demo/.test( r.textContent ) );
			return row ? row.textContent.replace( /\s+/g, ' ' ).trim() : '';
		} );
		t.check( 'forms view lists the form with a live count',
			/Minn Contact/.test( formRow ) && /[1-9]\d*/.test( formRow ),
			formRow || '(no row)' );

		await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			const row = rows.find( ( r ) => /Minn Contact/.test( r.textContent ) )
				|| rows.find( ( r ) => /Contact Form Demo/.test( r.textContent ) );
			if ( row ) row.click();
		} );
		await page.waitForSelector( '.minn-modal a[href]', { timeout: 15000 } );
		t.check( 'form row links into Fluent\'s editor', await page.$$eval( '.minn-modal a[href]', ( els ) =>
			els.some( ( a ) => /page=fluent_forms.*form_id=\d+|route=editor/.test( a.href ) ) ) );
	} finally {
		// Restore the deleted Priya row for the next run.
		await setOpt( 'minn_test_seed_fluent_forms', '1' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
