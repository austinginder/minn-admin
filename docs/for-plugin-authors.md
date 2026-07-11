# Adding your plugin to Minn Admin

Minn Admin renders third-party plugin data through **surfaces** — declarative descriptors
registered from PHP. One filter, no JavaScript, no build step. Minn draws your data with the same
list / tabs / detail-modal / action primitives that power its built-in views.

## Ship the adapter inside your own plugin

Minn's whole extension surface is a small set of public hooks. The main ones:

| Hook | Kind | Purpose |
|---|---|---|
| `minn_admin_surfaces` | filter | Sidebar views (lists, tabs, detail modals) |
| `minn_admin_editor_panels` | filter | Per-post fields in the editor sidebar |
| `minn_admin_traffic` | filter | Overview chart traffic provider |
| `minn_admin_block_forms` | filter | Block inspector labels/controls + slash insert templates |
| `minn_admin_insert_blocks` | filter | Prune or extend the auto-insert slash list |
| `minn_admin_page_builders` | filter | Register a full-canvas page builder |
| `minn_admin_design_sources` | filter | Register a design/template library for the slash menu + block picker |
| `minn_admin_before_render_blocks` | action | Register assets before island `do_blocks` |
| `minn_admin_render_styles` | filter | Extra CSS URLs / inline CSS for island previews |
| `minn_admin_rendered_html` | filter | Rewrite one island's rendered HTML (maps, fallbacks) |
| `minn_admin_template_footer` | action | End of Minn's app document (no `wp_head`/`wp_footer`) |
| `minn_admin_cache_purgers` | filter | Join the "Clear site cache" palette command |
| `minn_admin_spam_providers` | filter | Add a provider card to Settings → Spam |
| `minn_admin_license_providers` | filter | Report your license state on the System page's Licenses card, optionally with activate / deactivate / re-verify |
| `minn_admin_comments_enabled` | filter | Override comments detection (nav, palette, badge) |
| `minn_admin_visibility_providers` | filter | Report an active maintenance / coming-soon / password mode (Overview banner, topbar chip, System check) |

Minn deliberately never fires `wp_head`/`wp_footer` (its document stays clean), so developer
tooling that wants to render into the page attaches at `minn_admin_template_footer`; the
bundled Query Monitor adapter is the reference. The standardized way to integrate is to put
your `add_filter()` / `add_action()` calls in one file inside **your** plugin and require it
unconditionally:

```
my-plugin/
└── includes/minn-admin.php   ← all your minn_admin_* filters live here
```

No `class_exists( 'Minn_Admin' )` guard is needed: when Minn isn't installed the filters are
simply never applied, so the integration is a free no-op. Users who install both plugins get
the integration automatically — nothing to configure, no companion plugin to ship.

Minn bundles adapters (in `includes/adapters/`) only for popular plugins that don't know about
Minn — Gravity Forms, ACF, Redirection, the analytics providers. If you're the author of the
plugin being integrated, ship the adapter with it instead; [Anchor Blocks](https://github.com/anchorhost/anchor-blocks)
does exactly this in `app/MinnAdmin.php`.

## Compatibility

The hooks above, and the descriptor keys documented on this page, are Minn's integration
contract. The intent is that it changes rarely, and additively:

- New hooks and descriptor keys may appear in any release; documented keys keep their
  meaning.
- If a documented hook or key ever has to change, the old form keeps working for a
  deprecation window and the change is called out in the changelog.
- Keys you find in Minn's bundled adapters but not on this page are internal and may
  change without notice. If you need one, open an issue so it can be documented and
  stabilized here.

## Quick start

```php
add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
    $surfaces['my-plugin'] = array(
        'label'      => 'Submissions',          // sidebar + page title
        'sub'        => 'My Plugin',            // small badge next to the title
        'icon'       => 'inbox',                // one of Minn's icon names
        'cap'        => 'manage_options',       // checked server-side before the surface is exposed
        'collection' => array(
            'route'     => 'my-plugin/v1/submissions',   // any REST route, called with cookie + nonce
            'pageQuery' => 'per_page=25&page={page}',    // {page} is filled in by Minn
            'itemsKey'  => 'items',   // key of the item array in the response (omit if the response IS the array)
            'totalKey'  => 'total',   // key of the total count (omit to use the X-WP-Total header)
            'columns'   => array(
                array( 'key' => 'title',  'label' => 'Title',  'format' => 'title' ),
                array( 'key' => 'email',  'label' => 'Email' ),
                array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
                array( 'key' => 'date',   'label' => 'Date',   'format' => 'ago' ),
            ),
        ),
    );
    return $surfaces;
} );
```

That's a working, paginated, capability-gated view in the Minn sidebar.

**While you build, watch the Integrations card** on Minn's System page
(`/minn-admin/system`): it lists every registered surface, editor panel, design source,
cache purger, page builder and hook listener, attributes each to the plugin that
registered it, and flags contract problems (unknown keys, missing routes, columns
without keys). A descriptor the app quietly can't render explains itself there, and the
page's copy-report carries the section for bug reports.

## Descriptor reference

### Top level

| Key | Meaning |
|---|---|
| `label` | Sidebar label and page title |
| `sub` | Subtitle badge (usually your plugin name) |
| `icon` | Icon name from Minn's set: `inbox`, `send`, `doc`, `img`, `chat`, `cart`, `users`, `gear`, `plug`, `grid`, `list` |
| `cap` | Capability required. Checked server-side; the surface is absent from the app for users without it |
| `collection` | The list definition (below) |
| `family` | Group id for surfaces that do the same job (`forms`, `mail`, `redirects`, `activity-log`, `snippets`, `backups`, or your own). Same-family surfaces share one sidebar entry with a provider switcher in the topbar badge; the user's pick is remembered per family |
| `group` | Sidebar placement. Surfaces default to the **Tools** group (logs, redirects, snippets: site plumbing). Declare `"workspace"` only when the surface is inbox-shaped, something users check daily because new items need a human (form entries are the bundled example) |
| `manage` | Optional second collection (same shape as `collection`). Adds a view switcher above the list; each collection's `viewLabel` names its tab. Gravity Forms uses it for Entries / Forms |
| `status` | Optional status card above the list: `{ "route": "your/v1/status" }`. The route returns a server-built display model (below), so your adapter formats values server-side and the client stays generic |

### `status` — a card above the list

For surfaces where the list alone doesn't tell the story (Disembark's Backups
view is the reference), declare `status.route` and return:

