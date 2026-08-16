# Flexible content support — implementation plan

Status: planned, not started. Scoped 2026-08-16 against ACF Pro 6.8.7 and the
mula.localhost lab. Companion to `full-ui-adapters.md` (this is the last big
ACF rung) and the v0.31.0 ACF cycle. Delete this file when the work ships and
its content has moved into `plugin-support.md` + the changelog.

## Thesis

ACF flexible content is a repeater whose rows carry per-row schemas. After
the v0.31 cycle, everything INSIDE a row already exists in Minn: the shared
per-type value layer (`minn_admin_acf_value_in` / `value_out`), the `__idx`
row merge that preserves unmapped subs, group-sub flattening into prefixed
row columns (`gpath`), and the link / color / image / gallery / file / date
controls. The only structural novelty is that each row picks its schema by
layout. Build it as a `flex` variant of the rows control, not a new system.

## Storage facts (verified on mula)

- Raw value (`get_field( $key, $pid, false )`) = ordered array of rows; each
  row is an array keyed by sub-field KEYS plus `'acf_fc_layout' => layout
  name`. Empty field reads `null`/`false`.
- Layouts live on the field as `$f['layouts']` = list of
  `{ key, name, label, sub_fields, min?, max? }`.
- Writes go through `update_field( $key, $rows, $pid )` like repeaters; ACF
  stores the layout-name list as the field's own meta and per-sub metas per
  row. New rows MUST carry `acf_fc_layout`.
- Scale reality: mula's post-side `sections` field has 19 layouts (2–10
  top-level subs each, most carrying an `options` group that flattens to
  ~15–20 design controls). The 404 options page reuses the same layouts.

## Phase 1 — server (`includes/adapters/acf.php`)

1. Extract the sub-flatten walker from `minn_admin_acf_map_repeater` (the
   `$push` closure: chrome skip, group flatten with label/name prefixes +
   `gpath`, wysiwyg/suggest/relation locked) into a shared helper both the
   repeater map and the new layout map call.
2. `minn_admin_acf_map_flex( $f )` → mapped field:
   - `type: 'flex'`, `name`, `label`, `key`
   - `layouts`: name → `{ label, subfields (client shape), subLocked }`
   - internal `layoutSubs`: name → subs incl. keys/gpath (the write side),
     mirroring the repeater's `subfields` vs `subs` split.
   - A layout with zero mappable subs still lists (rows of it render as a
     card with a locked note) — never drop stored sections.
3. `minn_admin_acf_flex_out( $field, $val )` → `[{ __idx, __layout, values }]`
   — per-row sub mapping using the row's layout subs (reuse the rows_out
   inner loop; factor if clean). Unknown stored layout name → emit the row
   with `__layout` and empty values plus a `__locked: true` flag so the
   client renders an inert preserved card.
4. `minn_admin_acf_flex_in( $field, $value, $orig )` — the rows_in merge per
   row, choosing subs by the row's `__layout`; validate `__layout` against
   declared layout names (invalid row → keep original via `__idx` if
   anchored, else drop); `$base['acf_fc_layout']` always set; kept rows
   overlay only mapped subs (preservation discipline unchanged). Return
   null when the incoming shape is not a list.
