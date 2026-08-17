/**
 * WP Multi Network adapter coverage.
 *
 * Runs against the separate minnms.localhost lab. The suite creates a network
 * and a site, moves the site through Minn's right-click action, then deletes the
 * disposable network through Minn. Cleanup runs through the same guarded API.
 *
 * Required: MINN_MS_SUPER_PASS. Unset or unreachable labs skip cleanly.
 */
const { chromium } = require( 'playwright-core' );

const MAIN = process.env.MINN_MS_URL || 'https://minnms.localhost';
const STORE = process.env.MINN_MS_STORE || 'https://store.minnms.localhost';
const SUPER_USER = process.env.MINN_MS_SUPER_USER || 'admin';
const SUPER_PASS = process.env.MINN_MS_SUPER_PASS || '';
const CHROME = process.env.MINN_TEST_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SUBSITE_ADMIN = { user: 'minnsiteadmin', pass: 'minnms-siteadmin-pass-1' };

const results = [];
function check( label, ok, detail = '' ) {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
}

async function reachable( url ) {
	try {
		return ( await fetch( url ) ).status > 0;
	} catch ( e ) {
		return false;
	}
}

async function makeContext( browser ) {
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text().slice( 0, 180 ) );
		}
	} );
	return { ctx, page, errors };
}

