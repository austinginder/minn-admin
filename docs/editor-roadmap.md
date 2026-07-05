# Editor roadmap — the long-term plan

**Thesis: the editor is the selling feature.** Minn Admin started as "a calmer admin," but
the editor is where the product wins or loses. The rest of the admin is supporting cast —
excellent supporting cast, but nobody switches admins for a nicer plugins screen. People
switch tools over *where they write*.

## Why this is realistic

- **The demand is proven and unserved.** Classic Editor holds five-million-plus active
  installs years after Gutenberg shipped — a standing vote for "not the block editor,"
  currently served by frozen technology. Minn is the modern answer: markdown-fluent,
  instant, calm.
- **The lock-in fear is solved, and that's the moat.** Every previous "write in peace"
  editor for WordPress died on one of two fears: *my content is trapped in your format*
  or *your editor will destroy my layouts*. Minn stores native Gutenberg markup (open any
  post in the block editor, any time, forever) and block islands preserve complex layouts
  byte-identical. No competitor has this story; Gutenberg itself can't have the "calm"
  half of it.
- **The writing use case is 90% of edits.** Paragraphs, headings, lists, quotes, code,
  images, the occasional table and embed. Minn's editor already does all of it with
  markdown conventions, undo that works, and previews that wear the site's real styles.

## Positioning

**"The writing editor for WordPress."** Not "an editor with fewer features than
Gutenberg" — a different tool for a different job. Gutenberg is the layout tool; Minn is
where the writing happens; islands and the one-click handoff are the seam between them.
Saying "that's Gutenberg's job" is the strategy, not a limitation to apologize for.

The bar for "it works": **every anchor.host post gets written and edited in Minn without
opening the block editor once.** Dogfooding is the roadmap's referee.

## What was learned building v0.5.x (the paper-cut ledger)

Editors are judged on a thousand small behaviors, and contenteditable fights back on each
one. The ledger so far, all fixed and regression-tested: Chrome rebalances whitespace
destructively at inline boundaries; `insertHTML` rewrites `<code>` into styled spans;
lists nest inside their source paragraph; whole-block deletion merges neighbors into
leftover husks; a non-editable island dies to a single adjacent Backspace; selection dies
crossing into any modal; `execCommand('strikeThrough')` emits obsolete tags; alignment
via `justify*` writes styles the serializer strips. **Every future feature budgets for
this class of fight** — the browser-level Playwright verification loop is not optional
overhead, it's how an editor earns trust.

## Horizon 1 — trust (0.6.x): nothing surprising, ever

The theme: a writer who moves in must never lose work or hit a behavior that makes them
distrust the surface.

- **Paste cleanup.** Word / Google Docs / arbitrary HTML paste → clean safe-subset markup.
  ✅ *Shipped 2026-07-05* — `sanitizePastedHtml()` + caret-context insertion (see the
  "Paste cleanup" section in app.js and `tests/paste.test.js`). Word mso-lists rebuild as
  real nested lists; Docs style-spans map to strong/em/s/code; js: hrefs, handlers and
  vendor styling never pass; single ⌘Z reverts a whole paste. Clipboard *images* (files,
  not URLs) remain with the "inline media flow" item below.
- **Undo completeness.** Island operations (insert, remove, table ops) sit outside the
  browser undo stack today. Decide: a small custom undo journal for island/array state
  interleaved with native undo, or document the boundary honestly. Investigate before 1.0.
- **Conflict safety.** Post locking / "someone else is editing" (core's heartbeat locks),
  and a localStorage safety net for drafts so a crashed browser loses nothing even before
  the first autosave.
- **Input long tail.** IME/composition input audit (CJK), mobile Safari pass,
  accessibility pass (keyboard access to chips/popovers/islands, ARIA on the toolbar).
- **Inline media flow.** Paste/drag an image from the clipboard straight to the media
  library at the caret; inline figcaption editing.

## Horizon 2 — delight (0.7–0.9): things Gutenberg will never feel like

The theme: features that only make sense in a *writing* tool, where the calm surface is
the point.

- **Outline panel.** Headings as a clickable table of contents in the sidebar; doubles as
  structure feedback while drafting.
- **Focus mode.** Fade everything but the current paragraph; typewriter scroll. The
  feature that markets itself in a screenshot.
- **Revision diffs.** The History card opens a side-by-side diff instead of a raw
  restore — writers think in "what changed," not in revision IDs.
- **Internal link picker.** ⌘K link flow searches your own posts first — linking to your
  own writing should be faster than pasting a URL.
- **Find & replace** within the post, markdown-aware.
- **Writing stats that matter.** Session word counts, per-post goals; the pill grows up.
- **Slash-command extension point.** `minn_admin_editor_commands` filter so plugins add
  their own slash items the way they already add blocks, panels and surfaces.

## Horizon 3 — the editor as platform (1.0+)

- **Presence** (who else has this post open), building on core's locks rather than
  inventing collaboration infrastructure.
- **Offline-tolerant drafting** if the localStorage net from Horizon 1 proves itself.
- **The marketing turn.** minnadmin.com leads with the editor: the hero is the writing
  surface, the admin is "and it comes with a better admin around it." readme.txt and
  screenshots follow.

## What we will never build (unchanged, load-bearing)

Columns, groups, patterns, cover blocks, page building, FSE, block parity of any kind.
Islands make the cost of *not* supporting a block small — it displays, survives, and can
be configured through the inspector. If a post is mostly layout, Gutenberg is the right
tool and the handoff is one click. This list is what keeps the editor good.

## Engineering posture

- **No build step stays.** One file, sections clearly banded. If the editor doubles the
  file, it doubles the file — greppability beats architecture astronautics at this scale.
- **The test suites become repo citizens.** The Playwright suites that verified v0.5.x
  (markdown rules, autosave semantics, island guards, table ops, embed pipeline) should
  live in `tests/` and run before releases, not be rebuilt in scratchpads.
- **Safety model is frozen.** `editorModeFor` → classic/blocks/locked, byte-identity
  islands, attribute allowlists that must be DOM-reproducible. New capabilities extend
  the allowlists one proven attribute at a time (see editor-direction.md); they never
  loosen the model.
