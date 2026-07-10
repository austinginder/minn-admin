# One place to activate Pro licenses (proposal)

A real WordPress site runs several commercial plugins, and every one of them
nags for its license key on its own wp-admin page: Elementor Pro, Brizy Pro,
Bricks, WPBakery, and dozens more each show a "activate your license" banner
that links somewhere different. In Minn's notification digest they stack up as
a wall of near-identical asks (see the license nags captured on the builders
lab). The idea: **one activation surface in Minn where you paste a key once and
Minn hands it to the right plugin**, so the nags clear without a wp-admin
scavenger hunt.

This is a proposal, not shipped. The research below is what makes it hard, and
the recommendation is a phased, honest-about-its-limits adapter surface.

## Why there is no easy version

There is **no shared license API in WordPress**. Each vendor invented its own
storage option, key format, activation function, and remote endpoint. Verified
against the builders lab:

| Plugin | Key format | Stored in | Activate via | Endpoint |
|---|---|---|---|---|
| **Elementor Pro** | license key | option `elementor_pro_license_key` (+ `_elementor_pro_license_v2_data`) | `\ElementorPro\License\API::activate_license($key)` | `my.elementor.com/api/v1/licenses/` |
| **Brizy Pro** | license key | plugin option | `BrizyPro_Admin_License->activate($args)` → `request(…, ACTIVATE_LICENSE)` | Brizy's own API |
| **WPBakery** | **Envato purchase code** (not a key) | option `js_composer_purchase_code` (+ `license_key_token`) | `Vc_License` + `wp_ajax_vc_check_license_key` | Envato / WPBakery |
| **Bricks** | license key | option `bricks_license_key` | theme-side activation | `api.bricksbuilder.io` |

The differences are not cosmetic:

- **Different secrets.** WPBakery wants an Envato purchase code, not a license
  key. EDD-based plugins (many) want a license key tied to a download ID.
- **Different call shapes.** Some expose a clean static method
  (`API::activate_license`), some only an AJAX handler with a nonce, some only
  a form POST on their settings page.
- **Different success/failure semantics.** Each returns its own JSON shape:
  activated, invalid, expired, **site limit reached** (a real hazard — a wrong
  activation can burn a paid seat), disabled, etc.
- **Different per-domain rules.** Seat counts, dev/staging exemptions, and
  deactivation-before-move behaviour vary per vendor.

So a single "activate everything" button is a **per-plugin adapter matrix**,
not one integration. Getting it wrong risks burning activation slots or writing
malformed license state that the plugin then refuses to repair.

## The three strategies

1. **Deep-link only (what Minn does today).** The notice digest surfaces each
   nag with its "Activate ↗" link; clicking opens the vendor's own page. Zero
   risk, zero magic. The status quo.
2. **Store-and-forward vault.** Minn keeps a per-site "license locker" (keys the
   user pastes once), and when a matching plugin is active, offers a one-click
   "Apply to ⟨plugin⟩" that calls that plugin's own activation path. The keys
   still live per plugin; Minn is a convenience layer, never the source of
   truth.
3. **Full abstraction.** Minn owns license state and silently keeps every
   plugin activated. Rejected: it makes Minn responsible for other vendors'
   billing edge cases, and a bug here costs the user real money or a broken
   Pro install.

## Recommendation: a capability-gated vault (strategy 2), adapter by adapter

Ship a **Licenses** surface that is honestly a convenience wrapper over each
plugin's own activation, one adapter at a time, highest-value vendors first.

**Shape:**

- A `minn_admin_license_providers` filter. Each provider declares:
  `{ id, name, secret_label ("License key" | "Envato purchase code"),
  status(): {state, expires?, site_limit?}, activate($secret): result,
  deactivate(): result }`. Bundled adapters call the plugin's OWN activation
  code (never a reimplemented HTTP call to the vendor) so seat rules,
  nonces and error handling stay the vendor's.
- The surface lists every active Pro plugin Minn has an adapter for, its
  current license state (read from the plugin's stored option — no network
  call), and a field to paste + activate. Deactivate where the vendor
  supports it.
- The notice digest learns about it: a captured "activate your license" nag
  from a plugin Minn has a license adapter for gets an inline **Activate in
  Minn** action instead of only the deep-link.

**Non-negotiable guardrails (why this is careful, not clever):**

- **Never reimplement a vendor's activation HTTP call.** Always route through
  the plugin's own method/class, so if the vendor changes their API Minn
  doesn't silently corrupt state. If a plugin exposes no callable path (only a
  form POST), that plugin is deep-link-only — no adapter.
- **Surface "site limit reached" as a first-class result**, never retry a
  failed activation automatically (retries can burn seats).
- **Read status from the plugin's stored option, not the network**, so the
  surface is fast and can't rack up API calls.
- **Store pasted secrets encrypted at rest** (or not at all — a "paste to
  activate, don't retain" mode should be the default; the locker is opt-in).
- **manage_options only.**

## Phasing

- **Phase 1 — Elementor Pro + Bricks.** Both expose a clean activation call and
  a plain option for status; highest install base among Pro builders. Proves
  the provider contract end-to-end with a real key.
- **Phase 2 — EDD/Freemius families.** A large share of Pro plugins use one of
  a few licensing SDKs (EDD Software Licensing, Freemius). A single adapter per
  *SDK* can cover many plugins at once — the real leverage.
- **Phase 3 — Envato purchase-code plugins (WPBakery et al.).** Different
  secret type; the surface already models `secret_label`, so it slots in.
- **Deferred:** vendors that only accept a form POST on their settings page
  stay deep-link-only, and that's fine.

## Open questions (decide before building)

- **Which vendors first, and do we have test licenses?** Phase 1 needs a real
  Elementor Pro / Bricks key on the lab to verify activation without faking it.
- **Locker storage.** Encrypt-at-rest vs paste-and-forget default. Where does
  the key material live, and what happens on plugin deactivation or site
  migration (deactivate-first etiquette)?
- **Scope creep into billing.** Minn should show state and activate; it should
  not try to renew, upsell, or manage seats across sites. Draw that line
  explicitly.

## Status

Parked. This is a multi-session feature that needs a scope decision (which
vendors, locker storage model) and test licenses before any code. Until then,
the notice digest's deep-links are the shipped answer, and they already remove
most of the "where do I even go" friction.
