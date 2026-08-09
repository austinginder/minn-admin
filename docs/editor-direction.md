# Editor direction

> Where this is all heading: [editor-roadmap.md](editor-roadmap.md) — the editor as the
> selling feature, the horizons, and the never-build list.
>
> **North star:** Minn is the writing editor for WordPress. Gutenberg is the layout tool.
> Building document components that stay editable in Minn is welcome; see
> [content-blocks.md](content-blocks.md).

**Decision: keep and deepen Minn's own editor. Use Gutenberg as the escape hatch, not the foundation.**

## The options considered

1. **Embed the block editor** (`@wordpress/edit-post` or an iframe of `post.php`). Full block
   fidelity, but it drags in React, the build toolchain, and the exact visual noise Minn exists to
   remove. An iframed wp-admin inside Minn is two admins fighting in one window.
2. **Rebuild block support piecemeal in the custom editor.** Chasing full parity with Gutenberg
   (nested layouts, patterns, dynamic blocks) is a treadmill we would never get off.
3. **Hybrid (chosen).** Minn's editor owns the *writing* use case — the 90% of edits that are
   paragraphs, headings, lists, quotes, pullquotes, details, code and images. It reads and
   writes native Gutenberg block markup for that subset, so nothing is proprietary and every
   post remains fully editable in Gutenberg at any time. Anything beyond the safe subset
   (`SIMPLE_BLOCKS` in `app.js`) becomes an atomic island (or, historically, locked mode) and
   hands off to the real block editor with one click.

## Why hybrid wins

- **Interop is guaranteed by the storage format.** Minn writes `<!-- wp:paragraph -->`-style
  markup that `parse_blocks()` validates. There is no lock-in and no migration.
- **The lock is the safety valve.** `editorModeFor()` classifies content as `classic` / `blocks` /
  `locked`. Locked posts never have their content sent on save, so a complex layout can't be
  damaged by Minn — worst case you click through to Gutenberg.
- **No build step.** The whole app stays a single vanilla-JS file, which is the plugin's core
  architectural bet.

## Block islands — how complex content became safe to edit

The original design locked the whole body when any complex block appeared. That's now replaced
by **atomic block islands**:

1. `tokenizeBlocks()` splits raw content into top-level segments and verifies the segments
   reassemble the original byte-for-byte (fails → the old locked mode, now rare).
2. Simple, attribute-safe blocks become editable HTML. A simple block carrying attributes the
   serializer can't reproduce (`{"fontSize":"large"}`, image `{"id":…}`) is *not* edited lossily —
   it becomes an island too (`segmentEditable()` / `EDITABLE_ATTRS`).
3. Everything else renders as a `contenteditable="false"` island — a bordered card with the block
   name chip and a static preview — whose original markup is stored verbatim and spliced back
   unchanged on save. Deleting an island deletes the block; nothing else can happen to it.

The result: text edits flow *around* complex layouts with zero risk to them. Verified by
byte-comparing nested group/columns and shortcode blocks through a full edit-and-save cycle.
Known cosmetic effect: the first Minn save normalizes inter-block whitespace to the Gutenberg
standard blank line.

## The writing surface

The hybrid model decides what's *safe* to edit; these are the affordances that make the
editing itself fast (all landed in the v0.5.x cycle):

