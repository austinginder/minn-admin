/**
 * Build the static demo bundle into the marketing theme.
 *
 * Writes <theme>/demo/: app.js, app.css and fonts as committed at a git ref
 * (HEAD unless --ref names a release tag, so work in progress in the tree
 * never reaches the site), the replay script, the recording (data.js +
 * uploads/) and catalog.json with `demo: true` on every plugin whose surface
 * the recording holds. The theme reads only that folder, so the site always
 * shows the app build and the plugin list that were bundled together.
 *
 * Usage (from the plugin root):
 *   node bin/demo/build.js <recording-dir> <theme-dir> [--ref=v0.45.0]
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..', '..' );
const [ REC, THEME ] = process.argv.slice( 2 ).filter( ( a ) => ! a.startsWith( '--' ) );
const REF = ( process.argv.find( ( a ) => a.startsWith( '--ref=' ) ) || '--ref=HEAD' ).slice( 6 );
const atRef = ( rel ) => execFileSync( 'git', [ '-C', ROOT, 'show', REF + ':' + rel ], { maxBuffer: 64 * 1024 * 1024 } );
if ( ! REC || ! THEME ) {
	console.error( 'Usage: node bin/demo/build.js <recording-dir> <theme-dir> [--ref=<tag>]' );
	process.exit( 2 );
}
if ( ! fs.existsSync( path.join( THEME, 'style.css' ) ) ) {
	console.error( THEME + ' is not a theme folder (no style.css).' );
	process.exit( 2 );
}

const read = ( f ) => JSON.parse( fs.readFileSync( f, 'utf8' ) );
const meta = read( path.join( REC, 'meta.json' ) );
const boot = read( path.join( REC, 'boot.json' ) );
const responses = read( path.join( REC, 'responses.json' ) );
const catalog = read( path.join( __dirname, 'catalog.json' ) );

// The recording must come from the app build being bundled: a response shape
// from another version can break a view the bundled app.js draws.
const version = ( atRef( 'minn-admin.php' ).toString( 'utf8' ).match( /^\s*\*\s*Version:\s*(\S+)/m ) || [] )[ 1 ];
if ( version !== meta.version ) {
	console.error( `The recording is from v${ meta.version } but ${ REF } is v${ version }. Record again.` );
	process.exit( 1 );
}

const OUT = path.join( THEME, 'demo' );
fs.rmSync( OUT, { recursive: true, force: true } );
for ( const dir of [ 'app/js', 'app/css', 'app/fonts' ] ) fs.mkdirSync( path.join( OUT, dir ), { recursive: true } );

fs.writeFileSync( path.join( OUT, 'app/js/app.js' ), atRef( 'assets/js/app.js' ) );
fs.writeFileSync( path.join( OUT, 'app/css/app.css' ), atRef( 'assets/css/app.css' ) );
const fonts = execFileSync( 'git', [ '-C', ROOT, 'ls-tree', '--name-only', REF, 'assets/fonts/' ], { encoding: 'utf8' } ).split( '\n' ).filter( ( f ) => f.endsWith( '.woff2' ) );
for ( const f of fonts ) fs.writeFileSync( path.join( OUT, 'app/fonts', path.basename( f ) ), atRef( f ) );
fs.copyFileSync( path.join( __dirname, 'replay.js' ), path.join( OUT, 'replay.js' ) );
if ( fs.existsSync( path.join( REC, 'uploads' ) ) ) {
	fs.cpSync( path.join( REC, 'uploads' ), path.join( OUT, 'uploads' ), { recursive: true } );
}

// The recording is scrubbed JSON, but it lands inside a <script> element:
// escape anything that could end the element or open a comment.
const data = JSON.stringify( { boot, responses } ).replace( /</g, '\\u003c' ).replace( /\u2028/g, '\\u2028' ).replace( /\u2029/g, '\\u2029' );
fs.writeFileSync( path.join( OUT, 'data.js' ), 'window.MINN_DEMO = ' + data + ';\n' );

const recorded = new Set( meta.surfaces );
let linked = 0;
for ( const c of catalog.categories ) {
	for ( const p of c.plugins ) {
		if ( p.surface && recorded.has( p.surface ) ) {
			p.demo = true;
			linked++;
		}
	}
}
catalog.version = meta.version;
catalog.recordedAt = meta.recordedAt;
catalog.routes = meta.routes;
fs.writeFileSync( path.join( OUT, 'catalog.json' ), JSON.stringify( catalog, null, '\t' ) + '\n' );

const size = ( f ) => ( fs.statSync( path.join( OUT, f ) ).size / 1024 ).toFixed( 0 ) + ' KB';
console.log( `v${ version } demo (app from ${ REF }) → ${ OUT }` );
console.log( `  app.js ${ size( 'app/js/app.js' ) }, data.js ${ size( 'data.js' ) }` );
console.log( `  ${ linked } catalog plugins link a demo; ${ meta.skipped.length } catalog surfaces were not recorded` );
