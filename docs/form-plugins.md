# Form plugins — adapter hunt (2026-07-09)

**Question:** Minn only ships a Gravity Forms surface today. Which other form
plugins are worth a Forms family member (list + entry detail, same pattern as
Snippets / Activity Log / Redirects)?

**Today:** `includes/adapters/gravity-forms.php` only. Label `Forms`,
`sub` Gravity Forms. Pure descriptor over `gf/v2` when REST is enabled, plus
small shims for labeled entry detail and the forms manage list.

**Goal:** `family: 'forms'` with a topbar provider switcher when more than one
entries-capable form plugin is active. Deliberately **not** a form builder —
deep-link to each plugin's editor for create/edit.

---

## Landscape (wp.org active installs, free slugs)

| Plugin | Free installs | Entries storage | REST / API for entries | Minn fit |
|---|---|---|---|---|
| **Contact Form 7** | 10M+ | **None** unless Flamingo (CPT `flamingo_inbound`) | CF7 has form REST; entries need Flamingo + shim | Weak alone; Flamingo pair is possible later |
| **Elementor** | 10M+ | **Pro only** — `{prefix}e_submissions` + `e_submissions_values` (+ actions log) | **No public REST** for submissions | **High value shim** if Pro is on the site |
| **WPForms Lite** | 5M+ | Lite does **not** store local entries (email / Lite Connect); **Pro** uses `wpforms_entries` + meta/fields tables | Abilities API (`wpforms/*` via `/wp-abilities/v1/…/run`) since 1.9.9; entries abilities **Pro** | Reach king; Lite useless for Minn entries; Pro = abilities **or** SQL shim |
| **Gravity Forms** | paid (not on wp.org) | Custom tables via GFAPI | **`gf/v2`** first-class (cookie auth) | **Shipped** |
| **Fluent Forms** | 700k+ | Custom tables; full entry UI | **`fluentform/v1`** — forms + **submissions** (15 submission routes) | **Best next pure-REST adapter** |
| **Ninja Forms** | 600k+ | Custom tables | Limited / addon-ish | Later |
| **Forminator** | 600k+ | Custom tables | Some REST under `forminator/v1` | Later |
| **Formidable** | 300k+ | Custom tables | **`frm/v2`** (often API / higher tier) | Strong if license exposes REST |
| Everest / JetFormBuilder | ~90k | Varies | Varies | Low priority |

Sources: wordpress.org plugin API (install counts), vendor developer docs (Fluent, WPForms Abilities, Formidable `frm/v2`), Elementor help + community DB notes (`e_submissions*`).

---

## Ranked for Minn

### 1. Fluent Forms — **best next free adapter**

| Concern | Finding |
|---|---|
| Reach | 700k+ free; modern UI; same vendor family as FluentSnippets (already adapted) |
| REST | Namespace `fluentform/v1`. Auth: `X-WP-Nonce` (same cookie model as Minn). |
| Entries | `GET …/submissions`, `GET …/submissions/{id}`, delete, status, notes, bulk-actions, print. Forms group has 16 routes. |
| Shape for Minn | Likely **pure descriptor** for list + trash; detail may need a thin shim to map field labels (same reason GF has `minn-admin/v1/gf/entries/{id}`). |
| Cap | Their SubmissionPolicy (map to `fluentform_view_entries` / manage equivalents). |

**Surface sketch:** `label: Forms`, `family: forms`, `sub: Fluent Forms`. Collection = submissions list with form tabs; manage view = forms list + "Edit in Fluent Forms ↗".

---

### 2. Elementor Pro Forms — **best "builder that is also forms" pick**

Austin's ask. Forms are **not** in free Elementor; the Form widget + Submissions UI are **Elementor Pro**.

| Concern | Finding |
|---|---|
| Storage | Three tables (prefix-scoped): `e_submissions`, `e_submissions_values`, `e_submissions_actions_log`. Values are EAV-style (submission_id + key/label + value). |
| Admin | **Elementor → Submissions** when Collect Submissions is enabled on the form. |
| REST | **None** for browsing submissions (community + Elementor discussions). Outbound webhooks / "Actions after submit" exist; that is the wrong direction for Minn. |
| Cap | Typically `manage_options` / Elementor Pro form caps — verify against live Pro. |
| Lab gap | `builders.localhost` has free Elementor only; **no Elementor Pro zip** on hand. Need Pro to fixture + probe columns and any internal Query classes. |