- **Markdown typing rules.** Inline wraps fire on the closing delimiter — `` `code` ``,
  `**bold**`, `*italic*`, `__bold__` / `_italic_` (word-boundary only, so `snake_case`
  survives), `~~strike~~`, `[text](url)` (URL-shaped destinations only). Block prefixes fire
  on space at a paragraph start — `#`–`######`, `-`/`*`/`+`, `1.`, `>` — plus ``` → code
  block and `---` → divider. Wraps go through `execCommand` so ⌘Z restores the literal text.
  Hard-won Blink facts live as comments in `bindMarkdown()`: `insertHTML` rewrites `<code>`
  into a styled span (code wraps are built manually), it rebalances adjacent spaces into
  nbsp (fixed post-insert; `cleanBoundaryNbsp()` also scrubs at serialize), and new lists
  nest inside the paragraph until lifted.
- **Inline-code boundary escape.** contenteditable offers no caret position that types
  *outside* an inline element at its edge — Chrome extends the format. Printable keys at a
  `<code>` edge are intercepted and inserted beside the element (unconditionally for code
  chips; one-shot for the element a markdown wrap just created, so toolbar bold-then-type
  still extends).
- **Calm, status-aware autosave.** 15s idle / 60s max-while-typing. Drafts save in place;
  published/scheduled/private posts are **never** written by autosave — edits back up to a
  WP autosave revision (like Gutenberg) and apply only on Update/⌘S. Save draft button,
  ⌘S, an Unsaved-changes indicator, flush-on-navigate and an unload warning round it out.
- **Slash menu filters as you type** (`/co` → Code); a second `/` (a literal path) closes it.
- **Word count · reading time** in a sticky pill under the body.
- **Previews wear the site's real styles.** `minn-admin/v1/editor-styles` collects every
  registered block's style handles, the theme's editor styles and the global stylesheet;
  the client scopes every rule to `.minn-island-preview` (html/body/:root map onto the
  container) and injects once. Islands render like the front end; the typing surface
  deliberately keeps Minn's own typography.

## Where the line moves over time

Grow `SIMPLE_BLOCKS` and `EDITABLE_ATTRS` deliberately, one block/attribute at a time, only when
the round-trip is proven safe. Two later mechanisms moved the line substantially: the **block
inspector** (docs/block-inspector.md) makes islands configurable without making them editable,
and **attribute passthrough** (`PASSTHROUGH_BLOCKS`) lets attribute-carrying instances of
non-text-flow simple blocks — images with `{"id":…}`, styled tables/quotes/separators — stay
editable by parking the comment JSON on the element (`data-minn-attrs`) and re-emitting it
byte-faithfully on save. Text-flow blocks (paragraphs, headings, lists) have been deliberately
excluded so far: contenteditable splits clone element attributes, which would duplicate the
marker. The nested-content plan below revisits that exclusion, since Gutenberg's own split
copies attributes to both halves, making the duplication correct semantics rather than
corruption. Islands make the cost of *not* supporting a block small — it still
displays and survives — so there is no pressure to chase parity. If a site's content is mostly
complex layouts, Gutenberg is simply the right tool and Minn should be great at everything
*around* the editor.

Authors who want first-class Minn editing should build **content blocks** (dynamic, schema-
first, words in attributes), not layout kits. The contract and Anchor Blocks reference are in
[content-blocks.md](content-blocks.md).

## The nested-content plan (plan of record, 2026-08-08)

Written in response to [GH #4](https://github.com/austinginder/minn-admin/issues/4): on a
fully FSE, core-blocks-only site, grouped content locks. The gap is now measured, not
anecdotal. A classification probe over the Twenty Twenty-Four/Five pattern corpus (the
closest stand-in for content an average user builds in FSE) found that effectively **100% of
top-level segments island**, dominated by `group` wrappers, and that **63% of core text
blocks anywhere in that markup would island on attributes alone** even if containers
recursed. The offenders rank: `fontSize` (165), `style` (121), `className` (38), `textColor`
(22), `fontFamily` (16). Two conclusions follow. For FSE sites the container is the primary
problem, not the attributes, and attribute support must be verbatim *carry*, not a longer
whitelist, because `style` is an arbitrary JSON blob no serializer can reproduce from the DOM.

The load-bearing precedent already ships: the **details island** renders a
`contenteditable="false"` shell with a `contenteditable="true"` body inside it, commits edits
into `ed.islands[idx]`, and Blink respects the boundary, so typing in the editable interior
can never merge into or destroy the preserved shell. Container support generalizes that
pattern instead of rebuilding the editor.

Three phases, each shippable alone, in order:

1. **Attribute carry for text-flow blocks** (medium). Extend the `data-minn-attrs`
   passthrough to paragraphs, headings and lists carrying attributes outside
   `EDITABLE_ATTRS`: park the comment JSON on the element verbatim, keep the element's saved
   classes and inline styles in the DOM as they already are, and re-emit the stored JSON at
   serialize. Enter-splitting duplicates the marker to both halves, which matches Gutenberg's
   own split behavior. Gate the build on a one-script Blink probe of split/merge/undo around
   the marker. This alone unlocks the 63%.
   ✅ *Shipped 2026-08-08 (v0.26.0 cycle)* — `TEXTFLOW_CARRY_BLOCKS` (paragraph, heading,
   list) join `segmentEditable`; the load path parks the full attrs JSON on the element
   (and, for lists, each list-item's own attrs on its `<li>` — closing a pre-existing hole
   where list-item attrs vanished silently on save). The serializer emits carried JSON
   verbatim, merging only the DOM-editable keys back in (paragraph `align`, heading
   `textAlign`, list numbering — the table `hasFixedLayout` precedent), keeps the marker
   element's inline style (saved content, exempt from the chrome style-strip), and keeps
   empty marker paragraphs as real blocks. Probed Blink facts (`scratchpad/probe-attr-carry.*`):
   mid-split copies class+style+marker to both halves (correct Gutenberg semantics); merge
   keeps the first block's attrs (also correct); end-split clones the marker onto the empty
   half, so a keydown observer strips marker+class+style from an empty same-marker sibling
   one frame later (out-of-stack ATTR mutation is undo-safe — probed; text mutation is not);
   `formatBlock` HALF-drops attrs (class+marker gone, style kept), which is why block-TYPE
   conversions (markdown `#`/`-`/`1.`/`>`/```` ``` ````/`---` prefixes, toolbar block +
   list buttons) are refused on marker blocks with a toast. Inline marks stay fully allowed.
   Suite: `tests/attr-carry.test.js` (20 checks, byte-identity assertions on saved JSON).