async function loginApp( page, site, user, pass ) {
	const dest = site + '/minn-admin/overview';
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		await page.goto( site + '/wp-login.php?redirect_to=' + encodeURIComponent( dest ), { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await page.click( '#wp-submit' );
		try {
			await page.waitForURL( /\/minn-admin\//, { timeout: 30000, waitUntil: 'domcontentloaded' } );
			return;
		} catch ( e ) {
			if ( attempt ) throw e;
			await page.waitForTimeout( 1500 );
		}
	}
}

async function gotoRoute( page, site, route ) {
	await page.goto( site + '/minn-admin/' + route, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
	await page.waitForTimeout( 1500 );
}

async function api( page, method, path, body ) {
	return page.evaluate( async ( args ) => {
		const response = await fetch( window.MINN.restUrl + args.path, {
			method: args.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: args.body === undefined ? undefined : JSON.stringify( args.body ),
		} );
		let data = null;
		try { data = await response.json(); } catch ( e ) {}
		return { status: response.status, data };
	}, { method, path, body } );
}

( async () => {
	if ( ! SUPER_PASS ) {
		console.log( 'SKIP wp-multi-network: set MINN_MS_SUPER_PASS.' );
		process.exit( 0 );
	}
	if ( ! await reachable( MAIN + '/minn-admin/' ) ) {
		console.log( `SKIP wp-multi-network: lab ${ MAIN } is unreachable.` );
		process.exit( 0 );
	}

	const browser = await chromium.launch( {
		executablePath: CHROME,
		args: [ '--ignore-certificate-errors', '--disable-http2', '--disable-features=MacAppCodeSignClone' ],
	} );
	const stamp = Date.now();
	const networkTitle = 'Minn network suite ' + stamp;
	const networkDomain = 'minn-wpmn-' + stamp + '.minnms.localhost';
	const siteAddress = 'wpmnmove' + String( stamp ).slice( -9 );
	const siteTitle = 'Minn move suite ' + stamp;
	let networkId = 0;
	let siteId = 0;
	let unauthorizedNetworkId = 0;
	let superPage = null;
	let superContext = null;
	const allErrors = [];

	try {
		const made = await makeContext( browser );
		superContext = made.ctx;
		superPage = made.page;
		await loginApp( superPage, MAIN, SUPER_USER, SUPER_PASS );
		await gotoRoute( superPage, MAIN, 'wp-multi-network' );

		const initial = await api( superPage, 'GET', 'minn-admin/v1/wp-multi-network/networks' );
		if ( initial.status === 404 ) {
			console.log( 'SKIP wp-multi-network: WP Multi Network is not active on the lab.' );
			await superContext.close();
			await browser.close();
			process.exit( 0 );
		}
		await superPage.waitForSelector( '#minn-view [data-sitem]', { timeout: 15000 } );
		const directory = await superPage.evaluate( () => ( {
			nav: [ ...document.querySelectorAll( '#minn-navgrp-network .minn-nav-btn' ) ].map( ( b ) => b.textContent.trim() ),
			rows: [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].map( ( r ) => r.textContent.trim() ),
			columns: [ ...document.querySelectorAll( '#minn-view .minn-table-head > *' ) ].map( ( c ) => c.textContent.trim() ),
		} ) );
		check( 'Networks joins Minn\'s Network navigation', directory.nav.includes( 'Networks' ), directory.nav.join( ', ' ) );
		check( 'network directory lists both lab networks', directory.rows.length >= 2 && directory.rows.some( ( row ) => /Lab Network 2/.test( row ) ), `${ directory.rows.length } rows` );
		check( 'network directory shows operational counts', directory.columns.some( ( c ) => /Sites/.test( c ) ) && directory.columns.some( ( c ) => /Administrators/.test( c ) ), directory.columns.join( ' | ' ) );
		const primaryDelete = await api( superPage, 'DELETE', 'minn-admin/v1/wp-multi-network/networks/1' );
		const primaryMove = await api( superPage, 'POST', 'minn-admin/v1/wp-multi-network/sites/1/move', { network: 2 } );
		check( 'the server protects the primary network', primaryDelete.status === 400 && primaryDelete.data.code === 'main_network', JSON.stringify( primaryDelete ) );
		check( 'the server protects every network main site', primaryMove.status === 400 && primaryMove.data.code === 'main_site', JSON.stringify( primaryMove ) );

		await superPage.click( '#minn-surface-add' );
		await superPage.waitForSelector( '[data-createfield="title"]', { timeout: 10000 } );
		const defaultPath = await superPage.inputValue( '[data-createfield="path"]' );
		check( 'new-network form defaults the path to slash', defaultPath === '/', defaultPath );
		await superPage.fill( '[data-createfield="title"]', networkTitle );
		await superPage.fill( '[data-createfield="domain"]', networkDomain );
		await superPage.fill( '[data-createfield="site_title"]', 'Minn suite root' );
		await superPage.click( '#minn-surface-create' );
		await superPage.waitForFunction( ( title ) => [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].some( ( row ) => row.textContent.includes( title ) ), networkTitle, { timeout: 30000 } );

		const afterCreate = await api( superPage, 'GET', 'minn-admin/v1/wp-multi-network/networks?per_page=100' );
		const created = afterCreate.data.items.find( ( row ) => row.address === networkDomain + '/' );
		networkId = created ? Number( created.id ) : 0;
		check( 'Add network creates a root site through WP Multi Network', !! created && created.sites === 1 && created.mainSiteId > 0, JSON.stringify( created || {} ) );

		const site = await api( superPage, 'POST', 'minn-admin/v1/network/sites', {
			address: siteAddress,
			title: siteTitle,
			email: 'admin@minnms.localhost',
		} );
		siteId = site.data && site.data.id ? Number( site.data.id ) : 0;
		check( 'suite created a disposable source site', site.status === 200 && siteId > 0, JSON.stringify( site.data || {} ) );

		await gotoRoute( superPage, MAIN, 'network-sites' );
		await superPage.waitForFunction( ( title ) => [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].some( ( row ) => row.textContent.includes( title ) ), siteTitle, { timeout: 20000 } );
		await superPage.evaluate( ( title ) => {
			const row = [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].find( ( item ) => item.textContent.includes( title ) );
			row.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 420, clientY: 360 } ) );
		}, siteTitle );
		await superPage.waitForSelector( '.minn-ctx-menu', { timeout: 10000 } );
		const siteMenu = await superPage.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( items ) => items.map( ( item ) => item.textContent.trim() ) );
		check( 'site right-click menu offers moving to another network', siteMenu.includes( 'Move to another network' ), siteMenu.join( ' | ' ) );
		await superPage.evaluate( () => {
			const button = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( item ) => item.textContent.trim() === 'Move to another network' );
			button.click();
		} );
		await superPage.waitForSelector( '[data-actfield="network"] .minn-ac-input', { timeout: 10000 } );
		await superPage.click( '[data-actfield="network"] .minn-ac-input' );
		await superPage.waitForSelector( `[data-actfield="network"] .minn-ac-item[data-acv="${ networkId }"]`, { timeout: 10000 } );
		await superPage.click( `[data-actfield="network"] .minn-ac-item[data-acv="${ networkId }"]` );
		let moveConfirm = '';
		superPage.once( 'dialog', async ( dialog ) => {
			moveConfirm = dialog.message();
			await dialog.accept();
		} );
		const moveResponse = superPage.waitForResponse( ( response ) => response.url().includes( `/wp-multi-network/sites/${ siteId }/move` ) && response.request().method() === 'POST' );
		await superPage.click( '[data-actgo]' );
		const moved = await moveResponse;
		const movedData = await moved.json();
		check( 'site move asks for confirmation', /network settings and administration context change/.test( moveConfirm ), moveConfirm );
		check( 'site move posts the selected destination', moved.status() === 200 && movedData.moved && Number( movedData.network ) === networkId, JSON.stringify( movedData ) );

		await gotoRoute( superPage, MAIN, 'wp-multi-network' );
		await superPage.waitForFunction( ( title ) => [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].some( ( row ) => row.textContent.includes( title ) ), networkTitle, { timeout: 20000 } );
		const afterMove = await api( superPage, 'GET', 'minn-admin/v1/wp-multi-network/networks?per_page=100' );
		const movedNetwork = afterMove.data.items.find( ( row ) => Number( row.id ) === networkId );
		check( 'destination network count updates after the move', movedNetwork && movedNetwork.sites === 2, JSON.stringify( movedNetwork || {} ) );

		await superPage.evaluate( ( title ) => {
			const row = [ ...document.querySelectorAll( '#minn-view [data-sitem]' ) ].find( ( item ) => item.textContent.includes( title ) );
			row.querySelector( '.minn-row-more' ).click();
		}, networkTitle );
		await superPage.waitForSelector( '.minn-ctx-menu', { timeout: 10000 } );
		const deleteResponse = superPage.waitForResponse( ( response ) => response.url().includes( `/wp-multi-network/networks/${ networkId }` ) && response.request().method() === 'DELETE' );
		let deleteConfirm = '';
		superPage.once( 'dialog', async ( dialog ) => {
			deleteConfirm = dialog.message();
			await dialog.accept();
		} );
		await superPage.evaluate( () => {
			const button = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( item ) => item.textContent.trim() === 'Delete network and sites' );
			button.click();
		} );
		const deleted = await deleteResponse;
		check( 'network deletion names its permanent scope', /every site in it permanently/.test( deleteConfirm ), deleteConfirm );
		check( 'deleting the disposable network also removes its sites', deleted.status() === 200, String( deleted.status() ) );
		networkId = 0;
		siteId = 0;

		allErrors.push( ...made.errors );

		const low = await makeContext( browser );
		await loginApp( low.page, STORE, SUBSITE_ADMIN.user, SUBSITE_ADMIN.pass );
		await gotoRoute( low.page, STORE, 'overview' );
		const hidden = await low.page.evaluate( () => ! [ ...document.querySelectorAll( '#minn-navgrp-network .minn-nav-btn' ) ].some( ( button ) => button.textContent.trim() === 'Networks' ) );
		check( 'site administrators never see the Networks surface', hidden );
		const probes = [
			await api( low.page, 'GET', 'minn-admin/v1/wp-multi-network/networks' ),
			await api( low.page, 'POST', 'minn-admin/v1/wp-multi-network/networks', { title: 'Unauthorized', domain: 'unauthorized-' + stamp + '.minnms.localhost', path: '/' } ),
			await api( low.page, 'DELETE', 'minn-admin/v1/wp-multi-network/networks/1' ),
			await api( low.page, 'POST', 'minn-admin/v1/wp-multi-network/sites/1/move', { network: 2 } ),
		];
		if ( probes[ 1 ].data && probes[ 1 ].data.id ) unauthorizedNetworkId = Number( probes[ 1 ].data.id );
		check( 'every WP Multi Network route refuses a site administrator', probes.every( ( probe ) => probe.status === 403 ), probes.map( ( probe ) => probe.status ).join( ', ' ) );
		await low.ctx.close();
	} catch ( e ) {
		check( 'suite ran without throwing', false, e.message.split( '\n' )[ 0 ] );
	} finally {
		if ( superPage && ( networkId || unauthorizedNetworkId ) ) {
			for ( const id of [ networkId, unauthorizedNetworkId ].filter( Boolean ) ) {
				await api( superPage, 'DELETE', 'minn-admin/v1/wp-multi-network/networks/' + id ).catch( () => {} );
			}
		}
		if ( superPage && siteId ) {
			await api( superPage, 'DELETE', 'minn-admin/v1/network/sites/' + siteId ).catch( () => {} );
		}
		if ( superContext ) await superContext.close().catch( () => {} );
	}

	check( 'No console/page errors across the adapter walk', allErrors.length === 0, [ ...new Set( allErrors ) ].slice( 0, 5 ).join( ' | ' ) );
	const failed = results.filter( ( result ) => ! result ).length;
	console.log( `\nwp-multi-network: ${ results.length - failed }/${ results.length } passed` );
	await Promise.race( [ browser.close(), new Promise( ( resolve ) => setTimeout( resolve, 5000 ) ) ] );
	process.exit( failed ? 1 : 0 );
} )();
