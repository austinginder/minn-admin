#!/usr/bin/env node
/**
 * Parity diff: what changed between two captures (tests/parity/capture.js).
 *
 *   node tests/parity/diff.js <before.json> <after.json>
 *       [--only <part,...>]   endpoints, probes and surfaces whose route, callback
 *                             file or surface id contains one of these
 *       [--as <label,...>]    only these accounts
 *       [--accept <file>]     deliberate changes: a JSON list of
 *                             { "match": "<text in the line>", "why": "<reason>" }
 *       [--strict]            REVIEW lines fail too
 *
 * FAIL: something a caller could depend on is gone or different: an
 * endpoint or method, an argument, who a gate admits, a surface's descriptor
 * value, a probe, a status class, a JSON path, a type, a key that is now
 * sometimes missing, a string format no longer sent.
 * REVIEW: something new or looser: an added endpoint, argument, path or
 * format, a value that may now be null, an error code that changed.
 * Paths under a list that was empty (or a value that was null) on one side
 * cannot be compared and are counted, not judged: capture both sides on the
 * same lab data, right before and right after the change.
 *
 * Exit 1 when any FAIL is left after --accept (or any REVIEW, with --strict).
 */
'use strict';

const fs = require( 'fs' );

function arg( name, fallback = '' ) {
	const i = process.argv.indexOf( '--' + name );
	return i > -1 && process.argv[ i + 1 ] && ! process.argv[ i + 1 ].startsWith( '--' ) ? process.argv[ i + 1 ] : fallback;
}
const files = process.argv.slice( 2 ).filter( ( a, i, all ) => ! a.startsWith( '--' ) && ! ( i > 0 && all[ i - 1 ].startsWith( '--' ) && 'strict' !== all[ i - 1 ].slice( 2 ) ) );
if ( files.length < 2 ) {
	console.error( 'usage: node tests/parity/diff.js <before.json> <after.json> [--only a,b] [--as a,b] [--accept file] [--strict]' );
	process.exit( 2 );
}
const A = JSON.parse( fs.readFileSync( files[ 0 ], 'utf8' ) );
const B = JSON.parse( fs.readFileSync( files[ 1 ], 'utf8' ) );
const only = arg( 'only' ).split( ',' ).filter( Boolean );
const asOnly = arg( 'as' ).split( ',' ).filter( Boolean );
const accept = arg( 'accept' ) ? JSON.parse( fs.readFileSync( arg( 'accept' ), 'utf8' ) ) : [];
const strict = process.argv.includes( '--strict' );

const lines = [];
let skipped = 0;
function report( level, where, text ) {
	const msg = where + ': ' + text;
	const ok = accept.find( ( a ) => msg.includes( a.match ) );
	lines.push( { level: ok && 'FAIL' === level ? 'ACCEPTED' : level, msg: msg + ( ok ? '  (' + ok.why + ')' : '' ) } );
}
const wanted = ( ...hay ) => ! only.length || only.some( ( o ) => hay.some( ( h ) => h && String( h ).includes( o ) ) );
const accounts = ( x ) => Object.keys( x || {} ).filter( ( a ) => ! asOnly.length || asOnly.includes( a ) );

// Endpoints, compared per method so a route split or merged across
// registrations still matches.
function perMethod( cap ) {
	const out = {};
	for ( const ep of Object.values( cap.endpoints ) ) {
		for ( const m of ep.methods ) {
			out[ m + ' ' + ep.route ] = ep;
		}
	}
	return out;
}
const ea = perMethod( A );
const eb = perMethod( B );
for ( const key of new Set( [ ...Object.keys( ea ), ...Object.keys( eb ) ] ) ) {
	const a = ea[ key ];
	const b = eb[ key ];
	if ( ! wanted( key, a && a.file, b && b.file ) ) {
		continue;
	}
	if ( ! b ) {
		report( 'FAIL', key, 'endpoint no longer registered (was ' + a.file + ')' );
		continue;
	}
	if ( ! a ) {
		report( 'REVIEW', key, 'new endpoint (' + b.file + '), admits ' + ( b.admits.join( ', ' ) || 'nobody without an object' ) );
		continue;
	}
	const as = accounts( Object.fromEntries( A.meta.accounts.filter( ( x ) => B.meta.accounts.includes( x ) ).map( ( x ) => [ x, 1 ] ) ) );
	const wider = as.filter( ( x ) => b.admits.includes( x ) && ! a.admits.includes( x ) );
	const narrower = as.filter( ( x ) => a.admits.includes( x ) && ! b.admits.includes( x ) );
	if ( wider.length ) {
		report( 'FAIL', key, 'gate now admits ' + wider.join( ', ' ) );
	}
	if ( narrower.length ) {
		report( 'FAIL', key, 'gate no longer admits ' + narrower.join( ', ' ) );
	}
	for ( const name of new Set( [ ...Object.keys( a.args || {} ), ...Object.keys( b.args || {} ) ] ) ) {
		const x = ( a.args || {} )[ name ];
		const y = ( b.args || {} )[ name ];
		if ( ! y ) {
			report( 'FAIL', key, 'argument "' + name + '" no longer declared' );
		} else if ( ! x ) {
			report( 'REVIEW', key, 'new argument "' + name + '" ' + JSON.stringify( y ) );
		} else {
			for ( const k of new Set( [ ...Object.keys( x ), ...Object.keys( y ) ] ) ) {
				if ( JSON.stringify( x[ k ] ) !== JSON.stringify( y[ k ] ) ) {
					report( 'FAIL', key, 'argument "' + name + '" ' + k + ': ' + JSON.stringify( x[ k ] ) + ' → ' + JSON.stringify( y[ k ] ) );
				}
			}
		}
	}
}

