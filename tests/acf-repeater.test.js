/**
 * ACF repeater fields through the panel's `rows` control: rows render one
 * card each with the sub-field controls — including image (media picker)
 * and gallery (images editor in items mode) — and edits/adds/reorders/
 * deletes save through the server-side row merge ({ __idx, values } — each
 * kept row overlays only its mapped subs onto the original stored row, so
 * sub-values the form doesn't render survive).
 *
 * Repeaters are ACF Pro. minnadmin runs Pro with the "Minn Repeater Lab"
 * group (team_members: name text, role select, bio textarea, photo image,
 * shots gallery), so this runs there; without Pro or a repeater on posts it
 * SKIPs (exit 0). Run it against another ACF Pro site with:
 *
 *   MINN_TEST_URL=https://acf-pro.localhost MINN_TEST_USER=austin \
 *   MINN_TEST_PASS=… node acf-repeater.test.js
 */
const { launch, login, deletePost, openEditor, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-repeater' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// A rows field on posts is the precondition (ACF Pro + a repeater group).
	const rowsField = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/acf/fields?post_type=posts&post_id=0', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		for ( const g of ( ( await r.json() ).groups || [] ) ) {
			const f = ( g.fields || [] ).find( ( x ) => x.type === 'rows' );
			if ( f ) return f;
		}
		return null;
	} );
	if ( ! rowsField ) {
		console.log( 'SKIP: no repeater with simple sub-fields on posts (ACF Pro required)' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}
	const F = rowsField.name;
	const textSub = ( rowsField.subfields.find( ( s ) => s.type === 'text' ) || rowsField.subfields[ 0 ] ).name;
	const selSub = ( rowsField.subfields.find( ( s ) => s.type === 'select' && s.choices ) || {} ).name;

	const id = await page.evaluate( async ( args ) => {
		const row = { values: {} };
		row.values[ args.textSub ] = 'Row one';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { title: 'Repeater suite', status: 'draft', content: '<p>x</p>', minn_acf: { [ args.F ]: [ row ] } } ),
		} );
		return ( await r.json() ).id;
	}, { F, textSub } );

	const readRows = () => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + args.id + '?context=edit&_fields=minn_acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).minn_acf[ args.F ];
	}, { id, F } );
	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 500 );
	};
	const openPanel = async () => {
		// The dialog reopens on its own after picker / images-editor flows —
		// only knock on the door when it is actually closed (a click through
		// the open modal's overlay would never land).
		if ( await page.$( `[data-pf$=":${ F }"][data-ftype="rows"]` ) ) return;
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( `[data-pf$=":${ F }"][data-ftype="rows"]`, { timeout: 15000 } );
	};

	try {
		await openEditor( page, id );
		await openPanel();
		t.check( 'seeded row renders as a card with its subs', await page.evaluate( ( args ) => {
			const w = document.querySelector( `[data-pf$=":${ args.F }"]` );
			return w.querySelectorAll( '.minn-rows-card' ).length === 1
				&& w.querySelector( `[data-rowsub="0:${ args.textSub }"]` )?.value === 'Row one';
		}, { F, textSub } ) );
		if ( rowsField.subLocked ) {
			t.check( 'complex subs render the per-row locked note', await page.$eval(
				`[data-pf$=":${ F }"]`, ( w ) => /per row/.test( w.textContent ) ) );
		}

		// Edit + add a second row, save, verify.
		await page.fill( `[data-rowsub="0:${ textSub }"]`, 'Row one edited' );
		await page.click( `[data-pf$=":${ F }"] [data-radd]` );
		await page.waitForSelector( `[data-rowsub="1:${ textSub }"]`, { timeout: 5000 } );
		await page.fill( `[data-rowsub="1:${ textSub }"]`, 'Row two' );
		if ( selSub ) {
			const choice = await page.$eval( `[data-rowsub="1:${ selSub }"]`, ( e ) => e.options[ e.options.length - 1 ].value );
			await page.selectOption( `[data-rowsub="1:${ selSub }"]`, choice );
		}
		await save();
		let rows = await readRows();
		t.check( 'edit + add persisted in order', rows.length === 2
			&& rows[ 0 ].values[ textSub ] === 'Row one edited' && rows[ 1 ].values[ textSub ] === 'Row two',
			JSON.stringify( rows ) );

		// Image sub: the media picker REPLACES the panel modal (the app's
		// one-way flow), the pick lands in panelValues and the dialog
		// reopens on its own; ⌘S saves it like any panel edit.
		const imgSub = ( rowsField.subfields.find( ( s ) => s.type === 'image' ) || {} ).name;
		const galSub = ( rowsField.subfields.find( ( s ) => s.type === 'gallery' ) || {} ).name;
		if ( imgSub ) {
			t.check( 'image sub renders the image control in the row card',
				!! ( await page.$( `[data-rowsub="0:${ imgSub }"][data-ftype="image"]` ) ) );
			await page.click( `[data-rowsub="0:${ imgSub }"] [data-img-pick]` );
			await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
			await page.click( '.minn-picker-item[data-pick]' );
			await page.waitForTimeout( 800 );
			await save();
			rows = await readRows();
			t.check( 'picked row image persisted as { id, url }',
				rows[ 0 ].values[ imgSub ] && rows[ 0 ].values[ imgSub ].id > 0,
				JSON.stringify( rows[ 0 ] && rows[ 0 ].values[ imgSub ] ) );
		}

		// Gallery sub: the images editor in items mode (the row's Edit
		// button closes the panel modal first — the media picker must be
		// able to stack above the images editor — and Apply reopens it).
		if ( galSub ) {
			await openPanel();
			t.check( 'gallery sub renders the gallery control in the row card',
				!! ( await page.$( `[data-rowsub="0:${ galSub }"][data-ftype="gallery"]` ) ) );
			await page.click( `[data-rowsub="0:${ galSub }"] [data-gal-edit]` );
			await page.waitForSelector( '#minn-imgedit-add', { timeout: 10000 } );
			await page.click( '#minn-imgedit-add' );
			await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
			await page.click( '.minn-picker-item[data-pick="0"]' );
			await page.click( '.minn-picker-item[data-pick="1"]' );
			await page.click( '#minn-picker-done' );
			await page.waitForFunction( () => document.querySelectorAll( '.minn-imgedit-tile' ).length === 2, null, { timeout: 10000 } );
			await page.click( '#minn-imgedit-apply' );
			await page.waitForTimeout( 600 );
			await save();
			rows = await readRows();
			t.check( 'picked row gallery persisted as ordered { id, url } items',
				Array.isArray( rows[ 0 ].values[ galSub ] ) && rows[ 0 ].values[ galSub ].length === 2
				&& rows[ 0 ].values[ galSub ].every( ( x ) => x.id > 0 ),
				JSON.stringify( rows[ 0 ] && rows[ 0 ].values[ galSub ] ) );
		}

		// Fresh anchors from the server, then move row 2 up and delete the old
		// row 1 — the merge must follow the referenced rows, not positions.
		await openEditor( page, id );
		await openPanel();
		await page.click( `[data-pf$=":${ F }"] [data-rmv="1:-1"]` );
		await page.waitForTimeout( 300 );
		await page.click( `[data-pf$=":${ F }"] [data-rdel="1"]` );
		await page.waitForTimeout( 300 );
		await save();
		rows = await readRows();
		t.check( 'reorder + delete persisted', rows.length === 1 && rows[ 0 ].values[ textSub ] === 'Row two',
			JSON.stringify( rows ) );

		// Emptying the repeater is a legitimate save.
		await openEditor( page, id );
		await openPanel();
		await page.click( `[data-pf$=":${ F }"] [data-rdel="0"]` );
		await page.waitForTimeout( 300 );
		await save();
		rows = await readRows();
		t.check( 'emptied repeater persisted', Array.isArray( rows ) && rows.length === 0, JSON.stringify( rows ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
