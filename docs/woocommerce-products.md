# WooCommerce products: what the page can hold

Scope map for the product page at `/minn-admin/products/{id}`. Every row below
was checked against the live `wc/v3/products` OPTIONS schema on WooCommerce
11.0.1, not against the REST handbook, so "writable" means this build accepts it.

The reference for the page shape is the order page (`renderOrderPage`): one
shared body renderer feeding both a quick-view modal and a full page, with
`m.page` telling them apart.

## The shape of the page

Cards on a main column plus a 340px sidebar, the same grid the order page
uses, and for the same reason: this page runs to ten sections and a two-column
slab of bordered panels reads as one wall.

The split is Shopify's. The main column is what the product **is**: name and
summary, pictures, price, stock, size, attributes, variations. The sidebar is
what it is **filed under**: published or not, type, taxonomies, linked
products. The grid lives in the page's own stylesheet (`.minn-order-page
.minn-order-layout`), so the quick-view modal takes the identical markup and
stacks it. One body, two hosts, as on an order.

Each card carries a `data-pcard` name so the layout can be tested by where a
card is actually **drawn**, not by where it sits in the markup. A page can
carry every right class name and still paint as one slab if the CSS never
lands.

**The save bar** appears when something changes and offers Discard beside
Save. It rides a fingerprint of `buildProductPayload()` rather than a dirty
flag per control: that function already reads every field on screen, so what
it produces IS the answer to "did anything change", and it cannot drift from
what the save sends. Cancelling out of a picker leaves it down; a chip, a
dragged tile or a flipped switch raises it, and none of those fire an input
event, which is why the watcher also listens for clicks (deferred a tick, so
the model has already moved). Controls whose clicks never reach the body, the
media picker and the date picker, call `m.syncDirty()` themselves.

Discard rebuilds from a copy of the product taken at load. `m.full` is not
that copy: flipping the type harvests the form into it, on purpose.

The page therefore has no card wrapper around the body. An `overflow: hidden`
wrapper would become the sticky bar's containing block, and the bar would
stick to nothing.

## The wp-admin metabox, mapped

wp-admin puts all of this behind the Product data metabox's vertical tabs plus a
sidebar. Minn does not need the tabs: the page is cards in a grid, and a card
per group grows without a nav.

| wp-admin | REST field | Notes |
|---|---|---|
| Product type select | `type` | Writable. Switching type changes which cards make sense, so the page re-renders on change. |
| Virtual / Downloadable | `virtual`, `downloadable` | Writable. Downloadable reveals the files card. |
| General: Regular price | `regular_price` | Writable. `price` is readonly and derived. |
| General: Sale price | `sale_price` | Writable. |
| General: Schedule | `date_on_sale_from`, `date_on_sale_to` | Writable. Pair with `bindDatePicker`, not a native date input. |
| Inventory: SKU | `sku` | Writable. Duplicate SKUs are rejected by WC with a clear message. |
| Inventory: GTIN, UPC, EAN, ISBN | `global_unique_id` | Writable. One field for all four labels. |
| Inventory: Stock management | `manage_stock` | Writable. Store-wide off state is a store setting, so the card explains rather than lying. |
| Inventory: Quantity | `stock_quantity` | Writable, only meaningful with `manage_stock`. |
| Inventory: Stock status | `stock_status` | Writable, and derived by WC when `manage_stock` is on. |
| Inventory: Backorders | `backorders` | Writable: `no`, `notify`, `yes`. |
| Inventory: Low stock threshold | `low_stock_amount` | Writable, nullable. Empty means the store default. |
| Inventory: Sold individually | `sold_individually` | Writable. |
| Shipping: Weight | `weight` | Writable string. Unit comes from store settings. |
| Shipping: Dimensions | `dimensions` | Writable object: `length`, `width`, `height`. |
| Shipping: Shipping class | `shipping_class` | Writable by slug. `shipping_class_id` is readonly. Options come from `wc/v3/products/shipping_classes`. |
| Linked: Upsells | `upsell_ids` | Writable id array. Needs a product search picker. |
| Linked: Cross-sells | `cross_sell_ids` | Writable id array. |
| Linked: Grouped products | `grouped_products` | **Readonly in this build.** Grouped children stay a link-out until that changes. |
| Attributes | `attributes`, `default_attributes` | Writable. Global attributes reference `wc/v3/products/attributes`; custom ones are inline name plus options. |
| Advanced: Purchase note | `purchase_note` | Writable. |
| Advanced: Menu order | `menu_order` | Writable. |
| Advanced: Enable reviews | `reviews_allowed` | Writable. |
| Advanced: Available for POS | plugin meta | Not a core field. Reachable only through generic `meta_data`, which is a per-plugin treadmill. Stays a link-out. |
| Sidebar: Product image, gallery | `images` | Writable array, first entry is the featured image. Reuse the media picker and the island images editor's reorder grid. |
| Sidebar: Categories | `categories` | Writable id array. |
| Sidebar: Tags | `tags` | Writable id array. |
| Sidebar: Brands | `brands` | Writable id array. WooCommerce ships brands natively now, so this is not a plugin field. |
| Sidebar: Slug | `slug` | Writable. |
| Publish: Status, visibility, password | `status`, `catalog_visibility`, `post_password` | Writable. |
| Publish: Published on | `date_created` | Writable. |
| Publish: Featured | `featured` | Writable. |
| Tax status, tax class | `tax_status`, `tax_class` | Writable. Classes come from `wc/v3/taxes/classes`. |
| External product URL, button text | `external_url`, `button_text` | Writable, external type only. |
| Downloadable files, limit, expiry | `downloads`, `download_limit`, `download_expiry` | Writable. |
| Variations | not on the parent | `variations` is readonly here. Real editing is the `wc/v3/products/{id}/variations` sub-resource, which is full CRUD. |

## The long description

`product` registers with `editor`, `thumbnail`, `excerpt` and `autosave`
support, and `wp/v2/product/{id}?context=edit` answers 200. So the full
description already has a home in Minn's own editor at
`/minn-admin/editor/product/{id}`, with blocks, islands, autosave and revisions.

The page therefore keeps the short description inline (it is a plain summary
field) and links out to the editor for the long one. Embedding the editor body
inside the product page was considered and rejected: the editor owns a lock, an
autosave chain and a dirty model, and the product page owns a separate explicit
Save, so the two save models would collide over the same post.

## Build order

Each wave is a card or two plus its save-payload keys, and each is shippable on
its own.

1. **The page** (shipped): route, shared renderer, quick-view modal kept, parity
   with the old modal fields, editor link for the description.
2. **Inventory and shipping** (shipped): GTIN, backorders, low stock, sold
   individually, weight, dimensions, shipping class. This wave also settled the
   page's shape: four cards in a two-column grid named the way WooCommerce
   groups them (Basics, Pricing, Inventory, Shipping) plus a full-width card for
   the short description, so later waves add a card rather than a tab. Two rules
   fell out of it. A virtual product hides the Shipping card entirely, matching
   what WooCommerce does, and an empty low-stock box saves `null` rather than
   `0`, because `0` is a real threshold and `null` means "use the store
   default". Shipping classes load once per session through a deduped promise.
3. **Organization** (shipped): categories, tags, brands, slug, featured. Chips
   plus an async suggest per taxonomy, in the Terms manager's pattern. Tags and
   brands are flat, so Enter creates one that does not exist yet; categories are
   pick-only, because a typo would leave junk in a hierarchy this card is not
   editing. Brands render only when the store answers with a `brands` key, so a
   WooCommerce without brands shows nothing. Picks live on the detail model
   rather than in the DOM, and taxonomies save as the whole set because
   WooCommerce replaces rather than merges.
4. **Images** (shipped): featured plus gallery, reorder and replace. Reuses the
   island images editor's tile CSS, but inline in the card rather than in a
   modal: the page has the room, and it saves with the rest of the form instead
   of needing its own Apply. Order is the meaning here, since WooCommerce reads
   entry one as the product image and the rest as the gallery, so reordering is
   the primary verb (drag a tile, or use its arrows) and the first tile is
   badged. Tiles are the picker's thumbnails, not the full-size originals.
5. **Advanced and pricing extras** (shipped): sale schedule, tax status and
   class in Pricing; purchase note, menu order and reviews in a new Advanced
   card. Two WooCommerce behaviors to keep in mind. Clearing a sale date sends
   an empty string, NOT null: these are guarded with `isset()`, and
   `isset( null )` is false, so a null returns 200 and changes nothing. And
   `purchase_note` and `short_description` come back run through `wpautop`
   while being stored raw, so the markup is stripped on the way into the
   textarea or every save wraps the text in another paragraph.
6. **Type-conditional cards** (shipped): the type combobox, Virtual and
   Downloadable, a Downloads card and the external URL and button text.
   Changing any of the three repaints the page, because they decide which cards
   exist, and a repaint rebuilds the form from the model. So the form is read
   back into the model FIRST (`harvestProductForm`), or flipping a switch would
   throw away everything typed since the page opened. That is the same
   late-render wipe the shipping-class load caused in wave 2, except here the
   repaint is wanted and the harvest is what makes it safe.
   `buildProductPayload` was extracted for this: the save and the harvest read
   the form through one function rather than two that can drift.
   An empty download limit or expiry stores `-1`, which is how WooCommerce
   spells "no cap".
7. **Linked products** (shipped): upsells and cross-sells as chips plus a
   product search, the taxonomy shape again. These are stored as bare ids, so
   the names come from a second request that is awaited BEFORE the first paint
   rather than merged in when it lands. A product never offers itself in its
   own results.
8. **Attributes** (shipped): a row per attribute with its values, a Visible
   flag and a Variations flag. Custom attributes are typed; store-wide (`pa_*`)
   ones are picked from those that already exist and show their name as a label
   rather than a field, because the name belongs to the taxonomy.

   Creating a new store-wide attribute stays in WooCommerce, and not only on
   taste: `pa_*` taxonomies are registered on `init` from the stored
   definitions, so one created during a request cannot be assigned to a product
   in that same request. wc/v3 accepts the write with a 200 and silently drops
   the attribute; the identical call succeeds on the next request. Verified
   both ways against a live store.
9. **Variations** (shipped): a row per variation on a variable product, with
   its attribute values, SKU, regular and sale price and stock status, plus
   Generate from attributes (every combination the variation-enabled attributes
   allow, minus the ones already there).

   Variations are a sub-resource, so they load and save on their own, but they
   ride the page's single Save button: a second save button on one form is a
   way to lose work. The order matters. The product saves first, then the
   variations batch, because a variation's attributes have to exist on the
   parent before WooCommerce will accept them. Freshly created variations are
   re-read afterwards so a second save updates them rather than creating
   duplicates.

   Per-variation images and the variation-level shipping and tax fields are not
   here yet; the card covers what a shop owner changes rather than everything
   the resource holds.

Waves 2 through 5 cover what a shop owner touches weekly. Waves 8 and 9 are
where WooCommerce's own UI is a canvas, so they need their own scoping pass
before anyone starts.

## Two things the fields learned later

**The taxonomy fields open into a list.** Categories, tags and brands were
search-only, an empty box that answered nothing until you guessed a term that
exists. They now drop a list on focus with a tick box per row, ticked when the
product is in that term, and a row toggles without closing, so filing a
product in four categories is four clicks. Typing still searches the server: a
store can have hundreds of terms, and filtering the first page locally would
lie about what exists. The first page is cached per taxonomy so reopening is
instant. Add new is offered for the flat taxonomies only, for the same reason
Enter never created a category.

Every search says that it is searching, in the slot the chevron occupies so
nothing shifts when the answer lands. Requests carry a generation: a slow
answer to an earlier keystroke must neither repaint over a newer one nor stop
its spinner. The store-wide attribute picker has no spinner because it never
waits, its vocabulary is fetched before the first paint.

**The gallery takes several files at once**, and shows each one's name,
percentage and place in the batch while it uploads. That readout is the reason
`uploadMedia()` uses XMLHttpRequest: fetch cannot report request progress, so
a fetch upload can only say "working". Uploads run one after another, not in
parallel, because a dozen at once is how a shared host answers 503 and because
the order of the picks is the gallery's order.

**Video does not belong in the gallery, and this is WooCommerce's rule.**
`set_product_images()` rejects any attachment that is not an image with a 400
`woocommerce_product_invalid_image_id`, verified against a live store by
PUTting a `video/mp4` attachment id: the write fails outright, so a picker
that offered video would offer a save that cannot succeed. A product video
lives in the long description (Minn's editor takes a video block), in the
Downloads card for a downloadable product, or in whatever meta field a theme
reads, which is per-theme and not core.
