/**
 * A minimal zip writer, so a suite can build a real plugin or theme package on
 * disk and hand it to the installer the way a person hands it a download.
 *
 * Entries are STORED (method 0), never deflated: WordPress unzips through
 * ZipArchive or PclZip and both read stored entries, while writing a correct
 * deflate stream here would buy nothing but a smaller file. Everything a test
 * package holds is a few hundred bytes of PHP.
 *
 * No dependency and no build step, matching the rest of tests/.
 */
const fs = require( 'fs' );
const path = require( 'path' );

// CRC-32 (IEEE), table built once. The zip central directory stores it per
// entry and PclZip verifies it, so an approximation is not an option.
const CRC_TABLE = ( () => {
	const table = new Int32Array( 256 );
	for ( let i = 0; i < 256; i++ ) {
		let c = i;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		table[ i ] = c;
	}
	return table;
} )();

function crc32( buf ) {
	let c = -1;
	for ( let i = 0; i < buf.length; i++ ) {
		c = CRC_TABLE[ ( c ^ buf[ i ] ) & 0xff ] ^ ( c >>> 8 );
	}
	return ( c ^ -1 ) >>> 0;
}

// A fixed DOS timestamp (2026-01-01 00:00) keeps two builds of the same files
// byte-identical, which is one less thing to explain when a test flakes.
const DOS_TIME = 0;
const DOS_DATE = ( ( 2026 - 1980 ) << 9 ) | ( 1 << 5 ) | 1;

/**
 * Write a zip holding the given entries.
 *
 * @param {string} zipPath Destination file.
 * @param {Object} files   Map of path inside the zip => file contents (string).
 * @return {string} zipPath, for chaining.
 */
function writeZip( zipPath, files ) {
	const locals = [];
	const central = [];
	let offset = 0;

	for ( const name of Object.keys( files ) ) {
		const nameBuf = Buffer.from( name, 'utf8' );
		const data = Buffer.from( files[ name ], 'utf8' );
		const crc = crc32( data );

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 ); // version needed
		local.writeUInt16LE( 0, 6 ); // flags
		local.writeUInt16LE( 0, 8 ); // method: stored
		local.writeUInt16LE( DOS_TIME, 10 );
		local.writeUInt16LE( DOS_DATE, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( data.length, 18 );
		local.writeUInt32LE( data.length, 22 );
		local.writeUInt16LE( nameBuf.length, 26 );
		local.writeUInt16LE( 0, 28 ); // extra length
		locals.push( local, nameBuf, data );

		const entry = Buffer.alloc( 46 );
		entry.writeUInt32LE( 0x02014b50, 0 );
		entry.writeUInt16LE( 20, 4 ); // version made by
		entry.writeUInt16LE( 20, 6 ); // version needed
		entry.writeUInt16LE( 0, 8 );
		entry.writeUInt16LE( 0, 10 );
		entry.writeUInt16LE( DOS_TIME, 12 );
		entry.writeUInt16LE( DOS_DATE, 14 );
		entry.writeUInt32LE( crc, 16 );
		entry.writeUInt32LE( data.length, 20 );
		entry.writeUInt32LE( data.length, 24 );
		entry.writeUInt16LE( nameBuf.length, 28 );
		entry.writeUInt16LE( 0, 30 ); // extra
		entry.writeUInt16LE( 0, 32 ); // comment
		entry.writeUInt16LE( 0, 34 ); // disk number
		entry.writeUInt16LE( 0, 36 ); // internal attrs
		entry.writeUInt32LE( 0, 38 ); // external attrs
		entry.writeUInt32LE( offset, 42 );
		central.push( entry, nameBuf );

		offset += local.length + nameBuf.length + data.length;
	}

	const body = Buffer.concat( locals );
	const dir = Buffer.concat( central );
	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( 0x06054b50, 0 );
	end.writeUInt16LE( 0, 4 );
	end.writeUInt16LE( 0, 6 );
	end.writeUInt16LE( Object.keys( files ).length, 8 );
	end.writeUInt16LE( Object.keys( files ).length, 10 );
	end.writeUInt32LE( dir.length, 12 );
	end.writeUInt32LE( body.length, 16 );
	end.writeUInt16LE( 0, 20 );

	fs.mkdirSync( path.dirname( zipPath ), { recursive: true } );
	fs.writeFileSync( zipPath, Buffer.concat( [ body, dir, end ] ) );
	return zipPath;
}

/**
 * Build a one-file plugin package at the given version.
 *
 * The zip carries a single top-level directory, which is what
 * Plugin_Upgrader::check_package() insists on, and a header block complete
 * enough for get_plugin_data() to read a name and a version out of it.
 *
 * @param {string} zipPath Destination file.
 * @param {Object} opts    { slug, name, version }.
 * @return {string} zipPath.
 */
function writePluginZip( zipPath, { slug, name, version } ) {
	const php = `<?php
/**
 * Plugin Name: ${ name }
 * Description: Throwaway package built by Minn Admin's test suite.
 * Version: ${ version }
 * Author: Minn Admin tests
 */

// Deliberately inert: the suite installs and replaces this, never runs it for
// behaviour of its own.
`;
	return writeZip( zipPath, { [ `${ slug }/${ slug }.php` ]: php } );
}

/**
 * Build a bare theme package at the given version.
 *
 * Theme_Upgrader::check_package() wants a style.css carrying a Theme Name,
 * and an index.php for anything that is not a child theme. Both are here and
 * nothing else is.
 *
 * @param {string} zipPath Destination file.
 * @param {Object} opts    { slug, name, version }.
 * @return {string} zipPath.
 */
function writeThemeZip( zipPath, { slug, name, version } ) {
	const css = `/*
Theme Name: ${ name }
Description: Throwaway package built by Minn Admin's test suite.
Version: ${ version }
Author: Minn Admin tests
*/
`;
	return writeZip( zipPath, {
		[ `${ slug }/style.css` ]: css,
		[ `${ slug }/index.php` ]: "<?php\n// Inert: the suite installs and replaces this, never renders it.\n",
	} );
}

module.exports = { writeZip, writePluginZip, writeThemeZip, crc32 };
