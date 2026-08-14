/**
 * Generates a locale's .po from languages/minn-admin.pot.
 *
 * Three passes, cheapest first:
 *
 *   1. WordPress core's OWN translations, matched verbatim. Minn replaces
 *      wp-admin, so its vocabulary should match what the user already sees
 *      there. No model is asked about a string core already answers.
 *   2. Entries a human already reviewed in the existing .po. Generated
 *      entries are replaceable; corrected ones are never overwritten.
 *   3. Everything left goes to Claude in batches, with core's matches
 *      supplied as a glossary so the two halves stay consistent.
 *
 * Then everything is validated (bin/i18n/validate.js) and anything that
 * fails is dropped, falling through to English rather than shipping broken.
 *
 * Usage:
 *   ANTHROPIC_API_KEY=... node translate.js de_DE [--limit N] [--dry]
 *   node translate.js de_DE --core-only     # no model calls, glossary pass only
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const { parsePo, writePo } = require( './po.js' );
const { loadCoreGlossary, buildNormIndex, lookup } = require( './core-glossary.js' );
const { byCode, nplurals } = require( './locales.js' );
const { validateCatalog } = require( './validate.js' );

const ROOT = path.resolve( __dirname, '../..' );
const POT = path.join( ROOT, 'languages/minn-admin.pot' );
const OUT_DIR = path.join( ROOT, 'languages' );

const MODEL = process.env.MINN_I18N_MODEL || 'claude-opus-5';
const BATCH = Number( process.env.MINN_I18N_BATCH || 40 );

/** Entries a human has signed off on carry this flag; never regenerate them. */
const REVIEWED = 'minn-reviewed';

const SYSTEM = ( loc, np ) => `You are translating the interface of Minn Admin, a WordPress admin replacement, from English into ${ loc.name } (${ loc.code }).

These strings are the entire interface a site owner reads: navigation, buttons, table headers, empty states, confirmations, error messages and help text.

RULES

1. Preserve every printf placeholder exactly: %s, %d and positional forms like %1$s. The set and the count must match the source. Reordering them for natural word order is correct and expected; dropping or adding one is a fatal error in the running site.
2. Never translate: placeholders, HTML tags and attributes, WordPress permalink tags (%year%, %postname%), file extensions, URLs, email addresses, keyboard shortcuts, or product and brand names (WordPress, WooCommerce, Gutenberg, Elementor, Minn Admin, Akismet, and any other plugin or company name).
3. Match the register and vocabulary of the official WordPress ${ loc.name } translation. The glossary below is drawn from WordPress core itself: reuse those exact terms so the interface reads as one product. If core says "Beiträge" for Posts, so do you.
4. Keep it short. These sit in a fixed-width sidebar and on buttons. Where a natural translation is much longer than the English, prefer the shorter accurate wording over the more literal one.
5. Preserve leading and trailing whitespace, capitalisation style, and terminal punctuation (including the ellipsis character …).
6. This locale has ${ np } plural form${ np === 1 ? '' : 's' } (${ loc.plural }). For an entry with a plural source, return exactly ${ np } form${ np === 1 ? '' : 's' } in the locale's own order.
${ loc.code === 'en_GB' ? '7. This is British English. Change ONLY spelling and vocabulary where British usage differs (colour, customise, organise, licence as a noun, catalogue). Leave everything else byte-identical to the source.' : '' }
${ loc.rtl ? '7. This is a right-to-left language. Do not add directional control characters; the interface handles direction itself.' : '' }

Return a translation for every entry you are given.`;

const SCHEMA = ( np ) => ( {
	type: 'object',
	properties: {
		translations: {
			type: 'array',
			items: {
				type: 'object',
				properties: {
					id: { type: 'integer', description: 'The entry id you were given.' },
					forms: {
						type: 'array',
						items: { type: 'string' },
						description: `Translated forms. One entry for a singular string; exactly ${ np } for a plural string, in the locale's own order.`,
					},
				},
				required: [ 'id', 'forms' ],
				additionalProperties: false,
			},
		},
	},
	required: [ 'translations' ],
	additionalProperties: false,
} );