```json
{
    "rows": [
        { "label": "Last scan", "value": "3 hours ago", "hint": "142,318 files · 2.1 GB" }
    ],
    "command": {
        "label": "Back up from any terminal",
        "text": "disembark connect https://example.com abc123…",
        "hint": "Requires the Disembark CLI."
    },
    "actions": [
        { "label": "Clean up working files", "route": "your/v1/cleanup", "method": "POST", "confirm": "Really?", "danger": true },
        { "label": "Open Disembark ↗", "href": "https://example.com/wp-admin/tools.php?page=disembark" }
    ]
}
```

`rows` render as a stat strip (send display-ready strings; format server-side).
`command` renders as a click-to-copy monospace box; omit it when there's
nothing to copy. `actions` render as buttons: with `route` they POST (or
`method`) and re-fetch both the status and the list on success, `confirm`
shows a native confirm first, `danger` styles the button red, and `href`
renders a plain new-tab link instead of a request. Omit any key you don't
need; conditional actions are just actions your route leaves out of the
response.

### `collection`

| Key | Meaning |
|---|---|
| `route` | REST route for the list. May contain `{tab}` (replaced with the active tab value) |
| `allRoute` | Route used for the "All" tab when `route` contains `{tab}` |
| `query` | Extra query string appended to every request (sorting etc.) |
| `pageQuery` | Pagination template, default `per_page=25&page={page}`. `{page}` is 1-based; use `{page0}` for zero-based APIs (Redirection). Use your API's own style, e.g. Gravity Forms' `paging[page_size]=25&paging[current_page]={page}` |
| `itemsKey` / `totalKey` | Where items/total live in the response body. Omit both for standard WP collections (plain array + `X-WP-Total` header) |
| `tabs` | Either `{ "route": "...", "valueKey": "id", "labelKey": "title" }` to build tabs from a REST call, or `{ "param": "status", "static": [["sent","Sent"],["failed","Failed"]] }` for fixed tabs sent as a query param. `allLabel` names the first tab |
| `viewLabel` | Names this collection in the view switcher (with `manage`) and in the search placeholder |
| `columns` | Array of `{ key, label, format, altKey, width, utc }`. `key` supports dot paths (`initiator_data.user_login`); `altKey` is a fallback key read when the primary is empty. Formats: `title`, `text` (default), `pill`, `ago`, `mono`, `num` (right-aligned numeric), `entry-summary` (first scalar values of numeric keys — useful for form entries). `width` overrides the column's grid width; defaults are sized by format. For `ago`, bare datetimes parse as site-local: set `utc: true` for UTC-stored timestamps (or use a key ending in `_gmt`, or emit a trailing `Z`) |
| `detail` | Detail modal config: `detailRoute` (fetch full item by `{id}`), `sectionsRoute` (server-built display model, an alternative to `detailRoute` + `labels`, below), `labels` (resolve field keys to human labels from another route), `messageKey` (render one field as a large text block — HTML messages render in a sandboxed iframe, plain text in a `<pre>`), `skip` (keys to hide), `edit` (inline editing, below) |
| `actions` | Buttons in the detail modal: `{ label, method, route, body, confirm, danger, when, href }`. `{id}` in the route is replaced with the item id. `when: { key, equals }` shows the button only when the item's field matches (Activate vs Deactivate). `href` renders the action as a plain link instead of a request; `{field}` placeholders are filled from the item |
| `search` | A query-string template with `{q}` (e.g. `filterBy[url]={q}` or `search={q}`). Adds a filter box to the toolbar; the term is debounced and appended to the list request. For APIs that take search criteria as a JSON string (Gravity Forms), use the object form: `array( 'param' => 'search', 'json' => <criteria array with '{q}' where the term goes> )` — the term is JSON-escaped and the criteria double-URL-encoded to match APIs that `urldecode()` the param themselves |
| `create` | Adds an "Add" button + form modal. `{ label, route, method, fields, defaults }` — `fields` are `{ key, label, mono, type, value, placeholder, rows, options }` (dot-path keys supported, e.g. `action_data.url`); `defaults` are merged under the typed values so fixed fields (group, match type) ride along. Field types: `text` (default), `number`, `textarea` (`rows` sets its height), `select` (`options` as `[value, label]` pairs), `tags` (comma-separated input, submitted as an array) |

