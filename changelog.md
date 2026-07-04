# Changelog

## **v0.2.0** - Unreleased

### Added
* **Code block languages:** A language picker (PHP, JS, HTML, CSS, bash, JSON, Python, SQL) appears in the editor toolbar whenever the caret is inside a code block. The choice is stored as a Prism-style `language-*` class on the `<code>` element — portable and theme-highlighter compatible — and drives language-aware syntax highlighting, including PHP `$variables` and `<?php` tags.
* **Dark code surfaces:** Code blocks in the editor and previews always render on a dark surface with a fixed highlight palette, so syntax colors are equally readable in light and dark themes.

* **Plugin installs:** An "Add plugin" modal on Extensions with a WordPress.org search picker (server-side proxy — the app never talks to external hosts), install/activate in place, and zip upload via drag-and-drop or file picker.
* **AI Access:** Your account now manages **application passwords** — create a revocable credential for an AI agent (shown once, with copy-password and copy-curl buttons) and revoke any credential — plus a generated **agent guide**: a markdown REST reference tailored to what's installed on the site (core routes, WooCommerce, Gravity Forms, ACF, Minn extras), ready to hand to a coding agent.
* **Notifications:** Individual notifications mark read on click and navigate to the thing they're about (comments → moderation, updates → Extensions, new users → Users).

### Fixed
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
