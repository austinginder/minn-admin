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

The bar for "it works": **every post on a real production site gets written and
edited in Minn without opening the block editor once.** Dogfooding is the
roadmap's referee.

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

Two chrome-positioning corollaries joined the ledger 2026-07-11: anything
that must visually track scrolling content lives INSIDE the scroller at
content coordinates (fixed-position chrome chased per scroll frame lags the
compositor and elastic overscroll reports nothing until it settles — the
block chips wiggled; a ResizeObserver re-anchors on real reflow), and a
panel that HAS escaped to fixed positioning closes on ancestor scroll like
a native select rather than chasing (the combobox). Same day: images gained
link + lightbox through the popover (the link is the saved DOM itself —
core sources href from figure > a — and lightbox rides the attrs
passthrough), and focus/outline modes wear a topbar exit chip.

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
  browser undo stack today.
  🔍 *Investigated 2026-07-05* (empirical, `scratchpad/undo-probe*.js`). The map:
  - **execCommand ops are fully tracked** — typing, markdown, toolbar formatting, rich
    paste (a whole paste reverts in one ⌘Z), image insert/remove, link create/edit.
  - **Direct-DOM ops are outside the stack** — island insert (embed/gallery/slash custom),
    island remove (backspace guard / inspector), table row/col/header ops, inspector
    attribute regen. For all of these ⌘Z is a **safe no-op**, never a corruption.
  - **The headline: no sequence corrupts.** The dangerous case — native undo of typing
    restoring a stale full-DOM snapshot that wipes or duplicates an untracked island —
    does **not** happen. Blink's undo is transaction-based, not snapshot-based: it replays
    the specific ranges its execCommands recorded and leaves direct-DOM nodes alone. Even
    the worst adversarial case (type into a table cell, delete that row out from under the
    pending transaction, then ⌘Z) is a clean no-op with zero console errors.
  - **The one genuine gap: structural DELETION is unrecoverable.** Remove an embed island
    or a table row and ⌘Z can't bring it back (only autosave / revisions / the crash net
    can, coarsely). Everything else that's a no-op is merely *incomplete*, not lossy.

  **Decision: do NOT build the interleaved custom undo journal.** Because there's no
  corruption, this is a completeness/polish gap, not a data-integrity one — and a journal
  that correctly interleaves with Blink's native stack means intercepting ⌘Z globally and
  essentially reimplementing the whole undo stack (capturing typing too, to keep ordering
  right). That's a large, fragile core rewrite for a polish feature — against the "no build
  step / greppable / islands keep the editor good" ethos. Instead:
  1. **Toast-Undo for island deletions** (embed/gallery/custom block remove): a Gmail-style
     "Deleted — Undo" toast (~6s) that re-inserts the removed node + its islands[] entry.
     ✅ *Shipped 2026-07-05* — `toastAction()` + `removeIslandWithUndo()`; suite
     `tests/undo-toast.test.js`.
  2. **Table ops on the real undo stack** (2026-07-09): row/col add/delete, header toggle,
     and table delete mutate a detached clone then swap via `commandOnBlock` /
     `applyTableMutation`. Figures use `selectNodeContents` + insertHTML of the inner
     markup (Blink nests a full-figure `selectNode` insertHTML); bare tables use
     `selectNode`. Table-delete is contents-delete on the figure (⌘Z restores). Same
     idea as image remove. Destructive ops toast a "⌘Z restores it" hint. Suite:
     `tests/undo-toast.test.js` + `tests/table-menu.test.js`.
  3. **Document the boundary:** ⌘Z covers writing + table structure + image remove;
     island deletions offer the Undo toast. Remaining direct-DOM ops (inspector attr
     regen, island insert) are still safe no-ops under ⌘Z.
