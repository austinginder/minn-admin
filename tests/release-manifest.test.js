/**
 * Per-language manifest stamping. A catalog that did not change must keep
 * its old package URL and version even when PO entry order and rebuilt zip
 * bytes change; a catalog with a real translation change must move forward.
 *
 * Run: node tests/release-manifest.test.js
 */
'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const tmp = fs.mkdtempSync( path.join( os.tmpdir(), 'minn-manifest-' ) );
const checks = [];
const check = ( label, ok ) => {
	checks.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }` );
};

const header = ( locale ) => `msgid ""
msgstr ""
"Language: ${ locale }\\n"
"Plural-Forms: nplurals=2; plural=n != 1;\\n"

`;
const contextual = ( locale, reverse = false ) => {
	const rows = [
		`msgctxt "button"\nmsgid "Open"\nmsgstr "${ locale === 'de_DE' ? 'Öffnen' : 'Ouvrir' }"\n`,
		`msgctxt "state"\nmsgid "Open"\nmsgstr "${ locale === 'de_DE' ? 'Offen' : 'Ouvert' }"\n`,
	];
	return header( locale ) + ( reverse ? rows.reverse() : rows ).join( '\n' );
};

try {
	for ( const dir of [ 'bin/i18n', 'languages', 'dist/languages' ] ) {
		fs.mkdirSync( path.join( tmp, dir ), { recursive: true } );
	}
	for ( const rel of [ 'bin/release-manifest.js', 'bin/i18n/po.js', 'bin/i18n/locales.js' ] ) {
		fs.copyFileSync( path.join( ROOT, rel ), path.join( tmp, rel ) );
	}
	fs.writeFileSync( path.join( tmp, 'languages/de_DE.po' ), contextual( 'de_DE' ) );
	fs.writeFileSync( path.join( tmp, 'languages/fr_FR.po' ), contextual( 'fr_FR' ) );
	fs.writeFileSync( path.join( tmp, 'dist/languages/minn-admin-de_DE.zip' ), 'de-v1' );
	fs.writeFileSync( path.join( tmp, 'dist/languages/minn-admin-fr_FR.zip' ), 'fr-v1' );
	fs.writeFileSync( path.join( tmp, 'manifest.json' ), JSON.stringify( { version: '0.30.0' }, null, 4 ) );

	const run = ( tag ) => execFileSync( process.execPath, [ path.join( tmp, 'bin/release-manifest.js' ), tag ], {
		cwd: tmp,
		encoding: 'utf8',
	} );
	const firstOut = run( 'v0.30.0' );
	const first = JSON.parse( fs.readFileSync( path.join( tmp, 'manifest.json' ), 'utf8' ) );
	check( 'First release attaches both catalogs', /minn-admin-de_DE\.zip/.test( firstOut ) && /minn-admin-fr_FR\.zip/.test( firstOut ) );
	check( 'First release stamps two translation entries', first.translations.length === 2 );

	const oldDe = first.translations.find( ( p ) => p.language === 'de_DE' );
	const oldFr = first.translations.find( ( p ) => p.language === 'fr_FR' );
	first.version = '0.31.0';
	fs.writeFileSync( path.join( tmp, 'manifest.json' ), JSON.stringify( first, null, 4 ) );
	// Same German translations, deliberately reordered. French changes.
	fs.writeFileSync( path.join( tmp, 'languages/de_DE.po' ), contextual( 'de_DE', true ) );
	fs.writeFileSync( path.join( tmp, 'languages/fr_FR.po' ), contextual( 'fr_FR' ).replace( 'Ouvrir', 'Afficher' ) );
	fs.writeFileSync( path.join( tmp, 'dist/languages/minn-admin-de_DE.zip' ), 'de-rebuilt-different-bytes' );
	fs.writeFileSync( path.join( tmp, 'dist/languages/minn-admin-fr_FR.zip' ), 'fr-v2' );

	const secondOut = run( 'v0.31.0' );
	const second = JSON.parse( fs.readFileSync( path.join( tmp, 'manifest.json' ), 'utf8' ) );
	const de = second.translations.find( ( p ) => p.language === 'de_DE' );
	const fr = second.translations.find( ( p ) => p.language === 'fr_FR' );
	check( 'Reordered contextual entries keep the old German pack', de.version === '0.30.0' && de.package === oldDe.package && de.sha256 === oldDe.sha256 );
	check( 'A real French translation change moves its pack forward', fr.version === '0.31.0' && fr.package.includes( '/v0.31.0/' ) && fr.sha256 !== oldFr.sha256 );
	check( 'Attach list contains only the changed pack', /carried: de_DE/.test( secondOut ) && ! /ATTACH[\s\S]*minn-admin-de_DE\.zip/.test( secondOut ) && /ATTACH[\s\S]*minn-admin-fr_FR\.zip/.test( secondOut ) );

	fs.rmSync( path.join( tmp, 'dist/languages/minn-admin-fr_FR.zip' ) );
	let partialRefused = false;
	try {
		run( 'v0.31.0' );
	} catch ( e ) {
		partialRefused = /missing: minn-admin-fr_FR\.zip/.test( String( e.stderr || '' ) );
	}
	check( 'An incomplete pack build is refused', partialRefused );
} finally {
	fs.rmSync( tmp, { recursive: true, force: true } );
}

const failed = checks.filter( ( ok ) => ! ok ).length;
console.log( `\nrelease-manifest: ${ checks.length - failed }/${ checks.length } passed` );
process.exit( failed ? 1 : 0 );
