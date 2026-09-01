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
native surfaces (`plugin-support.md`, `native-editors.md`). Re-checked
2026-08-06 at v0.24.0 open (unreleased): three of those four smaller gaps
shipped in the v0.23.0 cycle (auto-update toggles, theme live preview,
synced patterns; marked below), and the Media section's folders verdict
was stale: the browse-first provider contract (Wave D) had already
shipped in the v0.18.0 cycle, corrected below. The v0.24.0 cycle so far
is adapter depth (WPForms entries, forms status cards, FluentSMTP) and
changes no core-gap status. Re-checked 2026-08-11 at v0.28.0 open:
multisite moved from defensive degradation to daily network operations;
the section below now records the shipped surface and its deliberate
boundaries. Still open: block-theme surfaces, builder
theme templates, bulk category/author edit. Minn's
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
   **Navigation LISTING SHIPPED** (Phase 1): a `navigation` route takes the
   sidebar slot Menus vacates on a block theme, listing `wp_navigation`
   posts with create / rename / delete, item counts, and the answer the
   Site Editor does not give — WHERE each menu renders, from
   `minn-admin/v1/navigation/usage`, which scans the active theme's
   templates and parts (`get_block_templates()` merges theme files with DB
   overrides; `resolve_pattern_blocks()` first, or a nav inside a theme
   pattern is invisible and a stock theme reports everything unused).
   Menus from a previous theme correctly read as unused, and ref-less
   inline navs are called out since no listed menu represents them.
   Deleting names the part that loses its links. Suite
   `tests/navigation.test.js` (activates twentytwentyfive, restores the
   previous theme in finally).
   **TREE EDITOR SHIPPED** (Phase 2): `/navigation/{id}` (route `navedit`)
   arranges a menu's items. `wp_navigation` content is ONE serialized block
   document, not menu-item rows, so this is the island discipline applied
   to a tree: `navParse` builds nodes that each keep their VERBATIM markup
   (reusing `tokenizeBlocks` per level, byte-identity gate included),
   reorder/indent/outdent/drag permute those strings, and only an edited
   node's attrs are rewritten via `navSetAttrs`. Verified empirically:
   `wp_navigation` content round-trips BYTE-IDENTICAL through REST and is a
   fixed point (unlike post content, which core re-serializes), so
   `metadata.ignoredHookedBlocks`, classNames and unregistered blocks all
   survive. Non-link blocks are opaque (reorder/remove yes, edit no).
   Indent converts a link to a submenu and emptying one converts it back;
   both conversions MUTATE the node because undo and drag hold references
   across them. Drag resolves its target by IDENTITY, not token, since
   removing the dragged node shifts every later index. Unparseable markup
   falls to read-only + Site Editor. Suite
   `tests/navigation-tree.test.js` (25); real-data parse walk on the
   mmonroe lab covers nested `social-links` and a third-party block.
   **TEMPLATES SURFACE SHIPPED**: `/templates` (route `templates`) lists
   `wp/v2/templates` + `/template-parts` (pure core REST, no Minn endpoint)
   with the status the Site Editor's flat list cannot give — from theme /
   customized / added here / from a plugin — changed rows sorted first,
   author + modified named, and Reset to theme (a DELETE that hands the
   theme file back; verified `source` returns to `theme`). HARD-WON: a
   plugin's registered templates (The Events Calendar) are INDISTINGUISHABLE
   from hand-made ones by `is_custom` / `origin` / `author` — only the
   `<owner>//<slug>` id says so, compared against boot `site.stylesheet` /
   `site.template` (both added for this). They get no destructive action
   because DELETE answers 200 and the plugin re-registers next request.
   Suite `tests/templates.test.js` (18).
   IA: Templates and Navigation are TABS under one **Design** nav item
   (id `templates`), the Structure/Terms precedent; each tab keeps its own
   route and Design stays highlighted across `templates`, `navigation`
   and `navedit`.
   **TEMPLATE USAGE SHIPPED**: `minn-admin/v1/templates/usage` answers what
   reaches each one — TEMPLATES by counting `_wp_page_template` assignments
   (one grouped query; `default` excluded, revisions/auto-drafts excluded),
   PARTS by counting `wp:template-part {"slug"}` references across
   templates and parts (patterns resolved first, same as the nav scan;
   cross-checked against a raw string scan on mmonroe: 9/9/0/0). Feeds the
   row meta and the delete confirm ("N pages will fall back…").
   HARD-WON: "unused" is only sayable for templates reached by CHOICE.
   Core's `is_custom` is exactly that signal (the slug is not one of
   WP's default template types). The theme's own theme.json
   `customTemplates` is NOT a substitute — mmonroe declares `page` and
   `404` there, and an early build wrongly told the user the template
   behind every page was unused. Note PHP coerces a numeric key like
   "404" to int if that list is ever read again.
   **TEMPLATE EDITING IN MINN SHIPPED** (the patterns precedent, measured
   first): all 15 real mmonroe templates open in blocks mode — groups are
   slot containers with editable interiors, structural blocks stay atomic
   islands, so the daily edits (footer copyright, 404 copy) are direct.
   Route `/editor/templates/<theme>/<slug>` (+ `template-parts`): the id
   "theme//slug" collapses in the decoded path into exactly two slash-free
   parts, so the STRING id rides losslessly; `loadTemplateEditor()` in
   app.js; every request path `encodeURIComponent`s the id. Deliberate
   differences from posts, each verified: NO autosave (a timer must never
   quietly create a site copy of a theme template; the autosaves route
   refuses theme-file templates with 400 anyway), NO post lock (theme-file
   templates have no post row; Site Editor takes none either — last write
   wins), payload degrades to {title, content} because buildSavePayload
   only adds dirty-flagged fields. First save of a theme template flips
   source theme→custom (sidebar From theme→Customized, Reset to theme
   appears; `'source' in p` adoption in doSaveEditor). Whole-document
   byte identity holds EXCEPT the theme file's trailing newline, which no
   block serializer re-emits (verified: 1 byte in 2,910, position 2909) —
   the guarantee is per-block. Plugin-registered templates still open in
   the Site Editor (their override save path is unproven). Suite
   `tests/template-editor.test.js` (20).
   **STYLE-VARIATIONS PICKER SHIPPED** (Design → Styles, route `styles`):
   `minn-admin/v1/styles/variations` reads ONLY top-level `styles/*.json`
   (parent + child dirs, child wins by basename) — core's own variations
   route recursively sweeps the color/ and typography/ PARTIAL subdirs,
   which duplicate top-level titles (TT5 ships "Evening" twice) and are
   meant for mixing; Gutenberg dedupes client-side, Minn server-side.
   Apply = POST `wp/v2/global-styles/{userStylesId}` with the variation's
   settings/styles (proven to change the visitor-facing front end);
   Default = write empty objects; Undo = restore the pre-apply snapshot
   the endpoint carries (`current`). ACTIVE DETECTION TRAP: the write
   passes through WP_Theme_JSON's user-origin sanitization (drops
   styles.variations, among others), so the RAW file never equals the
   stored config — compare via
   `( new WP_Theme_JSON( $decoded, 'custom' ) )->get_raw_data()` (both
   public), the same transform the write applies. Suite
   `tests/styles.test.js` (14; verifies the FRONT END changes and that
   Undo restores the stored config exactly).
   STILL OPEN, ranked: template REVISIONS/history in the editor (route
   exists; loadEditorRevisions guarded off for templates); global-styles
   history (core keeps revisions of the user global-styles post — would
   extend the Styles tab).
   **SITE LOGO ON BLOCK THEMES FIXED**: the Settings field existed but
   gated on `current_theme_supports('custom-logo')`, which stock block
   themes never declare (TT5 included — its style.css "custom-logo" is a
   directory TAG, not support), so the field hid on exactly the sites
   moving to block themes; the dev fixture's unconditional
   add_theme_support masked it (now classic-only). Gate is
   `supports || wp_is_block_theme()`; the theme-mod write is safe there
   because core registers the custom_logo-mod ↔ site_logo-option sync
   filters UNCONDITIONALLY (blocks/site-logo.php), proven both directions.
   Suite `tests/site-logo.test.js` (10) includes the option-sync proof.
   (`wp/v2/global-styles/themes/{stylesheet}/variations` lists them and
   applying one is a REST write, so it needs no canvas); then a read-only
   Design card (active theme, palette, site logo). The Site Editor canvas
   itself is a permanent link-out (same reasoning as form builders).
