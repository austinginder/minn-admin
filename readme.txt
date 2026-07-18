=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.18.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A reimagined WordPress admin experience. Fast, focused and beautiful.

== Description ==

Minn Admin serves a modern, minimal admin dashboard at `/minn-admin/` on your site. It talks to the WordPress REST API and works alongside the classic wp-admin (which stays fully available).

Features:

* **Overview** — real stats, a Traffic chart with hover details when an analytics plugin is installed (Koko Analytics, WP Statistics, Burst, Independent Analytics, AnalyticsWP, or Google Analytics through Site Kit), click a bar for that day's top pages and referrers (Koko, WP Statistics, Burst and Independent Analytics today), and a recent-activity feed.
* **Content** — posts, pages and custom post types sorted by publish date (scheduled posts lead), with search, category/tag filters, status pills, bulk actions, and right-click row actions for quick publish/draft/trash, view, and a block-editor escape.
* **Media** — grid and list library views with real thumbnails, uploads, drag-and-drop, a preview overlay with in-place title, alt text, caption and description editing, bulk select and delete, a right-click menu, and a built-in image editor (rotate and crop, saved as a new copy). Folders arrive from your folder plugin (FileBird, Real Media Library or Folders by Premio) with a Move-to-folder bulk action through each plugin's own machinery; an Unattached filter and a month picker cover the daily cleanup questions; every file's detail names the post it belongs to, one click from that post's editor; Replace file works in place when Enable Media Replace is active; the SVG filter tab appears with Safe SVG or SVG Support, and ↻ Thumbnails works through Regenerate Thumbnails or Force Regenerate Thumbnails.
* **Comments** — moderation with Pending/Approved/Spam/Trash tabs, one-click actions, bulk moderation with shift-range select, inline replies, and a right-click menu.
* **Ecommerce** — WooCommerce day-to-day in Minn: Orders (search, status, notes, refunds, resend/custom email, pay URL, New order, Analytics), Products (stock filters, bulk, daily fields, Add product), Coupons, Customers, and Subscriptions when WooCommerce Subscriptions is active. Orders, products and customers all carry right-click menus for the common moves (status changes, stock and publish toggles, email, jump to a customer's orders). Invoice/packing-slip downloads when PDF Invoices & Packing Slips is active.
* **Users** — a role filter, create/edit users, roles, passwords, bulk role change, per-user login sessions with one-click sign-out, Switch to this user when User Switching is active (a switched session shows a Switch back bar), and Copy one-time login link when One Time Login is active.
* **Your profile** — a full page: account (name, email, role, password), public profile (first/last name, website, bio, Gravatar), per-user language with automatic pack installs, the front-end toolbar preference, appearance, hidden integrations, and login sessions.
* **AI Access** — application passwords for AI agents plus a generated, site-tailored agent guide, on Your profile.
* **Extensions** — install plugins and themes from WordPress.org or zip upload (Add plugin opens a catalog by category with install tips); activate, deactivate, delete, per-item and bulk updates with Queued/Updating feedback; Themes tab with screenshots; cards wear real wp.org icons linked to the directory, with linked author lines; right-click menus on plugin and theme cards; and a Licenses tab (see below).
* **Structure** — post types, taxonomies and terms on one page. Manage custom post type and taxonomy definitions through whichever manager owns them (ACF, Custom Post Type UI, or Minn's own store), and manage terms across every taxonomy: rename, re-parent, merge (posts move to the survivor) and delete, with an indented tree for hierarchical taxonomies.
* **Settings** — reorganized by intent: Site (identity, locale, admin), Visibility (search engines, maintenance mode, membership), Homepage, Content (defaults plus permalinks), Comments (discussion plus spam), Design (the Customizer's Additional CSS, validated before saving) and Connectors (WP 7.0's registry of AI providers and external services: connection state, where each key comes from, install-in-place, keys saved through core's own masked route). The Spam page shows who filters comment spam (Akismet, Antispam Bee, CleanTalk, WP Armour) with safe toggles and blocked counts. Site-visibility warnings (an Overview banner and a topbar chip) appear whenever a maintenance plugin, password gate or "discourage search engines" is hiding the site.
* **Editor** — a distraction-free, block-aware writing surface that stores native Gutenberg markup. Markdown typing (wraps stay on the undo stack, including inline code), link popover on ⌘K, text alignment, table and image controls, writing stats on the sticky pill (words, reading time, session delta, optional word goal), featured images, categories and tags, status-aware autosave, scheduling with a themed date-time picker, and ⌘⏎ publish. Complex blocks are configurable islands with real front-end previews (full height, lazy CSS, auto-warm when styles only exist after a front-end visit). **Block library:** curated slash menu, **Browse all / ⌘/** block picker, plugin slash commands via `minn_admin_editor_commands`, auto-insert for standalone dynamic third-party blocks, Stackable / Kadence / GenerateBlocks free design libraries, and site block patterns; island text and images are editable; large block schemas collapse to used fields plus More settings; each island links to the block editor for layout. Paste cleanup (Word / Docs / web); paste or drag an image at the caret; publish sidebar for slug, visibility (themed Public / Password / Private combobox), discussion, sticky and post format. Embed delete offers an Undo toast; table structure undoes with ⌘Z. Revision diffs, outline panel, focus mode (⌘⇧D), outline mode (⌘⇧O), internal link picker, and find & replace (⌘⇧F) that respects inline formatting and protected blocks. Built for real sessions: IME-safe composition, mobile Safari keyboard and hit-target polish, and a first-cut accessible toolbar, slash menu and block popovers.
* **Never lose work** — post locking on WordPress's own `_edit_lock` (Minn, the classic editor and Gutenberg honor each other, with takeover), plus a localStorage crash net that snapshots every edit within ~1.2s (before the first autosave) and offers recovery on the next open.
* **Page builders** — build a page with Divi, Elementor, Brizy, Beaver Builder, Etch, Bricks or WPBakery and keep managing it from Minn: builder-owned pages are marked, edited through the builder's own chrome-free surface (no wp-admin screen), and fenced so a stray Minn edit can't break the builder's canvas. + New can start a page in any active builder. Third parties register via the `minn_admin_page_builders` filter.
* **Menus & Widgets** — classic menus with drag-to-reorder and right-click menus on every item; classic sidebars with drag grips to reorder widgets, move between areas, and edit block/text/HTML widgets in place.
* **System** — a developer diagnostics page with a sticky section jump bar: a health strip over WordPress / PHP / database / server facts (plus loopback and REST self-checks, site visibility, Wordfence firewall and scan posture, SSL enforcement, backups and licenses), autoload and cron breakdowns that expand into full-detail modals, an installed extensions manifest, a Tools card linking wp-admin's one-shot jobs, live debug toggles that safely rewrite wp-config.php, a clickable debug-log viewer, and one-click Copy report as markdown.
* **Licenses** — a license manager on Extensions → Licenses, beside the plugins and themes it describes: every paid product classified valid / expired / invalid / missing from the vendor's own stored state (read-only, so it can never burn a seat), grouped by state with inactive components collapsed, with paste-to-activate, deactivate and re-verify wired through each vendor's own code for more than twenty vendors (Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Divi, The Events Calendar family, Kadence Blocks Pro, WPMU DEV, SearchWP, Slider Revolution and more). A pasted key rides one request and is never stored; failures never auto-retry.
* **Editor field panels** — ACF, Meta Box and Pods simple fields in the sidebar; SEO panel for Yoast SEO, Rank Math, All in One SEO, SEOPress or SiteSEO title, meta description and focus keyword; Event details for The Events Calendar (dates, all-day, venue and organizer as live search pickers, cost, website); Job listing for WP Job Manager, drawn live from its own field schema; Podcast episode for Seriously Simple Podcasting and PowerPress (media file, duration, the Apple Podcasts fields). Every write goes through the owning plugin's own save machinery.
* **Command palette** — press ⌘K / Ctrl-K anywhere, and it finds your content: type anything to see your posts, pages and custom post types (drafts and scheduled included) under the command matches, with Enter opening the Minn editor. Site-care actions built in, like Clear site cache across more than a dozen cache plugins including Redis, Breeze, Nginx Helper and Cloudflare (each layer in its own isolated request) and Back up site now.
* **Surfaces** — one sidebar item per job, not per plugin, with every capable plugin layered in behind it and a provider switcher when more than one is active. Forms (Gravity Forms, Ninja Forms, Fluent Forms, Elementor Pro, Contact Form 7 via Flamingo or CFDB7, Forminator, Formidable, Everest Forms, SureForms) shows entries as contact cards, with the full Gravity Forms workflow inside Minn: star, spam, trash, restore, bulk actions, notes and resent notifications across Received / Spam / Trash views, plus Notifications and Feeds views (every add-on integration across your forms, toggled through GF's own model) and per-form settings drawn from GF's own schema; Email (Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging, SureMails, Site Mailer) shows sent mail with the real HTML body in a fully sandboxed preview, resend, and search plus delete where the logger supports it, plus Gravity SMTP's full settings (all 21 connectors, drawn from its own schema), suppressions list, send-a-test, an in-Minn debug log and Routing list, and a FluentSMTP Settings view for the day-to-day choices; Activity Log (Simple History, WP Activity Log, Activity Log, Stream, All-In-One Security, Wordfence login security, Limit Login Attempts Reloaded and Solid Security lockouts with unlock/release actions) reads like an audit feed; Redirects (Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects) creates, searches and edits, with sortable columns on Redirection; Snippets (Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS, Header Footer Code Manager) lists, toggles, creates and bulk-edits; Performance (Perfmatters, Autoptimize, Asset CleanUp, Performance Lab) shares one Tools item with a provider switcher. Status cards now open the whole mail, redirects and snippets families (counts, each store's own facts, warning rows) and can carry charts, like Gravity SMTP's and Redirection's 14-day series. Detail modals render typed rows: status pills, code blocks, key-value tables and sandboxed HTML previews. List rows open a ⋯ / right-click menu of that surface's actions. Plugins that need their own first-run install get a setup card that runs their installer in place. The sidebar organizes into Workspace, Tools and Manage groups.
* **Backups** — UpdraftPlus and WPvivid: sets listed, status cards, health check and Back up site now. BackWPup: local folder archives with run-now. All-in-One WP Migration: local .wpress exports with delete. Duplicator: packages with sizes from disk. Disembark: a status card, the exact connect command click-to-copy, scan sessions and cleanup.
* **Notifications** — comments, plugin/theme/core updates and new users, plus an admin-notice digest: notices other plugins print in wp-admin arrive as structured data (never their HTML or scripts); Allow / No Thanks and ThemeIsle-style dismiss links run in the background; any notice hides with Undo. Each update offer has its own Update button; Update everything still runs plugins, themes and core in one click.
* **Extending** — one-filter APIs for any plugin to register views (status cards with optional charts, extra list views, tabs, status filters, sortable columns, detail layouts with typed rows including sandboxed HTML previews, actions with inline fields, bulk actions, schema-driven settings views including item-scoped settings, setup gates), editor panels (including async search-picker fields), editor slash commands, traffic data (including per-day drill-down), media folder providers, cache purgers, spam providers, license providers, visibility providers, design libraries, page builders or block-inspector forms; the System page's Integrations card shows everything registered and flags descriptor problems. The author guide opens with a quick start and screenshots of every primitive, and a shim tutorial builds a custom-table integration end to end with a copyable example plugin (preinstalled in the WordPress Playground demo).
* **Quiet by architecture** — integrations are data, never third-party HTML or scripts, and attention is budgeted: one plugin holds at most three nav slots and three default slash entries (overflow stays one search away), Workspace placement requires an inbox-shaped view, off-site links always carry an external mark, and every surface, editor panel, design library and slash namespace can be hidden per user from Minn's own UI (restore from Your profile), with no API for a plugin to detect or resist it.
* **Dark, light and System themes** — follows your OS until you choose; right-click the theme control for Dark / Light / System. Per-user color schemes add named light/dark presets or a fully custom scheme with per-slot color pickers on Your profile. Fonts are bundled locally.
* **Self-updater** — updates arrive from GitHub Releases through the normal WordPress updates UI.

== Installation ==

Try it instantly in WordPress Playground (launch link and blueprint): https://github.com/austinginder/minn-admin#minn-admin

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

= 0.18.0 =
* Plugin surface detail grows typed rows: status pills, code blocks, key-value tables and real HTML email bodies in a fully sandboxed preview, live across the whole mail family; status cards spread to the redirects and snippets families and columns become sortable where a route supports it.
* The media library gains folders from FileBird, Real Media Library or Folders by Premio (with Move to folder), an Unattached filter, a month picker, an Attached-to jump, and in-place Replace file through Enable Media Replace.
* Editor panels reach The Events Calendar, WP Job Manager, Seriously Simple Podcasting and PowerPress, each drawn live from the plugin's own schema; Gravity Forms gains a Feeds view and FluentSMTP a Settings view.
* New providers: SureForms (forms), SureMails and Site Mailer (email), All-In-One Security (activity log). Four bugs fixed, including the Overview traffic chart dropping today's visitors before noon UTC. Full details in changelog.md.

= 0.17.0 =
* Integration boundaries enforced: placement and count budgets, marked external links, a frozen descriptor contract with a kitchen-sink test suite, and per-user hiding of any surface, panel, design library or slash namespace with restore from Your profile.
* Your profile is a full page: account, public profile (name, website, bio), per-user language with automatic pack installs, the front-end toolbar preference, appearance, AI access and sessions.
* The command palette searches your content; orders, products, customers and menu items gain right-click menus; the author guide is rebuilt with a quick start, screenshots and a copyable example plugin.
* Settings' last native selects and the structure dialogs' checkboxes became themed controls; surface status cards tightened up. Full details in changelog.md.

= 0.16.0 =
* Per-user appearance: named color schemes for light and dark, or a fully custom palette with per-slot pickers, chosen on Your profile. Preferring Minn as the default admin is now an opt-in per-user choice.
* Lists stay calm: tab, filter and search changes keep toolbars painted and dim in place across Content, Media, the commerce views and plugin surfaces. Updating a single plugin or theme no longer clears every other pending offer.
* Editor: secondary meta tucks behind door rows, revisions open with an activity heatmap and day filter, the schedule calendar shows what is already planned, the link popover gains open-in-new-tab, and long titles wrap.
* Licenses grow with Yoast SEO Premium and the Smash Balloon family. Activity Log status cards (Simple History, WP Activity Log, Stream, Aryo), Gravity SMTP bulk log delete, Rank Math social thumbnail, Users ID column with sorting and a session filter. Full details in changelog.md.

= 0.15.0 =
* Performance family: Autoptimize, Asset CleanUp and Performance Lab join Perfmatters under one Tools item. Add plugin catalog by category with install tips. Meta Box and Pods editor panels. WooCommerce Subscriptions surface. Traffic day drill-down for Burst and Independent Analytics. Safe SVG media tab.
* Extensions: right-click / ⋯ menus on plugin and theme cards; resilient Update all with Queued/Updating feedback; per-row Update on notification offers.
* Notices: clickable Allow / No Thanks and ThemeIsle dismiss in-panel; mark-read keeps scroll. Post SMTP search and delete; Fluent Forms suite. Full details in changelog.md.

= 0.14.0 =
* WooCommerce day-to-day in Minn: Products, Coupons, Customers surfaces; create product and new order; order notes, refunds, resend and compose email, pay URL; Orders Analytics (7d through All) with revenue and top products.
* Diagnostics family under Tools: Scrutoscope, WP Crontrol, Transients Manager, Rewrite Rules Inspector (provider switcher).
* Public Post Preview adapter (shareable draft links), FluentSMTP search and log delete, View all revisions dialog.
* Editor: paste a URL over selected text to hyperlink it; Select All / copy includes island block content. Orders status tabs and coupons-disabled nav fixed. Full details in changelog.md.

= 0.13.0 =
* Forms and Email grow into full workflows: extra list views (Gravity SMTP debug log and routing, Gravity Forms notifications), item-scoped form settings from GF's own schema, Forminator / Formidable / Everest Forms entries, and status-card charts (Gravity SMTP 14-day series).
* Backups family: WPvivid, BackWPup and All-in-One WP Migration join UpdraftPlus, Duplicator and Disembark. Snippets: Simple Custom CSS and JS plus Header Footer Code Manager. Clear site cache covers SpeedyCache, Redis, Breeze, Nginx Helper and Cloudflare too.
* Editor: slash-command extension filter, writing stats with session delta and word goal, traffic day drill-down on Overview, accessibility and mobile Safari polish, IME-safe composition, Visibility as a themed combobox.
* Surface list row actions (⋯ / right-click), calmer Content and surface toolbars, theme Dark / Light / System (default System). License activate force-checks updates; per-plugin update queue; license ⋯ menu deactivate/re-verify fixed. Full details in changelog.md.

= 0.12.0 =
* Surfaces grow into complete workflows: schema-driven settings views (Gravity SMTP fully configurable from Minn, all 21 connectors), bulk actions with shift-select, status filters (Gravity Forms spam and trash round-trip inside Minn), parameterized actions (send a test email, add an entry note), setup gates that run a plugin's own first-run installer in place, and action toasts that report honest outcomes.
* Six more plugins join the families: Perfmatters (a full Performance settings page drawn at runtime from its own registrations), Ninja Forms (Forms), Solid Security and Limit Login Attempts Reloaded (Activity Log, with release/unlock actions), WP Mail Logging (Email), Duplicator (Backups).
* Settings gains Connectors, mirroring WP 7.0's connector registry: connection state, where each key comes from (saved, wp-config or environment), install-in-place, and keys saved through core's own masked route.
* The license manager moves to Extensions → Licenses, grouped by state with inactive components collapsed; System health cards with a destination are clickable; the header wears the site's own icon and name.
* One Time Login links and User Switching switch-back in Minn, image link and lightbox in the editor, clickable Recent activity, adapter dropdowns as themed comboboxes, and scroll fixes for block chips and combobox panels. Full details in changelog.md.

= 0.11.0 =
* License manager on the System page: every paid plugin and theme classified (valid / expired / invalid / missing) from the vendor's own stored state, with paste-to-activate, deactivate and re-verify through each vendor's own code for more than twenty vendors. Keys ride one request and are never stored; failures never auto-retry; inactive components can be turned back on in place.
* Daily-work gaps closed: a terms manager (rename, re-parent, merge, delete, on the new Structure page), bulk comment moderation, bulk media delete, bulk user role change, media captions and descriptions, a per-post format picker, and Custom CSS under Settings → Design.
* Site posture: visibility warnings when a maintenance plugin, password gate or search-engine setting is hiding the site (with inline fix controls), Wordfence firewall and scan rows, SSL enforcement, the real login URL, loopback and REST self-checks, and autoload/cron full-detail modals.
* Settings reorganized by intent (Site, Visibility, Homepage, Content, Comments, Design) with a sticky section nav; the System page gains a jump bar; the theme follows the OS light/dark setting by default.
* SiteSEO in the SEO panel, WP Armour on the Spam page, 301 Redirects (WebFactory) in the Redirects family, order PDF downloads, Switch to this user, regenerate thumbnails. Full details in changelog.md.

= 0.10.0 =
* Surfaces grow into families: Forms (now with Contact Form 7 via Flamingo or CFDB7), Email Log (Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP), Activity Log (plus Wordfence login security), Snippets, Redirects and Backups (UpdraftPlus, Disembark) each get one nav item with a provider switcher; the sidebar reorganizes into Workspace, Tools and Manage.
* Admin-notice digest: other plugins' wp-admin notices arrive as structured data in a Notices tab, action links run in the background, and any notice hides with Undo.
* Update everything (plugins, themes, core in one click with poll-verified completion), core-update banner and topbar chip, Clear site cache across a dozen providers, Spam settings page, Site Kit traffic, Duplicate any post.
* Editor: find & replace (⌘⇧F), live Buttons and Details islands, shortcode fields, spacer/file/pullquote upgrades.
* Surface status cards, Integrations diagnostics on the System page, and an open design-library seam for plugin authors. Full details in changelog.md.

= 0.9.0 =
* Block library: auto-insert for standalone dynamic third-party blocks; Stackable, Kadence, and GenerateBlocks free design/pattern libraries in the slash menu; site block patterns; Browse all / ⌘/ block picker grouped by source.
* Islands: edit text runs and swap images in complex blocks; inspector scales for huge schemas (More settings); block-editor escape on every island; previews auto-warm browser-compiled CSS and no longer clip tall content.
* Table structure undoes with ⌘Z; X embed previews work in dark mode; empty posts stay in blocks mode; widget drag grips; ←/→ steps through Gravity Forms (and other surface) entry details.
* Full details in changelog.md.

= 0.8.0 =
* Editor: revision diffs (side-by-side, word-marked, against current content), a live outline panel, focus mode (⌘⇧D), outline mode (⌘⇧O), an internal link picker, a themed date-time picker, and ⌘⏎ publish.
* Built-in image editor: rotate and crop in the media preview, saved as a new copy via core's image-editing endpoint.
* Context menus: content rows (quick status, view, block-editor escape, trash), media items, comments, and table cells.
* Audit-log adapters for WP Activity Log, Activity Log (Aryo), and Stream; System page adds autoload, cron health, and expired-transient checks; Extensions cards wear real wp.org icons with linked authors.
* Keyboard shortcuts documented in the help dialog; version badge opens the full changelog; collapsible sidebar cards; menu drag handles; many scroll-jump and inspector fixes. Full details in changelog.md.

= 0.7.1 =
* Fix: System page returned a 500 on managed hosts (Kinsta) that disable disk_free_space/disk_total_space in web PHP; now guarded, disk usage shows "Unknown" where hidden. php_uname guarded the same way.

= 0.7.0 =
* Page-builder coexistence: Divi, Elementor, Brizy, Beaver Builder, Etch, Bricks and WPBakery. Builder-owned pages are marked, edited through the builder's own chrome-free surface, and fenced so Minn can't break them. + New can start a page in any builder.
* Paste cleanup (Word / Google Docs / web HTML → safe subset), inline media (paste or drag an image to upload at the caret) with captions, and Undo toasts for structural deletions.
* Conflict safety: post locking on WordPress's own edit-lock (with takeover) plus a localStorage crash net that recovers work even before the first autosave.
* Editor publish essentials in the sidebar: slug, visibility (public / password / private), per-post discussion and sticky.
* System page: developer diagnostics, health checks, an installed extensions manifest, live wp-config debug toggles, and a debug-log viewer. Extensions view gains status filters and search. Full details in changelog.md.

= 0.6.0 =
* The editor release: full markdown typing conventions, inline code with boundary-safe typing, link popover on ⌘K, text alignment, table and image controls with island-style cutouts, word count and reading time, SVG toolbar, sticky toolbar with toggleable block buttons.
* Status-aware autosave: drafts save in place, published posts back up to autosave revisions; only Update goes live. Save draft button, ⌘S, backup-restore banner.
* Island previews render with the site's real front-end styles; embeds render for real, with in-place Change URL / Replace images.
* SEO editor panel (Yoast / Rank Math) and a much better Gravity Forms surface: readable entry detail plus a Forms view with activate/deactivate.
* Fixes: Backspace can no longer destroy an adjacent embed, images insert at the caret, x.com tweets embed again on WordPress 7.0, serialized markup stays clean. Full details in changelog.md.

= 0.5.0 =
* Taxonomies manager, redirect creation and search, image controls, video/audio blocks editable, Query Monitor integration, attribute passthrough for simple blocks, activity chart drill-down. Full details in changelog.md.

= 0.4.1 =
* Fixed: updating an active plugin from the Extensions per-plugin update button no longer deactivates it (including Minn updating itself).

= 0.4.0 =
* Block inspector: configure complex blocks (islands) in place with schema-driven forms, add/remove/reorder children, wrapper-text edits, live server-rendered previews, and removal.
* Insert custom blocks from the slash menu (plugins declare templates via minn_admin_block_forms; Anchor Blocks ships five).
* Post Types manager: create/edit/remove CPT definitions through ACF, Custom Post Type UI, or Minn's own store; code-registered types shown read-only.
* Settings: site icon with drag & drop, membership + default role, comment moderation toggles, searchable comboboxes for timezone/role/category/pages.
* Image picker: drag & drop upload used immediately. Code blocks: language config chip. AnalyticsWP traffic adapter.
* Fixed stale Overview after switching plugins. Full details in changelog.md.

= 0.3.0 =
* Content: bulk actions (set status, trash) with shift-click range select, plus category and tag filters.
* Editor: tags. Add existing or new tags inline with suggestions, alongside categories.
* Email Log: real HTML preview in a sandboxed frame, open-raw and resend actions.
* Orders: change an order's status from the detail modal.
* Media: edit an image's title and alt text in place.
* Users: filter the directory by role.
* Redirects: edit source, target and HTTP status in place, via a new surface `edit` API any adapter can use.
* Smoother in-place loading, horizontally scrolling tab strips, and a proper phone layout (compact topbar, wrapping toolbars, tables that drop columns instead of clipping).

= 0.2.0 =
* Editor: block islands hardening, tables, verse, citations, featured images, code-block language picker with syntax highlighting on dark surfaces, revision restore.
* Install plugins and themes from WordPress.org search or zip upload; Themes management tab.
* AI Access: application passwords and a generated agent guide.
* Traffic chart with four analytics adapters; Simple History and Redirection views.
* Per-item notification reads, cleaned plugin names, no overlay flashing, and many fixes. Full details in changelog.md.

= 0.1.0 =
* Initial release.
