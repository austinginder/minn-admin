# Internationalization roadmap

How Minn Admin becomes usable in a language other than English, and how it stays
that way without turning every release into twenty translation chores.

The convention for writing translatable strings already lives in `CLAUDE.md`
("Internationalization"). This file is the plan for the other half: coverage,
catalogs, right to left layout, delivery, and upkeep.

## Status

Phases 0 through 5 are built and verified in development. What remains is
generation coverage, which needs an API key. See "What is left" at the end.

| | Before | Now |
|---|---|---|
| `.pot` entries | 341, stamped 0.25.0 | **2,712**, current, 0 extractor warnings |
| Plural entries | 0 usable | 20, with a real Plural-Forms evaluator |
| Translator notes | 0 | 84 |
| Unwrapped attribute literals in `app.js` | 265 | **0** |
| Unwrapped visible text nodes in `app.js` | ~600 | **0** |
| Untranslated display strings in PHP | ~1,200 | **0** |
| Logical CSS properties | 0 | 183 declarations, plus 24 offsets |
| Locales with a catalog | 0 | 13 |
| Decay guards | none | 4 static checks + 3 suites |

### Measured before the sweep, for the record

341 `.pot` entries stamped `0.25.0`; 235 PHP `__()` calls across 97 files;
420 `__()` in `app.js` against 265 unwrapped attribute literals; zero logical
CSS properties against roughly 272 physical direction declarations.

The reason the sweep came first: hand a translator the old `.pot` and the
result is an admin that reverts to English the moment anyone does real work,
because Cancel, Save changes, Status and most placeholders were not in the
catalog at all. **A half translated interface reads worse than an English
one**, which is why coverage was a hard prerequisite for shipping any locale,
and why the generated catalogs are still not wired into the manifest.

Three small code gaps blocked the delivery model, all now closed:

1. `js_translations()` globbed only `MINN_ADMIN_DIR . 'languages/'`. Language
   packs install to `WP_LANG_DIR . '/plugins/'`, so PHP would have translated
   while the whole app stayed English.
2. `Minn_Admin_Updater::update()` filled only `$transient->response`. WordPress
   reads translation updates from `$transient->translations`.
3. `includes/template.php` set `lang` on `<html>` and never `dir`.

## The delivery mechanism: WordPress language packs

This is the native update system, and it is worth being precise about why it is
the right target rather than bundling `.mo` files in the plugin zip.

WordPress reads pending translation updates from the same transient the updater
already filters:

```php
$transient->translations[] = array(
    'type'       => 'plugin',
    'slug'       => 'minn-admin',
    'language'   => 'de_DE',
    'version'    => '0.30.0',
    'updated'    => '2026-08-20 12:00:00',
    'package'    => 'https://.../minn-admin-de_DE.zip',
    'autoupdate' => true,
);
```

Four properties fall out of that for free, verified against WP 7.x core:

- **They install themselves after a plugin update.** `admin-filters.php` hooks
  `Language_Pack_Upgrader::async_upgrade()` to `upgrader_process_complete` at
  priority 20. Update Minn, and its language packs update immediately after with
  no code from us.
- **They appear in Dashboard, Updates** under Translations, and in the automatic
  update paths, because core owns the whole flow.
- **They land in `wp-content/languages/plugins/`, which core checks first.**
  `WP_Textdomain_Registry::get_paths_for_domain()` orders `WP_LANG_DIR/plugins`
  ahead of the plugin's own directory. A pack wins over anything bundled, and a
  bundled fallback still works when no pack is installed.
- **They survive a plugin reinstall,** because they do not live inside the
  plugin directory.

Bundling catalogs in the zip gives up all four and grows the download for every
user in every locale. Language packs are strictly better here.

### What the packs contain

One zip per locale, files at the zip root, extracted directly into
`wp-content/languages/plugins/`:

```
minn-admin-de_DE.mo
minn-admin-de_DE-<md5 of assets/js/app.js>.json
```

The `.po` stays in git as the source and is not shipped. Nothing else goes in.

