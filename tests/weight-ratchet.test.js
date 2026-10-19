#!/usr/bin/env node
/**
 * The weight ratchet: adapters stay lean and their logic lives in the kernel
 * (docs/adapter-kernel.md), so what an adapter carries may only go down:
 *
 *   code_lines            the adapters' lines of code (kits included)
 *   adapter_kernel_lines  those plus the kernel's (includes/kernel/, every
 *                         tier), so moving a rule into the kernel saves
 *                         only when the kernel's copy is smaller
 *   vendor_guards         function_exists, class_exists, method_exists and
 *                         defined calls, and catch blocks
 *   hand_errors           new WP_Error in an adapter
 *   cap_checks            current_user_can in an adapter
 *   detect_helpers        minn_admin_*_active, _can, _ready and kin
 *
 * The counts are bin/adapter-inventory.js's; the ceilings are
 * tests/fixtures/weight-ratchet.json. A count above its ceiling fails. A
 * count below it fails too, until the ceiling is lowered in the same commit,
 * so later growth cannot quietly take the freed room. Every commit that
 * changes an adapter or the kernel rewrites the fixture from the tree:
 *
 *   MINN_RATCHET=write node tests/weight-ratchet.test.js
 *
 * Raising a ceiling is a decision the commit explains: a new adapter or a
 * new feature is a fair reason; a port that grew is not.
 *
 * The five adapters that grew most since the fixture was written are
 * printed, without failing, so growth is visible where it happens. No
 * browser and no site: it reads the source tree.
 */
'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const MEASURES = {
	code_lines: 'adapter code belongs in the kernel',
	adapter_kernel_lines: 'a rule moved into the kernel should cost less there than it did in the adapters',
	vendor_guards: 'a plugin check belongs in the declaration\'s active callback or a kernel guard',
	hand_errors: 'an error answer belongs to the kernel\'s error shape',
	cap_checks: 'a capability check belongs in a declared gate',
	detect_helpers: 'a detection helper belongs in the declaration',
};

const root = path.resolve( __dirname, '..' );
const file = path.join( __dirname, 'fixtures', 'weight-ratchet.json' );
const out = fs.mkdtempSync( path.join( os.tmpdir(), 'minn-weight-' ) );
let now;
let rows;
try {
	now = JSON.parse( execFileSync( process.execPath, [ path.join( root, 'bin', 'adapter-inventory.js' ), '--out', out ], { cwd: root, encoding: 'utf8' } ) );
	rows = JSON.parse( fs.readFileSync( path.join( out, 'adapters.json' ), 'utf8' ) );
} finally {
	fs.rmSync( out, { recursive: true, force: true } );
}
const perAdapter = {};
rows.forEach( ( r ) => ( perAdapter[ r.adapter ] = r.code_lines ) );

if ( 'write' === process.env.MINN_RATCHET ) {
	const fixture = {};
	Object.keys( MEASURES ).forEach( ( k ) => ( fixture[ k ] = now[ k ] ) );
	fixture.adapters = perAdapter;
	fs.writeFileSync( file, JSON.stringify( fixture, null, '\t' ) + '\n' );
	const shown = {};
	Object.keys( MEASURES ).forEach( ( k ) => ( shown[ k ] = now[ k ] ) );
	console.log( 'WROTE weight-ratchet.json: ' + JSON.stringify( shown ) );
	process.exit( 0 );
}

const ceilings = JSON.parse( fs.readFileSync( file, 'utf8' ) );
let failed = 0;
let total = 0;
const check = ( label, ok, detail ) => {
	total++;
	console.log( ( ok ? 'PASS' : 'FAIL' ) + '  ' + label + ( ok || ! detail ? '' : ' — ' + detail ) );
	failed += ok ? 0 : 1;
};
Object.keys( MEASURES ).forEach( ( k ) => {
	const count = now[ k ];
	const ceiling = ceilings[ k ];
	check( k + ' does not rise (' + count + ', ceiling ' + ceiling + ')', count <= ceiling, MEASURES[ k ] + '; a new adapter or feature that needs room raises the ceiling with MINN_RATCHET=write and says why in its commit' );
	check( k + ' ceiling matches the tree', count >= ceiling, k + ' fell to ' + count + ': lower the ceiling with MINN_RATCHET=write and commit it with the change' );
} );

// Growth since the fixture, per adapter (a new adapter counts whole).
const was = ceilings.adapters || {};
const grew = Object.keys( perAdapter )
	.map( ( a ) => [ a, perAdapter[ a ] - ( was[ a ] || 0 ), was[ a ] === undefined ] )
	.filter( ( g ) => g[ 1 ] > 0 )
	.sort( ( x, y ) => y[ 1 ] - x[ 1 ] )
	.slice( 0, 5 );
if ( grew.length ) {
	console.log( '\nGrew most since the fixture (code lines, not a failure):' );
	grew.forEach( ( g ) => console.log( '  +' + g[ 1 ] + '  ' + g[ 0 ] + ( g[ 2 ] ? ' (new)' : ' (' + was[ g[ 0 ] ] + ' -> ' + perAdapter[ g[ 0 ] ] + ')' ) ) );
}

console.log( '\nweight-ratchet: ' + ( total - failed ) + '/' + total + ' passed' );
process.exit( failed ? 1 : 0 );
