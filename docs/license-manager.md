# License manager — visibility first, activation second

A real WordPress site runs several commercial plugins, and every one of them
handles its license alone: its own wp-admin page, its own nag banner, its own
idea of what "active" means. Two distinct problems fall out of that:

1. **Zero visibility.** Nothing on a site can answer "which of my paid plugins
   have a valid license right now?" An expired key usually means silently
   missed updates (including security releases) until something breaks.
2. **Activation scavenger hunt.** Setting a site up means visiting five
   different settings pages to paste five keys.

No true cross-vendor license manager has ever existed in WordPress. There is
no shared license API; each vendor invented its own storage, key format,
activation call and status semantics. That's exactly why it's valuable, and
why it has to be built carefully.

The 2026-07-10 research pass (source-verified against the local labs' real
paid plugins) changed the plan: **visibility is buildable now, read-only,
with zero risk**, while activation stays the careful per-vendor project the
earlier draft of this doc described. Ship them in that order.

## Phase 0 — the license status dashboard (read-only) — SHIPPED 2026-07-10

Implemented in `includes/adapters/licenses.php`: the Licenses card on the
System page, a Licenses health check, `GET minn-admin/v1/licenses`, the
`minn_admin_license_providers` filter (documented in for-plugin-authors.md)
and Integrations-card attribution. Verified against real licenses: WP Rocket
(valid, real expiry date), ACF PRO and Blocksy Companion Pro (valid,
lifetime), Perfmatters through the generic EDD reader, and all seven paid
builders on the lab enumerating correctly. One implementation fact the
research missed: Freemius keys `sites` by ITS product slug, not the plugin
directory (`blocksy-companion-pro/` registers as `blocksy-companion`); the
stored `file_slug_map` inside `fs_accounts` bridges plugin file → product
slug. The design below is the record of what was built and why.

A surface that enumerates every license-wanting component on the site and
classifies each as **valid / expired / invalid / missing / unknown**, from
locally stored state only. No network calls, no vendor code execution, no
writes. It cannot burn an activation seat because it never activates anything.

### Why this is feasible: the storage landscape (source-verified)

Two generic SDKs cover a large share of the commercial ecosystem with one
adapter each:

- **Freemius** — detection: the plugin/theme ships a `freemius/` directory.
  All state lives in one option, `fs_accounts`: per-slug install objects carry
  `license_id`/`plan_id`, and `all_licenses` holds license entities with an
  absolute `expiration` datetime. Valid/expired/missing is fully computable
  offline, and because expiry is an absolute date, the classification stays
  correct even when Freemius's own sync is stale. The best-behaved vendor of
  all.
- **EDD Software Licensing clients** — detection: the plugin bundles the
  `EDD_SL_Plugin_Updater` class (filenames vary; the class name is the
  signal). Storage is conventional, not fixed: `{prefix}_license_key` +
  `{prefix}_license_status` option pairs (verified: perfmatters, BNFW,
  Breakdance). The *status vocabulary* is standardized by the EDD server:
  `valid`, `invalid`, `expired`, `disabled`, `site_inactive`. Classification
  works by prefix-pairing key/status options; a key with no readable status
  is `unknown`.

Major single vendors, each a small dedicated reader (all verified in source):

