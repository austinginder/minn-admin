# Block inspector — editing complex blocks without Gutenberg

**Status: design (targeted at v0.4.0). Nothing here is built yet.**

Block islands made complex content *safe* (see [editor-direction.md](editor-direction.md)) —
this is the plan for making them *workable*. The goal: click an island, get a small inspector
popover next to it, edit the block's configuration in place, watch the preview update. No React,
no build step, no change to the safety model.

## Why this is feasible at all

The key realization: for **server-registered blocks**, everything the inspector needs already
exists as core REST endpoints — Minn drives the same server-side machinery Gutenberg does,
minus the React shell.

| Need | Endpoint | Verified |
|---|---|---|
| What attributes does this block have? | `GET wp/v2/block-types/<namespace>/<name>` → full attribute schema with types and defaults | ✓ returns `role` / `label` / `content` for `anchor/conversation-message` |
| What does it look like with these attributes? | `GET wp/v2/block-renderer/<name>?context=edit&attributes={…}` → server-rendered HTML | ✓ renders `anchor/stat-card` with arbitrary attrs |

Self-closing dynamic blocks (`<!-- wp:anchor/stat-card {"value":"85","label":"…"} /-->`) are
**pure attribute containers** — no saved HTML exists to invalidate, so editing them is literally
rewriting JSON inside a comment. The island's raw markup is already stored verbatim and spliced
back on save; the inspector just modifies that stored string. Nothing about the byte-identity
model changes.

## The design

1. **Trigger** — a ⚙ affordance on the island chip (and clicking the chip itself). Opens a
   popover anchored to the island, same visual family as the slash-command menu.
2. **Schema-driven form** — fetch the block type once (cache per session), generate fields from
   the attribute schema: `string` → input, `boolean` → switch, `number` → number input,
   `enum` → select. Skip `lock` / `metadata` / `style` (Gutenberg plumbing).
3. **Apply** — rewrite the attributes JSON in the island's stored raw markup: for self-closing
   blocks, rebuild the whole comment; for wrapped dynamic blocks, rebuild only the opening
   comment and keep inner HTML untouched.
4. **Preview refresh** — POST the new attrs to `block-renderer` and swap the island preview.
   Blocks with no `render_callback` keep their static preview.
5. **Serialize** — unchanged. Islands still pass through verbatim from stored raw markup.

### Nested islands (`anchor/conversation`)

The conversation block is a static InnerBlocks wrapper whose children are self-closing dynamic
`conversation-message` blocks. `tokenizeBlocks()` already splits nested content, so the
inspector can list children and edit each one with the same schema-driven form — including
add / remove / reorder, since children are attribute-only comments. The parent's own wrapper
markup (`<div class="wp-block-anchor-conversation"><div class="ab-conv-header">…</div>`) is
reproducible but is saved by the block's JS `save()`, so regenerating it risks Gutenberg's
block validation — **ship child editing first, parent-attribute editing last, behind
round-trip tests.**

### The code block (ships first, independent of the inspector)

`<!-- wp:code {"language":"sql"} -->` islands today only because `language` isn't in
`EDITABLE_ATTRS`. Add `code: [ 'language' ]`, map the attribute into the existing toolbar
language picker on load, re-emit attribute + `language-*` class on serialize. The block becomes
fully editable and the picker (built in v0.2.0) just works. Small, high value — the most common
island on real content.

## The honest limit

**Static third-party blocks** — ones whose `save()` lives in their editor JS bundle — stay
verbatim islands. Changing their attributes requires regenerating HTML only Gutenberg's runtime
can produce; that's the parity treadmill editor-direction.md refuses to get on. They already
display and survive edits safely, and WordPress has pushed block development server-side for
years, so the reachable set keeps growing on its own.

## Extension point

Schema types alone can't express intent: `role` is really a user/assistant enum, `content`
deserves a textarea, `color` is a fixed palette. A `minn_admin_block_forms` filter (same
declarative-descriptor pattern as surfaces and editor panels — see
[for-plugin-authors.md](for-plugin-authors.md)) lets a plugin refine the generated form for its
own blocks: labels, control types, option lists, field order. Anchor Blocks becomes the
reference adapter, the same role ACF plays for editor panels.

## Sequencing

1. `code.language` via `EDITABLE_ATTRS` — small.
2. Inspector for attribute-only / dynamic islands (stat-card, callout, timeline-item…) — the
   core build: schema fetch, form generation, comment rewrite, renderer preview.
3. Child-block editing inside InnerBlocks islands (conversation messages).
4. `minn_admin_block_forms` filter + Anchor Blocks descriptors.
5. (Maybe, last) parent-attribute editing for simple InnerBlocks wrappers, gated on proven
   round-trips.
