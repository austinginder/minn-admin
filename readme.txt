=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A reimagined WordPress admin experience — fast, focused and beautiful.

== Description ==

Minn Admin serves a modern, minimal admin dashboard at `/minn-admin/` on your site. It talks to the WordPress REST API and works alongside the classic wp-admin (which stays fully available).

Features:

* **Overview** — real stats, a Traffic chart with hover details when an analytics plugin is installed (Koko Analytics, WP Statistics, Burst, Independent Analytics, AnalyticsWP), and a recent-activity feed.
* **Content** — posts, pages and custom post types with search, category/tag filters, status pills, and bulk actions (set status or trash, shift-click range select).
* **Media** — grid and list library views with real thumbnails, uploads, drag-and-drop, and a preview overlay with in-place title and alt text editing.
* **Comments** — moderation with Pending/Approved/Spam/Trash tabs and one-click actions.
* **Orders** — WooCommerce orders with summary cards, line-item detail and status changes (when WooCommerce is active).
* **Users** — a role filter, create/edit users, roles, passwords, and per-user login sessions with one-click sign-out.
* **AI Access** — application passwords for AI agents plus a generated, site-tailored agent guide.
* **Extensions** — install plugins and themes from WordPress.org or zip upload; activate, deactivate, delete, per-item and bulk updates; Themes tab with screenshots.
* **Post Types & Taxonomies** — manage custom post type and taxonomy definitions through whichever manager owns them (ACF, Custom Post Type UI, or Minn's own store); code-registered ones shown read-only.
* **Settings** — General (with timezone picker), Writing, Reading, Discussion and Permalinks sections, plus a built-in maintenance mode.
* **Editor** — a distraction-free, block-aware writing surface that stores native Gutenberg markup. Markdown typing conventions (bold, italic, strike, inline code, links, headings, lists, quotes, code fences, dividers), a link popover on ⌘K, text alignment, table and image controls with island-style cutouts, complex blocks preserved byte-for-byte as configurable islands with real front-end styling in previews, slash commands with type-to-filter, syntax-highlighted code blocks, word count and reading time, featured images, categories and tags, revisions with restore and backup recovery, status-aware autosave (published posts back up to revisions — only Update goes live), scheduling and one-click publish.
* **SEO panel** — Yoast SEO or Rank Math title, meta description and focus keyword in the editor sidebar.
* **Command palette** — press ⌘K / Ctrl-K anywhere.
* **Plugin adapters** — Gravity Forms (readable entries with real field labels, plus a Forms view with activate/deactivate), Gravity SMTP (HTML email preview and resend), Simple History, Redirection / Safe Redirect Manager / Simple 301 Redirects (create, search, edit redirects), ACF and SEO views built in, plus one-filter APIs for other plugins (views, editor panels, traffic data, block-inspector forms).
* **Notifications** — pending comments, plugin/core updates and new users; click an item to jump to it.
* **Dark & light themes** — toggle persists per browser. Fonts are bundled locally.
* **Self-updater** — updates arrive from GitHub Releases through the normal WordPress updates UI.

== Installation ==

Try it instantly in WordPress Playground — launch link and blueprint: https://github.com/austinginder/minn-admin#minn-admin

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

= 0.6.0 =
* The editor release: full markdown typing conventions, inline code with boundary-safe typing, link popover on ⌘K, text alignment, table and image controls with island-style cutouts, word count and reading time, SVG toolbar, sticky toolbar with toggleable block buttons.
* Status-aware autosave: drafts save in place, published posts back up to autosave revisions — only Update goes live. Save draft button, ⌘S, backup-restore banner.
* Island previews render with the site's real front-end styles; embeds render for real, with in-place Change URL / Replace images.
* SEO editor panel (Yoast / Rank Math) and a much better Gravity Forms surface: readable entry detail plus a Forms view with activate/deactivate.
* Fixes: Backspace can no longer destroy an adjacent embed, images insert at the caret, x.com tweets embed again on WordPress 7.0, serialized markup stays clean. Full details in changelog.md.

= 0.5.0 =
* Taxonomies manager, redirect creation and search, image controls, video/audio blocks editable, Query Monitor integration, attribute passthrough for simple blocks, activity chart drill-down. Full details in changelog.md.

= 0.4.1 =
* Fixed: updating an active plugin from the Extensions per-plugin update button no longer deactivates it (including Minn updating itself).

= 0.4.0 =
* Block inspector: configure complex blocks (islands) in place — schema-driven forms, add/remove/reorder children, wrapper-text edits, live server-rendered previews, and removal.
* Insert custom blocks from the slash menu (plugins declare templates via minn_admin_block_forms; Anchor Blocks ships five).
* Post Types manager: create/edit/remove CPT definitions through ACF, Custom Post Type UI, or Minn's own store; code-registered types shown read-only.
* Settings: site icon with drag & drop, membership + default role, comment moderation toggles, searchable comboboxes for timezone/role/category/pages.
* Image picker: drag & drop upload used immediately. Code blocks: language config chip. AnalyticsWP traffic adapter.
* Fixed stale Overview after switching plugins. Full details in changelog.md.

= 0.3.0 =
* Content: bulk actions (set status, trash) with shift-click range select, plus category and tag filters.
* Editor: tags — add existing or new tags inline with suggestions, alongside categories.
* Email Log: real HTML preview in a sandboxed frame, open-raw and resend actions.
* Orders: change an order's status from the detail modal.
* Media: edit an image's title and alt text in place.
* Users: filter the directory by role.
* Redirects: edit source, target and HTTP status in place — via a new surface `edit` API any adapter can use.
* Smoother in-place loading, horizontally scrolling tab strips, and a proper phone layout (compact topbar, wrapping toolbars, tables that drop columns instead of clipping).

= 0.2.0 =
* Editor: block islands hardening, tables, verse, citations, featured images, code-block language picker with syntax highlighting on dark surfaces, revision restore.
* Install plugins and themes from WordPress.org search or zip upload; Themes management tab.
* AI Access: application passwords and a generated agent guide.
* Traffic chart with four analytics adapters; Simple History and Redirection views.
* Per-item notification reads, cleaned plugin names, no overlay flashing, and many fixes. Full details in changelog.md.

= 0.1.0 =
* Initial release.
