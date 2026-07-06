=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.1
License: MIT
License URI: https://opensource.org/licenses/MIT

A reimagined WordPress admin experience. Fast, focused and beautiful.

== Description ==

Minn Admin serves a modern, minimal admin dashboard at `/minn-admin/` on your site. It talks to the WordPress REST API and works alongside the classic wp-admin (which stays fully available).

Features:

* **Overview** — real stats, a Traffic chart with hover details when an analytics plugin is installed (Koko Analytics, WP Statistics, Burst, Independent Analytics, AnalyticsWP), and a recent-activity feed.
* **Content** — posts, pages and custom post types sorted by publish date (scheduled posts lead), with search, category/tag filters, status pills, bulk actions, and right-click row actions for quick publish/draft/trash, view, and a block-editor escape.
* **Media** — grid and list library views with real thumbnails, uploads, drag-and-drop, a preview overlay with in-place title and alt text editing, a right-click menu, and a built-in image editor (rotate and crop, saved as a new copy).
* **Comments** — moderation with Pending/Approved/Spam/Trash tabs, one-click actions, inline replies, and a right-click menu.
* **Orders** — WooCommerce orders with summary cards, line-item detail and status changes (when WooCommerce is active).
* **Users** — a role filter, create/edit users, roles, passwords, and per-user login sessions with one-click sign-out.
* **AI Access** — application passwords for AI agents plus a generated, site-tailored agent guide.
* **Extensions** — install plugins and themes from WordPress.org or zip upload; activate, deactivate, delete, per-item and bulk updates; Themes tab with screenshots; cards wear real wp.org icons linked to the directory, with linked author lines.
* **Post Types & Taxonomies** — manage custom post type and taxonomy definitions through whichever manager owns them (ACF, Custom Post Type UI, or Minn's own store); code-registered ones shown read-only.
* **Settings** — General (with timezone picker), Writing, Reading, Discussion and Permalinks sections, plus a built-in maintenance mode.
* **Editor** — a distraction-free, block-aware writing surface that stores native Gutenberg markup. Markdown typing conventions (bold, italic, strike, inline code, links, headings, lists, quotes, code fences, dividers), a link popover on ⌘K, text alignment, table and image controls with island-style cutouts, complex blocks preserved byte-for-byte as configurable islands with real front-end styling in previews, slash commands with type-to-filter, syntax-highlighted code blocks, word count and reading time, featured images, categories and tags, revisions with restore and backup recovery, status-aware autosave (published posts back up to revisions; only Update goes live), scheduling with a themed date-time picker and one-click publish (⌘⏎). Revision diffs show a side-by-side, word-marked diff against the current content. An outline panel lists headings as a live table of contents; focus mode (⌘⇧D) fades all but the current paragraph; outline mode (⌘⇧O) leaves just the writing and the outline. The internal link picker searches your own posts from the link popover. Paste cleanup turns Word / Google Docs / web HTML into the safe subset; paste or drag an image to upload it at the caret with an inline caption; the publish sidebar edits slug, visibility (public / password / private), per-post discussion and sticky; deleting an embed or table row offers an Undo toast.
* **Never lose work** — post locking on WordPress's own `_edit_lock` (Minn, the classic editor and Gutenberg honor each other, with takeover), plus a localStorage crash net that snapshots every edit within ~1.2s (before the first autosave) and offers recovery on the next open.
* **Page builders** — build a page with Divi, Elementor, Brizy, Beaver Builder, Etch, Bricks or WPBakery and keep managing it from Minn: builder-owned pages are marked, edited through the builder's own chrome-free surface (no wp-admin screen), and fenced so a stray Minn edit can't break the builder's canvas. + New can start a page in any active builder. Third parties register via the `minn_admin_page_builders` filter.
* **System** — a developer diagnostics page: a health strip over WordPress / PHP / database (largest tables, an autoloaded-options breakdown with top offenders, expired-transient bloat, cron health) / server facts, an installed extensions manifest, live debug toggles that safely rewrite wp-config.php, a clickable debug-log viewer, and one-click Copy report as markdown.
* **SEO panel** — Yoast SEO or Rank Math title, meta description and focus keyword in the editor sidebar.
* **Command palette** — press ⌘K / Ctrl-K anywhere.
* **Plugin adapters** — Gravity Forms (readable entries with real field labels, plus a Forms view with activate/deactivate), Gravity SMTP (HTML email preview and resend), Simple History / WP Activity Log / Activity Log / Stream (audit logs), Redirection / Safe Redirect Manager / Simple 301 Redirects (create, search, edit redirects), ACF and SEO views built in, plus one-filter APIs for other plugins (views, editor panels, traffic data, block-inspector forms).
* **Notifications** — pending comments, plugin/core updates and new users; click an item to jump to it.
* **Dark & light themes** — toggle persists per browser. Fonts are bundled locally.
* **Self-updater** — updates arrive from GitHub Releases through the normal WordPress updates UI.

== Installation ==

Try it instantly in WordPress Playground (launch link and blueprint): https://github.com/austinginder/minn-admin#minn-admin

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

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
