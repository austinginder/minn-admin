# Using Minn Admin

*A guide for the people who run their site with Minn: writing, moderating,
checking on things, keeping plugins current. No code in here. If you build
plugins, you want [for-plugin-authors.md](for-plugin-authors.md) instead.*

*Current as of v0.21.0. This file ships inside the plugin, so the copy you
are reading always matches the version you have installed.*

## What Minn is (and is not)

Minn Admin is a second front door to your WordPress site. It lives at
`yoursite.com/minn-admin/` and covers the daily work: content, media,
comments, orders, users, plugins, updates and settings, in a calm interface
with none of the banners and upsells that crowd a typical dashboard.

Three things to know before anything else:

1. **wp-admin is still there.** Minn replaces nothing. The classic admin
   keeps working at `/wp-admin/`, and Minn links you to it whenever a job is
   better done there. You can use both side by side forever.
2. **Everything Minn writes is native WordPress.** Posts are normal block
   markup, settings are normal options. Deactivate the plugin and your site
   is exactly as it was, with nothing to migrate and nothing lost.
3. **You only see what your account can do.** Minn checks the same
   WordPress permissions as wp-admin. An author sees writing tools; the
   Extensions and Settings areas only appear for accounts allowed to use
   them.

## Getting around

**The sidebar** has three groups:

- **Workspace** — the daily material: Overview, Content, Media, Comments,
  and (with WooCommerce) Orders, Products, Coupons, Customers. Plugins with
  an inbox of their own (form entries, for example) can appear here too.
- **Tools** — site plumbing contributed by your plugins: form entries, mail
  logs, activity logs, redirects, backups, snippets.
- **Manage** — the site itself: Extensions, Users, Menus and Widgets (on
  classic themes), Structure, System, Settings.

Group headings collapse when clicked, and the sidebar remembers your
arrangement. Counts on Content, Comments and Orders show pending work.

**The command palette** is the fastest way anywhere. Press **⌘K**
(Ctrl+K on Windows and Linux), start typing, and jump to any view, any
post, or run a command directly: "Clear site cache", "Back up site now",
"Create new page". If you learn one thing from this guide, learn ⌘K.