// Descriptors by value.
function flatValues( v, p = '', out = {} ) {
	if ( v && 'object' === typeof v ) {
		const entries = Array.isArray( v ) ? v.map( ( x, i ) => [ '[' + i + ']', x ] ) : Object.entries( v ).map( ( [ k, x ] ) => [ '.' + k, x ] );
		if ( ! entries.length ) {
			out[ p || '.' ] = Array.isArray( v ) ? '[]' : '{}';
		}
		for ( const [ k, x ] of entries ) {
			flatValues( x, p + k, out );
		}
	} else {
		out[ p || '.' ] = JSON.stringify( v );
	}
	return out;
}
function routesIn( v ) {
	return JSON.stringify( v || {} );
}
for ( const who of accounts( A.surfaces ) ) {
	const sa = A.surfaces[ who ] || {};
	const sb = ( B.surfaces || {} )[ who ];
	if ( ! sb ) {
		continue;
	}
	for ( const id of new Set( [ ...Object.keys( sa ), ...Object.keys( sb ) ] ) ) {
		if ( ! wanted( id, only.length ? routesIn( sa[ id ] ) + routesIn( sb[ id ] ) : '' ) ) {
			continue;
		}
		const where = 'surface ' + id + ' as ' + who;
		if ( ! sb[ id ] ) {
			report( 'FAIL', where, 'no longer sent' );
			continue;
		}
		if ( ! sa[ id ] ) {
			report( 'REVIEW', where, 'newly sent' );
			continue;
		}
		const fa = flatValues( sa[ id ] );
		const fb = flatValues( sb[ id ] );
		for ( const p of new Set( [ ...Object.keys( fa ), ...Object.keys( fb ) ] ) ) {
			if ( ! ( p in fb ) ) {
				report( 'FAIL', where, p + ' removed (was ' + fa[ p ] + ')' );
			} else if ( ! ( p in fa ) ) {
				report( 'REVIEW', where, p + ' added: ' + fb[ p ] );
			} else if ( fa[ p ] !== fb[ p ] ) {
				report( 'FAIL', where, p + ': ' + fa[ p ] + ' → ' + fb[ p ] );
			}
		}
	}
}
if ( ! only.length ) {
	for ( const who of accounts( A.editorPanels ) ) {
		if ( ! B.editorPanels || ! ( who in B.editorPanels ) ) {
			continue;
		}
		const fa = flatValues( A.editorPanels[ who ] );
		const fb = flatValues( B.editorPanels[ who ] );
		for ( const p of new Set( [ ...Object.keys( fa ), ...Object.keys( fb ) ] ) ) {
			if ( fa[ p ] !== fb[ p ] ) {
				report( p in fb ? ( p in fa ? 'FAIL' : 'REVIEW' ) : 'FAIL', 'editor panels as ' + who, p + ': ' + ( fa[ p ] ?? '(none)' ) + ' → ' + ( fb[ p ] ?? '(none)' ) );
			}
		}
	}
}

