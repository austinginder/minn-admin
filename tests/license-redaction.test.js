/**
 * The database browser hides every licence credential the Licences screen
 * reads, and every copy of one in an option or meta row (no browser, ~1 min).
 *
 * The work is PHP (tests/lib/license-redaction.php, run with wp eval-file):
 * it traces what each licence reader reads, checks the names the provider
 * files use, and looks for each redacted credential elsewhere in the
 * database. MINN_TEST_WP selects the WordPress root (defaults to this
 * plugin's site); a lab with few licensed plugins sets MINN_MIN_READERS and
 * MINN_MIN_CREDENTIALS lower.
 */
const { spawnSync } = require( 'child_process' );
const path = require( 'path' );
const { WP } = require( './helpers' );

const r = spawnSync( 'wp', [ `--path=${ WP }`, 'eval-file', path.join( __dirname, 'lib/license-redaction.php' ) ], {
	encoding: 'utf8',
	timeout: 600000,
	maxBuffer: 20 * 1024 * 1024,
	env: process.env,
} );
process.stdout.write( r.stdout || '' );
if ( r.status !== 0 && ! /license-redaction: \d+\/\d+ passed/.test( r.stdout || '' ) ) {
	// Died before its summary: show why, and count it as a failure.
	process.stdout.write( ( r.stderr || '' ).split( '\n' ).filter( ( l ) => /Fatal|Error|error/.test( l ) ).slice( 0, 20 ).join( '\n' ) + '\n' );
	console.log( '\nlicense-redaction: 0/1 passed' );
}
process.exit( 0 === r.status ? 0 : 1 );
