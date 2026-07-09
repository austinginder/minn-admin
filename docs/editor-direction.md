# Editor direction

> Where this is all heading: [editor-roadmap.md](editor-roadmap.md) — the editor as the
> selling feature, the horizons, and the never-build list.

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
byte-faithfully on save. Text-flow blocks (paragraphs, headings, lists) are deliberately
excluded: contenteditable splits clone element attributes, which would duplicate the marker. Islands make the cost of *not* supporting a block small — it still
displays and survives — so there is no pressure to chase parity. If a site's content is mostly
complex layouts, Gutenberg is simply the right tool and Minn should be great at everything
*around* the editor.
