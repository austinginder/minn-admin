'use strict';
/**
 * Plural-Forms evaluator, checked against the real gettext rules for every
 * wave-1 locale. English's "n != 1" is the exception: Japanese has one form,
 * Russian and Polish three, Arabic six, so a hardcoded rule is wrong in most
 * shipped locales. Also asserts a hostile rule cannot execute (the evaluator
 * parses, it never eval()s a downloaded catalog).
 *
 * Extracts the pluralRule IIFE from app.js and checks it against the real
 * gettext Plural-Forms rules for every wave-1 locale, using CLDR-derived
 * expected form indexes.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const APP = path.resolve( __dirname, '../assets/js/app.js' );
const src = fs.readFileSync( APP, 'utf8' );

const start = src.indexOf( 'const pluralRule = ( () => {' );
const endMark = '\t} )();';
const end = src.indexOf( endMark, start ) + endMark.length;
const body = src.slice( start, end );

const makeRule = ( expr ) => {
	const B = { i18nPlural: expr };
	// eslint-disable-next-line no-new-func
	return new Function( 'B', body + '\n return pluralRule;' )( B );
};

// Real Plural-Forms headers as WordPress/gettext ship them.
const LOCALES = {
	en_GB: 'nplurals=2; plural=n != 1;',
	de_DE: 'nplurals=2; plural=n != 1;',
	fr_FR: 'nplurals=2; plural=n > 1;',
	it_IT: 'nplurals=2; plural=n != 1;',
	nl_NL: 'nplurals=2; plural=n != 1;',
	es_ES: 'nplurals=2; plural=n != 1;',
	pt_BR: 'nplurals=2; plural=n > 1;',
	tr_TR: 'nplurals=2; plural=n > 1;',
	fa_IR: 'nplurals=2; plural=(n > 1);',
	ja: 'nplurals=1; plural=0;',
	ru_RU: 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
	pl_PL: 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
	ar: 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
};

// n => expected form index
const EXPECT = {
	en_GB: { 0: 1, 1: 0, 2: 1, 21: 1 },
	de_DE: { 0: 1, 1: 0, 2: 1, 100: 1 },
	fr_FR: { 0: 0, 1: 0, 2: 1, 100: 1 },
	it_IT: { 1: 0, 5: 1 },
	nl_NL: { 1: 0, 5: 1 },
	es_ES: { 1: 0, 5: 1 },
	pt_BR: { 0: 0, 1: 0, 2: 1 },
	tr_TR: { 0: 0, 1: 0, 3: 1 },
	fa_IR: { 0: 0, 1: 0, 5: 1 },
	ja: { 0: 0, 1: 0, 2: 0, 99: 0 },
	ru_RU: { 1: 0, 2: 1, 5: 2, 11: 2, 21: 0, 22: 1, 25: 2, 101: 0, 111: 2 },
	pl_PL: { 1: 0, 2: 1, 5: 2, 12: 2, 22: 1, 25: 2 },
	ar: { 0: 0, 1: 1, 2: 2, 3: 3, 10: 3, 11: 4, 99: 4, 100: 5, 102: 5 },
};

let pass = 0, fail = 0;
for ( const [ loc, header ] of Object.entries( LOCALES ) ) {
	const rule = makeRule( header );
	if ( ! rule ) { console.log( `FAIL ${ loc }: rule did not parse` ); fail++; continue; }
	const bad = [];
	for ( const [ n, want ] of Object.entries( EXPECT[ loc ] ) ) {
		const got = rule( Number( n ) );
		if ( got !== want ) bad.push( `n=${ n } want ${ want } got ${ got }` );
	}
	if ( bad.length ) { console.log( `FAIL ${ loc }: ${ bad.join( ', ' ) }` ); fail++; }
	else { console.log( `ok   ${ loc.padEnd( 6 ) } ${ Object.keys( EXPECT[ loc ] ).length } cases` ); pass++; }
}

// A malformed / hostile rule must fall back, never throw or execute.
const hostile = makeRule( 'nplurals=2; plural=(function(){throw 1})();' );
console.log( hostile ? `ok   hostile rule falls back (n=5 -> ${ hostile( 5 ) })` : 'ok   hostile rule rejected at parse' );
const missing = makeRule( '' );
console.log( missing === null ? 'ok   empty rule -> null (English fallback path)' : 'FAIL empty rule should be null' );

console.log( `\nplural-forms: ${ pass }/${ pass + fail } locales correct` );
process.exit( fail ? 1 : 0 );
