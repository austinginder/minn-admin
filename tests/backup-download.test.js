/**
 * Backup rows can be downloaded. Every provider with archives on this
 * server (UpdraftPlus, WPvivid, BackWPup, Duplicator, All-in-One WP
 * Migration) carries a Download row action; the shared door at
 * admin-post.php streams one file, lists a set of several, and refuses a
 * link without its nonce. The row menu (⋯ and right-click) shows the
 * actions; the files come back through the browser's own cookie session.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { apiFor } = require( './_jet-common' );
( async () => {
	const t = reporter( 'backup-download' );
	const { browser, page, errors, ctx } = await launch();
	await login( page );
	const api = apiFor( page );
	// Mirrors the app's surfaceFillHref: a whole-URL placeholder passes
	// through untouched, inline ones are encoded.
	const fill = ( href, item ) => {
		const whole = /^\{(\w+)\}$/.exec( String( href ) );
		if ( whole && /^https?:\/\//i.test( String( item[ whole[ 1 ] ] ?? '' ) ) ) return String( item[ whole[ 1 ] ] );
		return String( href ).replace( /\{(\w+)\}/g, ( _, k ) => encodeURIComponent( item[ k ] ?? '' ) );
	};
	const visible = ( a, item ) => ! a.when || String( item[ a.when.key ] ?? '' ) === String( a.when.equals );
	try {
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.surfaces, null, { timeout: 20000 } );
		const surfaces = await page.evaluate( () => window.MINN.surfaces );
		const byId = ( id ) => ( Array.isArray( surfaces ) ? surfaces.find( ( s ) => s.id === id ) : surfaces[ id ] ) || null;

		for ( const [ id, expect ] of [ [ 'updraftplus', /Download/ ], [ 'wpvivid', /Download/ ], [ 'backwpup', /Download/ ], [ 'duplicator', /Download archive/ ], [ 'ai1wm', /Download/ ] ] ) {
			const s = byId( id );
			const actions = ( s && s.collection && s.collection.actions ) || [];
			t.check( `${ id }: the row actions include a Download`, actions.some( ( a ) => a.href && expect.test( a.label ) ), JSON.stringify( actions.map( ( a ) => a.label ) ) );
			const list = await api( s.collection.route + ( s.collection.route.includes( '?' ) ? '&' : '?' ) + 'per_page=5' );
			const items = ( list.body && list.body.items ) || [];
			const item = items.find( ( i ) => actions.some( ( a ) => a.href && expect.test( a.label ) && visible( a, i ) ) );
			if ( ! item ) { t.check( `${ id }: a row with a Download action exists`, false, JSON.stringify( items.slice( 0, 2 ) ) ); continue; }
			for ( const a of actions.filter( ( x ) => x.href && /Download/.test( x.label ) && visible( x, item ) ) ) {
				const href = fill( a.href, item );
				const r = await ctx.request.get( href, { maxRedirects: 0 } );
				const disp = r.headers()[ 'content-disposition' ] || '';
				const type = r.headers()[ 'content-type' ] || '';
				const body = await r.body();
				// A provider whose folder is web-readable (All-in-One WP
				// Migration) links the file itself: a plain 200 with bytes.
				const direct = ! /admin-post\.php/.test( href );
				const ok = r.status() === 200 && ( /attachment/.test( disp ) || ( /text\/html/.test( type ) && /several files/.test( body.toString() ) ) || direct ) && body.length > 0;
				t.check( `${ id }: "${ a.label }" answers a file (or a file list) over the cookie session`, ok, `${ r.status() } ${ type } ${ disp } ${ body.length }b` );
				if ( /several files/.test( body.toString() ) ) {
					const m = /href="([^"]+part=[^"]+)"/.exec( body.toString().replace( /&amp;/g, '&' ) );
					const r2 = m ? await ctx.request.get( m[ 1 ] ) : null;
					t.check( `${ id }: a part link from the list streams its file`, !! r2 && r2.status() === 200 && /attachment/.test( r2.headers()[ 'content-disposition' ] || '' ), r2 ? String( r2.status() ) : 'no part link' );
				}
			}
		}

		/* ===== The door refuses a link without its nonce ===== */
		const s = byId( 'updraftplus' );
		const a = s.collection.actions.find( ( x ) => x.href && /^Download$/.test( x.label ) );
		const bare = fill( a.href, { id: 1 } ).replace( /[&?]_wpnonce=[^&]*/, '' );
		const r = await ctx.request.get( bare, { maxRedirects: 0 } );
		t.check( 'a download link without its nonce is refused', r.status() === 403, String( r.status() ) );

		/* ===== The row menu shows them ===== */
		await page.goto( `${ BASE }/minn-admin/updraftplus`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view .minn-table-row[data-sitem]', { timeout: 30000 } );
		await page.click( '#minn-view .minn-table-row[data-sitem]', { button: 'right' } );
		await page.waitForTimeout( 500 );
		const menu = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu a, .minn-ctx-menu button, .minn-ctx-menu [role="menuitem"]' ) ].map( ( e ) => e.textContent.trim() ) );
		t.check( 'right-clicking an UpdraftPlus row lists Download, Download database and Delete set', menu.some( ( m ) => /^Download$/.test( m ) ) && menu.some( ( m ) => /Download database/.test( m ) ) && menu.some( ( m ) => /Delete set/.test( m ) ), JSON.stringify( menu ) );
		const dlHref = await page.evaluate( () => { const a = [ ...document.querySelectorAll( '.minn-ctx-menu a' ) ].find( ( x ) => /Download database/.test( x.textContent ) ); return a ? a.getAttribute( 'href' ) : ''; } );
		t.check( 'the menu entry is a real link to the download door', /admin-post\.php\?/.test( dlHref ) && /part=db/.test( dlHref ), dlHref );
		await page.keyboard.press( 'Escape' );
	} catch ( e ) {
		t.check( 'the suite ran to its end without an exception', false, String( e && e.message || e ) );
	} finally {
		await t.done( browser, errors );
	}
} )();
