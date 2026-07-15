# Content blocks for Minn Admin

**North star:** Minn is the writing editor for WordPress. Gutenberg is the layout tool.

This doc is the contract for people who want to **build blocks that stay fully workable in
Minn**. It is not a promise that every block on every site gets a full editor. Layout kits
belong in the block editor. Document components belong here.

API details (filters, descriptor keys, island CSS hooks) live in
[for-plugin-authors.md](for-plugin-authors.md). Editor safety model:
[editor-direction.md](editor-direction.md). Inspector history:
[block-inspector.md](block-inspector.md).

## Why custom blocks are welcome

Minn does not need to become Gutenberg for authors to extend it. It needs **document
types**: callouts, conversations, timelines, stat strips, report cards, FAQs, pull-stats.
Those are the same job as headings and pullquotes, just richer.

Building a block plugin so writers can keep editing posts in Minn is correct and expected.
[Anchor Blocks](https://github.com/anchorhost/anchor-blocks) is the reference: server-rendered
leaves, PHP attribute schemas, thin `app/MinnAdmin.php` for insert templates and labels, no
third-party JavaScript inside Minn.

What would be wrong is promising full fidelity for every static-save design system, or
growing Minn's SPA to reimplement plugin React UIs. Content-block fidelity is the product.
Layout-block fidelity is the handoff.

## Two kinds of blocks

| | **Content blocks (for Minn)** | **Layout blocks (for Gutenberg)** |
|---|---|---|
| Job | Express meaning in a post | Compose visual layout |
| Truth | Attributes + server render (or simple, known markup) | Client `save()` + design UI |
| Edit in Minn | Inspector, child lists, text/image tools, live fields | Island + "Block editor ↗" |
| Author goal | "I can write this post in Minn" | "I can design this page without code" |
| Examples | Callout, conversation message, stat card, FAQ item | Column systems, cover playgrounds, device-visibility trees |

Both kinds are valid WordPress. Minn optimizes for the left column and keeps the right column
**safe** (byte-identical islands, real previews, content-in-island edits where markup allows).

**Litmus:** if changing the *copy* requires opening the block editor, it is not a Minn content
block yet. If changing *spacing, columns, or absolute layout* requires the block editor, that
is correct and permanent.

## What Minn will never reimplement

Frozen for the writing product (see [editor-roadmap.md](editor-roadmap.md)):

- Nested layout as a first-class canvas (groups, columns, covers as drag targets)
- Hosting third-party block editor JavaScript inside Minn
- Regenerating static HTML that only a plugin's JS `save()` knows how to produce
- Full Site Editing / template parts / global styles as Minn surfaces
- "Block parity" as a roadmap KPI

Minn **may** insert finished serialized markup (patterns, design libraries) as islands, and
**may** content-edit text and images inside those islands. That is not layout authoring. It is
insert + write.

## The content-block contract

Design for this path and most blocks need zero Minn-specific code.

### Prefer

1. **`render_callback` (or block.json `render`) + `save: null`** for leaves.  
   A bare `<!-- wp:namespace/name {"attr":"…"} /-->` is valid saved markup and valid preview.
2. **Attributes that *are* the content.** Title, body, label, enum style, numeric value.  
   Not a parallel design-token tree the front end ignores.
3. **Register the schema in PHP or `block.json`.** Types, enums, defaults, titles, `parent` /
   `ancestor`. Minn's inspector and auto-insert both read the server registry.
4. **Meaningful output from defaults.** Empty or default attrs should still render something
   honest in `do_blocks`, or ship an `insert.template` with starter content.
5. **Front-end styles registered so `do_blocks` can enqueue them** (not only when
   `has_block()` finds them on a saved post). Island previews run the real render path.
6. **Children as content units** (message, row, card, item), not free layout cells.

### Use sparingly (still supported)

1. **Static InnerBlocks wrappers** when a fixed shell is required (conversation, timeline).  
   Ship an `insert.template` with real wrapper HTML + starter children. Do not rely on a bare
   self-closing comment. Minn can reorder/add/remove **content** children; it will not
   regenerate arbitrary parent `save()` output for design attrs.
2. **`minn_admin_block_forms`** for labels, control types, option wording, field order, and
   insert seeds. Refinement of the schema, not a second registration system.
3. **`wrapperText`** for a single declared text region in a static parent wrapper (e.g. a
   conversation header). See for-plugin-authors.md.

### Avoid if Minn editing is a goal

1. **Static `save()` that invents large HTML/CSS** from complex attributes (layout kits).
2. **Hybrid blocks** (render_callback *and* JS `save()` wrapper) without a known insert
   template. Bare comments often render empty and fail Gutenberg validation.
3. **Empty server schema** with all truth only in editor JavaScript.
4. **Design controls as the product** (spacing scales, absolute position, breakpoint trees).
5. **Requiring the block editor** to change words the reader sees.

## How Minn treats your block (by class)

| Your block | Insert | Edit content | Edit design / structure |
|---|---|---|---|
| Dynamic leaf, good schema | Auto (search) or slash via `insert` | Inspector form | N/A if attrs *are* content |
| Dynamic parent of dynamic children | Template or empty + add children | Children list + forms | Add / remove / reorder content children when structural |
| Static InnerBlocks content parent | **Required** `insert.template` | Children + `wrapperText` | Parent design stays in block editor |
| Static-save design kit | Design library / pattern markup, or paste from Gutenberg | Text runs + image swap when markup allows | Block editor only |
| Hybrid without template | Excluded from auto-insert | Island if already in post | Block editor |

Generic tools that apply without an adapter:

- **Text runs** on island HTML (edit text nodes without regenerating structure)
- **Image swaps** when URL and media id conventions are mirrored in attrs + markup
- **Schema inspector** for registered attributes (non-sourced)
- **Real front-end preview** via `minn-admin/v1/render-blocks`

## Ship the integration in *your* plugin

Same convention as surfaces: one file, unconditional filters, free no-op when Minn is absent.

```
my-plugin/
└── includes/minn-admin.php   ← minn_admin_block_forms, optional insert_blocks tweaks
```

Reference: Anchor Blocks `app/MinnAdmin.php`.

Minimal dynamic leaf (often enough alone):

```php
register_block_type( 'my-plugin/pull-stat', array(
	'title'           => 'Pull stat',
	'attributes'      => array(
		'value' => array( 'type' => 'string', 'default' => '0' ),
		'label' => array( 'type' => 'string', 'default' => 'Label' ),
		'color' => array(
			'type'    => 'string',
			'default' => 'blue',
			'enum'    => array( 'blue', 'green', 'red' ),
		),
	),
	'render_callback' => 'my_plugin_render_pull_stat',
) );
```

Optional polish:

```php
add_filter( 'minn_admin_block_forms', function ( $forms ) {
	$forms['my-plugin/pull-stat'] = array(
		'insert' => array(
			'label'    => 'Pull stat',
			'template' => '<!-- wp:my-plugin/pull-stat {"value":"42","label":"Uptime"} /-->',
		),
		'attributes' => array(
			'value' => array( 'label' => 'Value' ),
			'label' => array( 'label' => 'Caption' ),
			'color' => array(
				'label'   => 'Accent',
				'control' => 'select',
				'options' => array(
					array( 'blue', 'Blue' ),
					array( 'green', 'Green' ),
					array( 'red', 'Red' ),
				),
			),
		),
	);
	return $forms;
} );
```

Full descriptor vocabulary: [for-plugin-authors.md](for-plugin-authors.md)
(`minn_admin_block_forms`, `minn_admin_insert_blocks`, island preview hooks).

## Design libraries and patterns

If your product is a **catalog of finished compositions** (not a freeform design canvas),
expose serialized markup rather than asking Minn to rebuild your React editor:

- Filter `minn_admin_design_sources` (list + fetch template by id), or
- Register block patterns Minn can list via core's pattern registry

Minn inserts top-level segments as islands. Writers then use text runs, image swaps, and
child inspectors where the markup allows. Spacing and layout controls stay in Gutenberg.

That path is for content kits. It is not an invitation to reimplement your full builder UI
in Minn.

## Success criteria

A content block is "done" for Minn when:

1. A writer can insert it from `/` or Browse without opening the block editor.
2. Changing the words (and content attrs) happens in Minn and round-trips cleanly.
3. Opening the same post in Gutenberg shows no invalid-block recovery.
4. Design-only changes, if any, are explicitly "Block editor ↗", not missing UI in Minn.

If (1) through (3) hold, you have extended the writing editor. If you need Minn to drag
columns and rebuild `save()` HTML, you are asking for the layout tool. Use Gutenberg for
that, and keep Minn for the writing.

## Related

| Doc | Role |
|---|---|
| [editor-direction.md](editor-direction.md) | Hybrid editor decision, islands, allowlists |
| [editor-roadmap.md](editor-roadmap.md) | Horizons + never-build list |
| [block-inspector.md](block-inspector.md) | How islands became configurable |
| [for-plugin-authors.md](for-plugin-authors.md) | Hook and descriptor reference |
| [block-suites.md](block-suites.md) | Lab notes on layout-oriented block plugins |
| [page-builders.md](page-builders.md) | Full-canvas builders (deep link, not content blocks) |