### `detail.edit` — inline editing in the detail modal

Let users edit an item's fields in place and save through your plugin's own update endpoint:

```php
'detail' => array(
    'edit' => array(
        'route'    => 'redirection/v1/redirect/{id}',   // update endpoint; {id} replaced
        'method'   => 'POST',                            // default POST
        'preserve' => array( 'match_type', 'group_id' ), // untouched fields sent along so
                                                         // your sanitizer doesn't reset them
        'fields'   => array(
            array( 'key' => 'url', 'label' => 'Source URL', 'mono' => true ),
            array( 'key' => 'action_data.url', 'label' => 'Target URL', 'mono' => true ),
            array( 'key' => 'action_code', 'label' => 'HTTP status', 'type' => 'number' ),
        ),
    ),
),
```

Each field is `{ key, label, mono, type }` — `key` supports dot paths (`action_data.url`
reads and writes `{ "action_data": { "url": … } }`), `mono` renders a monospace input, and
`type: "number"` sends a numeric value. Fields shown as inputs are hidden from the static
detail rows automatically. The bundled Redirection adapter is the reference.

### `detail.sectionsRoute` — server-built detail view

Instead of `detailRoute` + `labels`, your endpoint can return the whole display model in
one response, with labels already resolved server-side. `sectionsRoute` (with `{id}`)
must return:

```json
{
    "kind": "entry",
    "sections": [
        { "title": "Response",   "rows": [ { "label": "Email", "value": "dana@example.com", "type": "email" } ] },
        { "title": "Submission", "rows": [ { "label": "Date",  "value": "2026-07-09 14:02" } ] }
    ],
    "adminUrl": "https://example.com/wp-admin/admin.php?page=…"
}
```

`kind` picks the layout: `"entry"` renders the contact-style form-entry view (name and
email hero, message body, quiet meta), `"activity"` renders the audit-event view (who,
event message, context chips). Omit it for a plain grouped key/value view. Surfaces in
the `forms` family default to `entry` and `activity-log` to `activity`, so those
usually don't need `kind` at all. A row's `type` hints rendering (`email` and `url`
values become links). `adminUrl` links the item's wp-admin screen and suppresses any
`href` action that points at the same place. The bundled Gravity Forms adapter
(entries) and WP Activity Log adapter (events) are the references.

## Block inspector forms — `minn_admin_block_forms`

Minn's editor renders complex blocks as read-only islands, and the **block inspector** (the ⚙
chip on every island) generates a config form from each block's registered attribute schema.
The schema goes a long way on its own: attributes with an `enum` render as selects, an
attribute named `content` (or holding long text) gets a textarea, and labels are humanized
from the key. What a schema can't express is the rest of the intent: a friendlier label than
the key name, human option labels, a guaranteed textarea. This filter layers that on:

```php
add_filter( 'minn_admin_block_forms', function ( $forms ) {
    $forms['my-plugin/testimonial'] = array(
        'order'      => array( 'author', 'quote', 'tone' ),   // field order
        'attributes' => array(
            'author' => array( 'label' => 'Author' ),
            'quote'  => array( 'label' => 'Quote', 'control' => 'textarea' ),
            'tone'   => array(
                'label'   => 'Tone',
                'control' => 'select',
                'options' => array( array( 'light', 'Light' ), array( 'dark', 'Dark' ) ),
            ),
            'legacy' => array( 'hide' => true ),              // keep out of the form
        ),
    );
    return $forms;
} );
```

Per attribute: `label`, `control` (`text` · `textarea` · `select` · `number` · `checkbox`),
`options` (`[value, label]` pairs, implies `select`), `hide`. Without a descriptor the
inspector falls back to schema-derived controls, so this is refinement, not requirement.
Attributes with a `source` (stored in saved HTML) are never form-edited.

Prefer expressing what you can in the attribute schema itself: declare `enum` on
fixed-choice attributes and the generic form renders a select with no descriptor at all
(and every other schema consumer benefits too). Reach for `options` only when the raw
values need human labels.

### Dynamic blocks insert automatically — no descriptor needed

