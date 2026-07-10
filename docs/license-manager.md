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

## Phase 0 — the license status dashboard (read-only, build first)

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

## Phase 1 — the activation vault (careful, per-vendor)

Unchanged in substance from the original proposal: a
`minn_admin_license_providers` filter where each provider declares
`{ id, name, secret_label, status(), activate($secret), deactivate() }`,
with bundled adapters calling the plugin's OWN activation code so seat
rules, nonces and error handling stay the vendor's.

The guardrails are non-negotiable:

- **Never reimplement a vendor's activation HTTP call.** Route through the
  plugin's own method. No callable path (form-POST-only vendors) means
  deep-link only, no adapter.
- **"Site limit reached" is a first-class result.** Never auto-retry a failed
  activation; retries can burn paid seats.
- **Paste-to-activate, don't retain, is the default.** A stored key locker is
  opt-in and encrypted at rest.
- **manage_options only.**

Sequencing within Phase 1: Elementor Pro + Bricks first (clean activation
calls, huge install base, and Phase 0 already reads their status), then the
EDD and Freemius SDK families (one adapter covers many products: the real
leverage), then Envato purchase-code vendors (`secret_label` already models
the different secret type). Needs real test licenses on a lab before any
code; a wrong activation costs actual money.

## Phase 2 — freshness on demand

An optional "Re-verify now" per row that triggers the vendor's own
revalidation (or clears its status transient so the vendor re-checks on its
next admin load). This executes vendor code, so it deliberately stays out of
Phase 0. Small, but it closes the staleness gap for the transient-based
vendors.

## What Minn will never do here

Own license state, silently keep plugins activated, renew or upsell, manage
seats across sites, or store secrets without opt-in. Minn shows state and,
where a vendor exposes a safe path, forwards a key. Billing edge cases stay
the vendor's product.

## Status

Phase 0 is scoped and ready to build: the storage research is done
(source-verified 2026-07-10 against the labs' real paid plugins), the
classification enum and detector layers are designed above, and no scope
decision blocks it. Phase 1 stays parked behind test licenses and a locker
decision.
