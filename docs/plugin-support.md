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
| **SEO** | Yoast, Rank Math, AIOSEO, SEOPress, SureRank, SiteSEO, Squirrly | Editor panel (title, meta description, focus keyword; social thumbnail on Rank Math, SureRank and Squirrly) |
| **Events** | The Events Calendar | Events are a REST CPT, so the Content list and Minn editor already carry them; the **Event details** editor panel covers start/end, all-day, venue and organizer (async-search pickers over TEC's own records), cost and website, all written through TEC's own saveEventMeta (duration, UTC mirrors and linked-post bookkeeping stay TEC's). Multiple organizers, recurrence, tickets, timezone and venue/organizer creation stay in TEC |
| **Jobs** | WP Job Manager | Listings are a REST CPT, so the Content list and Minn editor already carry them; the **Job listing** editor panel adds the details estate (location, company fields, application email or URL, salary, remote/filled/featured flags, expiry) read live from WPJM's own field schema, with WPJM's own per-field sanitizers ruling every write |
| **Podcasting** | Seriously Simple Podcasting, PowerPress | SSP: episodes are a REST CPT, so the Content list and Minn editor already carry them; the **Podcast episode** editor panel adds the whole episode-detail estate (file URL, audio/video type, duration, file size, date recorded, explicit and block flags, the iTunes fields) read live from SSP's own schema and stored in its own conventions (cover image and Castos hosting sync stay SSP's). PowerPress: the same panel on plain posts for the default channel (media URL, size, duration, subtitle, Apple episode fields), rebuilding its enclosure blob diff-based so hosting, chapters and artwork keys survive untouched; custom channels, artwork, explicit and chapters stay on its metabox |
| **Bookings** | Amelia, LatePoint, Bookly | **Bookings** surface — upcoming appointments as an inbox (pending / today / canceled filters, search). Opening one goes to its own page in the same two-column shape as an order: service, time and payment on the left, customer and notes on the right, with approve / cancel / no-show through each plugin's own status handler so notifications stay its own (Bookly has no no-show status, so it offers approve / cancel). A status card of today / pending / next, and an Open Amelia / Open LatePoint / Open Bookly deep link. Calendar authoring, employee hours and the booking form stay in the plugin |
| **Forms** | Gravity Forms, WPForms, Fluent Forms, Elementor Pro, Contact Form 7 (via Flamingo), CFDB7, Ninja Forms, Forminator, Formidable, Everest Forms, SureForms | **Forms** surface — entries as contact cards. Providers with status workflows (Everest, Fluent, Ninja, Flamingo, Elementor Pro) share Received/Spam-or-Unread/Trash filters with restore and bulk; CFDB7 filters All/Unread/Read. Fluent Forms, Ninja Forms, Forminator, Flamingo, Everest Forms, SureForms and WPForms open with a status card (unread or received counts, spam and trash where tracked, form count, link to the plugin's entries screen). WPForms (Pro, which is where entries live) gets the full treatment: per-form tabs, unread/read/starred/spam/trash filters, star and read state, spam and trash flows with restore, permanent delete through WPForms' own handler, and entries that mark themselves viewed when opened, all behind WPForms' own access capabilities. Gravity Forms adds the full entry workflow through its own endpoints: Received/Spam/Trash status views, star/unstar and mark-read (open marks read like GF's own screen), restore and delete-permanently where they apply, bulk actions, entry notes on the card plus add-a-note, and resend notifications. A **Notifications** view (edit-forms capability, via GF's own resolver) lists every notification across forms with type-aware recipients (address / field label / routing rule count), activate-deactivate through GF's own toggle, and daily-field editing (name, send-to, subject, message) through GF's own notifications store; routing rules, conditional logic and events stay in GF's editor, one deep link away. Each form's row opens **Form settings**: the whole form-settings estate (basics, layout, save-and-continue, restrictions, spam detection, options) drawn at request time from GF's own Settings-framework schema and saved through `GFAPI::update_form` with GF's own validation semantics; schedule date-times stay in GF, honestly counted as locked. A **Feeds** view (shown while a feed add-on is registered) lists every add-on integration across forms with activate, deactivate and delete through GF's own model; feed configuration deep-links to the add-on's screen. SureForms entries ride its clean JSON table with per-form tabs, read/unread/trash status actions, search, delete and a status card |
| **Email** | Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging, SureMails, Site Mailer | **Email** surface (renamed from Email Log once it grew settings) — sent mail, resend, single/bulk log delete. FluentSMTP, Post SMTP and WP Mail Logging all have status cards (14-day charts); FluentSMTP also has test send, subject/from/to search, single/bulk log delete through its Logger, resend through its own resend flow on 2.3+ (original headers and attachments preserved, a per-entry resend history, and a **Resend to…** recipient override), its daily connection-health verdict on the status card and as a System health row, capabilities through its `fluent_mail/manage_capability` filter, and a **Settings** view (default and fallback connection, logging, retention, email simulation through its own Settings model; the connection wizard stays FluentSMTP's); Post SMTP has search + single/bulk delete; WPML bulk-deletes log rows. Gravity SMTP goes deeper: a **Settings** view maps its own settings schema into Minn (sending service across all 21 connectors, connector config with masked secrets, general/logging settings through its constant-lock-aware stores), the surface honors its granular `gravitysmtp_*` capabilities (including `DELETE_EMAIL_LOG` for log delete through its own `Event_Model`), the event detail reads through its own models (from/cc/bcc/source), resend replays its own recipient handling through the configured connector, a **Suppressions** view lists/adds/reactivates blocked addresses through its own model, a **Debug log** view, a **Routing** view of 2.3+ conditional send rules (enable/disable/delete; condition authoring stays in Gravity SMTP), a **Filtered** log tab for partially-sent events, and a status card with active service, test mode, routing counts, a 14-day chart, and **Send a test email**. SureMails and Site Mailer get the full log treatment over their free log tables (tabs, search, delete, status card + chart, sandboxed HTML detail; Site Mailer is a cloud sender, so no local resend) |
| **Redirects** | Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects (WebFactory) | **Redirects** surface — list + in-place edit + bulk delete; Redirection's first-run install runs in place via the setup gate, its daily options (monitor, log retention, IP logging) live in a Settings view through its own `red_set_options`, and a status card leads the surface (rules, hits, served/404 counts and a stacked 14-day chart from its log tables) |
| **Activity log** | Simple History, WP Activity Log, Aryo, Stream, **Wordfence**, **Limit Login Attempts Reloaded**, **Solid Security**, **All-In-One Security** | **Activity Log** surface — severity/level tabs (Simple History, WSAL, AIOS), connector tabs (Stream), action tabs (Aryo); every provider has a **status card** (audit logs: 24h / 7d / all-time + last event and a family-specific mix; Wordfence: 24h logins + firewall/scan posture; Limit Login Attempts and Solid Security: lockouts now + policy/protection, with one-click Unlock/Release through each plugin's own store). AIOS reads its audit-log table with JSON details flattened to context rows, and reports **failed logins (24h)**, **locked out now**, and **permanent blocks** (System health row too; deep-links into AIOS for management) |
| **Security posture** | Wordfence, Really Simple SSL, Solid Security | System health rows: Wordfence firewall mode (enabled / learning / off) + last scan and unresolved-issue count; Really Simple SSL enforcement status (both read through each plugin's own public APIs). The System page's **Login URL** row uses `wp_login_url()`, so it honors login-hiders (WPS Hide Login and friends) rather than assuming wp-login.php |
| **Multisite** | WP Multi Network | **Networks** surface in the Network group: searchable global network directory with site and administrator counts, network and root-site creation through WP Multi Network, guarded site moves from the Sites detail card, and permanent deletion of non-primary/non-current networks with all of their sites. Dashboards, main sites and WP Multi Network's domain/path editor stay one click away |
| **Automation** | OttoKit (SureTriggers) | **Automation** surface — the outgoing-request log OttoKit keeps locally ({prefix}suretriggers_webhook_requests): every webhook this site fired, with response code, error and retry count, filtered by delivered/failed, searchable by endpoint, with the JSON payload on the detail card and **Retry** (single and bulk) through OttoKit's own retry so the attempt accounting and status stay its own. Workflows, recipes and run history live in OttoKit's cloud with no local copy, so the status card link-outs to its own screens are deliberate rather than a half-mirror; its REST routes are machine-to-machine (a shared secret its cloud holds), so Minn never calls them |
| **Snippets** | Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS, Header Footer Code Manager | **Snippets** surface — list, toggle, edit, create, bulk (provider switcher when more than one is active) |
| **Analytics** | Koko, WP Statistics, Burst, Independent Analytics, AnalyticsWP, **Matomo**, **Site Kit**, **Jetpack Stats** | Overview **Traffic** chart (daily visitors/pageviews). Day-click drill-down (top pages + referrers via `minn_admin_traffic_day`): **Koko**, **WP Statistics** (hits only per URI), **Burst** (`page_url` + session referrers), **Independent Analytics** (views × resources + session referrers), **Matomo** (their own reporting API — Bootstrap + `doAsSuperUser`, gated on `view_matomo`; numbers match their UI including its hourly archiving cadence), **Jetpack Stats** (WPCOM_Stats blog-token client, gated on connection + stats module + `view_stats`; per-page counts are views-only by WPCOM design) |
| **Backups** | UpdraftPlus, Disembark, Duplicator, WPvivid, BackWPup, All-in-One WP Migration | **Backups** surface; health check + "Back up now" (UpdraftPlus, else WPvivid); status card, CLI command, sessions + cleanup, and on Disembark 2.8+ a **Restore a backup** deep-link (Disembark); package list with disk sizes, status card and delete-through-its-own-cleanup (Duplicator, no freshness claims: manual builds); backup list + status card + schedule + backup-now + delete-through-its-own-cleanup (WPvivid); local FOLDER archives + run-job-now + delete through their destination (BackWPup); local .wpress export list + delete through their Backups model, no freshness claims (All-in-One WP Migration; export/import stay deep links) |
| **Caching** | Kinsta, LiteSpeed, WP Super Cache, W3TC, WP Rocket, WP Fastest Cache, SiteGround, Autoptimize, WP-Optimize, Cache Enabler, Hummingbird, Elementor CSS, SpeedyCache, Redis Object Cache, Breeze, Nginx Helper, Cloudflare | **Clear site cache** action (⌘K). Redis Object Cache also adds a System health row for drop-in + connection posture |
| **Custom fields** | ACF (+ Pro), Meta Box, Pods | Editor panel (text, textarea, number, select / radio / button group as the themed searchable dropdown, checkbox and multi-select as tick lists), switch/boolean, color via a swatch + hex control, date/datetime via the app's own calendar popover and time via lenient typed input (values stored in ACF's exact raw formats), image via the media picker, file via the any-attachment picker, gallery via the images editor: reorder, replace, remove, add; relational fields — post object, relationship, page link, taxonomy, user — through one async picker pair (single suggest + ordered multi with draggable chips), searching within each field's own post-type/taxonomy/role constraints; ACF conditional logic honored at edit time — rows follow their controlling fields live, with hidden values always preserved; ACF Pro repeaters as row cards in the panel: add, edit, reorder and remove rows of simple sub-fields plus image, file, gallery, checkbox and date subs, with remaining complex sub-values preserved untouched through a server-side row merge; ACF Pro flexible content as collapsible section cards in the panel and on options pages, each card rendering its own layout's fields, the add button offering the layout list, sections reordering and deleting with their unrendered values intact, and a section whose layout the field no longer declares kept exactly as it is; wysiwyg fields in a rich-text modal — paragraphs, bold/italic/strike, lists, links, sanitized pastes — in the panel, in ACF block inspector forms and on ACF options pages, with kses applied for users without unfiltered_html). ACF values ride a `minn_acf` REST field (`get_field`/`update_field` by field key, so every applicable group appears with no "Show in REST API" needed); Meta Box values ride a `minn_meta_box` REST field (`rwmb_set_meta`); Pods values ride `minn_pods` (`pods()->save()` on extended post types). ACF Pro blocks (`acf_register_block_type`) get a real field form in the block inspector via auto-generated `dataForm` descriptors, with the internal name/data/mode wrapper attributes hidden. ACF Pro options pages become ONE settings-only surface ("Site Options", or the page's own name when a site registers just one), every page merging into its tab strip so options never bleed into top-level navigation, honoring each page's own capability per tab: ACF `tab` fields form the tab strip, simple fields plus wysiwyg (rich-text modal), single image and file (media picker), gallery (images editor), checkbox, date/time, link ({ title, url, target }) and relational fields edit in place through `get_field`/`update_field`; group fields flatten into titled sections whose subs (repeaters included) read and write through the parent, repeaters edit as the same row cards the editor panel uses, and advanced fields count as locked with a wp-admin link. A **Field Groups** surface (ACF's own admin capability) manages the schema itself: each group opens its own builder page (stacked field rows with inline per-type settings, add/reorder/remove, name auto-derivation with lock-after-save, a location-rules editor with or-sets of and-rules over live site vocabularies, one-unit transactional save), list with humanized locations and status, create a group against a post type / options page / ACF block, rename, duplicate, activate/deactivate, trash (values always stay in the database), an **Add field** row action (text, textarea, number, email, URL, select/radio with choices, true/false, color, image), and a Fields view with per-group tabs for editing labels, defaults, choices and required flags, reordering and deleting — field names stay immutable (renaming orphans stored values), code-registered groups (acf-json / PHP) list read-only, and conditional logic, complex types and multi-rule locations stay in ACF's editor Advanced types (clones, file, relationships, multi-pick…) count as locked with a wp-admin link |
| **Ecommerce** | WooCommerce, **WooCommerce Subscriptions** | **Orders** (full-page order view with its own URL plus a quick-view modal; list, search, status, **Record payment** by hand through `payment_complete`, line-item refunds with restock and gateway-honest copy, pay URL, resend/custom email, order notes, the customer's other orders, **New order**, an Overview **Store strip** of orders needing attention, **Analytics** view with revenue chart + top products via `wc-analytics`; WooCommerce log channels also feed the System log viewer through its own log controller) + **Products** (list, search, stock tabs incl. Low stock, bulk, daily fields, **Add product**) + **Coupons** (list/create/edit) + **Customers** (list/search, profile + billing, recent orders; **Subscriptions** strip when WCS is active) + Overview stats. **Subscriptions** (when WCS is active): status tabs, search, next payment, period label, status save through `wc/v3/subscriptions`, parent order + related orders, View customer, and a reverse link from the order modal. Product, coupon and subscription CPTs are fenced out of Content |
| **Spam filtering** | Akismet, Antispam Bee, CleanTalk, WP Armour | Settings → Spam provider cards; open via `minn_admin_spam_providers` |
| **Licenses** | Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Gravity SMTP, AnalyticsWP, Bricks, Divi, Beaver Builder, WPBakery, Brizy, Etch, Astra/Brainstorm Force family (Astra Pro, Ultimate Addons for Beaver Builder and for Elementor, Convert Pro, Schema Pro, WP Portfolio, Premium Starter Templates, Spectra Blocks Pro, each a dedicated provider off their shared registry), WPMU DEV (Dashboard + Smush Pro), SearchWP, Gravity Perks, Rank Math Pro, Perfmatters, GP Premium, WP All Import/Export Pro, Slider Revolution, LayerSlider, Avada, Envato Market, The Events Calendar family (Pro, Event Tickets Plus, Filter Bar, Community, each a dedicated provider) + any other StellarWP Uplink or PUE product generically, Kadence Blocks Pro, Smash Balloon (Instagram / Facebook / YouTube / Twitter / Social Wall / Reviews / TikTok / Feed Analytics Pro, including All Plugins multi-product keys), Yoast SEO Premium (MyYoast portal), Search & Filter Pro, Admin Columns Pro, plus any Freemius, EDD Software Licensing or SureCart plugin generically | Extensions → **Licenses** tab (grouped by state, inactive components collapsed, actions in a per-row menu; the System health check is the clickable doorway): valid / expired / invalid / missing per paid component; paste-to-activate for Elementor Pro, ACF PRO, Gravity Forms, Gravity SMTP, Beaver Builder, Brizy Pro, Etch, Bricks and Divi (active theme; Divi takes username + API key), WPMU DEV, SearchWP, Gravity Perks, Perfmatters, GP Premium, WP All Export Pro, LayerSlider, all four The Events Calendar products, Kadence Blocks Pro, every Brainstorm Force product, Search & Filter Pro and Admin Columns Pro, deactivate and re-verify where each vendor's code allows, and an "Activate ↗" link for portal- or admin-context-bound vendors (WPBakery, Rank Math, Envato, WP All Import, Slider Revolution), all through each vendor's own code; open via `minn_admin_license_providers` SureCart joins the connections rows as a store link (its token is read for presence only, never decrypted; every order, customer and product lives in SureCart's cloud behind it). |
| **Site visibility** | Maintenance (WebFactory), SeedProd, Under Construction, LightStart (WP Maintenance Mode), CMP Coming Soon & Maintenance, Minimal Coming Soon, Password Protected, WooCommerce coming soon (incl. the store-pages-only partial shape), Elementor maintenance mode, plus Minn's own maintenance mode and the `blog_public` "discourage search engines" setting | Overview banner + persistent amber topbar chip (on every route) + System health check when the site is hidden, partly hidden, password-gated or unindexed; Settings → Visibility lists active third-party limiters; every bundled provider can be turned off in place (with Undo) through its own storage; open via `minn_admin_visibility_providers` + `minn_admin_visibility_toggles` |
| **Page builders** | Elementor, Beaver Builder, Brizy, Divi, Bricks, WPBakery, Etch | Detected, fenced, "Edit in ⟨builder⟩" |
| **Block libraries** | Stackable, Kadence, GenerateBlocks | Design library in the editor's Browse-all; open to any plugin via `minn_admin_design_sources`. Stackable columns edit in place as slot islands: one writing surface per column, wrapper bytes and per-block styles preserved verbatim, complex children as protected nested cards |
| **Block previews** | Otter, Essential Blocks, Spectra, Kadence, GenerateBlocks, Stackable | Real front-end styling in island previews |
| **Performance** | Perfmatters, Autoptimize, Asset CleanUp, Performance Lab | One **Performance** Tools item with a provider switcher. **Perfmatters** (settings-only): whole estate from its live core-Settings-API registrations. **Autoptimize** (settings-only): JS / CSS / HTML / CDN / Misc toggles written as its own `on`/empty options (Critical CSS, Extra and Image stay deep-linked; Clear site cache still purges its cache). **Asset CleanUp** (settings-only): global minify/combine/cleanup/fonts/test-mode toggles via its JSON settings option; the page-level CSS/JS unload manager stays in Asset CleanUp. **Performance Lab**: list of WordPress Performance Team standalone features (activate through its own install helper, deactivate through core); Server Timing and per-feature settings stay deep-linked |
| **Dev tools** | Query Monitor; **Diagnostics** family (Scrutoscope, WP Crontrol, Transients Manager, Rewrite Rules Inspector) | QM panel on Minn pages (this-request). One Tools item **Diagnostics** with a provider switcher: **Scrutoscope** (performance profiles + Cron inventory; on 1.4+ **Profile this hook** runs the hook under their profiler and saves an on-demand profile), **WP Crontrol** (event inventory, run-now, pause/resume, delete), **Transients Manager** (list/search/delete, expired purge, never unserializes blobs), **Rewrite Rules Inspector** (registered rules by source, search by path, flush, test URL). Capture settings, PHP/URL cron authoring, deep transient edit, and the full RRI screen stay deep-linked |
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
  → AIOSEO → SEOPress → SureRank → SiteSEO → Squirrly); the first active
  one wins. SEO *scores* and content analysis stay in wp-admin.
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

Refreshed 2026-08-06 at **v0.24.0** open. Two releases have shipped since
the last refresh: v0.22.0 (2026-07-30) brought the read-only database
viewer, a native developer surface rather than an adapter, plus the Matomo
and Jetpack Stats traffic providers; v0.23.0 (2026-08-04), the switches
release, brought visibility toggles, per-extension auto-update pills, theme
live preview and synced patterns. Coverage history lives in the table
above; living primitive matrix + sweep log is `docs/adapter-coverage.md`.

Recently closed threads: ~~**WPForms Pro entries**~~ ✅ shipped 2026-08-06
(v0.24.0 cycle; the last big uncovered forms name, unblocked once a Pro zip
made fixtures possible), ~~forms-family status cards~~ ✅ shipped 2026-08-06
(Fluent, Ninja, Forminator, Flamingo, Everest), ~~Jetpack Stats / Matomo
traffic providers~~ ✅ shipped 2026-07-29 (v0.22.0 cycle; Jetpack verified
end to end on a live connected site the same day).

The open adapter threads, ranked: **MetForm** (deferred until
Elementor-dependent adapters are on the table), status/chart parity on the
remaining thin family siblings outside forms via `/dev-minn-admin sweep`,
deeper Really Simple SSL 9.7 posture rows (its enforcement row is already
wired), and the license long tail. The
largest non-adapter thread is v1 gate **G1**, the outside-tester afternoon
test (`docs/v1-readiness.md`).

A full per-category inventory of untried popular plugins landed 2026-08-06:
Wave E (surface and provider gaps) and Wave F (categories Minn does not
have at all) below, plus the license vendor-family inventory in
`docs/license-manager.md`. Headline picks from that pass: the Awesome
Motive Pro license family first (WPForms Pro is already an active fixture
with no license row, and WP Mail SMTP Pro's license also unlocks its real
email log table), the WooCommerce.com helper read-only license row second,
the MonsterInsights traffic provider third, then the SiteOrigin / Oxygen /
Breakdance builder-detection pass. Fleet counts have NOT been re-measured
since 2026-07-10; re-run the Manager DB inventory against these candidates
before committing a wave.

> **v0.17.0 note (2026-07-16):** adapter waves PAUSED for one cycle. The
> v0.17.0 charter was the plugin-author cycle (developer experience and abuse
> resistance on the road to v1.0). See `docs/v1-readiness.md`. The waves
> resumed 2026-07-17 in the v0.18.0 cycle, which closed Wave B and Wave D.

### Wave A — Dev tools ✅ complete (v0.14.0)

Diagnostics family ships Scrutoscope, WP Crontrol, Transients Manager, and
Rewrite Rules Inspector as one Tools item (provider switcher). **QM stays a
panel, not a surface.** The first native developer surface, the read-only
database viewer, shipped in the v0.22.0 cycle (`/minn-admin/database`,
scoped in `docs/native-editors.md`); the file browser stays parked there.

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
   installed-inactive per family convention). The failed-login and
   permanent-block posture row **SHIPPED 2026-07-27**: failed logins (24h),
   locked out now and permanent blocks on the status card, a System health
   row via `minn_admin_aios_checks()`, and deep links into the AIOS
   locked-ip and permanent-block tabs.
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
5. ~~**WPForms Pro entries**~~ ✅ **SHIPPED 2026-08-06** (v0.24.0 cycle):
   the full forms-family treatment over `{prefix}wpforms_entries` with
   per-form tabs, unread/read/starred/spam/trash filters, star and read
   state, spam and trash flows with restore, permanent delete through
   WPForms' own entry handler, open-marks-viewed, and their
   `wpforms_current_user_can()` capability model. Lite registers nothing
   (entries are a Pro feature, so there is no store to read). The blocker
   was always fixtures rather than code: entries turned out to store fine
   unlicensed, since the license gates updates and addons, not storage.
6. ~~**Meta Box** editor panel~~ ✅ shipped (v0.15.0). ~~**The Events Calendar**
   editor panel~~ ✅ shipped 2026-07-17 (v0.18.0: Event details panel +
   the async `suggest` field primitive). ~~**Jetpack Stats** / **Matomo**
   traffic providers~~ ✅ shipped 2026-07-29 (v0.22.0 cycle).

### Wave D — Media management (researched 2026-07-16, wp.org installs live)

Minn's media core is already caught up (caption/description, bulk delete,
image editor, Regenerate Thumbnails + Safe SVG wired), so this wave is
adapters plus one new primitive, ranked:

7. ~~**Enable Media Replace** (600k)~~ **SHIPPED 2026-07-17** (v0.18.0):
   ⇅ Replace file on the media detail modal through EMR's own
   ReplaceController, URL preserved, same-type enforced; rename-and-move
   stays on EMR's screen.
8. ~~**Core media polish** (no plugin)~~ **SHIPPED 2026-07-17** (v0.18.0):
   Unattached filter, month combobox, and the detail modal's "Attached to"
   row with an editor jump (see `docs/core-gaps.md`).
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

### Wave E — untried popular plugins, per category (inventoried 2026-08-06)

A per-category pass over the marketing page's library categories against
the adapters actually on disk. The surface families are essentially
complete for their categories; these are the largest remaining names,
ranked by install base and daily-ops fit. Install counts are approximate
wp.org figures, not fleet counts; re-rank against the Manager DB before
shipping.

1. **MonsterInsights (~3M) + ExactMetrics (~1M) traffic providers** — the
   largest uncovered free plugins anywhere in this inventory. Both proxy
   GA reports like Site Kit, so they slot into the existing
   `minn_admin_traffic` provider tier (Site Kit precedent: their own
   report machinery, their auth, a short Minn-side transient).
2. **SiteOrigin Page Builder (~1M) detection** — meta storage
   (`panels_data`), which is the silent-stale-copy class the builder
   fencing exists for. **Oxygen** and **Breakdance** (both meta storage)
   join the same detector pass; Breakdance is Soflyy, already a known
   vendor from the license work.
3. **Security posture providers** — SiteGround Security (~1M), Loginizer
   (~1M; lockout log in the LLA-R shape), WPS Hide Login (~1M; one
   posture row, the login URL), Sucuri (~800k; audit log for the
   activity-log family), Jetpack Protect.
4. **SEO panel providers** — The SEO Framework (~100k) and Slim SEO
   (~100k). The provider contract is mature; S each.
5. **Easy Digital Downloads** — the only other real store platform, now
   Awesome Motive-owned (pairs with the AM license family in
   `docs/license-manager.md`). Orders and customers in the Woo mold.
6. **Redirects family joiners** — Rank Math's built-in redirections
   module and Yoast Premium's redirect manager; both join the existing
   family rather than adding a plugin.
7. **Spam cards** — hCaptcha and Simple Cloudflare Turnstile.
8. **Broken Link Checker (~600k)** — real table, inbox-shaped daily ops;
   would seed a content-health category.
9. **WS Form** — real entry storage, forms family.

Deliberate skips stay skipped (optimizers, email marketing, popups,
migration tools, remote-management agents; see the list at the end of this
file). MetForm stays deferred as documented in Wave B.

### Wave F — categories Minn does not have at all (inventoried 2026-08-06)

Own-cycle scoped bets in the Wave C mold, not sweep items. Rank by fleet
before starting any of them.

1. **LMS** — LearnDash (paid, StellarWP), Tutor LMS (~100k), LifterLMS.
   Enrollments and quiz attempts are inbox-shaped, exactly the workspace
   thesis.
2. **Bookings** — **Amelia + LatePoint + Bookly SHIPPED** (appointments
   inbox + status actions + Open Amelia / Open LatePoint / Open Bookly).
   FluentBooking and Simply Schedule Appointments remain family joiners.
   Appointments are the most inbox-shaped data there is.
3. **Membership / community** — Ultimate Member (~200k), Paid Memberships
   Pro, MemberPress, BuddyPress. Member lists extend the Users surface
   naturally.

### Licenses fleet (see `docs/license-manager.md`)

~~Smash Balloon~~ and ~~Yoast SEO Premium~~ shipped 2026-07-15;
~~Search & Filter Pro~~ and ~~Admin Columns Pro~~ shipped 2026-07-19
(v0.20.0, full activation loops with real keys). Remaining fleet-ranked
open work is the long-tail Freemius/EDD verification list, plus the
vendor-family inventory added 2026-08-06 (Awesome Motive family,
WooCommerce.com helper, SolidWP, StellarWP beyond TEC, WPManageNinja,
Delicious Brains, Crocoblock, OTGS, Elementor addon packs, and the odds
and ends); see "Vendor-family inventory" in `docs/license-manager.md`.

### Axis A leftovers (adapter depth, not new plugins)

From `docs/adapter-coverage.md` and `docs/full-ui-adapters.md` (2026-07-15):

- ~~Gravity SMTP bulk log delete~~ ✅ shipped (mail reference parity with
  FluentSMTP / Post SMTP / WPML).
- ~~Activity-log status cards~~ ✅ shipped (Simple History, WSAL, Stream,
  Aryo; Solid / LLA-R / Wordfence already had them).
- ~~Richer `sectionsRoute` row types~~ ✅ shipped 2026-07-17 (v0.18.0:
  `pill`, `code`, sandboxed `html-preview`, `kv-table`; the whole mail
  family's log detail converted).
- ~~Forms-family status cards~~ ✅ shipped 2026-08-06 (Fluent Forms, Ninja
  Forms, Forminator, Flamingo and Everest Forms all match the SureForms
  shape; every number comes from the plugin's own storage). Gravity Forms
  deliberately skips the card: its depth lives in the entry workflow.
- Status/chart parity on the remaining thin adapters outside the forms
  family, when a family sweep is scheduled (`/dev-minn-admin sweep`).

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
