/**
 * Records a human correction to a catalog entry, permanently.
 *
 * Generated entries are replaceable: every regeneration overwrites them.
 * An entry marked `minn-reviewed` is never regenerated and never sent to a
 * translator again. This is the path from "generated" to "maintained", and
 * it is what a native speaker's pull request should touch.
 *
 * Usage:
 *   node review.js <locale> <msgid> <translation> [<plural2> …]
 *   node review.js <locale> --list
 *   node review.js <locale> --from-file corrections.json
 *
 * corrections.json: { "Source string": "Translation", … }
 *                or { "Source string": ["form1", "form2", …], … }
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const { parsePo, writePo } = require( './po.js' );
const { byCode, nplurals } = require( './locales.js' );
const { checkEntry } = require( './validate.js' );

const ROOT = path.resolve( __dirname, '../..' );
const REVIEWED = 'minn-reviewed';

const code = process.argv[ 2 ];
const loc = code && byCode( code );
if ( ! loc ) {
	console.error( 'usage: node review.js <locale> <msgid> <translation> [<plural2> …]' );
	console.error( '       node review.js <locale> --list' );
	console.error( '       node review.js <locale> --from-file corrections.json' );
	process.exit( 2 );
}

const poPath = path.join( ROOT, 'languages', `${ loc.code }.po` );
if ( ! fs.existsSync( poPath ) ) { console.error( `no catalog at ${ poPath }` ); process.exit( 1 ); }
const { header, entries } = parsePo( fs.readFileSync( poPath, 'utf8' ) );

if ( process.argv.includes( '--list' ) ) {
	const marked = entries.filter( ( e ) => e.flags.includes( REVIEWED ) );
	console.log( `${ loc.code }: ${ marked.length } reviewed entr${ marked.length === 1 ? 'y' : 'ies' }` );
	for ( const e of marked ) console.log( `  ${ JSON.stringify( e.msgid.slice( 0, 50 ) ) } -> ${ JSON.stringify( e.msgstr[ 0 ] ) }` );
	process.exit( 0 );
}

let corrections = {};
const ff = process.argv.indexOf( '--from-file' );
if ( ff > 0 ) {
	corrections = JSON.parse( fs.readFileSync( process.argv[ ff + 1 ], 'utf8' ) );
} else {
	const msgid = process.argv[ 3 ];
	const forms = process.argv.slice( 4 );
	if ( ! msgid || ! forms.length ) { console.error( 'need a msgid and at least one translation' ); process.exit( 2 ); }
	corrections[ msgid ] = forms;
}

const np = nplurals( loc );
const byId = new Map( entries.map( ( e ) => [ e.msgid, e ] ) );
let applied = 0, missing = [], rejected = [];

for ( const [ msgid, value ] of Object.entries( corrections ) ) {
	const entry = byId.get( msgid );
	if ( ! entry ) { missing.push( msgid ); continue; }
	const forms = Array.isArray( value ) ? value : [ value ];
	const candidate = Object.assign( {}, entry, { msgstr: forms } );
	// A hand correction is checked like any other entry: a human can drop a
	// placeholder as easily as a machine can, and the consequence is the same.
	const r = checkEntry( candidate, np );
	if ( ! r.ok ) { rejected.push( `${ JSON.stringify( msgid.slice( 0, 40 ) ) }: ${ r.reason }` ); continue; }
	entry.msgstr = forms;
	if ( ! entry.flags.includes( REVIEWED ) ) entry.flags.push( REVIEWED );
	applied++;
}

if ( applied ) fs.writeFileSync( poPath, writePo( header, entries ) );

console.log( `${ loc.code }: ${ applied } correction(s) recorded as ${ REVIEWED }` );
for ( const m of missing ) console.log( `  NOT IN CATALOG ${ JSON.stringify( m.slice( 0, 50 ) ) }` );
for ( const r of rejected ) console.log( `  REJECTED ${ r }` );
process.exit( rejected.length ? 1 : 0 );