2. ~~**Builder theme templates are invisible (M).**~~ **SHIPPED for
   Elementor and Bricks.** `elementor_library` stays hidden from Content
   (plumbing, not a writing surface) and is listed on a Templates
   surface instead: type tabs, Pro's Instances column as the conditions
   summary, create through Elementor's own `documents->create`,
   duplicate with conditions stripped, export of their interchange JSON,
   Edit in Elementor. Bricks has the matching surface over
   `bricks_template`. Remaining: Divi / Beaver Builder template
   libraries, which still have no listing.
3. ~~**Per-extension auto-update toggles (S).**~~ **SHIPPED 2026-08-04**
   (v0.23.0 cycle): Auto pills on plugin AND theme cards through
   `minn-admin/v1/auto-updates` (the same option writes as
   `wp_ajax_toggle_auto_updates`, stale entries pruned; gated on
   `wp_is_auto_update_enabled_for_type` server- and client-side). Suite
   `tests/auto-updates.test.js`.
4. ~~**Theme live preview (S).**~~ **SHIPPED 2026-08-04** (v0.23.0
   cycle): Live preview link on inactive theme cards: Customizer for
   classic themes, `site-editor.php?wp_theme_preview=` for block themes
   (the `block` flag now rides `minn-admin/v1/themes`).
