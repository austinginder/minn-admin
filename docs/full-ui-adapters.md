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

## Where the adapter system stands today (re-verified at v0.16.0 open, 2026-07-15; stale-checked 2026-07-23 at v0.21.0 open)

The thesis held. Rungs 1–2 are shipped, Rung 3 is essentially shipped, and two
hard case studies (Gravity Forms + Gravity SMTP) plus a cold third
(Perfmatters) proved the multiplier. Ground truth for the vocabulary is
still `docs/for-plugin-authors.md` and the validator constants in
`class-minn-admin-surfaces.php`.

### Shipped rungs

| Rung | Status | What landed |
|---|---|---|
| **1 — form engine** | ✅ shipped (v0.12.0) | One vocabulary renders surface create/edit, editor panels and inspector controls (`required` / `default` / `help` / `placeholder` / `showWhen`, toggles, selects as themed comboboxes in adapter dialects). |
| **2 — settings surfaces + mappers** | ✅ shipped (v0.12.0–v0.13.0) | Surface `settings` key (tabs + one GET/POST route per tab); **settings-only** surfaces (no `collection`); **item-scoped** settings (`route` with `{id}`, entered via `settingsItem` actions). Four schema frameworks covered: Gravity SMTP component trees, Minn's form vocabulary, core WP Settings API (Perfmatters), GF Settings framework (form settings). |
| **3 — richer primitives** | mostly ✅ | Parameterized actions (`fields` + honest `{ message }` toasts), bulk selection, status/filter dimension, `status` cards (incl. chart series, v0.13.0), `views[]` extra list views, manage-slot second collections, **list-row ⋯ menus** from `actions` (v0.13.0). Surface toolbars calmed (two-row switcher + quiet filters + long tab lists → combobox) in the v0.13.0 cycle. Richer `sectionsRoute` row types (`pill`/`code`/`html-preview`/`kv-table`) shipped v0.18.0; **sortable columns** (`sort` tokens + `sortQuery`) shipped 2026-07-17. Remaining: per-item stat tiles, more chart consumers. |
| **4 — bespoke** | policy holding | Deep-link everywhere a screen is a canvas. The "80% form editor" over clean documents is scoped in `docs/native-editors.md` (parked, prerequisite plumbing now live). |

### Still open from the Rung-3 list

- ~~**Chart row type**~~ ✅ shipped (v0.13.0): status cards accept optional
  `chart: { title, primary, secondary, points:[{label,value,secondary?}] }`
  and render Overview-style bars with a hover tip. Gravity SMTP's Email
  status card is the first consumer (14-day sent/failed from its events
  table). Still open beside it: per-item stat tiles, and other chart
  consumers (GF form results; ecommerce analytics shipped v0.14.0).
- ~~**Richer `sectionsRoute` row types**~~ ✅ shipped (v0.18.0 open,
  2026-07-17): `pill`, `code`, `html-preview` (fully sandboxed iframe) and
  `kv-table` rows in the sections renderer, documented in the author guide
  with the escaping/sandbox guarantees and pinned by the detail-rows suite
  (hostile payloads proven inert). Gravity SMTP's log detail is the first
  consumer; Fluent/Post/WPML conversions are natural next slices.
- ~~**Row actions in surface lists**~~ ✅ shipped (v0.13.0): any collection
  with `actions` grows a content-list-style ⋯ / right-click menu (Open +
  when-gated verbs). Parameterized `fields` actions stay detail-only; opt
  out of the list with `list: false`. No per-adapter menu duplication —
  the same `actions` array drives both surfaces.

### Schema frameworks covered (the multiplier)

1. **Gravity SMTP component trees** — `settings_fields()` once → all 21
   connectors (v0.12.0). Suite: `tests/gsmtp-settings.test.js`.
2. **Minn's own form vocabulary** — the Rung-1 engine every surface form
   and editor panel already speaks.