| Vendor | Status + expiry storage | Read-only verdict |
|---|---|---|
| Elementor Pro | `elementor_pro_license_key` + `_elementor_pro_license_v2_data` (12h cache; holds `expired`/`site_inactive`/`disabled` and `expires`, or `lifetime`) | Full classification + expiry |
| ACF Pro | `acf_pro_license` + `acf_pro_license_status` (status + expiry array) | Full classification + expiry |
| WP Rocket | keys in `wp_rocket_settings`; invalid flag `wp_rocket_no_licence`; expiry in the 1-day `wp_rocket_customer_data` transient | Full when cache warm; key + invalid flag always |
| Astra / Brainstorm Force | `brainstrom_products` (sic), per-product `purchase_key` + `status === 'registered'` (covers Astra Pro, UAE, Spectra Pro) | Full classification |
| Kadence (StellarWP Uplink) | per-plugin `stellarwp_uplink_license_key_{slug}` + status options | Full classification |
| Bricks | `bricks_license_key` + `bricks_license_status` transient (7d TTL) | Status while cache warm, else unknown-stale |
| Beaver Builder | `fl_themes_subscription_email` + `fl_get_subscription_info` transient (`active`, `expiration`) | Status while cache warm |
| Divi / Elegant Themes | `et_automatic_updates_options` (username + API key) + `et_account_status` | Status string, no expiry |
| Admin Columns / Advanced Ads / WP All Import | EDD-style key+status options (per-product names) | Full classification |
| WPBakery | `wpb_js_js_composer_purchase_code` only; no status is ever stored (lifetime model) | Presence-only |
| Brizy Pro | postmeta `brizy-license-key` on the Brizy project post, not wp_options | Presence-only |
| Etch / SureCart licensing SDK | `{name}_license_options` + activation-id option; SDK is shared, so this is a small generic adapter too | Activated/missing, no expiry |
| Gravity Forms | `rg_gforms_key` stores the key md5-hashed | Presence-only |

### Detector design

Two layers, both strictly read-only (raw option/postmeta reads; never invoke
vendor classes, never hit the network):

**Layer 1 — enumeration ("who wants a license?").** Cheap, cacheable signals
per installed plugin/theme: embedded SDK fingerprints (`freemius/` dir,
`EDD_SL_Plugin_Updater` string, SureCart licensing dir, `bsf-core/`, Uplink),
a known-vendor slug registry, and the update-source heuristic (entries in the
`update_plugins`/`update_themes` transients whose package URL is not
wordpress.org, which the plugin-meta endpoint already reads for the reverse
purpose). Anything matching becomes a row even if unclassifiable.

**Layer 2 — classification.** Vendor adapters declare option/meta locations, a
status map, an expiry field and the vendor's cache TTL; the two SDK adapters
(Freemius, EDD) cover their whole families generically. Output per component:

- `valid` — stored status says so, within honesty limits
- `expired` — stored status or an absolute expiry date in the past
- `invalid` — deactivated, site_inactive, disabled, key/domain mismatch
- `missing` — component wants a license, no key stored (the loudest row)
- `unknown` — key present but status unreadable or stale past the vendor's TTL

Every classified row carries an "as of" timestamp derived from the vendor's
own cache/check time. **Stored status is last-verified truth, not live truth**;
the UI must say "valid as of 3 days ago", never pretend to real-time.

### Honest limits

- WPBakery, Gravity Forms, Brizy and Etch cap out at activated/missing.
- Bricks, Beaver Builder and Divi lose status when their transients expire;
  those rows go unknown-stale with the last-known value and its age.
- Expect a visible chunk of long-tail rows at "key present, status unknown".
  That is still useful: it proves a key exists and names the plugin.
- WooCommerce.com subscriptions and Envato purchase-code plugins use entirely
  different models; unverified, deferred.
- A renewal or a remote deactivation isn't visible until the vendor's own
  check runs again. Phase 2 addresses this; Phase 0 does not.

### Where it lives

Start as a **Licenses card on the System page** (rows with status pills, the
same health-check language the page already speaks) plus a health check
("2 licenses expired, 1 missing") and license badges on Extensions cards.
Graduate to a dedicated surface when Phase 1 adds actions. The notice digest
already captures the vendors' own nag banners; rows here should link to the
same activation deep-links the digest extracts.

## Phase 1 — activation (wave 1 SHIPPED 2026-07-10)

Shipped as paste-to-activate on the Licenses card, with the locker question
resolved by not building one: **no key is ever retained.** A pasted secret
rides one request into the vendor's own activation code and is never stored,
logged or echoed back. The provider contract grew three optional callables
(`activate( $secret )`, `deactivate()`, `verify()`; documented in
for-plugin-authors.md), attached only while the vendor's code is loaded, so
the card never draws a control that cannot work. Results normalize to
`{ ok, code, message }` with `site_limit` first-class, and nothing retries.

