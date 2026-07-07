# Block-suite lab findings — blocks.localhost sweep (July 6, 2026)

Lab site: `blocks.localhost` (Cove), minn-admin symlinked, five suites installed and
active: **Kadence Blocks 3.7.8, Spectra 2.19.29, GenerateBlocks 2.3.0, Otter 3.2.0,
Essential Blocks 6.3.0**. Stackable findings (the reference integration) live in
[block-inspector.md](block-inspector.md) and `adapters/stackable.php`.

## What already works with zero adapter code

Verified against a real Otter pattern page: islands preserve `atomic-wind`/suite markup
byte-identically through double saves, previews server-render, text runs are editable,
and the core editor suites pass untouched on the lab site (markdown 20/20 with all five
suites active). The generic machinery — islands, render probe, text runs, image swaps,
lazy-CSS pickup — needed nothing suite-specific.

## Registration models (server's-eye view)

| Suite | Blocks | `is_dynamic` | Real server attrs | Render-probe pass |
|---|---|---|---|---|
| Essential Blocks | 75 | 75 | 75 | 6 |
| Kadence | 59 | 59 | 59 | 0 |
| GenerateBlocks | 16 | 16 | 16 | 0 |
| Spectra (uagb) | 13 | 12 | 13 | 9 |
| Otter (themeisle-blocks) | 40 | 11 | 40 | 6 |

The headline: `is_dynamic` is nearly meaningless as a "server-rendered" signal in this
ecosystem. Kadence and GenerateBlocks register every block with a render_callback that
exists to *generate CSS or decorate saved content*, not to produce markup — a bare
comment renders empty. Essential Blocks' ~40 "static" blocks have callbacks that just
return `$content`. The render probe is the only honest gate, and it holds: everything it
passes (post grids, FAQs, maps, feeds, breadcrumbs) genuinely works from a bare comment.

Unlike Stackable, all five register **real attribute schemas server-side**, so the
generic inspector has material to work with. But Spectra's schemas are enormous
(uagb/post-grid: 315 attributes, 313 form-renderable) — the generic form needs a scaling
strategy before that's usable (see roadmap).

## Template/pattern libraries — where the markup data lives

| Suite | Source | Markup as data? | Account? |
|---|---|---|---|
| Otter | **61 local PHP pattern files**, registered in `WP_Block_Patterns_Registry` | Yes, on disk | No |
| Essential Blocks | **12 local JSON pattern files** (`patterns/*.json`), registered as patterns | Yes, on disk | No (Templately packs are gated + not wired) |
| Kadence | Cloud (`patterns.startertemplatecloud.com`) via its own `kb-design-library/v1` REST proxy; file-cached in uploads | Yes — `get_pattern_content` returns serialized markup; free sections served with an empty api_key | No for free tier |
| Spectra | Cloud (`websitedemos.net` REST) via its ajax proxy; catalog synced to JSON files in uploads | Yes — template content endpoint returns markup | No for blocks/pages |
| GenerateBlocks | Cloud (`patterns.generatepress.com`) with a **public key hardcoded in the plugin**; own `generateblocks/v1` REST proxy, transient-cached | Yes | No |

Every one of the five publishes its design markup as fetchable data. The Stackable
adapter shape (slim list endpoint + insert-ready template endpoint with image sideload)
transfers directly; and for Otter + Essential Blocks not even that is needed — their
patterns are already in the core pattern registry.

## CSS models — why previews can render unstyled

Confirmed empirically: `uagb/faq` and `essential-blocks/post-grid` enqueue **nothing**
during a bare `do_blocks()` render. Each suite generates instance CSS from attributes
and emits it through front-end-only machinery:

- **GenerateBlocks**: per-block `css` attribute; render_block path prepends inline
  `<style>` only when `did_action('wp_head')` or the **`generateblocks_do_inline_styles`
  filter** is true. One-line shim available.
- **Kadence**: per-block base stylesheets registered lazily at render (the queue-diff in
  render-blocks catches those), plus instance CSS prepended inline by `render_css()` —
  but suppressed on block themes, where it goes through a `wp_enqueue_scripts` head pass
  that needs a real `$post`.
- **Otter**: CSS generated on save into postmeta `_themeisle_gutenberg_block_styles` +
  an uploads file; emitted only on `wp`/`wp_head`/`wp_footer` with `is_singular()`.
