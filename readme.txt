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

Try it instantly in WordPress Playground: https://playground.wordpress.net/#%7B%22%24schema%22%3A%22https%3A%2F%2Fplayground.wordpress.net%2Fblueprint-schema.json%22%2C%22landingPage%22%3A%22%2Fminn-admin%2F%22%2C%22meta%22%3A%7B%22title%22%3A%22Minn%20Admin%22%2C%22author%22%3A%22Austin%20Ginder%22%2C%22description%22%3A%22Launch%20Minn%20Admin%20from%20GitHub%20in%20WordPress%20Playground.%22%7D%2C%22preferredVersions%22%3A%7B%22php%22%3A%228.3%22%2C%22wp%22%3A%22latest%22%7D%2C%22features%22%3A%7B%22networking%22%3Atrue%7D%2C%22extraLibraries%22%3A%5B%22wp-cli%22%5D%2C%22steps%22%3A%5B%7B%22step%22%3A%22login%22%2C%22username%22%3A%22admin%22%2C%22password%22%3A%22password%22%7D%2C%7B%22step%22%3A%22setSiteOptions%22%2C%22options%22%3A%7B%22blogname%22%3A%22Minn%20Admin%20Playground%22%2C%22blogdescription%22%3A%22A%20disposable%20WordPress%20demo%20for%20Minn%20Admin.%22%2C%22permalink_structure%22%3A%22%2F%25postname%25%2F%22%7D%7D%2C%7B%22step%22%3A%22installPlugin%22%2C%22pluginData%22%3A%7B%22resource%22%3A%22git%3Adirectory%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fdknauss%2Fminn-admin%22%2C%22ref%22%3A%22main%22%2C%22refType%22%3A%22branch%22%2C%22path%22%3A%22%2F%22%7D%2C%22options%22%3A%7B%22activate%22%3Atrue%2C%22targetFolderName%22%3A%22minn-admin%22%7D%2C%22ifAlreadyInstalled%22%3A%22overwrite%22%7D%2C%7B%22step%22%3A%22wp-cli%22%2C%22command%22%3A%22wp%20rewrite%20flush%20--hard%22%7D%5D%7D

1. Upload the `minn-admin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Visit `/minn-admin/` (also linked from the admin bar and the wp-admin menu).

Pretty permalinks are recommended. Without them the app is served at `/?minn_admin=1`.

== Changelog ==

= 0.1.0 =
* Initial release.