Wave-1 vendors: **Elementor Pro** (activate / deactivate / re-verify through
`API::activate_license` + `Admin::set_license_key` + `API::set_license_data`,
mirroring its own ajax handler; `no_activations_left` maps to `site_limit`),
**ACF PRO** (activate / deactivate via `acf_pro_activate_license` /
`acf_pro_deactivate_license`, silent mode), and **WP Rocket** (re-verify only
via `rocket_check_key()`: its credentials ship inside the vendor's zip, so
there is no key to paste; this also delivers the first slice of Phase 2).
Plumbing was proven against the real Elementor API with a deliberately bogus
key (clean `invalid` surfaced, nothing written, no retry); a real-key
activation pass is the owner's manual step, same as the self-updater
release-candidate test.

**Wave 2 (shipped 2026-07-10, same day):** Beaver Builder (activate +
re-verify via `FLUpdater::save_subscription_license` /
`get_subscription_info`; no safe deactivate exists so none is offered),
Brizy Pro (activate + deactivate via its singleton; its exceptions carry the
vendor message), Etch (activate + deactivate via its SureCart wrapper
singleton, plus a dedicated reader for its `etch_license_key` /
`etch_license_status` options that the generic SureCart sweep missed), and
Bricks when it is the ACTIVE theme (activate + re-verify through the public
static key + the non-ajax `activate_license()` path, which only persists on
a real status response; its deactivate handler nonce-checks unconditionally,
so deactivation stays on the Bricks screen). The contract also gained
`activate_url` for portal-handshake vendors with no callable path: WPBakery
rows now carry an "Activate ↗" link to its own activation screen instead of
a paste field that could not work. All four callable vendors were
plumbing-verified with bogus keys against their live APIs (clean vendor
messages surfaced, zero leftover state).

**Wave 3 (shipped 2026-07-10, same day): Divi**, the first multi-secret
provider. The contract gained `secret_fields` (id + label per credential;
`activate` receives an id-keyed array); Divi takes the Elegant Themes
username + API key, writes them to their site option, busts their
`et_update_themes` cache and runs the core theme-update check so THEIR
hooked checker validates and stamps `et_account_status`. A failed attempt
restores the previously stored credentials (kinder than Divi's own settings
page, which clobbers them). Verified on a disposable Divi lab with bogus
credentials. Divi has no per-site seats, so deactivate simply clears the
stored credentials.

Rows for INACTIVE components keep their read-only license state (stored
options need no vendor code) but render dimmed with "not active; activate
the theme/plugin to manage its license" in place of controls, so the
active-theme gating reads as intended behavior instead of a missing button.

**Wave 4 (shipped 2026-07-10): the Gravity family.** Gravity Forms reads
validity + expiry from `gform_version_info` and activates through
`GFFormsModel::save_key()` (their complete flow, including their own
revert-on-rejection); Gravity SMTP validates through its container's
license connector before storing via its own data store. Both verified
against Gravity's live API with bogus keys.

**Wave 5 (shipped 2026-07-10): the fleet-ranked uncovered tail.** Eleven
new readers, source-verified against real builds pulled from the
CaptainCore quicksave archive and installed inactive on the primary test site:

- **WPMU DEV** (`wpmudev`): one Hub API key (site option `wpmudev_apikey`
  or the `WPMUDEV_APIKEY` constant) unlocks the family. Membership status
  reads from the Hub-cached `wdp_un_membership_data` (`full`/`unit`/
  `single`/`free` = valid, `paused` = invalid, `expired`, empty/numeric
  handled). Activation mirrors their own auth endpoint minus the redirect:
  `set_key()` then a forced `hub_sync()`; an invalid or expired key comes
  back with an empty `membership` (their own sync logs the site out in
  that case). Deactivate is `logout( false )`. Pinned via the wp-config
  constant means no paste field (`set_key` would be overridden next boot).
- **Smush Pro** (`smush-pro`): rides the Dashboard key but keeps its OWN
  24-hour validity cache `wp_smush_api_auth` (a per-key `{validity,
  timestamp}` map). Read-only; it has no independent key to paste.
- **SearchWP** (`searchwp`): one option `searchwp_license`
  `{key,status,expires,type}`. Its EDD updater is renamed
  (`SearchWP\Updater`), so the generic filename sweep can never fingerprint
  it. Activate/deactivate/verify through `\SearchWP\License`.
