# Changelog

## **v0.4.0** - Unreleased

### Added
* **Block inspector:** Complex blocks (islands) are no longer opaque. Every island's chip is now a ⚙ button that opens an inspector popover: the block's attribute schema is fetched from `wp/v2/block-types`, a form is generated from it (strings, numbers, booleans, enums), and edits rewrite the attributes JSON in the block comment — Gutenberg-escaped, spliced back verbatim, byte-safety model unchanged. Works one level deep too: nested self-closing children (e.g. Anchor Blocks conversation messages) each get their own form section. Attributes stored in saved HTML (`source`-backed) are correctly left alone.
* **Real island previews:** Islands render their actual content in the editor via a new `minn-admin/v1/render-blocks` endpoint (server-side `do_blocks`, `edit_posts`) — dynamic blocks and nested dynamic children show what the site will show instead of an empty "Dynamic block" card. Previews refresh live after inspector edits. Best-effort: a misbehaving render callback never breaks the editor.
* **Code block language attribute:** `core/code` blocks carrying `{"language":…}` in the block comment (the attr dialect) are now fully editable instead of islanded — the existing toolbar language picker reads and writes the attribute, and serialization preserves the incoming dialect (attr stays attr, class stays class). `markup` added to the language list.
* **Site icon:** Settings → General can now set the site icon — drag & drop an image, choose from the media library, or remove it, with a live preview.
* **More settings:** Membership (anyone can register) and New user default role (General); Convert emoticons (Writing); Moderate all comments, Registered-users-only commenting and Show avatars (Discussion) — exposed over `wp/v2/settings` via `register_setting`, with role writes validated server-side.

## **v0.3.0** - July 4, 2026

### Added
* **User role filter:** The Users view gains role tabs (All plus every registered role), filtering server-side via `wp/v2/users?roles=`.
* **HTML email preview:** The Email Log (Gravity SMTP) detail now renders the real HTML message in a sandboxed iframe — the email as it actually looks — in a wider modal, with **Open raw** (opens the message in a new tab) and **Resend** (re-dispatches to the original recipients) actions.
* **Category & tag filters:** The Content list gains category and tag dropdowns (post taxonomies), and the editor sidebar gains a **Tags** box — add existing or brand-new tags inline (with suggestions), remove with a click — alongside the existing categories picker.
* **Order status changes:** The order detail modal can set an order's status (processing, completed, on-hold, …) straight from Minn via `wc/v3`, no longer read-only.
* **Media editing:** The media overlay is larger and lets you edit an image's **title and alt text** in place instead of bouncing to wp-admin.
* **Content bulk actions:** Select rows in Content to bulk-change status or move to trash. **Shift-click** a checkbox to select a whole range; **Esc** clears the selection.
* **Redirect editing:** The Redirects (Redirection) detail modal can now edit a redirect's source URL, target URL and HTTP status in place — via a small generic "editable fields" capability on the surface API that other adapters can opt into.
* **Permalink settings:** The Settings → Permalinks stub is now real. Core leaves `permalink_structure` out of `wp/v2/settings`, so Minn exposes its own `minn-admin/v1/permalinks` endpoint (GET/POST, `manage_options`): structure presets or a custom structure with tag validation, category/tag bases, the same normalization as options-permalink.php (including the `/index.php` prefix when URL rewriting is unavailable), and an automatic rewrite flush. If a save flips the site between pretty and plain permalinks, the app reloads itself at its new home (`/minn-admin/` ↔ `?minn_admin=1`).

### Improved
* **Toolbars stay put:** Switching a user role (or a content category/tag filter) no longer blanks the whole view — the toolbar stays in place and only the table dims while the new data loads.
* **Scrolling tabs:** Tab strips with many entries (all the user roles on a big site) now scroll horizontally on one line instead of wrapping and clipping.
* **Phone layout:** A real mobile breakpoint. The topbar compacts to the essentials, toolbars wrap (tab strip on its own scrollable row, full-width search), the Content/Orders/Users tables drop secondary columns instead of clipping, plugin surface tables scroll sideways, the settings nav becomes a scrollable row, and the app height tracks mobile Safari's collapsing URL bar. Touch niceties: visible media prev/next arrows, larger checkboxes, and inputs sized so iOS doesn't zoom on focus.

