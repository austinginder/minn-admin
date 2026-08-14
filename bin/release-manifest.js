/**
 * Stamps built language packs into manifest.json.
 *
 * Reads dist/languages/*.zip, computes each sha256, and writes the
 * `translations` array the updater feeds into WordPress's own translation
 * update path. Run AFTER build-packs.sh and after the release tag is known.
 *
 * The plugin's own sha256 is stamped separately (release step 3b) because
 * manifest.json ships INSIDE the plugin zip and cannot contain its own hash.
 * Language packs have no such problem: they are separate downloads, so their
 * hashes can live in the manifest that describes them.
 *
 * Usage:
 *   node bin/release-manifest.js v0.30.0
 *   node bin/release-manifest.js v0.30.0 --dry
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );

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

const stamp = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 19 );
const translations = [];

for ( const file of fs.readdirSync( DIST ).sort() ) {
	const m = /^minn-admin-(.+)\.zip$/.exec( file );
	if ( ! m ) continue;
	const locale = m[ 1 ];
	const buf = fs.readFileSync( path.join( DIST, file ) );
	translations.push( {
		language: locale,
		version,
		updated: stamp,
		package: `https://github.com/${ REPO }/releases/download/${ tag }/${ file }`,
		sha256: crypto.createHash( 'sha256' ).update( buf ).digest( 'hex' ),
	} );
}

if ( ! translations.length ) {
	console.error( 'no minn-admin-<locale>.zip files found' );
	process.exit( 1 );
}

manifest.translations = translations;

console.log( `${ translations.length } language packs for ${ tag }:` );
for ( const t of translations ) {
	console.log( `  ${ t.language.padEnd( 6 ) } ${ t.sha256.slice( 0, 16 ) }…` );
}

if ( dry ) { console.log( '\n(dry run, manifest.json unchanged)' ); process.exit( 0 ); }
fs.writeFileSync( MANIFEST, JSON.stringify( manifest, null, 4 ) + '\n' );
console.log( `\nstamped into ${ path.relative( ROOT, MANIFEST ) }` );
