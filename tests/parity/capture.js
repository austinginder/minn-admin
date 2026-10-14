#!/usr/bin/env node
/**
 * Parity capture: what every minn-admin/v1 route answers, per kind of account.
 *
 * A port to the resource kernel must keep every route, method, gate and
 * response shape the app and its callers depend on. This records them so a
 * capture before a port and one after it can be diffed (tests/parity/diff.js):
 *
 *   node tests/parity/capture.js --out <file.json>
 *       [--path <wp root>] [--url <site url>]   default: the site this checkout sits in
 *       [--only <part,...>]     probe only routes whose callback file, or surfaces
 *                               whose id, contains one of these (endpoints and
 *                               surfaces are always recorded in full)
 *       [--writes <part,...>]   also run these surfaces' row and bulk actions,
 *                               rolled back (see capture.php for what that covers)
 *       [--as <label,...>]      only these accounts (default: all)
 *       [--swap <plugin,...>]   load plugins the site keeps off, for this run only
 *                               (tests/lib/plugin-swap.php)
 *       [--super <login>]       on a network, also capture as this super admin
 *       [--require <file.php>]  load this file in every WP-CLI process (the
 *                               diff's own check uses it to plant changes)
 *
 * It creates one account per role (tests/parity/principals.php), runs
 * capture.php once per account in its own WP-CLI process, so every request
 * sees a fresh boot for that user, then removes the accounts and merges the
 * parts. A request that kills its process (an exit, a fatal) is skipped and
 * that account's pass runs again; the skip is listed in the capture.
 *
 * Captures hold JSON paths and types, never values, but they describe a lab:
 * keep them out of git (tests/.parity/ is ignored).
 */
'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const PLUGIN = path.resolve( __dirname, '..', '..' );

function arg( name, fallback = '' ) {
	const i = process.argv.indexOf( '--' + name );
	return i > -1 && process.argv[ i + 1 ] && ! process.argv[ i + 1 ].startsWith( '--' ) ? process.argv[ i + 1 ] : fallback;
}

const out = arg( 'out' );
if ( ! out ) {
	console.error( 'usage: node tests/parity/capture.js --out <file.json> [--path <wp root>] [--url <url>] [--only a,b] [--writes a,b] [--as a,b] [--swap p/p.php] [--super <login>]' );
	process.exit( 2 );
}
const wpPath = arg( 'path', path.resolve( PLUGIN, '..', '..', '..' ) );
const url = arg( 'url' );
const only = arg( 'only' );
const writes = arg( 'writes' );
const asOnly = arg( 'as' ).split( ',' ).filter( Boolean );
const swap = arg( 'swap' );
const superLogin = arg( 'super' );
const extra = arg( 'require' );

const base = [ '--path=' + wpPath ].concat( url ? [ '--url=' + url ] : [] );
const swapArgs = ( swap ? [ '--require=tests/lib/plugin-swap.php' ] : [] ).concat( extra ? [ '--require=' + path.resolve( extra ) ] : [] );

function wp( args, env = {} ) {
	const r = spawnSync( 'wp', args.concat( base, swapArgs ), {
		cwd: PLUGIN,
		env: Object.assign( {}, process.env, swap ? { MINN_SWAP_ADD: swap } : {}, env ),
		encoding: 'utf8',
		maxBuffer: 256 * 1024 * 1024,
	} );
	return { code: r.status, out: ( r.stdout || '' ) + ( r.stderr || '' ) };
}

function git( args ) {
	const r = spawnSync( 'git', args, { cwd: PLUGIN, encoding: 'utf8' } );
	return ( r.stdout || '' ).trim();
}

const tmp = fs.mkdtempSync( path.join( os.tmpdir(), 'minn-parity-' ) );
// This run's accounts carry its id, so captures running at once never
// remove each other's.
const run = { MINN_PARITY_RUN: Date.now().toString( 36 ).slice( -6 ) + Math.random().toString( 36 ).slice( 2, 6 ) };
const made = wp( [ 'eval-file', 'tests/parity/principals.php', 'create' ], run );
const line = made.out.split( '\n' ).find( ( l ) => l.startsWith( 'PRINCIPALS ' ) );
if ( ! line ) {
	console.error( 'could not create the accounts:\n' + made.out );
	process.exit( 1 );
}
const principals = [ [ 'signed_out', 0 ] ].concat( Object.entries( JSON.parse( line.slice( 11 ) ) ) );
if ( superLogin ) {
	principals.push( [ 'super_admin', superLogin ] );
}