5. Wire into the three carriers exactly like `rows`:
   - post panel: `fields_payload` / `simple_fields_for_post` (`'flexible_content'`
     branch beside `'repeater'`), `read_values` / `write_values` branches.
   - options: `minn_admin_acf_options_tabs` walk (top level AND inside the
     recursive group flatten — mula's 404 flex is top-level), `tab_shape`
     passthrough (`flex` + `layouts`), `options_save` (uses `stored_at` for
     the merge orig; `_path` descent already generic).
   - block dataForms: stay locked (as today).
6. Conditional logic: field-level cond already rides `_cond`. Per-SUB conds
   inside layouts are Phase 3 — until then subs render unconditionally
   (values preserved; matches how repeater subs behave today).

## Phase 2 — client (`assets/js/app.js`)

1. `formControlHtml` type `'flex'`: shell like `rows`, with
   `data-flex-layouts` (JSON: name → { label, subfields, subLocked }) and
   `data-rows-val` seeding `[{ __idx, __layout, values }]`.
   `formControlValue` mirrors `rows` (`el._rowsValue` first).
2. `bindFlexField( wrap, onChange )` — a sibling of `bindRowsField` sharing
   its inner pieces (extract the per-sub control render + the image/date
   arming loop rather than duplicating):
   - Row card header: layout LABEL + a text preview (first non-empty
     text/textarea/wysiwyg-preview value, truncated) + move/delete + a
     collapse toggle.
   - **Collapsed by default** — non-negotiable at 19-layout scale; expanded
     state kept per card in the binder (not persisted).
   - Per-row def = `layouts[ row.__layout ].subfields`; `__locked` rows and
     unknown layouts render an inert "preserved as-is" card.
   - Per-layout `subLocked` note per card ("N design settings live in
     wp-admin").
   - `+ Add section` opens `openMinnMenu( x, y, entries )` listing layout
     labels → push `{ __layout, values: {} }`, render expanded.
   - Commit contract identical to rows: `wrap._rowsValue`, `onChange`.
3. Arming: editor panel branch (`ftype === 'flex'` next to `rows`), settings
   engine branch. Rows' gallery-sub delegated click + media/date arming
   loops must cover flex cards too (shared extraction handles this).
4. CSS: collapse chrome on `.minn-rows-card` (header row + hidden body);
   keep tokens/theming conventions.

## Phase 3 — fit and finish

- Per-row conditional display: layouts lean on conds (mula: `bg_color` when
  `bg_type == color`). Server: ship each sub's cond translated to sibling
  NAMES within the layout (reuse the fields_payload translation; controllers
  outside the row → drop the cond, show always). Client: `acfCondShow` with
  a row-scoped `getVal` reading sibling controls in the same card; re-apply
  on every row input. This also upgrades plain repeater subs for free.
- Honor per-layout `min`/`max` on add/delete (soft: hide the menu entry at
  max).
- Duplicate section (clone row values, drop `__idx`).

## Fixtures + suites

- Options lab (minnadmin, `group_minn_options_lab`): add a flex field
  `opt_sections` (2–3 small layouts: `hero` = text title + color + a group;
  `quote` = textarea + link; `spacer` = button_group only) — **child-post
  fields only** (`acf_update_field` with `parent` = field ID per level; the
  inline-sub trap has bitten three times: inline subs load inconsistently
  and write empty-prefixed meta names). Layout sub-fields on a
  flexible_content field: verify how layouts persist for DB fields (layouts
  live IN the flex field's settings, sub_fields as child posts parented to
  the flex field with a `parent_layout` key — probe ACF's own storage on a
  scratch group FIRST, same discipline as the group/repeater probes).
- Post-side fixture: a small flex group on posts (or reuse the options one
  via a second location) for panel coverage.
- New suite `tests/acf-flex.test.js`: stored sections render collapsed with
  layout labels; expand + edit a sub + save → REST verify; unmapped sub
  preservation (seed a locked-type sub value via wp-cli, edit a sibling,
  verify survival); add via the layout picker (menu entries = layouts);
  reorder + delete with `__idx` anchors; unknown-layout row preserved
  byte-true; per-layout locked note. Panel + options both.
- Regressions: acf-options 31, acf-repeater 9, acf-panel 45,
  acf-options-menu 6, data-form 16.
- Live verification on mula (read-only + revert): the 404 tab renders its
  stored sections; a scratch draft post drives the panel side. Mula browser
  login = cookie injection (`wp_generate_auth_cookie` pair for `anchorhost`,
  the anchor.localhost trick); mula's minn-admin is SYMLINKED to the dev
  copy.

## Boundaries (keep saying no)

- No visual/front-end preview — it's a settings form; "edit in wp-admin"
  stays one click away and the named-locked note covers what's excluded.
- Clone fields inside layouts stay locked per row (values preserved).
- Layout SCHEMA authoring stays in ACF's field editor (and out of Minn's
  field-group builder for now).
- Block dataForms keep flexible content locked.

## Gotchas carried from the cycle (do not relearn)

- Fixture fields must be child posts; never inline `sub_fields` via
  `acf_update_field`.
- `wp eval` REST probes need `--user=admin` (permission callbacks 401
  silently) and ACF's runtime store caches fields within a request — verify
  fixture changes in a FRESH eval.
- Suite failures during FrankenPHP crash windows fake regressions
  convincingly (four reruns + a false git-stash bisect last time): check
  `~/Library/Logs/DiagnosticReports/frankenphp-*.ips` + watchdog.log, settle
  with the sub-second curl guard, re-run, and only then bisect.
- The settings engine sends DIRTY keys only; the panel round-trips the whole
  `panelValues` object — flex must behave under both.
- `changelog.md` entry rides the eventual release commit (never committed
  mid-cycle); update `plugin-support.md` + `for-plugin-authors.md` (the
  `flex` field type joins the documented vocabulary) in the shipping commit.

## Estimate

Phase 1 ~1 day, Phase 2 ~1 day, Phase 3 ~1 day, fixtures/suites woven in.
Options first, panel second, conditionals third.
