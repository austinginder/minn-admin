# Core coverage audit — gaps vs classic wp-admin

Audited 2026-07-10 against v0.10.0; stale-checked 2026-07-12 during the
v0.13.0 cycle (WP 7.0.1); re-checked 2026-07-13 at v0.14.0 open; light
re-check 2026-07-15 at v0.16.0 open; re-checked 2026-07-23 at v0.21.0 open
(comment editing + commenter blocking, site logo and site language all
shipped in the v0.20.0 cycle; sections below updated); re-checked
2026-07-24 at v0.22.0 open (the read-only database viewer shipped as a
native developer surface, covered under Tools below; no new daily-work
gaps): every
ranked priority remains shipped, no new daily-work gaps in core areas.
Re-audited 2026-07-30 at v0.23.0 open with a theme-integration focus (see
the new section below): the remaining wp-admin territory is theme-shaped
(block-theme Site Editor artifacts, builder theme templates) plus four
smaller operational gaps (per-extension auto-update toggles, theme live
preview, bulk category/author edit, synced-pattern visibility). Dev tools Wave A is complete
(Diagnostics family); further inventory work is adapter depth or parked
native surfaces (`plugin-support.md`, `native-editors.md`). Minn's
positioning grades these: daily work belongs in Minn, the long tail stays
one click away in wp-admin. Each area below gets a status and a judgment on
whether the gap blocks daily work.

## Priority ranking (the gaps that matter)

1. **Term management** — ✅ shipped 2026-07-10: the **Terms** manager
   (Manage → Terms, `/minn-admin/terms`) covers every REST-enabled taxonomy
   with a switcher, an indented tree for hierarchical taxonomies, inline
   create/edit (name, slug, parent, description), delete with honest
   confirms, count links into the filtered content list, and **merge**
   (posts move to the surviving term through core's own reassignment
   machinery, then the source is deleted). Editors get it via
   `manage_categories`; per-taxonomy capabilities are enforced by core's
   REST routes.
2. **Media caption and description** — ✅ shipped (v0.11.0 cycle): the media
   detail modal now edits caption and description alongside title and alt,
   carrying the edit-context raw value (fetched lazily so the list stays
   light) and saving through wp/v2/media.
3. **Media bulk select/delete** — ✅ shipped (v0.11.0 cycle): grid tiles and
   list rows carry a checkbox (shift-range, select-all) with a delete bar
   mirroring the content-list pattern; force=true, per item.
4. **Comment bulk moderation** — ✅ shipped (v0.11.0 cycle): comment rows get
   a checkbox + Select-page, and a bar whose verbs are the current tab's own
   actions (Approve/Spam/Trash on Pending, Restore/Delete on Trash, and so
   on), applied per item.
5. **Bulk user role change** — ✅ shipped (v0.11.0 cycle): the users table
   gains a checkbox column (gated on edit-users) and a bar to change every
   selected user's role at once; the current user is skipped (self-lockout
   guard).
6. **Per-post format picker** — ✅ shipped (v0.11.0 cycle): the editor sidebar
   has a Format select, gated on the active theme declaring post-format
   support (matching wp-admin), saving through wp/v2's native `format`
   field. Boot payload carries the supported formats.

## Area-by-area status

