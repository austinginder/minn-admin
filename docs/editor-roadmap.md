# Editor roadmap — the long-term plan

*Statuses last verified against the code 2026-08-09 (v0.26.0 cycle, unreleased). The
measurable goals this plan serves live in [roadmap.md](roadmap.md); this document holds
the editor thesis, the shipped ledger, and the open frontier.*

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
- **The writing use case is 90% of edits — and since the nesting cycle, the layout-shaped
  rest opens for writing too.** Paragraphs, headings, lists, quotes, code, images, tables,
  embeds, and now the text inside groups, columns, covers and media-text.

## Positioning

**"The writing editor for WordPress."** Not "an editor with fewer features than
Gutenberg" — a different tool for a different job. Gutenberg is the layout tool; Minn is
where the writing happens; islands and the one-click handoff are the seam between them.
Saying "that's Gutenberg's job" is the strategy, not a limitation to apologize for.

The bar for "it works": **every post on a real production site gets written and
edited in Minn without opening the block editor once.** Dogfooding is the
roadmap's referee — formalized as Goal 1 in [roadmap.md](roadmap.md).

## The paper-cut ledger (engineering knowledge, keep)

Editors are judged on a thousand small behaviors, and contenteditable fights back on each
one. The ledger so far, all fixed and regression-tested: Chrome rebalances whitespace
destructively at inline boundaries; `insertHTML` rewrites `<code>` into styled spans;
lists nest inside their source paragraph; whole-block deletion merges neighbors into
leftover husks; a non-editable island dies to a single adjacent Backspace; selection dies
crossing into any modal; `execCommand('strikeThrough')` emits obsolete tags; alignment
via `justify*` writes styles the serializer strips. **Every future feature budgets for
this class of fight** — the browser-level Playwright verification loop is not optional
overhead, it's how an editor earns trust.

Two chrome-positioning corollaries (2026-07-11): anything that must visually track
scrolling content lives INSIDE the scroller at content coordinates (fixed-position
chrome chased per scroll frame lags the compositor; a ResizeObserver re-anchors on real
reflow), and a panel that HAS escaped to fixed positioning closes on ancestor scroll
like a native select rather than chasing (the combobox).

## Shipped ledger (Horizons 1 + 2, closed)

Horizon 1 (trust) and Horizon 2 (delight) are complete. The deep notes live in the
suites, in `editor-direction.md`, and in the per-feature app.js sections; this ledger
is the index. Format: feature — landed — suite.

**Horizon 1 — trust: nothing surprising, ever**

- Paste cleanup (Word/Docs/web → safe subset; island copy round-trip v0.23.0) —
  2026-07-05 — `paste`, `island-copy`
- Undo completeness (probed: no sequence corrupts; toast-undo for island deletion;
  table ops on the real stack; the interleaved custom journal deliberately NOT built) —
  2026-07-05/09 — `undo-toast`, `table-menu`
- Conflict safety (core `_edit_lock` interop, takeover flow, localStorage crash net) —
  2026-07-05 — `lock`, `localnet`
- IME/composition guards — 2026-07-12 — `ime`
- Mobile Safari pass (keyboard inset, hit targets) — 2026-07-12 — `mobile-editor`
- Accessibility first cut (toolbar/dialog/listbox semantics; not a WCAG audit) —
  2026-07-12 — `editor-a11y`
- Inline media flow (paste/drop images → library at caret; inline captions) —
  2026-07-05 — `media-flow`

**Horizon 2 — delight: things Gutenberg will never feel like**

- Outline panel + outline mode (⌘⇧O) — 2026-07-05 — `outline`
- Focus mode + zen (⌘⇧D) — 2026-07-05/06 — `focus`
- Revision diffs (side-by-side vs live serializer output) — 2026-07-05 — `revision-diff`
- Internal link picker — 2026-07-05 — `link-picker`
- Find & replace (⌘⇧F; overlay rects; native-undo replaces) — 2026-07-10 — `find-replace`
- Writing stats (session delta, word goal) — 2026-07-12 — `writing-stats`
- Slash-command extension point + plugin content (auto-insert blocks, design
  libraries, patterns incl. the user's own, ⌘/ Browse all) — v0.9.0–v0.23.0 —
  `editor-commands`, `patterns`, `user-patterns`, `block-picker`

**Nested content (GH #4) — complete 2026-08-09.** The full arc — attribute carry,
in-place island text, container slots, nested islands and containers at any depth,
cover/media-text, the full insert surface inside slots, raw-markup paste conversion,
duplicate + move, comment tolerance, and the deepest-wins chrome pass — is documented
as the plan of record in [editor-direction.md](editor-direction.md), each slice with
its probes and suites (`attr-carry` 20, `island-runs` 21, `container-slots` 44,
`nested-islands` 34). Every core layout container is a writing surface; byte-identity
for untouched content remains the non-negotiable invariant.

## Horizon 3 — the editor as platform (the open frontier)

- **Presence** (who else has this post open), building on core's locks rather than
  inventing collaboration infrastructure. Shipped so far: the lock story's blind
  windows are closed (v0.19.0 `X-Minn-Expect-Lock` save verification; "Load theirs"
  on regain), the content list shows a "{name} is editing" chip (v0.20.0), and public
  REST reads don't leak the holder's name (v0.21.0). Live cursors / multi-presence
  beyond the lock model remain the open 1.0+ question; nothing beyond the lock model
  exists in code.
- **Offline-tolerant drafting** if the localStorage net keeps proving itself. Still
  open: app.js carries no connectivity awareness. The net is suite-covered
  (`localnet`), and expired-nonce recovery (v0.21.0) means an overnight tab saves
  without a reload.
- **The marketing turn.** minnadmin.com leads with the editor: the hero is the writing
  surface, the admin is "and it comes with a better admin around it." Landed:
  minnadmin.com is the plugin's listed website with shareable docs pages; readme.txt is
  gone. Remaining: the editor-led hero itself; readme.md still opens on "a reimagined
  WordPress admin experience" with an Overview screenshot, not the writing surface.

## What we will never build (unchanged, load-bearing)

**North star:** Minn is the writing editor for WordPress. Gutenberg is the layout tool.
See [content-blocks.md](content-blocks.md) for the author-facing contract.

Never: columns/groups/covers as a live layout canvas, pattern or design **authoring**,
page building, FSE, hosting third-party block editor JS, regenerating static `save()` HTML,
or "block parity" as a KPI.

OK (not the never-build list): **insert** finished patterns/design-library markup as
islands; content-edit text/images/attrs inside islands (and, since the nesting cycle,
write directly inside core containers); ship **content blocks** that Minn edits fully
(dynamic + schema-first).

Islands make the cost of *not* supporting a layout block small — it displays, survives, and
can be configured through the inspector where the server allows. If a post is mostly layout,
Gutenberg is the right tool and the handoff is one click. This list is what keeps the editor
good.

## Engineering posture

- **No build step stays.** One file, sections clearly banded. If the editor doubles the
  file, it doubles the file — greppability beats architecture astronautics at this scale.
- **The test suites are repo citizens** (since 2026-07-05). Suites live in `tests/`
  with conventions in `tests/README.md`; the sequential full-suite runner is part of
  the release rhythm. Editor bug fixes still ship with a ported suite.
- **Safety model is frozen.** `editorModeFor` → classic/blocks/locked, byte-identity
  islands, attribute allowlists that must be DOM-reproducible. New capabilities extend
  the allowlists one proven attribute at a time (see editor-direction.md); they never
  loosen the model.
