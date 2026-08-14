/**
 * Stamps built language packs into manifest.json.
 *
 * Reads dist/languages/*.zip, computes each sha256, and writes the
 * `translations` array the updater feeds into WordPress's own translation
 * update path. Run AFTER build-packs.sh and after the release tag is known.
 *
 * PER-LANGUAGE VERSIONING. An entry's `version` only moves when that
 * language's catalog CONTENT changed. Every entry carries a `catalog` field:
 * the sha256 of the catalog's translated entries (msgid/msgstr pairs, sorted,
 * header excluded — PO-Revision-Date restamps on every pipeline run and zip
 * bytes differ on every build, so neither is an honest change signal). When
 * the hash matches the previous manifest, the WHOLE entry is carried forward
 * untouched: same version, same package URL (the old release's asset, which
 * GitHub keeps serving), same sha256. A site with that pack installed
 * compares versions, sees nothing newer, and is not offered a download.
 * Improve only the Spanish catalog and only Spanish sites see an update.
 *
 * The plugin's own sha256 is stamped separately (release step 3b) because
 * manifest.json ships INSIDE the plugin zip and cannot contain its own hash.
 * Language packs have no such problem: they are separate downloads, so their
 * hashes can live in the manifest that describes them.
 *
 * Output ends with the ATTACH LIST: exactly the zips that must be uploaded
 * as assets on this tag's GitHub release. Carried-forward packs point at
 * prior releases and need nothing uploaded.
 *
 * Usage:
 *   node bin/release-manifest.js v0.30.0
 *   node bin/release-manifest.js v0.30.0 --dry
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );

const { parsePo } = require( './i18n/po.js' );
const { byCode, ALIASES } = require( './i18n/locales.js' );

const ROOT = path.resolve( __dirname, '..' );
const DIST = path.join( ROOT, 'dist/languages' );
const MANIFEST = path.join( ROOT, 'manifest.json' );
const REPO = 'austinginder/minn-admin';

const tag = process.argv[ 2 ];
const dry = process.argv.includes( '--dry' );
if ( ! tag || ! /^v\d+\.\d+\.\d+$/.test( tag ) ) {
	console.error( 'usage: node bin/release-manifest.js v<major>.<minor>.<patch> [--dry]' );
	process.exit( 2 );
}

if ( ! fs.existsSync( DIST ) ) {
	console.error( `no packs in ${ path.relative( ROOT, DIST ) } — run bin/i18n/build-packs.sh first` );
	process.exit( 1 );
}

const manifest = JSON.parse( fs.readFileSync( MANIFEST, 'utf8' ) );
const version = tag.replace( /^v/, '' );
if ( manifest.version !== version ) {
	console.error( `manifest.json says ${ manifest.version } but the tag is ${ version } — bump the version first` );
	process.exit( 1 );
}

const prior = {};
for ( const t of manifest.translations || [] ) {
	if ( t && t.language ) prior[ t.language ] = t;
}

/**
 * Content hash of a catalog: its translated entries only, sorted, with the
 * header entry dropped. Two pipeline runs that changed no translation hash
 * identically even though PO-Revision-Date and source references moved.
 */
const catalogHash = ( poPath ) => {
	const { entries } = parsePo( fs.readFileSync( poPath, 'utf8' ) );
	const rows = entries
		.filter( ( e ) => e.msgid !== '' && e.msgstr.some( Boolean ) )
		.map( ( e ) => [ e.msgctxt || '', e.msgid, e.msgidPlural || '', ...e.msgstr ] )
		// Sort the whole identity and translation, not msgid alone. Contextual
		// entries may share one msgid; if their PO order changes, a msgid-only
		// comparator preserves the new input order and falsely changes the hash.
		.sort( ( a, b ) => JSON.stringify( a ).localeCompare( JSON.stringify( b ) ) );
	return crypto.createHash( 'sha256' ).update( JSON.stringify( rows ) ).digest( 'hex' );
};

// An alias pack (en_AU …) is built from another locale's catalog byte for
// byte, so it shares that catalog's content hash.
const sourceOf = ( locale ) => {
	if ( byCode( locale ) ) return locale;
	for ( const [ src, aliases ] of Object.entries( ALIASES ) ) {
		if ( aliases.includes( locale ) ) return src;
	}
	return locale;
};

const stamp = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 19 );
const translations = [];
const attach = [];
const carried = [];

for ( const file of fs.readdirSync( DIST ).sort() ) {
	const m = /^minn-admin-(.+)\.zip$/.exec( file );
	if ( ! m ) continue;
	const locale = m[ 1 ];
	const src = sourceOf( locale );
	const poPath = path.join( ROOT, 'languages', `${ src }.po` );
	if ( ! fs.existsSync( poPath ) ) {
		console.error( `no catalog languages/${ src }.po behind pack ${ file } — skipping` );
		continue;
	}
	const hash = catalogHash( poPath );
	const old = prior[ locale ];
	if ( old && old.catalog === hash && old.package && old.sha256 ) {
		translations.push( old );
		carried.push( `${ locale } (still ${ old.version })` );
		continue;
	}
	const buf = fs.readFileSync( path.join( DIST, file ) );
	translations.push( {
		language: locale,
		version,
		updated: stamp,
		package: `https://github.com/${ REPO }/releases/download/${ tag }/${ file }`,
		sha256: crypto.createHash( 'sha256' ).update( buf ).digest( 'hex' ),
		catalog: hash,
	} );
	attach.push( file );
}

if ( ! translations.length ) {
	console.error( 'no minn-admin-<locale>.zip files found' );
	process.exit( 1 );
}

manifest.translations = translations;

console.log( `${ translations.length } language packs for ${ tag }:` );
console.log( `  ${ attach.length } new or changed, ${ carried.length } carried forward unchanged` );
if ( carried.length ) console.log( `  carried: ${ carried.join( ', ' ) }` );

if ( dry ) { console.log( '\n(dry run, manifest.json unchanged)' ); process.exit( 0 ); }
fs.writeFileSync( MANIFEST, JSON.stringify( manifest, null, 4 ) + '\n' );
console.log( `\nstamped into ${ path.relative( ROOT, MANIFEST ) }` );

if ( attach.length ) {
	console.log( '\nATTACH to the GitHub release (only these changed):' );
	for ( const f of attach ) console.log( `  dist/languages/${ f }` );
} else {
	console.log( '\nNo pack changed: nothing to attach beyond minn-admin.zip.' );
}
