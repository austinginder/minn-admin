# Full-UI plugin adapters — research and roadmap

**Thesis: full UI support for a complex plugin is a ladder, not a cliff.** The fear
behind "full plugin editing is a massive undertaking" assumes the unit of work is the
screen: rebuild every admin page of every plugin by hand, forever behind. The research
says the unit of work is the *schema system*. Serious plugins stopped hand-writing
their own settings pages years ago; they declare fields as data and render them
generically. If Minn can import those declarations, one adapter mapper covers an entire
plugin family, and the screens fall out.

Research basis (2026-07-06): source-level audits of Gravity Forms 2.10.5 and Gravity
SMTP 2.2.0 (two of the most complex plugins in the ecosystem, deliberately chosen as
the hard cases) against an inventory of Minn's current extension machinery. Wiring
their existing admin UIs into Minn was ruled out from the start; the question was
whether the adapter approach scales to full coverage.

## The headline findings

1. **Both plugins are schema-driven where it counts.** Gravity Forms runs one shared
   Settings engine (`includes/settings/class-settings.php`, 23 field types) for its
   plugin settings, form settings, notifications, confirmations, and every add-on's
   settings and feeds. Gravity SMTP declares all 21 connectors' config fields as data
   (`settings_fields()` descriptor arrays, ~8 component types). Neither hand-writes
   settings HTML. A foreign UI that can render their schemas renders nearly all of
   their admin, including screens in add-ons that don't exist yet.

2. **The write paths are clean.** GF's entire form definition (fields, settings,
   notifications, confirmations) round-trips as one JSON document through
   `PUT /gf/v2/forms/{id}`; entries, notes and feeds have full REST CRUD. Gravity SMTP
   stores everything in two JSON option shapes plus four custom tables, with a nonce'd
   ajax endpoint for every mutation. No screen-scraping, no POST-to-admin-page hacks.

3. **Minn already owns most of the client machinery.** Three schema-driven form
   systems exist today (editor panels, surface create/edit, block-inspector forms)
   with three inconsistent field vocabularies, and the `sectionsRoute` display-model
   pattern already moves detail rendering server-side. The single highest-leverage
   move is consolidation, not invention.

4. **The genuinely bespoke surfaces are few and skippable.** In GF it is the drag-drop
   form editor (and the small conditional-logic rule builder). In Gravity SMTP it is
   only the OAuth handshakes for Google/Microsoft/Zoho. Everything else in both
   plugins is lists, detail views, and schema-declared forms. The bespoke rung already
   has a proven answer: delegate, exactly like page builders ("Edit in Gravity Forms"
   is one click, with no wp-admin chrome needed for the builder-style screens).

## Where the adapter system stands today (re-verified at v0.10.0, 2026-07-10)

The current vocabulary (docs/for-plugin-authors.md; ground truth is the validator
constants in `class-minn-admin-surfaces.php`) expresses: paginated list tables with
tabs and search, a second `manage` collection with a `viewLabel` switcher, sidebar
placement via `group`, a detail modal (raw dump, server-shaped `sectionsRoute`
sections, or a sandboxed `messageKey` body), inline `detail.edit` and `create` forms
(text, number, textarea, select, tags), declarative `actions` (route + method +
static body, `when`-gated, `confirm`, `href`), column refinements (`altKey`,
`width`, `utc`, formats incl. `entry-summary`), and the `status` card: a
server-built display model above the list with stat rows, a click-to-copy command
box and action buttons (shipped 2026-07-10, Disembark is the reference). Provider
families beyond surfaces also grew their own filters: traffic, cache purgers, spam
provider cards, design sources, page builders, insert blocks.

What no descriptor can express today, re-confirmed against app.js at v0.10.0:

- **Settings pages.** No surface shape maps to "a form that reads and writes
  options." The Spam settings page (2026-07-10) is the closest thing shipped, and
  it is deliberately bespoke: a fixed Settings tab rendering provider cards with a
  narrow `{configured, note, blocked, toggles}` vocabulary, not a schema-driven
  form. It proves the appetite; it is not the Rung-2 engine.
- **Real form fields with semantics.** Surface `create`/`detail.edit` grew
  textarea/select/tags, but still no `required`/`default`/`help`/`placeholder`/
  `dependency`. Editor panels know select, radio, toggle, textarea, min/max, but
  stay trapped in the editor sidebar and hard-wired to the `wp/v2` post save. The
  block inspector remains a third vocabulary. Rung 1 is still the keystone.