- **Conflict safety.** Post locking / "someone else is editing" (core's heartbeat locks),
  and a localStorage safety net for drafts so a crashed browser loses nothing even before
  the first autosave.
  ✅ *Shipped 2026-07-05* — locking rides core's own `_edit_lock`
  (`minn-admin/v1/posts/{id}/lock`; Minn, classic and Gutenberg all honor each other;
  takeover dialog, 30s refresh doubling as takeover detection, read-only + banner when
  taken, release on leave incl. `sendBeacon` from pagehide). The crash net snapshots
  every edit to localStorage within ~1.2s, offers recovery on the next open (including
  never-saved new posts) and clears itself on successful saves. Suites: `tests/lock.test.js`,
  `tests/localnet.test.js`. This also delivers Horizon 3's presence groundwork.
- **Input long tail.** IME/composition input audit (CJK), mobile Safari pass,
  accessibility pass (keyboard access to chips/popovers/islands, ARIA on the toolbar).
- **Inline media flow.** Paste/drag an image from the clipboard straight to the media
  library at the caret; inline figcaption editing.
  ✅ *Shipped 2026-07-05* — screenshot ⌘V and dropped image files upload to the library
  with an instant blob preview and an undo-safe swap to the real attachment (id + class,
  a true Gutenberg image block); serializers skip in-flight uploads so an autosave can
  never store a blob URL. Every editable image carries a typable caption ("Write a
  caption…" placeholder; empty ones never serialize; Enter/Backspace edge guards).
  Suite: `tests/media-flow.test.js`. Fixing the suite also flushed out and fixed a
  pre-existing race: a late editor re-render could revert unsaved DOM edits before a
  save (renderEditor now adopts the live DOM when dirty).

## Horizon 2 — delight (0.7–0.9): things Gutenberg will never feel like

The theme: features that only make sense in a *writing* tool, where the calm surface is
the point. Most of this horizon shipped across v0.8.0–v0.10.0; remaining items are
stats growth and a slash extension point.

- **Outline panel.** ✅ *Shipped 2026-07-05 (v0.8.0)* — heading ToC as the last sidebar
  card, sticky; rebuilds on the stats cadence; click ping lives inside the scroller at
  content coordinates. Outline *mode* (⌘⇧O) collapses nav + every sidebar card except the
  outline. Suite: `tests/outline.test.js`.
- **Focus mode.** ✅ *Shipped 2026-07-05 / zen 2026-07-06* — caret-block dim bands,
  typewriter scroll (capped instant steps; smooth scroll is canceled by caret-reveal),
  zen collapses nav + editor sidebar. ⌘⇧D palette command (not a toolbar icon). Suite:
  `tests/focus.test.js`.
- **Revision diffs.** ✅ *Shipped 2026-07-05 (v0.8.0)* — History card opens a side-by-side
  word diff vs the current serializer output (unsaved edits count); LCS with sameRatio
  gate so unrelated del/add paragraphs stay separate. Suite: `tests/revision-diff.test.js`.
- **Internal link picker.** ✅ *Shipped 2026-07-05 (v0.8.0)* — link popover URL field
  searches `wp/v2/search` for non-URL text; pick applies immediately. Suite:
  `tests/link-picker.test.js`.
- **Find & replace** within the post, markdown-aware.
  ✅ *Shipped 2026-07-10* — ⌘⇧F (rebound from ⌘F so the browser keeps plain find; also a
  ⌘K command). Matching runs over the text writers see: text nodes concatenated per block, so a
  match crosses inline marks (a split `<strong>`) but never a block boundary, and islands /
  `contenteditable=false` subtrees are excluded entirely. Highlights are overlay rects inside
  the scroller at content coordinates (nothing touches the typing surface); replaces select
  the match Range and run `execCommand insertText`, so every replacement is a native undo
  entry and Replace-all applies last-to-first to keep earlier ranges valid. Locked mode falls
  through to browser find. Suite: `tests/find-replace.test.js`.
- **Writing stats that matter.** Session word counts, per-post goals; the pill (word count
  + reading time) is the seed. Still open.
- **Slash-command extension point.** `minn_admin_editor_commands` filter so plugins add
  their own slash items the way they already add blocks, panels and surfaces. Still open
  (auto-insert blocks, design libraries and patterns already cover the plugin-content
  half without a new filter).

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
