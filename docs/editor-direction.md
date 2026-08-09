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
   nearest `.minn-slot` as their block root (`mdRootOf`/`topBlockOf`, the toolbar
   walks, `liftNestedLists` per root). The slash menu inside a slot offers ONLY the
   prose-safe basics — actions stamped `minnSlotSafe` (headings, quote, pullquote,
   code, lists, divider); island inserts, media flows, tables and Browse-all stay
   top-level. Root-cause fix that fell out: Minn never set
   `defaultParagraphSeparator`, so Blink created `<div>`s on list-exit/insertParagraph
   (the serializer papered over them; the prefix handlers refused them) — now set to
   `p` document-wide at editor bind. Still NOT in slots: multi-block paste, table/code
   chips (top-level chrome), columns/cover, nesting. Suite:
   `tests/container-slots.test.js` (27 checks incl. untouched-group byte-identity and
   slot slash-menu contents).

The never-build list is unchanged by this plan. Slots edit **content** inside layouts;
layout itself (spacing, variations, query loops, the block inserter's full catalog) remains
Gutenberg's job, one click away. Byte-identity for everything untouched stays the
non-negotiable invariant at every phase.
