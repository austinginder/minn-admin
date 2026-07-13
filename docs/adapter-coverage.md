# Adapter coverage matrix

Living checklist for **bundled** adapters: which Minn surface primitives each
one uses, last reviewed, and deliberate deep-links. Maintained by the
**Adapter sweep** runbook in the `dev-minn-admin` skill (`/dev-minn-admin sweep`).

This is **not** the marketing map. For "what plugins show up in Minn," see
`plugin-support.md`. For descriptor contracts, see `for-plugin-authors.md`.
For the full-UI ladder (form engine, settings mappers, Rung 3), see
`full-ui-adapters.md`.

## How to read the matrix

| Cell | Meaning |
|---|---|
| **Y** | Wired and useful for daily ops |
| **—** | Not applicable (plugin has no such concept, or a thin shim is enough) |
| **L** | Deliberate deep link to wp-admin / vendor UI (canvas, OAuth, condition builder) |
| **·** | Possible Axis A gap: Minn has the primitive; this adapter does not use it yet |

Primitives (columns):

| Col | Surface primitive |
|---|---|
| **list** | `collection` (paginated list + columns) |
| **tabs** | Status/filter tabs or search on the list |
| **bulk** | Multi-select bulk actions |
| **detail** | Detail modal / `sectionsRoute` |
| **manage** | Second collection (`manage`) |
| **status** | Status card (`status.route`) |
| **chart** | Status-card chart series |
| **settings** | Surface `settings` (or settings-only) |
| **views** | Extra list views (`views[]`) |
| **suite** | Playwright suite under `tests/` |

Do **not** treat missing cells as automatic work. Compare each provider to its
**family reference** first. A thin log shim that matches siblings is done.

## Families (surface adapters)

Seeded 2026-07-12 from adapter sources + recent expansion rounds. Re-check
on each sweep; update cells and `Reviewed` when inventory finds drift.

### mail

Reference depth: **Gravity SMTP**. Family doc: `mail-plugins.md`.

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| gravity-smtp | Y | Y | · | Y | — | Y | Y | Y | Y | Y | 2026-07-12 | Settings, Suppressions, Debug log, Routing (toggle/delete), Filtered tab; condition authoring = **L** |
| fluent-smtp | Y | · | · | Y | — | Y | Y | · | · | Y | 2026-07-12 | Status + chart; no settings mapper |
| post-smtp | Y | · | · | Y | — | · | · | · | · | Y | 2026-07-12 | Log shim; transcript not exposed |
| wp-mail-smtp | Y | · | · | Y | — | · | · | · | · | Y | 2026-07-12 | Free = debug events only; full log is Pro |
| wp-mail-logging | Y | · | Y | Y | — | · | · | · | · | Y | 2026-07-12 | Log-only; bulk delete |

### forms

Reference depth: **Gravity Forms**.

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| gravity-forms | Y | Y | Y | Y | Y | · | · | Y | Y | Y | 2026-07-12 | Form settings (item), Notifications view; form builder = **L** |
| fluent-forms | Y | Y | · | Y | Y | · | · | · | · | · | | |
| elementor-forms | Y | Y | · | Y | · | · | · | · | · | · | | Elementor canvas = **L** |
| cf7-flamingo | Y | Y | · | Y | Y | · | · | · | · | Y | | CF7 builder = **L** |
| cfdb7 | Y | Y | · | Y | · | · | · | · | · | Y | | |
| ninja-forms | Y | Y | · | Y | Y | · | · | · | · | Y | | |
| forminator | Y | · | · | Y | Y | · | · | · | · | Y | | |
| formidable | Y | · | · | Y | Y | · | · | · | · | Y | | |
| everest-forms | Y | Y | · | Y | Y | · | · | · | · | · | | |

### backups

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| updraftplus | Y | — | — | — | — | Y | — | — | — | Y | | Backup-now + System health; restore = **L** |
| wpvivid | Y | — | — | Y | — | Y | — | — | — | Y | | Schedule + backup-now; restore = **L** |
| duplicator | Y | — | — | — | — | Y | — | — | — | Y | | Package list; no freshness claim |
| disembark | Y | — | — | — | — | Y | — | — | — | Y | | Connector; CLI command + sessions; no freshness |
| backwpup | Y | — | — | Y | — | Y | — | — | — | Y | 2026-07-12 | Local FOLDER archives; run-job-now; remote dests = **L** |
| ai1wm | Y | — | — | Y | — | Y | — | — | — | Y | 2026-07-12 | .wpress list; export/import wizards = **L**; no freshness |