2. **Editable text inside island previews** (medium, highest daily leverage). The text-runs
   machinery (`textRunsOf` / `spliceTextRuns`) already edits island text through inspector
   textareas by byte-offset splice. Move that editing in place: the preview's text runs
   become directly editable, and each edit splices back into the stored raw markup, which
   otherwise stays verbatim. Serialization fidelity is free by construction, and it works
   for every island, core containers and third-party blocks alike. Accepted constraint,
   stated in the UI: text and inline edits only, no Enter-splitting into new blocks.
   ✅ *Shipped 2026-08-08 (v0.26.0 cycle)* — `armIslandTextRuns()` wraps preview text
   nodes in nested `contenteditable` spans after every preview render, gated on STRICT
   alignment (every raw text run must byte-match its preview text node, whitespace
   padding included; any mismatch leaves that island read-only with the ⚙ inspector as
   before — the fallback for dynamic renders that rewrite text). Edits splice into
   `ed.islands[idx]` from the arm-time base on every input, so serialize needed zero
   changes. Blink facts the build stands on (probed, `scratchpad/probe-nested-span.*`):
   edge Backspace/Delete inside a nested editable are native no-ops, ⌘Z tracks in-span
   typing, arrows flow between runs — but ⌘A escapes to the outer body (clamped), Enter
   inserts `<br><br>` (blocked), and `stopPropagation` does NOT stop same-node listeners,
   so the run keydown branch uses `stopImmediatePropagation` or markdown wraps and the
   slash menu fire inside runs. `bindIslandGuards` needed an explicit run bail: its
   caret walk otherwise resolves to the island itself and Backspace-in-run arms then
   DELETES the whole block. Embed/gallery islands are excluded (their URL is itself a
   text node). Suite: `tests/island-runs.test.js` (20 checks, saved-markup assertions).
