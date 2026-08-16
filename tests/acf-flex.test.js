/**
 * ACF flexible content through the `flex` control: one card per stored
 * section, each rendering ITS OWN layout's fields, collapsed by default.
 * Adds pick a layout first; edits, reorders and deletes save through the
 * same { __idx, values } merge repeaters use, plus a layout dimension.
 *
 * The preservation discipline is the point of most of these checks: a kept
 * row overlays only the subs the form rendered onto the stored row it
 * references, so a locked sub (a wysiwyg in a layout) survives an edit to
 * its sibling, a reorder, and an add — and a section whose layout the field
 * no longer declares rides through untouched rather than disappearing.
 *
 * Flexible content is ACF Pro. minnadmin runs Pro with `page_sections` on
 * the "Post details" group (layouts: banner = heading text + tone select +
 * notes wysiwyg (locked), faq = question text + answer textarea) and
 * `opt_sections` on the "Minn Options Lab" page (hero / quote / spacer /
 * embed). Without a flex field on posts this SKIPs (exit 0); the options
 * half skips on its own if no options page carries one.
 *
 * The retired-layout case lives on the options side because ACF's REST
 * schema validates `acf_fc_layout` against the declared layouts, so nothing
 * a browser can send creates one. The options lab holds a STANDING section
 * of a `retired_banner` layout that the field does not declare, exactly as a
 * schema change would leave it; suites must not delete it. Run elsewhere:
 *
 *   MINN_TEST_URL=https://acf-pro.localhost MINN_TEST_USER=austin \
 *   MINN_TEST_PASS=… node acf-flex.test.js
 */
