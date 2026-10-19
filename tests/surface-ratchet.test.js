#!/usr/bin/env node
/**
 * The surface ratchet: security rules adapters write by hand may only go
 * down (docs/adapter-kernel.md). Each is a place the registry scans keep
 * finding the same bug in another file, and each is meant to move into the
 * kernel's tiers:
 *
 *   panel_saves    editor-panel save callbacks (register_rest_field update_callback)
 *   held_refusals  panel refusals held by hand (minn_admin_panel_held)
 *   mask_rules     egress mask compares and refusals (served_as, refuse_masks, unchanged)
 *   url_cleaning   URL cleaning (esc_url_raw, minn_admin_url_clean)
 *   hand_clips     stored text cut to 16 characters or more outside Minn_Admin_Egress::clip()
 *
 * The counts are bin/adapter-inventory.js's; the ceilings are
 * tests/fixtures/surface-ratchet.json. A count above its ceiling fails. A
 * count below it fails too, until the ceiling is lowered in the same commit,
 * so a later hand-written rule cannot quietly take the freed slot:
 *
 *   MINN_RATCHET=write node tests/surface-ratchet.test.js
 *
 * Raising a ceiling is a decision, not a fix: the commit says why the rule
 * could not go through the kernel. No browser and no site: it reads the
 * source tree.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const MEASURES = {
	panel_saves: 'a panel save belongs in the fields tier',
	held_refusals: 'a refusal belongs in the fields tier, which holds it',
	mask_rules: 'a mask compare or refusal belongs in the kernel\'s save path',
	url_cleaning: 'a URL belongs in the kernel\'s url field type',
	hand_clips: 'cut stored text with Minn_Admin_Egress::clip()',
};

const root = path.resolve( __dirname, '..' );
const file = path.join( __dirname, 'fixtures', 'surface-ratchet.json' );
const now = JSON.parse( execFileSync( process.execPath, [ path.join( root, 'bin', 'adapter-inventory.js' ) ], { cwd: root, encoding: 'utf8' } ) );

if ( 'write' === process.env.MINN_RATCHET ) {
	const out = {};
	Object.keys( MEASURES ).forEach( ( k ) => ( out[ k ] = now[ k ] ) );
	fs.writeFileSync( file, JSON.stringify( out, null, '\t' ) + '\n' );
	console.log( 'WROTE surface-ratchet.json: ' + JSON.stringify( out ) );
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
	check( k + ' does not rise (' + count + ', ceiling ' + ceiling + ')', count <= ceiling, MEASURES[ k ] );
	check( k + ' ceiling matches the tree', count >= ceiling, k + ' fell to ' + count + ': lower the ceiling with MINN_RATCHET=write and commit it with the change' );
} );
console.log( '\nsurface-ratchet: ' + ( total - failed ) + '/' + total + ' passed (credential rows only the central lists know: ' + now.credential_rows + ')' );
process.exit( failed ? 1 : 0 );