- **Spectra**: per-post CSS built by `UAGB_Post_Assets` on `wp_enqueue_scripts`
  (inline by default; optional file mode in `uploads/uag-plugin/`).
- **Essential Blocks**: CSS baked into each block's `blockMeta` attribute in the saved
  markup; materialized to `uploads/eb-style/eb-style-{post}.min.css` on save and
  enqueued with a real `$post`.

The render-blocks **style-queue diff** (shipped this cycle) covers the lazy-register
class (Stackable, Kadence base styles). The per-post-generated class needs per-suite
shims — candidates ranked in the roadmap.

## Image attribute conventions (for `swapIslandImage` coverage)

- Flat `XxxUrl`/`XxxId` pairs (already handled): Essential Blocks (`imageUrl`/`imageId`,
  sometimes `imageURL`).
- **`bgImg` ↔ `bgImgID`** (Kadence rows/sections; also `overlayBgImg`, responsive
  variants in `tabletBackground`/`mobileBackground` arrays): key + `ID` pairing not yet
  handled.
- **Media objects `{ url, id, alt }`** in one attribute (Spectra `image`,
  `backgroundImage`, `mediaGallery[]`; Otter `image`, `backgroundImage`; EB
  `image`, `sources[]`): URL swap works (string replace), id retarget needs a
  same-object heuristic.
- **GenerateBlocks**: no URL attribute at all — `mediaId` + `htmlAttributes.src` +
  `data-media-id` on the `img`. URL swap works; id lives in `mediaId`/`data-media-id`.
- **Otter slider**: `img[data-id]` carries the attachment id.
- JSON-escaped `https:\/\/` URL forms occur in server-authored markup — handled as of
  this sweep (Kadence's own importer does the same normalization, which validates the
  string-surgery approach wholesale).

## Roadmap (ranked)

1. ~~**Server-registered patterns in the slash menu**~~ — **SHIPPED** (same day):
   `minn-admin/v1/patterns` (slim list; blockTypes/templateTypes-contextual patterns
   excluded, postTypes restrictions client-filtered) + `minn-admin/v1/pattern?name=`
   (content; query arg because names contain slashes). Multi-root patterns insert as
   one island per top-level block via `insertPatternIslands()`; on reload the load
   pipeline upgrades simple blocks to editable prose. Lab site surfaces 101 patterns
   (Otter 61 + theme 40); suite: tests/patterns.test.js.
2. ~~**Preview CSS shims**~~ — **SHIPPED** (same day) for three of four: render-blocks
   flips `generateblocks_do_inline_styles` (GB blocks inline their own `<style>`),
   `adapters/essential-blocks.php` extracts `blockMeta` desktop CSS from the submitted
   markup, and `adapters/otter.php` recovers the per-post CSS caches
   (`_themeisle_gutenberg_block_styles` + `_atomic_wind_css` — atomic-wind is Tailwind
   compiled in the browser on first front-end view, so a never-viewed section has no
   cache until someone views the page once). Plumbing: render-blocks accepts a `post`
   param and applies the `minn_admin_render_styles` filter; editor-styles also carries
   handles the fired hooks enqueued directly (atomic-wind's base CSS). The client
   scoper now unwraps `@layer` (compiled Tailwind) and preserves CSS nesting.
   REMAINING: Spectra generates per-post CSS at request time with no persistent store —
   needs its `UAGB_Post_Assets` generator run over the edited post; investigate before
   committing.
3. **Library adapters** on the Stackable shape: Kadence sections (free tier, empty
   api_key), Spectra templates, GenerateBlocks patterns (its own `generateblocks/v1`
   proxy is already `edit_posts`-gated and transient-cached — Minn's client might call
   it directly).
4. **Inspector form scaling** for huge schemas (Spectra's 300+ attrs): collapse to a
   curated first page (attrs explicitly set on the block first, then a "More settings"
   expander or filter box).
5. **Image-swap heuristic widening**: key+`ID` pairing (Kadence), same-object
   `{url, id}` retarget (Spectra/Otter/EB), `data-media-id`/`data-id` img attributes
   (GenerateBlocks/Otter).

## Lab housekeeping

Fixture: page 5 ("Otter pattern: CTA Banner"). The lab is a local Cove site;
`cove delete blocks` when it has served its purpose.