### activity-log

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| simple-history | Y | Y | — | Y | — | · | — | — | — | · | | Real REST |
| wp-activity-log | Y | Y | — | Y | — | · | — | — | — | · | | Never unserialize meta |
| aryo-activity-log | Y | Y | — | Y | — | · | — | — | — | · | | Local epoch trap |
| stream | Y | Y | — | Y | — | · | — | — | — | · | | |
| wordfence | Y | Y | — | Y | — | Y | — | — | — | Y | | Login log + posture rows |
| limit-login-attempts | Y | · | — | Y | — | Y | — | — | — | Y | | Unlock action |
| solid-security | Y | · | — | Y | — | Y | — | — | — | Y | | Release + posture |

### redirects / snippets

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| redirection | Y | · | Y | Y | — | · | — | Y | — | · | | Setup gate + options |
| safe-redirect-manager | Y | · | Y | Y | — | · | — | · | — | · | | |
| simple-301-redirects | Y | · | Y | Y | — | · | — | · | — | · | | |
| eps-301-redirects | Y | · | Y | Y | — | · | — | · | — | Y | | No leading slash on source |
| code-snippets | Y | · | Y | Y | — | · | — | · | — | Y | | |
| wpcode | Y | Y | Y | Y | — | · | — | · | — | Y | | |
| fluent-snippets | Y | Y | Y | Y | — | · | — | · | — | Y | | |
| custom-css-js | Y | Y | Y | Y | — | · | — | · | — | Y | 2026-07-12 | CPT shim; tree rebuild on write |
| hfcm | Y | Y | Y | Y | — | · | — | · | — | Y | 2026-07-12 | hfcm_scripts; page targeting = **L** |

### settings-only / other surfaces

| Adapter | Shape | suite | Reviewed | Notes |
|---|---|---|---|---|
| perfmatters | settings-only (core Settings API) | Y | 2026-07-11 | Reference for Settings-API mappers |
| licenses | Extensions → Licenses (filter providers) | Y | | Use license-manager skill loop for new vendors |
| spam | Settings → Spam cards | Y | | |
| seo | Editor panel (providers) | Y | | |
| cache-purge | ⌘K purgers (+ Redis System row) | Y | 2026-07-12 | SpeedyCache, Redis, Breeze, Nginx Helper, Cloudflare pack |
| site-kit / koko / … | Traffic providers | partial | | Overview chart only |
| query-monitor | panel (not a surface) | — | 2026-07-13 | Footer arm + launcher chip; this-request only |
| scrutoscope | Profiler surface (list + detail + status + Cron view) | Y | 2026-07-13 | Profiles via Storage list; detail via rest_do_request `/profile/{id}`; capture UI = **L** |
| wp-crontrol | Cron surface (list + detail + status; run/pause/resume/delete) | Y | 2026-07-13 | Via `Crontrol\Event\*`; add/edit PHP/URL jobs = **L** |
| **transients-manager** | **not shipped** | — | 2026-07-13 | Candidate: list/delete; System has expired count only |

## Deliberate deep-links (do not re-propose as Axis B)

- Form / page / condition **builders** and OAuth handshakes
- Backup **restores** (surgery, not daily ops)
- GSMTP **routing condition tree** authoring (list/toggle/delete is wired)
- GF **notification** events / conditional logic / routing rules (daily fields are wired)
- Pro-only stores without fixtures (WP Mail SMTP Pro full log, WPForms Pro entries)

## Sweep log

| Date | Scope | Outcome |
|---|---|---|
| 2026-07-12 | Skill + this matrix seeded | Report-first runbook added to `dev-minn-admin`; no auto-ship |
| 2026-07-13 | v0.14.0 open / Dev tools review | QM classified panel-only; Scrutoscope + WP Crontrol + Transients Manager ranked in plugin-support Wave A; no ship |
| 2026-07-13 | Scrutoscope adapter | Profiler surface shipped (profiles, detail, status, Cron view, delete); suite 22 checks |
| 2026-07-13 | WP Crontrol adapter | Cron surface shipped (list, run-now, pause/resume, delete, status); suite 20 checks |

When a sweep updates cells or ships work, append a row and set `Reviewed` on
touched adapters (or stamp `// last-sweep: YYYY-MM-DD` in the adapter header).