### Security: hash the packs like the plugin

The updater verifies its own download against a manifest `sha256` and refuses
to install when the manifest publishes no hash. That posture extends to
translation packages rather than lapsing for them.

- The manifest publishes a per pack `sha256` alongside `package`.
- `hash_for_package()` looks a hash up BY PACKAGE URL, three-state on purpose:
  a hash to verify against, `''` when the manifest claims the package but
  publishes no hash (a refusal, not a pass), and `null` when the URL is not
  ours at all, so every other plugin's downloads pass through untouched.
- A pack that would be refused is never OFFERED either.
- `is_our_package_url()` reads an allowlist of repository paths rather than one
  hardcoded prefix, keeping the anchored (never substring) match. Moving packs
  to their own repository later is one more entry plus a manifest URL.

A `.mo` is data rather than executable code, so the blast radius is smaller than
a plugin zip. It is still a file that decides what text the admin renders, and
the plugin's own comments argue that opt out integrity is the thing to avoid.

## Which locales

**Decided: thirteen in wave 1**, covering roughly 41.6% of all WordPress
installs. Twenty by the end of wave 2.

Live figures from `https://api.wordpress.org/stats/locale/1.0/`, read
2026-08-13. Percentages are share of all WordPress installs.

**Wave 1, the top ten non English locales.** Together these cover 36.9% of all
WordPress installs.

| # | Locale | Language | Share |
|---|---|---|---|
| 1 | `ja` | Japanese | 5.96% |
| 2 | `es_ES` | Spanish (Spain) | 5.78% |
| 3 | `de_DE` | German | 5.65% |
| 4 | `fr_FR` | French | 4.58% |
| 5 | `pt_BR` | Portuguese (Brazil) | 3.89% |
| 6 | `it_IT` | Italian | 3.17% |
| 7 | `nl_NL` | Dutch | 2.41% |
| 8 | `ru_RU` | Russian | 2.11% |
| 9 | `pl_PL` | Polish | 2.10% |
| 10 | `tr_TR` | Turkish | 1.30% |

**Three additions that earn wave 1 on their own merits:**

