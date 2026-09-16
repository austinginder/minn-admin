# Store settings: WooCommerce configuration inside Minn

Source audit and build plan for making every WooCommerce → Settings screen editable from Minn, so a store owner never bounces to wp-admin for configuration. Measured against WooCommerce 11.1.0 on minnadmin.localhost.

## Where Minn stands

Operations are deep (Orders, Products, Coupons, Customers, Subscriptions, Memberships, Gift cards). Configuration is absent: zero of the 162 fields on WooCommerce's settings pages are editable in Minn. The only touches are the coming-soon toggle in Settings → Visibility and two `wc-settings` link-outs (the Coupons empty state and the product units note).

## What WooCommerce exposes

| Screen | wp-admin fields | WC's own REST | Needs a Minn shim | Stays in wp-admin |
|---|---|---|---|---|
| General | 20 | `wc/v3/settings/general` (20) | | |
| Products: General, Inventory, Downloadable, Advanced | 32 | `wc/v3/settings/products` (32, flattened) | section titles come from the registry | |
| Tax options | 9 | `wc/v3/settings/tax` (9) | | |
| Tax rates (per class) | table | `wc/v3/taxes` CRUD + batch, `wc/v3/taxes/classes` | | CSV import (their importer) |
| Shipping options | 5 | `wc/v3/settings/shipping` (5) | | |
| Shipping zones, regions, methods | tables | `wc/v3/shipping/zones`, `/zones/{id}/locations`, `/zones/{id}/methods` (per-method `settings` validated by WC), `wc/v3/shipping_methods` | | Local pickup (Blocks) settings are React |
| Shipping classes | table | `wc/v3/products/shipping_classes` CRUD | | |
| Payments | React page | `wc/v3/payment_gateways` (enabled, order, title, description, per-gateway `settings` validated by WC) | | gateways with React settings (WooPayments, Stripe, PayPal): their `get_settings_url()`; provider suggestions and incentives |
| Accounts & Privacy | 19 | `wc/v3/settings/account` (12) | 7 `relative_date_selector` retention fields (`{number, unit}`) | |
| Emails: sender and template | 21 | `wc/v3/settings/email` (13) | header image (`email_image_url`), font family, auto-sync with theme | preview, palette picker, "email improvements" |
| Emails: one group per email | 6–9 each, 33 emails on the fixture | `wc/v3/settings/email_{id}` | | |
| Advanced: Page setup, Endpoints, Features, WooCommerce.com | 47 | `wc/v3/settings/advanced` (47) | | Blueprint |
| Advanced: Webhooks | table | `wc/v3/webhooks` CRUD | | |
| Advanced: REST API keys | table | none (ajax `update_api_key` only) | list, create, revoke through `wc_api_hash` on `{prefix}woocommerce_api_keys` | |
| Site visibility | slotfill | | already Minn's Visibility page | |
| Extension pages (Subscriptions 33, Point of Sale 5, JetWooBuilder 15, …) | | `wc/v3/settings/{group}` | | |

516 options over REST on the fixture; about 19 fields need a shim and two slotfills stay link-outs.

## Write paths (verified in WC source)

- Option pages: `WC_Admin_Settings::save_fields( $fields, $data )` is exactly what wp-admin submits through. It runs `woocommerce_admin_settings_sanitize_option`, handles `relative_date_selector`, and is followed by `woocommerce_update_options_{page}` / `_{page}_{section}` actions that extensions listen to (`WC_Settings_Page::save()`). A missing checkbox key saves as `no`, so the shim passes only the edited fields, which matches Minn's only-edited-keys contract.
- Gateways and shipping methods: the v3 controllers validate each field by type against the gateway's or method's own `form_fields` and write the option key (`update_item`). Minn calls them same-origin with cookie + nonce (rule 6), no shim.
- Taxes, zones, classes, webhooks: plain v3 CRUD, `manage_woocommerce`.
- Two `save()` overrides read `$_POST` directly: Advanced (terms page must differ from checkout, cart from checkout and account) and Emails (per-email action). The shim reproduces the Advanced page-collision rule as a 400 and fires the per-email action itself.

## Shape

One destination: **Commerce → Store settings** (pinned last in the group, cap `manage_woocommerce`, route `/minn-admin/store-settings/{section}`), a bespoke page like Minn's own Settings with a section list on the left. Sections are of two kinds:

- **Form sections** rendered by the shared settings form engine (the one `renderSurfaceSettings` uses) from a schema the server builds at runtime out of `WC_Admin_Settings::get_settings_pages()`: page → section → titled groups, `desc_tip` as help, never hand-copied fields (the Perfmatters rule). Extension pages appear automatically.
- **List sections** (Payments, Shipping, Tax rates, Webhooks, API keys) with bespoke renderers on the existing primitives: inline row editors (Terms manager), drag reorder (menus), comboboxes, the record-page shape for a shipping zone.

Implementation notes from phase 1: the settings form engine is shared (`settingsFormHtml` + `bindSettingsForm` in app.js, also behind surface settings and Site Options); the section list is `GET minn-admin/v1/wc/settings` and each section `GET/POST …/wc/settings/{page}/{section|default}`; saves replay `WC_Admin_Settings::save()` on a prepared `$_POST` (current values overlaid with the edits, checkboxes present only when on) so `woocommerce_settings_save_{tab}`, the update-options actions, the rewrite-flush queue and `woocommerce_settings_saved` all fire; errors WooCommerce queues ride back as `errors[]`. Emails: `GET …/wc/emails` for the list, `GET/POST …/wc/emails/{id}` for one email's `form_fields`, saved through `set_post_data()` + `woocommerce_update_options_email_{id}`.

