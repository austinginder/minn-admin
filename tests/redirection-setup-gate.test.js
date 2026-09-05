/**
 * Redirection's real first-run setup gate. surface-setup.test.js proves the
 * gate machinery on the mu-fixture; this one proves the bundled Redirection
 * adapter still FINDS the vendor's installer classes, which is the part a
 * vendor refactor breaks (5.10 moved them into an autoloaded namespace and
 * the adapter's path include silently answered "no setup needed").
 *
 * A fresh install is simulated the way Redirection itself decides it: its
 * `database` option empty (Status::needs_installing()). Tables and groups on
 * the fixture are never touched, and the option is restored in finally. The
 * install itself is not run here (it would re-run create_groups on a live
 * fixture); the Playground CLI covers that path end to end.
 */
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { launch, login, reporter, BASE } = require( './helpers' );

// MINN_TEST_WP first: on core-latest runs the harness points at the bare
// site, and a __dirname-derived path silently writes the DEV site's database.
const WP_PATH = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const wpEval = ( code ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } eval ${ JSON.stringify( code ) } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();

( async () => {
	const t = reporter( 'redirection-setup-gate' );

	const active = wpEval( "echo defined( 'REDIRECTION_VERSION' ) ? REDIRECTION_VERSION : '';" );
	if ( ! active ) {
		console.log( 'SKIP  Redirection is not active on this site.' );
		process.exit( 0 );
	}
	const saved = wpEval( "echo (string) Red_Options::get()['database'];" );
	t.check( 'fixture starts installed (database version stamped)', '' !== saved, saved );

	const { browser, page, errors } = await launch();
	await login( page );
	try {
		wpEval( "red_set_options( array( 'database' => '' ) );" );
		t.check( 'vendor now reports needs_installing', 'yes' === wpEval( "echo ( new \\Redirection\\Database\\Status() )->needs_installing() ? 'yes' : 'no';" ) );

		await page.goto( BASE + '/minn-admin/redirection', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-setup', { timeout: 20000 } );
		const card = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-setup-title' ).textContent,
			opts: [ ...document.querySelectorAll( '[data-setupopt]' ) ].map( ( s ) => [ s.dataset.setupopt, s.classList.contains( 'on' ) ] ),
			run: !! document.querySelector( '#minn-setup-run' ),
			table: !! document.querySelector( '.minn-table' ),
			add: !! document.querySelector( '#minn-surface-add' ),
		} ) );
		t.check( 'setup card names Redirection', /Redirection needs its one-time setup/.test( card.title ), card.title );
		t.check( 'the wizard\'s three choices, monitor + log on, IP off',
			JSON.stringify( card.opts ) === JSON.stringify( [ [ 'monitor', true ], [ 'log', true ], [ 'ip', false ] ] ), JSON.stringify( card.opts ) );
		t.check( 'Set up now runs in place (no link-out)', card.run );
		t.check( 'collection and create are unreachable behind the gate', ! card.table && ! card.add );

		/* ===== Restore: an installed site shows no gate ===== */
		wpEval( `red_set_options( array( 'database' => ${ JSON.stringify( saved ) } ) );` );
		await page.goto( BASE + '/minn-admin/redirection', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table, .minn-empty', { timeout: 20000 } );
		const after = await page.evaluate( () => ( {
			gate: !! document.querySelector( '#minn-surface-setup' ),
			add: !! document.querySelector( '#minn-surface-add' ),
		} ) );
		t.check( 'installed site renders the list, no gate', ! after.gate && after.add );
	} finally {
		wpEval( `red_set_options( array( 'database' => ${ JSON.stringify( saved ) } ) );` );
		t.check( 'database version restored', saved === wpEval( "echo (string) Red_Options::get()['database'];" ) );
	}
	await t.done( browser, errors );
} )();
