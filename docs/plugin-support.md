# Plugin support in Minn Admin

Minn works on any WordPress site; classic wp-admin always stays one click
away for anything Minn doesn't surface. On top of that baseline, Minn ships
**adapters** that bring specific plugins into the Minn UI natively. Every
adapter is a thin, read-mostly shim: it reaches into a plugin's data through
the plugin's own API or a prefix-scoped query, never runs foreign PHP inside
Minn's UI, and never unserializes third-party blobs. Plugin authors can add
their own coverage through the documented filters (see `for-plugin-authors.md`)
without Minn shipping code.

This page is the map of what's covered today. "Surface" = a nav item;
"panel" = a card in the editor sidebar; "provider" = feeds an existing
shared view; "action" = a ⌘K / menu command.

## Coverage at a glance

| Area | Plugins | How it shows up |
|---|---|---|
| **SEO** | Yoast, Rank Math, AIOSEO, SEOPress, SiteSEO | Editor panel (title, meta description, focus keyword) |
| **Forms** | Gravity Forms, Fluent Forms, Elementor Pro, Contact Form 7 (via Flamingo), CFDB7 | **Forms** surface — entries as contact cards. Gravity Forms adds the full entry workflow through its own endpoints: Received/Spam/Trash status views, star/unstar and mark-read (open marks read like GF's own screen), restore and delete-permanently where they apply, bulk actions, entry notes on the card plus add-a-note, and resend notifications |
| **Email** | Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging | **Email** surface (renamed from Email Log once it grew settings) — sent mail, resend. Gravity SMTP goes deeper: a **Settings** view maps its own settings schema into Minn (sending service across all 21 connectors, connector config with masked secrets, general/logging settings through its constant-lock-aware stores), the surface honors its granular `gravitysmtp_*` capabilities, the event detail reads through its own models (from/cc/bcc/source), resend replays its own recipient handling through the configured connector, a **Suppressions** view lists/adds/reactivates blocked addresses through its own model, and a status card reports the active service and test mode with a parameterized **Send a test email** action |
| **Redirects** | Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects (WebFactory) | **Redirects** surface — list + in-place edit; Redirection's first-run install runs in place via the setup gate |
| **Activity log** | Simple History, WP Activity Log, Aryo, Stream, **Wordfence**, **Limit Login Attempts Reloaded** | **Activity Log** surface (Wordfence = login security; Limit Login Attempts = lockout log with a status card and one-click Unlock through the plugin's own store) |
| **Security posture** | Wordfence, Really Simple SSL | System health rows: Wordfence firewall mode (enabled / learning / off) + last scan and unresolved-issue count; Really Simple SSL enforcement status (both read through each plugin's own public APIs). The System page's **Login URL** row uses `wp_login_url()`, so it honors login-hiders (WPS Hide Login and friends) rather than assuming wp-login.php |
| **Snippets** | Code Snippets, WPCode, FluentSnippets | **Snippets** surface — list, toggle, edit |
| **Analytics** | Koko, WP Statistics, Burst, Independent Analytics, AnalyticsWP, **Site Kit** | Overview **Traffic** chart |
| **Backups** | UpdraftPlus, Disembark, Duplicator | **Backups** surface; health check + "Back up now" (UpdraftPlus); status card, CLI command, sessions + cleanup (Disembark); package list with disk sizes, status card and delete-through-its-own-cleanup (Duplicator, no freshness claims: manual builds) |
| **Caching** | Kinsta, LiteSpeed, WP Super Cache, W3TC, WP Rocket, WP Fastest Cache, SiteGround, Autoptimize, WP-Optimize, Cache Enabler, Hummingbird, Elementor CSS | **Clear site cache** action (⌘K) |
| **Custom fields** | ACF (+ Pro) | Editor panel |
| **Ecommerce** | WooCommerce | **Orders** surface + Overview stats |
| **Spam filtering** | Akismet, Antispam Bee, CleanTalk, WP Armour | Settings → Spam provider cards; open via `minn_admin_spam_providers` |
| **Licenses** | Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Gravity SMTP, AnalyticsWP, Bricks, Divi, Beaver Builder, WPBakery, Brizy, Etch, Astra/Brainstorm family, WPMU DEV (Dashboard + Smush Pro), SearchWP, Gravity Perks, Rank Math Pro, Perfmatters, GP Premium, WP All Import/Export Pro, Slider Revolution, LayerSlider, Avada, Envato Market, The Events Calendar family (Pro, Event Tickets Plus, Filter Bar, Community, each a dedicated provider) + any other StellarWP Uplink or PUE product generically, Kadence Blocks Pro, plus any Freemius, EDD Software Licensing or SureCart plugin generically | System → **Licenses** card + health check: valid / expired / invalid / missing per paid component; paste-to-activate for Elementor Pro, ACF PRO, Gravity Forms, Gravity SMTP, Beaver Builder, Brizy Pro, Etch, Bricks and Divi (active theme; Divi takes username + API key), WPMU DEV, SearchWP, Gravity Perks, Perfmatters, GP Premium, WP All Export Pro, LayerSlider, all four The Events Calendar products and Kadence Blocks Pro, deactivate and re-verify where each vendor's code allows, and an "Activate ↗" link for portal- or admin-context-bound vendors (WPBakery, Rank Math, Envato, WP All Import, Slider Revolution), all through each vendor's own code; open via `minn_admin_license_providers` |
| **Site visibility** | WP Maintenance Mode, SeedProd, Under Construction, Password Protected, WooCommerce coming soon (incl. the store-pages-only partial shape), Elementor maintenance mode, plus Minn's own maintenance mode and the `blog_public` "discourage search engines" setting | Overview banner + persistent amber topbar chip (on every route) + System health check when the site is hidden, partly hidden, password-gated or unindexed; Settings → Visibility lists active third-party limiters; open via `minn_admin_visibility_providers` |
| **Page builders** | Elementor, Beaver Builder, Brizy, Divi, Bricks, WPBakery, Etch | Detected, fenced, "Edit in ⟨builder⟩" |
| **Block libraries** | Stackable, Kadence, GenerateBlocks | Design library in the editor's Browse-all; open to any plugin via `minn_admin_design_sources` |
| **Block previews** | Otter, Essential Blocks, Spectra, Kadence, GenerateBlocks, Stackable | Real front-end styling in island previews |
| **Performance** | Perfmatters | **Performance** surface (settings-only): its whole settings estate (General, JavaScript, CSS, Code, Preload, Lazy Loading, Fonts, CDN, Analytics) rendered from its live core-Settings-API registrations, saved through its own sanitizer; the few bespoke fields (input rows, font subsets) count as locked with a wp-admin link. Its license was already in the Licenses card |
| **Dev tools** | Query Monitor | QM panel on Minn pages |
| **Users** | User Switching | "Switch to this user" in the users row menu (the plugin's own nonce URLs) |
| **Media** | Regenerate Thumbnails | ↻ Thumbnails button on the media detail modal (per-image full rebuild) |
| **Order documents** | PDF Invoices & Packing Slips for WooCommerce | Download buttons per enabled document on the order detail modal |

Beyond the named plugins: any plugin's standalone dynamic blocks and
registered patterns appear in the editor automatically (no adapter), and
any plugin's **admin notices** are extracted into Minn's notification
panel. Third-party analytics, cache, forms and other plugins can register
themselves through the extension filters.

## Notes and limits

- **One provider per family shows at a time.** The Email Log, Redirects,
  Activity Log and Snippets surfaces collapse multiple plugins into one nav
  item with a provider switcher when more than one is active.
- **SEO is one plugin at a time**, in install-base order (Yoast → Rank Math
  → AIOSEO → SEOPress); the first active one wins. SEO *scores* and content
  analysis stay in wp-admin.
- **Backups**: restores stay in wp-admin (surgery, not daily work); Minn
  lists sets, reports freshness, and triggers a new backup.
- **Disembark is a connector, not a scheduler.** Backups are pulled off-site
  by its CLI (or disembark.host), and the site keeps no record that a pull
  completed, so Minn never claims freshness for it. The surface shows the
  backup profile (last scan, database size, working files on disk), hands
  over the exact `disembark connect` command (also in ⌘K as "Copy Disembark
  backup command"), lists scan sessions, and cleans up the whole-site
  archives sessions can leave in uploads. The scan itself runs from
  Disembark's own UI or CLI.
- **Contact Form 7 stores nothing itself** — entries need a storage plugin.
  Minn covers both popular ones: Flamingo (spam/unspam and trash through
  Flamingo's own handlers, CF7 forms in the Manage view) and CFDB7 (entries
  parsed from its serialized rows without ever running `unserialize`,
  open-marks-read, permanent delete). Building forms stays in CF7's editor.
- **Page builders** that store content outside `post_content` (Elementor,
  Beaver, Brizy, Bricks, WPBakery) open read-only in Minn's editor with an
  "Edit in ⟨builder⟩" button; block-native builders (Etch, Divi 5) stay
  editable through the island system.
- **What Minn deliberately doesn't reimplement**: form builders, SEO score
  UIs, firewall/scan config, cache plugin settings pages, builder canvases.
  Those are each plugin's product; Minn links out.

## Roadmap candidates

Refreshed 2026-07-10 against the wp.org top-500 by active installs. The
pattern that falls out: the highest-value next wave is almost entirely
**providers into surfaces that already exist**, not new machinery. Waves in
recommended order (installs × fit × effort):

1. **License visibility (Phase 0)** — ✅ shipped 2026-07-10: the System page's
   Licenses card classifies every paid component's license from stored state,
   no network calls, no seat risk (see the coverage table above and
   `docs/license-manager.md`). The activation vault remains Phase 1.
2. **Security posture rows** — Wordfence firewall mode + last scan + issue
   count (5M installs, reads `wfConfig`/`wfIssues`, extends the adapter Minn
   already ships) and Really Simple SSL (3M, pure options read) as System
   health rows. Limit Login Attempts Reloaded ✅ shipped (v0.12.0 cycle):
   lockout log in the Activity Log family with status card and Unlock.
   Follow with Solid Security (now listed as **Kadence Security** on
   wp.org; settings in `itsec-storage`, lockouts in
   `itsec_logs`/`itsec_lockouts`) and All-In-One Security.
3. **Forms providers** — Ninja Forms (`nf3_*` tables), Forminator
   (`frmt_form_entry*`) and Formidable (`frm_items`), all storing entries in
   their free tiers, into the existing Forms surface. SureForms and MetForm
   likely fit too (free-tier storage believed but not source-verified).
4. **Backups providers** — Duplicator ✅ shipped (v0.12.0 cycle: package
   list, status card, delete via its own cleanup; no freshness claims).
   Remaining: WPvivid (`wpvivid_backup_list` option, free tier schedules so
   freshness is claimable), BackWPup, and an All-in-One WP Migration
   local-exports listing (never claim freshness; the Disembark precedent).
5. **Cache purge pack** — SpeedyCache, Redis Object Cache (flush + drop-in
   status row), Breeze, Nginx Helper, Cloudflare. The cheapest shape in the
   codebase: one purge hook plus detection each.
6. **Email log providers** — WP Mail Logging ✅ shipped (v0.12.0 cycle:
   list, detail, resend through its own resender service, delete).
   Remaining: GoSMTP (logs free), SureMails, Site Mailer; Easy WP SMTP's
   full log is Pro-only (free has debug events, the WP Mail SMTP shape).
7. **Snippets providers** — Simple Custom CSS & JS (a CPT) and Header Footer
   Code Manager (`hfcm_scripts` table) into the existing Snippets surface.
8. **Site-status rows with toggles** — ✅ shipped (v0.11.0 cycle): the
   visibility posture (banner + chip + popover toggles + System check) covers
   WP Maintenance Mode, SeedProd, Under Construction, Password Protected,
   WooCommerce coming soon (partial-aware for store-pages-only) and Elementor
   maintenance mode; the login URL row honors login-hiders.
9. **Small delights** — ✅ shipped (v0.11.0 cycle): User Switching
   ("Switch to this user" from the user row via its own nonce URLs),
   Regenerate Thumbnails on the media detail, WooCommerce PDF Invoices
   downloads on the order detail, WP Armour in the spam provider cards,
   SiteSEO in the SEO panel (SEOPress fork, own `_siteseo_` meta prefix)
   and 301 Redirects (WebFactory) in the Redirects family.
10. **Bigger scoped bets** — WPForms Pro entries (source-verified: Lite
    stores no entries at all, so this costs a license and Pro fixtures;
    biggest uncovered name), Jetpack Stats as a traffic provider (data lives
    on WordPress.com behind its connection auth; scope to stats only),
    Meta Box editor panel (runtime field discovery; ACF precedent), Matomo
    traffic provider (small base but the best-behaved local analytics data
    source), The Events Calendar editor panel (events already list natively;
    the panel covers date/venue meta), and **ecommerce analytics** (Austin,
    2026-07-11): an Analytics view alongside Orders, pill-style switcher on
    the Orders surface. WooCommerce ships the data over its own
    `wc-analytics` REST namespace (revenue/orders/products stats the Woo
    Admin dashboard uses), so the read layer is free; the build cost is the
    charting UI. Deserves its own cycle rather than riding an Orders
    change; the pill switcher lands with it.

Parked as structural: **multilingual** (WPML / Polylang / TranslatePress)
needs a language dimension in content lists. Also parked, with scope and
boundaries drawn in `docs/native-editors.md`: **native editors over clean
documents** (the Gravity Forms "80% form editor") and **developer surfaces
over site primitives** (read-only database viewer, file browsing).

Explicitly skip (link-out or nothing is the honest answer): image optimizers
(Smush, EWWW, Imagify, ShortPixel: background processors), consent/GDPR
banners, popups/sliders/optin (canvases and SaaS dashboards; popups are CPTs
so they list anyway), email marketing platforms (MailPoet, MC4WP), one-shot
migration tools (importers, Better Search Replace), remote-management agents
(ManageWP, MainWP), file managers, Elementor addon packs (already fenced via
the Elementor adapter), duplicate-post plugins (Minn's native Duplicate
supersedes them), and Classic Editor/Widgets (Minn's classic mode already
handles the storage reality).

See `docs/for-plugin-authors.md` to add coverage from your own plugin, and
`docs/extension-api.md` for the surface/panel/provider contracts.
