/**
 * v0.45.0 fix-delta, the client half: rows re-anchored after a save that
 * was edited while in flight, the variation moved-stock check, item ids as
 * one route segment, safeHref and control characters, and WP Migrate's
 * requests through apiRes.
 *
 *   NI-2  A save that reorders, deletes or adds repeater / flex rows, with
 *         the same control edited while it is in flight: the next save
 *         names every kept row by the position the server now holds.
 *   NI-3  A variation sharing the parent's stock switched to its own, and a
 *         tracked variation with a null quantity, take a stock edit.
 *   H1    An item id holding ../, ? or # stays inside its route segment; a
 *         dot segment is refused; a form:id keeps its colon.
 *   H2    safeHref refuses "/\t/host" and other control-character forms.
 *   H3    WP Migrate's REST calls ride apiRes (same-origin backstop, nonce
 *         refresh) and its table loop posts to Minn's own admin-ajax URL.
 *
 * Every request that could change something outside a fixture is answered
 * by a route mock: the H1 probes never reach the server, and the WP Migrate
 * run is mocked end to end (nothing is migrated). Fixtures (a draft post,
 * a variable product) are made over REST and removed on the way out.
 *
 * MINN_DELTA_ONLY=rows|flex|stock|ids|href|migrate runs one section.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );
const { execFileSync } = require( 'child_process' );
const { launch, login, deletePost, openEditor, reporter, BASE, WP } = require( './helpers' );

const ONLY = process.env.MINN_DELTA_ONLY || '';
const want = ( s ) => ! ONLY || ONLY.split( ',' ).includes( s );
const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();

( async () => {
	const t = reporter( 'security-v045-delta-client' );

	/* ===== H2 · safeHref, and idSeg, as shipped in app.js ===== */
	if ( want( 'href' ) ) {
		const src = fs.readFileSync( path.resolve( __dirname, '../assets/js/app.js' ), 'utf8' );
		const grab = ( name ) => {
			const at = src.indexOf( `\tconst ${ name } = ` );
			if ( at < 0 ) return null;
			const eol = src.indexOf( '\n', at );
			const line = src.slice( at, eol );
			if ( ! /\{\s*$/.test( line ) ) return line;
			const end = src.indexOf( '\n\t};', at );
			return src.slice( at, end + 4 );
		};
		const fn = ( name ) => {
			const code = grab( name );
			return code ? vm.runInNewContext( code + `\n${ name };` ) : null;
		};
		const safeHref = fn( 'safeHref' );
		const host = ( u ) => {
			try { return new URL( u, 'https://minnadmin.localhost/minn-admin/' ).host; } catch ( e ) { return 'unparsable'; }
		};
		const offsite = [ '/\t/evil.example', '/\n/evil.example', '/\r\\evil.example', '/\t\\evil.example', '\t//evil.example', '/\u0001/evil.example' ];
		const leaks = offsite.filter( ( v ) => {
			const out = safeHref( v );
			return out && host( out ) !== 'minnadmin.localhost';
		} );
		t.check( 'H2: safeHref refuses tab, CR, LF and other control forms that resolve off-site', 0 === leaks.length, JSON.stringify( leaks ) );
		t.check( 'H2 control: safeHref keeps same-site paths and https links', '/wp-admin/edit.php' === safeHref( '/wp-admin/edit.php' )
			&& 'https://example.com/x' === safeHref( 'https://example.com/x' ) && '/path' === safeHref( ' /path ' ), '' );
		t.check( 'H2 control: safeHref still refuses //host, /\\host and javascript:', '' === safeHref( '//evil.example' )
			&& '' === safeHref( '/\\evil.example' ) && '' === safeHref( 'javascript:alert(1)' ), '' );
		const idSeg = fn( 'idSeg' );
		t.check( 'H1: idSeg keeps a hostile id inside one segment and keeps a colon', !! idSeg
			&& '..%2F..%2Fwp%2Fv2%2Fusers%2F2%3Fforce%3Dtrue%23' === idSeg( '../../wp/v2/users/2?force=true#' ) && '12:5f0c' === idSeg( '12:5f0c' ), idSeg ? idSeg( '../a?b#' ) : 'no idSeg in app.js' );
		let refused = 0;
		[ '.', '..' ].forEach( ( v ) => { try { idSeg( v ); } catch ( e ) { refused++; } } );
		t.check( 'H1: idSeg refuses a dot segment', !! idSeg && 2 === refused, String( refused ) );
	}

	const { browser, page, errors } = await launch();
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 30000 } );

	const api = ( p, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.p, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { p, opts } );
	const restPath = await page.evaluate( () => new URL( window.MINN.restUrl, location.origin ).pathname );

	let postId = null;
	let productId = null;

	try {
		/* ===== NI-2 · rows edited while a structural save is in flight ===== */
		const acfFields = ( want( 'rows' ) || want( 'flex' ) ) ? ( ( await api( 'minn-admin/v1/acf/fields?post_type=posts&post_id=0' ) ).body || {} ) : {};
		const fields = ( acfFields.groups || [] ).reduce( ( all, g ) => all.concat( g.fields || [] ), [] );
		const rowsField = fields.find( ( f ) => 'rows' === f.type );
		const flexField = fields.find( ( f ) => 'flex' === f.type );
		if ( ( want( 'rows' ) || want( 'flex' ) ) && ( rowsField || flexField ) ) {
			const made = await api( 'wp/v2/posts', { method: 'POST', body: JSON.stringify( { title: 'Delta rows fixture', status: 'draft', content: '<p>x</p>' } ) } );
			postId = made.body && Number.isInteger( made.body.id ) && made.body.id > 1 ? made.body.id : null;
		}
		const postUrl = postId ? new RegExp( 'wp/v2/posts/' + postId + '(\\?|$)' ) : null;
		const isSave = ( res ) => res.request().method() === 'POST' && postUrl.test( res.url() );
		const holdSave = async ( route ) => {
			if ( 'POST' === route.request().method() ) await new Promise( ( r ) => setTimeout( r, 5000 ) );
			await route.continue().catch( () => {} );
		};
		// Save with the response held; `during` runs while it is in flight.
		const heldSave = async ( during ) => {
			await page.route( postUrl, holdSave );
			const flight = page.waitForResponse( isSave, { timeout: 90000 } );
			let landed = false;
			flight.then( () => { landed = true; }, () => {} );
			await page.keyboard.press( 'Meta+s' );
			await page.waitForTimeout( 600 );
			await during();
			const late = landed;
			const res = await flight;
			await page.unroute( postUrl, holdSave );
			await page.waitForTimeout( 700 );
			let sent = {};
			try { sent = JSON.parse( res.request().postData() || '{}' ); } catch ( e ) {}
			return { status: res.status() + ( late ? ' (edit landed after it)' : '' ), sent };
		};
		const nextSaveBody = async () => {
			const flight = page.waitForResponse( isSave, { timeout: 90000 } );
			await page.keyboard.press( 'Meta+s' );
			const res = await flight;
			await page.waitForTimeout( 600 );
			return JSON.parse( res.request().postData() || '{}' );
		};
		const openPanelFor = async ( name, ftype ) => {
			if ( await page.$( `[data-pf$=":${ name }"][data-ftype="${ ftype }"]` ) ) return;
			// The panel's fields load after the editor paints; a busy site
			// can take a while.
			await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 60000 } );
			await page.click( '[data-side-door="panel:acf"]' );
			await page.waitForSelector( `[data-pf$=":${ name }"][data-ftype="${ ftype }"]`, { timeout: 30000 } );
		};
		const anchors = ( rows ) => ( rows || [] ).map( ( r ) => ( null == r.__idx ? 'new' : r.__idx ) ).join( ',' );

		if ( want( 'rows' ) && postId && rowsField ) {
			const F = rowsField.name;
			const sub = ( rowsField.subfields.find( ( s ) => 'text' === s.type ) || rowsField.subfields[ 0 ] ).name;
			const W = `[data-pf$=":${ F }"]`;
			const seed = ( names ) => api( 'wp/v2/posts/' + postId, { method: 'POST', body: JSON.stringify( { minn_acf: { [ F ]: names.map( ( n ) => ( { values: { [ sub ]: n } } ) ) } } ) } );
			const stored = async () => ( ( ( await api( 'wp/v2/posts/' + postId + '?context=edit&_fields=minn_acf' ) ).body || {} ).minn_acf || {} )[ F ] || [];
			const names = ( rows ) => rows.map( ( r ) => ( r.values || {} )[ sub ] ).join( ',' );
			const fresh = async ( list ) => {
				await seed( list );
				await openEditor( page, postId );
				await openPanelFor( F, 'rows' );
			};
			const click = async ( sel ) => { await page.click( `${ W } ${ sel }` ); await page.waitForTimeout( 250 ); };
			const type = ( i, v ) => page.fill( `${ W } [data-rowsub="${ i }:${ sub }"]`, v );
			const sentRows = ( body ) => ( ( body.minn_acf || {} )[ F ] ) || [];

			// Reorder, then type in a moved row while that save is in flight.
			await fresh( [ 'A', 'B', 'C' ] );
			await click( '[data-rmv="2:-1"]' );
			await click( '[data-rmv="1:-1"]' );
			let held = await heldSave( () => type( 1, 'A2' ) );
			let body = await nextSaveBody();
			t.check( 'NI-2: after a reorder save, rows typed in while it was in flight are named by the stored order', '0,1,2' === anchors( sentRows( body ) ),
				JSON.stringify( sentRows( body ).map( ( r ) => [ r.__idx, r.values[ sub ] ] ) ) + ' (held save ' + held.status + ' sent ' + anchors( sentRows( held.sent ) ) + ')' );
			t.check( 'NI-2: and the stored rows are the ones on screen', 'C,A2,B' === names( await stored() ), names( await stored() ) );

			// Reorder, then type and press Update again while that save is in
			// flight: the queued save goes once it lands, so its rows are
			// named by the order it stored.
			await fresh( [ 'A', 'B', 'C' ] );
			await click( '[data-rmv="2:-1"]' );
			await click( '[data-rmv="1:-1"]' );
			const posts = [];
			const onReq = ( r ) => { if ( 'POST' === r.method() && postUrl.test( r.url() ) ) posts.push( r ); };
			page.on( 'request', onReq );
			held = await heldSave( async () => {
				await type( 1, 'A2' );
				await page.keyboard.press( 'Meta+s' );
			} );
			for ( let i = 0; i < 150 && posts.length < 2; i++ ) await page.waitForTimeout( 200 );
			const queued = posts[ 1 ];
			if ( queued ) await queued.response().catch( () => null );
			page.off( 'request', onReq );
			await page.waitForTimeout( 700 );
			body = queued ? JSON.parse( queued.postData() || '{}' ) : {};
			t.check( 'NI-2: a save pressed while a reorder save is in flight names its rows by the order that save stored', '0,1,2' === anchors( sentRows( body ) ),
				JSON.stringify( sentRows( body ).map( ( r ) => [ r.__idx, r.values[ sub ] ] ) ) + ' (held save ' + held.status + ' sent ' + anchors( sentRows( held.sent ) ) + ', ' + posts.length + ' saves)' );
			t.check( 'NI-2: and the stored rows are C, A2, B', 'C,A2,B' === names( await stored() ), names( await stored() ) );

			// Delete, then type while that save is in flight.
			await fresh( [ 'A', 'B', 'C' ] );
			await click( '[data-rdel="1"]' );
			held = await heldSave( () => type( 0, 'A2' ) );
			body = await nextSaveBody();
			t.check( 'NI-2: after a delete save, the row left behind the gap is named by its new stored position', '0,1' === anchors( sentRows( body ) ),
				JSON.stringify( sentRows( body ).map( ( r ) => [ r.__idx, r.values[ sub ] ] ) ) + ' (held save ' + held.status + ' sent ' + anchors( sentRows( held.sent ) ) + ')' );
			t.check( 'NI-2: and the stored rows are A2, C', 'A2,C' === names( await stored() ), names( await stored() ) );

			// Add a row at the top, then type in a shifted row while in flight.
			await fresh( [ 'A', 'B' ] );
			await click( '[data-radd]' );
			await page.waitForSelector( `${ W } [data-rowsub="2:${ sub }"]`, { timeout: 5000 } );
			await type( 2, 'D' );
			await click( '[data-rmv="2:-1"]' );
			await click( '[data-rmv="1:-1"]' );
			held = await heldSave( () => type( 1, 'A2' ) );
			body = await nextSaveBody();
			t.check( 'NI-2: after an add save, the shifted rows are named by the stored order and the added row stays new', 'new,1,2' === anchors( sentRows( body ) ),
				JSON.stringify( sentRows( body ).map( ( r ) => [ r.__idx, r.values[ sub ] ] ) ) + ' (held save ' + held.status + ' sent ' + anchors( sentRows( held.sent ) ) + ')' );
			t.check( 'NI-2: and the stored rows are D, A2, B', 'D,A2,B' === names( await stored() ), names( await stored() ) );

			// Reorder, type in flight, and close the dialog before it lands:
			// the off-screen branch re-anchors what the panel holds.
			await fresh( [ 'A', 'B', 'C' ] );
			await click( '[data-rmv="2:-1"]' );
			await click( '[data-rmv="1:-1"]' );
			held = await heldSave( async () => {
				await type( 1, 'A3' );
				await page.keyboard.press( 'Escape' );
				await page.waitForSelector( W, { state: 'detached', timeout: 5000 } ).catch( () => null );
			} );
			await openPanelFor( F, 'rows' );
			await type( 2, 'B3' );
			body = await nextSaveBody();
			t.check( 'NI-2: with the dialog closed while the save was in flight, the rows are named by the stored order', '0,1,2' === anchors( sentRows( body ) ),
				JSON.stringify( sentRows( body ).map( ( r ) => [ r.__idx, r.values[ sub ] ] ) ) + ' (held save ' + held.status + ' sent ' + anchors( sentRows( held.sent ) ) + ')' );
			t.check( 'NI-2: and the stored rows are C, A3, B3', 'C,A3,B3' === names( await stored() ), names( await stored() ) );

			// Controls: no structural change in the save (value edits on both
			// sides), and 6234c007's case (a value save, a delete in flight).
			await fresh( [ 'A', 'B', 'C' ] );
			await type( 0, 'A2' );
			await heldSave( () => type( 1, 'B2' ) );
			body = await nextSaveBody();
			t.check( 'NI-2 control: a value save with a value edit in flight keeps 0,1,2', '0,1,2' === anchors( sentRows( body ) ), anchors( sentRows( body ) ) );
			t.check( 'NI-2 control: and both edits are stored', 'A2,B2,C' === names( await stored() ), names( await stored() ) );
			await fresh( [ 'A', 'B', 'C' ] );
			await type( 0, 'A2' );
			await heldSave( () => click( '[data-rdel="1"]' ) );
			body = await nextSaveBody();
			t.check( 'NI-2 control: a delete made while a value save is in flight keeps the stored anchors 0,2', '0,2' === anchors( sentRows( body ) ), anchors( sentRows( body ) ) );
			t.check( 'NI-2 control: and the rows left are A2, C', 'A2,C' === names( await stored() ), names( await stored() ) );
		} else if ( want( 'rows' ) ) {
			console.log( 'SKIP  NI-2 rows: no repeater on posts (ACF Pro)' );
		}

		if ( want( 'flex' ) && postId && flexField ) {
			const F = flexField.name;
			const lays = Object.keys( flexField.layouts );
			const withLock = lays.find( ( n ) => flexField.layouts[ n ].subLocked > 0 ) || lays[ 0 ];
			const other = lays.find( ( n ) => n !== withLock ) || lays[ 0 ];
			const textOf = ( n ) => ( flexField.layouts[ n ].subfields.find( ( s ) => 'text' === s.type ) || flexField.layouts[ n ].subfields[ 0 ] ).name;
			const lockText = textOf( withLock );
			const otherText = textOf( other );
			const W = `[data-pf$=":${ F }"]`;
			const acfRows = async () => ( ( ( await api( 'wp/v2/posts/' + postId + '?context=edit&_fields=acf' ) ).body || {} ).acf || {} )[ F ] || [];
			// ACF's own field seeds the sub Minn never renders.
			await api( 'wp/v2/posts/' + postId, { method: 'POST', body: JSON.stringify( { acf: { [ F ]: [ { acf_fc_layout: withLock, [ lockText ]: 'X' }, { acf_fc_layout: other, [ otherText ]: 'Y' } ] } } ) } );
			const mapped = flexField.layouts[ withLock ].subfields.map( ( s ) => s.name );
			const lockedKey = Object.keys( ( await acfRows() )[ 0 ] || {} ).find( ( k ) => 'acf_fc_layout' !== k && -1 === mapped.indexOf( k ) );
			if ( lockedKey ) {
				await api( 'wp/v2/posts/' + postId, { method: 'POST', body: JSON.stringify( { acf: { [ F ]: [ { acf_fc_layout: withLock, [ lockText ]: 'X', [ lockedKey ]: '<p>kept note</p>' }, { acf_fc_layout: other, [ otherText ]: 'Y' } ] } } ) } );
			}
			await openEditor( page, postId );
			await openPanelFor( F, 'flex' );
			await page.click( `${ W } [data-rmv="1:-1"]` );
			await page.waitForTimeout( 250 );
			await heldSave( async () => {
				await page.click( `${ W } button[data-rtoggle="1"]` );
				await page.waitForSelector( `${ W } [data-rowsub="1:${ lockText }"]`, { state: 'visible', timeout: 5000 } );
				await page.fill( `${ W } [data-rowsub="1:${ lockText }"]`, 'X2' );
			} );
			const body = await nextSaveBody();
			const sent = ( ( body.minn_acf || {} )[ F ] ) || [];
			t.check( 'NI-2: after a flex reorder save, a section typed in while it was in flight is named by the stored order', '0,1' === anchors( sent ),
				JSON.stringify( sent.map( ( r ) => [ r.__idx, r.__layout ] ) ) );
			const after = await acfRows();
			const shape = after.map( ( r ) => r.acf_fc_layout + ':' + ( r[ lockText ] || r[ otherText ] || '' ) ).join( ',' );
			t.check( 'NI-2: and the stored sections are the ones on screen, each keeping its own layout', `${ other }:Y,${ withLock }:X2` === shape, shape );
			if ( lockedKey ) {
				t.check( 'NI-2: and the sub Minn never renders stays on its own section', /kept note/.test( String( ( after[ 1 ] || {} )[ lockedKey ] || '' ) ), JSON.stringify( ( after[ 1 ] || {} )[ lockedKey ] ) );
			}
		} else if ( want( 'flex' ) ) {
			console.log( 'SKIP  NI-2 flex: no flexible-content field on posts (ACF Pro)' );
		}

		/* ===== NI-3 · the variation moved-stock check ===== */
		const hasWc = await page.evaluate( () => !! ( window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
		const attr = want( 'stock' ) && hasWc ? ( ( await api( 'wc/v3/products/attributes?_fields=id,name' ) ).body || [] )[ 0 ] : null;
		if ( want( 'stock' ) && attr ) {
			const made = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Delta variation stock ' + Date.now(), type: 'variable', status: 'publish', manage_stock: true, stock_quantity: 20 } ) } );
			productId = made.body && Number.isInteger( made.body.id ) && made.body.id > 1 ? made.body.id : null;
			const vids = {};
			if ( productId ) {
				await api( `wc/v3/products/${ productId }`, { method: 'PUT', body: JSON.stringify( { attributes: [ { id: attr.id, visible: true, variation: true, options: [ 'Shared', 'Unset', 'Counted' ] } ] } ) } );
				const mk = async ( opt, extra ) => {
					const r = await api( `wc/v3/products/${ productId }/variations`, { method: 'POST', body: JSON.stringify( Object.assign( { attributes: [ { id: attr.id, option: opt } ], regular_price: '10.00' }, extra ) ) } );
					return r.body && r.body.id;
				};
				vids.shared = await mk( 'Shared', { manage_stock: false } );
				vids.nullqty = await mk( 'Unset', { manage_stock: true, stock_quantity: 4 } );
				vids.counted = await mk( 'Counted', { manage_stock: true, stock_quantity: 10 } );
				// A tracked variation whose quantity is blank, as a CSV import
				// or a REST write can leave it.
				if ( Number.isInteger( vids.nullqty ) && vids.nullqty > 1 ) wp( 'eval', `update_post_meta( ${ vids.nullqty }, '_stock', '' ); wc_delete_product_transients( ${ vids.nullqty } );` );
			}
			const vread = async ( vid ) => {
				const b = ( await api( `wc/v3/products/${ productId }/variations/${ vid }?_fields=manage_stock,stock_quantity,stock_status` ) ).body || {};
				return { manage_stock: b.manage_stock, stock_quantity: b.stock_quantity, stock_status: b.stock_status };
			};
			const pre = productId ? { shared: await vread( vids.shared ), nullqty: await vread( vids.nullqty ) } : {};
			const ready = productId && 'parent' === pre.shared.manage_stock && true === pre.nullqty.manage_stock && null === pre.nullqty.stock_quantity && vids.counted;
			t.check( 'NI-3 fixture: one variation shares the parent stock, one tracks a null quantity', !! ready, JSON.stringify( pre ) );
			if ( ready ) {
				const openProduct = async () => {
					await page.goto( BASE + '/minn-admin/products/' + productId, { waitUntil: 'domcontentloaded' } );
					await page.waitForSelector( '[data-pvaropen]', { timeout: 25000 } );
				};
				const editVar = async ( label, fn ) => {
					const ok = await page.evaluate( ( l ) => {
						const row = [ ...document.querySelectorAll( '[data-pvaropen]' ) ].find( ( r ) => ( r.querySelector( '.minn-pvar-name' ) || {} ).textContent.indexOf( l ) !== -1 );
						if ( row ) row.click();
						return !! row;
					}, label );
					if ( ! ok ) throw new Error( 'no variation row ' + label );
					await page.waitForSelector( '#minn-var-dialog #minn-var-qty-row', { state: 'attached', timeout: 15000 } );
					await fn();
					await page.click( '#minn-var-dialog [data-var-done]' );
					await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
				};
				const save = async () => {
					await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
					const toast = page.waitForSelector( '.minn-toast', { timeout: 25000 } ).then( ( el ) => el.textContent() ).then( ( x ) => ( x || '' ).trim() ).catch( () => '' );
					await page.click( '#minn-product-save' );
					const text = await toast;
					await page.waitForFunction( () => {
						const b = document.querySelector( '#minn-product-save' );
						return ! b || ( ! b.disabled && /Save/.test( b.textContent ) );
					}, null, { timeout: 25000 } ).catch( () => null );
					await page.waitForTimeout( 600 );
					return text;
				};
				await openProduct();
				await editVar( 'Shared', async () => {
					await page.click( '#minn-var-dialog #minn-var-track' );
					await page.waitForSelector( '#minn-var-dialog #minn-var-qty', { state: 'visible', timeout: 5000 } );
					await page.fill( '#minn-var-dialog #minn-var-qty', '5' );
				} );
				await editVar( 'Unset', () => page.fill( '#minn-var-dialog #minn-var-qty', '7' ) );
				const said = await save();
				const s1 = await vread( vids.shared );
				const s2 = await vread( vids.nullqty );
				t.check( 'NI-3a: a variation switched from the parent stock to its own takes its quantity', true === s1.manage_stock && 5 === s1.stock_quantity, JSON.stringify( s1 ) );
				t.check( 'NI-3a: and is not left out of stock', 'instock' === s1.stock_status, String( s1.stock_status ) );
				t.check( 'NI-3b: a tracked variation with a null quantity takes a stock edit', 7 === s2.stock_quantity, JSON.stringify( s2 ) );
				t.check( 'NI-3: and the save reports no moved stock', ! /except the stock/.test( said ), said );

				// Controls: a quantity that moved while the page was open is
				// still refused; one that did not move still saves.
				await openProduct();
				await api( `wc/v3/products/${ productId }/variations/${ vids.counted }`, { method: 'PUT', body: JSON.stringify( { inventory_delta: -2 } ) } );
				await editVar( 'Counted', () => page.fill( '#minn-var-dialog #minn-var-qty', '12' ) );
				const said2 = await save();
				t.check( 'NI-3 control: a variation stock edit over a stock that moved is still refused (8 stays)', 8 === ( await vread( vids.counted ) ).stock_quantity, JSON.stringify( await vread( vids.counted ) ) );
				t.check( 'NI-3 control: and the save says why', /stock of 1 variation/.test( said2 ), said2 );
				await openProduct();
				await editVar( 'Counted', () => page.fill( '#minn-var-dialog #minn-var-qty', '9' ) );
				await save();
				t.check( 'NI-3 control: an unmoved variation stock edit saves', 9 === ( await vread( vids.counted ) ).stock_quantity, JSON.stringify( await vread( vids.counted ) ) );
			}
		} else if ( want( 'stock' ) ) {
			console.log( 'SKIP  NI-3: WooCommerce or a global attribute unavailable' );
		}

		/* ===== H1 · item ids in action and page routes ===== */
		const surfaces = await page.evaluate( () => ( window.MINN.surfaces || [] ).map( ( s ) => s.id ) );
		if ( want( 'ids' ) && surfaces.includes( 'limit-login-attempts' ) ) {
			const seen = [];
			const item = ( id, who ) => ( { id, message: 'Locked out: ' + who, who, ip: '192.0.2.10', attempts: 3, gateway: 'Login form', status: 'locked', locked: true, date: new Date().toISOString().replace( /\.\d+Z$/, 'Z' ) } );
			const items = [ item( '../../../../h1probe?x=1#', 'h1-slash' ), item( '..', 'h1-dot' ), item( '0123456789abcdef0123456789abcdef', 'h1-control' ) ];
			const listRoute = ( u ) => u.pathname === restPath + 'minn-admin/v1/llar/log' || ( /[?&]rest_route=\/minn-admin\/v1\/llar\/log(&|$)/.test( u.search ) );
			const probe = ( u ) => u.pathname.indexOf( restPath ) === 0 && ! listRoute( u ) && ( /h1probe/.test( u.href ) || /\/llar\/(log\/.+|unlock)/.test( u.pathname ) );
			const onList = ( route ) => route.fulfill( { status: 200, contentType: 'application/json', headers: { 'X-WP-Total': '3', 'X-WP-TotalPages': '1' }, body: JSON.stringify( { items, total: 3 } ) } );
			const onProbe = ( route ) => {
				const u = new URL( route.request().url() );
				seen.push( route.request().method() + ' ' + u.pathname + u.search );
				return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { message: 'probe' } ) } );
			};
			await page.route( listRoute, onList );
			await page.route( probe, onProbe );
			try {
				await page.goto( BASE + '/minn-admin/limit-login-attempts', { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '.minn-table-row', { timeout: 25000 } );
				const unlock = async ( who ) => {
					seen.length = 0;
					await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
					const row = page.locator( '.minn-table-row', { hasText: who } ).first();
					await row.click( { button: 'right' } );
					await page.locator( '.minn-ctx-menu button', { hasText: 'Unlock IP' } ).click( { timeout: 10000 } );
					await page.waitForSelector( '.minn-toast', { timeout: 15000 } ).catch( () => {} );
					await page.waitForTimeout( 500 );
					const toast = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-toast' ) ].map( ( e ) => e.textContent.trim() ).join( ' | ' ) );
					await page.waitForSelector( '.minn-table-row', { timeout: 25000 } ).catch( () => {} );
					return { sent: seen.slice(), toast };
				};
				let r = await unlock( 'h1-slash' );
				const inSegment = ( s ) => /\/llar\/log\/[^/]+\/unlock$/.test( s.replace( /^POST /, '' ) );
				t.check( 'H1: an action on an id holding ../ ? # stays on its own route', 1 === r.sent.length && inSegment( r.sent[ 0 ] ) && ! /\/h1probe/.test( r.sent[ 0 ].split( '/llar/log/' )[ 0 ] ), JSON.stringify( r.sent ) );
				r = await unlock( 'h1-dot' );
				t.check( 'H1: an action on a dot-segment id sends nothing', 0 === r.sent.length && /Refused an item id/.test( r.toast ), JSON.stringify( r ) );
				r = await unlock( 'h1-control' );
				t.check( 'H1 control: an action on an ordinary id is sent to its route', 1 === r.sent.length && /\/llar\/log\/0123456789abcdef0123456789abcdef\/unlock$/.test( r.sent[ 0 ] ), JSON.stringify( r.sent ) );
			} finally {
				await page.unroute( listRoute, onList ).catch( () => {} );
				await page.unroute( probe, onProbe ).catch( () => {} );
			}
		} else if ( want( 'ids' ) ) {
			console.log( 'SKIP  H1 actions: Limit Login Attempts surface not present' );
		}

		if ( want( 'ids' ) && surfaces.includes( 'gravity-forms' ) ) {
			const seen = [];
			const probe = ( u ) => u.pathname.indexOf( restPath ) === 0 && ( /h1probe/.test( u.href ) || u.pathname === restPath + 'minn-admin/v1/gf/full' );
			const onProbe = ( route ) => {
				const u = new URL( route.request().url() );
				seen.push( route.request().method() + ' ' + u.pathname + u.search );
				return route.fulfill( { status: 404, contentType: 'application/json', body: JSON.stringify( { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } } ) } );
			};
			await page.route( probe, onProbe );
			try {
				// The app's own address carries the id, decoded once by the
				// router and once more for the id: ../../../../h1probe?x=1#.
				// Reached as an in-app navigation (this server answers a %25
				// path with an empty 404, other servers serve the app).
				const navTo = ( p ) => page.evaluate( ( to ) => {
					history.pushState( null, '', to );
					window.dispatchEvent( new PopStateEvent( 'popstate' ) );
				}, p );
				await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
				await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 25000 } );
				await page.waitForTimeout( 1500 );
				await navTo( '/minn-admin/gravity-forms/notification/..%252F..%252F..%252F..%252Fh1probe%253Fx%253D1%2523' );
				await page.waitForSelector( '#minn-view .minn-empty, #minn-view .minn-gfn', { timeout: 25000 } ).catch( () => {} );
				await page.waitForTimeout( 800 );
				t.check( 'H1: a notification id from the address stays inside its route', seen.length > 0 && seen.every( ( s ) => /\/gf\/notifications\/[^/]*h1probe[^/]*\/full$/.test( s ) ), JSON.stringify( seen ) );
				seen.length = 0;
				await navTo( '/minn-admin/gravity-forms/notification/..%2Fx' );
				await page.waitForSelector( '#minn-view .minn-empty', { timeout: 25000 } ).catch( () => {} );
				await page.waitForTimeout( 800 );
				const shown = await page.evaluate( () => ( document.querySelector( '#minn-view .minn-empty' ) || {} ).textContent || '' );
				t.check( 'H1: a dot-segment notification id from the address is refused before any request', 0 === seen.length && /Refused an item id/.test( shown ), JSON.stringify( { seen, shown } ) );
			} finally {
				await page.unroute( probe, onProbe ).catch( () => {} );
			}
			const gfn = ( ( await api( 'minn-admin/v1/gf/notifications' ) ).body || {} ).items || [];
			const real = gfn.find( ( n ) => /^\d+:[A-Za-z0-9_.-]+$/.test( String( n.id ) ) );
			if ( real ) {
				const loaded = page.waitForResponse( ( res ) => /\/gf\/notifications\/.+\/full/.test( res.url() ), { timeout: 25000 } );
				await page.goto( BASE + '/minn-admin/gravity-forms/notification/' + real.id, { waitUntil: 'domcontentloaded' } );
				const res = await loaded.catch( () => null );
				t.check( 'H1 control: a form:id notification keeps its colon and loads', !! res && 200 === res.status() && res.url().indexOf( '/' + real.id + '/full' ) !== -1, res ? res.status() + ' ' + res.url() : 'no response' );
			}
		}

		/* ===== H3 · WP Migrate through apiRes ===== */
		const mig = await page.evaluate( () => window.MINN.wpMigrate || null );
		if ( want( 'migrate' ) && mig ) {
			t.check( 'H3: the boot payload no longer carries an admin-ajax URL of its own', ! ( 'ajax' in mig ) && !! mig.nonce && 'mdb-api/v1' === mig.restBase, JSON.stringify( Object.keys( mig ) ) );
			const remote = { url: 'https://h3-remote.invalid', site_url: 'https://h3-remote.invalid', path: '/srv/h3-remote', prefix: 'wp_', tables: [ 'wp_options' ], site_details: { site_url: 'https://h3-remote.invalid' } };
			const calls = [];
			let refreshed = false;
			// Every WP Migrate route is answered here: nothing reaches their
			// handlers, and nothing is migrated.
			const onMdb = ( route ) => {
				const req = route.request();
				const u = new URL( req.url() );
				const name = u.pathname.split( '/' ).pop() || ( u.searchParams.get( 'rest_route' ) || '' ).split( '/' ).pop();
				calls.push( name + ' ' + ( req.headers()[ 'x-wp-nonce' ] || '' ) );
				if ( 'h3-stale' === req.headers()[ 'x-wp-nonce' ] ) {
					return route.fulfill( { status: 403, contentType: 'application/json', body: JSON.stringify( { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } } ) } );
				}
				if ( 'verify-connection' === name ) {
					refreshed = true;
					return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: remote } ) } );
				}
				if ( 'initiate-migration' === name ) return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: { migration_state_id: 'h3probe' } } ) } );
				if ( 'finalize-migration' === name || 'cancel-migration' === name ) return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
				return route.abort();
			};
			const isMdb = ( u ) => /mdb-api/.test( u.pathname + u.search );
			const ajaxPosts = [];
			const onAjax = ( route ) => {
				const req = route.request();
				if ( 'POST' !== req.method() ) return route.continue();
				const body = req.postData() || '';
				if ( /wpmdb_/.test( body ) ) {
					ajaxPosts.push( { url: req.url(), table: /name="action"\r?\n\r?\nwpmdb_migrate_table/.test( body ), nonce: body.indexOf( mig.nonce ) !== -1 } );
					return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: { current_row: -1, primary_keys: '' } } ) } );
				}
				return route.abort();
			};
			const offsite = [];
			const isOff = ( u ) => 'h3-probe.invalid' === u.hostname;
			const onOff = ( route ) => { offsite.push( route.request().url() ); return route.abort(); };
			await page.route( isMdb, onMdb );
			await page.route( '**/admin-ajax.php**', onAjax );
			await page.route( isOff, onOff );
			try {
				await page.goto( BASE + '/minn-admin/migrate', { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '.minn-mig', { timeout: 25000 } );
				const connect = async () => {
					await page.fill( '.minn-mig-conn-in', 'https://h3-remote.invalid\nh3probekeyh3probekey' );
					await page.click( '[data-migconnect]' );
					await page.waitForFunction( () => document.querySelector( '.minn-note.err' ) || document.querySelector( '[data-migrun]' ) || document.querySelector( '[data-migdisconnect]' ), null, { timeout: 20000 } ).catch( () => {} );
					await page.waitForTimeout( 400 );
					return page.evaluate( () => ( document.querySelector( '.minn-note.err' ) || {} ).textContent || '' );
				};

				// A route naming another host is refused before the nonce leaves.
				await page.evaluate( () => { window.MINN.wpMigrate.restBase = 'https://h3-probe.invalid/mdb-api/v1'; } );
				let err = await connect();
				await page.evaluate( ( b ) => { window.MINN.wpMigrate.restBase = b; }, mig.restBase );
				t.check( 'H3: a WP Migrate route naming another host is refused, nonce unsent', 0 === offsite.length && /cross-origin/i.test( err ), JSON.stringify( { offsite, err: err.trim().slice( 0, 80 ) } ) );

				// A stale nonce is refreshed and the call retried, as every apiRes call is.
				const liveNonce = await page.evaluate( () => window.MINN.nonce );
				await page.evaluate( () => { window.MINN.wpMigrate.licensed = true; window.MINN.nonce = 'h3-stale'; } );
				calls.length = 0;
				err = await connect();
				t.check( 'H3: a stale nonce on a WP Migrate call is refreshed and the call goes through', refreshed && ! err.trim() && !! ( await page.$( '[data-migrun]' ) ), JSON.stringify( { calls, err: err.trim().slice( 0, 80 ) } ) );

				// Control: the table loop, mocked end to end, posts to Minn's
				// own admin-ajax URL with their nonce.
				if ( ! await page.$( '[data-migrun]' ) ) {
					await page.evaluate( ( n ) => { if ( 'h3-stale' === window.MINN.nonce ) window.MINN.nonce = n; }, liveNonce );
					await connect();
				}
				const run = await page.$( '[data-migrun]:not([disabled])' );
				if ( run ) {
					await run.click();
					await page.waitForFunction( () => /Finished|Stopped/.test( ( document.querySelector( '.minn-mig-phase' ) || {} ).textContent || '' ), null, { timeout: 60000 } ).catch( () => {} );
					const phase = await page.evaluate( () => ( document.querySelector( '.minn-mig-phase' ) || {} ).textContent || '' );
					const ajaxUrl = await page.evaluate( () => window.MINN.ajaxUrl );
					t.check( 'H3 control: the table loop posts to Minn\'s admin-ajax URL with their nonce, and the mocked run finishes',
						ajaxPosts.length > 0 && ajaxPosts.every( ( a ) => a.url === ajaxUrl && a.table && a.nonce ) && /Finished/.test( phase ),
						JSON.stringify( { posts: ajaxPosts.length, first: ajaxPosts[ 0 ], phase: phase.trim().slice( 0, 60 ) } ) );
				} else {
					t.check( 'H3 control: the run button is offered once connected', false, 'no enabled [data-migrun]' );
				}
			} finally {
				await page.unroute( isMdb, onMdb ).catch( () => {} );
				await page.unroute( '**/admin-ajax.php**', onAjax ).catch( () => {} );
				await page.unroute( isOff, onOff ).catch( () => {} );
			}
		} else if ( want( 'migrate' ) ) {
			console.log( 'SKIP  H3: WP Migrate not available' );
		}
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		if ( productId ) await api( `wc/v3/products/${ productId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
