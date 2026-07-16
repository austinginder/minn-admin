/**
 * The shim tutorial's example plugin (docs/examples/minn-example-adapter)
 * actually works end to end: copied into wp-content/plugins, activated, its
 * Feedback surface appears in Workspace with a status card + chart, tabs,
 * search, detail modal with a custom-toast action gated by `when`, and the
 * Integrations card lists it clean. Deleting the plugin runs uninstall.php
 * (drops the table). This suite is what keeps the tutorial honest — if the
 * contract changes, the copyable example fails here before an author hits it.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const SRC = path.resolve( __dirname, '..', 'docs', 'examples', 'minn-example-adapter' );
const DEST = path.resolve( __dirname, '..', '..', 'minn-example-adapter' );
const PLUGIN = 'minn-example-adapter/minn-example-adapter';

( async () => {
	const t = reporter( 'example-adapter' );
	const { browser, page, errors } = await launch();
	await login( page );

	const plugin = ( status ) => page.evaluate( async ( [ file, st ] ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + file + ( st ? '' : '?_cb=' + Math.random() ), {
			method: st ? 'PUT' : 'DELETE',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: st ? JSON.stringify( { status: st } ) : undefined,
		} );
		return r.status;
	}, [ PLUGIN, status ] );

	try {
		/* ===== Install: copy the example in, activate over REST ===== */
		fs.rmSync( DEST, { recursive: true, force: true } );
		fs.cpSync( SRC, DEST, { recursive: true } );
		t.check( 'example plugin copied into wp-content/plugins', fs.existsSync( path.join( DEST, 'minn-example-adapter.php' ) ) );
		const act = await plugin( 'active' );
		t.check( 'activates over REST', act === 200, String( act ) );

		/* ===== The surface appears, in the Workspace group ===== */
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn[data-nav="campfire"]', { timeout: 20000 } );
		t.check( 'Feedback lands in the Workspace group', await page.evaluate( () => {
			const btn = document.querySelector( '.minn-nav-btn[data-nav="campfire"]' );
			return !! btn.closest( '#minn-navgrp-workspace' );
		} ) );

		await page.click( '.minn-nav-btn[data-nav="campfire"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );

		/* ===== Status card + chart ===== */
		await page.waitForSelector( '.minn-surface-status', { timeout: 15000 } );
		const status = await page.evaluate( () => document.querySelector( '.minn-surface-status' ).textContent );
		t.check( 'status card shows the server-formatted rows', /Awaiting reply/.test( status ) && /Total feedback/.test( status ), status.slice( 0, 80 ) );
		t.check( 'status chart renders', !! ( await page.$( '.minn-sstat-chart' ) ), '' );

		/* ===== List: seeded rows, tabs, search =====
		 * Plugin activation churn recycles PHP workers, so a list reply can
		 * arrive seconds late or drop entirely. Same-tab clicks are no-ops by
		 * design, so the retry path bounces through another tab to force a
		 * fresh reload. */
		const rows = () => page.$$eval( '.minn-table-row', ( r ) => r.length );
		const rowCount = ( n, ms = 12000 ) => page.waitForFunction(
			( want ) => document.querySelectorAll( '.minn-table-row' ).length === want,
			n, { timeout: ms } ).then( () => true ).catch( () => false );
		const tabTo = async ( stab, want ) => {
			for ( let i = 0; i < 3; i++ ) {
				await page.click( `[data-stab="${ stab }"]` ).catch( () => {} );
				if ( await rowCount( want ) ) return true;
				await page.click( `[data-stab="${ stab === '_all' ? 'read' : '_all' }"]` ).catch( () => {} );
				await page.waitForTimeout( 1200 );
			}
			return false;
		};
		t.check( 'seeded feedback lists on All', ( await rows() ) === 8, String( await rows() ) );
		t.check( 'New tab narrows server-side', await tabTo( 'new', 3 ) );
		t.check( 'back to All restores the full list', await tabTo( '_all', 8 ) );

		// A dropped reply mid-churn can land the showErr card (no toolbar).
		// Recover with a fresh route render before driving search/detail.
		const ensureSurface = async () => {
			if ( await page.$( '#minn-surface-search' ) ) return;
			await page.goto( `${ BASE }/minn-admin/campfire`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-surface-search', { timeout: 20000 } );
			await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length > 0, null, { timeout: 20000 } );
		};
		await ensureSurface();
		let searched = false;
		for ( let i = 0; i < 3 && ! searched; i++ ) {
			await page.click( '#minn-surface-search' );
			await page.evaluate( () => { document.querySelector( '#minn-surface-search' ).value = ''; } );
			await page.keyboard.type( 'typo' );
			searched = await rowCount( 1 );
		}
		t.check( 'search narrows to the matching row', searched );
		await page.evaluate( () => {
			const s = document.querySelector( '#minn-surface-search' );
			s.value = '';
			s.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await rowCount( 8, 20000 );

		/* ===== Detail modal: labels, message block, `when`-gated action ===== */
		await ensureSurface();
		await page.$$eval( '.minn-table-row', ( rows ) => {
			const row = rows.find( ( r ) => r.textContent.includes( 'Miguel' ) );
			if ( row ) row.click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		// Actions render from list data before the detailRoute fetch lands —
		// wait for the resolved message before sampling.
		await page.waitForFunction( () => /typo on the pricing page/.test(
			( document.querySelector( '.minn-modal' ) || {} ).textContent || '' ), null, { timeout: 20000 } );
		const detail = await page.evaluate( () => ( {
			text: document.querySelector( '.minn-modal' ).textContent,
			actions: [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() ),
		} ) );
		t.check( 'detail shows the item fields and message', /typo on the pricing page/.test( detail.text ) && /email/i.test( detail.text ) && ! /"id"/.test( detail.text ), '' );
		t.check( '`when` offers Mark read on a new item', detail.actions.includes( 'Mark read' ), detail.actions.join( ',' ) );

		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Mark read' );
			btn.click();
		} );
		await page.waitForFunction( () => {
			const t2 = document.querySelector( '.minn-toast' );
			return t2 && /Marked as read/.test( t2.textContent );
		}, null, { timeout: 15000 } );
		t.check( 'action route\'s { message } replaces the default toast', true );

		// Re-open the same item: the `when` gate now hides Mark read.
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), null, { timeout: 15000 } ).catch( () => null );
		await page.$$eval( '.minn-table-row', ( rows ) => {
			const row = rows.find( ( r ) => r.textContent.includes( 'Miguel' ) );
			if ( row ) row.click();
		} );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		const after = await page.evaluate( () => [ ...document.querySelectorAll( '[data-saction]' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( '`when` hides Mark read once read', ! after.includes( 'Mark read' ) && after.includes( 'Archive' ), after.join( ',' ) );

		// Archive rides a native confirm and the custom toast.
		page.once( 'dialog', ( dlg ) => dlg.accept() );
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => b.textContent.trim() === 'Archive' );
			btn.click();
		} );
		await page.waitForFunction( () => {
			const t2 = document.querySelector( '.minn-toast' );
			return t2 && /Feedback archived/.test( t2.textContent );
		}, null, { timeout: 15000 } );
		t.check( 'Archive confirms and reports its own toast', true );

		/* ===== Integrations card lists it, attributed and clean ===== */
		await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 30000 } );
		const card = await page.$eval( '#minn-sys-integrations', ( el ) => el.textContent );
		t.check( 'Integrations card lists the example surface', /Feedback/.test( card ) && /campfire/.test( card ), '' );
	} finally {
		await plugin( 'inactive' ).catch( () => {} );
		const del = await plugin( null ).catch( () => 0 );
		// REST delete removes the files and runs uninstall.php (drops the
		// table). Belt and braces if it failed: remove the copy ourselves.
		if ( fs.existsSync( DEST ) ) fs.rmSync( DEST, { recursive: true, force: true } );
		t.check( 'plugin deletes cleanly (uninstall drops the table)', del === 200, String( del ) );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
