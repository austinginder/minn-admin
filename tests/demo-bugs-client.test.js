/**
 * Demo-site bugs, the client half (v0.45.0).
 *
 *   D-03  Wall-clock times follow the site's timezone, not the browser's: a
 *         booking page and a booking list read in a browser nine hours away
 *         show what the site (and its "Next" card) says. With the payload's
 *         timezone name the time is right across a DST change too.
 *   D-05  A surface list waiting on a tab change keeps its old rows dim for
 *         the whole wait and out of reach of the keyboard, not only the
 *         mouse.
 *   D-06  The media preview is a dialog: role, aria-modal, a label, and
 *         focus moved into it on open.
 *   D-20  A subscription's Orders card gives the parent order its status and
 *         total and every order a date; the Customer card names the account.
 *   D-21  A card's row list (a plan's Members) has no section rule under its
 *         last row.
 *   rows  A detail row holding a full ISO instant reads on the site's clock
 *         (mail logs, an entry's Submission panel); a text cell keeps a
 *         recipient's "<address>" and still renders markup as text; vendor
 *         statuses read as words and their pills stay in their column; an
 *         on/off boolean is a dialog's pill.
 *   day   A stay's days (JetBooking) read as the site's in a browser west
 *         of it.
 *   ip    An activity event filed under ip_addresses shows the address.
 *   settings  A settings tab whose route fails says so under a tab bar
 *         that stays, and another tab still opens.
 *   labels  A detail dialog's display keys (UpdraftPlus and WPvivid send
 *         stored and lock) take Minn's translated labels.
 *   entry  (N2) Back from an opened entry shows its row as the page left it.
 *   year  (I4) A list date names the year the site counts.
 *   heat  (I5) A revision's heatmap day is the site's.
 *   itemsettings  (I7) A late settings failure for one form stays off the
 *         next form's settings.
 *   dot   (I8) A content row's unsaved dot survives the pill clip on a phone.
 *   report  (S15) The copied system report carries the site name as text.
 *   tzsave  (S19) A timezone saved in Settings moves the app's clock.
 *
 * Fixtures: two Amelia appointments and their customer (Amelia's own
 * tables), a PNG attachment, and a product, customer, parent order,
 * subscription and renewal (WooCommerce REST, and WooCommerce
 * Subscriptions' own renewal API). All are removed on the way out. The
 * busy-list and Members checks only read what the lab already holds (core
 * cron events through WP Crontrol, an existing membership plan); the
 * list's responses are held by a route, never changed, and the rows
 * section answers WP Crontrol's GETs with mocked events, the day section one
 * JetBooking detail with a mocked stay, and the ip section WP Activity
 * Log's GETs with a mocked event, and the settings section answers one
 * settings tab's GET with a 404 (any other request to those routes is
 * aborted).
 *
 * The entry, year, itemsettings and tzsave sections answer their routes
 * with mocks as well (an entry list and page, one event, one form's
 * settings held then refused, the settings save), so nothing is written.
 *
 * MINN_DEMO_ONLY=labels|tz|busy|rows|day|ip|settings|entry|year|heat|
 * itemsettings|dot|report|tzsave|media|subs|members runs those sections
 * (comma-separated).
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const vm = require( 'vm' );
const { execFileSync } = require( 'child_process' );
const { launch, login, reporter, loadAuthState, BASE, WP } = require( './helpers' );

const ONLY = process.env.MINN_DEMO_ONLY || '';
const want = ( s ) => ! ONLY || ONLY.split( ',' ).includes( s );
const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-demo-bugs-client-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				return wp( 'eval-file', file );
			} catch ( e ) {
				if ( attempt === 3 ) return String( e.stdout || '' ).trim();
			}
		}
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* gone */ }
	}
	return '';
};
const json = ( out ) => {
	try { return JSON.parse( ( String( out ).match( /\{.*\}/s ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { return {}; }
};
// Intl writes a narrow no-break space before AM/PM; wp_date a plain one.
const flat = ( s ) => String( s || '' ).replace( /\s+/g, '' );
const id = ( v ) => ( Number.isInteger( v ) && v > 1 ? v : null );

( async () => {
	const t = reporter( 'demo-bugs-client' );

	/* ===== Detail labels for display keys, as shipped in app.js ===== */
	if ( want( 'labels' ) ) {
		const src = fs.readFileSync( path.resolve( __dirname, '../assets/js/app.js' ), 'utf8' );
		const at = src.indexOf( '\tfunction surfaceDetailLabel(' );
		const code = at < 0 ? '' : src.slice( at, src.indexOf( '\n\t}', at ) + 3 );
		// A catalog that marks every string it translates.
		const label = code ? vm.runInNewContext( code + '\nsurfaceDetailLabel;', { __: ( x ) => '«' + x + '»' } ) : null;
		const got = label ? [ 'stored', 'lock', 'where', 'locked' ].map( ( k ) => label( k ) ) : [];
		t.check( 'The backup dialogs\' display keys (stored, lock) take the translated labels', '«Stored»|«Lock»|«Stored»|«Lock»' === got.join( '|' ), got.join( '|' ) || 'no surfaceDetailLabel in app.js' );
	}

	const { browser, page, errors } = await launch();
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 60000 } );

	const api = ( p, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.p, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { p, opts } );
	const surfaces = await page.evaluate( () => ( window.MINN.surfaces || [] ).map( ( s ) => s.id ) );
	const suffix = Date.now().toString( 36 );

	let amelia = null;
	let mediaId = null;
	const wc = { product: null, customer: null, parent: null, sub: null, renewal: null };
	let tzCtx = null;

	try {
		/* ===== D-03 · times in the site's timezone ===== */
		if ( want( 'tz' ) && surfaces.includes( 'amelia' ) ) {
			const email = `demo-bugs-tz-${ suffix }@example.com`;
			amelia = json( evalPhp( `
				global $wpdb;
				$users = $wpdb->prefix . 'amelia_users';
				$services = $wpdb->prefix . 'amelia_services';
				$appts = $wpdb->prefix . 'amelia_appointments';
				$bookings = $wpdb->prefix . 'amelia_customer_bookings';
				$provider = (int) $wpdb->get_var( "SELECT id FROM {$users} WHERE type = 'provider' ORDER BY id ASC LIMIT 1" );
				$service = (int) $wpdb->get_var( "SELECT id FROM {$services} ORDER BY id ASC LIMIT 1" );
				if ( ! $provider || ! $service ) {
					echo wp_json_encode( array( 'skip' => 'no Amelia provider or service to book against' ) );
					return;
				}
				$ok = $wpdb->insert( $users, array( 'status' => 'visible', 'type' => 'customer', 'firstName' => 'Tz', 'lastName' => 'Probe ${ suffix }', 'email' => '${ email }' ) );
				$cid = $ok ? (int) $wpdb->insert_id : 0;
				$out = array( 'customer' => $cid, 'appts' => array(), 'tz' => wp_timezone_string() );
				$tz = wp_timezone();
				$now_off = $tz->getOffset( new DateTime( 'now', $tz ) );
				// Tomorrow, in the offset in force now; and a day more than a week
				// out on the other side of a DST change, where the zone has one.
				$soon = strtotime( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 14:00:00 UTC' );
				$far = 0;
				foreach ( array( '-01-15', '-07-15' ) as $md ) {
					foreach ( array( 0, 1 ) as $add ) {
						$c = strtotime( ( (int) gmdate( 'Y' ) + $add ) . $md . ' 15:00:00 UTC' );
						if ( $c > time() + 9 * DAY_IN_SECONDS && $tz->getOffset( new DateTime( '@' . $c ) ) !== $now_off && ( ! $far || $c < $far ) ) {
							$far = $c;
						}
					}
				}
				if ( ! $far ) {
					$far = strtotime( gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) . ' 15:00:00 UTC' );
				}
				foreach ( array( 'soon' => $soon, 'far' => $far ) as $k => $ts ) {
					if ( $cid <= 1 ) {
						break;
					}
					$ok = $wpdb->insert( $appts, array( 'status' => 'approved', 'bookingStart' => gmdate( 'Y-m-d H:i:s', $ts ), 'bookingEnd' => gmdate( 'Y-m-d H:i:s', $ts + 1800 ), 'notifyParticipants' => 0, 'serviceId' => $service, 'providerId' => $provider, 'internalNotes' => 'minn-demo-bugs-client' ) );
					$aid = $ok ? (int) $wpdb->insert_id : 0;
					if ( $aid > 1 ) {
						$wpdb->insert( $bookings, array( 'appointmentId' => $aid, 'customerId' => $cid, 'status' => 'approved', 'price' => 45, 'persons' => 1, 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
						$out['appts'][ $k ] = array( 'id' => $aid, 'iso' => gmdate( 'Y-m-d\\TH:i:s\\Z', $ts ), 'time' => wp_date( 'g:i A', $ts ), 'day' => wp_date( 'M j', $ts ) );
					}
				}
				echo wp_json_encode( $out );
			` ) );
			const soon = ( amelia.appts || {} ).soon;
			const far = ( amelia.appts || {} ).far;
			if ( amelia.skip ) {
				console.log( 'SKIP  D-03: ' + amelia.skip );
			} else {
				t.check( 'D-03 fixtures: two Amelia appointments and their customer', !! ( id( amelia.customer ) && soon && far ), JSON.stringify( amelia ).slice( 0, 200 ) );
			}
			if ( soon && far ) {
				// A browser nine hours from the site: Tokyo, unless the site is there.
				const siteOff = await page.evaluate( () => window.MINN.gmtOffset );
				// Without the zone's name the client knows only today's offset,
				// which is an hour off for a date across a DST change.
				const bootZone = await page.evaluate( () => window.MINN.timezone || '' );
				t.check( 'D-03 the boot payload names the site\'s timezone', bootZone === amelia.tz, bootZone + ' vs ' + amelia.tz );
				const zone = 9 === siteOff ? 'America/Los_Angeles' : 'Asia/Tokyo';
				const inZone = ( iso, o ) => new Date( iso ).toLocaleString( 'en-US', Object.assign( { timeZone: zone }, o ) );
				tzCtx = await browser.newContext( { ignoreHTTPSErrors: true, storageState: loadAuthState() || undefined, timezoneId: zone } );
				const tp = await tzCtx.newPage();
				tp.on( 'pageerror', ( e ) => errors.push( 'pageerror (tz): ' + e.message ) );
				const head = async ( p, aid ) => {
					await p.goto( `${ BASE }/minn-admin/amelia/${ aid }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await p.waitForSelector( '.minn-booking-page .minn-order-customer', { timeout: 60000 } );
					return p.evaluate( () => ( document.querySelector( '.minn-booking-page .minn-order-page-head' ) || {} ).textContent || '' );
				};
				const browserTime = inZone( soon.iso, { hour: 'numeric', minute: '2-digit' } );
				const h = await head( tp, soon.id );
				t.check( `D-03: a booking page read from ${ zone } shows the site's time, not the browser's`,
					flat( h ).includes( flat( soon.time ) ) && ! flat( h ).includes( flat( browserTime ) ),
					JSON.stringify( { site: soon.time, browser: browserTime, head: h.replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) } ) );

				// The list's absolute date (past a week out) is the site's day.
				await tp.goto( `${ BASE }/minn-admin/amelia`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await tp.waitForSelector( '.minn-table-row, .minn-empty', { timeout: 60000 } );
				if ( await tp.$( '#minn-surface-search' ) ) {
					await tp.fill( '#minn-surface-search', email );
					await tp.waitForFunction( ( n ) => {
						const tbl = document.querySelector( '.minn-table' );
						return tbl && ! tbl.classList.contains( 'minn-busy' ) && [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( n ) );
					}, 'Probe ' + suffix, { timeout: 60000 } ).catch( () => {} );
				}
				const cell = await tp.evaluate( ( iso ) => {
					const c = document.querySelector( `.minn-table-row [title="${ iso }"]` );
					return c ? c.textContent.trim() : null;
				}, far.iso );
				const browserDay = inZone( far.iso, { month: 'short', day: 'numeric' } );
				t.check( 'D-03: the booking list dates a far booking by the site\'s day',
					null !== cell && cell.startsWith( far.day ) && ( browserDay === far.day || ! cell.startsWith( browserDay ) ),
					JSON.stringify( { site: far.day, browser: browserDay, cell } ) );

				// Once the boot payload names the zone, a time across a DST
				// change is right as well (the offset alone is the one in force now).
				if ( amelia.tz && ! /^[+-]/.test( amelia.tz ) && 'UTC' !== amelia.tz ) {
					const tb = await tzCtx.newPage();
					tb.on( 'pageerror', ( e ) => errors.push( 'pageerror (tz name): ' + e.message ) );
					await tb.addInitScript( ( tz ) => {
						let v;
						Object.defineProperty( window, 'MINN', {
							configurable: true,
							get: () => v,
							set: ( x ) => {
								if ( x && 'object' === typeof x && ! x.timezone ) x.timezone = tz;
								v = x;
							},
						} );
					}, amelia.tz );
					const hb = await head( tb, far.id );
					const farBrowser = inZone( far.iso, { hour: 'numeric', minute: '2-digit' } );
					t.check( 'D-03: with the zone named in the payload, a time across a DST change is the site\'s',
						flat( hb ).includes( flat( far.time ) ) && ! flat( hb ).includes( flat( farBrowser ) ),
						JSON.stringify( { site: far.time, browser: farBrowser, head: hb.replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) } ) );
					await tb.close();
				}
				await tp.close();
			}
		} else if ( want( 'tz' ) ) {
			console.log( 'SKIP  D-03: Amelia is not active on this site' );
		}

		/* ===== D-05 · a list waiting on a tab change ===== */
		if ( want( 'busy' ) && surfaces.includes( 'wp-crontrol' ) ) {
			await page.goto( BASE + '/minn-admin/wp-crontrol', { waitUntil: 'domcontentloaded', timeout: 90000 } );
			await page.waitForSelector( '.minn-table-row .minn-row-more', { timeout: 60000 } );
			await page.waitForSelector( '[data-stab="once"]', { timeout: 30000 } );
			await page.waitForTimeout( 800 );
			let hold = false;
			const isList = ( u ) => /crontrol\/events(?:[?&]|%3F|$)/.test( u.href );
			const onList = async ( route ) => {
				if ( hold && 'GET' === route.request().method() ) await new Promise( ( r ) => setTimeout( r, 6000 ) );
				await route.continue().catch( () => {} );
			};
			await page.route( isList, onList );
			try {
				hold = true;
				await page.click( '[data-stab="once"]' );
				await page.waitForTimeout( 300 );
				const mid = await page.evaluate( async () => {
					const tbl = document.querySelector( '.minn-table' );
					const more = tbl && tbl.querySelector( '.minn-row-more' );
					const box = tbl && tbl.querySelector( '.minn-cb' );
					if ( more ) more.focus();
					const moreFocused = !! more && document.activeElement === more;
					if ( box ) box.focus();
					const boxFocused = !! box && document.activeElement === box;
					let peak = 0;
					for ( let i = 0; i < 12; i++ ) {
						peak = Math.max( peak, parseFloat( getComputedStyle( tbl ).opacity ) );
						await new Promise( ( r ) => setTimeout( r, 130 ) );
					}
					return { busy: !! tbl && tbl.classList.contains( 'minn-busy' ), rows: document.querySelectorAll( '.minn-table-row' ).length, moreFocused, boxFocused, peak: Math.round( peak * 100 ) / 100, ariaBusy: tbl && tbl.getAttribute( 'aria-busy' ) };
				} );
				t.check( 'D-05 control: the old rows stay on screen, marked busy, while the new tab loads', mid.busy && mid.rows > 0, JSON.stringify( mid ) );
				t.check( 'D-05: the waiting rows cannot take keyboard focus (their ⋯ menu or checkbox)', ! mid.moreFocused && ! mid.boxFocused, JSON.stringify( mid ) );
				t.check( 'D-05: the waiting list stays dim for the whole wait (never back to full strength)', mid.peak > 0 && mid.peak <= 0.5, JSON.stringify( mid ) );
				hold = false;
				await page.waitForFunction( () => {
					const tbl = document.querySelector( '.minn-table' );
					return tbl && ! tbl.classList.contains( 'minn-busy' );
				}, null, { timeout: 60000 } );
				const after = await page.evaluate( () => {
					const tbl = document.querySelector( '.minn-table' );
					const more = tbl && tbl.querySelector( '.minn-row-more' );
					if ( more ) more.focus();
					return { active: !! document.querySelector( '[data-stab="once"].active' ), inert: !! ( tbl && tbl.inert ), focusable: ! more || document.activeElement === more };
				} );
				t.check( 'D-05 control: the new rows are live once they land', after.active && ! after.inert && after.focusable, JSON.stringify( after ) );
			} finally {
				hold = false;
				await page.unroute( isList, onList ).catch( () => {} );
			}
		} else if ( want( 'busy' ) ) {
			console.log( 'SKIP  D-05: WP Crontrol is not active on this site' );
		}

		/* ===== Detail rows, cells and pills (mail logs, Submission panels, vendor statuses) ===== */
		if ( want( 'rows' ) && surfaces.includes( 'wp-crontrol' ) ) {
			// Mocked events, list and detail, carrying the shapes the mail, form,
			// booking and log adapters send: an ISO instant, a "Name <address>"
			// recipient, markup that must stay text, underscored statuses and
			// labels the server already wrote, an on/off boolean with no
			// status, and a bare site-local time from the other DST half.
			const janNext = ( new Date().getFullYear() + 1 ) + '-01-15T09:00:00';
			const base = { schedule: 'once', interval: '—', date: '2026-10-09T19:44:00Z', can_run: false, can_pause: false, can_resume: false, can_delete: false };
			const items = [
				Object.assign( {}, base, { id: 'demo-bugs-probe', hook: 'demo_bugs_probe', schedule: 'Nadia Petrov <nadia@example.com>', interval: '<img src=x onerror="window.__minnCellXss=1">plain', status: 'payment_pending' } ),
				Object.assign( {}, base, { id: 'demo-bugs-probe-2', hook: 'demo_bugs_probe_2', status: 'Failed login (unknown user)' } ),
				Object.assign( {}, base, { id: 'demo-bugs-probe-3', hook: 'demo_bugs_probe_3', enabled: true, starts: janNext, recipient: 'Nadia Petrov <nadia@example.com>' } ),
				Object.assign( {}, base, { id: 'demo-bugs-probe-4', hook: 'demo_bugs_probe_4', status: 'awaiting_customer_confirmation' } ),
				Object.assign( {}, base, { id: 'demo-bugs-probe-5', hook: 'demo_bugs_probe_5', status: 'Signed in' } ),
				Object.assign( {}, base, { id: 'demo-bugs-probe-6', hook: 'Ops desk <ops@example.com>', status: 'sent' } ),
			];
			const detail = { sections: [ { title: 'Event', rows: [
				{ label: 'Sent', value: '2026-10-09T19:44:00Z' },
				{ label: 'Logged', value: '2026-10-09T15:44:00-04:00' },
				{ label: 'Note', value: 'Sent 2026-10-09T19:44:00Z' },
			] } ] };
			const isMock = ( u ) => /crontrol\/events/.test( decodeURIComponent( u.href ) );
			const onMock = ( route ) => {
				const u = decodeURIComponent( route.request().url() );
				if ( 'GET' !== route.request().method() ) return route.abort();
				// The on/off event has no detail route answer: the dialog falls
				// back to the row's own fields, as a collection without one does.
				if ( /crontrol\/events\/demo-bugs-probe-3/.test( u ) ) return route.fulfill( { status: 404, contentType: 'application/json', body: JSON.stringify( { code: 'not_found', message: 'Not found', data: { status: 404 } } ) } );
				if ( /crontrol\/events\/demo-bugs-probe/.test( u ) ) return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( detail ) } );
				return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { items, total: items.length } ) } );
			};
			const rowOf = ( hook ) => `[data-sitem="${ items.findIndex( ( i ) => i.hook === hook ) }"]`;
			await page.route( isMock, onMock );
			try {
				await page.goto( BASE + '/minn-admin/wp-crontrol', { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( 'demo_bugs_probe_5' ) ), null, { timeout: 60000 } );
				const cells = await page.evaluate( () => {
					const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
					const row = ( h ) => rows.find( ( r ) => r.querySelector( '.minn-row-title' ) && r.querySelector( '.minn-row-title' ).textContent.trim() === h );
					const one = row( 'demo_bugs_probe' );
					const two = row( 'demo_bugs_probe_2' );
					const pill = row( 'demo_bugs_probe_4' ).querySelector( '.minn-status' );
					const cell = pill.parentElement;
					return {
						cells: [ ...one.children ].map( ( d ) => d.textContent.trim() ),
						img: !! one.querySelector( 'img' ),
						xss: !! window.__minnCellXss,
						pill1: one.querySelector( '.minn-status' ).textContent.trim(),
						pill2: two.querySelector( '.minn-status' ).textContent.trim(),
						pill5: row( 'demo_bugs_probe_5' ).querySelector( '.minn-status' ).textContent.trim(),
						title6: !! row( 'Ops desk <ops@example.com>' ),
						spill: Math.round( pill.getBoundingClientRect().right - cell.getBoundingClientRect().right ),
					};
				} );
				t.check( 'A text cell keeps the address of a recipient written Name <address>', cells.cells.includes( 'Nadia Petrov <nadia@example.com>' ), JSON.stringify( cells.cells ) );
				t.check( 'control: a text cell still renders markup as text', ! cells.img && ! cells.xss && cells.cells.includes( 'plain' ), JSON.stringify( cells ) );
				t.check( 'An underscored vendor status reads as words', 'Payment pending' === cells.pill1, cells.pill1 );
				t.check( 'A label the server already wrote shows as sent, not re-cased', 'Failed login (unknown user)' === cells.pill2 && 'Signed in' === cells.pill5, JSON.stringify( [ cells.pill2, cells.pill5 ] ) );
				t.check( 'A title cell keeps the address of a "Name <address>" value', cells.title6, String( cells.title6 ) );
				t.check( 'A long status pill stays inside its column', cells.spill <= 1, JSON.stringify( { spill: cells.spill } ) );

				await page.click( rowOf( 'demo_bugs_probe' ) );
				await page.waitForFunction( () => /Note/.test( ( document.querySelector( '#minn-overlays .minn-modal' ) || {} ).textContent || '' ), null, { timeout: 30000 } );
				const rows = await page.evaluate( () => {
					const out = {};
					document.querySelectorAll( '#minn-overlays .minn-side-row' ).forEach( ( r ) => {
						const k = r.querySelector( '.minn-side-key' );
						if ( k ) out[ k.textContent.trim() ] = r.textContent.replace( k.textContent, '' ).trim();
					} );
					// The site's clock at its offset (an October instant: today's offset).
					const ms = Date.parse( '2026-10-09T19:44:00Z' ) + window.MINN.gmtOffset * 3600000;
					out.want = new Date( ms ).toLocaleString( 'en-US', { timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'short' } );
					return out;
				} );
				t.check( 'D-03: a detail row holding an ISO instant reads on the site\'s clock', flat( rows.Sent ) === flat( rows.want ) && flat( rows.Logged ) === flat( rows.want ), JSON.stringify( rows ) );
				t.check( 'control: text that merely holds a date stays as sent', 'Sent 2026-10-09T19:44:00Z' === rows.Note, JSON.stringify( rows ) );
				await page.keyboard.press( 'Escape' );
				await page.waitForFunction( () => ! document.querySelector( '#minn-overlays .minn-modal' ), null, { timeout: 10000 } ).catch( () => {} );

				await page.click( rowOf( 'demo_bugs_probe_3' ) );
				await page.waitForFunction( () => {
					const m = document.querySelector( '#minn-overlays .minn-modal' );
					return m && ! m.querySelector( '.minn-loading' ) && /demo_bugs_probe_3/.test( m.textContent );
				}, null, { timeout: 30000 } );
				const onoff = await page.evaluate( () => {
					const m = document.querySelector( '#minn-overlays .minn-modal' );
					const head = m.querySelector( '.minn-modal-head' );
					const keys = [ ...m.querySelectorAll( '.minn-side-key' ) ].map( ( k ) => k.textContent.trim() );
					const val = ( label ) => {
						const k = [ ...m.querySelectorAll( '.minn-side-key' ) ].find( ( x ) => x.textContent.trim() === label );
						return k ? k.parentElement.textContent.replace( k.textContent, '' ).trim() : null;
					};
					return { pill: ( ( head && head.querySelector( '.minn-status' ) ) || {} ).textContent || '', keys, starts: val( 'Starts' ), recipient: val( 'Recipient' ), tz: window.MINN.timezone || '' };
				} );
				t.check( 'An on/off boolean with no status is the dialog\'s pill, not an "Enabled: true" row', 'Active' === onoff.pill.trim() && ! onoff.keys.includes( 'Enabled' ), JSON.stringify( onoff ) );
				t.check( 'A detail value keeps the address of a "Name <address>" recipient', 'Nadia Petrov <nadia@example.com>' === onoff.recipient, String( onoff.recipient ) );
				// A bare stamp is the site's wall clock, whatever the offset that day.
				if ( onoff.tz ) {
					t.check( 'A bare site-local time from the other DST half reads as stored', /·.*9:00\s*AM/.test( onoff.starts || '' ) && ! /8:00\s*AM/.test( onoff.starts || '' ), String( onoff.starts ) );
				}
				await page.keyboard.press( 'Escape' );
			} finally {
				await page.unroute( isMock, onMock ).catch( () => {} );
			}
		} else if ( want( 'rows' ) ) {
			console.log( 'SKIP  rows: WP Crontrol is not active on this site' );
		}

		/* ===== A stay's day, read from a browser west of the site ===== */
		if ( want( 'day' ) && surfaces.includes( 'jet-booking' ) ) {
			const settings = await api( 'wp/v2/settings?_fields=timezone' );
			const tz = settings.body && settings.body.timezone;
			const siteOff = await page.evaluate( () => window.MINN.gmtOffset );
			if ( tz && siteOff > -9 ) {
				// The site's midnight with that day's own offset, as JetBooking sends it.
				const offAt = ( day ) => {
					const name = new Intl.DateTimeFormat( 'en-US', { timeZone: tz, timeZoneName: 'longOffset' } ).formatToParts( new Date( day + 'T12:00:00Z' ) ).find( ( p ) => 'timeZoneName' === p.type ).value;
					const m = name.match( /GMT([+-]\d\d:\d\d)/ );
					return m ? m[ 1 ] : '+00:00';
				};
				const fakeId = 990000 + Math.floor( Math.random() * 9000 );
				const stay = {
					kind: 'booking', status: 'completed', title: 'Demo stay', adminUrl: '',
					customer: { name: 'Day Probe', email: 'day-probe@example.com', phone: '' },
					booking: { allDay: true, nights: 3, starts: '2027-07-15T00:00:00' + offAt( '2027-07-15' ), ends: '2027-07-18T00:00:00' + offAt( '2027-07-18' ) },
					sections: [ { title: 'Customer', rows: [ { label: 'Name', value: 'Day Probe' } ] } ],
				};
				const westCtx = await browser.newContext( { ignoreHTTPSErrors: true, storageState: loadAuthState() || undefined, timezoneId: 'Pacific/Honolulu' } );
				try {
					const wpg = await westCtx.newPage();
					wpg.on( 'pageerror', ( e ) => errors.push( 'pageerror (west): ' + e.message ) );
					const isStay = ( u ) => new RegExp( 'jet-booking/bookings/' + fakeId ).test( decodeURIComponent( u.href ) );
					await wpg.route( isStay, ( route ) => ( 'GET' === route.request().method()
						? route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( stay ) } )
						: route.abort() ) );
					await wpg.goto( `${ BASE }/minn-admin/jet-booking/${ fakeId }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await wpg.waitForSelector( '.minn-booking-page .minn-order-customer', { timeout: 60000 } );
					const h = await wpg.evaluate( () => ( document.querySelector( '.minn-booking-page .minn-order-page-head' ) || {} ).textContent || '' );
					t.check( 'A stay\'s check-in and check-out read as the site\'s days in a browser west of it',
						h.includes( 'July 15, 2027' ) && h.includes( 'July 18, 2027' ) && ! h.includes( 'July 14' ),
						h.replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) );
				} finally {
					await westCtx.close().catch( () => {} );
				}
			} else {
				console.log( 'SKIP  day: the site has no timezone name, or sits at or west of Honolulu' );
			}
		} else if ( want( 'day' ) ) {
			console.log( 'SKIP  day: JetBooking is not active on this site' );
		}

		/* ===== An activity event's IP, keyed by where it came from ===== */
		if ( want( 'ip' ) && surfaces.includes( 'wp-activity-log' ) ) {
			// Simple History's shape (ip_addresses keyed _server_remote_addr),
			// on a mocked activity-log event with no IP row of its own.
			const ev = { id: 9900001, message: 'Demo bugs IP probe', username: 'demo-probe', severity: 'info', date: '2026-10-09T19:44:00Z', ip_addresses: { _server_remote_addr: '203.0.113.9' } };
			const isEv = ( u ) => /wsal\/events/.test( decodeURIComponent( u.href ) );
			const onEv = ( route ) => {
				const u = decodeURIComponent( route.request().url() );
				if ( 'GET' !== route.request().method() ) return route.abort();
				if ( /wsal\/events\/9900001/.test( u ) ) return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { sections: [ { title: 'Event', rows: [ { label: 'Message', value: ev.message } ] } ] } ) } );
				return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { items: [ ev ], total: 1 } ) } );
			};
			await page.route( isEv, onEv );
			try {
				await page.goto( BASE + '/minn-admin/wp-activity-log', { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( 'Demo bugs IP probe' ) ), null, { timeout: 60000 } );
				await page.click( '[data-sitem="0"]' );
				await page.waitForFunction( () => {
					const m = document.querySelector( '#minn-overlays .minn-modal' );
					return m && ! m.querySelector( '.minn-loading' ) && /Demo bugs IP probe/.test( m.textContent );
				}, null, { timeout: 30000 } );
				const txt = await page.evaluate( () => document.querySelector( '#minn-overlays .minn-modal' ).textContent.replace( /\s+/g, ' ' ) );
				t.check( 'An activity event shows its IP address, not the key it is filed under', txt.includes( '203.0.113.9' ) && ! txt.includes( '_server_remote_addr' ), ( txt.match( /IP [\w.:]+/g ) || [ txt.slice( 0, 120 ) ] ).join( ' | ' ) );
				await page.keyboard.press( 'Escape' );
			} finally {
				await page.unroute( isEv, onEv ).catch( () => {} );
			}
		} else if ( want( 'ip' ) ) {
			console.log( 'SKIP  ip: WP Activity Log is not active on this site' );
		}

		/* ===== A settings tab that fails to load keeps the tab bar ===== */
		if ( want( 'settings' ) ) {
			const pick = await page.evaluate( () => {
				const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.settings && ( x.settings.tabs || [] ).length > 1
					&& ! String( x.settings.route || '' ).includes( '{id}' ) && ! x.settings.tabs.some( ( t ) => String( t.route || '' ).includes( '{id}' ) ) );
				return s ? { id: s.id, list: !! s.collection, tabs: s.settings.tabs.map( ( t ) => ( { id: t.id, path: ( t.route || s.settings.route ).replace( '{tab}', t.id ) } ) ) } : null;
			} );
			if ( pick ) {
				const bad = pick.tabs[ 1 ];
				const esc = ( x ) => x.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
				const badRe = new RegExp( esc( bad.path ) + '(?:[?&]|$)' );
				const isBad = ( u ) => badRe.test( decodeURIComponent( u.href ) );
				// Only that tab's GET is answered (a 404); the others load for real.
				const onBad = ( route ) => ( 'GET' === route.request().method()
					? route.fulfill( { status: 404, contentType: 'application/json', body: JSON.stringify( { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } } ) } )
					: route.abort() );
				await page.route( isBad, onBad );
				try {
					await page.goto( `${ BASE }/minn-admin/${ pick.id }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
					if ( pick.list ) {
						await page.waitForSelector( '[data-sview="settings"]', { timeout: 60000 } );
						await page.click( '[data-sview="settings"]' );
					}
					await page.waitForSelector( `[data-ssettab="${ bad.id }"]`, { timeout: 60000 } );
					await page.waitForFunction( () => ! document.querySelector( '#minn-view .minn-loading' ), null, { timeout: 60000 } );
					await page.click( `[data-ssettab="${ bad.id }"]` );
					await page.waitForFunction( () => !! document.querySelector( '#minn-view .minn-empty' ) || ! document.querySelector( '#minn-view .minn-loading' ), null, { timeout: 60000 } );
					await page.waitForTimeout( 500 );
					const failed = await page.evaluate( () => ( {
						tabs: document.querySelectorAll( '#minn-view [data-ssettab]' ).length,
						error: ( ( document.querySelector( '#minn-view .minn-empty' ) || {} ).textContent || '' ).trim().slice( 0, 90 ),
					} ) );
					t.check( `A settings tab that fails to load (${ pick.id }) says so under the tab bar, which stays`, failed.tabs === pick.tabs.length && /No route was found/.test( failed.error ), JSON.stringify( failed ) );
					let reopened = false;
					if ( failed.tabs ) {
						await page.click( `[data-ssettab="${ pick.tabs[ 0 ].id }"]` );
						reopened = await page.waitForFunction( ( id ) => {
							const on = document.querySelector( `#minn-view [data-ssettab="${ id }"].active` );
							return on && ! document.querySelector( '#minn-view .minn-loading' ) && ! document.querySelector( '#minn-view .minn-empty' );
						}, pick.tabs[ 0 ].id, { timeout: 60000 } ).then( () => true ).catch( () => false );
					}
					t.check( 'and another tab still opens from there', reopened, String( reopened ) );
				} finally {
					await page.unroute( isBad, onBad ).catch( () => {} );
				}
			} else {
				console.log( 'SKIP  settings: no surface here has more than one settings tab' );
			}
		}

		/* ===== N2 · an opened entry's list row follows the page ===== */
		if ( want( 'entry' ) ) {
			const pick = await page.evaluate( () => {
				const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.family === 'forms' && x.collection && x.collection.open
					&& /\/entry\/\{id\}$/.test( x.collection.open.route || '' ) && ( x.collection.detail || {} ).sectionsRoute
					&& ( x.collection.columns || [] ).some( ( c ) => 'pill' === c.format && 'is_read' !== c.key ) );
				if ( ! s ) return null;
				const c = s.collection;
				return { id: s.id, route: c.route, allRoute: c.allRoute || '', itemsKey: c.itemsKey || '', totalKey: c.totalKey || '', detail: c.detail.sectionsRoute, pill: c.columns.find( ( x ) => 'pill' === x.format && 'is_read' !== x.key ).key };
			} );
			if ( pick ) {
				const fakeId = 990000 + Math.floor( Math.random() * 9000 );
				const reEsc = ( x ) => x.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
				// The All tab may read its own route (Gravity Forms' gf/entries).
				const listRe = new RegExp( '(?:' + [ pick.route, pick.allRoute ].filter( Boolean ).map( ( r ) => reEsc( r ).replace( /\\\{tab\\\}/g, '[^/?&]+' ) ).join( '|' ) + ')(?:[?&]|$)' );
				const detailRe = new RegExp( reEsc( pick.detail.replace( '{id}', String( fakeId ) ) ) + '(?:[?&]|$)' );
				const row = { id: fakeId, form_id: 1, date_created: '2026-10-09 19:44:00', 1: 'Entry Probe', summary: 'Entry Probe', is_read: 0, status: 'unread', [ pick.pill ]: 'unread' };
				const listBody = pick.itemsKey ? { [ pick.itemsKey ]: [ row ], [ pick.totalKey || 'total' ]: 1 } : [ row ];
				const detailBody = { kind: 'entry', title: 'Probe form', status: 'read', sections: [
					{ title: 'Response', rows: [ { label: 'Name', value: 'Entry Probe' } ] },
					{ title: 'Submission', rows: [] },
				], item: { [ pick.pill ]: 'read', is_read: 1, status: 'read' } };
				const isMine = ( u ) => listRe.test( decodeURIComponent( u.href ) ) || detailRe.test( decodeURIComponent( u.href ) );
				const onMine = ( route ) => {
					if ( 'GET' !== route.request().method() ) return route.abort();
					const u = decodeURIComponent( route.request().url() );
					if ( detailRe.test( u ) ) return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( detailBody ) } );
					return route.fulfill( { status: 200, contentType: 'application/json', headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' }, body: JSON.stringify( listBody ) } );
				};
				await page.route( isMine, onMine );
				try {
					await page.goto( `${ BASE }/minn-admin/${ pick.id }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => /Entry Probe/.test( r.textContent ) ), null, { timeout: 120000 } );
					const pillOf = () => page.evaluate( () => {
						const r = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( x ) => /Entry Probe/.test( x.textContent ) );
						return r && r.querySelector( '.minn-status' ) ? r.querySelector( '.minn-status' ).textContent.trim() : null;
					} );
					const before = await pillOf();
					await page.evaluate( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( x ) => /Entry Probe/.test( x.textContent ) ).click() );
					await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 120000 } );
					await page.click( '#minn-ep-back' );
					await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => /Entry Probe/.test( r.textContent ) ), null, { timeout: 120000 } );
					const after = await pillOf();
					t.check( `N2: Back from an opened entry (${ pick.id }) shows its row as the page left it`, 'Unread' === before && 'Read' === after, JSON.stringify( { pill: pick.pill, before, after } ) );
				} finally {
					await page.unroute( isMine, onMine ).catch( () => {} );
				}
			} else {
				console.log( 'SKIP  entry: no forms surface here opens entries on their own page' );
			}
		}

		/* ===== I4 · a list date names the year the site counts ===== */
		if ( want( 'year' ) && surfaces.includes( 'wp-crontrol' ) ) {
			const tz = await page.evaluate( () => window.MINN.timezone || '' );
			const siteOff = await page.evaluate( () => window.MINN.gmtOffset );
			if ( tz && siteOff < 0 ) {
				// 10 pm on the site's last day of last year: already next year in Tokyo.
				const y = new Date().getFullYear() - 1;
				const off = ( new Intl.DateTimeFormat( 'en-US', { timeZone: tz, timeZoneName: 'longOffset' } ).formatToParts( new Date( `${ y }-12-31T12:00:00Z` ) ).find( ( x ) => 'timeZoneName' === x.type ).value.match( /[+-]\d\d:\d\d/ ) || [ '+00:00' ] )[ 0 ];
				const ev = { id: 'demo-bugs-year', hook: 'demo_bugs_year', schedule: 'once', interval: '—', status: 'scheduled', date: `${ y }-12-31T22:00:00${ off }` };
				const yCtx = await browser.newContext( { ignoreHTTPSErrors: true, storageState: loadAuthState() || undefined, timezoneId: 'Asia/Tokyo' } );
				try {
					const yp = await yCtx.newPage();
					yp.on( 'pageerror', ( e ) => errors.push( 'pageerror (year): ' + e.message ) );
					await yp.route( ( u ) => /crontrol\/events/.test( decodeURIComponent( u.href ) ), ( route ) => ( 'GET' === route.request().method()
						? route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { items: [ ev ], total: 1 } ) } )
						: route.abort() ) );
					await yp.goto( BASE + '/minn-admin/wp-crontrol', { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await yp.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( 'demo_bugs_year' ) ), null, { timeout: 60000 } );
					const cell = await yp.evaluate( ( iso ) => ( ( document.querySelector( `.minn-table-row [title="${ iso }"]` ) || {} ).textContent || '' ).trim(), ev.date );
					t.check( 'I4: a list date from the site\'s last year names that year, read from a browser already in the next', cell === `Dec 31, ${ y }`, cell );
				} finally {
					await yCtx.close().catch( () => {} );
				}
			} else {
				console.log( 'SKIP  year: the site has no zone name, or sits east of UTC' );
			}
		}

		/* ===== I5 · revision heatmap days are the site's ===== */
		if ( want( 'heat' ) ) {
			// The shipped functions, run with the browser clock in Tokyo and the
			// site in New York: a 10:30 pm revision belongs to that site day.
			const probe = `
				const fs = require( 'fs' ), vm = require( 'vm' );
				const src = fs.readFileSync( process.env.MINN_APP_JS, 'utf8' );
				const fn = ( n ) => { const a = src.indexOf( '\\tfunction ' + n + '(' ); return a < 0 ? '' : src.slice( a, src.indexOf( '\\n\\t}', a ) + 3 ); };
				const region = src.slice( src.indexOf( '\\tlet siteZoneFor' ), src.indexOf( '\\t// Absolute fallback for timeAgo' ) );
				const f = vm.runInNewContext( region + fn( 'parseWpDate' ) + fn( 'revisionDayKey' ) + '\\nrevisionDayKey;',
					{ B: { timezone: 'America/New_York', gmtOffset: -4 }, uiLocale: () => 'en-US', Intl, Date, String, Math, Object, Number, isNaN } );
				process.stdout.write( f( '2026-10-12T22:30:00' ) + '|' + f( '2026-10-13T02:30:00Z' ) );`;
			let got = '';
			try {
				got = execFileSync( process.execPath, [ '-e', probe ], { encoding: 'utf8', env: Object.assign( {}, process.env, { TZ: 'Asia/Tokyo', MINN_APP_JS: path.resolve( __dirname, '../assets/js/app.js' ) } ) } ).trim();
			} catch ( e ) {
				got = 'error: ' + String( e.stderr || e.message ).slice( 0, 160 );
			}
			t.check( 'I5: a late-evening revision sits in the site\'s day, not the browser\'s', '2026-10-12|2026-10-12' === got, got );
		}

		/* ===== I7 · a late settings failure for one item stays off another's ===== */
		if ( want( 'itemsettings' ) && surfaces.includes( 'gravity-forms' ) ) {
			const forms = await api( 'minn-admin/v1/gf/forms' );
			const list = Array.isArray( forms.body ) ? forms.body : ( ( forms.body || {} ).items || ( forms.body || {} ).forms || [] );
			// Two forms whose titles can't be mistaken for each other in a row.
			const named = list.filter( ( f ) => f && f.id && f.title );
			const a = named[ 0 ];
			const b = a && named.find( ( f ) => f !== a && ! f.title.includes( a.title ) && ! a.title.includes( f.title ) );
			if ( a && b ) {
				let release = null;
				const held = new Promise( ( r ) => { release = r; } );
				const aRe = new RegExp( `gf/forms/${ a.id }/settings/` );
				const isA = ( u ) => aRe.test( decodeURIComponent( u.href ) );
				const onA = async ( route ) => {
					if ( 'GET' !== route.request().method() ) return route.abort();
					await held;
					return route.fulfill( { status: 404, contentType: 'application/json', body: JSON.stringify( { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } } ) } );
				};
				const openSettings = async ( title ) => {
					await page.waitForFunction( ( x ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( x ) ), title, { timeout: 60000 } );
					await page.waitForFunction( () => { const t2 = document.querySelector( '.minn-table' ); return t2 && ! t2.classList.contains( 'minn-busy' ); }, null, { timeout: 60000 } );
					await page.evaluate( ( x ) => [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => r.textContent.includes( x ) )
						.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, clientX: 300, clientY: 300 } ) ), title );
					await page.waitForSelector( '.minn-ctx-menu', { timeout: 15000 } );
					await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( x ) => 'Form settings' === x.textContent.trim() ).click() );
				};
				await page.route( isA, onA );
				try {
					await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
					await page.click( '[data-sview="manage"]' );
					await openSettings( a.title );
					await page.waitForSelector( '#minn-view .minn-loading', { timeout: 20000 } );
					await page.click( '[data-sview="manage"]' );
					await openSettings( b.title );
					await page.waitForSelector( '[data-sset]', { timeout: 60000 } );
					release();
					await page.waitForTimeout( 2500 );
					const now = await page.evaluate( () => ( {
						form: !! document.querySelector( '#minn-view [data-sset]' ),
						error: !! document.querySelector( '#minn-view .minn-empty' ),
						meta: ( ( document.querySelector( '#minn-view .minn-toolbar-meta' ) || {} ).textContent || '' ).trim(),
					} ) );
					t.check( 'I7: a late failure for one form\'s settings leaves the next form\'s settings in place', now.form && ! now.error && now.meta.includes( b.title ), JSON.stringify( now ) );
				} finally {
					release();
					await page.unroute( isA, onA ).catch( () => {} );
				}
			} else {
				console.log( 'SKIP  itemsettings: fewer than two Gravity Forms forms here' );
			}
		}

		/* ===== I8 · a content row's dot survives the pill clip on a phone ===== */
		if ( want( 'dot' ) ) {
			await page.setViewportSize( { width: 390, height: 844 } );
			try {
				await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForSelector( '.minn-table-row .minn-row-status .minn-status', { timeout: 60000 } );
				// A row's pill with the unsaved dot as the row renders it, and a
				// label as long as a translation's ("Veröffentlicht").
				const fit = await page.evaluate( () => {
					const pill = document.querySelector( '.minn-table-row .minn-row-status .minn-status' );
					const words = pill.querySelector( '.minn-status-text' ) || [ ...pill.childNodes ].find( ( n ) => 3 === n.nodeType && n.textContent.trim() );
					words.textContent = 'Veröffentlicht';
					if ( ! pill.querySelector( '.minn-row-modified' ) ) pill.insertAdjacentHTML( 'beforeend', '<span class="minn-row-modified" aria-hidden="true"></span>' );
					const dot = pill.querySelector( '.minn-row-modified' ).getBoundingClientRect();
					const box = pill.getBoundingClientRect();
					const cell = pill.closest( '.minn-row-status' ).getBoundingClientRect();
					return { label: pill.textContent.trim(), dotRight: Math.round( dot.right ), pillRight: Math.round( box.right ), cellRight: Math.round( cell.right ), cellWidth: Math.round( cell.width ) };
				} );
				t.check( 'I8: on a phone the unsaved dot stays inside its pill, and the pill inside its column', fit.dotRight <= fit.pillRight && fit.pillRight <= fit.cellRight + 1, JSON.stringify( fit ) );
			} finally {
				await page.setViewportSize( { width: 1280, height: 720 } );
			}
		}

		/* ===== S15 · the copied system report carries the site name as text ===== */
		if ( want( 'report' ) ) {
			await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded', timeout: 90000 } );
			await page.waitForSelector( '#minn-sys-copy', { timeout: 90000 } );
			const copied = await page.evaluate( async () => {
				const was = window.MINN.site.name;
				window.MINN.site.name = 'Larkspur & Fern';
				let text = '';
				Object.defineProperty( navigator.clipboard, 'writeText', { configurable: true, value: async ( v ) => { text = v; } } );
				document.querySelector( '#minn-sys-copy' ).click();
				await new Promise( ( r ) => setTimeout( r, 400 ) );
				window.MINN.site.name = was;
				return text.split( '\n' )[ 0 ];
			} );
			t.check( 'S15: the copied system report names the site as text, not HTML', '# System report — Larkspur & Fern' === copied, copied );
		}

		/* ===== D-06 · the media preview is a dialog ===== */
		if ( want( 'media' ) ) {
			const made = await page.evaluate( async ( name ) => {
				const c = document.createElement( 'canvas' );
				c.width = 120;
				c.height = 80;
				const cx = c.getContext( '2d' );
				cx.fillStyle = '#3355ff';
				cx.fillRect( 0, 0, 120, 80 );
				const blob = await new Promise( ( res ) => c.toBlob( res, 'image/png' ) );
				const fd = new FormData();
				fd.append( 'file', blob, name + '.png' );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/media', { method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, body: fd } );
				return ( await r.json().catch( () => ( {} ) ) ).id;
			}, 'demo-bugs-media-' + suffix );
			mediaId = id( made );
			t.check( 'D-06 fixture: an image attachment', !! mediaId, String( made ) );
			if ( mediaId ) {
				await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForSelector( `[data-media="${ mediaId }"]`, { timeout: 60000 } );
				await page.click( `[data-media="${ mediaId }"]` );
				await page.waitForSelector( '#minn-overlays .minn-modal.media', { timeout: 30000 } );
				await page.waitForTimeout( 400 );
				const dlg = await page.evaluate( () => {
					const m = document.querySelector( '#minn-overlays .minn-modal.media' );
					const a = document.activeElement;
					return {
						role: m.getAttribute( 'role' ),
						modal: m.getAttribute( 'aria-modal' ),
						label: m.getAttribute( 'aria-label' ) || '',
						title: ( m.querySelector( '.minn-modal-title' ) || {} ).textContent || '',
						focusIn: m.contains( a ),
						onField: !! a && /^(INPUT|TEXTAREA|SELECT)$/.test( a.tagName ),
					};
				} );
				t.check( 'D-06: the media preview is a modal dialog named by its file', 'dialog' === dlg.role && 'true' === dlg.modal && !! dlg.label && dlg.label === dlg.title.trim(), JSON.stringify( dlg ) );
				t.check( 'D-06: opening it moves focus into it, not onto a field (← → still step)', dlg.focusIn && ! dlg.onField, JSON.stringify( dlg ) );
				if ( await page.$( '#minn-media-edit-image' ) ) {
					await page.click( '#minn-media-edit-image' );
					await page.waitForSelector( '#minn-overlays .minn-modal.media.wide #minn-imged-canvas', { timeout: 20000 } );
					const ed = await page.evaluate( () => {
						const m = document.querySelector( '#minn-overlays .minn-modal.media.wide' );
						return { role: m.getAttribute( 'role' ), modal: m.getAttribute( 'aria-modal' ), label: m.getAttribute( 'aria-label' ) || '' };
					} );
					t.check( 'D-06: the image editor in the same overlay is a modal dialog too', 'dialog' === ed.role && 'true' === ed.modal && /demo-bugs-media/.test( ed.label ), JSON.stringify( ed ) );
				}
				await page.keyboard.press( 'Escape' );
			}
		}

		/* ===== D-20 · a subscription's Orders and Customer cards ===== */
		const hasWcs = await page.evaluate( () => !! window.MINN.wcs );
		if ( want( 'subs' ) && hasWcs ) {
			const email = `demo-bugs-sub-${ suffix }@example.com`;
			const billing = { first_name: 'Bill', last_name: 'Payer', email, country: 'US' };
			const acct = 'Quinn Account' + suffix;
			const prod = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Demo bugs plant ' + suffix, type: 'simple', regular_price: '30.00', status: 'publish' } ) } );
			wc.product = id( prod.body && prod.body.id );
			const cust = await api( 'wc/v3/customers', { method: 'POST', body: JSON.stringify( { email, first_name: 'Quinn', last_name: 'Account' + suffix, billing } ) } );
			wc.customer = id( cust.body && cust.body.id );
			if ( wc.product && wc.customer ) {
				const parent = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( { status: 'completed', set_paid: true, customer_id: wc.customer, billing, line_items: [ { product_id: wc.product, quantity: 1 } ] } ) } );
				wc.parent = id( parent.body && parent.body.id );
			}
			if ( wc.parent ) {
				const sub = await api( 'wc/v3/subscriptions', { method: 'POST', body: JSON.stringify( { status: 'active', billing, customer_id: wc.customer, parent_id: wc.parent, billing_period: 'month', billing_interval: 1, line_items: [ { product_id: wc.product, quantity: 1 } ] } ) } );
				wc.sub = id( sub.body && sub.body.id );
			}
			if ( wc.sub ) {
				const out = evalPhp( `
					$s = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( ${ wc.sub } ) : null;
					$o = $s ? wcs_create_renewal_order( $s ) : null;
					echo wp_json_encode( array( 'renewal' => ( $o && ! is_wp_error( $o ) ) ? (int) $o->get_id() : 0 ) );
				` );
				wc.renewal = id( json( out ).renewal );
			}
			t.check( 'D-20 fixtures: a subscription with a parent order and a renewal', !! ( wc.sub && wc.renewal ), JSON.stringify( wc ) );
			if ( wc.sub && wc.renewal ) {
				await page.goto( `${ BASE }/minn-admin/subscriptions/${ wc.sub }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForSelector( `.minn-sub-orders [data-relorder="${ wc.renewal }"]`, { timeout: 60000 } );
				await page.waitForFunction( () => {
					const a = document.querySelector( '.minn-order-customer' );
					return a && ! /#\d+\s*$/.test( a.querySelector( '.minn-order-read' ).textContent.trim() );
				}, null, { timeout: 15000 } ).catch( () => {} );
				const cards = await page.evaluate( ( ids ) => {
					const row = ( oid ) => {
						const r = document.querySelector( `.minn-sub-orders [data-relorder="${ oid }"]` );
						return r ? { text: r.textContent.replace( /\s+/g, ' ' ).trim(), pill: !! r.querySelector( '.minn-status' ) } : null;
					};
					const read = document.querySelector( '.minn-order-customer .minn-order-read' );
					const subs = read ? [ ...read.querySelectorAll( '.minn-order-read-sub' ) ] : [];
					const acctLabel = subs.find( ( s ) => /Account/.test( s.textContent ) );
					return { parent: row( ids.parent ), renewal: row( ids.renewal ), account: acctLabel && acctLabel.nextElementSibling ? acctLabel.nextElementSibling.textContent.trim() : null };
				}, { parent: wc.parent, renewal: wc.renewal } );
				const dated = ( r ) => !! r && /(just now|min ago|\dh ago|\dd ago|in \d|[A-Z][a-z]{2} \d)/.test( r.text );
				t.check( 'D-20: the parent order row carries its status and total, beside "Initial"', !! cards.parent && cards.parent.pill && /Initial/.test( cards.parent.text ) && /30\.00/.test( cards.parent.text ), JSON.stringify( cards.parent ) );
				t.check( 'D-20: every order row carries its date', dated( cards.parent ) && dated( cards.renewal ) && cards.renewal.pill, JSON.stringify( { parent: cards.parent, renewal: cards.renewal } ) );
				t.check( 'D-20: the Customer card names the account, not its user id', cards.account === acct, JSON.stringify( { account: cards.account, want: acct } ) );
			}
		} else if ( want( 'subs' ) ) {
			console.log( 'SKIP  D-20: WooCommerce Subscriptions is not active on this site' );
		}

		/* ===== D-21 · the Members card ===== */
		if ( want( 'members' ) && surfaces.includes( 'woocommerce-memberships' ) ) {
			const plans = await api( 'minn-admin/v1/wcm/plans?per_page=20' );
			const plan = ( ( plans.body || {} ).items || [] )[ 0 ];
			if ( plan && plan.id ) {
				await page.goto( `${ BASE }/minn-admin/membership-plans/${ plan.id }`, { waitUntil: 'domcontentloaded', timeout: 90000 } );
				await page.waitForSelector( '[data-pcard="members"] .minn-side-row', { timeout: 120000 } );
				const rule = await page.evaluate( () => {
					const meta = document.querySelector( '[data-pcard="members"] .minn-modal-meta' );
					const cs = getComputedStyle( meta );
					return { border: cs.borderBottomWidth + ' ' + cs.borderBottomStyle, rows: meta.querySelectorAll( '.minn-side-row' ).length };
				} );
				t.check( 'D-21: the Members card\'s last row sits on no divider', /^0px|none$/.test( rule.border ) || / none$/.test( rule.border ), JSON.stringify( rule ) );
			} else {
				console.log( 'SKIP  D-21: no membership plan on this site' );
			}
		} else if ( want( 'members' ) ) {
			console.log( 'SKIP  D-21: WooCommerce Memberships is not active on this site' );
		}

		/* ===== S19 · a timezone saved in Settings moves the app's clock ===== */
		if ( want( 'tzsave' ) ) {
			const vals = await api( 'wp/v2/settings' );
			const was = vals.body && vals.body.timezone;
			// Zones every browser lists by this name, with no DST to shift them.
			const pickZone = 'Asia/Tokyo' === was ? 'Asia/Seoul' : 'Asia/Tokyo';
			const wantOff = 9;
			// The save is answered here: the site's own timezone never changes.
			const isSave = ( u ) => /wp\/v2\/settings(?:[?&]|$)/.test( decodeURIComponent( u.href ) );
			const onSave = ( route ) => {
				if ( 'POST' !== route.request().method() ) return route.continue();
				let sent = {};
				try { sent = JSON.parse( route.request().postData() || '{}' ); } catch ( e ) {}
				return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( Object.assign( {}, vals.body, sent ) ) } );
			};
			if ( vals.status === 200 && was !== undefined ) {
				await page.route( isSave, onSave );
				try {
					await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded', timeout: 90000 } );
					await page.waitForSelector( '[data-key="timezone"]', { timeout: 120000 } );
					await page.fill( '[data-key="timezone"]', pickZone );
					const posted = page.waitForResponse( ( r ) => 'POST' === r.request().method() && isSave( new URL( r.url() ) ), { timeout: 30000 } );
					await page.click( '#minn-save-settings' );
					const res = await posted;
					let sent = {};
					try { sent = JSON.parse( res.request().postData() || '{}' ); } catch ( e ) {}
					await page.waitForTimeout( 600 );
					const boot = await page.evaluate( () => ( { tz: window.MINN.timezone, off: window.MINN.gmtOffset } ) );
					t.check( 'S19: saving a new timezone moves the app\'s clock without a reload', sent.timezone === pickZone && boot.tz === pickZone && boot.off === wantOff, JSON.stringify( { sent: sent.timezone, boot } ) );
				} finally {
					await page.unroute( isSave, onSave ).catch( () => {} );
				}
			} else {
				console.log( 'SKIP  tzsave: this account cannot read the site settings' );
			}
		}

	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( tzCtx ) await tzCtx.close().catch( () => {} );
		if ( amelia && id( amelia.customer ) ) {
			const ids = Object.values( amelia.appts || {} ).map( ( a ) => id( a.id ) ).filter( Boolean );
			evalPhp( `
				global $wpdb;
				$appts = $wpdb->prefix . 'amelia_appointments';
				$bookings = $wpdb->prefix . 'amelia_customer_bookings';
				$users = $wpdb->prefix . 'amelia_users';
				foreach ( array( ${ ids.join( ', ' ) } ) as $aid ) {
					if ( is_int( $aid ) && $aid > 1 && 'minn-demo-bugs-client' === $wpdb->get_var( $wpdb->prepare( "SELECT internalNotes FROM {$appts} WHERE id = %d", $aid ) ) ) {
						$wpdb->delete( $bookings, array( 'appointmentId' => $aid ) );
						$wpdb->delete( $appts, array( 'id' => $aid ) );
					}
				}
				$cid = ${ id( amelia.customer ) };
				if ( is_int( $cid ) && $cid > 1 ) {
					$wpdb->delete( $users, array( 'id' => $cid, 'type' => 'customer', 'email' => 'demo-bugs-tz-${ suffix }@example.com' ) );
				}
			` );
		}
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( wc.sub ) await api( `wc/v3/subscriptions/${ wc.sub }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( wc.renewal ) await api( `wc/v3/orders/${ wc.renewal }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( wc.parent ) await api( `wc/v3/orders/${ wc.parent }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( wc.customer ) await api( `wc/v3/customers/${ wc.customer }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( wc.product ) await api( `wc/v3/products/${ wc.product }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