3. **Core WP Settings API** — Perfmatters cold-build (v0.12.0): read
   `$wp_settings_fields` at runtime after forcing the plugin's registration
   under REST (must `require` `wp-admin/includes/template.php` first).
   Suite: `tests/perfmatters-settings.test.js`.
4. **Gravity Forms Settings framework** — form settings at request time from
   `GFFormSettings::form_settings_fields()` (v0.13.0). Mapper facts that
   transfer: single-checkbox idiom → boolean toggle with nested dependents;
   `text_and_select` → two Minn fields; `dependency` → `showWhen` (last rule
   = nearest parent; empty values = truthy); save whitelist derived from the
   same walk; composites through GF's own `activate_save` /
   `deactivate_save` / `toggle_spam_confirmation`; everything lands in one
   `GFAPI::update_form`. Schedule date-times and inverted `markupVersion`
   stay locked. Suite: `tests/gf-form-settings.test.js`.

### Views and item settings (v0.13.0 opener)

- A surface may declare `views` (array of collections, optional per-view
  `cap`); client ids `x0`/`x1`…; Gravity SMTP **Debug log** is the reference
  (status-card link-out removed).
- Item-scoped settings: `settings.route` containing `{id}`, entered only via
  a row action with `settingsItem: true`. Gravity Forms per-form settings is
  the reference.
