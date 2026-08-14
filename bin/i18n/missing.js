/**
 * How many catalog entries a locale is still missing, POT-relative.
 *
 * An entry counts as answered when the reviewed file, the existing catalog,
 * or the core glossary supplies it — the same three layers import-batch.js
 * assembles from, so a zero here means an import would produce a complete
 * catalog. release.sh gates on this; the translation itself happens in a
 * Claude Code session through export-batch.js / import-batch.js.
 *
 * Usage:
 *   node missing.js <locale>      one line: "<locale> <missing>"
 *   node missing.js --all         every locale with a catalog, nonzero exit
 *                                 when anything is missing
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const { parsePo } = require( './po.js' );
const { loadCoreGlossary, buildNormIndex, lookup, coreMayAnswer } = require( './core-glossary.js' );
const { byCode, LOCALES } = require( './locales.js' );

const ROOT = path.resolve( __dirname, '../..' );
const POT = path.join( ROOT, 'languages/minn-admin.pot' );

const missingFor = ( loc ) => {
	const { entries } = parsePo( fs.readFileSync( POT, 'utf8' ) );
	const answered = new Set();
	const reviewedPath = path.join( ROOT, 'languages/reviewed', `${ loc.code }.po` );
	if ( fs.existsSync( reviewedPath ) ) {
		for ( const e of parsePo( fs.readFileSync( reviewedPath, 'utf8' ) ).entries ) {
			if ( e.msgid && e.msgstr.some( Boolean ) ) answered.add( e.msgid );
		}
	}
	const poPath = path.join( ROOT, 'languages', `${ loc.code }.po` );
	if ( fs.existsSync( poPath ) ) {
		for ( const e of parsePo( fs.readFileSync( poPath, 'utf8' ) ).entries ) {
			if ( e.msgid && e.msgstr.some( Boolean ) ) answered.add( e.msgid );
		}
	}
	const glossary = loadCoreGlossary( loc.code );
	const normIdx = buildNormIndex( glossary );
	let missing = 0;
	for ( const e of entries ) {
		if ( ! e.msgid || answered.has( e.msgid ) ) continue;
		if ( e.msgidPlural == null && coreMayAnswer( e.msgid ) ) {
			const hit = lookup( glossary, normIdx, e.msgid );
			if ( hit && hit.forms[ 0 ] ) continue;
		}
		missing++;
	}
	return missing;
};

if ( process.argv[ 2 ] === '--all' ) {
	let worst = 0;
	for ( const loc of LOCALES ) {
		if ( ! fs.existsSync( path.join( ROOT, 'languages', `${ loc.code }.po` ) ) ) continue;
		const n = missingFor( loc );
		worst = Math.max( worst, n );
		console.log( `${ loc.code.padEnd( 6 ) } ${ n }` );
	}
	process.exit( worst > 0 ? 1 : 0 );
}

const loc = byCode( process.argv[ 2 ] || '' );
if ( ! loc ) { console.error( 'usage: node missing.js <locale> | --all' ); process.exit( 2 ); }
const n = missingFor( loc );
console.log( `${ loc.code } ${ n }` );
process.exit( n > 0 ? 1 : 0 );