const parts = {};
let failed = false;
try {
	for ( const [ label, user ] of principals ) {
		if ( asOnly.length && ! asOnly.includes( label ) ) {
			continue;
		}
		const file = path.join( tmp, label + '.json' );
		const skip = [];
		for ( let attempt = 1; attempt <= 8; attempt++ ) {
			const started = Date.now();
			const r = wp( [ 'eval-file', 'tests/parity/capture.php' ].concat( user ? [ '--user=' + user ] : [] ), {
				MINN_PARITY_OUT: file,
				MINN_PARITY_AS: label,
				MINN_PARITY_ONLY: only,
				MINN_PARITY_WRITES: writes,
				MINN_PARITY_SKIP: skip.join( '\n' ),
			} );
			const inflight = file + '.inflight';
			if ( 0 === r.code && fs.existsSync( file ) && ! fs.existsSync( inflight ) ) {
				const summary = r.out.split( '\n' ).find( ( l ) => l.startsWith( 'parity ' ) ) || label;
				console.log( summary + ' (' + Math.round( ( Date.now() - started ) / 1000 ) + 's)' );
				break;
			}
			if ( ! fs.existsSync( inflight ) ) {
				console.error( label + ': capture failed before any request:\n' + r.out.slice( -4000 ) );
				failed = true;
				break;
			}
			const dead = fs.readFileSync( inflight, 'utf8' );
			fs.unlinkSync( inflight );
			const why = r.out.split( '\n' ).find( ( l ) => /Fatal error|Uncaught|^Error:/.test( l ) ) || '';
			console.log( label + ': ' + dead + ' ended the process (exit ' + r.code + '); skipping it and running again' + ( why ? '\n    ' + why.slice( 0, 300 ) : '' ) );
			skip.push( dead );
		}
		if ( fs.existsSync( file ) ) {
			parts[ label ] = JSON.parse( fs.readFileSync( file, 'utf8' ) );
			parts[ label ].killed = skip;
		}
	}
} finally {
	const gone = wp( [ 'eval-file', 'tests/parity/principals.php', 'delete' ], run );
	console.log( gone.out.split( '\n' ).filter( ( l ) => l.startsWith( 'removed' ) ).join( '\n' ) );
	fs.rmSync( tmp, { recursive: true, force: true } );
}
if ( failed || ! Object.keys( parts ).length ) {
	process.exit( 1 );
}

// Merge: endpoints once, with the accounts each gate admits; surfaces and
// responses per account.
const merged = {
	meta: {
		at: new Date().toISOString(),
		commit: git( [ 'rev-parse', 'HEAD' ] ),
		dirty: '' !== git( [ 'status', '--porcelain', '--', 'includes', 'minn-admin.php' ] ),
		site: url || wpPath.replace( os.homedir(), '~' ),
		only: only || null,
		writes: writes || null,
		swap: swap || null,
		accounts: Object.keys( parts ),
	},
	endpoints: {},
	surfaces: {},
	editorPanels: {},
	responses: {},
	unfilled: {},
	skipped: {},
};
for ( const [ label, p ] of Object.entries( parts ) ) {
	for ( const [ key, ep ] of Object.entries( p.endpoints ) ) {
		const e = merged.endpoints[ key ] || ( merged.endpoints[ key ] = { route: ep.route, methods: ep.methods, file: ep.file, args: ep.args, admits: [] } );
		if ( ep.gate ) {
			e.admits.push( label );
		}
	}
	merged.surfaces[ label ] = p.surfaces;
	merged.editorPanels[ label ] = p.editorPanels;
	for ( const [ key, r ] of Object.entries( p.responses ) ) {
		( merged.responses[ key ] = merged.responses[ key ] || {} )[ label ] = r;
	}
	if ( p.probeErrors && Object.keys( p.probeErrors ).length ) {
		( merged.probeErrors = merged.probeErrors || {} )[ label ] = p.probeErrors;
	}
	if ( p.unfilled.length ) {
		merged.unfilled[ label ] = p.unfilled;
	}
	if ( p.skipped.length || p.killed.length ) {
		merged.skipped[ label ] = p.killed.concat( p.skipped.filter( ( k ) => ! p.killed.includes( k ) ) );
	}
}
merged.endpoints = Object.fromEntries( Object.entries( merged.endpoints ).sort() );
merged.responses = Object.fromEntries( Object.entries( merged.responses ).sort() );
fs.mkdirSync( path.dirname( path.resolve( out ) ), { recursive: true } );
fs.writeFileSync( out, JSON.stringify( merged, null, '\t' ) + '\n' );
console.log( 'wrote ' + out + ': ' + Object.keys( merged.endpoints ).length + ' endpoints, ' + Object.keys( merged.responses ).length + ' probes, accounts ' + merged.meta.accounts.join( ', ' ) );
