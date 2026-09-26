/**
 * Sucuri Security — local audit log in the activity-log family.
 *
 * Proves: events Sucuri queues on the site list through Minn (their own
 * queue reader), newest first, with level tabs and search; the status card
 * counts them, says where the history lives, and charts them; an Editor is
 * refused; zero console errors.
 *
 * Sucuri rests installed-inactive on minnadmin (WSAL and LLA-R are the
 * resident activity logs). The suite activates it, seeds two events through
 * SucuriScanEvent's own reporters, and in finally removes exactly those queue
 * entries through their cache class and restores inactive.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null`, { timeout: 120000 } ).toString().trim();
const wpPhp = ( php, user = 'admin' ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=${ user } 2>/dev/null`, { input: '<?php ' + php, timeout: 120000 } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'sucuri' );
	try { wp( 'plugin is-installed sucuri-scanner' ); } catch ( e ) {
		console.log( 'SKIP  sucuri-scanner is not installed' );
		process.exit( 0 );
	}
	let wasActive = false;
	try { wp( 'plugin is-active sucuri-scanner' ); wasActive = true; } catch ( e ) {}
	if ( ! wasActive ) wp( 'plugin activate sucuri-scanner' );
	const marker = 'Minn suite sucuri ' + Date.now();
	wpPhp( `SucuriScanEvent::reportWarningEvent( '${ marker } warning' ); SucuriScanEvent::reportInfoEvent( '${ marker } info' ); echo 1;` );

	const { browser, page, errors } = await launch();
	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return { status: r.status, body: await r.json() };
	}, path );

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/sucuri', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		t.check( 'surface joins the activity-log family', await page.evaluate( () =>
			( window.MINN.surfaces || [] ).some( ( s ) => s.id === 'sucuri' && s.family === 'activity-log' ) ) );

		const all = await api( 'minn-admin/v1/sucuri/events?search=' + encodeURIComponent( marker ) );
		const items = ( all.body && all.body.items ) || [];
		t.check( 'both seeded events list through their queue reader', 2 === all.body.total && items.every( ( i ) => /Z$/.test( i.when ) ), JSON.stringify( all.body ) );
		t.check( 'levels come through (warning and info)', items.some( ( i ) => 'warning' === i.event ) && items.some( ( i ) => 'info' === i.event ), JSON.stringify( items.map( ( i ) => i.event ) ) );
		const warn = await api( 'minn-admin/v1/sucuri/events?level=warning&search=' + encodeURIComponent( marker ) );
		t.check( 'the Warnings tab narrows to the warning', 1 === warn.body.total && 'warning' === warn.body.items[ 0 ].event, JSON.stringify( warn.body ) );

		const st = await api( 'minn-admin/v1/sucuri/status' );
		const labels = ( ( st.body && st.body.rows ) || [] ).map( ( r ) => r.label );
		t.check( 'status card counts events and says where they live', labels.includes( 'Events on this site' ) && labels.includes( 'Warnings and worse' ), JSON.stringify( st.body.rows ) );
		const ch = st.body && st.body.chart;
		t.check( 'status card charts the last 14 days with today holding the seeded events',
			!! ch && 14 === ch.points.length && ( ch.points[ 13 ].value + ch.points[ 13 ].secondary ) >= 2, JSON.stringify( ch && ch.points[ 13 ] ) );

		const ed = wpPhp( `echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/sucuri/events' ) )->get_status();`, 'minn-editor' );
		t.check( 'an Editor is refused', '403' === ed, ed );
	} finally {
		try {
			wpPhp( `
				$c = new SucuriScanCache( 'auditqueue' );
				foreach ( (array) $c->getAll() as $k => $m ) { if ( is_string( $m ) && false !== strpos( $m, '${ marker }' ) ) { $c->delete( $k ); } }
				echo 1;
			` );
		} catch ( e ) {}
		if ( ! wasActive ) {
			try { wp( 'plugin deactivate sucuri-scanner' ); } catch ( e ) {}
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
