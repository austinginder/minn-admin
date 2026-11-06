/**
 * Container queries in a block preview. The scoper rebuilds a theme's sheet
 * rule by rule, so a group rule it doesn't name is lost: a theme laying out
 * its sections with @container collapsed to its narrow layout inside Minn,
 * the one place those queries were meant to help. The rules arrive through
 * the editor-styles response here, so no fixture is involved.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const CSS = `
.acme-box { container-type: inline-size; }
@container (min-width: 200px) { .acme-inner { color: rgb(1, 2, 3); } }
@container (min-width: 99999px) { .acme-inner { background-color: rgb(9, 9, 9); } }
@container acme-named (min-width: 1px) { .acme-named-inner { color: rgb(4, 5, 6); } }
@media (min-width: 1px) { @container (min-width: 200px) { .acme-nested { color: rgb(7, 8, 9); } } }
@container (min-width: 200px) { body .acme-bodied { color: rgb(1, 1, 1); } }
`;
const BLOCK = '<!-- wp:acme/plain -->\n<div class="acme-box"><div class="acme-inner">inner</div><div class="acme-nested">nested</div><div class="acme-bodied">bodied</div></div>\n<!-- /wp:acme/plain -->';

( async () => {
	const t = reporter( 'preview-container-queries' );
	const { browser, page, errors } = await launch();
	await login( page );

	await page.route( '**/minn-admin/v1/editor-styles**', async ( route ) => {
		const res = await route.fetch();
		const body = await res.json();
		body.inline = ( body.inline || '' ) + CSS;
		await route.fulfill( { response: res, json: body } );
	} );

	const id = await createPost( page, { title: 'Container query preview', content: BLOCK } );
	try {
		await openEditor( page, id );
		await page.waitForSelector( '#minn-frontend-css', { state: 'attached', timeout: 30000 } );
		await page.waitForSelector( '.minn-island-preview .acme-inner', { timeout: 30000 } );
		await page.waitForTimeout( 800 );

		const scoped = await page.$eval( '#minn-frontend-css', ( s ) => s.textContent );
		t.check( 'the query survives scoping', /@container \(min-width: 200px\)/.test( scoped ), '' );
		t.check( 'its selector is scoped to the preview', /\.minn-island-preview \.acme-inner/.test( scoped ), '' );
		t.check( 'a named container keeps its name', /@container acme-named \(min-width: 1px\)/.test( scoped ), '' );
		t.check( 'body inside a query maps to the preview', /\.minn-island-preview \.acme-bodied/.test( scoped ) && ! /[^-]body \.acme-bodied/.test( scoped ), '' );

		const color = ( sel ) => page.$eval( '.minn-island-preview ' + sel, ( el ) => getComputedStyle( el ).color );
		t.check( 'a query that matches applies', await color( '.acme-inner' ) === 'rgb(1, 2, 3)', await color( '.acme-inner' ) );
		const bg = await page.$eval( '.minn-island-preview .acme-inner', ( el ) => getComputedStyle( el ).backgroundColor );
		t.check( 'a query that doesn\'t match stays off', bg !== 'rgb(9, 9, 9)', bg );
		t.check( 'a query inside @media applies', await color( '.acme-nested' ) === 'rgb(7, 8, 9)', await color( '.acme-nested' ) );

	} finally {
		await deletePost( page, id );
		await t.done( browser, errors );
	}
} )();
