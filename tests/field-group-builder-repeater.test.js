/**
 * Field group builder — repeater sub-field mini-builder (one nested level).
 * Builds a repeater with text + select subs, saves the whole group as one
 * unit, then exercises sub reorder / duplicate / delete and the top-level
 * duplicate of a repeater (subs clone as new fields), plus the server's
 * one-level guard (a new sub of type repeater refuses 400).
 *
 * Repeaters are ACF Pro, so on the minnadmin dev site this suite SKIPs
 * (exit 0). Run it for real against the ACF Pro lab:
 *
 *   MINN_TEST_URL=https://acf-pro.localhost MINN_TEST_USER=austin \
 *   MINN_TEST_PASS=… node field-group-builder-repeater.test.js
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'field-group-builder-repeater' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.restUrl, null, { timeout: 15000 } );

	const api = ( method, route, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, route, body } );
	const sweep = async () => {
		const list = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		for ( const g of ( ( list && list.items ) || [] ) ) {
			if ( /^Builder Repeater Suite/.test( g.title ) ) await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + g.id + '?force=1' );
		}
	};
	const saveGroup = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /acf\/schema\/groups\/[^/]+\/full/.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-fgb-save' );
		const res = await wait;
		await page.waitForTimeout( 600 ); // adopt + re-render settle
		return res.status();
	};
	const themedConfirm = async ( label ) => {
		await page.waitForSelector( '.minn-confirm-overlay button', { timeout: 5000 } ).catch( () => {} );
		await page.evaluate( ( lb ) => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			if ( ov ) {
				const btn = Array.from( ov.querySelectorAll( 'button' ) ).find( ( b ) => new RegExp( lb ).test( b.textContent ) );
				if ( btn ) btn.click();
			}
		}, label );
	};

	let gkey = '';
	try {
		const created = await api( 'POST', 'minn-admin/v1/acf/schema/groups', { title: 'Builder Repeater Suite Group', location: 'post_type:post' } );
		if ( created.status !== 200 && created.status !== 201 ) {
			console.log( 'SKIP: schema routes unavailable (' + created.status + ')' );
			await browser.close().catch( () => {} );
			process.exit( 0 );
		}
		const list = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		gkey = ( list.items.find( ( g ) => g.title === 'Builder Repeater Suite Group' ) || {} ).id;
		let full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full' ) ).data;
		if ( ! ( full.types || [] ).includes( 'repeater' ) ) {
			console.log( 'SKIP: repeater type not offered (ACF Pro required)' );
			await sweep();
			await browser.close().catch( () => {} );
			process.exit( 0 );
		}

		/* ===== Build: a repeater with a text sub and a select sub. ===== */
		await page.goto( BASE + '/minn-admin/field-groups/' + gkey, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-fgb-add', { timeout: 20000 } );
		await page.click( '#minn-fgb-add' );
		await page.waitForSelector( '[data-fgb="0:label"]', { timeout: 5000 } );
		await page.type( '[data-fgb="0:label"]', 'Team' );
		await page.selectOption( '[data-fgb="0:type"]', 'repeater' );
		await page.waitForSelector( '[data-fgbsubadd="0"]', { timeout: 5000 } );
		t.check( 'repeater settings render min/max + button label',
			!! ( await page.$( '[data-fgb="0:min"]' ) ) && !! ( await page.$( '[data-fgb="0:button_label"]' ) ) );
		await page.type( '[data-fgb="0:button_label"]', 'Add member' );

		await page.click( '[data-fgbsubadd="0"]' );
		await page.waitForSelector( '[data-fgb="0.0:label"]', { timeout: 5000 } );
		await page.type( '[data-fgb="0.0:label"]', 'Member Name' );
		t.check( 'sub name auto-derives from the label', await page.$eval( '[data-fgb="0.0:name"]', ( e ) => e.value ) === 'member_name' );

		await page.click( '[data-fgbsubadd="0"]' );
		await page.waitForSelector( '[data-fgb="0.1:label"]', { timeout: 5000 } );
		t.check( 'sub type list drops repeater (one level)', await page.$eval( '[data-fgb="0.1:type"]', ( e ) =>
			! Array.from( e.options ).some( ( o ) => o.value === 'repeater' ) ) );
		await page.type( '[data-fgb="0.1:label"]', 'Member Role' );
		await page.selectOption( '[data-fgb="0.1:type"]', 'select' );
		await page.waitForSelector( '[data-fgb="0.1:choices"]', { timeout: 5000 } );
		await page.type( '[data-fgb="0.1:choices"]', 'lead : Lead\nsupport : Support' );

		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		const rep = full.fields[ 0 ];
		t.check( 'repeater saved with both subs and settings', full.fields.length === 1
			&& rep.type === 'repeater' && rep.editable && rep.button_label === 'Add member'
			&& rep.sub_fields.length === 2
			&& rep.sub_fields[ 0 ].name === 'member_name' && rep.sub_fields[ 0 ].type === 'text'
			&& rep.sub_fields[ 1 ].name === 'member_role' && /support : Support/.test( rep.sub_fields[ 1 ].choices )
			&& rep.sub_fields.every( ( s ) => s.key ),
			JSON.stringify( ( rep.sub_fields || [] ).map( ( s ) => s.name + ':' + s.type ) ) );

		/* ===== Sub reorder via the row controls, saved as one unit. ===== */
		await page.waitForSelector( '[data-fgbmv="0.1:-1"]', { timeout: 5000 } );
		await page.click( '[data-fgbmv="0.1:-1"]' );
		await page.waitForFunction( () =>
			document.querySelector( '.minn-fgb-subs .minn-fgb-name' ) &&
			document.querySelector( '.minn-fgb-subs .minn-fgb-name' ).textContent === 'member_role',
		null, { timeout: 5000 } );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'sub reorder persists', full.fields[ 0 ].sub_fields[ 0 ].name === 'member_role',
			JSON.stringify( full.fields[ 0 ].sub_fields.map( ( s ) => s.name ) ) );

		/* ===== Sub duplicate: a new sub with carried settings. ===== */
		await page.click( '[data-fgbdup="0.0"]' );
		await page.waitForSelector( '[data-fgb="0.1:label"]', { timeout: 5000 } );
		t.check( 'sub duplicate opens the copy below', await page.$eval( '[data-fgb="0.1:name"]', ( e ) => e.value ) === 'member_role_copy' );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		const subCopy = full.fields[ 0 ].sub_fields.find( ( s ) => s.name === 'member_role_copy' );
		t.check( 'sub copy persists with its own key and choices', full.fields[ 0 ].sub_fields.length === 3
			&& !! subCopy && /lead : Lead/.test( subCopy.choices ) && subCopy.key !== full.fields[ 0 ].sub_fields[ 0 ].key,
			JSON.stringify( full.fields[ 0 ].sub_fields.map( ( s ) => s.name ) ) );

		/* ===== Sub delete (existing sub → themed confirm), saved. ===== */
		await page.click( '[data-fgbdel="0.1"]' );
		await themedConfirm( 'Remove' );
		await page.waitForFunction( () =>
			document.querySelectorAll( '.minn-fgb-subs .minn-fgb-row' ).length === 2, null, { timeout: 5000 } );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'deleted sub is gone from the stored schema', full.fields[ 0 ].sub_fields.length === 2
			&& ! full.fields[ 0 ].sub_fields.some( ( s ) => s.name === 'member_role_copy' ),
			JSON.stringify( full.fields[ 0 ].sub_fields.map( ( s ) => s.name ) ) );

		/* ===== One-level guard: a new sub of type repeater refuses. ===== */
		const nested = await api( 'POST', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full', {
			fields: [ {
				key: full.fields[ 0 ].key,
				sub_fields: full.fields[ 0 ].sub_fields.map( ( s ) => ( { key: s.key } ) )
					.concat( [ { type: 'repeater', label: 'Nested', name: 'nested' } ] ),
			} ],
		} );
		t.check( 'nested repeater sub refuses 400', nested.status === 400, JSON.stringify( nested.data ) );
		const afterNested = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'refused save wrote nothing', afterNested.fields[ 0 ].sub_fields.length === 2 );

		/* ===== Top-level duplicate of the repeater: subs clone as new
		 * fields with their own keys. ===== */
		await page.click( '[data-fgbdup="0"]' );
		await page.waitForFunction( () =>
			document.querySelectorAll( '.minn-fgb-rows > .minn-fgb-row' ).length >= 2, null, { timeout: 5000 } );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		const orig = full.fields.find( ( f ) => f.name === 'team' );
		const copy = full.fields.find( ( f ) => f.name === 'team_copy' );
		t.check( 'repeater duplicate persists with cloned subs on new keys',
			!! orig && !! copy && copy.type === 'repeater' && copy.sub_fields.length === 2
			&& copy.sub_fields.map( ( s ) => s.name ).join() === orig.sub_fields.map( ( s ) => s.name ).join()
			&& ! copy.sub_fields.some( ( s ) => orig.sub_fields.some( ( o ) => o.key === s.key ) ),
			JSON.stringify( full.fields.map( ( f ) => f.name + ':' + ( f.sub_fields || [] ).length ) ) );
	} finally {
		await sweep().catch( () => {} );
	}

	await t.done( browser, errors );
} )();
