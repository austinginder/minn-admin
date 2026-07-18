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
| **Events** | The Events Calendar | Events are a REST CPT, so the Content list and Minn editor already carry them; the **Event details** editor panel covers start/end, all-day, venue and organizer (async-search pickers over TEC's own records), cost and website, all written through TEC's own saveEventMeta (duration, UTC mirrors and linked-post bookkeeping stay TEC's). Multiple organizers, recurrence, tickets, timezone and venue/organizer creation stay in TEC |
| **Jobs** | WP Job Manager | Listings are a REST CPT, so the Content list and Minn editor already carry them; the **Job listing** editor panel adds the details estate (location, company fields, application email or URL, salary, remote/filled/featured flags, expiry) read live from WPJM's own field schema, with WPJM's own per-field sanitizers ruling every write |
| **Podcasting** | Seriously Simple Podcasting, PowerPress | SSP: episodes are a REST CPT, so the Content list and Minn editor already carry them; the **Podcast episode** editor panel adds the whole episode-detail estate (file URL, audio/video type, duration, file size, date recorded, explicit and block flags, the iTunes fields) read live from SSP's own schema and stored in its own conventions (cover image and Castos hosting sync stay SSP's). PowerPress: the same panel on plain posts for the default channel (media URL, size, duration, subtitle, Apple episode fields), rebuilding its enclosure blob diff-based so hosting, chapters and artwork keys survive untouched; custom channels, artwork, explicit and chapters stay on its metabox |
| **Forms** | Gravity Forms, Fluent Forms, Elementor Pro, Contact Form 7 (via Flamingo), CFDB7, Ninja Forms, Forminator, Formidable, Everest Forms | **Forms** surface — entries as contact cards. Providers with status workflows (Everest, Fluent, Ninja, Flamingo, Elementor Pro) share Received/Spam-or-Unread/Trash filters with restore and bulk; CFDB7 filters All/Unread/Read. Gravity Forms adds the full entry workflow through its own endpoints: Received/Spam/Trash status views, star/unstar and mark-read (open marks read like GF's own screen), restore and delete-permanently where they apply, bulk actions, entry notes on the card plus add-a-note, and resend notifications. A **Notifications** view (edit-forms capability, via GF's own resolver) lists every notification across forms with type-aware recipients (address / field label / routing rule count), activate-deactivate through GF's own toggle, and daily-field editing (name, send-to, subject, message) through GF's own notifications store; routing rules, conditional logic and events stay in GF's editor, one deep link away. Each form's row opens **Form settings**: the whole form-settings estate (basics, layout, save-and-continue, restrictions, spam detection, options) drawn at request time from GF's own Settings-framework schema and saved through `GFAPI::update_form` with GF's own validation semantics; schedule date-times stay in GF, honestly counted as locked. A **Feeds** view (shown while a feed add-on is registered) lists every add-on integration across forms with activate, deactivate and delete through GF's own model; feed configuration deep-links to the add-on's screen |
| **Email** | Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging | **Email** surface (renamed from Email Log once it grew settings) — sent mail, resend, single/bulk log delete. FluentSMTP, Post SMTP and WP Mail Logging all have status cards (14-day charts); FluentSMTP also has test send, subject/from/to search, single/bulk log delete through its Logger, and a **Settings** view (default and fallback connection, logging, retention, email simulation through its own Settings model; the connection wizard stays FluentSMTP's); Post SMTP has search + single/bulk delete; WPML bulk-deletes log rows. Gravity SMTP goes deeper: a **Settings** view maps its own settings schema into Minn (sending service across all 21 connectors, connector config with masked secrets, general/logging settings through its constant-lock-aware stores), the surface honors its granular `gravitysmtp_*` capabilities (including `DELETE_EMAIL_LOG` for log delete through its own `Event_Model`), the event detail reads through its own models (from/cc/bcc/source), resend replays its own recipient handling through the configured connector, a **Suppressions** view lists/adds/reactivates blocked addresses through its own model, a **Debug log** view, a **Routing** view of 2.3+ conditional send rules (enable/disable/delete; condition authoring stays in Gravity SMTP), a **Filtered** log tab for partially-sent events, and a status card with active service, test mode, routing counts, a 14-day chart, and **Send a test email** |
| **Redirects** | Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects (WebFactory) | **Redirects** surface — list + in-place edit + bulk delete; Redirection's first-run install runs in place via the setup gate, its daily options (monitor, log retention, IP logging) live in a Settings view through its own `red_set_options`, and a status card leads the surface (rules, hits, served/404 counts and a stacked 14-day chart from its log tables) |
| **Activity log** | Simple History, WP Activity Log, Aryo, Stream, **Wordfence**, **Limit Login Attempts Reloaded**, **Solid Security** | **Activity Log** surface — severity/level tabs (Simple History, WSAL), connector tabs (Stream), action tabs (Aryo); every provider has a **status card** (audit logs: 24h / 7d / all-time + last event and a family-specific mix; Wordfence: 24h logins + firewall/scan posture; Limit Login Attempts and Solid Security: lockouts now + policy/protection, with one-click Unlock/Release through each plugin's own store) |
| **Security posture** | Wordfence, Really Simple SSL, Solid Security | System health rows: Wordfence firewall mode (enabled / learning / off) + last scan and unresolved-issue count; Really Simple SSL enforcement status (both read through each plugin's own public APIs). The System page's **Login URL** row uses `wp_login_url()`, so it honors login-hiders (WPS Hide Login and friends) rather than assuming wp-login.php |
| **Snippets** | Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS, Header Footer Code Manager | **Snippets** surface — list, toggle, edit, create, bulk (provider switcher when more than one is active) |
| **Analytics** | Koko, WP Statistics, Burst, Independent Analytics, AnalyticsWP, **Site Kit** | Overview **Traffic** chart (daily visitors/pageviews). Day-click drill-down (top pages + referrers via `minn_admin_traffic_day`): **Koko**, **WP Statistics** (hits only per URI), **Burst** (`page_url` + session referrers), **Independent Analytics** (views × resources + session referrers) |
| **Backups** | UpdraftPlus, Disembark, Duplicator, WPvivid, BackWPup, All-in-One WP Migration | **Backups** surface; health check + "Back up now" (UpdraftPlus, else WPvivid); status card, CLI command, sessions + cleanup (Disembark); package list with disk sizes, status card and delete-through-its-own-cleanup (Duplicator, no freshness claims: manual builds); backup list + status card + schedule + backup-now + delete-through-its-own-cleanup (WPvivid); local FOLDER archives + run-job-now + delete through their destination (BackWPup); local .wpress export list + delete through their Backups model, no freshness claims (All-in-One WP Migration; export/import stay deep links) |
| **Caching** | Kinsta, LiteSpeed, WP Super Cache, W3TC, WP Rocket, WP Fastest Cache, SiteGround, Autoptimize, WP-Optimize, Cache Enabler, Hummingbird, Elementor CSS, SpeedyCache, Redis Object Cache, Breeze, Nginx Helper, Cloudflare | **Clear site cache** action (⌘K). Redis Object Cache also adds a System health row for drop-in + connection posture |
| **Custom fields** | ACF (+ Pro), Meta Box, Pods | Editor panel (text, textarea, number, select, radio, checkbox/switch/boolean). ACF needs "Show in REST API" on the field group; Meta Box values ride a `minn_meta_box` REST field (`rwmb_set_meta`); Pods values ride `minn_pods` (`pods()->save()` on extended post types). Advanced types (clones, file, relationships, multi-pick…) count as locked with a wp-admin link |
| **Ecommerce** | WooCommerce, **WooCommerce Subscriptions** | **Orders** (list, search, status, refunds, pay URL, resend/custom email, order notes, **New order**, **Analytics** view with revenue chart + top products via `wc-analytics`) + **Products** (list, search, stock tabs incl. Low stock, bulk, daily fields, **Add product**) + **Coupons** (list/create/edit) + **Customers** (list/search, profile + billing, recent orders; **Subscriptions** strip when WCS is active) + Overview stats. **Subscriptions** (when WCS is active): status tabs, search, next payment, period label, status save through `wc/v3/subscriptions`, parent order + related orders, View customer, and a reverse link from the order modal. Product, coupon and subscription CPTs are fenced out of Content |
| **Spam filtering** | Akismet, Antispam Bee, CleanTalk, WP Armour | Settings → Spam provider cards; open via `minn_admin_spam_providers` |
| **Licenses** | Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Gravity SMTP, AnalyticsWP, Bricks, Divi, Beaver Builder, WPBakery, Brizy, Etch, Astra/Brainstorm family, WPMU DEV (Dashboard + Smush Pro), SearchWP, Gravity Perks, Rank Math Pro, Perfmatters, GP Premium, WP All Import/Export Pro, Slider Revolution, LayerSlider, Avada, Envato Market, The Events Calendar family (Pro, Event Tickets Plus, Filter Bar, Community, each a dedicated provider) + any other StellarWP Uplink or PUE product generically, Kadence Blocks Pro, Smash Balloon (Instagram / Facebook / YouTube / Twitter / Social Wall / Reviews / TikTok / Feed Analytics Pro, including All Plugins multi-product keys), Yoast SEO Premium (MyYoast portal), plus any Freemius, EDD Software Licensing or SureCart plugin generically | Extensions → **Licenses** tab (grouped by state, inactive components collapsed, actions in a per-row menu; the System health check is the clickable doorway): valid / expired / invalid / missing per paid component; paste-to-activate for Elementor Pro, ACF PRO, Gravity Forms, Gravity SMTP, Beaver Builder, Brizy Pro, Etch, Bricks and Divi (active theme; Divi takes username + API key), WPMU DEV, SearchWP, Gravity Perks, Perfmatters, GP Premium, WP All Export Pro, LayerSlider, all four The Events Calendar products and Kadence Blocks Pro, deactivate and re-verify where each vendor's code allows, and an "Activate ↗" link for portal- or admin-context-bound vendors (WPBakery, Rank Math, Envato, WP All Import, Slider Revolution), all through each vendor's own code; open via `minn_admin_license_providers` |
| **Site visibility** | WP Maintenance Mode, SeedProd, Under Construction, Password Protected, WooCommerce coming soon (incl. the store-pages-only partial shape), Elementor maintenance mode, plus Minn's own maintenance mode and the `blog_public` "discourage search engines" setting | Overview banner + persistent amber topbar chip (on every route) + System health check when the site is hidden, partly hidden, password-gated or unindexed; Settings → Visibility lists active third-party limiters; open via `minn_admin_visibility_providers` |
| **Page builders** | Elementor, Beaver Builder, Brizy, Divi, Bricks, WPBakery, Etch | Detected, fenced, "Edit in ⟨builder⟩" |
| **Block libraries** | Stackable, Kadence, GenerateBlocks | Design library in the editor's Browse-all; open to any plugin via `minn_admin_design_sources` |
| **Block previews** | Otter, Essential Blocks, Spectra, Kadence, GenerateBlocks, Stackable | Real front-end styling in island previews |
| **Performance** | Perfmatters, Autoptimize, Asset CleanUp, Performance Lab | One **Performance** Tools item with a provider switcher. **Perfmatters** (settings-only): whole estate from its live core-Settings-API registrations. **Autoptimize** (settings-only): JS / CSS / HTML / CDN / Misc toggles written as its own `on`/empty options (Critical CSS, Extra and Image stay deep-linked; Clear site cache still purges its cache). **Asset CleanUp** (settings-only): global minify/combine/cleanup/fonts/test-mode toggles via its JSON settings option; the page-level CSS/JS unload manager stays in Asset CleanUp. **Performance Lab**: list of WordPress Performance Team standalone features (activate through its own install helper, deactivate through core); Server Timing and per-feature settings stay deep-linked |
| **Dev tools** | Query Monitor; **Diagnostics** family (Scrutoscope, WP Crontrol, Transients Manager, Rewrite Rules Inspector) | QM panel on Minn pages (this-request). One Tools item **Diagnostics** with a provider switcher: **Scrutoscope** (performance profiles + attribution Cron view), **WP Crontrol** (event inventory, run-now, pause/resume, delete), **Transients Manager** (list/search/delete, expired purge, never unserializes blobs), **Rewrite Rules Inspector** (registered rules by source, search by path, flush, test URL). Capture settings, PHP/URL cron authoring, deep transient edit, and the full RRI screen stay deep-linked |
| **Users** | User Switching, One Time Login | "Switch to this user" in the users row menu (the plugin's own nonce URLs), plus a Switch-back bar for a switched session; "Copy one-time login link" mints a single-use login-as link through One Time Login's own token generator (that CLI-only plugin's first UI), gated on `edit_user` for the target |
| **Public preview** | Public Post Preview | Editor Publish card: **Public preview link** toggle + copy URL; content row **Copy public preview link** (enables if needed). Shareable anonymous draft links use the plugin's own expiring nonces and Reading expiry setting |
| **Media** | Regenerate Thumbnails, Force Regenerate Thumbnails, Safe SVG, SVG Support, Enable Media Replace, FileBird, Real Media Library, Folders by Premio | ↻ Thumbnails button on the media detail modal (per-image full rebuild; Force Regenerate Thumbnails covers the same button through its own admin-ajax handler when RT is absent). Safe SVG or SVG Support: **SVG** filter tab; detail note names the provider (sanitization claimed only for Safe SVG); sanitization stays the plugin's. Enable Media Replace: **⇅ Replace file** on the detail modal through EMR's own ReplaceController (same name, same URL; same-type enforced; rename-and-move stays on EMR's screen). Folders: FileBird, Real Media Library Lite and Folders by Premio all feed the Media view's folder combobox via the `minn_admin_media_folders` provider contract (browse-first; organizing stays in each plugin's UI) |
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

Refreshed 2026-07-15 mid **v0.16.0** (after v0.15.0 library cycle + Smash /
Yoast license providers + Gravity SMTP bulk log delete). Coverage history
lives in the table above; living primitive matrix + sweep log is
`docs/adapter-coverage.md`.

> **v0.17.0 note (2026-07-16):** adapter waves PAUSE for one cycle. The
> v0.17.0 charter is the plugin-author cycle (developer experience and abuse
> resistance on the road to v1.0) — see `docs/v1-readiness.md`. The waves
> below resume afterwards.

### Wave A — Dev tools ✅ complete (v0.14.0)

Diagnostics family ships Scrutoscope, WP Crontrol, Transients Manager, and
Rewrite Rules Inspector as one Tools item (provider switcher). **QM stays a
panel, not a surface.** Native developer surfaces (read-only DB viewer)
remain scoped in `docs/native-editors.md` (parked).

### Wave B — leftover providers (existing families)

Source-verified 2026-07-17 (installed all four on minnadmin):

1. ~~**Email log providers** — SureMails + Site Mailer~~ **SHIPPED
   2026-07-17**: both mail-family adapters over their free log tables
   ({prefix}suremails_email_log and site_mail_logs), full treatment
   (list/tabs/search/delete/status+chart/sections detail with the
   sandboxed HTML preview). **GoSMTP SKIPPED**: its `\GOSMTP\Logger` is
   Pro-only and the free build stores no logs (the WP Mail SMTP-free
   pattern). Easy WP SMTP's full log is Pro-only too (free has debug
   events). NOTE: both created_at columns ride the DB session timezone
   (UTC on managed hosts, site-local on Cove dev); the shared
   `minn_admin_db_local_to_utc_iso()` helper normalizes at runtime.
2. ~~**Security leftover** — All-In-One Security~~ **SHIPPED 2026-07-17**:
   activity-log audit feed ({base_prefix}aiowps_audit_log; JSON details
   flattened to Context rows; level tabs + search + status card;
   installed-inactive per family convention). A failed-login /
   permanent-block posture row is a future add.
3. **Forms leftovers** — **SureForms SHIPPED 2026-07-17** (verified:
   {prefix}srfm_entries, form_data is clean JSON keyed by field label,
   read/unread/trash status, sureforms_form CPT for tabs; full
   forms-family adapter). **MetForm DEFERRED**: it stores entries as a
   `metform-entry` CPT with per-field post meta, but field labels come
   from parsing the Elementor form widget (needs Elementor active and
   widget-tree resolution) — a different effort class than SureForms'
   flat JSON. Revisit as its own unit when Elementor-dependent adapters
   are on the table.

### Wave C — bigger scoped bets (own cycle or half-cycle)

4. ~~**Ecommerce analytics**~~ ✅ shipped (v0.14.0) on Orders as an
   **Analytics** pill; Customers, New order, Add product, Subscriptions
   also landed in the commerce cycle.
5. **WPForms Pro entries** — Lite stores no entries; needs Pro license +
   fixtures; biggest uncovered forms name.
6. ~~**Meta Box** editor panel~~ ✅ shipped (v0.15.0). **The Events Calendar**
   editor panel (date/venue meta), **Jetpack Stats** / **Matomo** traffic
   providers (auth and data-shape study first).

### Wave D — Media management (researched 2026-07-16, wp.org installs live)

Minn's media core is already caught up (caption/description, bulk delete,
image editor, Regenerate Thumbnails + Safe SVG wired), so this wave is
adapters plus one new primitive, ranked:

7. **Enable Media Replace** (600k) — the best single pick: a "Replace
   file…" action on the media detail modal through their own handler, URL
   preserved. Small, daily-ops, no canvas to rebuild.
8. **Core media polish** (no plugin) — unattached + date filters and
   "attached to" info, already ranked in `docs/core-gaps.md`. Serves every
   install.
9. **Media folders provider contract, browse-first** — **SHIPPED 2026-07-17**
   (v0.18.0 cycle): the `minn_admin_media_folders` filter feeds a folder
   combobox in the Media toolbar (folder → attachment-ids shim → `include=`
   on wp/v2/media, newest-500 cap, reserved id 0 = Uncategorized), FileBird
   bundled through its own model (per-user mode honored), Real Media
   Library Lite through its wp_rml_* API, and Folders by Premio through its
   media_folder taxonomy, suite `media-folders` (20). The "Move to folder"
   action shipped the same day: an optional `move` callable on the contract
   drives a folder picker + Move on the media bulk bar, wired through each
   plugin's own assign machinery. Original ranking: FileBird first (200k,
   custom `fbv` tables, clean model class); Real Media Library Lite (100k)
   and Folders by Premio (90k) join the same contract like the SEO panel's
   providers. This REVISES core-gaps' "folders: long-tail, skip": 400k+
   combined installs, and it extends the existing Media view rather than
   needing a new surface. NEVER a Minn-owned folder tree: a fifth folder
   standard invisible to wp-admin and page-builder pickers contradicts the
   thesis (core owns universal primitives, plugins own opinions).
10. **Parity crumbs** — **SHIPPED 2026-07-17**: SVG Support (1M) joins the
    Safe SVG gate for the SVG filter tab (`svgProvider` boot key names the
    plugin; the detail note claims sanitization only for Safe SVG); Force
    Regenerate Thumbnails (200k) joins the ↻ Thumbnails action through its
    own admin-ajax handler and nonce (boot key `frt`; RT wins when both are
    active). Wave D is COMPLETE except the optimizer one-liners and the
    deliberate skips below.

Still skipped, deliberately: the optimizers (Smush / EWWW / Imagify 1M
each, Converter for Media 500k, ShortPixel 300k, Optimole 200k) are
background processors with canvas dashboards (at most a one-line
"optimized by X" note on media detail someday); Media Cleaner (90k) is a
destructive scan tool that deserves its own full-attention UI; Media
Library Assistant (70k) and the renamers (50k and down) are power-tool
long tail.

### Licenses fleet (see `docs/license-manager.md`)

~~Smash Balloon~~ and ~~Yoast SEO Premium~~ shipped 2026-07-15. Remaining
fleet-ranked open work is mostly long-tail Freemius/EDD verification and
Admin Columns Pro (reader already covers it).

### Axis A leftovers (adapter depth, not new plugins)

From `docs/adapter-coverage.md` and `docs/full-ui-adapters.md` (2026-07-15):

- ~~Gravity SMTP bulk log delete~~ ✅ shipped (mail reference parity with
  FluentSMTP / Post SMTP / WPML).
- ~~Activity-log status cards~~ ✅ shipped (Simple History, WSAL, Stream,
  Aryo; Solid / LLA-R / Wordfence already had them).
- **Richer `sectionsRoute` row types** (`pill`, `code`, `html-preview`,
  `kv-table`) for email-log detail fidelity.
- Status/chart parity on thinner adapters when a family sweep is scheduled
  (`/dev-minn-admin sweep`).

Parked as structural: **multilingual** (WPML / Polylang / TranslatePress)
needs a language dimension in content lists. Also parked, with scope and
boundaries drawn in `docs/native-editors.md`: **native editors over clean
documents** (the Gravity Forms "80% form editor"; prerequisite plumbing
shipped in v0.13.0; dogfood form-management depth before committing).

Explicitly skip (link-out or nothing is the honest answer): image optimizers
(Smush, EWWW, Imagify, ShortPixel: background processors), consent/GDPR
banners, popups/sliders/optin (canvases and SaaS dashboards; popups are CPTs
so they list anyway), email marketing platforms (MailPoet, MC4WP), one-shot
migration tools (importers, Better Search Replace), remote-management agents
(ManageWP, MainWP), file managers, Elementor addon packs (already fenced via
the Elementor adapter), duplicate-post plugins (Minn's native Duplicate
supersedes them), Classic Editor/Widgets (Minn's classic mode already
handles the storage reality), and **Debug Bar** / P3 Profiler (QM and
Scrutoscope supersede).

See `docs/for-plugin-authors.md` for the surface/panel/provider contracts
and to add coverage from your own plugin (`docs/shim-tutorial.md` for the
custom-table walkthrough). For the
primitive-by-adapter matrix (status cards, views, settings depth) used by
adapter sweeps, see `docs/adapter-coverage.md`.
