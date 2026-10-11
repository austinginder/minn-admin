/**
 * A surface's edit dialog keeps changes made after it opened.
 *
 * The dialog opens on an item, then someone changes a different field
 * elsewhere (another tab, the plugin's own screen); the dialog saves its
 * own edit. The plugin wants the whole record, so the dialog sends every
 * field, and the fields the person left alone must go as stored now, not as
 * they were when the dialog opened. Two shapes: Code Snippets (the dialog
 * reads the item through a detail route) and Redirection (no detail route:
 * the dialog's item is a list row, and eight preserve keys ride along).
 *
 * SKIPs a plugin that is not active.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

function wpEval( php ) {
	const tmp = path.join( os.tmpdir(), 'minn-surface-conc-' + process.pid + '.php' );
	try {
		fs.writeFileSync( tmp, '<?php\n' + php );
		execSync( `wp --path=${ WP } eval-file ${ tmp }`, { stdio: 'ignore', timeout: 60000 } );
	} catch ( e ) { /* best-effort */ } finally {
		try { fs.unlinkSync( tmp ); } catch ( e ) { /* already gone */ }
	}
}

const rest = ( page, route, method = 'GET', body = null ) => page.evaluate( async ( { route, method, body } ) => {
	const r = await fetch( window.MINN.restUrl + route, {
		method,
		headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
		body: body ? JSON.stringify( body ) : undefined,
	} );
	let json = null;
	try { json = await r.json(); } catch ( e ) { /* empty */ }
	return { status: r.status, json };
}, { route, method, body } );

async function openRow( page, surface, text ) {
	await page.goto( `${ BASE }/minn-admin/${ surface }`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( ( t ) => ( document.querySelector( '.minn-table' )?.textContent || '' ).includes( t ), text, { timeout: 20000 } );
	await page.evaluate( ( t ) => {
		const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( t ) );
		if ( row ) row.click();
	}, text );
	await page.waitForSelector( '#minn-modal-overlay [data-editfield]', { timeout: 10000 } );
	await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay .minn-loading' ), null, { timeout: 10000 } );
}

async function saveDialog( page ) {
	await page.click( '#minn-surface-save' );
	await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), null, { timeout: 15000 } );
}

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'surface-edit-concurrency' );
	const uid = Date.now().toString( 36 );
	let snippetId = 0;
	let redirectId = 0;
	try {
		await login( page );

		/* ===== Code Snippets: detail route ===== */
		const snippets = await rest( page, 'code-snippets/v1/snippets?per_page=1' );
		if ( 200 !== snippets.status ) {
			console.log( 'SKIP  Code Snippets not active' );
		} else {
			const name = `Minn suite conc ${ uid }`;
			const made = await rest( page, 'code-snippets/v1/snippets', 'POST', {
				name,
				desc: 'as the dialog opened',
				// Inert and inactive: a crashed run must not leave live code.
				code: 'add_filter( "minn_test_snippet_noop", "__return_false" );',
				scope: 'global',
				active: false,
			} );
			snippetId = made.json && made.json.id;
			t.check( 'Code Snippets: fixture snippet created', !! snippetId, JSON.stringify( made ).slice( 0, 200 ) );
			if ( snippetId ) {
				await openRow( page, 'code-snippets', name );
				const elsewhere = await rest( page, `code-snippets/v1/snippets/${ snippetId }`, 'PUT', { desc: `changed elsewhere ${ uid }` } );
				t.check( 'Code Snippets: the other change landed', 200 === elsewhere.status && elsewhere.json.desc === `changed elsewhere ${ uid }`, String( elsewhere.status ) );
				await page.fill( '#minn-modal-overlay [data-editfield="name"]', `${ name } renamed` );
				await saveDialog( page );
				const after = ( await rest( page, `code-snippets/v1/snippets/${ snippetId }` ) ).json || {};
				t.check( 'Code Snippets: the dialog saved its own edit', after.name === `${ name } renamed`, after.name );
				t.check( 'Code Snippets: the description changed elsewhere is kept', after.desc === `changed elsewhere ${ uid }`, after.desc );
				t.check( 'Code Snippets: still inactive', false === after.active, String( after.active ) );
			}
		}

		/* ===== Redirection: list row, preserve keys ===== */
		const groups = await rest( page, 'redirection/v1/group?per_page=5' );
		const groupId = groups.json && groups.json.items && groups.json.items[0] && groups.json.items[0].id;
		if ( 200 !== groups.status || ! groupId ) {
			console.log( 'SKIP  Redirection not active' );
		} else {
			const source = `/minn-conc-${ uid }`;
			const made = await rest( page, 'redirection/v1/redirect', 'POST', {
				url: source,
				match_type: 'url',
				action_type: 'url',
				action_code: 301,
				action_data: { url: '/minn-conc-target-a' },
				group_id: groupId,
				regex: false,
			} );
			const row = await rest( page, `redirection/v1/redirect?filterBy[url]=${ encodeURIComponent( source ) }` );
			const item = row.json && ( row.json.items || [] ).find( ( r ) => r.url === source );
			redirectId = item && item.id;
			t.check( 'Redirection: fixture redirect created', !! redirectId, String( made.status ) );
			if ( redirectId ) {
				await openRow( page, 'redirection', source );
				const elsewhere = await rest( page, `redirection/v1/redirect/${ redirectId }`, 'POST', {
					url: source,
					match_type: 'url',
					action_type: 'url',
					action_code: 301,
					action_data: { url: '/minn-conc-target-b' },
					group_id: groupId,
					regex: false,
				} );
				t.check( 'Redirection: the other change landed', 200 === elsewhere.status, String( elsewhere.status ) );
				await page.fill( '#minn-modal-overlay [data-editfield="action_code"]', '302' );
				await saveDialog( page );
				const back = await rest( page, `redirection/v1/redirect?filterBy[url]=${ encodeURIComponent( source ) }` );
				const now = ( back.json && ( back.json.items || [] ).find( ( r ) => r.id === redirectId ) ) || {};
				t.check( 'Redirection: the dialog saved its own edit', 302 === Number( now.action_code ), String( now.action_code ) );
				t.check( 'Redirection: the target changed elsewhere is kept', now.action_data && now.action_data.url === '/minn-conc-target-b', JSON.stringify( now.action_data ) );
			}
		}
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( redirectId ) {
			await rest( page, 'redirection/v1/bulk/redirect/delete', 'POST', { items: [ redirectId ] } ).catch( () => {} );
		}
		if ( snippetId ) {
			wpEval( `if ( function_exists( 'Code_Snippets\\\\delete_snippet' ) ) { \\Code_Snippets\\delete_snippet( ${ Number( snippetId ) } ); }` );
		}
		await t.done( browser, errors );
	}
} )();
