/**
 * CleanTalk spam-user cleanup on the Users list (adapters/cleantalk.php).
 *
 * CleanTalk's scan stays on their screen. This suite covers what Minn
 * owns: the Users Spam tab of already-marked accounts, Not spam (clears
 * ct_marked_as_spam the way their Approve does), and delete (account +
 * posts, no reassignment). A fixture user is marked by writing their
 * meta; the cloud scan is not run.
 *
 * CleanTalk is installed-inactive at rest (Antispam Bee is the resident
 * spam provider). Activate for the run and restore after.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'cleantalk-spam-users' );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	const setPlugin = ( status ) => page.evaluate( async ( s ) => {
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/cleantalk-spam-protect/cleantalk', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return ( await r.json() ).status;
		} catch ( e ) {
			return 'dropped';
		}
	}, status );

	const plugin = await api( 'wp/v2/plugins/cleantalk-spam-protect/cleantalk?_fields=status' );
	if ( plugin.status === 404 ) {
		t.check( 'CleanTalk is installed', false, 'skip — plugin missing' );
		await t.done( browser, errors );
		return;
	}
	const wasActive = plugin.body && plugin.body.status === 'active';

	const wpMeta = ( id, key, val ) => {
		try {
			execFileSync( 'wp', [ `--path=${ WP }`, 'user', 'meta', 'update', String( id ), key, String( val ) ], { stdio: 'pipe' } );
			return true;
		} catch ( e ) {
			return false;
		}
	};

	const made = [];
	try {
		if ( ! wasActive ) {
			await setPlugin( 'active' );
			await page.waitForTimeout( 1200 );
			await page.reload( { waitUntil: 'domcontentloaded' } );
		}

		const boot = await page.evaluate( () => window.MINN && window.MINN.spamUsers );
		t.check( 'boot carries spamUsers when CleanTalk is active',
			!! boot && typeof boot.count === 'number' && /ct_check_users/.test( boot.checkUrl || '' ),
			JSON.stringify( boot ) );

		const suffix = Date.now().toString( 36 );
		const mk = async ( tag ) => {
			const email = `suite-ctspam-${ tag }-${ suffix }@example.com`;
			const r = await api( 'wp/v2/users', {
				method: 'POST',
				body: JSON.stringify( {
					username: `ctspam${ tag }${ suffix }`,
					email,
					password: 'TempPass123!x',
					roles: [ 'subscriber' ],
				} ),
			} );
			const id = r.body && r.body.id;
			if ( id ) made.push( id );
			return { id, email };
		};

		const victim = await mk( 'a' );
		const doomed = await mk( 'b' );
		t.check( 'fixture users created', !! victim.id && !! doomed.id, `${ victim.id } / ${ doomed.id }` );
		if ( ! victim.id || ! doomed.id ) throw new Error( 'no fixtures' );

		t.check( 'victim marked as spam', wpMeta( victim.id, 'ct_marked_as_spam', '1' ), '' );
		t.check( 'doomed marked as spam', wpMeta( doomed.id, 'ct_marked_as_spam', '1' ), '' );

		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-usess]', { timeout: 20000 } );
		t.check( 'Spam session tab is on the Users list', !! ( await page.$( '[data-usess="spam"]' ) ), '' );

		await page.click( '[data-usess="spam"]' );
		await page.waitForFunction( () => {
			const tab = document.querySelector( '[data-usess="spam"]' );
			return tab && tab.classList.contains( 'active' ) && ! document.querySelector( '.minn-table.minn-busy' );
		}, null, { timeout: 15000 } ).catch( () => null );

		await page.fill( '#minn-user-search', victim.email );
		const onList = await page.waitForFunction( ( id ) =>
			!! document.querySelector( `.minn-table-row[data-user="${ id }"]` ), victim.id, { timeout: 15000 } )
			.then( () => true ).catch( () => false );
		t.check( 'marked user appears on the Spam tab', onList, String( victim.id ) );
		t.check( 'Check for spam deep-link is in the toolbar', !! ( await page.$( '#minn-ct-check-users' ) ), '' );

		if ( onList ) {
			const row = await page.$( `.minn-table-row[data-user="${ victim.id }"]` );
			const box = await row.boundingBox();
			await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2, { button: 'right' } );
			await page.waitForSelector( '.minn-ctx-menu, .minn-new-menu', { timeout: 8000 } );
			const menu = await page.evaluate( () => {
				const root = document.querySelector( '.minn-ctx-menu, .minn-new-menu' );
				const labels = [ ...root.querySelectorAll( 'button, a' ) ].map( ( n ) => n.textContent.trim() );
				return {
					notSpam: labels.some( ( s ) => /Not spam/i.test( s ) ),
					del: labels.some( ( s ) => /Delete spam account/i.test( s ) ),
					reassign: labels.some( ( s ) => /^Delete user/i.test( s ) ),
				};
			} );
			t.check( 'context menu offers Not spam and Delete spam account',
				menu.notSpam && menu.del && ! menu.reassign, JSON.stringify( menu ) );

			await page.evaluate( () => {
				const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-new-menu button' ) ]
					.find( ( n ) => /Not spam/i.test( n.textContent ) );
				if ( btn ) btn.click();
			} );
			const gone = await page.waitForFunction( ( id ) =>
				! document.querySelector( `.minn-table-row[data-user="${ id }"]` ), victim.id, { timeout: 15000 } )
				.then( () => true ).catch( () => false );
			t.check( 'Not spam removes the row without a reload', gone, '' );
			const still = await api( `wp/v2/users/${ victim.id }?context=edit` );
			t.check( 'account remains after Not spam', still.status === 200, String( still.status ) );
		}

		await page.fill( '#minn-user-search', doomed.email );
		const doomedRow = await page.waitForFunction( ( id ) =>
			!! document.querySelector( `.minn-table-row[data-user="${ id }"]` ), doomed.id, { timeout: 15000 } )
			.then( () => true ).catch( () => false );
		t.check( 'second marked user is on the Spam tab', doomedRow, String( doomed.id ) );
		if ( doomedRow ) {
			const row = await page.$( `.minn-table-row[data-user="${ doomed.id }"]` );
			const box = await row.boundingBox();
			await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2, { button: 'right' } );
			await page.waitForSelector( '.minn-ctx-menu, .minn-new-menu', { timeout: 8000 } );
			await page.evaluate( () => {
				const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-new-menu button' ) ]
					.find( ( n ) => /Delete spam account/i.test( n.textContent ) );
				if ( btn ) btn.click();
			} );
			await page.waitForSelector( '.minn-confirm-overlay [data-ok]', { timeout: 10000 } );
			const confirmText = await page.evaluate( () => ( document.querySelector( '.minn-confirm-modal' ) || {} ).textContent || '' );
			t.check( 'delete confirm says posts go with the account',
				/posts and comments/i.test( confirmText ) && /no undo/i.test( confirmText ), confirmText.slice( 0, 120 ) );
			await page.click( '.minn-confirm-overlay [data-ok]' );
			const left = await page.waitForFunction( ( id ) =>
				! document.querySelector( `.minn-table-row[data-user="${ id }"]` ), doomed.id, { timeout: 15000 } )
				.then( () => true ).catch( () => false );
			t.check( 'deleted spam user leaves the list', left, '' );
			const goneUser = await api( `wp/v2/users/${ doomed.id }?context=edit` );
			t.check( 'spam account is gone on the server', goneUser.status === 404, String( goneUser.status ) );
			if ( goneUser.status === 404 ) {
				const idx = made.indexOf( doomed.id );
				if ( idx >= 0 ) made.splice( idx, 1 );
			}
		}

		const refuseAdmin = await api( 'minn-admin/v1/cleantalk/spam-users/1', {
			method: 'POST',
			body: JSON.stringify( { action: 'delete' } ),
		} );
		t.check( 'deleting the admin as spam is refused',
			refuseAdmin.status === 400 || refuseAdmin.status === 403,
			JSON.stringify( { status: refuseAdmin.status, body: refuseAdmin.body } ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const id of made ) {
			await api( `wp/v2/users/${ id }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => null );
		}
		if ( ! wasActive ) {
			await setPlugin( 'inactive' ).catch( () => null );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
