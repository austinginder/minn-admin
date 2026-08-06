# Connections center — audit and direction

Written 2026-08-06 during the v0.24.0 cycle, from Austin's prompt: the
license manager may want to evolve into a licensing/connector center. This
doc is the audit of every surface where Minn currently touches an
external-service credential or connection, and the staged plan that fell
out of it. Companion docs: `license-manager.md` (the license providers and
guardrails), `plugin-support.md` Waves E/F (the category inventory that
surfaced WooCommerce.com connect as the top missing item).

## The audit: five touch points exist today, not three

The prompt guessed three (license keys, spam licenses, AI connectors).
The sweep found five, running on four different handling models. That
spread is the actual argument for a center.

| # | Surface | What lives there | Handling model |
|---|---|---|---|
| 1 | **Extensions → Licenses** (`adapters/licenses.php`, ~40 providers) | Purchase licenses (Elementor Pro, ACF PRO, GF, TEC…), but ALSO connection-shaped rows that have already outgrown the name: the Envato Market account token, WPMU DEV's Hub connection (deactivate is literally `logout`), the MyYoast portal link, WP Rocket's embedded credentials, Divi's username + API key pair | `minn-admin/v1/licenses/action`: paste-once, never stored by Minn, no auto-retry, site_limit first-class, check-memory for stateless vendors |
| 2 | **Settings → Comments, SPAM cards** (`adapters/spam.php`) | Akismet API key (masked display `715e…`, read-only today), CleanTalk cloud key (read-only by design), Antispam Bee and WP Armour (keyless) | Display + deep link only; no editing |
| 3 | **Settings → Connectors** (WP 7.0 `wp_get_connectors()` registry) | AI providers and anything else registering a core connector, with `authentication.setting_name` | Core's own model: key-source resolver env → constant → database, saves through core `wp/v2/settings` where core masks responses and live-validates AI keys; install/activate affordance for the companion plugin |
| 4 | **Email surface settings** (mail family) | Gravity SMTP maps real credential fields with masked-secret sentinels and constant locks (key editing already ships here); FluentSMTP deliberately excludes credentials (its connection list is read-only, "credentials stay in FluentSMTP") | Per-vendor settings mappers writing through the plugin's own sanitizers |
| 5 | **Backups → Disembark status card** | The site token, fetched on demand for the copyable `disembark connect` command (never rides a pageload), plus a Regenerate token action | Token lifecycle on a context surface |

Adjacent but not yet surfaced as manageable state: Site Kit's Google
connection and Jetpack's WPCOM blog token (both consumed silently by the
traffic providers), and the WooCommerce.com helper connection (not wired;
top item in the license vendor-family inventory).

## What the audit says

1. **The Licenses card is already a connections center that has not
   admitted it.** Four of its rows are not licenses. Renaming and grouping
   is truth-telling, not new scope.
2. **Do not unify the write paths.** Each of the four handling models is
   correct for its source of truth (vendor license APIs, core's connectors
   registry, plugin settings sanitizers, token lifecycle). What is missing
   is one INVENTORY: a single view answering "what external services does
   this site talk to, as whom, and is each healthy."
3. **Context surfaces stay, and drive the same endpoints.** The spam card
   should edit the Akismet key in place; the center shows the same row.
   Center = audit view, context card = fix-it-here view. The Gravity SMTP
   settings mapper and the Disembark card already prove the pattern.

## Staged plan

**Brick 1 — Akismet (S). SHIPPED 2026-08-06** (same day as this doc;
bogus-key verified against live akismet.com through both doorways; spam
provider contract gained the optional `keyProvider` status key, documented
in for-plugin-authors.md). As scoped: Akismet joins `licenses.php` as a provider:
read from `wordpress_api_key` + `akismet_alert_code`/`akismet_alert_msg`
(their alert system is the local validity signal), honor
`Akismet::predefined_api_key()` (WPCOM_API_KEY constant and the
`akismet_get_api_key` filter render read-only rows), actions through their
own code: `Akismet_Admin::save_key()` (verify + subscription check + store,
their complete flow; the admin class is is_admin()/WP_CLI-gated so REST
requires it manually, the WPForms pattern) and `Akismet::deactivate_key()`
+ option cleanup (their own disconnect precedent). The spam card gains a
paste/change-key affordance posting to the same `licenses/action`
endpoint. Suite: spam-settings + license-vendors extensions, bogus-key
against their live API.

**Brick 2 — the reframe (M). SHIPPED 2026-08-06.** Implementation notes:
the classification field is `category` (`kind` was already taken by the
item shape's plugin/theme axis); grouping stayed STATE-FIRST for triage
(Needs attention → Valid → Not set up) with per-row chips carrying the
category, rather than kind-first groups; core connectors list only when
configured or their companion plugin is active (the uninstalled catalog
stays out), are excluded from the health summary by design, and the card
footer is the doorway to Settings → Connectors. The health check row is
now labeled "Licenses & connections" and carries a server-side `goto`.
As originally scoped: Provider contract gains
`kind: license | key | connection`; the tab renames (working name
"Licenses & connections"); rows group by kind; the existing
connection-shaped rows (Envato, WPMU DEV, MyYoast) reclassify. Marketing
copy, user guide, and the site's Licenses category card update at the next
release. Core connectors (touch point 3) appear as read-only rows linking
into Settings → Connectors; the section itself stays in Settings for now
(it just shipped against the WP 7.0 registry; folding it in is a separate
decision).

**Brick 3 — site connections (M–L).** WooCommerce.com helper connect
state ("connected as X, N subscriptions", read-only + Activate ↗) as the
first pure-connection provider, then Site Kit / Jetpack connection rows
(read-only, disconnect stays on their screens). Health: worst connection
state joins the existing Licenses health check on System.

## Non-goals

- One universal write path (see above; the vendors' own machinery wins).
- Storing any secret Minn-side (unchanged guardrail from license-manager.md).
- Rebuilding OAuth flows (Site Kit, Jetpack, WooCommerce.com connect
  ceremonies stay on their own screens; Minn shows state and links).
- Moving mail credential editing out of the Email surface (it lives where
  the feature lives, by design).