function batchPrompt( batch, glossaryPairs ) {
	const lines = [];
	if ( glossaryPairs.length ) {
		lines.push( 'GLOSSARY (WordPress core\'s own translations for terms appearing in this batch; reuse verbatim):' );
		for ( const [ en, tr ] of glossaryPairs ) lines.push( `  ${ JSON.stringify( en ) } => ${ JSON.stringify( tr ) }` );
		lines.push( '' );
	}
	lines.push( 'ENTRIES:' );
	for ( const b of batch ) {
		lines.push( `- id: ${ b.id }` );
		lines.push( `  source: ${ JSON.stringify( b.entry.msgid ) }` );
		if ( b.entry.msgidPlural != null ) lines.push( `  plural_source: ${ JSON.stringify( b.entry.msgidPlural ) }` );
		for ( const c of b.entry.comments ) {
			if ( /^translators\s*:/i.test( c ) ) lines.push( `  note: ${ c.replace( /^translators\s*:\s*/i, '' ) }` );
		}
		if ( b.entry.refs.length ) lines.push( `  seen_in: ${ b.entry.refs.slice( 0, 2 ).join( ', ' ) }` );
	}
	return lines.join( '\n' );
}

async function main() {
	const code = process.argv[ 2 ];
	const loc = code && byCode( code );
	if ( ! loc ) {
		console.error( 'usage: node translate.js <locale> [--limit N] [--core-only] [--dry]' );
		process.exit( 2 );
	}
	const np = nplurals( loc );
	const coreOnly = process.argv.includes( '--core-only' );
	const dry = process.argv.includes( '--dry' );
	const limFlag = process.argv.indexOf( '--limit' );
	const limit = limFlag > 0 ? Number( process.argv[ limFlag + 1 ] ) : Infinity;

	const pot = parsePo( fs.readFileSync( POT, 'utf8' ) );
	const outPath = path.join( OUT_DIR, `${ loc.code }.po` );

	// This tool REGENERATES: it carries forward only reviewed entries and
	// glossary hits, and everything else is retranslated (or, with
	// --core-only, left EMPTY). Running it against a finished catalog would
	// blank most of it. The incremental path is export-batch.js
	// --missing-only + import-batch.js, which keeps what exists. Refuse
	// unless the caller explicitly asks for a regeneration.
	if ( fs.existsSync( outPath ) && ! process.argv.includes( '--regenerate' ) ) {
		const have = parsePo( fs.readFileSync( outPath, 'utf8' ) ).entries
			.filter( ( e ) => e.msgid && e.msgstr.some( Boolean ) && ! e.flags.includes( REVIEWED ) ).length;
		if ( have > 0 ) {
			console.error( `${ loc.code }.po already holds ${ have } generated translations, and this tool` );
			console.error( 'would drop every one it does not retranslate. For a top-up use' );
			console.error( 'export-batch.js --missing-only + import-batch.js; pass --regenerate' );
			console.error( 'to rebuild the catalog from scratch anyway.' );
			process.exit( 1 );
		}
	}

	// Pass 2 source: what a human already reviewed.
	const prior = new Map();
	if ( fs.existsSync( outPath ) ) {
		for ( const e of parsePo( fs.readFileSync( outPath, 'utf8' ) ).entries ) {
			prior.set( e.msgid, e );
		}
	}

	// Pass 1: WordPress core.
	const glossary = loadCoreGlossary( loc.code );
	const normIdx = buildNormIndex( glossary );

	const out = [];
	const todo = [];
	let fromCore = 0, fromReviewed = 0;

	for ( const e of pot.entries ) {
		const entry = {
			msgid: e.msgid, msgidPlural: e.msgidPlural, msgctxt: e.msgctxt,
			comments: e.comments, refs: e.refs, flags: [], msgstr: [],
		};
		const was = prior.get( e.msgid );
		if ( was && was.flags.includes( REVIEWED ) && was.msgstr.some( Boolean ) ) {
			entry.msgstr = was.msgstr;
			entry.flags = [ REVIEWED ];
			out.push( entry ); fromReviewed++; continue;
		}
		if ( e.msgidPlural == null ) {
			const hit = lookup( glossary, normIdx, e.msgid );
			if ( hit && hit.forms[ 0 ] ) {
				entry.msgstr = [ hit.forms[ 0 ] ];
				out.push( entry ); fromCore++; continue;
			}
		}
		todo.push( { id: out.length + todo.length, entry } );
		out.push( entry );
	}

	console.log( `${ loc.code }: ${ pot.entries.length } source entries` );
	console.log( `  from a human review : ${ fromReviewed }` );
	console.log( `  from WordPress core : ${ fromCore }` );
	console.log( `  needing translation : ${ todo.length }` );

	const work = todo.slice( 0, limit === Infinity ? todo.length : limit );

	if ( ! coreOnly && work.length ) {
		if ( ! process.env.ANTHROPIC_API_KEY ) {
			console.error( '\nANTHROPIC_API_KEY is not set. Run with --core-only for the glossary pass alone,' );
			console.error( 'or export a key to translate the remaining entries.' );
			process.exit( 3 );
		}
		const Anthropic = require( '@anthropic-ai/sdk' );
		const client = new Anthropic();

		for ( let i = 0; i < work.length; i += BATCH ) {
			const batch = work.slice( i, i + BATCH );
			// Glossary terms relevant to this batch keep the prompt small.
			const pairs = [];
			for ( const b of batch ) {
				for ( const word of new Set( b.entry.msgid.split( /[^A-Za-z]+/ ).filter( ( w ) => w.length > 2 ) ) ) {
					const hit = glossary.get( word ) || glossary.get( word[ 0 ].toUpperCase() + word.slice( 1 ) );
					if ( hit && hit[ 0 ] && pairs.length < 60 ) pairs.push( [ word, hit[ 0 ] ] );
				}
			}
			process.stdout.write( `  batch ${ Math.floor( i / BATCH ) + 1 }/${ Math.ceil( work.length / BATCH ) } (${ batch.length } strings)… ` );
			if ( dry ) { console.log( 'dry run' ); continue; }

			const stream = client.messages.stream( {
				model: MODEL,
				max_tokens: 32000,
				thinking: { type: 'adaptive' },
				// The system prompt and glossary are stable across batches for
				// a locale, so cache them rather than re-billing every call.
				system: [ { type: 'text', text: SYSTEM( loc, np ), cache_control: { type: 'ephemeral' } } ],
				output_config: { format: { type: 'json_schema', schema: SCHEMA( np ) } },
				messages: [ { role: 'user', content: batchPrompt( batch, [ ...new Map( pairs ) ] ) } ],
			} );
			const msg = await stream.finalMessage();
			if ( msg.stop_reason === 'refusal' ) { console.log( 'REFUSED, skipping batch' ); continue; }
			const text = msg.content.find( ( b ) => b.type === 'text' );
			if ( ! text ) { console.log( 'no text block, skipping' ); continue; }
			let parsed;
			try { parsed = JSON.parse( text.text ); } catch ( e ) { console.log( 'unparseable JSON, skipping' ); continue; }
			const byId = new Map( batch.map( ( b ) => [ b.id, b.entry ] ) );
			let n = 0;
			for ( const t of parsed.translations || [] ) {
				const entry = byId.get( t.id );
				if ( ! entry || ! Array.isArray( t.forms ) ) continue;
				entry.msgstr = t.forms;
				n++;
			}
			console.log( `${ n } translated` );
		}
	}

	// Validate and drop anything unsafe.
	const { kept, dropped } = validateCatalog( out.filter( ( e ) => e.msgstr.some( Boolean ) ), np );
	console.log( `\nvalidated: ${ kept.length } kept, ${ dropped.length } dropped` );
	for ( const d of dropped.slice( 0, 10 ) ) {
		console.log( `  DROP ${ JSON.stringify( d.entry.msgid.slice( 0, 46 ) ) }: ${ d.reason }` );
	}

	const stamp = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + '+0000';
	const header = [
		`Project-Id-Version: Minn Admin`,
		`Report-Msgid-Bugs-To: https://github.com/austinginder/minn-admin/issues`,
		`PO-Revision-Date: ${ stamp }`,
		`Last-Translator: Minn Admin translation pipeline`,
		`Language-Team: ${ loc.name }`,
		`MIME-Version: 1.0`,
		`Content-Type: text/plain; charset=UTF-8`,
		`Content-Transfer-Encoding: 8bit`,
		`Language: ${ loc.code }`,
		`Plural-Forms: ${ loc.plural }`,
		`X-Generator: minn-admin/bin/i18n`,
	].join( '\n' );

	if ( dry ) { console.log( '(dry run, nothing written)' ); return; }
	fs.writeFileSync( outPath, writePo( header, kept ) );
	console.log( `wrote ${ path.relative( ROOT, outPath ) } (${ kept.length } entries, ${ ( kept.length / pot.entries.length * 100 ).toFixed( 1 ) }% coverage)` );
}

main().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
