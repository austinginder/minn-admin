/**
 * Catalog validator. Generated translations are not safe to ship without it.
 *
 * The load-bearing check is PLACEHOLDER PARITY. PHP 8's sprintf() throws
 * ArgumentCountError when arguments run short, so a translation that drops a
 * %s is not a cosmetic problem: it is a fatal error in somebody else's admin,
 * in a locale the author cannot read. The JS side is more forgiving (it
 * prints "undefined") but just as wrong.
 *
 * Everything that fails validation is DROPPED from the catalog rather than
 * blocking the build. English is the source vocabulary, so a dropped entry
 * falls through to English: degraded, never broken.
 */
'use strict';

/** Extract printf placeholders, ignoring WordPress permalink tags (%year%). */
function placeholders( s ) {
	const cleaned = String( s ).replace( /%[a-z_]+%/g, '' );
	return ( cleaned.match( /%(\d+\$)?[sd]/g ) || [] ).sort();
}

const sameSet = ( a, b ) => a.length === b.length && a.every( ( v, i ) => v === b[ i ] );

/**
 * Validate one entry against its source.
 * @returns {{ok: boolean, reason?: string}}
 */
function checkEntry( entry, nplurals ) {
	const forms = entry.msgstr || [];
	const filled = forms.filter( ( f ) => f && f.trim() !== '' );
	if ( ! filled.length ) return { ok: false, reason: 'empty' };

	// Plural entries must carry exactly the locale's form count.
	if ( entry.msgidPlural != null ) {
		if ( forms.length !== nplurals ) {
			return { ok: false, reason: `expected ${ nplurals } plural forms, got ${ forms.length }` };
		}
		if ( filled.length !== nplurals ) return { ok: false, reason: 'a plural form is empty' };
	} else if ( forms.length > 1 ) {
		return { ok: false, reason: 'singular entry carries plural forms' };
	}

	// Placeholder parity, per form. A plural form may legitimately reference
	// the singular OR the plural source, so accept either.
	const wantSingular = placeholders( entry.msgid );
	const wantPlural = entry.msgidPlural != null ? placeholders( entry.msgidPlural ) : wantSingular;
	for ( let i = 0; i < forms.length; i++ ) {
		const got = placeholders( forms[ i ] );
		if ( ! sameSet( got, wantSingular ) && ! sameSet( got, wantPlural ) ) {
			return {
				ok: false,
				reason: `placeholder mismatch in form ${ i }: source has [${ wantSingular.join( ' ' ) }], translation has [${ got.join( ' ' ) }]`,
			};
		}
	}

	// A translation that is byte-identical to the source is untranslated
	// noise for most locales, but legitimate for proper nouns and for the
	// English variants, so it is not an error.
	return { ok: true };
}

/**
 * Validate a whole catalog.
 * @returns {{kept: Array, dropped: Array<{entry, reason}>}}
 */
function validateCatalog( entries, nplurals ) {
	const kept = [], dropped = [];
	for ( const e of entries ) {
		const r = checkEntry( e, nplurals );
		if ( r.ok ) kept.push( e );
		else dropped.push( { entry: e, reason: r.reason } );
	}
	return { kept, dropped };
}

module.exports = { placeholders, checkEntry, validateCatalog };

if ( require.main === module ) {
	const fs = require( 'fs' );
	const path = require( 'path' );
	const { parsePo } = require( './po.js' );
	const { byCode, nplurals } = require( './locales.js' );
	const file = process.argv[ 2 ];
	if ( ! file ) { console.error( 'usage: node validate.js <file.po>' ); process.exit( 2 ); }
	const code = path.basename( file, '.po' );
	const loc = byCode( code );
	if ( ! loc ) { console.error( `unknown locale: ${ code }` ); process.exit( 2 ); }
	const { entries } = parsePo( fs.readFileSync( file, 'utf8' ) );
	const { kept, dropped } = validateCatalog( entries, nplurals( loc ) );
	console.log( `${ code }: ${ kept.length } valid, ${ dropped.length } dropped` );
	for ( const d of dropped.slice( 0, 20 ) ) {
		console.log( `  DROP ${ JSON.stringify( d.entry.msgid.slice( 0, 50 ) ) }: ${ d.reason }` );
	}
	process.exit( 0 );
}
