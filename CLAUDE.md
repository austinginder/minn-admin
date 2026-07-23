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
| `docs/` | Decisions live here. `editor-direction.md` (safety model — read before touching editor scope), `editor-roadmap.md` (where it's going), `block-inspector.md`, `for-plugin-authors.md`, `plugin-support.md` (coverage map), `adapter-coverage.md` (primitive matrix for adapter sweeps) |
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
- **Plumbing:** `Minn_Admin::js_translations()` reads standard JED files from
  `languages/` for `get_user_locale()` and ships the map in the boot payload as
  `B.i18n` (filter `minn_admin_js_translations`; the dev-fixtures option
  `minn_test_i18n` arms a German test catalog for `tests/i18n.test.js`).
- **Toolchain** (translation time only, never needed for development):
  `wp i18n make-pot . languages/minn-admin.pot --ignore-domain
  --exclude=tests,docs,.wp-playground,.github` regenerates the catalog (the stock
  extractor understands the JS helpers because they share core's names); translated
  `.po` files compile with `wp i18n make-mo` (PHP) and `wp i18n make-json` (JS JED
  files, into `languages/`).
- Plugin-supplied labels (surface descriptors, adapter data) are the PLUGIN's to
  translate; never wrap third-party data in Minn's catalog.
