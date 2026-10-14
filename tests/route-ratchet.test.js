#!/usr/bin/env node
/**
 * The route ratchet: hand-written REST routes in includes/adapters/ may only
 * go down (docs/adapter-kernel.md). A port to the kernel replaces them with
 * declarations; nothing new should add one.
 *
 * The count is bin/adapter-inventory.js's (register_rest_route calls in the
 * adapter files); the ceiling is tests/fixtures/route-ratchet.json. A count
 * above the ceiling fails. A count below it fails too, until the ceiling is
 * lowered in the same commit as the port, so a later route cannot quietly
 * take the freed slot:
 *
 *   MINN_RATCHET=write node tests/route-ratchet.test.js
 *
 * No browser and no site: it reads the source tree.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const root = path.resolve( __dirname, '..' );
const file = path.join( __dirname, 'fixtures', 'route-ratchet.json' );
const now = JSON.parse( execFileSync( process.execPath, [ path.join( root, 'bin', 'adapter-inventory.js' ) ], { cwd: root, encoding: 'utf8' } ) );
const count = now.routes;

if ( 'write' === process.env.MINN_RATCHET ) {
	fs.writeFileSync( file, JSON.stringify( { routes: count, kernel_resources: now.resources, kernel_endpoints: now.endpoints }, null, '\t' ) + '\n' );
	console.log( 'WROTE route-ratchet.json: ' + count + ' hand-written routes' );
	process.exit( 0 );
}
const ceiling = JSON.parse( fs.readFileSync( file, 'utf8' ) ).routes;
let failed = 0;
const check = ( label, ok, detail ) => {
	console.log( ( ok ? 'PASS' : 'FAIL' ) + '  ' + label + ( ok || ! detail ? '' : ' — ' + detail ) );
	failed += ok ? 0 : 1;
};
check( 'hand-written adapter routes do not rise (' + count + ', ceiling ' + ceiling + ')', count <= ceiling, 'a new register_rest_route in includes/adapters/: declare it through minn_admin_resource() or minn_admin_endpoint() instead' );
check( 'the ceiling matches the tree', count >= ceiling, 'routes fell to ' + count + ': lower the ceiling with MINN_RATCHET=write and commit it with the port' );
console.log( '\nroute-ratchet: ' + ( 2 - failed ) + '/2 passed (' + now.resources + ' kernel resources, ' + now.endpoints + ' kernel endpoints)' );
process.exit( failed ? 1 : 0 );
