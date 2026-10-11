/**
 * Island previews and vendor dynamic content.
 *
 * /render-blocks renders block markup the caller wrote. GenerateBlocks
 * dynamic tags and Otter's <o-dynamic> elements read a post by an id the
 * markup names, and neither vendor checks read access at render time: both
 * rely on a save gate and on treating core's block renderer as untrusted
 * for users who can't author dynamic data. So an Author asking for a
 * preview must not get the title or body of a private post written by
 * someone else, while an administrator's previews still resolve.
 *
 * Each vendor's half SKIPs when that plugin is inactive.
 */
const { launch, login, loginAs, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'render-blocks-dynamic' );
	await login( page );

	const api = ( p, path, opts ) => p.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );
	const active = async ( slug ) => {
		const r = await api( page, 'wp/v2/plugins?search=' + encodeURIComponent( slug ) );
		return Array.isArray( r.body ) && r.body.some( ( pl ) => pl.plugin.split( '/' )[ 0 ] === slug && pl.status === 'active' );
	};
	const render = async ( p, blocks ) => {
		const r = await api( p, 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks, post: 0 } ) } );
		return { status: r.status, html: ( ( r.body && r.body.rendered ) || [] ).join( '\n' ) };
	};

	const stamp = Date.now().toString( 36 );
	const title = `Dynamic probe ${ stamp }`;
	const secret = `body-secret-${ stamp }`;
	let id = null;
	let author = null;
	try {
		id = await createPost( page, { title, content: `<!-- wp:paragraph --><p>${ secret }</p><!-- /wp:paragraph -->`, status: 'private' } );
		t.check( 'private post by the administrator created', !! id, String( id ) );
		author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );

		const cases = [];
		if ( await active( 'generateblocks' ) ) {
			cases.push( {
				name: 'GenerateBlocks title tag',
				markup: `<!-- wp:generateblocks/text {"uniqueId":"mrbd${ stamp.slice( -4 ) }","tagName":"p"} --><p class="gb-text">{{post_title id:${ id }}}</p><!-- /wp:generateblocks/text -->`,
				leak: title,
			} );
			cases.push( {
				name: 'GenerateBlocks excerpt tag',
				markup: `<!-- wp:generateblocks/text {"uniqueId":"mrbe${ stamp.slice( -4 ) }","tagName":"p"} --><p class="gb-text">{{post_excerpt id:${ id }|length:200}}</p><!-- /wp:generateblocks/text -->`,
				leak: secret,
			} );
		} else {
			console.log( 'SKIP  GenerateBlocks inactive' );
		}
		if ( await active( 'otter-blocks' ) ) {
			cases.push( {
				name: 'Otter title element',
				markup: `<!-- wp:paragraph --><p><o-dynamic data-type="postTitle" data-context="${ id }">Post Title</o-dynamic></p><!-- /wp:paragraph -->`,
				leak: title,
			} );
			cases.push( {
				name: 'Otter content element',
				markup: `<!-- wp:paragraph --><p><o-dynamic data-type="postContent" data-context="${ id }">Post Content</o-dynamic></p><!-- /wp:paragraph -->`,
				leak: secret,
			} );
		} else {
			console.log( 'SKIP  Otter inactive' );
		}

		for ( const c of cases ) {
			const a = await render( author.page, [ c.markup ] );
			t.check( `${ c.name }: an Author's preview does not resolve another user's private post`, a.status === 200 && ! a.html.includes( c.leak ), `${ a.status } ${ a.html.slice( 0, 200 ) }` );
			// Control: the fix must not blank previews for a user the vendor
			// trusts with dynamic data.
			const m = await render( page, [ c.markup ] );
			t.check( `${ c.name }: an administrator's preview still resolves`, m.status === 200 && m.html.includes( c.leak ), `${ m.status } ${ m.html.slice( 0, 200 ) }` );
		}
	} finally {
		if ( author ) await author.ctx.close().catch( () => {} );
		if ( id ) await deletePost( page, id ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
