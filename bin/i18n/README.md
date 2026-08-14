# Translation pipeline

Dev-time only. Nothing in `bin/` ships in the plugin zip.

Translation is driven from a Claude Code session, not an API key: the agent
running the session (and its subagents) does the translating through the
export/import round trip below. Every layer that protects quality is
mechanical and runs regardless of who translated.

## The layers, cheapest first

1. **WordPress core's own translations**, matched verbatim (`core-glossary.js`).
   Minn replaces wp-admin, so its vocabulary should match what the user
   already sees there: a German user expects "Beiträge", not a plausible
   synonym a model picked. Around 12% of the catalog resolves here at zero
   cost and zero risk, and it doubles as the glossary that keeps the rest
   consistent.
2. **Entries a human reviewed**, in `languages/reviewed/<locale>.po`. Plain
   `.po`, outside the generated catalog, wins over everything, survives any
   regeneration. A native speaker's correction has to be harder to destroy
   than the thing it corrects.
3. **Everything already in the catalog** is kept as a base layer by
   `import-batch.js`, so a top-up pass only asks for what is genuinely new.
4. **What remains** is exported as chunk files and translated in-session.

Then everything is validated, and anything that fails is **dropped** rather
than shipped. English is the source vocabulary, so a dropped entry falls
through to English: degraded, never broken.

## The session flow (top-up after new strings, or a brand-new locale)

```bash
cd bin/i18n

node export-batch.js de_DE --missing-only   # chunks of what is absent
# → /tmp/minn-i18n/de_DE.00.json …  (source + glossary + comments + plural forms)
```

The session (or a subagent per locale) reads each chunk and writes
`de_DE.00.done.json` beside it: the same shape with a `forms` array per entry
(`nplurals` entries for plural sources, one otherwise). Use the glossary and
`translators:` comments; placeholders must survive verbatim.

```bash
node import-batch.js de_DE                  # merge, validate, drop failures
node validate.js ../../languages/de_DE.po
node qa.js de_DE                            # coverage + the smell tests
node missing.js de_DE                       # 0 = release-ready
```

A NEW locale is the same flow with no existing catalog: add its row to
`locales.js` first (see below), then export (the chunks will be the whole
catalog minus glossary hits), translate, import.

`translate.js` still exists for full regenerations (`--regenerate`) and the
old API-key path, but it refuses to run against a finished catalog: it would
drop every generated entry it did not retranslate. The incremental path above
is the normal one.

## Releasing

`release.sh v<x.y.z>` translates nothing. It regenerates the `.pot`, reports
the cycle's translation debt, FAILS if any shipped catalog is missing entries
(that means strings landed after the last session pass — run the flow above),
then validates, compiles, builds packs, and stamps `manifest.json`.

The stamp is **per-language**: a catalog whose content did not change keeps
its previous version and package URL, so sites are only offered the languages
that really changed. The stamp output ends with the exact zips to attach to
the GitHub release.

Packs land in `dist/languages/` as `minn-admin-<locale>.zip`.

## Three things that will bite

**`wp i18n make-json` purges the `.po` it is given.** Run it before
`make-mo` and the `.mo` silently loses every string the app uses, so PHP
translates and the whole SPA stays English. `build-packs.sh` works on a copy,
passes `--no-purge`, and builds the `.mo` first.

**`wp i18n make-mo` needs a destination FILE, not a directory.** Given a
directory it warns "Is a directory" and produces nothing, leaving a pack with
only JSON in it. The script asserts both files exist before zipping.

**Placeholder parity is not a style rule.** PHP 8's `sprintf()` throws
`ArgumentCountError` when arguments run short, so a translation that drops a
`%s` is a fatal error in somebody else's admin, in a language the author
cannot read. `validate.js` checks the set and count per plural form and drops
anything that does not match.

## Adding a locale

Add a row to `locales.js` with the locale's real gettext `Plural-Forms`
header (take it from translate.wordpress.org, not from memory). The form
count drives the validator, the chunk format, and the runtime evaluator in
`app.js`, so a wrong rule produces a catalog that looks fine and prints the
wrong plural. `tests/plural-forms.test.js` pins each shipped locale's rule
against real values — add the new locale there in the same commit.