- **Notifications** as a `views` list (composite row id `form:nid`; toggle +
  daily-field edit through GF's own store). Confirmations editing and
  plugin-wide GF settings (currency, logging) deliberately unbuilt:
  form-build-time / set-once work; license key already lives in the license
  manager.

Historical note: at v0.10.0 none of Rungs 1–2 existed (Spam settings was a
bespoke card page; three field vocabularies; actions were static JSON only).
That baseline is why this document exists; the tables above are the current
state.

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
- ~~**Richer `sectionsRoute` row types.**~~ ✅ shipped (v0.18.0 open, 2026-07-17);
  see the Rung-3 entry above.
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

Adapter: `includes/adapters/gravity-forms.php`. Daily form work lives in Minn;
form *building* stays a deep link (Rung 4).

| Surface | Status | Mechanics |
|---|---|---|
| Forms list + activate/deactivate | ✅ | Manage view; deep link to GF editor |
| Entries: search, bulk, star/read, spam/trash | ✅ | `gf/v2` + status `filter` + bulk; detail shim for labeled answers |
| Entry detail: notes, resend | ✅ | notes REST; resend as parameterized action; edit field *values* still open (form-engine over field-type inputs) |
| Form settings | ✅ (v0.13.0) | Item-scoped settings; Settings-framework schema at request time; `GFAPI::update_form` |
| Notifications (list + toggle + daily fields) | ✅ (v0.13.0) | `views[]` list; `save_form_notifications` read-modify-write |
| Confirmations editing | deliberately unbuilt | Form-build-time; GF screen is the deep link |
| Plugin settings (reCAPTCHA, currency, logging) | unbuilt | Set-once; license already in the license manager |
| Forms list trash/duplicate | partial | REST covers trash; `GFAPI::duplicate_form` has no REST route (one-line shim if demand) |
| Add-on feeds (list, toggle, delete) | ✅ (v0.18.0) | Feeds `views[]` entry: every GFFeedAddOn integration across forms, per-form tabs, activate/deactivate via `GFFormsModel::update_feed_property`, delete via `GFAPI::delete_feed`, deep link to the add-on's feed screen. GOTCHA: `GFAPI::get_feeds` defaults to ACTIVE-ONLY; pass `$is_active = null` or deactivated feeds vanish |
| Add-on feed CONFIG (the schema mapper) | deferred, with a verdict | The "one mapper, every add-on" build needs (a) a SECOND item-scoped settings slot on a surface (form settings already claims `settings`; a new primitive) and (b) a fixture add-on whose fields are mappable without vendor credentials (Twilio's from/to selects are creds-gated `select_custom`, `feed_condition` is a builder). Revisit when a second real add-on fixture with plain fields exists; the deep link is honest until then |
| Results/reports | open (needs chart row) | `GET /forms/{id}/results` + status/chart |
| Import/export | open | form JSON is GET/POST of `/forms`; entry CSV via a shim |
| Form editor (drag-drop) | Rung 4 forever | "Edit form in Gravity Forms ↗" |

Capability model: mirror GF exactly. Surface cap stays `read` with every shim gated
through `GFCommon::current_user_can_any()` per the hard-won rule (admins hold
`gform_full_access`, not the granular caps).

## Case study: Gravity SMTP coverage map

Adapter: `includes/adapters/gravity-smtp.php`. No drag-drop equivalent, so with
Rungs 1–3 it is nearly the whole plugin.

| Surface | Status | Mechanics |
|---|---|---|
| Email log + detail + resend + delete | ✅ | custom tables; resend through its models (regex fallback); single/bulk delete via Event_Model (v0.16); sections detail with status pill, sandboxed HTML preview and headers kv-table (v0.18.0, the first row-types consumer) |
| Connector config (21 connectors) | ✅ (v0.12.0) | `settings_fields()` once; sensitive-sentinel preserved |
| General / test-mode / logging settings | ✅ | through its own data stores + constant-lock awareness |
| Suppressions | ✅ | manage-slot list + create + reactivate |
| Debug log | ✅ (v0.13.0) | first `views[]` consumer; priority tabs; status-card link-out removed |
| Send a test | ✅ | parameterized action with honest outcome toast |
| Dashboard / charts | ✅ partial (v0.13.0) | status card reports service + test mode + 14-day sent/failed chart; richer dashboard tiles still open |
| OAuth connectors (Google/Microsoft/Zoho) | Rung 4 | external handshake via wp-admin deep link |

Transport note: Gravity SMTP has no REST API at all (100% admin-ajax with per-action
nonces, and its read state is server-rendered into its page payload). The adapter
therefore does not talk to its ajax endpoints; the shim calls its service container
directly server-side, which is both simpler and immune to its pre-rendered
component-tree response shapes.

## Sequencing (phases against reality)

| Phase | Plan | Status |
|---|---|---|
| **1 — keystone** | Unified form engine; port panels; upgrade create/edit | ✅ shipped v0.12.0 |
| **2 — multiplier** | `settings` surface + Gravity SMTP mapper, then GF form settings + notifications | ✅ shipped v0.12.0–v0.13.0 (confirmations + GF plugin settings deliberately skipped) |
| **3 — daily-work depth** | Parameterized actions, bulk, status filters, views, status cards + chart, list row-actions | ✅ (sectionsRoute row types + sortable columns landed v0.18.0); remaining polish: per-item stat tiles, more chart consumers |
| **4 — declare victory** | Document mapper pattern for third parties; GF form editor stays deep link | docs live in `for-plugin-authors.md`; 80% editor parked in `native-editors.md` |

Natural next builds inside this ladder (not a ranked product roadmap; see
`docs/plugin-support.md` for install-weighted adapter waves):

1. More chart consumers (GF form results) on the status-card chart shape.
   ~~Ecommerce analytics~~ ✅ shipped v0.14.0 (Orders Analytics pill).
2. ~~Richer detail row types (email HTML preview, kv tables)~~ ✅ shipped
   (v0.18.0, whole mail family converted).
3. ~~Surface list row-actions (⋯ menus)~~ ✅ shipped (v0.13.0).
4. ~~GSMTP bulk log delete~~ ✅ shipped (v0.16.0 open).
5. ~~GF add-on/feed settings mapper~~ — resolved 2026-07-17: the Feeds VIEW shipped
   (list/toggle/delete, v0.18.0); the config mapper carries a written deferral
   verdict in the coverage table above.
6. Only after dogfooding: reconsider the 80% form editor.

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
