/**
 * Bundled font coverage (GH #48). The admin ships its own fonts so it makes no
 * external requests, but only the latin subset was bundled: Polish, Czech,
 * Hungarian, Turkish and Vietnamese text fell back to a system font
 * mid-sentence, which is half the locales the plugin is translated into.
 * Latin-ext and vietnamese faces now carry those characters.
 *
 * Checked by measuring, not by trusting the stylesheet: a character the
 * bundled font cannot draw renders at a different width in the fallback face.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

// Characters that were missing, plus a control that was always present.
const SAMPLES = [
	[ 'Polish', 'ąćęłńśźż' ],
	[ 'Czech', 'čřžěů' ],
	[ 'Hungarian', 'őű' ],
	[ 'Turkish', 'ğş' ],
	[ 'Vietnamese', 'ăđặ' ],
];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'font-coverage' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-sidebar', { timeout: 20000 } );

	for ( const family of [ 'Hanken Grotesk', 'JetBrains Mono' ] ) {
		// document.fonts.check reports whether the family can render the text
		// with a face that is (or can be) loaded, per declared unicode-range.
		const res = await page.evaluate( async ( args ) => {
			const out = {};
			for ( const [ name, chars ] of args.samples ) {
				try { await document.fonts.load( `400 16px "${ args.family }"`, chars ); } catch ( e ) { /* keep going */ }
				out[ name ] = document.fonts.check( `400 16px "${ args.family }"`, chars );
			}
			return out;
		}, { family, samples: SAMPLES } );
		for ( const [ name ] of SAMPLES ) {
			t.check( `${ family }: ${ name } characters are covered`, res[ name ] === true, JSON.stringify( res ) );
		}
	}

	/* ===== Measured proof: an accented glyph must not fall back =====
	   Render the same word in the bundled family and in a deliberately absent
	   family (so the system fallback is what paints). Equal widths for the
	   accented word would mean the bundled family never supplied it. */
	const widths = await page.evaluate( async () => {
		await document.fonts.ready;
		const measure = ( text, family ) => {
			const el = document.createElement( 'span' );
			el.textContent = text;
			el.style.cssText = `position:fixed;left:-9999px;top:0;font:400 40px ${ family };white-space:pre;`;
			document.body.appendChild( el );
			const w = el.getBoundingClientRect().width;
			el.remove();
			return Math.round( w * 100 ) / 100;
		};
		return {
			asciiBundled: measure( 'zzzz', "'Hanken Grotesk', monospace" ),
			asciiFallback: measure( 'zzzz', 'monospace' ),
			polishBundled: measure( 'ąćęł', "'Hanken Grotesk', monospace" ),
			polishFallback: measure( 'ąćęł', 'monospace' ),
		};
	} );
	// The control proves the measurement works at all: plain ASCII already came
	// from the bundled face, so it must differ from the fallback.
	t.check( 'measurement is meaningful (ASCII differs from fallback)',
		widths.asciiBundled !== widths.asciiFallback, JSON.stringify( widths ) );
	t.check( 'Polish text is drawn by the bundled font, not the fallback',
		widths.polishBundled !== widths.polishFallback, JSON.stringify( widths ) );

	/* ===== The subset files are actually served ===== */
	const files = await page.evaluate( async ( base ) => {
		const names = [
			'hanken-grotesk.woff2', 'hanken-grotesk-latin-ext.woff2', 'hanken-grotesk-vietnamese.woff2',
			'jetbrains-mono.woff2', 'jetbrains-mono-latin-ext.woff2', 'jetbrains-mono-vietnamese.woff2',
		];
		const out = {};
		for ( const n of names ) {
			const r = await fetch( `${ base }/wp-content/plugins/minn-admin/assets/fonts/${ n }`, { credentials: 'same-origin' } );
			out[ n ] = r.status;
		}
		return out;
	}, BASE );
	t.check( 'every bundled font file is served', Object.values( files ).every( ( s ) => s === 200 ), JSON.stringify( files ) );

	/* ===== Still no external font requests ===== */
	const external = [];
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( /fonts\.(googleapis|gstatic)\.com/.test( u ) ) external.push( u );
	} );
	await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 1500 );
	t.check( 'no external font requests', external.length === 0, external.join( ', ' ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
