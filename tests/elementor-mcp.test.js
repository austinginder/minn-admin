/**
 * Elementor MCP — Agent Access family provider.
 *
 * Proves: the surface joins the agent-access family (one sidebar entry with
 * Novamira); the status card's Turn on / Turn off buttons really flip
 * Elementor's own option (status-card actions send their `body`, which they
 * did not before); a password their setup flow names shows up as a
 * connection while an unrelated application password does not; Revoke from
 * the row removes it; the switch refuses a caller without a REST nonce, as
 * Elementor's own route does; zero console errors.
 *
 * Restores elementor_mcp_enabled to what the run found and removes every
 * password it created.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wpPhp = ( php ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=admin 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'elementor-mcp' );
	const ready = wpPhp( "echo class_exists( '\\\\Elementor\\\\MCP\\\\Composer\\\\Admin\\\\McpSettingsController' ) ? 1 : 0;" );
	if ( '1' !== ready ) {
		console.log( 'SKIP  Elementor MCP package not loaded' );
		process.exit( 0 );
	}
	const before = wpPhp( "echo wp_json_encode( get_option( 'elementor_mcp_enabled', '__missing__' ) );" );
	wpPhp( "delete_option( 'elementor_mcp_enabled' ); echo 1;" );
	const optionOn = () => '1' === wpPhp( "echo \\Elementor\\MCP\\Composer\\Admin\\McpSettingsController::is_enabled() ? 1 : 0;" );

	const { browser, page, errors } = await launch();
	page.on( 'dialog', ( d ) => d.accept() );
	const statusRow = ( label ) => page.evaluate( ( l ) => {
		const row = Array.from( document.querySelectorAll( '.minn-surface-status *' ) ).find( ( el ) => el.children.length && el.textContent.trim().startsWith( l ) );
		return row ? row.textContent.replace( /\s+/g, ' ' ).trim() : '';
	}, label );
	const clickStatus = ( label ) => page.evaluate( ( l ) => {
		const b = Array.from( document.querySelectorAll( '[data-sstatact]' ) ).find( ( x ) => x.textContent.trim().startsWith( l ) );
		if ( b ) b.click();
		return !! b;
	}, label );
	let created = [];

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/elementor-mcp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sstatact]', { timeout: 20000 } );
		t.check( 'surface joins the agent-access family', await page.evaluate( () =>
			( window.MINN.surfaces || [] ).some( ( s ) => s.id === 'elementor-mcp' && s.family === 'agent-access' ) ) );

		/* ===== Turn on from the status card ===== */
		t.check( 'status card offers Turn on while off', await clickStatus( 'Turn on' ) );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '[data-sstatact]' ) ).some( ( x ) => /Turn off/.test( x.textContent ) ), null, { timeout: 15000 } );
		t.check( 'Turn on flips their option (the action body reached the route)', optionOn() );

		/* ===== Connections: only their setup's passwords ===== */
		created = JSON.parse( wpPhp( `
			$a = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'Elementor MCP - Claude Code (' . gmdate( 'Y-m-d H:i:s' ) . ')' ) );
			$b = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'Minn suite unrelated password' ) );
			echo wp_json_encode( array( $a[1]['uuid'], $b[1]['uuid'] ) );
		` ) );
		await page.goto( BASE + '/minn-admin/elementor-mcp', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => /Claude Code/.test( r.textContent ) ), null, { timeout: 15000 } );
		const rows = await page.$$eval( '.minn-table-row', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'their setup password is listed, an unrelated one is not',
			rows.some( ( r ) => /Claude Code/.test( r ) ) && ! rows.some( ( r ) => /unrelated/.test( r ) ), JSON.stringify( rows ) );

		/* ===== Revoke from the row ===== */
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => /Claude Code/.test( r.textContent ) ).click() );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).some( ( b ) => /Revoke/.test( b.textContent ) ), null, { timeout: 15000 } );
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => /Revoke/.test( b.textContent ) ).click() );
		await page.waitForFunction( () => ! Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => /Claude Code/.test( r.textContent ) ), null, { timeout: 15000 } );
		const left = wpPhp( `$ids = wp_list_pluck( WP_Application_Passwords::get_user_application_passwords( 1 ), 'uuid' ); echo wp_json_encode( array( in_array( '${ created[ 0 ] }', $ids, true ), in_array( '${ created[ 1 ] }', $ids, true ) ) );` );
		t.check( 'Revoke removes their password and leaves the unrelated one', '[false,true]' === left, left );

		/* ===== No nonce, no switch (Elementor's own rule) ===== */
		const noNonce = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/elementor-mcp/toggle', {
				method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { enabled: false } ),
			} );
			return r.status;
		} );
		t.check( 'toggle refuses a request without a REST nonce', noNonce >= 400 && optionOn(), String( noNonce ) );

		/* ===== Turn off from the status card ===== */
		await page.goto( BASE + '/minn-admin/elementor-mcp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sstatact]', { timeout: 20000 } );
		t.check( 'status card offers Turn off while on', await clickStatus( 'Turn off' ) );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '[data-sstatact]' ) ).some( ( x ) => /Turn on/.test( x.textContent ) ), null, { timeout: 15000 } );
		t.check( 'Turn off flips their option back', ! optionOn() );
	} finally {
		try {
			wpPhp( `
				foreach ( WP_Application_Passwords::get_user_application_passwords( 1 ) as $pw ) {
					if ( in_array( $pw['uuid'], json_decode( '${ JSON.stringify( created ) }', true ) ?: array(), true ) ) { WP_Application_Passwords::delete_application_password( 1, $pw['uuid'] ); }
				}
				$b = json_decode( '${ before.replace( /'/g, '' ) }', true );
				if ( '__missing__' === $b ) { delete_option( 'elementor_mcp_enabled' ); } else { update_option( 'elementor_mcp_enabled', $b ); }
				echo 1;
			` );
		} catch ( e ) {}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
