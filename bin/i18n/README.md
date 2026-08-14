# Translation pipeline

Dev-time only. Nothing in `bin/` ships in the plugin zip.

## The passes, cheapest first

1. **WordPress core's own translations**, matched verbatim (`core-glossary.js`).
   Minn replaces wp-admin, so its vocabulary should match what the user
   already sees there: a German user expects "Beiträge", not a plausible
   synonym a model picked. Around 12% of the catalog resolves here at zero
   cost and zero risk, and it doubles as the glossary that keeps the rest
   consistent.
2. **Entries a human already reviewed**, carried forward untouched. Generated
   entries are replaceable; corrected ones are never overwritten. Mark one by
   adding the `minn-reviewed` flag to it in the `.po`:

   ```
   #, minn-reviewed
   msgid "Save changes"
   msgstr "Änderungen speichern"
   ```

3. **Everything left** goes to Claude in batches, with the glossary, the
   `translators:` comments and the source references attached.

Then everything is validated, and anything that fails is **dropped** rather
than shipped. English is the source vocabulary, so a dropped entry falls
through to English: degraded, never broken.

## Running it

```bash
cd bin/i18n && npm install          # once, for the model pass

node locales.js 1                   # what wave 1 is
node core-glossary.js de_DE         # how much core covers, before spending anything

node translate.js de_DE --core-only # glossary pass only, no API key needed
ANTHROPIC_API_KEY=… node translate.js de_DE
node validate.js ../../languages/de_DE.po

./build-packs.sh de_DE              # or no args for every .po
```

Packs land in `dist/languages/` as `minn-admin-<locale>.zip`, ready to attach
to a GitHub release. `../release-manifest.js` stamps them into `manifest.json`
with their sha256 so the updater can verify them.

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
header. The form count drives the validator, the prompt, and the runtime
evaluator in `app.js`, so a wrong rule produces a catalog that looks fine and
prints the wrong plural. `tests/plural-forms.test.js` pins the wave-1 rules
against real values.
