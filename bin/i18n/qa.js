/**
 * Quality report for a translated catalog.
 *
 * validate.js answers "is this safe to ship" and drops what is not. This
 * answers the softer question: does it look like somebody actually translated
 * it, or did a batch quietly come back as the English source?
 *
 * Nothing here rejects a catalog. It surfaces the patterns worth a human
 * glance before a release.
 *
 * Usage: node qa.js <locale> [--verbose]
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const { parsePo } = require( './po.js' );
const { byCode, nplurals } = require( './locales.js' );
const { placeholders } = require( './validate.js' );

const ROOT = path.resolve( __dirname, '../..' );

// Names that SHOULD come back unchanged; an identical translation here is
// correct, not a miss.
const BRANDS = /^(WordPress|WooCommerce|Gutenberg|Elementor|Minn Admin|Minn|Akismet|Yoast|Jetpack|Matomo|Cloudflare|GitHub|Google|SEO|CSS|HTML|PHP|URL|API|ID|SVG|PDF|JSON|SQL|SMTP|DNS|HTTPS?|RSS|UTC|GMT)$/i;

const code = process.argv[ 2 ];
const loc = code && byCode( code );
if ( ! loc ) { console.error( 'usage: node qa.js <locale> [--verbose]' ); process.exit( 2 ); }
const verbose = process.argv.includes( '--verbose' );

const poPath = path.join( ROOT, 'languages', `${ loc.code }.po` );
if ( ! fs.existsSync( poPath ) ) { console.error( `no catalog at ${ poPath }` ); process.exit( 1 ); }

const { entries } = parsePo( fs.readFileSync( poPath, 'utf8' ) );
const potCount = parsePo( fs.readFileSync( path.join( ROOT, 'languages/minn-admin.pot' ), 'utf8' ) ).entries.length;
const np = nplurals( loc );
const isEnglishVariant = /^en_/.test( loc.code );

const same = [], suspicious = [], longer = [], pluralIssues = [];
let translated = 0;

for ( const e of entries ) {
	const first = e.msgstr[ 0 ] || '';
	if ( ! first ) continue;
	translated++;

	if ( first === e.msgid ) {
		if ( ! BRANDS.test( e.msgid.trim() ) ) same.push( e.msgid );
		continue;
	}

	// A translation that kept ASCII-only text in a non-Latin locale is worth
	// a look: it usually means the batch came back in English.
	if ( /^(ja|ru_RU|ar|fa_IR|he_IL|zh_CN)$/.test( loc.code ) && /^[\x20-\x7E]+$/.test( first ) && /[A-Za-z]{4,}/.test( first ) ) {
		suspicious.push( `${ JSON.stringify( e.msgid.slice( 0, 40 ) ) } -> ${ JSON.stringify( first.slice( 0, 40 ) ) }` );
	}

	// Interface strings live in fixed-width furniture; flag runaway growth.
	if ( e.msgid.length >= 12 && first.length > e.msgid.length * 2.4 ) {
		longer.push( `${ JSON.stringify( e.msgid.slice( 0, 36 ) ) } (${ e.msgid.length }) -> (${ first.length })` );
	}

	if ( e.msgidPlural != null && e.msgstr.filter( Boolean ).length !== np ) {
		pluralIssues.push( e.msgid.slice( 0, 40 ) );
	}
}

// Placeholder-bearing entries are the ones a mistake actually breaks.
let withPlaceholders = 0, placeholderOk = 0;
for ( const e of entries ) {
	if ( ! placeholders( e.msgid ).length ) continue;
	withPlaceholders++;
	const want = placeholders( e.msgid ).join( ' ' );
	if ( e.msgstr.every( ( f ) => ! f || placeholders( f ).join( ' ' ) === want
		|| ( e.msgidPlural && placeholders( f ).join( ' ' ) === placeholders( e.msgidPlural ).join( ' ' ) ) ) ) placeholderOk++;
}

const pct = ( n ) => ( n / potCount * 100 ).toFixed( 1 ) + '%';
console.log( `${ loc.code } (${ loc.name })` );
console.log( `  entries         ${ translated } of ${ potCount }  (${ pct( translated ) })` );
console.log( `  placeholders    ${ placeholderOk }/${ withPlaceholders } entries carry them correctly` );
console.log( `  plural forms    ${ pluralIssues.length ? pluralIssues.length + ' WRONG COUNT' : 'all correct' }` );
console.log( `  identical to English  ${ same.length }${ isEnglishVariant ? '  (expected for an English variant)' : '' }` );
if ( suspicious.length ) console.log( `  LOOKS UNTRANSLATED    ${ suspicious.length }` );
if ( longer.length ) console.log( `  much longer than source  ${ longer.length }` );

if ( verbose ) {
	const show = ( label, list ) => {
		if ( ! list.length ) return;
		console.log( `\n  ${ label }:` );
		for ( const s of list.slice( 0, 25 ) ) console.log( `    ${ s }` );
		if ( list.length > 25 ) console.log( `    … and ${ list.length - 25 } more` );
	};
	show( 'identical to English', same.map( ( s ) => JSON.stringify( s.slice( 0, 60 ) ) ) );
	show( 'looks untranslated', suspicious );
	show( 'much longer than source', longer );
	show( 'wrong plural form count', pluralIssues );
}