3. **Container slots** (large). `group` / `columns` / `column` / `cover` / `media-text`
   render their real wrapper markup as a preserved shell (the details-island pattern),
   their inner markup tokenizes into child segments, simple children become editable slots,
   and complex children stay nested mini-islands. Serialize splices each slot's output back
   between the container's verbatim byte ranges, the segment-level version of what text runs
   do today. The first save may normalize whitespace inside a touched container, which is
   the established fixed-point convention. Feature parity inside slots (markdown rules,
   toolbar, slash) arrives incrementally per feature, never as a precondition. Depth can
   stay limited to what real content needs; measure the corpus before recursing deeper.
   ✅ *Slice 1 shipped 2026-08-08 (v0.26.0 cycle)* — `SLOT_BLOCKS = ['group']`, single
   depth, ALL-simple children only (any complex child → the whole container stays a
   phase-2 island; no nested mini-islands yet). `slotParseContainer()` splits the raw
   into head/open/inner/tail with a tag-depth scan and a reassembly gate; children render
   through the same `editableSegmentHtml()` as the top level (phase-1 markers included),
   inside `<div class="minn-slot" contenteditable="true">` within the verbatim wrapper
   open tag. Serialize: `flushSlotIsland()` splices `serializeToBlocks(slot)` between the
   wrapper bytes ONLY when the slot is dirty (input-stamped `data-minn-slot-dirty`);
   untouched groups emit stored raw byte-identical. Working in slots with zero extra
   code: typing, Enter-splitting, merges, inline markdown, undo, phase-1 attribute
   carry. Guarded: island-guard bail (Backspace inside a slot otherwise armed and
   DELETED the container — the run-bail class), ⌘A clamped to the slot, plain-text
   paste (the rich pipeline's block splicing is top-level-tuned), per-slot trailing
   affordance, inspector flushes a dirty slot before modeling. Same day, the follow-up
   slice shipped BLOCK CREATION inside slots: markdown block prefixes (`#`…, `-`, `1.`,
   `>`, `---`), the toolbar's block/list buttons, and the slash menu all treat the
   nearest `.minn-slot` as their block root (via `blockRootOf`/`topBlockIn` since
   the consolidation pass, plus `liftNestedLists` per root). The slash menu inside a slot offers ONLY the
   prose-safe basics — actions stamped `minnSlotSafe` (headings, quote, pullquote,
   code, lists, divider); island inserts, media flows, tables and Browse-all stay
   top-level. Root-cause fix that fell out: Minn never set
   `defaultParagraphSeparator`, so Blink created `<div>`s on list-exit/insertParagraph
   (the serializer papered over them; the prefix handlers refused them) — now set to
   `p` document-wide at editor bind. COLUMNS shipped the same day:
   an all-simple `columns` block becomes a MULTI-slot island — one editable slot per
   `column`, both nesting levels' wrapper bytes (columns comment/open tag AND each
   column's) preserved verbatim; flush walks the stored raw's segments and splices each
   column's serialized children between ITS bytes, inter-column whitespace re-emitting
   untouched. Editor-side flex CSS mirrors the front-end shape (a column's own
   flex-basis inline style applies since the open tag is verbatim); a dashed divider
   marks each column's region. Any complex child in ANY column → the whole block stays
   a phase-2 island. Still NOT in slots: multi-block paste, table/code chips (top-level
   chrome), cover/media-text, nesting. Suite: `tests/container-slots.test.js`
   (34 checks incl. untouched-group byte-identity, slot slash-menu contents,
   per-column splice targeting and slot-interior copy).

### The writing-context contract (consolidation pass, 2026-08-08)

Slots and runs mean the editor now has FOUR editing contexts — body, container
slot (a mini-body), island text run (text-only) and live-field island — and every
future editor feature owes an answer for each. Two things keep that from rotting:

- **One canonical resolver.** `blockRootOf( node, body )` returns the node's
  container slot else the body; `topBlockIn( node, body )` returns its top-level
  block within that root. Every "walk to the top-level block" that should treat
  slots as mini-bodies goes through these (markdown prefixes, the toolbar's block
  and alignment buttons, inline code, the slash menu, focus-mode banding, the
  code-chip space heuristic). A new feature that uses them inherits slot support
  for free; a new CONTEXT is one function to teach.
- **Deliberate exceptions are labelled.** The walks that must stay body-anchored
  carry a `body-root by design` comment saying why: island inserts (islands only
  exist at the top level), image-figure landing, the block picker, block-level
  copy/cut semantics, and the island arm/delete guards (which bail for slot and
  run interiors before their body checks run). Any future body-anchored walk
  should either migrate or say why not.

Bug the pass surfaced: a partial-text selection inside a slot or run resolved
through the body walk to the whole island, so copying two words copied the entire
block. Copy/cut now bails to native handling when the selection is contained in
one slot or one run (suites: `container-slots`, `island-runs`).

### What remains (handoff, as of 2026-08-08)

Everything below is scoped but NOT built. Ordered as recommended, cheapest first.
Nothing here is required for the plan to be useful: the fallback at every gate is a
phase-2 island with in-place text editing, which is a working experience, not a broken
one. Read the writing-context contract above before touching any of it.

**Ready to build (small, ~half day each):**

1. **Table and code chips inside slots.** `syncTableChips()` collects its targets with
   `:scope > table` / `:scope > figure … img` / `:scope > pre` against the body only, so a
   table or code block inside a group gets no ⚙ chip (and therefore no row/column ops or
   language picker). Positioning already works at any depth — the chips live in
   `#minn-table-chips` at content coordinates inside the scroller. The fix is to run the
   collection per block root (body + each `.minn-slot`), the `liftNestedLists` shape.
   Watch: chip count on a heavily structured page (the visual-noise question below).
   ✅ *Shipped 2026-08-09* — per-root collection landed as scoped. The real work was the
   DIRTY-STAMP half: chip ops that mutate the DOM directly (`setCodeLang`, image-popover
   apply/replace) never fire `input`, so `flushSlotIsland` would silently drop them at
   save — `stampSlotDirtyFor( el )` now stamps the containing island by hand on those
   paths. execCommand paths (all table ops, image remove) need no call: probed
   (`scratchpad/probe-slot-chips.js`), Blink fires `input` ON THE SLOT HOST for a
   selection inside a nested editable, and the body's existing input handler stamps it.
   Bonus: table-delete now seats the caret via `blockRootOf`, so deleting a slot table
   lands in its slot instead of the document top. Suite: `container-slots` grew to 39
   (chip presence, code-language + row-op round-trips through saved raw, delete landing).
2. **Multi-block rich paste into slots.** Slot paste is deliberately plain text today
   (`body.addEventListener('paste', …)` capture branch). `pasteBlocksInsert()` brackets
   payloads with `<p data-minn-bkt>` markers and rebuilds islands at the top level; the
   generalization is the same root swap plus refusing island-class payloads inside a slot
   (they'd need nested islands, item 5). Suite should cover a Word/Docs multi-paragraph
   paste landing as separate blocks inside a group.
   ✅ *Shipped 2026-08-09* — the capture-phase plain-text intercept is GONE; slots ride
   the main pipeline. `pasteInsert`/`pasteBlocksInsert` take the caret's block root
   (`blockRootOf`, bracket-marker scan + list lift per root), so sanitized Docs/Word
   payloads land as separate real blocks inside the group and splice on save. Guards,
   all keyed on the SELECTION's slot (`selSlot` — e.target is useless for synthetic
   ClipboardEvents, which dispatch on the body): the embed-URL fast path and clipboard
   image FILES stay top-level (media flow in slots is future work; a files-only paste
   in a slot no-ops), and a `text/x-minn-blocks` payload splices in a slot only when
   EVERY segment is editable — island-class payloads fall through to the html/text
   flavors and land as prose. Suite: container-slots 44 (multi-block html paste saved
   inside the group, multi-line text → paragraphs, island payload refused); the two
   legacy plain-paste checks flipped to assert rich behavior. Neighbors green: paste
   37, island-runs 21, media-flow 14, undo-toast 22, island-copy 14, attr-carry 20.

**Ready to build (medium, 1–2 days each):**

3. **Cover and media-text slots.** These break `slotParseContainer()`'s assumption that
   children sit directly inside the wrapper's open tag: `cover` is
   `wrapper → background span/img → inner-container div → children`, `media-text` is
   `wrapper → figure → div.wp-block-media-text__content → children`. Add a per-block
   "content container" locator so the parse yields head/open/**preamble**/inner/tail, with
   the background/media bytes preserved verbatim like everything else. The reassembly gate
   still decides: no byte-identical parse, no slot. Keep the SAME dirty-flush contract.

**The last big piece (large, 3–5 days, do it last):**

4. **Nested containers** (a group inside a group) and **5. nested islands** (an image,
   embed or third-party block inside a group) are ONE project, not two. Today
   `slotChildSegments()` returns null for any non-simple child, which is exactly what keeps
   the protective apparatus simple: `bindIslandGuards` bails wholesale for slot interiors,
   island inserts are body-anchored by design, and `ed.islands` is a flat top-level array.
   Allowing islands inside slots means re-scoping all of that per root instead of bailing:
   arm/delete guards and the undo toast per slot, insert flows (slash/picker/paste/image
   upload) targeting a slot, and island indices that live inside a container's stored raw
   rather than the flat array. Nested containers then fall out for free (a group child is
   just another slot island), and the `SLOT_BLOCKS` + all-simple gates can be DELETED —
   every group/columns becomes writable, with complex children as protected cards inside.
   That is the plan's true end state.

   **Measure before building this.** Re-run the corpus probe asking a different question
   than last time: *what share of real containers are MIXED* (simple + complex children)?
   If mixed containers are rare, the current fallback already covers real content and this
   item can stay unbuilt indefinitely — a legitimate stopping point, not a compromise. The
   earlier probe measured which blocks island, not which containers are mixed.

   ✅ *Measured 2026-08-09* (`scratchpad/probe-container-mix.php` + `-names.php`, TT4+TT5
   patterns, 155 patterns / 494 container units). **Mixed is the norm, not the exception —
   the "stay unbuilt" exit does NOT hold.** Of 494 units (columns counted with their
   columns as one unit): 20% all-simple, 39% mixed only by NESTED CONTAINERS, 41% carry a
   complex leaf. At the top level, 3 of 131 containers slot today (2%); nesting alone
   (item 4) lifts that to 26 (20%); the other 105 need nested islands (item 5) — so the
   two items are confirmed as one project, and it is the whole remaining game. The
   complex-LEAF distribution is the encouraging part: template constructs (pattern, query,
   post-*/site-*/query-*, navigation, template-part) dominate the corpus but never appear
   in POST content — for post-shaped content the leaf problem collapses to essentially
   **spacer (122) + buttons (31)**, both of which already render as well-behaved islands.
   REPRIORITIZATION: **item 3 (cover/media-text) is skipped as a standalone slice** —
   media-text appears ZERO times in the corpus, cover 21 times but almost always NESTED
   inside groups (top-level coverage gain on this corpus: zero). Cover joins as a nested
   unit when item 4 lands. Caveat to carry: this corpus is FSE-template-heavy; it is the
   same stand-in the original 63% probe used, so the numbers are comparable, but they
   overstate template constructs relative to what a writer's post contains.

   ✅ *Slice 1 shipped 2026-08-09 — nested islands + nested containers are LIVE.* The
   all-simple gate is DELETED: every group/columns whose inner markup parses becomes a
   slot island; complex children render as protected nested islands (previews, runs,
   live fields, inspector, ⚙ chips all work through the existing per-root machinery)
   and container children recurse (group-in-group, columns-in-group, any depth). What
   the slice actually consisted of: (a) `slotChildSegments` keeps complex segments;
   `slotIslandHtml`/`islandHtml` take `ed` and register child islands (no `ed` at an
   insert site → plain island, upgraded on reload — the standing asymmetry);
   (b) `flushSlotIsland` filters to OWN slots (`closest('.minn-slot-island') === el` —
   a descendant query would misalign the columns walk once slots nest); nested slot
   islands flush through `serializeToBlocks`' existing recursion; (c) THE LOAD-BEARING
   INVARIANT: any write to a nested island's `ed.islands[idx]` or any direct-DOM edit
   inside a slot must stamp EVERY ANCESTOR slot island dirty (`stampSlotDirtyFor` walks
   up; called from the input handler, both undo helpers, `replaceIsland`,
   `applyInspector` and the three live-field commits) — an unstamped ancestor emits its
   stale stored raw and silently drops the nested edit; (d) `bindIslandGuards` is
   root-scoped (`blockRootOf` from the SELECTION; `isRootChild` = direct child of body
   or slot) instead of bailing for slots — Backspace arms/removes nested islands with
   the same two-press + undo-toast model; the `.minn-island-run` bail stays;
   (e) "nearest wrapper" rule for editability checks (image click, link click, table
   context menu): closest of `.minn-block-island, .minn-slot` — a slot means writing
   surface, an island means preview chrome; (f) slot-scoped CSS twins for the
   table/image cutouts and cell gridlines (`.minn-slot > …` beside
   `.minn-editor-body > …`); (g) `armIslandTextRuns` explicitly skips slot islands
   (its descendant preview query would otherwise walk a NESTED island's preview
   against the container's raw). Suites: NEW `tests/nested-islands.test.js` (16 —
   spacer+buttons leaves, group-in-group both-wrapper splice, byte-identity modulo
   the buttons live-field's first-save fixed point, nested arm/remove/undo/save);
   `container-slots` 44 re-pinned to the new model (a complex child no longer sinks
   the container — the acme columns is a slot island with a nested badge);
   `island-runs` 21 refixtured onto FOREIGN wrapper blocks (containers always slot
   now, so runs belong to non-container islands).

   ✅ *Insert flows shipped 2026-08-09 (same day, follow-up slice).* Slots take the
   FULL insert surface now: the slash menu's slot filter and the `slotOk`/`minnSlotSafe`
   mechanism are DELETED (every item incl. search-only design/pattern entries and
   Browse all works in slots), the ⌘/ block picker targets the caret's root, embed-URL
   paste islands in the slot, Minn-blocks paste payloads splice islands nested, and the
   media flow (paste/drop image files, picker inserts) lands in the caret's slot.
   Mechanics: `insertIsland`/`insertPatternIslands` are root-aware, pass `ed` (an
   inserted group slots immediately) and stamp ancestor slots (insertAdjacentHTML fires
   no input — the ⌘/ path never types a "/" so it can't rely on the typing stamp);
   `runSlashAction`'s direct-DOM branches (action.html, action.block, command r.html)
   stamp their landing paragraph; `insertImageFiles`' constrained-caret hop lands
   inside the slot. Suites: nested-islands 20 (slash table insert + embed paste round-
   trips), container-slots 44 re-pinned (full slot menu; island paste now SPLICES),
   patterns 9, block-picker 10, stackable-designs 19/20. TWO SUITE-LATENCY PHANTOMS
   fixed on the way: patterns and stackable-designs both used flat post-⌘S waits
   (the rule-77 class) and read stale raw — both now poll for expected content.
   stackable's remaining fail ('add clones the static column', 1 column where 2
   expected) is PRE-EXISTING (bisected: identical at pre-nesting adec8ba) and smells
   like upstream CDN design drift — re-pin at the next design-suite sweep, don't
   chase it as a nesting regression.

   ✅ *Cover + media-text slots shipped 2026-08-09 (same day, final slice).*
   `SLOT_CONTENT` maps a block to its content-container class
   (cover → `wp-block-cover__inner-container`, media-text →
   `wp-block-media-text__content`); `slotParseContent( raw, short )` wraps
   `slotParseContainer` and yields head/open/**preamble**/inner/tail — the preamble
   is everything from the wrapper's open tag through the content container's open
   tag (backgrounds, media figure), preserved verbatim like the wrapper bytes; the
   content container must be the LAST child (whitespace only after its close) or
   the gate fails and the block stays an island, and the reassembly identity now
   includes the preamble. REGEX LESSON: the content-open matcher must be a plain
   `<div[^>]*\\bCLASS\\b[^>]*>` — the attr-alternation form
   (`[^>"']|"[^"]*"|'[^']*'`) makes quoted content unreachable for the class
   match (quoted strings only match whole tokens), so it NEVER found the class
   inside `class="…"`. Editor CSS approximates the front-end SHAPE only
   (`.minn-media-slot-island`: cover background absolute behind a z-raised slot,
   media-text as a 2-col grid). SLOT_BLOCKS is now group/columns/cover/media-text.
   Suite: nested-islands 27 (cover + media-text byte-identity, preamble-verbatim
   edits in both). Remaining tail: the chip-density design pass; dogfood the whole
   nesting cycle on anchor.localhost before it rides a release.

**Watch items (not scope, but decide before shipping the above):**

- **Chip and hint density.** Slots + nested islands multiply ⚙ chips and hover hints on a
  structured page. Hover-reveal exists; a deeply nested page still wants a design answer
  before item 5 ships, not after.
- **`defaultParagraphSeparator`.** The consolidation-era fix sets it to `p` document-wide
  at editor bind, correcting a pre-existing quirk (Blink made `<div>`s on list-exit; the
  serializer papered over them). All suites pass, but this is the kind of change whose
  edges show up in real writing rather than tests — dogfood a week on anchor.localhost
  before it goes out in a release.
- **`code-trap.test.js` sits at 11/12** and did so BEFORE this cycle (verified by stashing
  the changes) — the known save-race-under-load class, not a nested-content regression.
  Don't chase it as one.

**Suites owning this work:** `tests/attr-carry.test.js` (20), `tests/island-runs.test.js`
(21), `tests/container-slots.test.js` (34). Any change to the slot/run/carry machinery
should keep all three green plus `markdown`, `paste`, `undo-toast`, `island-copy` and
`media-flow` (the Enter/clipboard-sensitive neighbours).

The never-build list is unchanged by this plan. Slots edit **content** inside layouts;
layout itself (spacing, variations, query loops, the block inserter's full catalog) remains
Gutenberg's job, one click away. Byte-identity for everything untouched stays the
non-negotiable invariant at every phase.
