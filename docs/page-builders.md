# Page builders — coexistence, not competition

**Question:** what happens when a site manages pages with Divi, Elementor, Brizy, Etch or
Beaver Builder? Can those users keep their builder while never bouncing to a wp-admin
screen — and can Minn stay safe around builder-owned content?

**Answer (verified in a live lab, 2026-07-05):** yes to both. Every major builder has an
editing surface with zero wp-admin chrome — most are literal front-end URLs. Minn's job
is to detect builder-owned posts, put the right door on them, and refuse to let its own
editor corrupt what the builder owns. Shipped as `includes/adapters/page-builders.php`
(the `minn_builder` REST field) plus the list chip and editor treatment in app.js.

## Lab method

Fresh Cove site (`builders.localhost`) with minn-admin symlinked; installed Divi 5
(theme), Etch 1.6.1, Beaver Builder agency + theme, Brizy 2.8.17 + Pro, Elementor free
(wp.org — no Elementor Pro zip was on hand, but Pro rides free's storage/URLs). For each
builder: opened its editor surface in a real Chrome, saved where scriptable, then
inspected `post_content`, postmeta, and how Minn's editor presented the same post.

## What each builder actually does

| Builder | Canonical content lives | post_content holds | Edit surface (no wp-admin chrome) | Detection |
|---|---|---|---|---|
| **Elementor** | `_elementor_data` postmeta (JSON) | stale/decorative copy | `post.php?post=N&action=elementor` — wp-admin URL, renders a full-screen app | `_elementor_edit_mode = builder` |
| **Beaver Builder** | `_fl_builder_data` / `_fl_builder_draft` postmeta (serialized PHP) | flattened render (on publish) | `permalink?fl_builder` — pure front end | `_fl_builder_enabled = 1` |
| **Brizy** | `brizy` postmeta storage (+ compiled HTML) | compiled copy | `post.php?action=in-front-editor&post=N` — bounces to the front-end editor | `Brizy_Editor_Entity::isBrizyEnabled()` (`brizy_post_uid` present) |
| **Divi 4 (legacy)** | `post_content` | `[et_pb_*]` shortcode soup | `permalink?et_fb=1` — pure front end | `_et_pb_use_builder = on`, no `wp:divi/` |
| **Divi 5** | `post_content` | `<!-- wp:divi/* -->` block markup | `permalink?et_fb=1` — pure front end | `_et_pb_use_builder = on` + `wp:divi/` |
| **Etch** | `post_content` | `<!-- wp:etch/* -->` block markup | `permalink?etch=magic` — pure front end, admin bar stripped by Etch itself | `wp:etch/` in content |

Two classes fall out:

### Block-native builders (Etch, Divi 5) — Minn already interops

Their canonical content is native Gutenberg block markup in `post_content`. Minn's
island machinery treats `wp:etch/*` / `wp:divi/*` as atomic islands: preserved exactly,
text between blocks editable, inspector available. Verified: a Minn save round-trips the
builder markup byte-identically — after WordPress **core's** own one-time REST-save
normalization (the block-hooks machinery parse/re-serializes all block markup on every
REST insert: `{}` attrs become `[]`, HTML in attrs gets `<`-escaped). Gutenberg
saves apply the identical normalization; content already in canonical form is a fixed
point (proved: second save byte-identical). This is the direction the industry is
moving — Divi 5's whole `builder-5/` server module is block-parser based.

### Meta-storage builders (Elementor, Beaver Builder, Brizy) + shortcode Divi 4 — must be fenced

Canonical content lives *outside* `post_content` (or, for Divi 4, inside it as shortcode
soup the Visual Builder owns). What `post_content` holds is a stale or compiled copy.
The trap, verified live: an Elementor page opened in Minn presented as a perfectly
editable classic-mode document — but edits to it would **silently never render**
(Elementor renders `_elementor_data`, not `post_content`). That's the worst failure
class Minn has: not a crash, a silent lie.

## The integration (shipped)

`includes/adapters/page-builders.php` registers a **`minn_builder` REST field** on all
REST-visible post types, only when a builder is active. Per post it answers:
`{ id, name, edit_url, owns_content }`, with third-party registration via the
`minn_admin_page_builders` filter (same philosophy as surfaces/panels: bundled adapters
for the big names, a filter for everyone else).

Client behavior:

- **Content list**: a quiet chip next to the status pill ("Elementor", "Divi", …) so
  builder pages are recognizable before opening them.
- **Editor, `owns_content: true`**: the post is forced into **locked mode** — which
  makes everything correct fall out of existing machinery: the body is a read-only
  preview (locked mode upgrades to `content.rendered`, which runs `the_content`, which
  means the preview shows the *builder's real output*), no write path ever sends
  `content`, autosave-to-revision is skipped. A note explains who owns the canvas with
  an **Edit in ⟨Builder⟩** button to the chrome-free surface. Title, status, slug,
  scheduling, featured image, tags and the SEO panel all still save from Minn — that's
  the actual product: Minn stays the calm admin *around* the builder.
- **Editor, `owns_content: false`** (Etch, Divi 5): fully editable as today (islands do
  the protecting), plus the same note in its lighter variant with the Edit-in-builder
  button.

## How "never bounce to /wp-admin/" holds up

- Beaver Builder, Divi, Etch: the editing surface is a **front-end URL** — wp-admin is
  never even in the address bar.
- Elementor, Brizy: the URL is technically `post.php?action=…`, but it renders the
  builder's own full-screen app with zero wp-admin chrome (verified by screenshot). The
  user experience has no wp-admin in it; only the URL bar knows.
- Everything around editing — lists, statuses, search, comments, media, users, SEO
  fields — is Minn.

## Not built (deliberate, candidates for a later round)

- **"New page in ⟨builder⟩"** from the + New menu / ⌘K: create a draft over REST, then
  redirect to the builder's edit URL (every builder boots fine on an empty post — this
  is exactly what their own admin buttons do). Cheap once wanted.
- **Builder templates/libraries** (Elementor library, Divi library, BB saved rows):
  their CPTs stay hidden from Content (`elementor_library` already is); managing those
  belongs to the builders.
- **Theme-builder surfaces** (Divi Theme Builder, Elementor Pro site parts): out of
  scope — that's site *building*, Minn is site *running*.
- Elementor Pro was not tested directly (no zip on hand); it shares free's storage and
  editor URL, so detection and routing hold. Worth a spot-check when a license is around.
- Etch's builder booted to a blank app in the lab (SureCart license gate suspected);
  its storage format and edit URL are confirmed from source and its own test fixtures.

## Lab artifacts

`builders.localhost` Cove site (kept for follow-ups; delete with
`cove delete builders`). Demo pages: Elementor (7), Beaver (11), Brizy (16), Etch (17,
21, 24), Divi (18), Divi-5-style blocks (20).
