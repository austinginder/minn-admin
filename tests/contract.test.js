/**
 * The descriptor contract suite (v1.0 gate G5), two halves:
 *
 * 1. DOC LOCKSTEP (static): every key in the validator's vocabulary
 *    constants (class-minn-admin-surfaces.php) must appear in
 *    docs/for-plugin-authors.md. A new key landing in the constants
 *    without documentation fails here — the rule-57 lockstep, enforced.
 *
 * 2. KITCHEN-SINK DRIVE (browser): the minn_test_contract_surface fixture
 *    declares one surface using the whole collection vocabulary — dynamic
 *    route tabs + allRoute, query/pageQuery, every column format plus
 *    altKey/width/utc, detail (detailRoute + labels + messageKey + skip +
 *    edit with preserve and the full field vocabulary), every action key,
 *    bulk, filter, create with defaults, manage, views[], status
 *    (rows/chart/command/actions), item-scoped settings + settingsItem —
 *    and this suite drives each observable behavior end to end, verifying
 *    server state through the raw route. The Integrations card validating
 *    the fixture with ZERO problems is itself a contract check.
 *
 * Setup gates, plain settings views, editor panels and design sources have
 * their own dedicated fixture suites (setup via hide-integrations' fixture,
 * settings-surface, seo-mappers/acf, design-sources) — this suite owns the
 * vocabulary the others don't reach.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'contract' );

	/* ===== 1. Doc lockstep ===== */
	const root = path.resolve( __dirname, '..' );
	const phpSrc = fs.readFileSync( path.join( root, 'includes/class-minn-admin-surfaces.php' ), 'utf8' );
	const docs = fs.readFileSync( path.join( root, 'docs/for-plugin-authors.md' ), 'utf8' );
	const constBlocks = [ ...phpSrc.matchAll( /const\s+([A-Z_]+)\s*=\s*array\(([^;]+)\);/g ) ];
	t.check( 'validator vocabulary constants found', constBlocks.length >= 12, String( constBlocks.length ) );
	const missing = [];
	for ( const [ , name, body ] of constBlocks ) {
		for ( const [ , key ] of body.matchAll( /'([^']+)'/g ) ) {
			if ( ! docs.includes( key ) ) missing.push( `${ name }:${ key }` );
		}
	}
	t.check( 'every vocabulary key appears in the author guide', missing.length === 0, missing.join( ', ' ) );

	/* ===== 2. Kitchen-sink drive ===== */
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( pathArg, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ pathArg, opts || null ] );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_contract_surface: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_contract_surface;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const raw = async () => ( await rest( 'minn-admin/v1/minn-test/contract-raw' ) ).body;
	const rowById = async ( id ) => ( await raw() ).rows.find( ( r ) => r.id === id );
	const openSurface = async () => {
		await page.goto( `${ BASE }/minn-admin/minn-contract-fixture`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 30000 } );
		await page.waitForTimeout( 500 );
	};
	const settle = ( ms = 700 ) => page.waitForTimeout( ms );

	let dialogAccept = null;
	page.on( 'dialog', ( d ) => { dialogAccept = d.message(); d.accept(); } );

	try {
		t.check( 'contract fixture armed', await setOpt( true ) );
		await rest( 'minn-admin/v1/minn-test/contract-raw', { method: 'DELETE' } ); // fresh seed

		/* ===== The kitchen sink validates clean ===== */
		const intg = await ( async () => {
			await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-sys-integrations', { timeout: 30000 } );
			return page.evaluate( () => {
				const probs = [ ...document.querySelectorAll( '.minn-sys-int-problem' ) ].map( ( e ) => e.textContent );
				return { probs, listed: document.querySelector( '#minn-sys-integrations' ).textContent.includes( 'Contract Fixture' ) };
			} );
		} )();
		t.check( 'fixture appears on the Integrations card', intg.listed );
		t.check( 'the full vocabulary produces zero contract problems', intg.probs.length === 0, intg.probs.join( ' | ' ) );

		/* ===== Tabs (route form + allLabel) + pagination + columns ===== */
		await openSurface();
		const shape = await page.evaluate( () => ( {
			tabs: [ ...document.querySelectorAll( '[data-stab]' ) ].map( ( e ) => e.textContent.trim() ),
			rows: document.querySelectorAll( '.minn-table-row' ).length,
			statusCard: !! document.querySelector( '.minn-surface-status' ),
			chartBars: document.querySelectorAll( '.minn-surface-status [class*="chart"] div, .minn-sstat-chart div' ).length,
			cmd: ( document.querySelector( '.minn-sstat-cmd-box code' ) || {} ).textContent || '',
			views: [ ...document.querySelectorAll( '[data-sview]' ) ].map( ( e ) => e.textContent.trim() ),
			filters: [ ...document.querySelectorAll( '[data-sfilter]' ) ].map( ( e ) => e.textContent.trim() ),
			add: !! document.querySelector( '#minn-surface-add' ),
			checks: document.querySelectorAll( '[data-scheck]' ).length,
		} ) );
		t.check( 'route tabs render with allLabel first', shape.tabs[ 0 ] === 'Everything' && shape.tabs.includes( 'New' ) && shape.tabs.includes( 'Read' ), JSON.stringify( shape.tabs ) );
		t.check( 'pageQuery caps the first page at 2 rows', shape.rows === 2, String( shape.rows ) );
		t.check( 'status card renders rows, chart and command', shape.statusCard && /contract connect example/.test( shape.cmd ) );
		t.check( 'view switcher lists Items, Groups and the extra Log view',
			shape.views.includes( 'Items' ) && shape.views.includes( 'Groups' ) && shape.views.includes( 'Log' ), JSON.stringify( shape.views ) );
		t.check( 'filter control renders with the first option default', shape.filters.length === 2 && shape.filters[ 0 ] === 'All', JSON.stringify( shape.filters ) );
		t.check( 'create button and bulk checkboxes render', shape.add && shape.checks === 2 );

		const cells = await page.$eval( '.minn-table-row', ( row ) => row.textContent );
		t.check( 'columns render pill, mono, num, entry-summary and altKey fallback',
			/new|read/.test( cells ) && /[a-z]\d[a-z]\d/.test( cells ) && /Answer/.test( cells ) && /@example\.com/.test( cells ), cells.slice( 0, 220 ) );
		t.check( 'ago column parses the UTC timestamp', /ago|1h|1d/.test( cells ) );

		/* ===== Sortable columns (sort + sortQuery, since v0.18.0) ===== */
		const sortHeads = await page.$$eval( '[data-ssort]', ( els ) => els.map( ( e ) => e.dataset.ssort ) );
		t.check( 'columns with sort tokens render sortable headers', sortHeads.includes( 'title' ) && sortHeads.includes( 'count' ), JSON.stringify( sortHeads ) );
		const sortWait = ( dir ) => page.waitForRequest(
			( r ) => r.url().includes( 'minn-test/contract/list' ) && r.url().includes( 'orderby=count' ) && r.url().includes( 'direction=' + dir ),
			{ timeout: 10000 } );
		let sw = sortWait( 'desc' ); // num format starts descending
		await page.click( '[data-ssort="count"]' );
		await sw;
		await page.waitForFunction( () => {
			const b = document.querySelector( '[data-ssort="count"]' );
			return b && b.classList.contains( 'is-active' ) && b.getAttribute( 'aria-sort' ) === 'descending';
		}, null, { timeout: 15000 } );
		t.check( 'num column sorts descending first, header marks active', true );
		sw = sortWait( 'asc' );
		await page.click( '[data-ssort="count"]' );
		await sw;
		await page.waitForFunction( () => {
			const b = document.querySelector( '[data-ssort="count"]' );
			return b && b.getAttribute( 'aria-sort' ) === 'ascending';
		}, null, { timeout: 15000 } );
		t.check( 'repeat click flips direction (request + aria)', true );
		// Fresh page load clears the sort so later checks see natural order.
		await openSurface();

		/* ===== Tab switch narrows; search narrows; filter narrows ===== */
		await page.click( '[data-stab="read"]' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 1, null, { timeout: 15000 } );
		t.check( 'tab route ({tab}) narrows the list', true );
		await page.click( '[data-stab="_all"]' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 2, null, { timeout: 15000 } );
		await page.type( '#minn-surface-search', 'Bravo' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 1
			&& document.querySelector( '.minn-table-row' ).textContent.includes( 'Bravo' ), null, { timeout: 15000 } );
		t.check( 'search template narrows the list', true );
		await page.evaluate( () => {
			const el = document.querySelector( '#minn-surface-search' );
			el.value = ''; el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await settle( 1200 );
		await page.click( '[data-sfilter="starred"]' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row' ).length === 1
			&& document.querySelector( '.minn-table-row' ).textContent.includes( 'Bravo' ), null, { timeout: 15000 } );
		t.check( 'filter query narrows to starred rows', true );
		await page.click( '[data-sfilter="all"]' );
		await settle( 900 );

		/* ===== Detail: labels, messageKey, skip ===== */
		await page.$eval( '.minn-table-row', ( el ) => el.click() );
		await page.waitForSelector( '.minn-modal [data-saction], .minn-modal .minn-modal-actions', { timeout: 15000 } );
		await settle( 400 );
		const detail = await page.evaluate( () => ( {
			text: document.querySelector( '.minn-modal' ).textContent,
			message: ( document.querySelector( '.minn-surface-message' ) || {} ).textContent || '',
			actions: [ ...document.querySelectorAll( '.minn-modal-actions a, .minn-modal-actions button' ) ].map( ( e ) => e.textContent.trim() ),
		} ) );
		t.check( 'labels route resolves numeric keys to human labels', detail.text.includes( 'First question' ) && detail.text.includes( 'Second question' ) );
		t.check( 'messageKey renders the large text block', /message body/.test( detail.message ) );
		t.check( 'skip hides internal keys', ! detail.text.includes( 'hide-me' ) && ! detail.text.includes( 'kept-' ) );
		t.check( 'detail offers the action vocabulary (list:false verb included)',
			[ 'Ping', 'Star', 'Add note', 'Hidden verb', 'Item settings', 'Delete' ].every( ( l ) => detail.actions.some( ( a ) => a.includes( l ) ) ), JSON.stringify( detail.actions ) );
		t.check( 'href action carries the off-site mark', detail.actions.some( ( a ) => /Vendor docs ↗/.test( a ) ) );

		/* ===== Edit: fields save, preserve rides along ===== */
		await page.$eval( '[data-editfield="title"]', ( el ) => { el.value = ''; } );
		await page.type( '[data-editfield="title"]', 'Alpha item renamed' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-surface-save' ), null, { timeout: 15000 } );
		const afterEdit = await raw();
		const edited = afterEdit.rows.find( ( r ) => r.title === 'Alpha item renamed' );
		t.check( 'detail.edit saves through the fixture route', !! edited );
		t.check( 'preserve sends untouched keys along', afterEdit.preserve_seen === 'yes', afterEdit.preserve_seen );
		t.check( 'preserved value survives the round trip', edited && /^kept-/.test( edited.keep_me ) );

		/* ===== Actions: confirm + body, when-gate, parameterized fields ===== */
		await settle( 600 );
		await page.$eval( '.minn-table-row', ( el ) => el.click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		dialogAccept = null;
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal-actions button' ) ].find( ( b ) => b.textContent.trim() === 'Ping' ).click() );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( e ) => /Pinged to 2/.test( e.textContent ) ), null, { timeout: 15000 } );
		t.check( 'confirm shows and the action body reaches the route', dialogAccept === 'Ping this item?' );
		t.check( 'route message replaces the default toast', true );

		await settle( 800 );
		await page.$eval( '.minn-table-row', ( el ) => el.click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal-actions button' ) ].find( ( b ) => b.textContent.trim() === 'Add note' ).click() );
		await page.waitForSelector( '[data-actfield="text"]', { timeout: 10000 } );
		await page.type( '[data-actfield="text"]', 'Note from the contract suite' );
		await page.click( '[data-actgo]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( e ) => /Note saved/.test( e.textContent ) ), null, { timeout: 15000 } );
		const noted = await raw();
		t.check( 'parameterized fields action posts typed values', noted.rows.some( ( r ) => r.note === 'Note from the contract suite' ) );

		/* ===== when-gate on the row menu; list:false stays off it ===== */
		await settle( 800 );
		await openSurface();
		await page.click( '.minn-table-row .minn-row-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 10000 } );
		const menu = await page.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'row menu carries eligible verbs, not the list:false one',
			menu.some( ( l ) => l === 'Ping' ) && ! menu.includes( 'Hidden verb' ) && ! menu.includes( 'Add note' ), JSON.stringify( menu ) );
		await page.mouse.click( 4, 4 );
		await settle( 300 );

		/* ===== Bulk: when-gated, per-item =====
		 * Run on the fresh Everything page (page 1 = the two `new` rows):
		 * clicking a tab first would soft-reload and the old checkboxes'
		 * selection dies with the repaint (rule-77 class). */
		await page.$$eval( '[data-scheck]', ( els ) => els.forEach( ( cb ) => cb.click() ) );
		await page.waitForSelector( '[data-sbulk]', { timeout: 10000 } );
		await page.click( '[data-sbulk]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( e ) => /Mark read: 2 done/.test( e.textContent ) ), null, { timeout: 20000 } );
		const afterBulk = await raw();
		t.check( 'bulk action ran per selected item', afterBulk.rows.every( ( r ) => r.state === 'read' ), JSON.stringify( afterBulk.rows.map( ( r ) => r.state ) ) );

		/* ===== Create: defaults merged under typed values ===== */
		await openSurface();
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]', { timeout: 10000 } );
		await page.type( '[data-createfield="title"]', 'Delta item' );
		await page.click( '[data-createfield="state"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="state"] .minn-ac-item[data-acv="new"]', { timeout: 5000 } );
		await page.click( '[data-createfield="state"] .minn-ac-item[data-acv="new"]' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () => ! document.querySelector( '[data-createfield="title"]' ), null, { timeout: 15000 } );
		const created = ( await raw() ).rows.find( ( r ) => r.title === 'Delta item' );
		t.check( 'create posts typed values with defaults merged', !! created && created.source === 'created-via-minn', JSON.stringify( created || {} ) );
		t.check( 'create field value default rides along', created && Number( created.count ) === 1 );

		/* ===== Manage + extra view render their collections ===== */
		await page.click( '[data-sview="manage"]' );
		// Soft reload keeps the old list painted — wait for the GROUP data.
		await page.waitForFunction( () => document.querySelector( '#minn-view' ).textContent.includes( 'Read items' ), null, { timeout: 20000 } );
		t.check( 'manage view lists groups', true );
		await page.click( '[data-sview="x0"]' );
		await page.waitForFunction( () => document.querySelector( '#minn-view' ).textContent.includes( 'Fixture seeded' ), null, { timeout: 15000 } );
		t.check( 'views[] entry renders its own collection', true );

		/* ===== settingsItem opens the item-scoped settings view ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.$eval( '.minn-table-row', ( el ) => el.click() );
		await page.waitForSelector( '.minn-modal [data-saction]', { timeout: 15000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-modal-actions button' ) ].find( ( b ) => b.textContent.trim() === 'Item settings' ).click() );
		await page.waitForSelector( '[data-sset="alias"]', { timeout: 15000 } );
		await page.type( '[data-sset="alias"]', 'Contract alias' );
		await page.click( '#minn-sset-save' );
		// Poll server state (a leftover toast from earlier flows can't lie).
		let aliasSaved = false;
		for ( let i = 0; i < 10 && ! aliasSaved; i++ ) {
			aliasSaved = ( await raw() ).rows.some( ( r ) => r.alias === 'Contract alias' );
			if ( ! aliasSaved ) await settle( 800 );
		}
		t.check( 'settingsItem opens and saves item-scoped settings', aliasSaved );

		/* ===== Status-card action with confirm ===== */
		await openSurface();
		dialogAccept = null;
		await page.evaluate( () => [ ...document.querySelectorAll( '[data-sstatact]' ) ].find( ( b ) => b.textContent.trim() === 'Reseed' ).click() );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( e ) => /Reseeded/.test( e.textContent ) ), null, { timeout: 15000 } );
		t.check( 'status-card action confirms and runs', dialogAccept === 'Reset the fixture rows?' );
	} finally {
		await rest( 'minn-admin/v1/minn-test/contract-raw', { method: 'DELETE' } ).catch( () => {} );
		await setOpt( false );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