const { launch, login, deletePost, openEditor, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-flex' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const flexField = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/acf/fields?post_type=posts&post_id=0', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		for ( const g of ( ( await r.json() ).groups || [] ) ) {
			const f = ( g.fields || [] ).find( ( x ) => x.type === 'flex' );
			if ( f ) return f;
		}
		return null;
	} );
	if ( ! flexField ) {
		console.log( 'SKIP: no flexible-content field on posts (ACF Pro required)' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}
	const F = flexField.name;
	const names = Object.keys( flexField.layouts );
	// Two layouts with different schemas is what makes this a flex test at
	// all; one of them should carry a locked sub for the preservation check.
	const withLock = names.find( ( n ) => flexField.layouts[ n ].subLocked > 0 ) || names[ 0 ];
	const other = names.find( ( n ) => n !== withLock ) || names[ 0 ];
	const textSubOf = ( n ) => ( flexField.layouts[ n ].subfields.find( ( s ) => s.type === 'text' )
		|| flexField.layouts[ n ].subfields[ 0 ] ).name;
	const lockText = textSubOf( withLock );
	const otherText = textSubOf( other );

	t.check( 'field maps every declared layout', names.length >= 2, names.join( ',' ) );
	t.check( 'a layout with an unrenderable sub reports it as locked',
		flexField.layouts[ withLock ].subLocked > 0, String( flexField.layouts[ withLock ].subLocked ) );
	t.check( 'layouts ship a label and their own subfields',
		names.every( ( n ) => typeof flexField.layouts[ n ].label === 'string' && Array.isArray( flexField.layouts[ n ].subfields ) ) );

	// Seed through ACF's OWN rest field — the only way to put a value in a
	// sub Minn's form never renders, and the only way to store a section of
	// a layout the field no longer declares (nothing in Minn creates one).
	const id = await page.evaluate( async ( a ) => {
		const rows = [
			{ acf_fc_layout: a.withLock, [ a.lockText ]: 'Seeded first' },
			{ acf_fc_layout: a.other, [ a.otherText ]: 'Seeded second' },
		];
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { title: 'Flex suite', status: 'draft', content: '<p>x</p>', acf: { [ a.F ]: rows } } ),
		} );
		return ( await r.json() ).id;
	}, { F, withLock, other, lockText, otherText } );

	// Which sub of that layout does Minn NOT render? ACF's own response lists
	// them all, so the difference names the locked one — then seed it with a
	// sentinel the form can never send back. The rows are rebuilt rather than
	// round-tripped: ACF answers `false` for any sub with no value and its own
	// REST schema then rejects that same `false` on the way back in.
	const lockedKey = await page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id + '?context=edit&_fields=acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		const row = ( await r.json() ).acf[ a.F ][ 0 ];
		const key = Object.keys( row ).find( ( k ) => k !== 'acf_fc_layout' && a.mapped.indexOf( k ) === -1 );
		if ( ! key ) return null;
		const rows = [
			{ acf_fc_layout: a.withLock, [ a.lockText ]: 'Seeded first', [ key ]: '<p>locked note</p>' },
			{ acf_fc_layout: a.other, [ a.otherText ]: 'Seeded second' },
		];
		const w = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { acf: { [ a.F ]: rows } } ),
		} );
		return w.ok ? key : null;
	}, { id, F, withLock, other, lockText, otherText, mapped: flexField.layouts[ withLock ].subfields.map( ( s ) => s.name ) } );

	const readFlex = () => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id + '?context=edit&_fields=minn_acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).minn_acf[ a.F ];
	}, { id, F } );
	const readRaw = () => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id + '?context=edit&_fields=acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).acf[ a.F ];
	}, { id, F } );

	const sel = `[data-pf$=":${ F }"][data-ftype="flex"]`;
	const cards = () => page.$$eval( sel + ' .minn-rows-card', ( els ) => els.map( ( e ) => ( {
		title: ( e.querySelector( '.minn-rows-n' ) || {} ).textContent,
		preview: ( e.querySelector( '.minn-rows-preview' ) || {} ).textContent,
		open: e.classList.contains( 'open' ),
		hidden: !! ( e.querySelector( '.minn-rows-body' ) || {} ).hidden,
		note: ( ( e.querySelector( '.minn-insp-note' ) || {} ).textContent || '' ).trim(),
		subs: e.querySelectorAll( '[data-rowsub]' ).length,
	} ) ) );
	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 600 );
	};
	const openPanel = async () => {
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( sel, { timeout: 15000 } );
	};

	try {
		await openEditor( page, id );
		await openPanel();

		const seeded = await cards();
		t.check( 'every stored section renders as a card', seeded.length === 2, JSON.stringify( seeded.map( ( c ) => c.title ) ) );
		t.check( 'cards start collapsed', seeded.every( ( c ) => ! c.open && c.hidden ), JSON.stringify( seeded.map( ( c ) => c.open ) ) );
		t.check( 'card head names the layout', seeded[ 0 ].title === flexField.layouts[ withLock ].label
			&& seeded[ 1 ].title === flexField.layouts[ other ].label, seeded.map( ( c ) => c.title ).join( ' | ' ) );
		t.check( 'card head previews the section\'s first text',
			/Seeded first/.test( seeded[ 0 ].preview ) && /Seeded second/.test( seeded[ 1 ].preview ),
			seeded.map( ( c ) => c.preview ).join( ' | ' ) );
		// Expanding one card must not disturb the others.
		await page.click( sel + ' .minn-rows-card:nth-of-type(1) .minn-rows-toggle' );
		await page.waitForTimeout( 250 );
		const opened = await cards();
		t.check( 'the toggle expands exactly its own card',
			opened[ 0 ].open && ! opened[ 0 ].hidden && ! opened[ 1 ].open, JSON.stringify( opened.map( ( c ) => c.open ) ) );
		t.check( 'an expanded card renders only its layout\'s fields',
			opened[ 0 ].subs === flexField.layouts[ withLock ].subfields.length,
			opened[ 0 ].subs + ' vs ' + flexField.layouts[ withLock ].subfields.length );
		t.check( 'the locked sub is named as living in wp-admin',
			/lives in wp-admin|live in wp-admin/.test( opened[ 0 ].note ), opened[ 0 ].note );

		// Edit the first section's text; the collapsed summary tracks typing.
		await page.click( `${ sel } [data-rowsub="0:${ lockText }"]`, { clickCount: 3 } );
		await page.keyboard.type( 'Edited first' );
		await page.waitForTimeout( 200 );
		t.check( 'the summary tracks typing without a re-render',
			/Edited first/.test( await page.$eval( sel + ' .minn-rows-card:nth-of-type(1) .minn-rows-preview', ( e ) => e.textContent ) ) );
		await save();
		let stored = await readFlex();
		t.check( 'the edit saved into its own section', stored[ 0 ].values[ lockText ] === 'Edited first', JSON.stringify( stored[ 0 ].values ) );
		t.check( 'the sibling section is untouched', stored[ 1 ].values[ otherText ] === 'Seeded second', JSON.stringify( stored[ 1 ].values ) );
		let raw = await readRaw();
		if ( lockedKey ) {
			t.check( 'the locked sub survived the edit', /locked note/.test( String( raw[ 0 ][ lockedKey ] || '' ) ), JSON.stringify( raw[ 0 ][ lockedKey ] ) );
		}
		// Add: the layout picker is the add.
		await page.click( sel + ' [data-radd]' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const menu = await page.$$eval( '.minn-ctx-menu button', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'the add menu offers every layout', menu.length === names.length
			&& names.every( ( n ) => menu.includes( flexField.layouts[ n ].label ) ), menu.join( ' | ' ) );
		await page.evaluate( ( label ) => {
			[ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === label ).click();
		}, flexField.layouts[ other ].label );
		await page.waitForTimeout( 300 );
		const added = await cards();
		t.check( 'the picked layout is appended, expanded', added.length === 3
			&& added[ 2 ].title === flexField.layouts[ other ].label && added[ 2 ].open, JSON.stringify( added.map( ( c ) => c.title + ':' + c.open ) ) );
		await page.type( `${ sel } [data-rowsub="2:${ otherText }"]`, 'Added section' );
		await save();
		stored = await readFlex();
		t.check( 'the added section saved with its layout', stored.length === 3
			&& stored[ 2 ].__layout === other && stored[ 2 ].values[ otherText ] === 'Added section',
			JSON.stringify( stored[ 2 ] ) );

		// Reorder: __idx anchors carry each section's unrendered subs with it.
		await page.click( sel + ' .minn-rows-card:nth-of-type(1) [data-rmv="0:1"]' );
		await page.waitForTimeout( 250 );
		await save();
		stored = await readFlex();
		raw = await readRaw();
		t.check( 'the reorder moved the whole section', stored[ 0 ].values[ otherText ] === 'Seeded second'
			&& stored[ 1 ].values[ lockText ] === 'Edited first', JSON.stringify( stored.map( ( r ) => r.__layout ) ) );
		if ( lockedKey ) {
			t.check( 'the locked sub travelled with its section', /locked note/.test( String( raw[ 1 ][ lockedKey ] || '' ) ), JSON.stringify( raw[ 1 ][ lockedKey ] ) );
		}
		// Delete: only the removed section goes.
		await page.click( sel + ' .minn-rows-card:nth-of-type(1) [data-rdel="0"]' );
		await page.waitForTimeout( 250 );
		await save();
		stored = await readFlex();
		t.check( 'the deleted section is gone and the rest stay', stored.length === 2
			&& stored[ 0 ].values[ lockText ] === 'Edited first', JSON.stringify( stored.map( ( r ) => r.__layout ) ) );
		raw = await readRaw();
		if ( lockedKey ) {
			t.check( 'deleting kept the locked sub of the sections that remain',
				/locked note/.test( String( raw[ 0 ][ lockedKey ] || '' ) ), JSON.stringify( raw[ 0 ][ lockedKey ] ) );
		}
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}

	// Options pages carry flexible content through the same control. A site
	// can register several; take the first tab of the first one that has a
	// flex field on it.
	const found = await page.evaluate( async () => {
		for ( const s of ( window.MINN.surfaces || [] ) ) {
			if ( ! s.id || ! /^acf-options/.test( s.id ) || ! s.settings ) continue;
			for ( const tab of ( s.settings.tabs || [] ) ) {
				const r = await fetch( window.MINN.restUrl + s.settings.route.replace( '{tab}', tab.id ), {
					headers: { 'X-WP-Nonce': window.MINN.nonce },
				} );
				if ( ! r.ok ) continue;
				for ( const g of ( ( await r.json() ).groups || [] ) ) {
					const f = ( g.fields || [] ).find( ( x ) => x.type === 'flex' );
					if ( f ) return { surface: s, tab: tab.id, key: f.key, layouts: f.layouts };
				}
			}
		}
		return null;
	} );
	const optSurface = found && found.surface;
	const optTab = found;
	if ( ! optTab ) {
		console.log( 'note: no options page carries a flexible-content field — options half skipped' );
	} else {
		await page.goto( BASE + '/minn-admin/' + optSurface.id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-settings [data-sset]', { timeout: 20000 } );
		if ( ( optSurface.settings.tabs || [] ).length > 1 ) {
			await page.click( `[data-ssettab="${ optTab.tab }"]` );
		}
		await page.waitForSelector( '[data-sset][data-ftype="flex"]', { timeout: 15000 } );
		const osel = `[data-sset="${ optTab.key }"]`;
		const optCards = () => page.$$eval( osel + ' .minn-rows-card', ( els ) => els.map( ( e ) => ( {
			title: ( e.querySelector( '.minn-rows-n' ) || {} ).textContent,
			open: e.classList.contains( 'open' ),
			note: ( ( e.querySelector( '.minn-insp-note' ) || {} ).textContent || '' ).trim(),
			subs: e.querySelectorAll( '[data-rowsub]' ).length,
		} ) ) );
		const shown = await optCards();
		const before = shown.length;
		t.check( 'options flex renders collapsed cards',
			before > 0 && shown.every( ( c ) => ! c.open ), 'cards: ' + before );

		// The standing section of a layout the field no longer declares.
		const optRead = () => page.evaluate( async ( a ) => {
			const r = await fetch( window.MINN.restUrl + a.route, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).values[ a.key ];
		}, { route: optSurface.settings.route.replace( '{tab}', optTab.tab ), key: optTab.key } );
		const start = await optRead();
		const retiredAt = start.findIndex( ( r ) => r.__locked );
		if ( retiredAt === -1 ) {
			console.log( 'note: no section of a retired layout in the options fixture — that half skipped' );
		} else {
			t.check( 'a section whose layout is gone renders as preserved, with nothing to edit',
				/kept exactly as it is/i.test( shown[ retiredAt ].note ) && shown[ retiredAt ].subs === 0,
				shown[ retiredAt ].note );
			t.check( 'its card head falls back to the stored layout name',
				shown[ retiredAt ].title === start[ retiredAt ].__layout, shown[ retiredAt ].title );
		}

		// Add one section, save, verify over REST, then take it back out.
		const optNames = Object.keys( optTab.layouts );
		const pick = optNames.find( ( n ) => optTab.layouts[ n ].subfields.some( ( s ) => s.type === 'text' || s.type === 'textarea' ) ) || optNames[ 0 ];
		const pickSub = optTab.layouts[ pick ].subfields.find( ( s ) => s.type === 'text' || s.type === 'textarea' ).name;
		await page.click( osel + ' [data-radd]' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( ( label ) => {
			[ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === label ).click();
		}, optTab.layouts[ pick ].label );
		await page.waitForTimeout( 300 );
		await page.type( `${ osel } [data-rowsub="${ before }:${ pickSub }"]`, 'Options flex probe' );
		const saveWait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /acf\/options\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		await saveWait;
		await page.waitForTimeout( 800 );
		const optVal = await optRead();
		t.check( 'the options section saved with its layout and value',
			optVal.length === before + 1 && optVal[ before ].__layout === pick
			&& optVal[ before ].values[ pickSub ] === 'Options flex probe', JSON.stringify( optVal[ before ] ) );
		if ( retiredAt !== -1 ) {
			t.check( 'saving around it left the retired section in place',
				optVal[ retiredAt ].__locked === true && optVal[ retiredAt ].__layout === start[ retiredAt ].__layout,
				JSON.stringify( optVal.map( ( r ) => r.__layout ) ) );
		}

		// Restore: options are singletons, so the suite cleans up through the UI.
		await page.click( `${ osel } .minn-rows-card:nth-of-type(${ before + 1 }) [data-rdel="${ before }"]` );
		await page.waitForTimeout( 250 );
		const restoreWait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && /acf\/options\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		await restoreWait;
		await page.waitForTimeout( 500 );
		const restored = await optRead();
		t.check( 'the probe section is gone again, the fixture intact',
			restored.length === before
			&& restored.map( ( r ) => r.__layout ).join( ',' ) === start.map( ( r ) => r.__layout ).join( ',' ),
			JSON.stringify( restored.map( ( r ) => r.__layout ) ) );
	}

	t.done( browser, errors );
} )();