- **`fa_IR` Persian, 1.00%.** Larger than Czech, Swedish or Chinese, and it
  arrives with a contributor who has already built and tested an implementation
  (issue #19). Shipping Persian in wave 1 turns an offer of help into a locale
  maintainer, which is the answer to the upkeep problem the main roadmap worried
  about.
- **`ar` Arabic, 0.45%.** Costs almost nothing once right to left works, and it
  is the second RTL locale that proves the foundation is not Persian specific.
- **`en_GB` English (UK), 3.24%.** The cheapest item on this entire page. It is
  spelling normalization, not translation: colour, customise, organise, licence
  as a noun. It would rank sixth on the list above, and the same catalog serves
  `en_AU`, `en_CA`, `en_NZ` and `en_ZA`, which together add another 1.46%.

That makes **thirteen locales in wave 1**, covering roughly 41.6% of installs
before counting the English variants.

**Wave 2, to twenty.** Add after wave 1 has survived one release cycle: `vi`
(1.16%), `id_ID` (1.02%), `cs_CZ` (0.60%), `sv_SE` (0.59%), `zh_CN` (0.57%),
`pt_PT` (0.56%), `hu_HU` (0.54%), `es_MX` (0.49%), `da_DK` (0.47%), `he_IL`
(0.41%). That is another 6.4%, and `he_IL` is the third RTL locale.

Two cheap derivations worth taking whenever their parent ships: `de_DE_formal`
(0.23%) is a Sie form pass over `de_DE`, and `pt_PT` and `es_MX` are regional
passes over `pt_BR` and `es_ES` rather than fresh translations.

## Generating the catalogs

Minn is distributed from GitHub, so there is no translate.wordpress.org and no
volunteer community attached. Generation is what makes twenty locales tractable
for one maintainer. The quality of a generated catalog depends almost entirely
on how much is decided before a model is asked anything.

### Step 1: take WordPress core's own translations first

This is the single largest quality lever and it costs nothing.

Minn is a replacement for wp-admin, so its vocabulary should match what the user
already sees in wp-admin. A German user should read Beiträge, not a plausible
synonym. Core publishes a translated catalog per locale at
`https://downloads.wordpress.org/translation/core/<version>/<locale>.zip`, which
is a few thousand human translated, community reviewed admin strings.

So before any model is involved: match each Minn `msgid` against core's catalog
and **use core's translation verbatim on an exact match**. Posts, Pages, Media,
Settings, Save changes, Cancel, Trash, Published, Draft, Add New, Search and most
of the rest of the shared admin vocabulary resolve here at zero cost and zero
risk. What remains is the genuinely Minn specific text, and it goes to the model
with core's matches supplied as a glossary so the two halves stay consistent.

### Step 2: translate the remainder

A script (`bin/translate.php`, or node, either is fine) that per locale:

1. Reads `languages/minn-admin.pot`.
2. Reads the existing `languages/<locale>.po` and **keeps every entry already
   marked as human reviewed.** Generated entries are replaceable; corrected ones
   are not. A flag comment on the entry is enough to mark the difference.
3. Sends only untranslated entries to Claude, in batches, with:
   - the core glossary from step 1,
   - the `/* translators: */` comments and the `#:` source references, which are
     the only context that distinguishes a noun from a verb ("Update" the button
     from "Update" the noun),
   - the locale's own `Plural-Forms` header, so `_n()` entries get the right
     number of forms. This ranges from one form (Japanese, Vietnamese, Chinese,
     Indonesian) to six (Arabic), and getting it wrong produces a broken catalog
     rather than a bad translation.
4. Writes the `.po`, then compiles.

The constraints given to the model are the part worth writing down carefully:

- **Preserve every placeholder exactly.** `%s`, `%d` and `%1$s` must appear in
  the translation with the same set and the same count. Reordering is expected
  and correct; dropping one is not.
- **Never translate placeholders, HTML, or brand names.** WooCommerce, Gutenberg,
  Minn Admin, WordPress and every plugin name stay as they are.
- **Keep UI strings short.** These sit in a fixed width sidebar and on buttons.
  A German string at twice the English length breaks the layout, so prefer the
  shorter accurate option over the more literal one.
- **Match the register of the locale's WordPress core translation,** formal or
  informal, rather than picking one per string.

### Step 3: validate before anything is written

Generated output without a validation gate is not safe to ship. Three checks,
all cheap, all mandatory:

- **Placeholder parity.** The set and count of `%s` / `%d` / `%n$s` in `msgstr`
  must equal `msgid`. This is not a style rule. PHP 8 `sprintf()` throws
  `ArgumentCountError` when arguments run short, so a dropped placeholder in a
  PHP string is a fatal error in someone else's admin.
- **`msgfmt -c`** for catalog syntax and plural form arity.
- **No empty or fuzzy `msgstr` reaching a pack.** Untranslated is fine, because
  the entry falls through to English by design. Half translated and marked
  complete is not.

Anything that fails validation drops out of the pack rather than blocking the
release. English is the source vocabulary and a missing entry is a graceful
degradation, which is exactly what makes generation safe here.

### Step 4: compile, in this order

```bash
# .mo FIRST, from the complete .po
wp i18n make-mo languages/ languages/

# THEN the JED files, with --no-purge
wp i18n make-json languages/ languages/ --no-purge
```

`make-json` purges the JS entries from the `.po` by default. Run it before
`make-mo` and the `.mo` silently loses every string the app uses. Order and
`--no-purge` are both load bearing.

## Right to left

Issue #19 is a well researched proposal with a tested `0.27.0` implementation
behind it, and its analysis matches what is in the code. Everything below is
worth accepting.

RTL is **independent of the catalogs** and should land first. It is valuable on
its own, it does not conflict with the string sweep, and it is what makes
Persian, Arabic and Hebrew possible later.

Scope:

- `dir` on `<html>` from `is_rtl()`, alongside the `lang` that is already there.
- Logical properties across `app.css`: `margin-inline-start`, `padding-inline-end`,
  `inset-inline-start`, `border-inline-end`, `text-align: start`. Roughly 272
  physical declarations, currently zero logical ones.
- **Preserve physical positioning where mirroring would be wrong.** Crop
  geometry, chart coordinates, image editor math, and previous/next controls
  that are physical rather than sequential. This is the part a blind find and
  replace gets wrong, and it is called out correctly in the issue.
- LTR isolation for technical values, which is the detail that separates a real
  RTL interface from a mirrored one:

  ```css
  .mono, code, pre,
  input[type="url"], input[type="email"] {
      direction: ltr;
      unicode-bidi: isolate;
  }
  ```

  URLs, slugs, version numbers, file paths and email addresses render as garbage
  in an RTL run without this.
- Direction aware popover and dropdown positioning, clamped to the viewport.
  `positionBlockPop` and `openMinnMenu` are the shared entry points, so this is
  a small number of places rather than a sweep.

Two notes for the contributor: the work was built against `0.27.0` and `app.css`
has moved since, so the layout commits need a rebase onto current `main`. And
the RTL commits should land before the string sweep completes, because they
touch different files and blocking one on the other helps nobody.

## Release automation

There is no CI in this repo today, which is a deliberate simplicity. The ask is
that translations update on every release, and that is one workflow, triggered
on a release tag:

1. Regenerate `languages/minn-admin.pot`.
2. Diff against the previous `.pot`. New and changed `msgid`s are the only work.
3. Run the generator for the delta only, across every supported locale. Existing
   human corrections are untouched, so a release costs a few dozen strings
   rather than six hundred.
4. Validate. Compile `.mo` and `.json`.
5. Build one zip per locale, compute each `sha256`.
6. Attach the packs to the release and update `manifest.json`'s `translations`
   array.

The `.pot` diff step is what keeps this cheap. It is also the honest answer to
the upkeep objection in the main roadmap: with generation, the standing
obligation is on one pipeline rather than on twenty relationships, and a locale
is never broken by a release, only briefly less complete.

Two supporting pieces:

- **A coverage report per locale,** regenerated at release and published. It
  makes staleness visible instead of silent, and it tells a native speaker where
  help is worth giving.
- **A "Help translate" link** from Minn's own Settings to the repo. Generated
  first, human corrected over time, is the only model that scales without
  translate.wordpress.org. Every correction that arrives is permanent, because
  the generator never overwrites a reviewed entry.

## Guarding the sweep

Sweeping six hundred literals is worth doing once. Doing it twice because the
sweep decayed over three releases is not, and this repo already has 264 test
files to hang a guard on.

Add a static suite that fails when `app.js` gains an unwrapped `placeholder=`,
`title=` or `aria-label=` literal, or a bare text node in a render function.
Add a lint that fails when a string containing `%s` or `%d` has no
`/* translators: */` comment on the line above. Both are grep shaped and both
run in a second.

Without this, the coverage numbers at the top of this file regenerate themselves
by v0.32.0.

## Where the files live

**Decided: the same repo, `austinginder/minn-admin`.** Sources live in
`languages/*.po` next to the code they translate, and packs are attached as
assets to the release tag they were generated from.

```
austinginder/minn-admin
├── languages/
│   ├── minn-admin.pot
│   ├── de_DE.po                      (source, in git)
│   └── ja.po
└── releases/v0.30.0/
    ├── minn-admin.zip                 + sha256
    ├── minn-admin-de_DE.zip           + sha256
    └── minn-admin-ja.zip              + sha256
```

One manifest, one release flow, one hash discipline. Catalogs are derived
artifacts of a specific version, so coupling them to the release tag is correct
rather than merely convenient.

The alternative, a separate `MinnAdmin/translations` repo, buys one real thing:
a bad translation could be fixed without cutting a plugin release. It costs a
second manifest, a second release flow, and a widened package host check. That
trade is worth revisiting once the org is real and once a translation has
actually needed an out of band fix.

**Write the move as a one line change now, so it stays cheap.** Two things make
that true, and both should land in phase 3:

- `is_our_package_url()` becomes an **allowlist of (host, path prefix) pairs**
  rather than one hardcoded prefix, keeping the anchored match. Moving later
  means adding a pair.
- `manifest.translations[]` entries carry their own `package` and `sha256`, so
  they can point at a different host without touching the plugin's own
  `download_url` path.

## Phasing

**Phase 0, sweep and foundation. Done.** `.pot` regenerated (341 to 2,712
entries, zero extractor warnings). Every attribute literal, text node and PHP
display string wrapped. Four decay guards added. `js_translations()` reads the
language-pack directory.

**Phase 1, right to left. Done.** `dir` from `is_rtl()`, logical properties,
LTR isolation for technical values, direction-aware positioning at nine JS
sites. Physical positioning preserved where mirroring would be wrong.
`tests/rtl.test.js` drives a real fa_IR session.

**Phase 2, the pipeline. Done.** `bin/i18n/`: core-glossary matcher, generator,
validator, packer. Proven end to end on `de_DE`, whose built pack translates
both the PHP side and the SPA when installed the way WordPress installs one.

**Phase 3, delivery. Done.** `translations` in the update transient, per pack
`sha256`, the package-URL check rewritten as an allowlist. Core's own
`wp_get_translation_updates()` returns the packs and the Updates screen reads
"Translation Updates".

**Phase 4, wave 1. Foundation done, coverage pending.** Thirteen catalogs
exist, validate and compile, at 12% coverage from WordPress core alone. The
model pass needs an API key. Persian ships with its contributor named as
maintainer.

**Phase 5, wave 2.** To twenty, after wave 1 has been through a release cycle.

## What was found along the way

Four defects the work surfaced that were not on the plan, each of which would
have shipped a translated build that looked fine and behaved wrong:

**Cards stopped being doors.** The overview stats and the System health checks
decided where a card navigated by comparing its DISPLAY LABEL against an
English string. That works exactly until a locale ships: a translated
"Backups" matches nothing, so every actionable card silently loses its
destination. Both now carry a stable key and route on that. The System code
already had a comment admitting the label matching would break under
translation.

**`_n()` plurals were wrong in every locale but English.** The app's `_n()`
hardcoded `n != 1`. Ten of the thirteen wave-1 locales need something else:
Japanese has one form, Russian and Polish three, Arabic six. The catalog
already carried each locale's rule on JED's reserved entry, which the loader
skipped. It is now read, shipped in the boot payload, and evaluated by a
parser rather than `eval`, because a translation pack is a downloaded file.

**Language packs would have translated PHP and left the app English.**
`js_translations()` read only the plugin's own `languages/` directory. Packs
install to `WP_LANG_DIR/plugins/`, which core checks first.

**A user's language choice only half applied.** Core consults the user's
locale only when `is_admin()`, and Minn renders at `/minn-admin/` on the front
end. Picking German in Your profile gave a German app with English PHP, and
`is_rtl()` never became true for that user, so no amount of choosing Persian
produced a right-to-left layout.

## What is left

**Generation coverage.** Each locale currently carries the WordPress-core pass
only: 327 entries, 12% coverage, every one a community-reviewed core
translation. The remaining ~2,385 strings per locale need
`ANTHROPIC_API_KEY=… node bin/i18n/translate.js <locale>`, which is a single
command per locale and the reason the pipeline exists.

Until then the catalogs are deliberately **not** wired into `manifest.json`.
Shipping them now would be worse than shipping nothing: a half-translated
admin reads worse than an English one, which is the whole reason the sweep
came before the locales.

**A first human review per locale.** Generated entries are replaceable;
marking one `minn-reviewed` in the `.po` makes it permanent. That is the path
from generated to maintained, and it is what a native speaker's pull request
should touch.

**Wave 2** takes the list to twenty and waits until wave 1 has been through a
release cycle.
