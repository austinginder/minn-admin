# Minn Admin

**A reimagined WordPress admin experience — fast, focused and beautiful.**

Minn Admin serves a modern, minimal dashboard at `/minn-admin/` on your WordPress site. It's a
single-page app built on the WordPress REST API — no React, no build step, one vanilla-JS file —
and it lives *alongside* the classic wp-admin, which stays fully available.

![Minn Admin — Overview](.github/screenshot-dark.png)

[![Launch in WordPress Playground](https://img.shields.io/badge/Launch-WordPress%20Playground-3858E9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/#%7B%22%24schema%22%3A%22https%3A%2F%2Fplayground.wordpress.net%2Fblueprint-schema.json%22%2C%22landingPage%22%3A%22%2Fminn-admin%2F%22%2C%22meta%22%3A%7B%22title%22%3A%22Minn%20Admin%22%2C%22author%22%3A%22Austin%20Ginder%22%2C%22description%22%3A%22Launch%20Minn%20Admin%20from%20GitHub%20in%20WordPress%20Playground.%22%7D%2C%22preferredVersions%22%3A%7B%22php%22%3A%228.3%22%2C%22wp%22%3A%22latest%22%7D%2C%22features%22%3A%7B%22networking%22%3Atrue%7D%2C%22steps%22%3A%5B%7B%22step%22%3A%22login%22%7D%2C%7B%22step%22%3A%22setSiteOptions%22%2C%22options%22%3A%7B%22blogname%22%3A%22Minn%20Admin%20Playground%22%2C%22blogdescription%22%3A%22A%20disposable%20WordPress%20demo%20for%20Minn%20Admin.%22%2C%22permalink_structure%22%3A%22%2F%25postname%25%2F%22%7D%7D%2C%7B%22step%22%3A%22installPlugin%22%2C%22pluginData%22%3A%7B%22resource%22%3A%22url%22%2C%22url%22%3A%22https%3A%2F%2Fgithub.com%2Faustinginder%2Fminn-admin%2Freleases%2Flatest%2Fdownload%2Fminn-admin.zip%22%7D%2C%22options%22%3A%7B%22activate%22%3Atrue%2C%22targetFolderName%22%3A%22minn-admin%22%7D%2C%22ifAlreadyInstalled%22%3A%22overwrite%22%7D%5D%7D)

<!--
  The badge/launch link above and the one in readme.txt are the URL-encoded contents of
  .wp-playground/blueprint.json (inlined because Playground intermittently fails to fetch
  a remote blueprint-url). blueprint.json is the source of truth — after editing it, regenerate
  the fragment and paste it after `https://playground.wordpress.net/#` in both readmes:

    node -e 'const b=require("fs").readFileSync(".wp-playground/blueprint.json","utf8");console.log("https://playground.wordpress.net/#"+encodeURIComponent(JSON.stringify(JSON.parse(b))))'
-->

## Features

- **Overview** — stat cards, a real **Traffic chart** with hover details when an analytics plugin
  is installed (Koko Analytics, WP Statistics, Burst, Independent Analytics, AnalyticsWP), and a
  recent-activity feed
- **Content** — posts, pages and custom post types with search, category/tag filters, status
  pills, and **bulk actions** (set status or trash, with shift-click range select)
- **Media** — grid/list library, uploads, drag-and-drop, and a preview overlay with arrow-key
  navigation and in-place **title & alt text editing**
- **Comments** — full moderation (pending / approved / spam / trash)
- **Orders** — WooCommerce orders with summary cards, line-item detail and **status changes**
  (when WooCommerce is active)
- **Users** — directory with search, a role filter, create/edit users, roles, passwords, and
  **per-user login sessions with one-click sign-out**
- **AI Access** — generate revocable **application passwords** for AI agents plus a site-tailored
  **agent guide** (markdown REST reference) to hand to a coding agent; configuration work stays
  out of Minn by design
- **Extensions** — install plugins and themes from WordPress.org or zip upload, activate,
  deactivate, delete, per-item and bulk updates, and a Themes tab with screenshots
- **Settings** — General (with timezone picker), Writing, Reading, Discussion and Permalinks
  (structure presets + custom, with automatic rewrite flushing), plus built-in maintenance mode
- **Editor** — distraction-free, block-aware writing surface: native Gutenberg markup with
  complex blocks preserved byte-for-byte as **read-only islands**, slash commands, tables,
  syntax-highlighted code blocks with a language picker, featured images, image insertion,
  categories & tags, revisions with restore, autosave, scheduling and one-click publish
- **Command palette** — ⌘K / Ctrl-K everywhere
- **Plugin surfaces** — bundled adapters for **Gravity Forms** (entries), **Gravity SMTP**
  (email log with a real HTML preview and resend), **Simple History** (activity log),
  **Redirection** (redirects, editable in place) and **ACF** (editor panels), plus one-filter
  APIs for any plugin to register views, editor panels, traffic data or block-inspector forms
- **Dark & light themes**, bundled fonts, zero external requests from the app, responsive down
  to phones

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
- [Block inspector (design, v0.4.0)](docs/block-inspector.md)
- [For plugin authors](docs/for-plugin-authors.md)
- [Changelog](changelog.md)

## Development

Edit and go — there's no build step. Lint with `node --check assets/js/app.js` and
`php -l minn-admin.php`. Commit messages follow [Emoji-Log](https://github.com/ahmadawais/Emoji-Log).

## License

[MIT](LICENSE) © [Austin Ginder](https://austinginder.com)