- **Gravity Perks** (`gravityperks`): key in the `gwp_settings` SITE
  option; validity cached in a version-suffixed 12-hour site transient
  `gwp_license_data_{version}`. Not an EDD-updater plugin (custom `GWAPI`).
  Activate/deactivate/verify through `GWPerks`/`GravityPerks`.
- **Rank Math Pro** (`rank-math-pro`): reads the free plugin's
  `rank_math_connect_data` (`username`/`api_key`/`plan`). Registration is
  a rankmath.com portal handshake owned by the free plugin, so the control
  is an `activate_url` to `admin.php?page=rank-math&view=registration`.
- **Perfmatters** (`perfmatters`): plain `perfmatters_edd_license_*`
  option pair (site options on multisite). Builds vary on whether they
  ship the SL updater file, so a dedicated reader (which claims the
  component and supersedes the generic sweep) is the reliable coverage.
  Activate/deactivate/verify through `\Perfmatters\License`.
- **GP Premium** (`gp-premium`): its option names break both generic-sweep
  assumptions (`gen_premium_` prefix vs `gp-premium` slug, and the status
  option is `..._license_key_status`), so it gets a dedicated reader.
  Activation drives its own REST route (`/generatepress-pro/v1/license/`)
  via `rest_do_request`; empty key = deactivate, `***` = re-verify.
- **WP All Import Pro / WP All Export Pro** (`wp-all-import` /
  `wp-all-export`): everything inside the serialized `PMXI_Plugin_Options`
  (class-keyed `licenses`/`statuses` maps) / `PMXE_Plugin_Options` (flat
  `license`/`license_status`); keys are salt-wrapped. Export activates
  through their `LicenseActivator` (stores the key their way first);
  Import's only paste path is a nonce-gated admin controller, so it gets a
  verify (their public static `check_license`) plus an "Activate ↗" link.
- **Slider Revolution** (`revslider`): flat `revslider-code` +
  `revslider-valid` (the string `'true'`/`'false'`); activate/deactivate
  through `RevSliderLicense` (`'exist'` = the site-limit result).
- **LayerSlider** (`layerslider`): `layerslider-purchase-code` +
  `layerslider-authorized-site` (1/0). Its updater exposes
  `handleActivation( $code, ['skipRefererCheck'=>true,'returnData'=>true] )`
  which is their own re-validation path; verify reuses it with the stored
  code. Its deactivate handler `die()`s mid-request, so releasing the seat
  stays on their screen.
- **Avada** (`avada`, theme): `fusion_registration_data['avada']` carries
  the purchase code and a strict `is_valid` flag; one code covers the
  bundled Avada plugins. Store paths are nonce/WP-CLI coupled, so read-only.
- **Envato Market** (`envato-market`): the account OAuth token is
  presence-only (Envato records no local validity → `unknown`), plus any
  per-item single-use tokens that DO carry an `authorized` success/failed
  flag; purchased-item counts ride the plugin's own hourly transients when
  warm. Token entry is a nonce-coupled Settings-API screen, so an
  "Activate ↗" link.
- **The Events Calendar family** (dedicated providers since 2026-07-11,
  proven with real keys): PUE stores the key in
  `pue_install_key_{slug with underscores}` and the recorded status in
  `pue_key_status_{dashed slug}_{domain}` (plus a `_timeout` sibling and
  an md5 transient). Full activate/deactivate/verify through each
  plugin's own `Tribe__PUE__Checker` (constructed with the plugin's exact
  args; `validate_key()` is their complete flow and persists the key only
  on acceptance). GOTCHA: a product with an Uplink resource (Event
  Tickets Plus) makes `validate_key` read the RESOURCE key, not the
  argument, so activate seeds the resource first and rolls back on
  rejection. Builds downloaded from a TEC account can EMBED the key
  (Uplink KeyFactory helper); the checker re-seeds the option from it, so
  local deactivation is temporary for those and the message says so.