Not a surface descriptor: a settings-only surface cannot host list views, and item-scoped settings (per gateway, per method) cannot share a surface with global tabs. WooCommerce is first-party in Minn (Orders and Products are app code), so its settings are too.

Type map: `checkbox`→toggle, `select`/`radio`→select, `multiselect`/`multi_select_countries`→tags over the country catalog, `single_select_country`→combobox (`US:CA` values), `single_select_page`/`_with_search`→combobox over pages, `color`→color, `textarea`, `number`, `email`, `password`→masked sentinel, `relative_date_selector`→number + unit pair written as one array, `email_image_url`→image (URL stored), `email_font_family`→select over WC's font list. Unknown types count as locked with the wp-admin link.

## Phases

1. **Form sections** (M, shipped 2026-09-16: `adapters/woocommerce-settings.php`, the Store settings page in app.js, suite `wc-settings`). `adapters/woocommerce-settings.php`: `GET/POST minn-admin/v1/wc/settings/{page}/{section}` from the registry, saved through `save_fields` on the edited subset plus the update-options actions. Sections: General, Products ×4, Tax options, Shipping options, Accounts & Privacy (with retention pairs), Emails (sender + template), Advanced (Page setup, Endpoints, Features, WooCommerce.com), plus every extension page. Emails list: one row per `WC_Emails` email with an enabled switch and recipient, opening that email's fields (write via `$email->set_post_data()` + `process_admin_options()`, the WC_Settings_API path). Retire the two `wc-settings` link-outs into internal doorways (the Coupons empty state jumps to General with the coupons toggle pulsed, the rule-31 class). Suite `wc-settings`.
2. **Payments** (M, shipped 2026-09-16: `minn-admin/v1/wc/payment_gateways` list/order/{id}; the route name deliberately contains `payment_gateways` because Cash on delivery only loads its shipping-method choices for a REST route named that way). Gateway rows from `wc/v3/payment_gateways`: enable switch, drag order (`order`), needs-setup badge, opening a row edits title, description and the gateway's `settings` map through the same form engine (`safe_text`, `textarea`, `checkbox`, `select`, `multiselect`, `password`). A gateway whose settings are React (`get_settings_url()` not on `wc-settings&tab=checkout`, or `admin_options()` overridden with no `form_fields`) shows "Open settings ↗" instead. Suite `wc-payments` on bacs/cheque/cod.
3. **Shipping** (M–L, shipped 2026-09-16: `minn-admin/v1/wc/shipping` payload + zones/methods routes writing through `WC_Shipping_Zone` and each method's `process_admin_options`, which needs `$_REQUEST['instance_id']` set; classes over `wc/v3/products/shipping_classes`). Zones list with drag order and the "Locations not covered" row; zone page (`/store-settings/shipping/{id}`): name, regions as tags over continents/countries/states plus a postcode textarea (`/locations`), methods list with add-from-`shipping_methods`, enable, reorder, delete, and each method's instance settings from its `settings` map (`/zones/{id}/methods/{instance}`). Shipping classes as an inline table. Local pickup stays a link-out until its options are mapped. Suite `wc-shipping`.
4. **Tax rates** (S–M, shipped 2026-09-16: sections per class from WooCommerce's own Tax page sections, rows over `wc/v3/taxes`, order via `/taxes/batch`). One tab per class from `taxes/classes` (add and remove classes), inline add/edit/delete rows (country, state, postcode, city, rate, name, priority, compound, shipping), bulk delete via `/taxes/batch`, CSV export through the `download` shape; import stays a link-out. Suite `wc-tax`.
5. **Advanced lists** (S, shipped 2026-09-16: webhooks over `wc/v3/webhooks`, custom action events must start with `woocommerce_` or `wc_` per `wc_is_webhook_valid_topic`; keys via `minn-admin/v1/wc/api-keys`). Webhooks CRUD (name, status, topic, delivery URL, secret, API version) over `wc/v3/webhooks`. API keys: list, revoke, create (description, user, permissions) mirroring `WC_AJAX::update_api_key` with the consumer secret shown once and never stored by Minn. Suite `wc-advanced`.
6. **Doorways** (S, partly shipped 2026-09-16: ⌘K commands for Store settings, Payment methods, Shipping zones, Tax rates and Store emails; the Coupons empty state opens Store settings. Still open: store-strip and System health rows linking into sections). ⌘K "Store settings" plus "Open shipping", "Open payments"; Overview store strip links to the section that fixes what it flags; System health rows where WC already reports posture (no payment method enabled, no shipping zone) link into the sections.

Deliberate non-goals: WooPayments onboarding, provider suggestions and incentives, the email preview and designer, Blueprint, Site visibility (Minn's own page owns it), the Local pickup React screen for now.

## Fixture notes

minnadmin runs WooCommerce 11.1.0 with bacs/cheque/cod, one uncovered zone, three tax rates across three classes, no webhooks, no API keys. Seed one zone with two methods, one webhook and one API key for the suites; Subscriptions, POS and JetWooBuilder pages give the extension-page path real subjects.