If your block is **fully server-rendered** (a `render_callback` or `render` file does the
work and your JS `save()` is null), it is already insertable in Minn with zero adapter code.
For those blocks a self-closing comment is valid saved markup, so Minn auto-registers every
dynamic, top-level, inserter-visible non-core block that **renders output from a bare
comment** as a search-only slash-menu entry: it doesn't clutter the default menu, but typing
part of its title (or its namespace, so `/my-plugin` lists everything you ship) surfaces it.
Insertion drops `<!-- wp:your/block /-->` as an island, renders the real preview, and opens
the schema-driven inspector.

The render probe is the honesty gate. `is_dynamic` alone doesn't mean a bare comment is
valid — hybrid blocks (a render_callback **plus** a JS `save()` that emits wrapper HTML, or
a render that only processes saved inner blocks) render nothing standalone and would fail
Gutenberg's block validation if Minn inserted them, so they are excluded. If your block
renders empty without attributes, give it sensible defaults or ship an `insert.template`.

To make the most of the auto-insert, register your blocks well server-side:

- **`title` in PHP registration** (or `block.json`) — without it Minn falls back to a
  humanized slug ("report-card" → "Report Card").
- **`parent` / `ancestor` for child blocks, declared server-side** — declared children are
  excluded from top-level insertion, exactly like Gutenberg's inserter. A `parent` that
  only exists in your editor JS is invisible to Minn (and to every other server-side
  consumer), so your child blocks would show up as standalone inserts.
- **`supports.inserter = false`** hides a block entirely.
- **Attribute schemas in PHP** — they are what the inspector's generated form is built
  from; `enum` attributes become selects, defaults pre-fill.

Static-save blocks (HTML produced by your editor JS `save()`) never auto-insert; only your
JS can produce their markup. Give those an explicit `insert.template` below.

To suppress an auto entry without providing a template, set `'insert' => false` in the
block's descriptor. Sites can also prune or extend the whole list via the
`minn_admin_insert_blocks` filter.

### `insert` — offer the block in the editor's `/` menu

Declare starting markup and your block appears in Minn's slash menu (an explicit template
always supersedes the auto entry). It's inserted as a configurable island — real
server-rendered preview, inspector opened immediately:

```php
$forms['my-plugin/testimonial'] = array(
    'insert' => array(
        'label'    => 'Testimonial',                    // slash-menu entry
        'icon'     => '❖',                              // optional, one glyph
        'template' => '<!-- wp:my-plugin/testimonial {"author":"…"} /-->',
    ),
    // …attribute refinements as above
);
```

`template` is full raw block markup — you know your block's canonical shape (wrapper HTML,
starter children, default attrs); Minn inserts it verbatim. Only declare `insert` on blocks
that make sense at the top level (parents and standalone blocks, not children).

### `wrapperText` — editable text in an InnerBlocks wrapper

