=== Minn Admin ===
Contributors: austinginder
Tags: admin, dashboard, ui, admin theme
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A reimagined WordPress admin experience — fast, focused and beautiful.

== Description ==

Minn Admin serves a modern, minimal admin dashboard at `/minn-admin/` on your site. It talks to the WordPress REST API and works alongside the classic wp-admin (which stays fully available).

Features:

* **Overview** — real stats (posts, pages, comments, media storage), an activity chart and a recent-activity feed.
* **Content** — posts, pages and custom post types with search, status pills and Load-more pagination.
* **Media** — grid and list library views with real thumbnails, uploads and drag-and-drop.
* **Comments** — moderation with Pending/Approved/Spam/Trash tabs and one-click actions.
* **Extensions** — activate/deactivate plugins with a switch, see and run available updates ("Update all").
* **Settings** — General, Writing, Reading and Discussion sections, plus a built-in maintenance mode.
* **Editor** — distraction-free, block-aware writing surface with autosave and one-click publish.
* **Command palette** — press ⌘K / Ctrl-K anywhere.
* **Notifications** — pending comments, plugin/core updates and new users, with unread tracking.
* **Dark & light themes** — toggle persists per browser. Fonts are bundled locally.
* **Self-updater** — updates arrive from GitHub Releases through the normal WordPress updates UI.

== Installation ==

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

= 0.1.0 =
* Initial release.