- **Parameterized actions.** An action body is static JSON; it cannot carry user
  input ("resend to ⟨address⟩") or item values beyond `{id}` in `href`.
- **Bulk operations, row-level actions in surface lists, charts, wizards, nested
  navigation.** The `status` card covers part of the old `stats` sketch (rows +
  actions), but there is no chart row type and no generic stat-tile grid.

Rung status at v0.10.0: **Rung 1 not started** (three field vocabularies, one
grew), **Rung 2 not started** (spam page is bespoke), **Rung 3 partial** (the
`status` card only), **Rung 4 policy holding** (deep links everywhere bespoke).

Rung status at v0.12.0 (updated 2026-07-11): **Rung 1 shipped** — the form
engine section in app.js renders surface create/edit forms, editor panels and
the inspector's generated controls from one vocabulary. **Rung 2 shipped** —
the surface `settings` key ({label, cap, tabs, route}; GET/POST one route per
tab so schema and values can't drift) is documented in for-plugin-authors.md,
and the Gravity SMTP mapper is the bundled reference: its
`settings_fields()` component tree maps once and covers all 21 connectors
(verified live: the primary-connector switch reshapes the tab from the new
connector's schema), writes go through its own data stores so constant locks
and the `****************` sentinel keep their semantics, and the surface
gates on its granular `gravitysmtp_*` caps. Suites:
tests/settings-surface.test.js (the shape, against a fixture surface),
tests/gsmtp-settings.test.js (the mapper, against the live plugin).
**Rung 3 largely shipped** (later the same day): parameterized actions
(`fields` on detail-modal and status-card actions — GS send-a-test with an
address field, GF add-note; routes may return `{ message }` for honest
outcome toasts), bulk selection (`bulk` on collections with per-item
`when`; GF entries star/read/spam/trash), a status/filter dimension
(`filter`, merging into a shared JSON criteria param — GF
Received/Spam/Trash round-trip inside Minn), and GS Suppressions in the
manage slot. Still open from the Rung 3 list: a chart row type, richer
sectionsRoute row types (pill/code/html-preview/kv-table), and row
actions in surface lists. The third list view shipped in the v0.13.0
cycle (2026-07-12): a surface may declare a `views` array of additional
collections (each with its own optional `cap`), and the Gravity SMTP
debug log is the bundled reference (its status-card link-out is gone).
Phase 2's remaining half, the GF Settings mapper, shipped in the v0.13.0
cycle (2026-07-12) in two pieces. **Form settings**: the `settings`
contract grew ITEM scope (a route containing `{id}` renders per item,
entered from a row's `settingsItem: true` action), and the GF adapter maps
`GFFormSettings::form_settings_fields()` at request time — the fourth
schema framework covered (GF's Settings framework joins Gravity SMTP
component trees, Minn's own form vocabulary and the core Settings API).
Mapper facts that transfer: GF's single-checkbox idiom (one choice named
like the field) is a boolean toggle with nested dependent fields;
`text_and_select` composites split into two Minn fields; `dependency`
rules map to `showWhen` (last rule = nearest parent; empty values =
truthy); the save whitelist derives from the same walk (selects validate
against their own choices) so schema and write path can't drift; the
composite save-and-continue keys route through GF's own
`activate_save`/`deactivate_save`, spam confirmation through
`toggle_spam_confirmation`, and everything lands in one
`GFAPI::update_form` (which round-trips notifications/confirmations
safely). Schedule date-times stay locked (no date type in the settings
vocabulary) and `markupVersion` stays locked (inverted 1/2 semantics).
**Notifications**: a `views` list across forms with type-aware
recipients, GF's own activate/deactivate, and daily-field editing
(name/send-to/subject/message) through `save_form_notifications`.
Confirmations editing is deliberately NOT built (edited at form-build
time, not daily; GF's screen is the deep link) — revisit only if real
use asks for it. Plugin settings (currency, logging) also remain unbuilt:
set-once config, and the license key already lives in Minn's license
manager.

**The multiplier, proven on a fresh plugin (2026-07-11):** the theory behind
this document was tested cold against Perfmatters, a settings-shaped plugin
with no prior Minn contact. Result: the whole build (a ~280-line adapter, a
15-check suite, zero new client field code) took one session fragment, and
the only machinery Minn had to grow was **settings-only surfaces** (a
surface may omit `collection` when it declares `settings` — three small
edits). Because Perfmatters registers everything through the core WP
Settings API, the adapter reads `$wp_settings_fields` as its schema at
runtime; that mapper pattern transfers to the thousands of plugins on the
same API and is the third schema framework covered (Gravity SMTP component
trees, Minn's own form vocabulary, core Settings API).

## The architectural framework: four rungs

The proposal is an escalation ladder. Each rung is independently shippable, each makes
the next cheaper, and a plugin's adapter can stop at any rung with the wp-admin
deep-link covering the rest. Verbatim principle throughout: Minn renders from schemas
read at runtime out of the live plugin, so when the plugin adds a field or an add-on,
coverage updates itself. Hand-built screens go stale; imported schemas don't.

### Rung 1 — one form engine (the keystone)

Merge the three field vocabularies into a single schema-driven form renderer, defined
by Minn (not by any one plugin): field types text, textarea, number, select,
select-with-custom, radio, checkbox, toggle, hidden, plus per-field `required`,
`default`, `choices`, `help`, `placeholder`, validation messages, and simple
`dependency` rules (show field B when field A equals X; GF evaluates these
client-side today via a small operator set, and Minn's `when` conditions are already
the same idea). The editor-panels engine (`panelInput`/`panelCard`) is the working
seed: it already handles choices, toggles, dirty tracking, and locked-field
escalation. Decouple it from the post-save payload so any caller can point it at a
`{ schemaRoute, valuesRoute, writeRoute }` triple.

Everything downstream reuses this: surface create/edit grows real field types for
free, editor panels lose nothing, and settings surfaces become possible at all.

### Rung 2 — settings surfaces + schema mappers (the multiplier)

New surface shape:

```php
'settings' => [
  'schemaRoute' => 'minn-admin/v1/gf/settings-schema/{tab}',
  'valuesRoute' => 'minn-admin/v1/gf/settings/{tab}',
  'writeRoute'  => 'minn-admin/v1/gf/settings/{tab}',
  'tabs'        => [ ... ],
],
```

The client renders tabs of form-engine groups and PUTs changed values back. All
intelligence lives in the adapter shim, which does two jobs:

- **Schema mapping.** Translate the plugin's native field descriptors into Minn's form
  vocabulary. This is written once per *framework*, not per screen. A GF Settings
  mapper (23 types, most mapping 1:1 onto Minn's) covers GF plugin settings, form
  settings, notifications, confirmations, and every GFAddOn's plugin/form/feed
  settings, present and future. A Gravity SMTP mapper (~8 component types) covers all
  21 connectors and the general/logging/alerts tabs. Unmappable field types (GF's
  `generic_map`, `notification_routing`; anything with an unresolvable callable) are
  counted and surfaced as "N advanced fields — edit in wp-admin ↗", exactly the
  locked-fields pattern the ACF panel already ships.
- **Value plumbing.** Read and write through the plugin's own PHP APIs inside the
  shim, under the plugin's own capability model. For GF: `GFAPI`,
  `update_plugin_settings()`, full-form PUT for notification/confirmation edits,
  always gated through `GFCommon::current_user_can_any()`. For Gravity SMTP: the
  service container's data stores (which preserve `GRAVITYSMTP_*` constant-lock
  behavior), gated on the granular `gravitysmtp_*` caps. Shim rules stay in force:
  prefix-scoped queries, never `unserialize()` third-party blobs, sensitive-field
  sentinels (Gravity SMTP masks secrets as `****************`; an unchanged sentinel
  must skip the write, matching its own save semantics).

This rung is where "full UI support" stops being hypothetical: it converts both
plugins' settings estates, which is most of their admin surface by screen count.

### Rung 3 — richer surface primitives

Grow the existing vocabulary along lines the research showed are actually needed, in
rough priority order:

- **Parameterized actions.** `fields` on an action (rendered by the form engine in a
  small modal) and `{item.key}` substitution in bodies. Unlocks Gravity SMTP "send
  test", "resend to different address", GF "resend notifications" with a picker.
- **Bulk selection.** Checkbox column plus a bulk-action bar reusing the same action
  descriptors. Unlocks GF entries bulk star/read/spam/trash and log cleanups.
- **Surface stat cards and a chart row type.** The `status` card (shipped
  2026-07-10) covers stat rows + actions above a list; still missing: a chart row
  type and per-item stat tiles. Unlocks the Gravity SMTP dashboard and GF
  `/forms/{id}/results`.
- **Richer `sectionsRoute` row types.** Today only `url` is special. Add `pill`,
  `code`, `html-preview` (sandboxed iframe, the `messageKey` machinery generalized),
  and `kv-table`. The email-log detail (headers, per-event audit trail, rendered
  preview) becomes fully expressible.
- **Row actions in the list** (the content-list `⋯` row menu pattern, generalized) and
  a third navigation level only if a real adapter demands it; tabs plus main/manage
  have covered everything so far.

### Rung 4 — the bespoke rung (decide it, don't drift into it)

Two candidate policies for surfaces beyond schemas, and the recommendation is the
first:

- **Delegate (recommended, and already the shipped pattern).** The GF form editor is
  to Gravity Forms what Elementor's canvas is to Elementor, and Minn already decided
  that case: detect, deep-link, never rebuild. The research reinforces it: the
  editor's ~100 setting panels are hand-authored inline JS, the drag-drop layer is a
  full application, and GF's own edit-locking is admin-screen-bound (a foreign editor
  would bypass it). Meanwhile the payoff is thin, because form *management* (lists,
  entries, settings, notifications) is the daily work; form *building* is occasional.
- **Plugin-provided JS views (rejected).** Letting adapters register arbitrary JS
  views would be the end of the no-build, one-file, greppable architecture and a
  security regression (arbitrary third-party code inside the Minn document, which
  currently only the deliberately page-level `minn_admin_template_footer` hatch
  allows, for developer tooling). If a screen can't be expressed in descriptors, the
  answer is the deep link.

A long-horizon note, recorded so the option stays visible: GF's form editor writes a
clean, fully enumerable JSON document (`display_meta`) through `PUT /forms/{id}`, the
field palette is programmatic (`GF_Fields::get_all()`), per-type setting keys are
enumerable, and conditional logic is a trivial rule JSON. A Minn-built form editor is
therefore *possible* without touching GF's JS. It would be a product decision on the
scale of "Minn builds a second editor", not an adapter feature, and nothing in rungs
1-3 forecloses it. That option (the "80% editor"), plus the developer-surface
siblings (read-only database viewer, file browsing), is now scoped with its
boundaries drawn in `docs/native-editors.md` — parked, not scheduled.

## Case study: Gravity Forms coverage map

Current adapter (includes/adapters/gravity-forms.php): entries list + sectioned
detail, forms list with activate/deactivate. With the rungs:

| Surface | Rung | Mechanics |
|---|---|---|
| Forms list + trash/duplicate | 1-3 | REST covers trash; duplicate needs a one-line shim route (`GFAPI::duplicate_form` has no REST route) |
| Entries: search, bulk, star/read, spam/trash | 3 | `gf/v2` entries CRUD + `/field-filters` even supplies the filter-builder schema |
| Entry detail: notes, resend, edit values | 2-3 | notes have full REST; editing entry values is form-engine rendering of field-type inputs |
| Form settings | 2 | Settings-framework schema via mapper; persist via full-form PUT |
| Notifications + confirmations | 2 | Settings-framework pages; stored inside the form JSON, so writes are read-modify-write full-form PUTs in the shim (concurrency note below) |
| Plugin settings (license, reCAPTCHA, currency, logging) | 2 | no REST today; shim reads/writes the `rg_gforms_*` options through GF's own accessors |
| Add-on settings + feeds (all add-ons) | 2 | feeds already have full REST CRUD; only the schema export is new. This is the multiplier: one mapper, every add-on |
| Results/reports | 3 | `GET /forms/{id}/results` + stat cards/chart |
| Import/export | 3 | form JSON is just GET/POST of `/forms`; entry CSV via a shim route |
| Form editor (drag-drop) | 4 | delegate: "Edit form in Gravity Forms ↗" |

Capability model: mirror GF exactly. Surface cap stays `read` with every shim gated
through `GFCommon::current_user_can_any()` per the hard-won rule (admins hold
`gform_full_access`, not the granular caps).

## Case study: Gravity SMTP coverage map

Current adapter (includes/adapters/gravity-smtp.php): email log surface. Gravity SMTP
is the stronger full-coverage candidate because it has no drag-drop equivalent: with
rungs 1-3 complete, effectively the whole plugin is expressible.

| Surface | Rung | Mechanics |
|---|---|---|
| Email log + detail (headers, audit trail, HTML preview, resend) | 1-3 | custom tables already shimmed; preview via the sandboxed iframe row type; resend as a parameterized action |
| Connector config (21 connectors) | 2 | `settings_fields()` descriptors via the container, mapped once; sensitive-sentinel semantics preserved on save |
| Primary/backup routing, general/test-mode/logging/alerts settings | 2 | two JSON option shapes (`gravitysmtp_config`, `gravitysmtp_{connector}`) read through the data-store router so constant locks are respected |
| Dashboard | 3 | stat cards + chart from `get_dashboard_data` equivalents computed in the shim |
| Send a test | 3 | parameterized action |
| Suppression list | 1 | today's vocabulary nearly suffices (list + create + reactivate action) |
| Debug log | 1 | list + detail, same shape as Minn's own System debug-log viewer |
| OAuth connectors (Google/Microsoft/Zoho) | 4 | delegate: external handshake via wp-admin deep link; everything after connection renders normally |

Transport note: Gravity SMTP has no REST API at all (100% admin-ajax with per-action
nonces, and its read state is server-rendered into its page payload). The adapter
therefore does not talk to its ajax endpoints; the shim calls its service container
directly server-side, which is both simpler and immune to its pre-rendered
component-tree response shapes.

## Suggested sequencing

- **Phase 1 (the keystone):** unified form engine; port editor panels onto it;
  upgrade surface `create`/`detail.edit` to the full vocabulary. Pure consolidation,
  no new product surface, immediately pays down three-systems drift.
- **Phase 2 (prove the multiplier):** `settings` surface shape + the Gravity SMTP
  mapper first (smaller vocabulary, no full-form round-trips, instant visible win:
  connector config in Minn), then the GF Settings mapper with form settings and
  notifications/confirmations.
- **Phase 3 (daily-work depth):** parameterized actions, bulk selection, stat
  cards/chart, richer detail row types; GF entries workflow and the Gravity SMTP
  dashboard as the proving grounds.
- **Phase 4 (declare victory):** document the mapper-authoring pattern in
  for-plugin-authors.md so third parties can ship their own (the Anchor Blocks
  convention, extended); the GF form editor stays a deep link unless a deliberate
  product decision reopens it.

Each phase ends the way Minn features end: browser-verified suites against the live
fixtures (GF is installed on minnadmin with seeded forms/entries; Gravity SMTP is
active on anchor for read-only walks and should get a minnadmin install with seeded
log fixtures before Phase 2).

## Risks and open questions

- **Schema callables.** GF descriptors may carry PHP callables (choices, render
  callbacks). Mappers resolve what they can and count the rest as locked fields;
  never attempt to serialize a callable.
- **Full-form PUT concurrency.** Editing one notification round-trips the whole form
  JSON, so two editors can clobber each other. Mitigate by read-modify-write inside
  the shim (one request, smallest window) and by checking `date_updated` before
  write; GF's own locking is admin-screen-bound and cannot help here.
- **Version drift.** Runtime schema import self-heals for fields, but mapper code
  touches plugin internals (GF's Settings classes, Gravity SMTP's container names).
  Every shim already guards on plugin presence; mappers must also guard on class/
  method existence and degrade to the deep link, never fatal.
- **Encrypted blobs.** GF add-on settings options are encrypted at rest; always go
  through `get_plugin_settings()`/`update_plugin_settings()`, never raw options.
- **Scope discipline.** The failure mode of this roadmap is rebuilding wp-admin one
  descriptor at a time. The `stats`/chart/bulk additions are bounded by what the two
  case-study plugins actually need; anything only one hypothetical plugin would use
  waits for that plugin to exist.

## What we will never build

Embedding plugin admin pages (iframes or otherwise), plugin-registered JS views, a
generic XML-ish layout language, drag-drop editor rebuilds, and per-screen hand-built
clones of plugin UIs. The deep link to wp-admin is not an apology; it is the escape
hatch that keeps every rung honest.
