# Core coverage audit — gaps vs classic wp-admin

Audited 2026-07-10 against v0.10.0. Minn's positioning grades these: daily work
belongs in Minn, the long tail stays one click away in wp-admin. Each area below
gets a status and a judgment on whether the gap blocks daily work.

## Priority ranking (the gaps that matter)

1. **Term management** — no way to rename, delete, merge or re-parent
   categories and tags. Assignment in the editor is rich (checkboxes, tag
   combobox with create-on-the-fly) and taxonomy *definitions* have a manager
   (Post Types → Taxonomies), but reorganizing the terms themselves forces a
   wp-admin trip. This is routine editorial work: the most impactful daily gap.
2. **Media caption and description** — the media detail modal edits title and
   alt text only. Captions are daily work for content teams.
3. **Media bulk select/delete** — content lists have bulk operations; the
   media library has none.
4. **Comment bulk moderation** — every comment action is one row at a time.
   Bites any site with real comment volume. (The Settings → Spam blocklist
   softens the "ban this pattern" case.)
5. **Bulk user role change** — users are row-at-a-time; matters at scale.
6. **Per-post format picker** — `post_format` sits in `TAX_SKIP`, so
   format-driven themes can't be worked in Minn. `default_post_format` is
   settable, the per-post choice is not. Small, but it's a one-field gap.

## Area-by-area status

### Customizer and theme options — partial, mostly by design
Covered: site identity lives in Settings → General (title, tagline, site icon
with full upload flow, site address, admin email); homepage settings in
Settings → Reading (latest posts vs static page, with page pickers). Missing:
custom logo, Custom CSS (`wp_custom_css_post`), site language, theme mods,
FSE global styles. Judgment: identity + homepage is the daily slice and it's
covered. Custom CSS is the one arguably-daily omission; the Customizer proper
and global styles are correctly long-tail.

### Appearance — covered where it counts
Menus (with drag reorder) and classic widgets are fully built; themes
install/activate/update/delete under Extensions. Template/FSE editing,
background and header images: out of scope by design.

### Taxonomies — definitions yes, terms no
See priority #1. A Terms manager (list, rename, edit slug/description/parent,
delete with reassignment, merge) is the missing piece; core REST
(`wp/v2/categories`, `wp/v2/tags`, custom taxonomy routes) covers all of it,
so this is a pure client build with no new server machinery.

### Tools — System page strong, one-shot tools absent
The System page covers diagnostics well (health checks, DB tables, autoload
weight, cron health, debug toggles + log viewer, integrations registry,
extensions manifest, copy-as-markdown report). No WXR export/import, no
privacy personal-data export/erase requests, no full Site Health test-suite
parity. Judgment: export/import and privacy requests are episodic surgery,
acceptable long-tail. Candidate System additions: loopback/REST self-check
rows (parked from the v0.9 bounce audit).

### Settings — daily options covered, two screens thin
Writable today: General (title, tagline, icon, URL, admin email, timezone,
date/time format, week start, default role, membership, maintenance, default
admin), Writing (default category/format, smilies), Reading (front page,
posts per page, search visibility), Discussion (default comment/ping status,
moderation, registration required, avatars on/off), Permalinks (structure +
bases), Spam (provider cards + disallowed keys). Missing: the entire Media
settings screen (thumbnail sizes, month/year folders), site language,
`posts_per_rss` / feed excerpt, and most of the Discussion matrix (threading
depth, per-page, previously-approved shortcut, close-after-days, notification
emails, avatar rating/default). Judgment: what's missing is set-once config;
add Discussion depth only if comment-heavy sites ask.

### Users — at parity or better
List, search, roles, add/edit, delete with content reassignment, password
reset, send email, session kill, application passwords ("AI Access" with
generated agent guide). Missing: bulk role change (priority #5) and the
long-tail profile fields (bio, website, per-user locale). Application
passwords and reassign-on-delete are better surfaced than classic.

### Media — grid solid, editing thin
Grid with type filter/search/pagination, multi-upload, drag-drop, image
editor (rotate/crop to a new copy), featured-image flows, delete, copy URL.
Missing: caption/description (priority #2), bulk delete (#3), unattached and
date filters, "attached to" info. Folders: long-tail, skip.

### Comments — single-row complete, bulk absent
Tabs for pending/approved/spam/trash, full per-row moderation, inline reply
(auto-approves like core), context menu. Missing: bulk moderation (priority
#4), editing a comment's text/author, per-commenter block.

### Multisite — non-goal, degrades sanely
Defensive gating exists (`manage_network_users`, super-admin file-mod
checks, System report row). No network surfaces, no visible hard breakage on
a subsite.

### Structural observation — REST-only is a hard boundary
Anything not exposed to REST is invisible to Minn by construction: CPTs and
taxonomies without `show_in_rest` (the UI flags them), meta not registered,
custom statuses. This is deliberate and worth keeping; it's the line that
keeps list views fast and safe.