**Right-click is real in Minn.** This is the least discoverable thing in
the app, so here it is in plain terms: rows in Content and Media have
right-click menus (open, duplicate, publish, trash), comments have them,
the theme toggle has one, plugin-contributed sidebar entries have one
(that's how you hide them), and group headings in the editor's block
library have one too. When in doubt, try a right-click.

**One view, many plugins.** Where several plugins do the same job (three
form plugins, two backup plugins), Minn shows one sidebar entry with a
switcher inside, instead of three lookalike menus. The view stays the same;
the provider changes.

**The topbar** carries the page title, a View site link, the theme toggle
(system, light, dark), notifications, and the New button. Amber chips
appear here only when something needs you: a pending WordPress update, or
a site that is not fully public.

## Writing

Press **New** (or ⌘K, "Create new post") and write. The editor is built
for drafting, not for page design:

- **Type markdown, get formatting.** `#` for a heading, `-` for a list,
  `>` for a quote, `**bold**`, `` `code` ``, `[link](url)`, `---` for a
  divider, triple backticks for a code block. All applied as you type.
- **The slash menu**: type `/` on an empty line for headings, lists,
  images, embeds, tables and more. **⌘/** opens the full block library,
  including your plugins' blocks and entire designs and patterns you can
  drop in.
- **Paste from anywhere.** Word, Google Docs and web pages paste in clean.
  A pasted image or a lone URL to a tweet or video becomes the real thing.
- **Complex blocks are kept safe, untouched.** If a post contains a block
  Minn's editor doesn't natively edit (a plugin's fancy block, a Gutenberg
  layout), it appears as a framed island rendered with your site's real
  styling. Its text and images are usually editable in place via the ⚙
  chip; its structure is preserved byte for byte. One click on "Block
  editor ↗" opens the same post in Gutenberg whenever you want the full
  toolkit. Posts built with page builders (Elementor, Bricks, Divi and
  friends) open read-only with an "Edit in your builder" button, because
  the builder owns that content.
- **Saving is status-aware.** Drafts autosave as you write. Published
  posts never change under you: edits back up silently, and the live post
  only updates when you press Update. A crash net keeps a local copy of
  unsaved work in your browser and offers to restore it.
- **History** shows revisions with side-by-side comparisons; restore takes
  one click. If someone else has the post open (in Minn or wp-admin), you
  will be told before you can both edit it.
- **Focus modes:** ⌘⇧D fades everything but the paragraph you are writing;
  ⌘⇧O reduces the screen to the text and an outline. ⌘⇧F is find and
  replace. A word-count pill sits at the bottom right; click it to set a
  session writing goal.

## Daily site care

**Comments**: approve, reply, edit in place, mark spam, or block a
commenter (future comments from that address go straight to the trash,
with Undo). Bulk-select works across the list.

**Media**: drop files anywhere in the app to upload. Images can be
cropped and rotated right in Minn, and regenerating thumbnails is one
button when that plugin is installed.

**Notifications** (the bell) collects what actually needs attention:
pending comments, available updates, and Notices.

**About Notices**: other plugins' wp-admin banners never render inside
Minn. Instead, Minn reads them in the background and reduces each one to
plain text with its buttons intact, attributed to the plugin that posted
it. Act on one ("No thanks", "Allow") without leaving the panel, or press
**Hide** and it stays gone for you, with Undo. This is the calm version of
the notice wall, and it is permanent policy: plugins cannot buy space in
Minn's interface.

**Updates**: the Updates tab shows everything pending, and **Update
everything** runs plugins, then themes, then WordPress core, telling you
exactly what will change and what is untouched before it starts. Minn
updates itself the same way: each release is fetched from the project's
GitHub releases and checked against a published checksum before it is
allowed to install.

**Cache and backups**: if a caching plugin or host cache is active, ⌘K
"Clear site cache" purges all of them at once. If a backup plugin is
installed, "Back up site now" is there too, and the System page reports
how fresh your last backup is.

## You are in control of the interface

Anything a plugin adds to Minn can be hidden, per user, without touching
the plugin itself:

- Right-click a plugin's sidebar entry and choose **Hide for you**.
- Right-click a group heading in the block library to hide that plugin's
  blocks or designs from your menus.
- Hide any notice from the Notices tab.

Everything hidden is listed under **Your profile**, where one click
restores it. Hiding is personal: your co-editors see their own layout.
Plugins also have hard budgets for how much space they may claim in the
sidebar, palette and menus, so the interface stays calm as you install
more of them.

## Managing the site

- **Extensions** — three tabs: Plugins, Themes, Licenses. Install by
  search, upload, or dropping a zip on the dialog. Toggle, update and
  delete with plain confirmations. The Licenses tab gathers your paid
  plugins' license keys in one place: see what is active, expired or
  missing, and activate or deactivate without hunting through each
  plugin's own settings screen.
- **Users** — create, edit, change roles in bulk, reset passwords, sign
  out sessions, and (with the User Switching plugin) switch into an
  account to see what they see.
- **Settings** — the settings people actually change: identity and logo,
  reading and discussion, permalinks, visibility (search engines,
  maintenance mode), site language, spam protection, custom CSS. The long
  tail of rarely-touched options deliberately stays in wp-admin, one
  click away. If you look for a setting and don't find it, that is the
  reason, not a bug.
- **System** — a health check (PHP, HTTPS, caching, backups, loopback),
  an activity view of what is installed, and debug tools when someone
  technical asks you to turn on logging.
- **Structure** — post types, taxonomies and terms: rename, merge and
  re-parent categories and tags safely.

## Your profile

Your account page covers your name and avatar, password and sessions,
interface language (each user can pick their own), appearance, everything
you have hidden, and **AI Access**: application passwords for connecting
an AI assistant or other tool to your site over the standard WordPress
API, created and revoked per tool.

## Keyboard shortcuts

| Keys | Does |
|---|---|
| **⌘K** | Command palette (with text selected in the editor: link) |
| **⌘S** | Save, keeping the current status |
| **⌘⏎** | Publish, Update or Schedule |
| **⌘/** | Block library |
| **⌘⇧F** | Find and replace in the post |
| **⌘⇧D** | Focus mode |
| **⌘⇧O** | Outline mode |
| **⌘.** | Show or hide the navigation |
| **← →** | Previous / next item in a media or entry dialog |
| **Esc** | Close menus and dialogs |

On Windows and Linux, use Ctrl wherever ⌘ appears.

## Safety, honestly stated

- **Is my content locked in?** No. Minn writes standard WordPress data
  and nothing else. Deactivating or deleting the plugin changes nothing
  about your content, users or settings.
- **How do updates arrive?** From the project's GitHub releases, through
  the normal WordPress updates screen. Since v0.21.0 every download is
  verified against a checksum published with the release before it
  installs; a tampered or broken download refuses to install.
- **Can a plugin misbehave inside Minn?** Not in the ways you are used
  to. Plugins describe their screens to Minn as plain data; their own
  code never draws inside the app, so a broken or pushy plugin cannot
  take the interface down with it or plaster it with banners.
- **Who can open `/minn-admin/`?** Only logged-in users your site already
  trusts to edit content, and each person sees only what their role
  allows. Everything is re-checked on the server on every action.
- **Multisite?** Not yet supported; Minn is built for single sites.
- **Something looks wrong?** Hard-refresh first (a cached stylesheet
  after an update is the usual cause), check the System page's health
  strip second, and report bugs at
  [github.com/austinginder/minn-admin/issues](https://github.com/austinginder/minn-admin/issues).
  wp-admin always remains as the fallback while anything is sorted out.
