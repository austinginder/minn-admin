/**
 * Builds a bilingual glossary from WordPress core's OWN translations.
 *
 * This is the highest-value, lowest-risk step in the pipeline and it costs
 * nothing. Minn replaces wp-admin, so its vocabulary should match what the
 * user already sees there: a German user expects "Beiträge", not a plausible
 * synonym a model picked. Where a Minn msgid matches a core msgid exactly,
 * core's community-reviewed translation is used verbatim and no model is
 * asked anything.
 *
 * Source order matters: admin.mo carries the admin vocabulary and wins over
 * the general core catalog.
 *
 * Usage:
 *   node core-glossary.js <locale> [--lang-dir <path>]   # prints coverage
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const https = require( 'https' );
const { parseMo } = require( './po.js' );

const DEFAULT_LANG_DIR = path.resolve( __dirname, '../../../../languages' );

/** Core catalogs, least specific first so admin wins on conflict. */
const CORE_FILES = ( locale ) => [
	`${ locale }.mo`,
	`admin-${ locale }.mo`,
	`admin-network-${ locale }.mo`,
];

/**
 * Load core's translations for a locale from an existing WP languages dir.
 * @returns {Map<string,string[]>} msgid => translated forms
 */
function loadCoreGlossary( locale, langDir = DEFAULT_LANG_DIR ) {
	const map = new Map();
	for ( const file of CORE_FILES( locale ) ) {
		const p = path.join( langDir, file );
		if ( ! fs.existsSync( p ) ) continue;
		try {
			for ( const [ k, v ] of parseMo( fs.readFileSync( p ) ) ) {
				if ( k && v && v[ 0 ] ) map.set( k, v );
			}
		} catch ( e ) {
			process.stderr.write( `  (skipped ${ file }: ${ e.message })\n` );
		}
	}
	return map;
}

/**
 * Key used for near-matching: case-folded, trailing punctuation and the
 * ellipsis dropped. "Save changes…" and "Save Changes" both reduce to
 * "save changes", so core's reviewed wording still wins for a label Minn
 * merely punctuates differently.
 */
const normKey = ( s ) => s.toLowerCase().replace( /[\s.:…!?]+$/u, '' ).trim();

/**
 * Look a msgid up in the glossary, exact first then normalized. Returns
 * { forms, exact } or null. On a normalized hit the ORIGINAL trailing
 * punctuation is re-applied, so "Save changes…" stays an ellipsis in German.
 */
function lookup( glossary, normIndex, msgid ) {
	const exact = glossary.get( msgid );
	if ( exact ) return { forms: exact, exact: true };
	const near = normIndex.get( normKey( msgid ) );
	if ( ! near ) return null;
	const tail = /([\s.:…!?]+)$/u.exec( msgid );
	const suffix = tail ? tail[ 1 ] : '';
	const forms = near.map( ( f ) => f.replace( /[\s.:…!?]+$/u, '' ) + suffix );

	// A "translation" that differs from the source only in CASE is not a
	// translation, it is core's house style. Minn writes labels in sentence
	// case ("All sites"); core writes several of them in title case ("All
	// Sites"). Taking core's casing here silently retitled the interface for
	// the English variants, where the normalized match is the only kind that
	// ever fires. Keep the source and let the translator decide.
	if ( forms[ 0 ] && normKey( forms[ 0 ] ).toLowerCase() === normKey( msgid ).toLowerCase()
		&& forms[ 0 ] !== msgid ) {
		return null;
	}
	return { forms, exact: false };
}

/**
 * Whether core's translation may be taken VERBATIM for this msgid.
 *
 * Only multi-word strings. A single English word can mean different things in
 * different products, and core's catalog answers for core's meaning: Dutch
 * core renders "Table" as "Tafel" (the furniture), "Order" as "Volgorde" (a
 * sequence, not a purchase) and "Find" as "Vind". Applied blindly those are
 * wrong in an admin that lists database tables and WooCommerce orders.
 *
 * Multi-word strings carry their own context and are safe. Single words still
 * reach the translator, with core's rendering attached as a suggestion, so
 * the shared vocabulary is kept where it fits and rejected where it does not.
 */
const coreMayAnswer = ( msgid ) => /\s/.test( String( msgid ).trim() );

/** Build the normalized index once per locale. */
function buildNormIndex( glossary ) {
	const idx = new Map();
	for ( const [ k, v ] of glossary ) {
		const nk = normKey( k );
		if ( nk && ! idx.has( nk ) ) idx.set( nk, v );
	}
	return idx;
}

/** Download core's language pack for a locale into langDir (zip of .mo/.po/.json). */
function corePackUrl( locale, wpVersion ) {
	return `https://downloads.wordpress.org/translation/core/${ wpVersion }/${ locale }.zip`;
}

function download( url, dest ) {
	return new Promise( ( resolve, reject ) => {
		const file = fs.createWriteStream( dest );
		https.get( url, ( res ) => {
			if ( res.statusCode >= 300 && res.statusCode < 400 && res.headers.location ) {
				file.close();
				return resolve( download( res.headers.location, dest ) );
			}
			if ( 200 !== res.statusCode ) { file.close(); return reject( new Error( `HTTP ${ res.statusCode } for ${ url }` ) ); }
			res.pipe( file );
			file.on( 'finish', () => file.close( () => resolve( dest ) ) );
		} ).on( 'error', reject );
	} );
}

module.exports = { loadCoreGlossary, buildNormIndex, lookup, normKey, coreMayAnswer, corePackUrl, download };

if ( require.main === module ) {
	const locale = process.argv[ 2 ];
	if ( ! locale ) { console.error( 'usage: node core-glossary.js <locale>' ); process.exit( 2 ); }
	const dirFlag = process.argv.indexOf( '--lang-dir' );
	const langDir = dirFlag > 0 ? process.argv[ dirFlag + 1 ] : DEFAULT_LANG_DIR;
	const g = loadCoreGlossary( locale, langDir );
	console.log( `core glossary for ${ locale }: ${ g.size } entries (from ${ langDir })` );

	// Coverage against Minn's catalog.
	const { parsePo } = require( './po.js' );
	const potPath = path.resolve( __dirname, '../../languages/minn-admin.pot' );
	const { entries } = parsePo( fs.readFileSync( potPath, 'utf8' ) );
	const idx = buildNormIndex( g );
	let hit = 0, exactHit = 0;
	for ( const e of entries ) {
		const r = lookup( g, idx, e.msgid );
		if ( r ) { hit++; if ( r.exact ) exactHit++; }
	}
	const pct = ( hit / entries.length * 100 ).toFixed( 1 );
	console.log( `Minn msgids: ${ entries.length }` );
	console.log( `matched from core: ${ hit } (${ pct }%)  [exact ${ exactHit }, normalized ${ hit - exactHit }]` );
	console.log( `needing translation: ${ entries.length - hit }` );
	if ( process.argv.includes( '--sample' ) ) {
		console.log( '\nsample matches:' );
		let n = 0;
		for ( const e of entries ) {
			if ( ! g.has( e.msgid ) || n++ >= 12 ) continue;
			console.log( `  ${ JSON.stringify( e.msgid ) } -> ${ JSON.stringify( g.get( e.msgid )[ 0 ] ) }` );
		}
	}
}
