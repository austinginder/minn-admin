/**
 * WP Migrate backups provider (adapters/wp-migrate.php): the .sql / .sql.gz
 * files WP Migrate writes, listed as a Backups-family surface. Reads ride
 * their own Filesystem::get_backups(), the Download row action is a link to
 * their capability-gated admin handler with the compressed flag their
 * FILTER_VALIDATE_BOOLEAN parser expects, and deletes remove the real file
 * through their filesystem with an escape-proof path check. Seeds its own
 * pattern-conforming files (their listing regex requires
 * {prefix}-(backup|migrate)-{14 digits}-{5 chars}) and removes them again.
 */
const { execSync } = require( 'child_process' );
const { launch, login, reporter, BASE, WP } = require( './helpers' );

( async () => {
	const t = reporter( 'wpm-backups' );
	const { browser, page, errors } = await launch();
	await login( page );

	const SQL = 'minnsuite-backup-20260821100000-aaaaa';
	const GZ  = 'minnsuite-migrate-20260821110000-bbbbb';
	// PHP rides stdin (eval-file -) so no layer of shell quoting can eat it.
	const phpDir = '<?php\n$fs = \\DeliciousBrains\\WPMDB\\WPMDBDI::getInstance()->get( \\DeliciousBrains\\WPMDB\\Common\\Filesystem\\Filesystem::class );\n$d = $fs->get_upload_info( "path" );\n';
	const runPhp = ( body ) => execSync( `wp --path=${ WP } eval-file -`, { input: phpDir + body, stdio: [ 'pipe', 'pipe', 'ignore' ] } ).toString();
	const seed = () => runPhp( `file_put_contents( $d . "/${ SQL }.sql", "-- suite sql\\n" );\nfile_put_contents( $d . "/${ GZ }.sql.gz", gzencode( "-- suite gz\\n" ) );\n` );
	const sweep = () => runPhp( 'foreach ( glob( $d . "/minnsuite-*" ) as $f ) { @unlink( $f ); }\n' );
	const api = ( method, route ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method, credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, route } );

	try {
		sweep();
		seed();
		await page.goto( BASE + '/minn-admin/wpmigrate-backups', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'minnsuite' ) ),
		null, { timeout: 20000 } );

		// Status card, server-built.
		const status = await page.evaluate( () => document.querySelector( '.minn-surface-status, [data-sstatus], .minn-status-card' )
			? document.querySelector( '.minn-surface-status, [data-sstatus], .minn-status-card' ).textContent : document.body.textContent );
		t.check( 'status card reports newest / files / on disk', /Newest backup/.test( status ) && /On disk/.test( status ) );

		// Both kinds list with their labels.
		const rows = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) )
				.filter( ( r ) => r.textContent.includes( 'minnsuite' ) )
				.map( ( r ) => r.textContent ) );
		t.check( 'plain backup and pre-migration safety copy both list', rows.length === 2
			&& rows.some( ( x ) => /Before a migration/.test( x ) ) && rows.some( ( x ) => /Backup/.test( x ) ),
			JSON.stringify( rows.map( ( x ) => x.slice( 0, 80 ) ) ) );

		// Download is a link to THEIR handler carrying the flag their
		// boolean filter parses — open the gz row's menu and read the href.
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-table-row' ) )
				.find( ( r ) => r.textContent.includes( 'minnsuite' ) && r.textContent.includes( 'Before a migration' ) );
			row.querySelector( '.minn-row-more' ).click();
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const href = await page.evaluate( () => {
			const a = Array.from( document.querySelectorAll( '.minn-ctx-menu a' ) ).find( ( x ) => /Download/.test( x.textContent ) );
			return a ? a.getAttribute( 'href' ) : '';
		} );
		t.check( 'download link targets their gated admin handler', /wpmdb-download-backup=/.test( href ) && href.includes( GZ ), href );
		t.check( 'compressed flag rides in their accepted vocabulary', /wpmdb-compressed-backup=(true|1)/.test( href ), href );
		await page.keyboard.press( 'Escape' );

		// Delete the plain backup through the row menu; native confirm.
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-table-row' ) )
				.find( ( r ) => r.textContent.includes( 'minnsuite' ) && ! r.textContent.includes( 'Before a migration' ) );
			row.querySelector( '.minn-row-more' ).click();
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		let confirmText = '';
		page.once( 'dialog', ( d ) => { confirmText = d.message(); d.accept(); } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-ctx-menu button' ) ).find( ( b ) => /Delete backup/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'minnsuite' ) && ! r.textContent.includes( 'Before a migration' ) ),
		null, { timeout: 15000 } );
		t.check( 'delete confirm says the file is unrecoverable', /cannot be recovered/.test( confirmText ), confirmText );
		const gone = runPhp( `echo file_exists( $d . "/${ SQL }.sql" ) ? "still-there" : "gone";\n` );
		t.check( 'the file itself is deleted through their filesystem', /gone/.test( gone ), gone );

		// The route refuses an id that would escape the backup directory.
		const esc = await api( 'POST', 'minn-admin/v1/wp-migrate/backups/' + encodeURIComponent( '../../wp-config' ) + '/delete' );
		t.check( 'path traversal refuses 404', esc.status === 404, JSON.stringify( esc.data ) );
	} finally {
		sweep();
	}

	await t.done( browser, errors );
} )();