Static InnerBlocks parents often bake a heading into their saved wrapper HTML (e.g. a
conversation block's header). Declare it editable with a regex of **exactly three capture
groups** — `(prefix)(text)(suffix)`:

```php
$forms['my-plugin/panel'] = array(
    'wrapperText' => array(
        array( 'label' => 'Heading', 'pattern' => '(<div class="panel-head">)([^<]*)(</div>)' ),
    ),
);
```

The text is replaced in place only when it actually changed — an untouched wrapper stays
byte-identical. Patterns that don't match simply don't render a field, and a generic
text-run field never doubles a matched pattern (the labeled field wins). Note that Minn's
generic text runs already make wrapper text editable with no descriptor; `wrapperText` is
worth declaring when you want a labeled, single-purpose field instead of a generic "Text"
run. For a real-world reference of a block plugin shipping its own descriptors,
[Anchor Blocks](https://github.com/anchorhost/anchor-blocks) registers insert templates
and semantic labels from its own plugin (`app/MinnAdmin.php`) — the filter is a no-op when
Minn isn't installed, so block plugins can ship it unconditionally.

## Island previews — free path first, adapter only when stuck

Complex blocks land in Minn as **islands**: the saved markup is preserved byte-for-byte,
and the ⚙ chip opens the inspector. Previews are not a second editor canvas. Minn POSTs the
raw markup to `minn-admin/v1/render-blocks`, runs `do_blocks()` server-side, diffs the style
queue, scopes any new CSS into `.minn-island-preview`, and sets the result with
`innerHTML`. That design is intentional: islands stay safe to serialize, never reimplement
your React UI, and still look like the front end when assets show up.

**Design for that path and most blocks need zero Minn-specific code.** When something still
looks empty, giant, or unstyled, the checklist below is the same one Minn's own adapters
follow.

### Free path (no adapter): make `do_blocks` honest

1. **Server-render meaningful HTML.** A bare `<!-- wp:your/block /-->` (or with default
   attrs) should produce visible markup. If output is empty without attrs, set attribute
   defaults in `block.json` / PHP registration, or ship an `insert.template` with starter
   markup.
2. **Register front-end styles at `init`, not only when `has_block()` finds them on a
   singular post.** Prefer `block.json` `"style": "file:./style.css"` so core registers the
   handle when the block is registered. If you use a named handle (`"style": "my-block-style"`),
   call `wp_register_style()` in the same place you `register_block_type()`. Minn (and any
   other headless `do_blocks` consumer) will then auto-enqueue on render.
3. **Do not gate styles on `is_admin()` or editor-only hooks** for the front-end stylesheet.
   Editor styles (`editorStyle`) stay editor-only; the **style** handle is what previews and
   the public site share.
4. **Size icons and SVGs in CSS** (`max-width` / `width` / `height` on the SVG or a wrapper
   class). Unconstrained SVGs look like "broken giant icons" in any CSS-less context, not
   only Minn.
5. **Prefer static or server HTML for maps / charts / carousels when possible**, or accept
   that a pure client-side shell will look empty in previews until you add a fallback
   (below).
6. **Put text and image URLs in saved HTML (or attrs that mirror that HTML).** Minn can
   already edit generic text runs and swap images inside islands without per-block code
   when the content lives in the markup.

What Minn already does for free (no adapter):

| Behavior | Mechanism |
|---|---|
| Dynamic block slash-insert | Render probe on bare comment; search-only entries |
| Schema inspector | Attr schema from `register_block_type` / `block.json` |
| Live preview HTML | `do_blocks` via `render-blocks` |
| Styles enqueued during render | Style-queue diff after `do_blocks` |
| Site + block library CSS | `editor-styles` + client scoper on `.minn-island-preview` |
| Text / image edit in islands | Generic text runs + image URL swap |
| Design libraries / patterns | Registered block patterns are automatic; libraries via `minn_admin_design_sources` (below) |

### When the free path fails: diagnosis → drop-in adapter

| Symptom in Minn | Likely cause | Fix without Minn code | Drop-in adapter if you can't change that |
|---|---|---|---|
| Empty island, full in block editor | Hybrid: JS `save()` owns markup; bare comment renders nothing | Fully server-render, or ship `insert.template` with real save markup | `minn_admin_block_forms` → `insert.template` |
| Content present but huge SVGs / broken layout | Front-end CSS never registered on REST render | Register `style` at `init` (`file:./style.css` or `wp_register_style`) | `minn_admin_before_render_blocks` + `minn_admin_render_styles` |
| Looks right on front end after a visit, empty in Minn | CSS only exists as postmeta / browser-compiled cache | Emit CSS from `do_blocks` or enqueue a real stylesheet | `minn_admin_render_styles` (postmeta / warm URL); see Otter |
| Empty map / slider / Lottie shell | Server outputs a div + script; `innerHTML` never runs scripts | Server-render a static fallback (image, iframe, noscript) | `minn_admin_rendered_html` swap to iframe/static |
| Insert works, inspector empty | No attr schema server-side | Register attributes in PHP / `block.json` | `minn_admin_block_forms` labels only refine schema |
| Static-save design library | Only editor JS can author full designs | Publish serialized templates (JSON/CDN) Minn can fetch | Design source (`minn_admin_design_sources`, below) |

### Preview hooks (copy into `includes/minn-admin.php`)

All of these no-op when Minn is not installed.

**1. Register styles before render** (named handles that core would not see otherwise):

```php
add_action( 'minn_admin_before_render_blocks', function ( $blocks, $post_id ) {
	// $blocks is an array of raw markup strings for the islands being previewed.
	if ( ! minn_admin_markup_has( $blocks, 'my-plugin/' ) ) {
		return;
	}
	wp_register_style(
		'my-block-style',
		plugins_url( 'build/style.css', MY_PLUGIN_FILE ),
		array(),
		MY_PLUGIN_VERSION
	);
}, 10, 2 );

function minn_admin_markup_has( $blocks, $needle ) {
	foreach ( (array) $blocks as $raw ) {
		if ( is_string( $raw ) && false !== strpos( $raw, $needle ) ) {
			return true;
		}
	}
	return false;
}
```

Once the handle is registered, Minn's `do_blocks` + queue-diff path enqueues it the same way
the front end would.

**2. Hand CSS URLs or inline CSS directly** (postmeta caches, CSS embedded in attrs, etc.):

```php
add_filter( 'minn_admin_render_styles', function ( $styles, $blocks, $post_id ) {
	// $styles = [ 'urls' => string[], 'inline' => string, optional 'warm' => url ]
	$styles['urls'][] = plugins_url( 'build/style.css', MY_PLUGIN_FILE );
	if ( $post_id ) {
		$cached = get_post_meta( $post_id, '_my_plugin_generated_css', true );
		if ( is_string( $cached ) && $cached !== '' ) {
			$styles['inline'] .= "\n" . $cached;
		}
	}
	return $styles;
}, 10, 3 );
```

Optional `'warm' => $front_end_url`: Minn loads that URL in a hidden iframe once so a
browser-only compiler can fill a cache, then re-fetches styles (Otter atomic-wind).

**3. Rewrite HTML for JS-only shells** (maps, widgets that need a script runner):

```php
add_filter( 'minn_admin_rendered_html', function ( $html, $raw, $post_id ) {
	if ( false === strpos( $raw, 'my-plugin/map' ) ) {
		return $html;
	}
	// Parse attrs from $raw or scrape them from $html; return a static preview.
	return '<iframe src="…" style="width:100%;height:400px;border:0" loading="lazy" title="Map"></iframe>';
}, 10, 3 );
```

Prefer fixing the free path in your plugin when you can. Adapters are for constraints you
cannot change (third-party hosting of CSS, JIT compilers, legacy registration order).

### Reference adapters in Minn (read these first)

| Adapter | Teaches |
|---|---|
| `includes/adapters/otter.php` | Lazy named styles + postmeta CSS + map HTML fallback + warm URL |
| `includes/adapters/essential-blocks.php` | CSS carried inside submitted block attrs |
| `includes/adapters/stackable.php` | Design-library insert when static-save blocks cannot auto-insert |
| `includes/adapters/kadence.php` / `generateblocks.php` | Same library pattern over the plugin's own REST/cache |
| Anchor Blocks `app/MinnAdmin.php` (external) | `minn_admin_block_forms` owned by the block plugin itself |

Internal lab notes (CSS models, hybrid traps): `docs/block-suites.md`.

## Design libraries — `minn_admin_design_sources`

If your plugin ships a template library (whole sections of serialized block markup users
insert as a unit), register it as a **design source**. Designs appear as search-only
entries in the editor's slash menu and as a labeled group in the block picker (⌘/):

```php
add_filter( 'minn_admin_design_sources', function ( $sources ) {
    $sources['my-plugin'] = array(
        'label' => 'My Library',                 // block-picker group name
        'route' => 'my-plugin/v1/minn-designs',  // implements the pair contract below
    );
    return $sources;
} );
```

The route implements a two-endpoint contract:

- `GET {route}` returns the slim list:
  `{ "designs": [ { "id": "hero-1", "label": "Hero 1", "category": "Heroes" } ] }`
  (`category` is optional, shown as the entry's meta). Minn fetches it lazily on the
  first slash-menu open and caches it for the session, so keep it fast and slim.
- `POST {route}/{id}` returns the insert payload:
  `{ "template": "<!-- wp:… -->…", "block": "my-plugin/hero" }`. `template` is full
  serialized block markup (your canonical save output, Gutenberg-valid by construction;
  a single top-level block inserts as one island), and the optional `block` names the
  island's chip.

Serve content the site can actually use (free tier only if your library is gated), and
localize remote images at insert time: when Minn is installed,
`minn_admin_localize_images( $template )` (see `includes/adapters/media-localize.php`)
sideloads them into the media library and rewrites the URLs, deduping by file name.
Guard the call with `function_exists` since your route is registered even when Minn
isn't. Give the routes a `permission_callback` (`edit_posts` matches the editor). The
bundled Stackable, Kadence and GenerateBlocks adapters register through this same
filter and are the references.

## Editor panels — per-post fields in the editor sidebar

For plugins whose data lives *inside the post* (custom fields, SEO meta), register an **editor
panel** instead of a surface. Same philosophy: a declarative descriptor, rendered by Minn.

```php
add_filter( 'minn_admin_editor_panels', function ( $panels ) {
    $panels['my-fields'] = array(
        'label'       => 'My fields',
        'sub'         => 'My Plugin',
        'cap'         => 'edit_posts',
        // Returns { groups: [ { group, fields: [ {name,label,type,choices,min,max} ], locked } ] }
        // for the post being edited. {id} = post ID (0 for new), {type} = REST base.
        'fieldsRoute' => 'my-plugin/v1/fields?post_id={id}&post_type={type}',
        'valuesKey'   => 'myplugin',   // key on the wp/v2 post response holding current values
        'writeKey'    => 'myplugin',   // key Minn writes changed values back under on save
    );
    return $panels;
} );
```

Supported field types: `text`, `textarea`, `number`, `range`, `email`, `url`, `select`, `radio`,
`true_false`. Report anything else in the `locked` count — Minn shows "N advanced fields — edit
in wp-admin ↗" rather than rendering something unsafe. Values ride the normal post save
(autosave included), so your plugin only needs its values readable/writable on the post REST
response (`register_rest_field` or, for ACF, the field group's "Show in REST API" toggle).

The bundled ACF adapter (`includes/adapters/acf.php`) is the reference implementation.

## Traffic providers — power the Overview chart

Analytics plugins can replace the Overview "Activity" chart with real traffic by answering the
`minn_admin_traffic` filter. Return daily visitor/pageview counts for the requested range plus
the previous period's visitor total (used for the delta on the Visitors stat card):

```php
add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
    if ( null !== $traffic ) {
        return $traffic; // another provider already answered
    }
    return array(
        'source'        => 'My Analytics',
        'days'          => array( // 'Y-m-d' => counts, covering the last $days days
            '2026-07-02' => array( 'visitors' => 120, 'pageviews' => 310 ),
            '2026-07-03' => array( 'visitors' => 141, 'pageviews' => 355 ),
        ),
        'prev_visitors' => 2210,  // visitor total for the $days before that
    );
}, 10, 2 );
```

Minn buckets the days to match the selected range (daily up to 45 days, weekly beyond), renders
the Traffic chart with your plugin's name as the source badge, and leads the stat cards with
Visitors and a period-over-period delta. Bundled adapters cover **Koko Analytics** (the
reference implementation), **WP Statistics**, **Burst Statistics** and **Independent
Analytics** — the first active provider answers, so a plugin registering its own adapter
should return early when `$traffic` is already non-null.

## Cache purgers — join "Clear site cache"

Minn's ⌘K palette has a "Clear site cache" command that purges every detected cache
layer, one request per provider. Caching and optimization plugins register a purger via
the `minn_admin_cache_purgers` filter:

```php
add_filter( 'minn_admin_cache_purgers', function ( $purgers ) {
    $purgers[] = array(
        'id'    => 'my-cache',   // stable slug
        'name'  => 'My Cache',   // shown in the palette entry and the result toast
        'purge' => function () {
            my_cache_flush_everything();
        },
    );
    return $purgers;
} );
```

The command is only exposed to users with `manage_options`, and your `purge` callback
runs server-side on that request; no extra capability check is needed for the common
case. A dozen providers ship bundled in `includes/adapters/cache-purge.php` (LiteSpeed,
WP Rocket, W3 Total Cache, …); copy any of them.

## Spam providers — Settings → Spam

Spam filtering plugins get a card on Minn's **Settings → Spam** page: configured state,
all-time blocked count, a few safe toggles written through your own option shape, and a
link to your full wp-admin screen. Register via the `minn_admin_spam_providers` filter:

```php
add_filter( 'minn_admin_spam_providers', function ( $providers ) {
    if ( ! defined( 'MY_SPAM_PLUGIN_VERSION' ) ) {
        return $providers; // register only while your plugin is active
    }
    $providers[] = array(
        'id'     => 'my-spam',
        'name'   => 'My Spam Filter',
        'status' => function () {
            return array(
                'configured' => (bool) get_option( 'my_spam_key' ),
                'note'       => 'One-line status shown under the name',
                'blocked'    => (int) get_option( 'my_spam_blocked_total', 0 ),
                'toggles'    => array(
                    array(
                        'id'    => 'notify',
                        'label' => 'Email me about new spam',
                        'desc'  => 'What the switch does, in one sentence.',
                        'on'    => (bool) get_option( 'my_spam_notify' ),
                    ),
                ),
                'adminUrl'   => admin_url( 'options-general.php?page=my-spam' ),
            );
        },
        'set'    => function ( $toggle_id, $on ) {
            if ( 'notify' === $toggle_id ) {
                update_option( 'my_spam_notify', $on ? 1 : 0 );
            }
        },
    );
    return $providers;
} );
```

Toggles save through `minn-admin/v1/spam` (gated on `manage_options`); `set` runs
server-side with the toggle id and the new boolean. Keep toggles to the two or three
switches a site owner actually flips; deep configuration belongs on your own screen,
which the card links to. Bundled providers (Akismet, Antispam Bee, CleanTalk) live in
`includes/adapters/spam.php` and are the references.

## License state — `minn_admin_license_providers`

The System page carries a read-only **Licenses** card: every paid component on the
site with its license state (valid / expired / invalid / missing / unknown), read
from stored options only. Minn never calls a licensing API and never activates
anything; if your plugin is commercial, a provider makes your license state visible
where the site owner already looks for site health:

```php
add_filter( 'minn_admin_license_providers', function ( $providers ) {
    $providers['my-plugin'] = array(
        'name'   => 'My Plugin Pro',
        'detect' => function () {
            return defined( 'MY_PLUGIN_VERSION' ); // installed, even if inactive
        },
        'read'   => function () {
            $status = get_option( 'my_plugin_license_status' );
            return array( array(
                'name'    => 'My Plugin Pro',
                'kind'    => 'plugin',           // or 'theme'
                'state'   => $status ? $status : 'missing',
                'key'     => (bool) get_option( 'my_plugin_license_key' ),
                'expires' => '2027-01-01',       // or 'lifetime' or ''
                'note'    => '',                 // optional one-line detail
                'stale'   => false,              // true when your cached status lapsed
            ) );
        },
    );
    return $providers;
} );
```

`read` may return several rows (one per product) and runs inside a Throwable guard,
so a broken provider drops out rather than breaking the card. Keep `read` strictly
local: option reads only, no HTTP. States roll into a Licenses health check (expired
or invalid fails it, missing or unknown warns) and the copy-as-markdown report.
Bundled readers, including generic Freemius and EDD Software Licensing coverage,
live in `includes/adapters/licenses.php`; the endpoint is
`GET minn-admin/v1/licenses` (gated on `manage_options`).

### Actions: activate, deactivate, re-verify (optional)

A provider may also declare `secret_label` plus any of three action callables, and
the card grows the matching controls (paste-to-activate field, Deactivate with a
confirm, Re-verify):

```php
'secret_label' => 'My Plugin license key',
'activate'     => function ( $secret ) {
    $res = my_plugin_activate_license( $secret ); // YOUR activation code
    return array(
        'ok'      => $res->ok,
        'code'    => $res->seats_exhausted ? 'site_limit' : ( $res->ok ? '' : 'invalid' ),
        'message' => $res->message,
    );
},
'deactivate'   => function () { /* your deactivation */ return array( 'ok' => true ); },
'verify'       => function () { /* re-check stored credentials */ return array( 'ok' => true ); },
```

Rules that make this safe, enforced by how Minn calls you and expected of your
callables: actions run only when your plugin's own code is loaded (attach them
conditionally, exactly like the bundled Elementor Pro adapter), the pasted secret
rides one request and is never stored, logged or echoed back by Minn, a failed
activation is never retried automatically (a retry can burn a paid seat), and
`site_limit` is a first-class result code so a seat problem is named instead of
buried in a generic error. Actions run through
`POST minn-admin/v1/licenses/action` (`{provider, action, secret?}`, gated on
`manage_options`); a returned `WP_Error` or a thrown exception surfaces as a plain
error result. After any action the fresh classification rides back in the same
response, so the card repaints from your stored state.

If activation happens through your own portal or an OAuth-style handshake (no
callable path for a pasted key), declare `activate_url` (a URL string or a
callable returning one) instead of `activate`: unlicensed rows then carry an
"Activate ↗" link to your screen. The bundled WPBakery entry is the reference.

If activation needs more than one credential, declare `secret_fields` and
`activate` receives an id-keyed array instead of a string (every field
required); the card renders one labeled field per secret. The bundled Divi
adapter (Elegant Themes username + API key) is the reference:

```php
'secret_fields' => array(
    array( 'id' => 'username', 'label' => 'Account username' ),
    array( 'id' => 'api_key', 'label' => 'API key' ),
),
'activate'      => function ( $secrets ) { /* $secrets['username'], $secrets['api_key'] */ },
```

## Comments detection — `minn_admin_comments_enabled`

Minn hides its Comments view, palette commands and badge when commenting is effectively
off (no post type supports comments and none exist). Plugins that manage commenting can
override the detection:

```php
add_filter( 'minn_admin_comments_enabled', function ( $enabled, $types ) {
    // $types = post types that still support comments.
    return false; // force-hide (or true to force-show)
}, 10, 2 );
```

## Site visibility — `minn_admin_visibility_providers`

When your plugin is hiding the site (a maintenance mode, coming-soon page or whole-site
password), tell Minn so the owner sees the persistent "Site hidden" chip, the Overview
banner and the System health check instead of wondering where their traffic went. Only
register while the mode is actually ACTIVE — the filter runs on every Minn pageload:

```php
add_filter( 'minn_admin_visibility_providers', function ( $providers ) {
    if ( my_plugin_maintenance_is_on() ) {
        $providers[] = array(
            'name'    => 'My Maintenance Mode',
            'kind'    => 'maintenance', // or 'coming-soon' | 'password'
            'note'    => 'Visitors see the holding page',          // optional
            'url'     => admin_url( 'admin.php?page=my-settings' ), // where to turn it off
            'partial' => false, // true when only SOME pages are covered
                                // (WooCommerce's store-pages-only shape) —
                                // Minn then says "part of the site" instead
                                // of claiming the whole site is dark
        );
    }
    return $providers;
} );
```

Minn links out to `url` to fix third-party modes; it never toggles another plugin's
option. Bundled detectors cover WP Maintenance Mode, SeedProd, Under Construction,
Password Protected, WooCommerce coming soon and Elementor maintenance mode.

## No REST API? Ship a shim

If your data lives in custom tables, register a small read-only REST collection and point the
descriptor at it. Minn's bundled Gravity SMTP adapter
(`includes/adapters/gravity-smtp.php`) is the reference implementation — ~60 lines of SQL-to-REST
plus a descriptor. Rules of the road: check capabilities in `permission_callback`, use
`$wpdb->prepare`, and never `unserialize()` stored blobs (extract what you need with regex or
`json_decode`).

## Notes

- All requests are same-origin with the logged-in user's cookie + `X-WP-Nonce` — your existing
  REST permission checks keep working.
- Escape nothing yourself — Minn escapes every value it renders.
- Bundled adapters live in `includes/adapters/` and are guarded by `class_exists`/`defined`
  checks; PRs adding adapters for widely-used plugins are welcome. Prefer shipping the
  adapter **inside your plugin** when you own the product; Minn bundles only for plugins
  that will never know about it.
