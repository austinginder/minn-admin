# Form plugins — Forms family map

Originally a 2026-07-09 source hunt ("which form plugins are worth a Forms
family member?"). Stale-checked 2026-07-12 during the v0.13.0 cycle: the
family is live with **eight** providers, the adapter ladder proved out on
Gravity Forms (entries → notifications → form settings), and the remaining
work is thin leftovers plus one big brand (WPForms Pro) that needs a
license for fixtures.

**Today:** `family: 'forms'` with a provider switcher. Deliberately **not**
a form builder: deep-link to each plugin's editor for create/edit of the
form document. Scope and boundaries for a future "80% form editor" over
clean documents live in `docs/native-editors.md` (parked).

## Coverage (shipped)

| Plugin | Adapter | What Minn surfaces |
|---|---|---|
| **Gravity Forms** | `gravity-forms.php` | Entries as contact cards with Received/Spam/Trash, star/read, notes, resend, bulk; **Forms** manage view (activate/deactivate); **Notifications** view (toggle + daily-field edit); **Form settings** (item-scoped settings from GF's Settings-framework schema at request time). Full workflow depth. |
| **Fluent Forms** | `fluent-forms.php` | Entries list + labeled detail; forms manage view. Pure-REST shape over `fluentform/v1` (Laravel paginator normalized). |
| **Elementor Pro Forms** | `elementor-forms.php` | Submissions via Elementor's own Query class; soft-trash through `move_to_trash_submission`. Free Elementor has no submissions store. |
| **Contact Form 7 + Flamingo** | `cf7-flamingo.php` | Inbound messages through Flamingo's own model (spam/unspam/trash); CF7 forms in manage with live channel counts. CF7 alone stores nothing. |
| **CFDB7** | `cfdb7.php` | Entries from `{prefix}db7_forms` (serialized map scanned by byte-length tokens, never unserialized); open-marks-read; permanent delete. |
| **Ninja Forms** | `ninja-forms.php` | Entries as `nf_sub` postmeta cards, form tabs, labeled detail, trash through its own model, forms manage with live entry counts. |
| **Forminator** | `forminator.php` | Entries from Forminator's own models, labels resolved at runtime, permanent delete through `Forminator_API::delete_entry`, forms manage. Honors `forminator-entries` permission model. |
| **Formidable** | `formidable.php` | Entries via `FrmEntry`, labels from field models at runtime, UTC stamps, permanent delete through `FrmEntry::destroy`, caps mirroring granular-or-administrator. |

The family lives under Workspace. Provider preference key: `minn-sf-forms`.

## Landscape leftovers (not yet adapted)

| Plugin | Free installs (approx.) | Why it is still open | Fit |
|---|---|---|---|
| **WPForms Pro** | 5M+ Lite brand | Lite stores **no** local entries (email / Lite Connect only). Pro uses `wpforms_entries` + meta/fields. Abilities API since 1.9.9 is awkward for the collection descriptor; a SQL/internal shim is cleaner. **Needs a Pro license + fixtures.** | Highest-value uncovered brand; costs a license. |
| **SureForms** | growing | Free-tier entry storage believed but not source-verified. | Verify storage before promising. |
| **MetForm** | ~100k+ | Same: free-tier storage not source-verified. | Verify first. |
| Everest / JetFormBuilder | ~90k | Lower reach; varies by storage. | Low priority. |

Sources for install counts: wordpress.org plugin API (ballpark; refresh when ranking again).

## Family pattern

```php
$surfaces['gravity-forms'] = array(
  'label'  => 'Forms',
  'family' => 'forms',
  'group'  => 'workspace',   // inbox-shaped
  'sub'    => 'Gravity Forms',
  // collection / manage / views / settings …
);
// Every other forms adapter uses the same family + distinct sub.
```

Sidebar: one **Forms** item. Topbar autocomplete when
`surfacesInFamily('forms').length > 1`.

## Build history (for orientation)

1. Tag Gravity Forms with `family: 'forms'` — done 2026-07-09.
2. Fluent Forms + Elementor Pro Forms — done same wave.
3. CF7 via Flamingo + CFDB7 — done v0.10.0 cycle.
4. Ninja Forms — done v0.12.0 cycle.
5. Forminator + Formidable — done v0.13.0 cycle.
6. GF depth (status filters, bulk, notifications view, form settings) — done
   across v0.12.0–v0.13.0; see `docs/full-ui-adapters.md`.

## Fixture expectations (for suites)

| Adapter | What a test site needs |
|---|---|
| Gravity Forms | Active; at least one form with seeded entries (and ideally one inactive form) |
| Fluent Forms | Installed so the family switcher has another provider |
| Elementor Pro Forms | Elementor Pro with Collect Submissions + at least one form submission |
| Flamingo + CFDB7 | Both active with a few standing inbound rows; suites may use one-shot seed options |
| Ninja Forms | Active; default form is fine once `Ninja_Forms()->activation()` has run |
| Forminator | Active form + entries; suites can arm `minn_test_seed_forminator` |
| Formidable | Active form + entries after `FrmAppController::install()`; suites can arm `minn_test_seed_formidable` |
| WPForms entries | **WPForms Pro** zip + license (Lite has no local entry store) |

## Out of scope (same as day one)

- Form field builders, conditional-logic rule builders, payment feeds, spam
  settings UIs that belong to the form plugin's own product.
- Creating forms inside Minn (the 80% editor, if it ever ships, is a
  deliberate product bet over clean documents: `docs/native-editors.md`).
- Unifying entries across plugins into one merged inbox (the family
  switcher is enough).

## Gravity Forms depth (the reference adapter)

- Gate: `GFAPI` + REST API setting enabled +
  `GFCommon::current_user_can_any(…)` (never a raw granular cap).
- Entries: `gf/v2` + status filters + bulk + notes + resend; detail shim
  `minn-admin/v1/gf/entries/{id}` for labeled answers.
- Forms manage: `minn-admin/v1/gf/forms` activate/deactivate + deep link.
- Notifications view: composite row id `form:nid`; toggle via
  `GFFormsModel::update_notification_active`; edits via
  `save_form_notifications`.
- Form settings: item-scoped settings from
  `GFFormSettings::form_settings_fields()` at request time; save through
  `GFAPI::update_form` with GF's own helpers for composites.
- Confirmations editing and plugin-wide settings (currency, logging) stay
  deep-linked: set-once / form-build-time work, not daily.