### Fixed
* **Resend sends to every recipient:** Resend in the Email Log now extracts the full To list from the event record (scoped to the `to` collection, so cc/bcc addresses are never promoted into the To header). Previously it reused the truncated display string and silently dropped recipients beyond the first two.
* **Open raw shows source, not a live page:** The Email Log's raw view opens the message as plain text. Opening it as HTML would have run any scripts in the logged email with the app's own origin.

## **v0.2.0** - July 3, 2026

### Added
* **Code block languages:** A language picker (PHP, JS, HTML, CSS, bash, JSON, Python, SQL) appears in the editor toolbar whenever the caret is inside a code block. The choice is stored as a Prism-style `language-*` class on the `<code>` element — portable and theme-highlighter compatible — and drives language-aware syntax highlighting, including PHP `$variables` and `<?php` tags.
* **Dark code surfaces:** Code blocks in the editor and previews always render on a dark surface with a fixed highlight palette, so syntax colors are equally readable in light and dark themes.
* **Theme installs:** An "Add theme" flow on the Themes tab with a WordPress.org search picker (screenshot cards, install and activate in place) and zip upload via drag-and-drop or file picker.
* **Redirects:** A bundled **Redirection** adapter lists redirects (source, target, status code, hits, last access) straight from its redirection/v1 API, with enable, disable and delete actions via its bulk endpoints.
* **Activity Log:** A bundled **Simple History** adapter surfaces the audit log as a native Minn view — events with who/level/when columns, Warnings and Errors tabs, and detail modals — visibility following Simple History's own view capability.
* **Plugin installs:** An "Add plugin" modal on Extensions with a WordPress.org search picker (server-side proxy — the app never talks to external hosts), install/activate in place, and zip upload via drag-and-drop or file picker.
* **AI Access:** Your account now manages **application passwords** — create a revocable credential for an AI agent (shown once, with copy-password and copy-curl buttons) and revoke any credential — plus a generated **agent guide**: a markdown REST reference tailored to what's installed on the site (core routes, WooCommerce, Gravity Forms, ACF, Minn extras), ready to hand to a coding agent.
* **Notifications:** Individual notifications mark read on click and navigate to the thing they're about (comments → moderation, updates → Extensions, new users → Users).
* **Featured images:** A Featured image card in the editor sidebar with a thumbnail preview, set/replace via the media picker, and remove. Saves through the normal post save and autosave.
* **Tables, verse and citations in the editor:** Tables are now editable inline (insert a 2×2 via the `/` menu, edit cells directly; `hasFixedLayout` round-trips), verse and preformatted blocks keep their block type on save instead of becoming code blocks, and quote citations (`<cite>`) are preserved.
* **Theme management:** Extensions gains a Themes tab with screenshots, active/update badges, activate (with confirmation), per-theme update, and delete for inactive themes.
* **Traffic on the Overview:** A new `minn_admin_traffic` filter lets analytics plugins power the Overview chart. When a provider is active, the Activity chart becomes a real Traffic chart (visitors per day/week with a source badge) and a Visitors stat card with a period-over-period delta leads the dashboard. Ships with adapters for **Koko Analytics**, **WP Statistics**, **Burst Statistics** and **Independent Analytics**, each reading the plugin's local tables directly; sites without an analytics plugin keep the Activity chart. Traffic bars stack pageviews behind visitors, and hovering any bar shows a Koko-style card with the date, visitors and pageviews (or event count on the Activity chart).
* **About Minn:** A help icon in the topbar (and ⌘K entry) opens the philosophy page — what Minn is for, the AI-agent configuration model, and the no-lock-in guarantees — with links to the docs.

### Fixed
* Plugin names are cleaned of keyword-stuffed suffixes everywhere ("Rank Math SEO", not "Rank Math SEO – AI SEO Tools to Dominate SEO Rankings") and HTML entities are decoded in wp.org search results; full names remain available on hover.
* Slash-menu inserts (table, divider, image) land at the top level of the document instead of nested inside the current block's wrapper, and wrapper divs created by contenteditable are serialized as their real child blocks instead of raw HTML.
* Stat cards flow into a single row regardless of count, and activity entries with invalid modified dates are skipped.
* Panels and modals no longer replay their entrance animation on every re-render — the notification panel opened with a double flash and flashed on tab switches, and the plugin-search modal flashed on each keystroke.
* Line breaks inside code blocks (entered as `<br>` by the browser) are preserved when saving.
* Classic-content saves now strip syntax-highlight decoration from code blocks before writing to the database.
* Elementor's internal post types (templates, floating elements) no longer appear as Content tabs.