// Response shapes.
const set = ( s ) => new Set( String( s || '' ).split( '|' ).filter( Boolean ) );
const base = ( t ) => t.replace( /:.*$/, '' );
// Paths whose value was only ever empty or null on a side: what sits under
// them on the other side cannot be compared.
function dead( shape ) {
	const out = [];
	for ( const [ p, t ] of Object.entries( shape || {} ) ) {
		const ts = [ ...set( t ) ].filter( ( x ) => 'absent' !== x );
		if ( ts.length && ts.every( ( x ) => 'list:empty' === x || 'null' === x || 'object:empty' === x ) ) {
			out.push( p );
		}
	}
	return out;
}
const under = ( p, prefixes ) => prefixes.some( ( d ) => p !== d && ( p.startsWith( d + '.' ) || p.startsWith( d + '[' ) ) );
function diffShape( where, sa, sb ) {
	const da = dead( sa );
	const db = dead( sb );
	for ( const p of new Set( [ ...Object.keys( sa ), ...Object.keys( sb ) ] ) ) {
		if ( ! ( p in sb ) ) {
			if ( under( p, db ) ) {
				skipped++;
			} else {
				report( 'FAIL', where, p + ' removed (was ' + sa[ p ] + ')' );
			}
			continue;
		}
		if ( ! ( p in sa ) ) {
			if ( under( p, da ) ) {
				skipped++;
			} else {
				report( 'REVIEW', where, p + ' added: ' + sb[ p ] );
			}
			continue;
		}
		if ( sa[ p ] === sb[ p ] ) {
			continue;
		}
		const ta = set( sa[ p ] );
		const tb = set( sb[ p ] );
		const solid = ( ts ) => new Set( [ ...ts ].filter( ( x ) => 'absent' !== x && 'null' !== x ).map( base ) );
		const ba = solid( ta );
		const bb = solid( tb );
		if ( ba.size && bb.size && [ ...ba ].sort().join() !== [ ...bb ].sort().join() ) {
			report( 'FAIL', where, p + ' type ' + sa[ p ] + ' → ' + sb[ p ] );
			continue;
		}
		if ( tb.has( 'absent' ) && ! ta.has( 'absent' ) ) {
			report( 'FAIL', where, p + ' is now sometimes missing' );
		}
		if ( tb.has( 'null' ) && ! ta.has( 'null' ) ) {
			report( 'REVIEW', where, p + ' may now be null' );
		}
		const fmt = ( ts ) => new Set( [ ...ts ].filter( ( x ) => x.startsWith( 'string:' ) && 'string:empty' !== x ) );
		const fa = fmt( ta );
		const fb = fmt( tb );
		const lost = [ ...fa ].filter( ( x ) => ! fb.has( x ) );
		const gained = [ ...fb ].filter( ( x ) => ! fa.has( x ) );
		if ( lost.length && fb.size ) {
			report( 'FAIL', where, p + ' format ' + sa[ p ] + ' → ' + sb[ p ] );
		} else if ( gained.length ) {
			report( 'REVIEW', where, p + ' format ' + sa[ p ] + ' → ' + sb[ p ] );
		}
	}
}
const statusClass = ( s ) => ( 'number' === typeof s ? Math.floor( s / 100 ) + 'xx' : String( s ) );
for ( const key of new Set( [ ...Object.keys( A.responses ), ...Object.keys( B.responses ) ] ) ) {
	const ra = A.responses[ key ] || {};
	const rb = B.responses[ key ] || {};
	const any = Object.values( ra )[ 0 ] || Object.values( rb )[ 0 ] || {};
	const anyB = Object.values( rb )[ 0 ] || {};
	if ( ! wanted( key, any.route, any.file, anyB.file, ...( any.via || [] ) ) ) {
		continue;
	}
	for ( const who of new Set( [ ...accounts( ra ), ...accounts( rb ) ] ) ) {
		if ( ! A.meta.accounts.includes( who ) || ! B.meta.accounts.includes( who ) ) {
			continue;
		}
		const a = ra[ who ];
		const b = rb[ who ];
		const where = key + ' as ' + who;
		if ( ! b ) {
			report( 'FAIL', where, 'no longer probed (its route or the descriptor that leads there is gone)' );
			continue;
		}
		if ( ! a ) {
			report( 'REVIEW', where, 'newly probed: ' + b.status );
			continue;
		}
		if ( statusClass( a.status ) !== statusClass( b.status ) ) {
			report( 'FAIL', where, 'status ' + a.status + ' → ' + b.status + ( b.error ? ' (' + b.error + ')' : '' ) );
			continue;
		}
		if ( a.status !== b.status ) {
			report( 'REVIEW', where, 'status ' + a.status + ' → ' + b.status );
		}
		if ( ( a.error || '' ) !== ( b.error || '' ) ) {
			report( 'REVIEW', where, 'error code ' + ( a.error || '(none)' ) + ' → ' + ( b.error || '(none)' ) );
		}
		if ( a.shape && b.shape ) {
			diffShape( where, a.shape, b.shape );
		}
		const na = ( a.notes || [] ).join( '; ' );
		const nb = ( b.notes || [] ).join( '; ' );
		if ( na !== nb ) {
			report( 'REVIEW', where, 'guard notes "' + na + '" → "' + nb + '"' );
		}
	}
}

const order = { FAIL: 0, REVIEW: 1, ACCEPTED: 2 };
lines.sort( ( x, y ) => order[ x.level ] - order[ y.level ] || x.msg.localeCompare( y.msg ) );
for ( const l of lines ) {
	console.log( ( l.level + '      ' ).slice( 0, 9 ) + l.msg );
}
const count = ( lvl ) => lines.filter( ( l ) => l.level === lvl ).length;
console.log( '\nparity: ' + count( 'FAIL' ) + ' fail, ' + count( 'REVIEW' ) + ' review, ' + count( 'ACCEPTED' ) + ' accepted, ' + skipped + ' paths not comparable (empty or null on one side)' );
console.log( '  before ' + A.meta.commit.slice( 0, 8 ) + ( A.meta.dirty ? '+dirty' : '' ) + ' ' + A.meta.at + '\n  after  ' + B.meta.commit.slice( 0, 8 ) + ( B.meta.dirty ? '+dirty' : '' ) + ' ' + B.meta.at );
for ( const [ cap, label ] of [ [ A, 'before' ], [ B, 'after' ] ] ) {
	for ( const [ who, keys ] of Object.entries( cap.skipped || {} ) ) {
		console.log( '  ' + label + ' skipped as ' + who + ': ' + keys.join( ', ' ) );
	}
}
process.exit( count( 'FAIL' ) || ( strict && count( 'REVIEW' ) ) ? 1 : 0 );
