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
| **Forms** | Gravity Forms, Fluent Forms, Elementor Pro, Contact Form 7 (via Flamingo), CFDB7, Ninja Forms, Forminator, Formidable, Everest Forms | **Forms** surface — entries as contact cards. Providers with status workflows (Everest, Fluent, Ninja, Flamingo, Elementor Pro) share Received/Spam-or-Unread/Trash filters with restore and bulk; CFDB7 filters All/Unread/Read. Gravity Forms adds the full entry workflow through its own endpoints: Received/Spam/Trash status views, star/unstar and mark-read (open marks read like GF's own screen), restore and delete-permanently where they apply, bulk actions, entry notes on the card plus add-a-note, and resend notifications. A **Notifications** view (edit-forms capability, via GF's own resolver) lists every notification across forms with type-aware recipients (address / field label / routing rule count), activate-deactivate through GF's own toggle, and daily-field editing (name, send-to, subject, message) through GF's own notifications store; routing rules, conditional logic and events stay in GF's editor, one deep link away. Each form's row opens **Form settings**: the whole form-settings estate (basics, layout, save-and-continue, restrictions, spam detection, options) drawn at request time from GF's own Settings-framework schema and saved through `GFAPI::update_form` with GF's own validation semantics; schedule date-times stay in GF, honestly counted as locked |
| **Email** | Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging | **Email** surface (renamed from Email Log once it grew settings) — sent mail, resend. FluentSMTP, Post SMTP and WP Mail Logging all have status cards (14-day charts); FluentSMTP also has test send; WPML bulk-deletes log rows. Gravity SMTP goes deeper: a **Settings** view maps its own settings schema into Minn (sending service across all 21 connectors, connector config with masked secrets, general/logging settings through its constant-lock-aware stores), the surface honors its granular `gravitysmtp_*` capabilities, the event detail reads through its own models (from/cc/bcc/source), resend replays its own recipient handling through the configured connector, a **Suppressions** view lists/adds/reactivates blocked addresses through its own model, a **Debug log** view, a **Routing** view of 2.3+ conditional send rules (enable/disable/delete; condition authoring stays in Gravity SMTP), a **Filtered** log tab for partially-sent events, and a status card with active service, test mode, routing counts, a 14-day chart, and **Send a test email** |
| **Redirects** | Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects (WebFactory) | **Redirects** surface — list + in-place edit + bulk delete; Redirection's first-run install runs in place via the setup gate, and its daily options (monitor, log retention, IP logging) live in a Settings view through its own `red_set_options` |
| **Activity log** | Simple History, WP Activity Log, Aryo, Stream, **Wordfence**, **Limit Login Attempts Reloaded**, **Solid Security** | **Activity Log** surface — severity/level tabs (Simple History, WSAL), connector tabs (Stream), action tabs (Aryo); Wordfence = login security with a status card (24h failures + firewall/scan); Limit Login Attempts and Solid Security = lockout logs with status cards and one-click Unlock/Release through each plugin's own store |
| **Security posture** | Wordfence, Really Simple SSL, Solid Security | System health rows: Wordfence firewall mode (enabled / learning / off) + last scan and unresolved-issue count; Really Simple SSL enforcement status (both read through each plugin's own public APIs). The System page's **Login URL** row uses `wp_login_url()`, so it honors login-hiders (WPS Hide Login and friends) rather than assuming wp-login.php |
| **Snippets** | Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS, Header Footer Code Manager | **Snippets** surface — list, toggle, edit, create, bulk (provider switcher when more than one is active) |
| **Analytics** | Koko, WP Statistics, Burst, Independent Analytics, AnalyticsWP, **Site Kit** | Overview **Traffic** chart (daily visitors/pageviews). Day-click drill-down (top pages + referrers via `minn_admin_traffic_day`): **Koko** and **WP Statistics** (WPS pages table stores hits only, so vis/views both report that total) |
| **Backups** | UpdraftPlus, Disembark, Duplicator, WPvivid, BackWPup, All-in-One WP Migration | **Backups** surface; health check + "Back up now" (UpdraftPlus, else WPvivid); status card, CLI command, sessions + cleanup (Disembark); package list with disk sizes, status card and delete-through-its-own-cleanup (Duplicator, no freshness claims: manual builds); backup list + status card + schedule + backup-now + delete-through-its-own-cleanup (WPvivid); local FOLDER archives + run-job-now + delete through their destination (BackWPup); local .wpress export list + delete through their Backups model, no freshness claims (All-in-One WP Migration; export/import stay deep links) |
| **Caching** | Kinsta, LiteSpeed, WP Super Cache, W3TC, WP Rocket, WP Fastest Cache, SiteGround, Autoptimize, WP-Optimize, Cache Enabler, Hummingbird, Elementor CSS, SpeedyCache, Redis Object Cache, Breeze, Nginx Helper, Cloudflare | **Clear site cache** action (⌘K). Redis Object Cache also adds a System health row for drop-in + connection posture |
| **Custom fields** | ACF (+ Pro) | Editor panel |
| **Ecommerce** | WooCommerce | **Orders** surface + Overview stats |
| **Spam filtering** | Akismet, Antispam Bee, CleanTalk, WP Armour | Settings → Spam provider cards; open via `minn_admin_spam_providers` |
| **Licenses** | Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Gravity SMTP, AnalyticsWP, Bricks, Divi, Beaver Builder, WPBakery, Brizy, Etch, Astra/Brainstorm family, WPMU DEV (Dashboard + Smush Pro), SearchWP, Gravity Perks, Rank Math Pro, Perfmatters, GP Premium, WP All Import/Export Pro, Slider Revolution, LayerSlider, Avada, Envato Market, The Events Calendar family (Pro, Event Tickets Plus, Filter Bar, Community, each a dedicated provider) + any other StellarWP Uplink or PUE product generically, Kadence Blocks Pro, plus any Freemius, EDD Software Licensing or SureCart plugin generically | Extensions → **Licenses** tab (grouped by state, inactive components collapsed, actions in a per-row menu; the System health check is the clickable doorway): valid / expired / invalid / missing per paid component; paste-to-activate for Elementor Pro, ACF PRO, Gravity Forms, Gravity SMTP, Beaver Builder, Brizy Pro, Etch, Bricks and Divi (active theme; Divi takes username + API key), WPMU DEV, SearchWP, Gravity Perks, Perfmatters, GP Premium, WP All Export Pro, LayerSlider, all four The Events Calendar products and Kadence Blocks Pro, deactivate and re-verify where each vendor's code allows, and an "Activate ↗" link for portal- or admin-context-bound vendors (WPBakery, Rank Math, Envato, WP All Import, Slider Revolution), all through each vendor's own code; open via `minn_admin_license_providers` |
| **Site visibility** | WP Maintenance Mode, SeedProd, Under Construction, Password Protected, WooCommerce coming soon (incl. the store-pages-only partial shape), Elementor maintenance mode, plus Minn's own maintenance mode and the `blog_public` "discourage search engines" setting | Overview banner + persistent amber topbar chip (on every route) + System health check when the site is hidden, partly hidden, password-gated or unindexed; Settings → Visibility lists active third-party limiters; open via `minn_admin_visibility_providers` |
| **Page builders** | Elementor, Beaver Builder, Brizy, Divi, Bricks, WPBakery, Etch | Detected, fenced, "Edit in ⟨builder⟩" |
| **Block libraries** | Stackable, Kadence, GenerateBlocks | Design library in the editor's Browse-all; open to any plugin via `minn_admin_design_sources` |
| **Block previews** | Otter, Essential Blocks, Spectra, Kadence, GenerateBlocks, Stackable | Real front-end styling in island previews |
| **Performance** | Perfmatters | **Performance** surface (settings-only): its whole settings estate (General, JavaScript, CSS, Code, Preload, Lazy Loading, Fonts, CDN, Analytics) rendered from its live core-Settings-API registrations, saved through its own sanitizer; the few bespoke fields (input rows, font subsets) count as locked with a wp-admin link. Its license was already in the Licenses card |
| **Dev tools** | Query Monitor | QM panel on Minn pages |
| **Users** | User Switching, One Time Login | "Switch to this user" in the users row menu (the plugin's own nonce URLs), plus a Switch-back bar for a switched session; "Copy one-time login link" mints a single-use login-as link through One Time Login's own token generator (that CLI-only plugin's first UI), gated on `edit_user` for the target |
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

Refreshed 2026-07-12 during the v0.13.0 cycle (originally ranked 2026-07-10
against the wp.org top-500 by active installs; re-checked the same day after
the forms opener and surface-toolbar polish landed). The 2026-07-10 wave list
is mostly drained: license visibility and activation, the lockout logs, Ninja
Forms / Forminator / Formidable / Everest Forms, Duplicator, WP Mail Logging, the visibility
posture and the small-delights wave all shipped across v0.11.0–v0.13.0 and
live in the coverage table above now. v0.13.0 so far also shipped surface
`views[]`, item-scoped settings, GF form settings + notifications, the
Gravity SMTP debug log, and calmer surface toolbars (see changelog). What
remains, re-ranked (installs × fit × effort):

1. **Email log providers** — GoSMTP (logs free), SureMails, Site Mailer;
   Easy WP SMTP's full log is Pro-only (free has debug events, the
   WP Mail SMTP shape).
2. **Security leftover** — All-In-One Security (activity-log family +
   posture row; the LLA-R / Solid Security pattern).
3. **Forms leftovers** — SureForms and MetForm (free-tier entry storage
   believed but not source-verified; verify before promising).
4. **Bigger scoped bets** — WPForms Pro entries (source-verified: Lite
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
   charting UI. Deserves its own cycle rather than riding an Orders change;
   the pill switcher lands with it — and it pairs naturally with the Rung-3
   chart row type (`docs/full-ui-adapters.md`): build Minn's charting once
   and both consume it.

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
`docs/extension-api.md` for the surface/panel/provider contracts. For the
primitive-by-adapter matrix (status cards, views, settings depth) used by
adapter sweeps, see `docs/adapter-coverage.md`.
