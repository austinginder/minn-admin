=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
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
* **Settings** — General (with timezone picker), Writing, Reading, Discussion and Permalinks sections, plus a built-in maintenance mode.
* **Editor** — distraction-free, block-aware writing surface: complex blocks preserved byte-for-byte as read-only islands, slash commands, tables, syntax-highlighted code blocks with a language picker, featured images, categories and tags, revisions with restore, autosave, scheduling and one-click publish.
* **Command palette** — press ⌘K / Ctrl-K anywhere.
* **Plugin adapters** — Gravity Forms, Gravity SMTP (HTML email preview and resend), Simple History, Redirection (editable in place) and ACF views built in, plus one-filter APIs for other plugins (views, editor panels, traffic data, block-inspector forms).
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