- **Kadence Blocks Pro** (dedicated provider since 2026-07-11, proven
  with a real key): StellarWP Uplink under the free kadence-blocks
  vendor namespace, slug `kadence-blocks-pro`; the purchaser's key ships
  inside the plugin build (`includes/uplink/Helper.php` DATA constant,
  the WP Rocket pattern), with the uplink option overriding the file
  key. Activate stores then `validate_license()`s with
  snapshot-restore on rejection; deactivate mirrors their own Clear
  button (the baked-in key remains as fallback).
- **Other StellarWP Uplink / PUE products** (`stellarwp`,
  registry-style catch-all): presence-only for PUE, status-classified
  for Uplink; slugs the dedicated providers claim are skipped.

Read classification for every one of these is proven by
tests/license-vendors.test.js (21 checks: seeds each real option shape,
asserts the state pill, then CLEARS the options (plain options are not
settings-API deletable, so a crash-aborted run cannot leave a fake license
behind).

The activation plumbing was verified 2026-07-10 by firing a bogus key at
each callable provider through the real action endpoint against the live
vendor APIs (the same bogus-key pass the earlier waves got). Every one
returned a clean `{ok:false, code:'invalid'}` with a readable vendor
message and left no key behind: SearchWP, Perfmatters, Gravity Perks,
GP Premium, LayerSlider, WP All Export and WPMU DEV all classify back to
`missing` after a rejected key. Three fixes came out of that pass: GP
Premium's REST route is registered without a trailing slash (a `/license/`
request 404s); Perfmatters, WP All Export and Gravity Perks store the
pasted key BEFORE validating (their own activation flow reads it from the
option), so each now snapshots and restores the prior key on failure
rather than retaining the rejected one, and GP Premium's route (which
unconditionally writes the key at the end) gets the same restore. Slider
Revolution was DEMOTED to an "Activate ↗" link: its
`activate_plugin()` is welded to admin-only classes (RevSliderTracking,
the load balancer that only registers in RevSliderGlobals during admin
init) that do not load in a REST request, and reproducing that boot order
is the vendor-internals guessing the guardrails forbid. (Reversed
2026-07-11: two `include_once` calls from `RS_PLUGIN_PATH` load exactly
those classes under REST with no boot-order guessing, so the full
activate / deactivate / verify loop shipped and passed with a real key;
see the tested table below.)
The generic EDD/Freemius verification pass is now moot for the tail: the
audit found the filename-only EDD fingerprint MISSES the renamed-updater
plugins (SearchWP, Soflyy) and the option-pair sweep MISSES GP Premium's
naming, so dedicated readers cover them all; Perfmatters could go either
way by build, and its dedicated reader wins to avoid double rows.

## Next candidates, ranked by real fleet impact (2026-07-10)

Measured across 2,790 production sites on the CaptainCore fleet (the
Manager DB inventory, classified against wp.org; full sourcing paths in the
2026-07-10 session record). The already-covered set tops the fleet chart:
Gravity Forms 2,543 sites, Gravity SMTP 1,440, Elementor Pro 1,191, ACF PRO
1,001, WP Rocket 286, Divi 259, WPBakery 129+, Beaver Builder 43+, Bricks
32. The uncovered tail; wave 5 (2026-07-10) shipped items 1-4 and most of 5-6:

1. ~~**WPMU DEV**~~ SHIPPED (Dashboard + Smush Pro): 488 + 425 sites.
2. ~~**Envato Market**~~ SHIPPED (token/purchase-code): 459 sites. The
   adapter reads the account token + per-item tokens; Avada, Slider
   Revolution and LayerSlider also got their own dedicated readers (their
   codes are stored independently of the Envato Market plugin).
3. ~~**StellarWP / The Events Calendar**~~ SHIPPED and upgraded to FULL
   activation (2026-07-11, verified with real keys: bad key → clean
   invalid, real key → valid with expiry, deactivate): 116 sites.
   Kadence Blocks Pro shipped the same day, same verification.
