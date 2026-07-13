=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.13.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A reimagined WordPress admin experience. Fast, focused and beautiful.

== Description ==

Minn Admin serves a modern, minimal admin dashboard at `/minn-admin/` on your site. It talks to the WordPress REST API and works alongside the classic wp-admin (which stays fully available).

Features:

* **Overview** — real stats, a Traffic chart with hover details when an analytics plugin is installed (Koko Analytics, WP Statistics, Burst, Independent Analytics, AnalyticsWP, or Google Analytics through Site Kit), click a bar for that day's top pages and referrers (Koko and WP Statistics today), and a recent-activity feed.
* **Content** — posts, pages and custom post types sorted by publish date (scheduled posts lead), with search, category/tag filters, status pills, bulk actions, and right-click row actions for quick publish/draft/trash, view, and a block-editor escape.
* **Media** — grid and list library views with real thumbnails, uploads, drag-and-drop, a preview overlay with in-place title, alt text, caption and description editing, bulk select and delete, a right-click menu, and a built-in image editor (rotate and crop, saved as a new copy).
* **Comments** — moderation with Pending/Approved/Spam/Trash tabs, one-click actions, bulk moderation with shift-range select, inline replies, and a right-click menu.
* **Orders** — WooCommerce orders with summary cards, line-item detail, status changes, and invoice/packing-slip downloads when PDF Invoices & Packing Slips is active.
* **Users** — a role filter, create/edit users, roles, passwords, bulk role change, per-user login sessions with one-click sign-out, Switch to this user when User Switching is active (a switched session shows a Switch back bar), and Copy one-time login link when One Time Login is active.
* **AI Access** — application passwords for AI agents plus a generated, site-tailored agent guide.
* **Extensions** — install plugins and themes from WordPress.org or zip upload; activate, deactivate, delete, per-item and bulk updates; Themes tab with screenshots; cards wear real wp.org icons linked to the directory, with linked author lines; and a Licenses tab (see below).
* **Structure** — post types, taxonomies and terms on one page. Manage custom post type and taxonomy definitions through whichever manager owns them (ACF, Custom Post Type UI, or Minn's own store), and manage terms across every taxonomy: rename, re-parent, merge (posts move to the survivor) and delete, with an indented tree for hierarchical taxonomies.
* **Settings** — reorganized by intent: Site (identity, locale, admin), Visibility (search engines, maintenance mode, membership), Homepage, Content (defaults plus permalinks), Comments (discussion plus spam), Design (the Customizer's Additional CSS, validated before saving) and Connectors (WP 7.0's registry of AI providers and external services: connection state, where each key comes from, install-in-place, keys saved through core's own masked route). The Spam page shows who filters comment spam (Akismet, Antispam Bee, CleanTalk, WP Armour) with safe toggles and blocked counts. Site-visibility warnings (an Overview banner and a topbar chip) appear whenever a maintenance plugin, password gate or "discourage search engines" is hiding the site.
* **Editor** — a distraction-free, block-aware writing surface that stores native Gutenberg markup. Markdown typing (wraps stay on the undo stack, including inline code), link popover on ⌘K, text alignment, table and image controls, writing stats on the sticky pill (words, reading time, session delta, optional word goal), featured images, categories and tags, status-aware autosave, scheduling with a themed date-time picker, and ⌘⏎ publish. Complex blocks are configurable islands with real front-end previews (full height, lazy CSS, auto-warm when styles only exist after a front-end visit). **Block library:** curated slash menu, **Browse all / ⌘/** block picker, plugin slash commands via `minn_admin_editor_commands`, auto-insert for standalone dynamic third-party blocks, Stackable / Kadence / GenerateBlocks free design libraries, and site block patterns; island text and images are editable; large block schemas collapse to used fields plus More settings; each island links to the block editor for layout. Paste cleanup (Word / Docs / web); paste or drag an image at the caret; publish sidebar for slug, visibility (themed Public / Password / Private combobox), discussion, sticky and post format. Embed delete offers an Undo toast; table structure undoes with ⌘Z. Revision diffs, outline panel, focus mode (⌘⇧D), outline mode (⌘⇧O), internal link picker, and find & replace (⌘⇧F) that respects inline formatting and protected blocks. Built for real sessions: IME-safe composition, mobile Safari keyboard and hit-target polish, and a first-cut accessible toolbar, slash menu and block popovers.
* **Never lose work** — post locking on WordPress's own `_edit_lock` (Minn, the classic editor and Gutenberg honor each other, with takeover), plus a localStorage crash net that snapshots every edit within ~1.2s (before the first autosave) and offers recovery on the next open.
* **Page builders** — build a page with Divi, Elementor, Brizy, Beaver Builder, Etch, Bricks or WPBakery and keep managing it from Minn: builder-owned pages are marked, edited through the builder's own chrome-free surface (no wp-admin screen), and fenced so a stray Minn edit can't break the builder's canvas. + New can start a page in any active builder. Third parties register via the `minn_admin_page_builders` filter.
* **Menus & Widgets** — classic menus with drag-to-reorder; classic sidebars with drag grips to reorder widgets, move between areas, and edit block/text/HTML widgets in place.
* **System** — a developer diagnostics page with a sticky section jump bar: a health strip over WordPress / PHP / database / server facts (plus loopback and REST self-checks, site visibility, Wordfence firewall and scan posture, SSL enforcement, backups and licenses), autoload and cron breakdowns that expand into full-detail modals, an installed extensions manifest, a Tools card linking wp-admin's one-shot jobs, live debug toggles that safely rewrite wp-config.php, a clickable debug-log viewer, and one-click Copy report as markdown.
* **Licenses** — a license manager on Extensions → Licenses, beside the plugins and themes it describes: every paid product classified valid / expired / invalid / missing from the vendor's own stored state (read-only, so it can never burn a seat), grouped by state with inactive components collapsed, with paste-to-activate, deactivate and re-verify wired through each vendor's own code for more than twenty vendors (Elementor Pro, ACF PRO, WP Rocket, Gravity Forms, Divi, The Events Calendar family, Kadence Blocks Pro, WPMU DEV, SearchWP, Slider Revolution and more). A pasted key rides one request and is never stored; failures never auto-retry.
* **SEO panel** — Yoast SEO, Rank Math, All in One SEO, SEOPress or SiteSEO title, meta description and focus keyword in the editor sidebar.
* **Command palette** — press ⌘K / Ctrl-K anywhere; site-care actions built in, like Clear site cache across more than a dozen cache plugins including Redis, Breeze, Nginx Helper and Cloudflare (each layer in its own isolated request) and Back up site now.
* **Surfaces** — one sidebar item per job, not per plugin, with every capable plugin layered in behind it and a provider switcher when more than one is active. Forms (Gravity Forms, Ninja Forms, Fluent Forms, Elementor Pro, Contact Form 7 via Flamingo or CFDB7, Forminator, Formidable, Everest Forms) shows entries as contact cards, with the full Gravity Forms workflow inside Minn: star, spam, trash, restore, bulk actions, notes and resent notifications across Received / Spam / Trash views, plus a Notifications view and per-form settings drawn from GF's own schema; Email (Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP, WP Mail Logging) shows sent mail with HTML previews and resend, plus Gravity SMTP's full settings (all 21 connectors, drawn from its own schema), suppressions list, send-a-test, an in-Minn debug log and Routing list; Activity Log (Simple History, WP Activity Log, Activity Log, Stream, Wordfence login security, Limit Login Attempts Reloaded and Solid Security lockouts with unlock/release actions) reads like an audit feed; Redirects (Redirection, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects) creates, searches and edits; Snippets (Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS, Header Footer Code Manager) lists, toggles, creates and bulk-edits; Performance (Perfmatters) renders all nine of its settings tabs from the plugin's own registrations. Status cards can carry charts (Gravity SMTP's 14-day series is the first). List rows open a ⋯ / right-click menu of that surface's actions. Plugins that need their own first-run install get a setup card that runs their installer in place. The sidebar organizes into Workspace, Tools and Manage groups.
* **Backups** — UpdraftPlus and WPvivid: sets listed, status cards, health check and Back up site now. BackWPup: local folder archives with run-now. All-in-One WP Migration: local .wpress exports with delete. Duplicator: packages with sizes from disk. Disembark: a status card, the exact connect command click-to-copy, scan sessions and cleanup.
* **Notifications** — comments, plugin/theme/core updates and new users, plus an admin-notice digest: notices other plugins print in wp-admin arrive as structured data (never their HTML or scripts), their action links run in the background, and any notice hides with Undo. Update everything runs plugins, themes and core in one click.
* **Extending** — one-filter APIs for any plugin to register views (status cards with optional charts, extra list views, tabs, status filters, detail layouts, actions with inline fields, bulk actions, schema-driven settings views including item-scoped settings, setup gates), editor panels, editor slash commands, traffic data (including per-day drill-down), cache purgers, spam providers, license providers, visibility providers, design libraries, page builders or block-inspector forms; the System page's Integrations card shows everything registered and flags descriptor problems.
* **Dark, light and System themes** — follows your OS until you choose; right-click the theme control for Dark / Light / System. Fonts are bundled locally.
* **Self-updater** — updates arrive from GitHub Releases through the normal WordPress updates UI.

== Installation ==

Try it instantly in WordPress Playground (launch link and blueprint): https://github.com/austinginder/minn-admin#minn-admin

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

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
