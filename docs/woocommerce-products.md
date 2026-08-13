# WooCommerce products: what the page can hold

Scope map for the product page at `/minn-admin/products/{id}`. Every row below
was checked against the live `wc/v3/products` OPTIONS schema on WooCommerce
11.0.1, not against the REST handbook, so "writable" means this build accepts it.

The reference for the page shape is the order page (`renderOrderPage`): one
shared body renderer feeding both a quick-view modal and a full page, with
`m.page` telling them apart.

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
2. **Inventory and shipping**: GTIN, backorders, low stock, sold individually,
   weight, dimensions, shipping class. All flat scalars, the cheapest real win.
3. **Organization**: categories, tags, brands, slug, featured. Needs a term
   picker, which the Terms manager's autocomplete already models.
4. **Images**: featured plus gallery, reorder and replace. The island images
   editor's tile grid is the closest existing UI.
5. **Advanced and pricing extras**: sale schedule, purchase note, menu order,
   reviews, tax status and class.
6. **Type-conditional cards**: virtual, downloadable and its files, external URL
   and button text. Also the point where the type select becomes safe to expose.
7. **Linked products**: upsells and cross-sells, needing a product search picker.
8. **Attributes**: inline custom attributes first, global attributes second.
9. **Variations**: a list view against the variations sub-resource, editing SKU,
   price, stock and image per variation. Attribute creation stays in WooCommerce.

Waves 2 through 5 cover what a shop owner touches weekly. Waves 8 and 9 are
where WooCommerce's own UI is a canvas, so they need their own scoping pass
before anyone starts.