### Customizer and theme options — partial, mostly by design
Covered: site identity lives in Settings → General (title, tagline, site icon
with full upload flow, site address, admin email); homepage settings in
Settings → Reading (latest posts vs static page, with page pickers); and as of
the v0.11.0 cycle, **Custom CSS** (`wp_custom_css_post`, the Customizer's
"Additional CSS") edits in Settings → Design through
`minn-admin/v1/custom-css` (edit_css cap, per-theme stylesheet, structural
validation mirroring the Customizer's refusal). Custom logo and site
language shipped in the v0.20.0 cycle (Settings → Site: the logo gated on
theme custom-logo support via `minn-admin/v1/site-logo`, the language over
`minn-admin/v1/site/language`, downloading packs on save). Missing: other
theme mods, FSE global styles. Judgment: identity + homepage + Custom CSS +
logo + language is the daily slice and it's covered; the Customizer proper
and global styles are correctly long-tail.

### Appearance — covered where it counts
Menus (with drag reorder) and classic widgets are fully built; themes
install/activate/update/delete under Extensions. Template/FSE editing,
background and header images: out of scope by design.

### Theme integration — the audited frontier (2026-07-30)
On a CLASSIC theme, the daily slice is covered: Menus, Widgets, logo, icon,
Custom CSS, homepage. The gaps cluster on block themes and theme builders,
verified empirically at v0.23.0:

1. **Block themes lose ground with no replacement (L, needs design).**
   Minn correctly hides Menus and Widgets when `wp_is_block_theme()`
   (`B.site.blockTheme`), but nothing steps in: no navigation editing
   (`wp_navigation` posts have REST parity with what the Menus manager
   already does for classic menus), no template/template-part LISTING with
   Site Editor deep links, no global-styles summary. A block-theme site
   demotes Minn from "the admin" to "the content admin". Scoped build,
   ranked inside this item: navigation first (real daily work, existing
   Menus UX transfers), then a read-only Design card (active theme, its
   templates/parts as "Edit in Site Editor ↗" rows, current palette). The
   Site Editor canvas itself is a permanent link-out (same reasoning as
   form builders).
2. **Builder theme templates are invisible (M).** `elementor_library` IS
   REST-exposed but not `viewable`, so the content switcher (rightly)
   skips it — meaning a site whose header/footer live in Elementor Theme
   Builder has no Minn surface listing those templates. Same story for
   Bricks/Divi template areas. Fits the existing page-builders adapter
   thesis: list, badge the type (header/footer/popup/archive), show
   display conditions read-only, deep-link into the builder canvas.
3. **Per-extension auto-update toggles (S).** wp-admin's plugins/themes
   screens toggle `auto_update_plugins` / `auto_update_themes`; Minn has
   no trace of them (verified by grep). Extensions cards should show and
   flip these — pure option writes, high maintenance-audience fit.
4. **Theme live preview (S).** Inactive theme cards offer Activate only;
   wp-admin offers a Customizer live preview first
   (`customize.php?theme=`). One link on classic-theme cards.
5. **Bulk edit beyond status (M).** The content bulk bar does status /
   trash / restore / delete but not wp-admin bulk-edit's "add these
   categories / set author across selected posts".
6. **Synced patterns (S-M).** `wp_block` is REST-exposed, not viewable, so
   reusable/synced patterns have no Minn surface (create/rename/delete/
   edit). Their content is plain block markup — Minn's editor could open
   them natively; the slash menu could offer them for insertion.
7. **Classic Customizer theme options — stays a link-out.** Arbitrary
   theme mods (colors, header/background images, per-theme sections) are
   a per-theme treadmill with no schema; the Customizer deep link is the
   honest answer, same verdict as before.

### Taxonomies — covered
The Terms manager shipped 2026-07-10 (see priority #1). The only server
addition was `minn-admin/v1/terms/merge`; everything else rides core REST.
As of the v0.11.0 cycle, Terms is folded into the **Structure** page (Post
Types / Taxonomies / Terms tabs) rather than a standalone nav item, to keep
the MANAGE group short. The tabs gate individually: Post Types and
Taxonomies need `manage_options`, Terms needs only `manage_categories`, so
an editor's Structure item shows just the Terms tab (labeled "Terms" for
them), while an admin sees all three. The `/minn-admin/terms` route and the
⌘K "Manage categories & tags" command still work, landing on the Terms tab.

### Tools — System page strong, one-shot tools absent
The System page covers diagnostics well (health checks, DB tables, autoload
weight, cron health, debug toggles + log viewer, integrations registry,
extensions manifest, copy-as-markdown report). Loopback and REST self-check
health rows shipped 2026-07-10 (core's own Site Health tests, cached 15
minutes), and a Tools card deep-links the one-shot jobs (Site Health,
export/import, GDPR export/erase): episodic surgery stays in wp-admin, one
click away, by design. As of the v0.22.0 cycle the Database card is also a
doorway: its largest-table rows and a "browse all" link open the read-only
database viewer (`/minn-admin/database`, also in ⌘K; deliberately not a nav
item). Writes and a SQL console stay permanent non-goals there
(`native-editors.md`).

### Settings — daily options covered, two screens thin
Writable today: General (title, tagline, icon, URL, admin email, timezone,
date/time format, week start, default role, membership, maintenance, default
admin), Writing (default category/format, smilies), Reading (front page,
posts per page, search visibility), Discussion (default comment/ping status,
moderation, registration required, avatars on/off), Permalinks (structure +
bases), Spam (provider cards + disallowed keys), Connectors (WP 7.0's
connector registry: provider keys with core-side masking and validation,
key-source honesty for wp-config/env keys, companion-plugin install in
place), plus site logo and site language on the Site tab since the v0.20.0
cycle. Missing: the entire Media
settings screen (thumbnail sizes, month/year folders),
`posts_per_rss` / feed excerpt, and most of the Discussion matrix (threading
depth, per-page, previously-approved shortcut, close-after-days, notification
emails, avatar rating/default). Judgment: what's missing is set-once config;
add Discussion depth only if comment-heavy sites ask.

### Users — at parity or better
List, search, roles, add/edit, delete with content reassignment, password
reset, send email, session kill, bulk role change (shipped v0.11.0),
application passwords ("AI Access" with generated agent guide). The
long-tail profile fields all shipped v0.17.0 on the /minn-admin/profile
page (first/last name, bio, website, per-user language with automatic
pack installs, the front-end toolbar preference). Application passwords
and reassign-on-delete are better surfaced than classic.

### Media — grid solid, editing caught up
Grid with type filter/search/pagination, multi-upload, drag-drop, image
editor (rotate/crop to a new copy), featured-image flows, delete, copy URL,
caption/description editing and bulk delete (both shipped v0.11.0).
Unattached filter (core parent=0), month filter (minn-admin/v1/media/months
combobox → after/before windows) and the detail modal's "Attached to" row
with an editor jump all shipped 2026-07-17 (v0.18.0 cycle, suite
media-polish). Folders: not a
core feature (a Minn-owned tree would be a fifth folder standard invisible
to wp-admin and builder pickers), but the earlier "long-tail, skip" verdict
was revised 2026-07-16: FileBird + Real Media Library + Folders total 400k+
installs, so a browse-first provider contract is Wave D in
`docs/plugin-support.md`.

### Comments — complete, single-row and bulk
Tabs for pending/approved/spam/trash, full per-row moderation, bulk
moderation with per-tab verbs (shipped v0.11.0), inline reply
(auto-approves like core), context menu. The last two gaps closed in the
v0.20.0 cycle: inline comment editing (text always; author name/email for
guest comments) and Block commenter (adds the author's email or IP to
core's disallowed list, visible under Settings → Comments, with Undo).

### Multisite — non-goal, degrades sanely
Defensive gating exists (`manage_network_users`, super-admin file-mod
checks, System report row). No network surfaces, no visible hard breakage on
a subsite.

### Structural observation — REST-only is a hard boundary
Anything not exposed to REST is invisible to Minn by construction: CPTs and
taxonomies without `show_in_rest` (the UI flags them), meta not registered,
custom statuses. This is deliberate and worth keeping; it's the line that
keeps list views fast and safe.