4. ~~**Gravity Wiz / Gravity Perks**~~ SHIPPED (~103 sites): pairs with GF.
5. ~~**Rank Math Pro**~~ SHIPPED (92), ~~**SearchWP**~~ SHIPPED (87),
   ~~**Soflyy WP All Import/Export Pro**~~ SHIPPED (81+), ~~**Smash Balloon**~~
   SHIPPED (2026-07-15: full activate/deactivate/verify for Instagram Feed
   Pro, Custom Facebook Feed Pro, YouTube Feed Pro, Custom Twitter Feeds Pro,
   Social Wall, Reviews Feed Pro, TikTok Feeds Pro, Feed Analytics Pro via
   EDD on smashballoon.com; "All Plugins" multi-product keys activate each
   product with its own EDD item_name; real-key pass 2026-07-15), ~~**Yoast
   Premium**~~ SHIPPED (2026-07-15: MyYoast portal reader + Activate ↗ to
   `wpseo_licenses` + verify that refreshes site_information; no paste-a-key,
   Rank Math shape; free wordpress-seo required), ~~**Admin Columns Pro**~~
   SHIPPED (2026-07-19, v0.20.0: full loop with a real key; headless
   container bootstrap, key-for-token swap handled; 140 sites).
6. **Cheap wins**: ~~Perfmatters~~ (142) and ~~GP Premium~~ both got
   dedicated readers this wave (the generic sweep does NOT cover them:
   renamed and nonstandard option names). ~~Search & Filter Pro~~ SHIPPED
   (2026-07-19, v0.20.0: full loop with a real key via their own REST
   controller callables). Still open with no vendor code: Unlimited
   Elements, Stackable Premium, Permalink Manager Pro (verify the
   Freemius/EDD sweeps light these up).

Remaining fleet-ranked open work: the long-tail Freemius/EDD verification
list above.

Test builds source from the CaptainCore quicksave repos
(`captaincore quicksave archive` extracts a plugin/theme zip from a
snapshot without touching state).

The guardrails, unchanged and load-bearing:

- **Never reimplement a vendor's activation HTTP call.** Route through the
  plugin's own method. No callable path (form-POST-only vendors) means
  deep-link only, no adapter.
- **"Site limit reached" is a first-class result.** Never auto-retry a failed
  activation; retries can burn paid seats.
- **Paste-to-activate, never retain.** No locker shipped, none planned unless
  a real need appears; if one ever lands it is opt-in and encrypted at rest.
- **manage_options only.**

## Phase 2 — freshness on demand (partially delivered with Phase 1)

The `verify` callable in the Phase-1 contract IS this: a per-row "Re-verify"
that triggers the vendor's own revalidation. Shipped for WP Rocket
(`rocket_check_key()`) and Elementor Pro (`get_license_data( true )`); the
remaining work is adding `verify` to more providers as their safe
revalidation paths are identified (Bricks, Beaver Builder and Divi are the
transient-based vendors that would benefit most).

## Tested and known working

The verification bar has three levels. **Real key** means the complete loop
ran against the live vendor service with a genuine license: activate with a
bad key (clean refusal, nothing written), activate with the real key (Valid
pill with vendor expiry where available), then deactivate. **Bogus key**
means the plumbing was proven against the live service with a deliberately
invalid key only (clean refusal, nothing written); a real-key pass is the
owner's step. **Read** means the reader was verified against real stored
license data on a lab site.

