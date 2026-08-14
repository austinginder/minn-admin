/**
 * The locales Minn ships, with the gettext Plural-Forms rule for each.
 *
 * Wave 1 is the ten largest non-English WordPress locales plus three that
 * earn their place on other grounds: en_GB (a spelling pass that also serves
 * en_AU/en_CA/en_NZ/en_ZA), fa_IR (13th largest, and it arrives with a
 * maintainer), and ar (proves the RTL work is not Persian-specific).
 *
 * Shares are from https://api.wordpress.org/stats/locale/1.0/ as a
 * percentage of all WordPress installs.
 */
'use strict';

const LOCALES = [
	// --- wave 1 -----------------------------------------------------------
	{ code: 'ja',    name: 'Japanese',             share: 5.96, wave: 1, rtl: false, plural: 'nplurals=1; plural=0;' },
	{ code: 'es_ES', name: 'Spanish (Spain)',      share: 5.78, wave: 1, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'de_DE', name: 'German',               share: 5.65, wave: 1, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'fr_FR', name: 'French',               share: 4.58, wave: 1, rtl: false, plural: 'nplurals=2; plural=n > 1;' },
	{ code: 'pt_BR', name: 'Portuguese (Brazil)',  share: 3.89, wave: 1, rtl: false, plural: 'nplurals=2; plural=n > 1;' },
	{ code: 'en_GB', name: 'English (UK)',         share: 3.24, wave: 1, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'it_IT', name: 'Italian',              share: 3.17, wave: 1, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'nl_NL', name: 'Dutch',                share: 2.41, wave: 1, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'ru_RU', name: 'Russian',              share: 2.11, wave: 1, rtl: false, plural: 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);' },
	{ code: 'pl_PL', name: 'Polish',               share: 2.10, wave: 1, rtl: false, plural: 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);' },
	{ code: 'tr_TR', name: 'Turkish',              share: 1.30, wave: 1, rtl: false, plural: 'nplurals=2; plural=n > 1;' },
	{ code: 'fa_IR', name: 'Persian',              share: 1.00, wave: 1, rtl: true,  plural: 'nplurals=2; plural=(n > 1);' },
	{ code: 'ar',    name: 'Arabic',               share: 0.45, wave: 1, rtl: true,  plural: 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);' },

	// --- wave 2 -----------------------------------------------------------
	{ code: 'vi',    name: 'Vietnamese',           share: 1.16, wave: 2, rtl: false, plural: 'nplurals=1; plural=0;' },
	{ code: 'id_ID', name: 'Indonesian',           share: 1.02, wave: 2, rtl: false, plural: 'nplurals=1; plural=0;' },
	{ code: 'cs_CZ', name: 'Czech',                share: 0.60, wave: 2, rtl: false, plural: 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;' },
	{ code: 'sv_SE', name: 'Swedish',              share: 0.59, wave: 2, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'zh_CN', name: 'Chinese (China)',      share: 0.57, wave: 2, rtl: false, plural: 'nplurals=1; plural=0;' },
	{ code: 'pt_PT', name: 'Portuguese (Portugal)',share: 0.56, wave: 2, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'hu_HU', name: 'Hungarian',            share: 0.54, wave: 2, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'es_MX', name: 'Spanish (Mexico)',     share: 0.49, wave: 2, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'da_DK', name: 'Danish',               share: 0.47, wave: 2, rtl: false, plural: 'nplurals=2; plural=n != 1;' },
	{ code: 'he_IL', name: 'Hebrew',               share: 0.41, wave: 2, rtl: true,  plural: 'nplurals=2; plural=n != 1;' },
];

const byCode = ( code ) => LOCALES.find( ( l ) => l.code === code );
const wave = ( n ) => LOCALES.filter( ( l ) => l.wave === n );
const nplurals = ( locale ) => {
	const m = /nplurals\s*=\s*(\d+)/.exec( locale.plural );
	return m ? parseInt( m[ 1 ], 10 ) : 2;
};

/**
 * Locales served by ANOTHER locale's catalog, byte for byte.
 *
 * en_GB is a spelling pass — colour, customise, licence — and Australian,
 * Canadian, New Zealand and South African English take the same spellings.
 * Emitting the same catalog under their codes costs one zip each and reaches
 * another ~1.5% of installs, where translating them separately would mean
 * four identical files to keep in step.
 *
 * Nothing else belongs here. pt_PT and es_MX differ from pt_BR and es_ES in
 * vocabulary, not just orthography, and get their own catalogs in wave 2.
 */
const ALIASES = {
	en_GB: [ 'en_AU', 'en_CA', 'en_NZ', 'en_ZA' ],
};

/** Every locale a given catalog should be packed for, itself first. */
const packedAs = ( code ) => [ code, ...( ALIASES[ code ] || [] ) ];

module.exports = { LOCALES, byCode, wave, nplurals, ALIASES, packedAs };

if ( require.main === module ) {
	const w = process.argv[ 2 ] ? Number( process.argv[ 2 ] ) : null;
	const list = w ? wave( w ) : LOCALES;
	let total = 0;
	for ( const l of list ) {
		total += l.share;
		console.log( `${ l.code.padEnd( 6 ) } ${ String( l.share ).padStart( 5 ) }%  ${ nplurals( l ) } form(s)${ l.rtl ? '  RTL' : '' }  ${ l.name }` );
	}
	console.log( `\n${ list.length } locales, ${ total.toFixed( 2 ) }% of WordPress installs` );
}
