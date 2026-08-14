# Working on Minn Admin

Minn Admin is a standalone WordPress admin SPA served at `/minn-admin/`. One vanilla-JS
file, one stylesheet, PHP that reads top to bottom. **No build step — that's a core
architectural bet, not an omission.** Read `docs/goals.md` before proposing structure.

## The development loop

1. Edit files directly (`assets/js/app.js`, `assets/css/app.css`, `includes/*.php`).
   Assets are cache-busted by `?ver=MINN_ADMIN_VERSION`, so hard-refresh while iterating.
2. Cheap validation on every change:
   ```bash
   node --check assets/js/app.js && php -l includes/*.php minn-admin.php
   ```
3. **Browser-verify before calling anything done.** Syntax checks prove nothing about an
   editor; this codebase fights contenteditable, and contenteditable fights back. Drive a
   real Chrome via the harness in `tests/` (see `tests/README.md`) — real keystrokes,
   real clicks, zero-console-errors as a standing gate, and check what actually got
   *saved*, not just what the DOM shows.
4. A bug fix in the editor ships with a test. Reproduce first (a failing script), fix,
   rerun to green, keep the suite in `tests/`.

## Map

| Where | What |
|---|---|
| `assets/js/app.js` | The entire SPA. Section banners (`/* ===== … ===== */`) are the navigation — grep them. |
| `includes/class-minn-admin.php` | Routing, auth gate, boot payload (`window.MINN`), oEmbed shims |
| `includes/class-minn-admin-rest.php` | `minn-admin/v1` endpoints (overview, render-blocks, editor-styles, …) |
| `includes/adapters/` | Bundled third-party integrations — each guards on its plugin; `acf.php` is the panel reference, `seo.php` the REST-field reference |
| `docs/` | Decisions live here. `editor-direction.md` (safety model — read before touching editor scope), `editor-roadmap.md` (where it's going), `wp-cli-roadmap.md` (future command plan), `block-inspector.md`, `for-plugin-authors.md`, `plugin-support.md` (coverage map), `adapter-coverage.md` (primitive matrix for adapter sweeps) |
| `tests/` | Self-contained Playwright suites + `helpers.js` harness |

## Editor invariants (violations are data loss)

- **The safety model is frozen.** `editorModeFor()` → classic / blocks / locked. Islands
  (`contenteditable=false`) pass through byte-identical from `ed.islands[]`; locked posts
  never send `content`. Grow `SIMPLE_BLOCKS` / `EDITABLE_ATTRS` one proven attribute at a
  time — every allowed attribute must be reproducible from the live DOM at serialize time.
- **Never trust contenteditable defaults.** The recurring traps, all documented at their
  fix sites in `app.js`: Chrome rebalances boundary whitespace destructively; `insertHTML`
  rewrites `<code>` into styled spans; new lists nest inside the source paragraph
  (`liftNestedLists`); whole-block deletion merges neighbors into husks (delete *contents*
  instead); an adjacent Backspace atomically deletes a non-editable island
  (`bindIslandGuards`); modal clicks destroy the selection (capture the Range first);
  `insertHTML` merges a payload's first/last blocks into the blocks around the caret and
  SHREDS non-paragraphs doing it (`pasteBlocksInsert` brackets payloads with marker
  paragraphs); after any execCommand, out-of-stack TEXT mutations corrupt undo — Chrome
  replays recorded offsets against the live DOM (node *removals* are safe; fix text at
  serialize time, see `cleanLeadingNbsp`).
- **Nothing decorative reaches the database.** Syntax-highlight spans, hover inline
  styles, `data-minn-attrs` markers, nbsp litter, `<strike>`, empty figure husks — all
  scrubbed in `serializeToBlocks()` / `classicHtml()`. New editor chrome must live on
  `document.body` (chips, popovers) or be scrubbed at serialize; prove it by saving and
  inspecting `post_content`.
- **Lists over REST:** never request rendered content in list views (`_fields`
  allowlists); no `_fields` on `wp/v2/types`. Capability checks are server-side.

## Conventions

- Commits: Emoji-Log — `📦 NEW:` `👌 IMPROVE:` `🐛 FIX:` `📖 DOC:` `🚀 RELEASE:`,
  imperative and present tense.
- Prose style: no em dashes inside sentences in user-facing text (readmes, docs,
  changelog entries, UI strings). Rewrite with a period, colon, semicolon or
  parentheses. The one sanctioned use is the list-item label separator
  (`**Feature** — description`).
- Version lives in three places at release time: `minn-admin.php` (×2) and
  `manifest.json` (version + download_url). Don't touch them mid-cycle. There is
  deliberately no readme.txt: GitHub is the distribution channel, so readme.md and
  minnadmin.com are the listing surfaces (a wp.org readme gets recreated from them
  if a directory listing ever happens).
- A release ends at the published GitHub release + verified manifest. **Never update a
  live site as part of releasing** — the owner updates through Minn's own Extensions UI,
  which doubles as the release-candidate test of the self-updater.
- Match the file's comment voice: comments state constraints the code can't show —
  especially the hard-won browser facts. Delete nothing labeled "hard-won" without
  re-proving it in a browser.

## Internationalization

English is the source vocabulary; a missing catalog or entry falls through to the
literal, so the app runs with zero tooling. The convention:

- **Every NEW user-facing string is wrapped.** PHP: core `__()`/`_n()` with the
  `minn-admin` domain. JS (app.js): the module's own `__()`, `_n()` and `sprintf()`
  helpers (top of the file). Existing literals convert opportunistically, view by
  view — do not block a feature on a sweep.
- **Interpolation goes through `sprintf`** (`%s`, `%d`, positional `%1$s`), never
  string concatenation or bare template literals inside a translatable string
  (translations reorder words). Counts use `_n( single, plural, n )`. Strings with
  placeholders get a `/* translators: … */` comment on the line above the call.
- Translated values placed in HTML attributes go through `esc()` like any other
  dynamic value.
- **Plumbing:** `Minn_Admin::js_translations()` reads standard JED files for
  `get_user_locale()` and ships the map in the boot payload as `B.i18n` (filter
  `minn_admin_js_translations`; the dev-fixtures option `minn_test_i18n` arms a
  German test catalog for `tests/i18n.test.js`). It reads TWO directories in
  core's own precedence order: the plugin's bundled `languages/` first, then
  `WP_LANG_DIR/plugins/` where installed language packs land, so a pack wins.
  `js_plural_forms()` ships the locale's Plural-Forms rule alongside it, and
  the app parses and evaluates that rule rather than assuming `n != 1` (which
  is wrong for ten of the thirteen shipped locales). The rule is parsed, never
  `eval`'d: a catalog is a downloaded file.
- **Locale on Minn's route:** `Minn_Admin::route_locale()` filters
  `determine_locale` so `/minn-admin/` and the `minn-admin/v1` namespace serve
  the USER's language. Core only does this for `is_admin()`, and Minn is not
  wp-admin, so without it the app translated while every PHP string stayed in
  the site language and `is_rtl()` never became true for the user.
- **Toolchain** (translation time only, never needed for development):
  `bin/i18n/` owns the whole pipeline and `bin/i18n/README.md` documents it.
  Translation itself is a Claude Code session's job, no API key involved:
  `export-batch.js <locale> --missing-only` writes chunk files, the session
  (or a subagent per locale) fills `<locale>.NN.done.json`, and
  `import-batch.js` merges with validation, keeping reviewed and existing
  entries. `translate.js` refuses to touch a finished catalog without
  `--regenerate` — it would blank every generated entry it did not redo.
  `bin/i18n/release.sh v<x.y.z>` is the release step and translates nothing:
  regenerate the `.pot`, report the cycle's debt, FAIL if a shipped catalog
  is missing entries (`missing.js` is the gate), validate, build one pack per
  locale, stamp `manifest.json` PER LANGUAGE — a catalog whose content hash
  is unchanged keeps its old version and package URL, so sites are offered
  only the languages that really changed, and the stamp output ends with the
  exact zips to attach to the GitHub release. Two traps the pack build
  exists to avoid, both of which fail silently: `wp i18n make-json` PURGES
  the `.po` it is given, so running it before `make-mo` produces a `.mo`
  missing every app.js string; and `wp i18n make-mo` needs a destination
  FILE, not a directory.
- **RTL:** the shell sets `dir` from `is_rtl()` and `app.css` uses logical
  properties. Deliberately physical and not to be "fixed": crop-handle
  coordinates, centering, symmetric stretches, previous/next. Anything
  positioning a floating element in PIXELS must consult `isRtl()` — CSS logical
  properties cannot reach a value computed in JS; use the shared `menuLeftAt()`
  and `panelLeftFor()` helpers.
- **Changing language does NOT reload.** `switchLanguage()` (app.js) fetches
  `minn-admin/v1/boot-locale` — the locale slice of the boot payload, built by
  `Minn_Admin::locale_payload()` — and repaints. Three things go stale on a
  language change and only the first is obvious: the JED catalog, text the
  SERVER already translated before it reached the boot payload (role names,
  surface labels, post formats — none of it re-translates on the client), and
  writing direction, which the shell only sets on the initial render. It is
  self-guarding: when the new locale matches `B.locale` it does nothing, so a
  site-language save by a user with a personal override is not a pointless
  repaint. The catalog is REPLACED IN PLACE (`applyCatalog`) because `__()`
  closes over it — rebinding would leave every existing call site on the old
  language — and `pluralRule` is rebuilt from a factory because a locale's
  form COUNT changes with it.
- **Numbers and dates format by the USER's locale, not the browser's.** Every
  `toLocale*` call passes `uiLocale()` (app.js), which reads `B.locale` live so
  a language switch applies on the next render. It appends `-u-nu-latn` to pin
  Latin digits: Arabic and Persian default to Arabic-Indic numerals, while
  PHP's `number_format_i18n` — rendering the server half of the same screens —
  emits Latin, and one digit system per screen matters more than either choice.
- **Language packs ship four files**, matching translate.wordpress.org:
  `.mo`, `.l10n.php` (WP 6.5+ fast path), `.json` (JED) and **`.po`**. The `.po`
  is not optional: `wp_get_installed_translations()` reads pack metadata from it
  and skips any `.mo` with no `.po` beside it, so a `.mo`-only pack reports as
  NOT INSTALLED and gets re-offered on every update check forever.
- **Translation updates are offered BY VERSION.** `build-packs.sh` stamps
  `Project-Id-Version: Minn Admin <version>` into the packed copy (never into
  git — that would rewrite every catalog each release), and the updater parses
  it and uses `version_compare`. Not `PO-Revision-Date`: the pipeline restamps
  that on every run, so a no-op regeneration would re-offer every pack. A
  translation-only fix is therefore a patch release.
- **`bin/build-zip.sh` builds the release zip**, and owns the exclusion list so
  it stops drifting in a runbook. `languages/*.po`/`*.pot` and `bin/` are
  excluded — translation SOURCE and toolchain, never read at runtime. `docs/`
  ships (the REST layer serves `docs/user-guide.md`).
- **Guards:** `tests/i18n-static.test.js` (no browser, ~1s) fails on a new
  unwrapped literal, a `%s` string with no translators comment, anything routing
  off a translated label, and an `_n()` plural wrapped in `__()`.
  `tests/plural-forms.test.js` pins every shipped locale's rule;
  `tests/rtl.test.js` drives a real fa_IR session.
- Plugin-supplied labels (surface descriptors, adapter data) are the PLUGIN's to
  translate; never wrap third-party data in Minn's catalog.