| Product | Actions | Verified | Date | Notes |
|---|---|---|---|---|
| Slider Revolution | activate / deactivate / verify | real key, full loop incl. real ThemePunch deregistration | 2026-07-11 | activation is also what unblocks their updater |
| The Events Calendar Pro | activate / deactivate / verify | real key | 2026-07-11 | PUE; local deactivate only (manage domains on theeventscalendar.com) |
| Event Tickets Plus | activate / deactivate / verify | real key | 2026-07-11 | key ships embedded in account builds and re-registers itself |
| TEC Filter Bar | activate / deactivate / verify | real key | 2026-07-11 | PUE |
| TEC Community | activate / deactivate / verify | real key | 2026-07-11 | PUE |
| Kadence Blocks Pro | activate / deactivate / verify | real key | 2026-07-11 | key ships embedded in the build; deactivate mirrors their Clear |
| Gravity SMTP | activate / deactivate / verify | real key (owner-run on a live site) | 2026-07-10 | check-memory upgrades the unknown state |
| Elementor Pro | activate / deactivate / verify | bogus key | 2026-07-10 | reader also proven against a real license |
| ACF PRO | activate / deactivate / verify | bogus key | 2026-07-10 | reader proven against a real lifetime license |
| WP Rocket | verify only | real license (cache lab) | 2026-07-10 | creds ship in the vendor zip; no paste by design |
| Gravity Forms | activate / deactivate / verify | bogus key | 2026-07-10 | save_key self-reverts on rejection |
| Beaver Builder, Brizy Pro, Etch, Bricks, Divi | per-vendor (see Phase 1) | bogus key / lab | 2026-07-10 | Divi proven on a disposable lab |
| WP All Export Pro | activate / deactivate / verify | real key, full loop | 2026-07-11 | lifetime, unlimited activations; deactivate is local (no seats exist) |
| WP All Import Pro | activate / deactivate / verify | real key, full loop | 2026-07-11 | same; stored keys are salt-wrapped, decode before any request |
| Search & Filter Pro | activate / deactivate / verify | real key, full loop | 2026-07-19 | EDD (item 526297) via their own REST controller callables; state in the free base's `{prefix}search_filter_options` table, JSON row `license-data`; needs base + Pro active for actions; snapshot-restore keeps a rejected key from clobbering a valid activation (their own connect() would) |
| Admin Columns Pro | activate / deactivate / verify | real key, full loop | 2026-07-19 | own API on admincolumns.com via DI-container services; main file is is_admin()-gated so Minn bootstraps the container headless (autoloaders + api.php + their six definition files, no Loader); activation DELETES the pasted key and stores an activation token (`acp_activation_key`) + `acp_subscription_details` {status active/cancelled/expired, expiry_date ts, null = lifetime}; permissions rules applied exactly as their handlers do |
| WPMU DEV, SearchWP, Gravity Perks, Perfmatters, GP Premium, LayerSlider | activate paths | bogus key | 2026-07-10 | readers proven against seeded real shapes |
| Rank Math Pro, Envato, WPBakery | Activate ↗ link | read | 2026-07-10 | portal- or admin-context-bound activation |
| Yoast SEO Premium | Activate ↗ + verify | read (missing until MyYoast) | 2026-07-15 | free Yoast `WPSEO_Addon_Manager`; page `wpseo_licenses`; no paste-a-key |
| Smash Balloon family (8 products) | activate / deactivate / verify | real All Plugins key | 2026-07-15 | EDD on smashballoon.com; multi-product key per item_name |
| Freemius / EDD / SureCart generics, Avada, Smush Pro, AnalyticsWP, BSF family | read | real stored data on labs | 2026-07-10 | see the verification map above |
| StellarWP Uplink / PUE catch-all | read | real stored data (TEC six-pack) | 2026-07-11 | registry reader; skips slugs claimed by dedicated providers |

Keep this table current when a vendor gets a real-key pass or a new provider
lands; the expansion runbook lives in the /dev-minn-admin skill.

## What Minn will never do here

Own license state, silently keep plugins activated, renew or upsell, manage
seats across sites, or store secrets without opt-in. Minn shows state and,
where a vendor exposes a safe path, forwards a key. Billing edge cases stay
the vendor's product.

## Status

Phase 0 shipped 2026-07-10 (visibility: the Licenses card, health check,
SDK-generic readers). Phase 1 waves 1-5 shipped the same day: the documented
provider action contract, no locker by design, and readers + actions for
Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Gravity SMTP, Beaver
Builder, Brizy Pro, Etch, Bricks, Divi, and the fleet-ranked tail (WPMU DEV,
Smush Pro, SearchWP, Gravity Perks, Rank Math Pro, Perfmatters, GP Premium,
WP All Import/Export Pro, Slider Revolution, LayerSlider, Avada, Envato
Market, The Events Calendar family + StellarWP Uplink, Smash Balloon family,
Yoast SEO Premium MyYoast). Search & Filter Pro and Admin Columns Pro joined
2026-07-19 (v0.20.0), both real-key full loops. Remaining: a real-key /
portal activation pass by the owner for anything still untested, then the
long-tail Freemius/EDD verification list.
