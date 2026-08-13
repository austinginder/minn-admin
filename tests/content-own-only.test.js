/**
 * Content counts agree with the rows for a user who edits only their own
 * posts (GH #17). wp/v2/posts?context=edit drops other authors' rows from the
 * body but still counts them in X-WP-Total, so an author used to read
 * "16 items" and a sidebar badge of 16 over a list holding two of their own.
 * The client now scopes those requests to author=<me>, so header and body
 * describe the same set. An editor, who may edit others' posts, still sees
 * everything.
 */
const { BASE, launch, login, loginAs, createPost, deletePost, reporter } = require( './helpers' );

const AUTHOR = { user: 'minn-author', pass: 'minn-author-pass-1' };
const EDITOR = { user: 'minn-editor', pass: 'minn-editor-pass-1' };

// Read the three numbers the user actually sees: the sidebar badge, the
// toolbar meta count and the rows on screen.
async function contentCounts( page ) {
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction(
		() => document.querySelector( '.minn-table-row, .minn-empty' ) !== null,
		null,
		{ timeout: 20000 }
	);
	await page.waitForTimeout( 600 );
	return page.evaluate( () => {
		const badge = document.querySelector( '#minn-content-count' );
		const meta = document.querySelector( '.minn-toolbar-meta' );
		const num = ( el ) => {
			const m = ( el && el.textContent || '' ).match( /[\d.]+k?/ );
			if ( ! m ) return null;
			return m[ 0 ].endsWith( 'k' ) ? Math.round( parseFloat( m[ 0 ] ) * 1000 ) : parseInt( m[ 0 ], 10 );
		};
		return {
			badge: badge && ! badge.hidden ? num( badge ) : 0,
			meta: num( meta ),
			rows: document.querySelectorAll( '.minn-table-row[data-id]' ).length,
		};
	} );
}

( async () => {
	const t = reporter( 'content-own-only' );
	const { browser, page, errors } = await launch();
	await login( page );

	const made = [];
	let authorId = 0;
	try {
		authorId = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/users?context=edit&search=minn-author&per_page=5', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			const u = ( j || [] ).find( ( x ) => x.slug === 'minn-author' || x.username === 'minn-author' );
			return u ? u.id : 0;
		} );
		t.check( 'found the minn-author account', authorId > 0, 'id ' + authorId );

		// Two owned by the author, one by admin: the author's list must show
		// exactly the two, and must not count the third.
		made.push( await createPost( page, { title: 'Own only A', status: 'draft', author: authorId } ) );
		made.push( await createPost( page, { title: 'Own only B', status: 'publish', author: authorId } ) );
		made.push( await createPost( page, { title: 'Own only other', status: 'publish' } ) );
		t.check( 'fixtures created', made.every( Boolean ), made.join( ',' ) );

		/* ===== The author: counts describe the rows ===== */
		const a = await loginAs( browser, AUTHOR.user, AUTHOR.pass );
		const ac = await contentCounts( a.page );
		t.check( 'author sees their own posts', ac.rows >= 2, `${ ac.rows } rows` );
		t.check( 'toolbar count matches the rows', ac.meta === ac.rows, `meta ${ ac.meta } vs rows ${ ac.rows }` );
		t.check( 'sidebar badge matches the rows', ac.badge === ac.rows, `badge ${ ac.badge } vs rows ${ ac.rows }` );

		const titles = await a.page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row[data-id] .minn-row-title' ) ].map( ( el ) => el.textContent.trim() )
		);
		t.check( 'own posts are listed', titles.includes( 'Own only A' ) && titles.includes( 'Own only B' ) );
		t.check( "another author's post is not listed", ! titles.includes( 'Own only other' ) );
		await a.ctx.close();

		/* ===== The editor: unchanged, sees every author's work ===== */
		const e = await loginAs( browser, EDITOR.user, EDITOR.pass );
		const ec = await contentCounts( e.page );
		t.check( 'editor count still matches its rows', ec.meta === ec.rows, `meta ${ ec.meta } vs rows ${ ec.rows }` );
		t.check( 'editor sees more than the author does', ec.rows > ac.rows, `${ ec.rows } vs ${ ac.rows }` );
		const eTitles = await e.page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-table-row[data-id] .minn-row-title' ) ].map( ( el ) => el.textContent.trim() )
		);
		t.check( "editor still sees another author's post", eTitles.includes( 'Own only other' ) );
		await e.ctx.close();

		/* ===== The boot payload names the restricted types ===== */
		const own = await page.evaluate( () => Object.keys( window.MINN.ownOnly || {} ) );
		t.check( 'admin has no own-only restriction', own.length === 0, own.join( ',' ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const id of made ) await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