5. **Bulk edit beyond status (M).** The content bulk bar does status /
   trash / restore / delete but not wp-admin bulk-edit's "add these
   categories / set author across selected posts".
6. ~~**Synced patterns (S-M).**~~ **SHIPPED 2026-08-04** (v0.23.0 cycle):
   wp_block allowlisted past the viewable gate (`slimContentTypes`) → a
   Patterns entry in the Content switcher with the standard row machinery
   (front-end view/preview suppressed, not publicly queryable); slash
   menu + ⌘/ picker "Your patterns" (synced → `wp:block {"ref":N}`
   reference island, quiet insert; unsynced → detached copy via
   `insertPatternIslands`); editor opens `/editor/blocks/{id}` natively
   with a synced-edit note and self-slimmed sidebar; + New → Pattern.
   GOTCHA: `wp_pattern_sync_status` is top-level READ-only in REST;
   writes ride `meta.wp_pattern_sync_status`. Suite
   `tests/user-patterns.test.js`.
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
installs, so a browse-first provider contract became Wave D in
`docs/plugin-support.md`. Wave D shipped 2026-07-17 (v0.18.0 cycle): the
`minn_admin_media_folders` provider filter
(`includes/adapters/media-folders.php`) with bundled FileBird, Real Media
Library and Folders by Premio providers; a folder picker on the Media view
filters the normal wp/v2/media query (search, type tabs, Unattached and
pagination keep working, newest 500 per folder), and a Move-to-folder bulk
action rides each provider's optional `move` callable. Suite
`tests/media-folders.test.js`. Moving stays browse-first: Minn never owns
a folder tree of its own.

### Comments — complete, single-row and bulk
Tabs for pending/approved/spam/trash, full per-row moderation, bulk
moderation with per-tab verbs (shipped v0.11.0), inline reply
(auto-approves like core), context menu. The last two gaps closed in the
v0.20.0 cycle: inline comment editing (text always; author name/email for
guest comments) and Block commenter (adds the author's email or IP to
core's disallowed list, visible under Settings → Comments, with Undo).

### Multisite — daily network operations covered
Minn boots on main sites and subsites, heals routes after network activation,
and offers a capped, searchable site switcher in the sidebar and command
palette. A subsite administrator gets the site's full Users list with role
changes, add-existing-account and remove-membership actions; network
administrators are protected from those per-site controls.

Network administrators get first-class Sites, Network users and Network
settings surfaces. The daily slice covers site creation and lifecycle,
network-administrator promotion and revocation, registration, uploads, site
administrator permissions and network mail. Extensions adds network-wide
plugin activation and theme availability, and a core update runs the required
database migration across each subsite.

The boundary is deliberate: network account creation/deletion, welcome
emails, first-post text, reserved names, language defaults, restores, exports
and network setup stay in WordPress Network Admin. Very large networks use
WordPress's Upgrade Network screen rather than a request-bound site walk.
Network-shared logs, database tables and plugin data are either scoped by site
or restricted to super administrators; a per-site `manage_options` check is
never treated as permission to read the whole network.

### Structural observation — REST-only is a hard boundary
Anything not exposed to REST is invisible to Minn by construction: CPTs and
taxonomies without `show_in_rest` (the UI flags them), meta not registered,
custom statuses. This is deliberate and worth keeping; it's the line that
keeps list views fast and safe.
