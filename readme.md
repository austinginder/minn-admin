# Minn Admin

**A reimagined WordPress admin experience — fast, focused and beautiful.**

Minn Admin serves a modern, minimal dashboard at `/minn-admin/` on your WordPress site. It's a
single-page app built on the WordPress REST API — no React, no build step, one vanilla-JS file —
and it lives *alongside* the classic wp-admin, which stays fully available.

![Minn Admin — Overview](.github/screenshot-dark.png)

[![Launch in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/#%7B%22%24schema%22%3A%22https%3A%2F%2Fplayground.wordpress.net%2Fblueprint-schema.json%22%2C%22landingPage%22%3A%22%2Fminn-admin%2F%22%2C%22meta%22%3A%7B%22title%22%3A%22Minn%20Admin%22%2C%22author%22%3A%22Austin%20Ginder%22%2C%22description%22%3A%22Launch%20Minn%20Admin%20from%20GitHub%20in%20WordPress%20Playground.%22%7D%2C%22preferredVersions%22%3A%7B%22php%22%3A%228.3%22%2C%22wp%22%3A%22latest%22%7D%2C%22features%22%3A%7B%22networking%22%3Atrue%7D%2C%22extraLibraries%22%3A%5B%22wp-cli%22%5D%2C%22steps%22%3A%5B%7B%22step%22%3A%22login%22%2C%22username%22%3A%22admin%22%2C%22password%22%3A%22password%22%7D%2C%7B%22step%22%3A%22setSiteOptions%22%2C%22options%22%3A%7B%22blogname%22%3A%22Minn%20Admin%20Playground%22%2C%22blogdescription%22%3A%22A%20disposable%20WordPress%20demo%20for%20Minn%20Admin.%22%2C%22permalink_structure%22%3A%22%2F%25postname%25%2F%22%7D%7D%2C%7B%22step%22%3A%22installPlugin%22%2C%22pluginData%22%3A%7B%22resource%22%3A%22git%3Adirectory%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Fdknauss%2Fminn-admin%22%2C%22ref%22%3A%22main%22%2C%22refType%22%3A%22branch%22%2C%22path%22%3A%22%2F%22%7D%2C%22options%22%3A%7B%22activate%22%3Atrue%2C%22targetFolderName%22%3A%22minn-admin%22%7D%2C%22ifAlreadyInstalled%22%3A%22overwrite%22%7D%2C%7B%22step%22%3A%22wp-cli%22%2C%22command%22%3A%22wp%20rewrite%20flush%20--hard%22%7D%5D%7D)

## Features

- **Overview** — real stat cards, an activity chart, and a recent-activity feed
- **Content** — posts, pages and custom post types with search, status pills and pagination
- **Media** — grid/list library, uploads, drag-and-drop, and a preview overlay with arrow-key navigation
- **Comments** — full moderation (pending / approved / spam / trash)
- **Orders** — WooCommerce orders with summary cards and line-item detail (when WooCommerce is active)
- **Users** — directory with search, create/edit users, roles, passwords, and **per-user login
  sessions with one-click sign-out**
- **Extensions** — activate, deactivate, delete and bulk-update plugins
- **Settings** — General, Writing, Reading and Discussion, plus built-in maintenance mode
- **Editor** — distraction-free, block-aware writing surface: native Gutenberg markup, slash
  commands, image insertion, categories, autosave, scheduling and one-click publish
- **Command palette** — ⌘K / Ctrl-K everywhere
- **Plugin surfaces** — bundled adapters for **Gravity Forms** (entries) and **Gravity SMTP**
  (email log), plus a one-filter API for any plugin to register its own view
- **Dark & light themes**, bundled fonts, zero external requests

## Install

1. Download or clone into `wp-content/plugins/minn-admin`.
2. Activate through the Plugins screen.
3. Visit `/minn-admin/` — also linked from the admin bar and the wp-admin menu.

Pretty permalinks recommended (clean routes like `/minn-admin/content`); without them the app
falls back to `/?minn_admin=1` with hash routing. Updates are delivered through the normal
WordPress updates UI via GitHub Releases.

## Extending

Any plugin can add a view to Minn with one filter — a declarative descriptor, no JavaScript
required. See [docs/for-plugin-authors.md](docs/for-plugin-authors.md), and
[docs/extension-api.md](docs/extension-api.md) for the design rationale.

## Documentation

- [Project goals](docs/goals.md)
- [Editor direction](docs/editor-direction.md)
- [For plugin authors](docs/for-plugin-authors.md)
- [Changelog](changelog.md)

## Development

Edit and go — there's no build step. Lint with `node --check assets/js/app.js` and
`php -l minn-admin.php`. Commit messages follow [Emoji-Log](https://github.com/ahmadawais/Emoji-Log).

## License

[MIT](LICENSE) © [Austin Ginder](https://austinginder.com)