## **v0.1.0** - July 3, 2026

### Added
* **Minn Admin app:** A reimagined WordPress admin served at `/minn-admin/` — a standalone single-page app that talks to the WordPress REST API and lives alongside the classic wp-admin.
* **Overview:** Real stat cards (posts, pages, comments, media storage), an activity chart with 7d/30d/90d ranges, and a recent-activity feed.
* **Content:** Combined posts, pages and custom post types with status pills, author and modified columns, title search, and Load-more pagination.
* **Media:** Grid and list library views with real thumbnails, an Upload button, and drag-and-drop uploads from anywhere in the app. Clicking a file opens a preview overlay (image/video/audio playback, metadata, copy URL, open, delete). Arrow keys and on-screen buttons step through the library inside the preview. The Upload button reveals a drag-and-drop zone with a file picker.
* **Orders:** WooCommerce orders view (when WooCommerce is active) with monthly summary cards, status tabs, and an order detail overlay with line items.
* **Users:** Searchable user directory with roles and registration dates, plus full user management — create users (with password generator), edit name/email/role, set new passwords, and delete with content reassignment. Each user's active **login sessions** are listed (browser, IP, sign-in time) with per-session sign-out and "Sign out everywhere".
* **Plugin surfaces:** A declarative extension API (`minn_admin_surfaces` filter) that renders third-party plugin data with Minn's generic list/tabs/detail/action primitives — no JavaScript required from the integrating plugin. Ships with two bundled adapters: **Gravity Forms** (entries per form, field-label resolution, trash action) and **Gravity SMTP** (email log via a custom-table REST shim). See `docs/for-plugin-authors.md`.
* **Editor panels:** A second extension class for per-post fields (`minn_admin_editor_panels` filter) rendered in the editor sidebar with native inputs and autosave. Ships with an **ACF / ACF Pro** adapter: field groups with "Show in REST API" appear as editable panels (text, textarea, number, select, radio, true/false…), with advanced field types deferring to wp-admin.
* **Clean URLs:** Path-based routing (`/minn-admin/content` instead of `#/content`) with pretty permalinks, including deep links, back/forward support, and automatic migration of legacy hash links. Falls back to hash routing on plain permalinks.
* **Comments:** Moderation view with Pending/Approved/Spam/Trash tabs and approve, spam, trash, restore and delete actions, plus a pending-count badge in the sidebar.
* **Extensions:** Activate/deactivate plugins with a switch, update badges, and one-click "Update all". Inactive plugins can be deleted from the card. Plugins with updates get a per-plugin "Update → x.y" button.
* **Settings:** General, Writing, Reading and Discussion sections backed by the core settings endpoint, plus a built-in maintenance mode. General includes a timezone picker, date/time formats and week start.
* **Editor:** Distraction-free, block-aware writing surface. New posts save native Gutenberg block markup; complex blocks render as atomic read-only islands preserved byte-for-byte on save, so text stays editable around any layout; classic posts stay classic. Autosave, one-click publish, slash commands (type `/` for headings, quotes, code, lists, images, dividers), code blocks get dependency-free syntax highlighting, image insertion from the media library, editable categories, and post scheduling with a date/time picker. A Publish-panel link previews drafts or views published posts on the frontend, and a History card lists recent revisions with preview and one-click restore. Posts and pages can be moved to trash from the editor. See `docs/editor-direction.md` for the hybrid-editor rationale.
* **Command palette:** ⌘K / Ctrl-K everywhere, with navigation and actions.
* **Notifications:** Pending comments, plugin/core updates and new users with per-user unread tracking.
* **Themes:** Dark and light, persisted per browser. Bundled variable fonts (Hanken Grotesk, JetBrains Mono) — no external font requests.
* **Self-updater:** Update checks against the GitHub manifest with install from GitHub Releases.