**Surface sketch:** shim `minn-admin/v1/elementor/submissions` (list + detail) — prefix-scoped `$wpdb` SELECTs only; **never** unserialize third-party blobs. Columns: summary (name/email heuristics from values), form name, status, date. Detail: labeled field rows from `e_submissions_values`. Action: trash/delete if Pro supports it safely. Deep link: `admin.php?page=e-form-submissions` (confirm path on Pro version).

**Why it ranks high:** Elementor sites often have **no** Gravity/Fluent/WPForms — only the Pro form widget. Without this adapter, Minn's Forms nav is empty on a huge slice of sites that still get daily contact spam.

---

### 3. WPForms Pro — **largest brand; entries are Pro-gated**

| Concern | Finding |
|---|---|
| Lite | No local entry store for Minn to list. Skip Lite for entries surface. |
| Pro storage | `wp_wpforms_entries`, `wpforms_entry_meta`, `wpforms_entry_fields`. |
| Official API | Not classic `wpforms/v1/entries`. Since 1.9.9 / 1.10.x they expose **Abilities API** abilities: `wpforms/get-entry-summaries`, `wpforms/get-entry`, `wpforms/search-entries` (Pro), invoked via `/wp-json/wp-abilities/v1/abilities/…/run`. Awkward for Minn's generic collection descriptor (expects a normal list route + page query). |
| Better path | SQL / internal API shim `minn-admin/v1/wpforms/entries` (same style as WSAL/Stream), **or** a thin wrapper that calls `wp_get_ability(…)->execute()` server-side and normalizes to `{ items, total }`. |
| Cap | `wpforms_current_user_can()` / `view_entries` family. |

**Surface sketch:** `sub: WPForms`. Gate on Pro + entry tables or ability existence.

---

### 4. Formidable Forms — **clean REST if available**

`frm/v2/entries`, `frm/v2/forms/{id}/entries` documented. Often tied to API / paid tier. Pure descriptor candidate when the routes register. Lower free installs than Fluent; still a clean fit.

### 5. Contact Form 7 + Flamingo — **reach without structure**

CF7 alone stores nothing. Flamingo CPT `flamingo_inbound` + meta can be listed via `wp/v2` if `show_in_rest` or a small shim. Field model is free-form meta keys (`_field_*`). Worth a later "Inbox" style surface, not first-class form tabs like GF.

### 6. Ninja Forms / Forminator — backlog

Solid install base; adapt after Fluent + Elementor + WPForms prove the family UX.

---

## Family pattern (same as Snippets)

```php
$surfaces['gravity-forms'] = array(
  'label'  => 'Forms',
  'family' => 'forms',
  'sub'    => 'Gravity Forms',
  // …
);
// Fluent Forms, Elementor, WPForms, Formidable → same family + distinct sub.
```

Sidebar: one **Forms** item. Topbar autocomplete when `surfacesInFamily('forms').length > 1`. Preference key `minn-sf-forms`.

---

## Recommended build order

1. **Tag Gravity Forms** with `family: 'forms'` (no UX change while alone).
2. **Fluent Forms** pure-REST adapter on minnadmin (free install, full REST) — proves multi-provider Forms switcher.
3. **Elementor Pro** submissions shim once a Pro zip is available on the builders lab (or a customer fixture) — high fleet value.
4. **WPForms Pro** entries shim (or abilities wrapper) when a Pro license is available for fixtures.
5. Formidable / Flamingo as capacity allows.

## Lab needs

| Adapter | Needs on minnadmin / builders |
|---|---|
| Fluent Forms | `fluentform` free from wp.org + seed form + entries |
| Elementor Forms | **Elementor Pro** zip + Form widget + Collect Submissions + 1–2 fixture posts |
| WPForms entries | **WPForms Pro** (Lite insufficient) |
| Formidable | Free or Pro with API routes registered |

## Out of scope (same as GF)

- Form field builders, conditional logic, payment feeds, spam settings UIs.
- Creating forms inside Minn.
- Unifying entries across plugins into one merged inbox (family switcher is enough).

---

## Gravity Forms (shipped) — quick recap

- Gate: `GFAPI` + REST API setting enabled + `GFCommon::current_user_can_any(…view_entries…)`.
- List: `gf/v2/forms/{tab}/entries` + all-entries route; tabs from `gf/v2/forms`.
- Detail shim: `minn-admin/v1/gf/entries/{id}` (labeled answers).
- Manage: `minn-admin/v1/gf/forms` activate/deactivate + deep link to GF editor.
