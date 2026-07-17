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
| gravity-smtp | Y | Y | Y | Y | — | Y | Y | Y | Y | Y | 2026-07-15 | Settings, Suppressions, Debug log, Routing (toggle/delete), Filtered tab; **single + bulk log delete** via Event_Model; condition authoring = **L** |
| fluent-smtp | Y | Y | Y | Y | — | Y | Y | · | · | Y | 2026-07-14 | Status + chart + sent/failed tabs; **search** + single/bulk **delete** via Logger |
| post-smtp | Y | Y | Y | Y | — | Y | Y | · | · | Y | 2026-07-14 | Status + chart + tabs; resend; **search** + single/bulk **delete**; transcript not exposed |
| wp-mail-smtp | Y | Y | · | Y | — | · | · | · | · | Y | 2026-07-14 | Free = debug events only; full log is Pro |
| wp-mail-logging | Y | Y | Y | Y | — | Y | Y | · | · | Y | 2026-07-14 | Log-only; bulk delete; status + chart; close to reference for a pure log |

### forms

Reference depth: **Gravity Forms**.

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| gravity-forms | Y | Y | Y | Y | Y | · | · | Y | Y | Y | 2026-07-14 | Form settings (item), Notifications view; form builder = **L**; status/chart not needed for GF depth |
| fluent-forms | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-14 | Unread/spam/trash tabs + bulk; suite `fluent-forms` (24); active fixture |
| elementor-forms | Y | Y | · | Y | · | · | · | · | · | · | 2026-07-14 | Elementor canvas = **L** |
| cf7-flamingo | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-15 | CF7 builder = **L**; bulk restore/delete already wired (matrix was stale) |
| cfdb7 | Y | Y | Y | Y | · | · | · | · | · | Y | 2026-07-15 | bulk delete already wired (matrix was stale) |
| ninja-forms | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-15 | bulk trash/delete already wired (matrix was stale) |
| forminator | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-14 | Tabs + bulk + search present (matrix was stale) |
| formidable | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-14 | Tabs + bulk + search present (matrix was stale) |
| everest-forms | Y | Y | Y | Y | Y | · | · | · | · | Y | 2026-07-14 | Received/Spam/Trash + bulk; suite `everest-forms` |

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
| simple-history | Y | Y | — | Y | — | Y | — | — | — | Y | 2026-07-15 | Real REST list; **status** card (24h/7d/total/severity) |
| wp-activity-log | Y | Y | — | Y | — | Y | — | — | — | Y | 2026-07-15 | Never unserialize meta; **status** card (24h/7d/high+critical) |
| aryo-activity-log | Y | Y | — | Y | — | Y | — | — | — | Y | 2026-07-15 | Local epoch trap; **status** card (24h/7d/top action) |
| stream | Y | Y | — | Y | — | Y | — | — | — | Y | 2026-07-15 | **status** card (24h/7d/top connector); blog_id scoped |
| wordfence | Y | Y | — | Y | — | Y | — | — | — | Y | 2026-07-14 | Login log + posture rows; inactive fixture (family) |
| limit-login-attempts | Y | · | — | Y | — | Y | — | — | — | Y | 2026-07-14 | Unlock action; active resident |
| solid-security | Y | · | — | Y | — | Y | — | — | — | Y | 2026-07-14 | Release + posture; **active** on minnadmin now (was inactive convention) |

### redirects / snippets

| Adapter | list | tabs | bulk | detail | manage | status | chart | settings | views | suite | Reviewed | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| redirection | Y | · | Y | Y | — | Y | Y | Y | — | Y | 2026-07-17 | Setup gate + options; **status card** (rules, hits, served/404 7d, dual 14-day chart); suite `redirection-status` |
| safe-redirect-manager | Y | · | Y | Y | — | · | — | · | — | · | | |
| simple-301-redirects | Y | · | Y | Y | — | · | — | · | — | · | | |
| eps-301-redirects | Y | · | Y | Y | — | · | — | · | — | Y | | No leading slash on source |
| code-snippets | Y | · | Y | Y | — | Y | — | · | — | Y | 2026-07-17 | **Status card** (active/inactive/trashed, running scopes, last change, safe-mode warning); first card in the family |
| wpcode | Y | Y | Y | Y | — | · | — | · | — | Y | | |
| fluent-snippets | Y | Y | Y | Y | — | · | — | · | — | Y | | |
| custom-css-js | Y | Y | Y | Y | — | · | — | · | — | Y | 2026-07-12 | CPT shim; tree rebuild on write |
| hfcm | Y | Y | Y | Y | — | · | — | · | — | Y | 2026-07-12 | hfcm_scripts; page targeting = **L** |

### settings-only / other surfaces

| Adapter | Shape | suite | Reviewed | Notes |
|---|---|---|---|---|
| perfmatters | settings-only (core Settings API); family `performance` | Y | 2026-07-14 | Reference for Settings-API mappers |
| autoptimize | settings-only (curated options); family `performance` | Y | 2026-07-14 | Toggle store `on`/empty; purge still in cache-purge |
| asset-cleanup | settings-only (JSON option); family `performance` | Y | 2026-07-14 | Page-level unload manager deep-linked |
| performance-lab | features list + status + activate/deactivate; family `performance` | Y | 2026-07-14 | Hub for WP Performance Team standalone plugins |
| licenses | Extensions → Licenses (filter providers) | Y | | Use license-manager skill loop for new vendors |
| spam | Settings → Spam cards | Y | | |
| seo | Editor panel (providers) | Y | | |
| meta-box | Editor panel (simple fields) | Y | 2026-07-14 | Clone/media = locked + deep link |
| pods | Editor panel (simple fields on extended types) | Y | 2026-07-14 | Advanced types locked + deep link |
| safe-svg | Media SVG filter + badge | Y | 2026-07-14 | Sanitization stays Safe SVG's job |
| cache-purge | ⌘K purgers (+ Redis System row) | Y | 2026-07-12 | SpeedyCache, Redis, Breeze, Nginx Helper, Cloudflare pack |
| site-kit / koko / burst / ia / … | Traffic providers (+ day drill) | partial | 2026-07-14 | Overview chart + traffic_day for Koko, WP Statistics, Burst, Independent Analytics |
| query-monitor | panel (not a surface) | — | 2026-07-13 | Footer arm + launcher chip; this-request only |
| scrutoscope | Diagnostics family (list + detail + status + Cron view) | Y | 2026-07-13 | family `diagnostics`; capture UI = **L** |
| wp-crontrol | Diagnostics family (list + detail + status; run/pause/resume/delete) | Y | 2026-07-13 | family `diagnostics`; add/edit PHP/URL jobs = **L** |
| transients-manager | Diagnostics family (list + detail + status; delete + delete-expired) | Y | 2026-07-13 | family `diagnostics`; never unserialize; edit UI = **L** |
| rewrite-rules-inspector | Diagnostics family (list + detail + status; flush + test URL) | Y | 2026-07-13 | family `diagnostics`; via their RewriteRules / UrlTester; hard .htaccess flush not offered |

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
| 2026-07-13 | Diagnostics family + Transients | Scrutoscope + Crontrol + Transients Manager collapse to one Tools item; TM suite 17 |
| 2026-07-13 | Rewrite Rules Inspector | Diagnostics → Rewrites (list, flush, test URL); suite 15 |
| 2026-07-14 | Focused report: mail → forms → activity-log | Ranked backlog below; **no ship** (report-first). Mail reference = Gravity SMTP; fixtures: FluentSMTP + WP Mail Logging + Gravity SMTP active |
| 2026-07-14 | Ship top 1: fluent-smtp search + delete/bulk | Axis A parity with GSMTP/WPML; suite mail-log 17 checks |
| 2026-07-14 | Full report-only re-sweep (mail → forms → activity-log → redirects/snippets → backups; fixtures inventory) | Matrix cells corrected (forminator/formidable/everest/fluent-forms); **no ship**. Top ship = post-smtp search + delete/bulk |
| 2026-07-14 | Ship: post-smtp search + single/bulk delete | Axis A mail parity with WPML/FluentSMTP; mail-log suite extended |
| 2026-07-14 | Ship: fluent-forms Playwright suite | Axis A coverage gap closed; 24 checks (list/tabs/filters/search/detail/trash/delete/manage); seeder `minn_test_seed_fluent_forms` |
| 2026-07-15 | Axis A: gravity-smtp bulk log delete | Single + bulk Delete via Event_Model (DELETE_EMAIL_LOG); mail-log suite extended; matrix fixed for cf7/cfdb7/ninja bulk (were already Y in code) |
| 2026-07-15 | Axis A: activity-log status cards | Simple History, WSAL, Stream, Aryo status cards (24h/7d/total + family-specific mix); suite `activity-log-status` |
| 2026-07-17 | Ship top 1: sectionsRoute row types | `pill`/`code`/sandboxed `html-preview`/`kv-table` rows in the sections detail renderer; contract-fixture Log view exercises all four with hostile payloads (suite detail-rows, 19 checks incl. sandbox + escaping proofs); Gravity SMTP log detail converted as the first consumer (status pill, HTML body preview, headers kv-table). Fluent/Post/WPML conversions are the natural next slices |
| 2026-07-17 | Ship next: code-snippets status card | Active/inactive/trashed counts, running scopes, last change and a safe-mode warning row from their own table (active -1 = trashed per their class-db docblock); suite code-snippets 26. Sweep-hygiene note: the report's snippets/redirects "family status parity" claims were grep over-matches; these two cards are family FIRSTS and the siblings are the real parity backlog |
| 2026-07-17 | Sortable surface columns (Austin's ask) | New collection primitive: column `sort` tokens + `sortQuery` template, clickable headers with the users-list direction convention (num/ago start desc); validator + author guide + contract fixture in lockstep (contract 39). Redirection wired first (Source/Hits/Last hit via their orderby vocabulary); suite redirection-status now 14 |
| 2026-07-17 | Ship top 1 (second slice): redirection status card | Rules/hits/served/404 rows + dual-series 14-day chart from Redirection's own log tables (SHOW TABLES-gated, site-local DATE buckets matching their log view); suite redirection-status (10). First status card in the redirects family |
| 2026-07-17 | v0.18.0-open report-only re-sweep (all 73 adapters, primitive grep + fixture states) | Waves resume after the v0.17.0 pause. Mail chart parity confirmed DONE (fluent-smtp, post-smtp, wp-mail-logging all carry `chart`). NEW Axis A finds: **redirection** (active resident) and **code-snippets** (active resident) are the only family surfaces without a `status` card. sectionsRoute row types still unbuilt (`pill` exists only as a column format). Fixture note: SearchWP DEACTIVATED (its profile_update loopbacks slowed every user save; license reads survive inactive). Wave B/D candidates (AIOS, GoSMTP/SureMails/Site Mailer, SureForms/MetForm, FileBird, Enable Media Replace) all uninstalled. **No ship.** |

### Ranked backlog (2026-07-17, v0.18.0 open)

| Rank | Adapter | Axis | Gap | Effort | Why now |
|---|---|---|---|---|---|
| ~~1~~ | ~~sectionsRoute row types (`pill`, `code`, `html-preview` sandboxed, `kv-table`)~~ | ~~A~~ | ~~Shared detail primitive~~ | ~~M~~ | **Shipped 2026-07-17** (suite detail-rows, 19; GSMTP log detail is the first consumer) |
| ~~2~~ | ~~redirection~~ | ~~A~~ | ~~`status` card~~ | ~~S~~ | **Shipped 2026-07-17** (rows + dual redirects/404s chart; suite redirection-status, 10) |
| ~~3~~ | ~~code-snippets~~ | ~~A~~ | ~~`status` card~~ | ~~S~~ | **Shipped 2026-07-17** (active/inactive/trashed, running scopes, last change, safe-mode row; suite code-snippets 26). CORRECTION: the 07-17 report's "siblings all have one" claim was a grep over-match on the word status; Redirection and Code Snippets are the FIRST cards in their families. Sibling cards (wpcode, fluent-snippets, custom-css-js, hfcm, SRM, S301, EPS) are now legitimate S-effort follow-ups |
| 4 | All-In-One Security (AIOS) | B | Activity-log + posture (LLA-R/Solid pattern) | M | Wave B leftover; **not installed** — install + source-verify first |
| 5 | GoSMTP / SureMails / Site Mailer | B | New mail-log providers | M | **Not installed**; source-verify free log storage first |
| 6 | Enable Media Replace + core media polish | B | Wave D opener (unattached/date filters, "attached to", replace-in-place) | M | Media is the least-covered daily surface |
| 7 | WPForms Pro entries | B | Forms family | M–L | Needs a Pro license for fixtures; biggest uncovered forms name |
| 8 | Media folders provider contract (FileBird first) | B | Wave D, browse-first | L | Needs a provider contract design |
| 9 | fluent-smtp | B | Settings mapper (connections) | M–L | GSMTP has settings; Fluent connection UI is canvas-ish |
| 10 | GF add-on/feed settings | B | Schema-export multiplier from full-ui-adapters | L | Only after the row types land |

### Ranked backlog (2026-07-15 Axis A pass, superseded)

| Rank | Adapter | Axis | Gap | Effort | Why now |
|---|---|---|---|---|---|
| ~~1~~ | ~~**gravity-smtp**~~ | ~~A~~ | ~~Bulk log delete~~ | ~~S~~ | **Shipped 2026-07-15** |
| ~~1~~ | ~~**simple-history / wsal / stream / aryo**~~ | ~~A~~ | ~~**status** cards~~ | ~~S–M~~ | **Shipped 2026-07-15** |
| 1 | sectionsRoute row types (`pill`, `code`, `html-preview`, `kv-table`) | A | Email-log detail fidelity | M | Shared primitive; benefits GSMTP/Fluent/Post detail |
| 3 | All-In-One Security (AIOS) | B | Activity-log + posture (LLA-R/Solid pattern) | M | Wave B leftover; **not installed** on minnadmin — install first |
| 4 | GoSMTP / SureMails / Site Mailer | B | New mail-log providers | M | **Not installed**; source-verify free log storage first |
| 5 | WPForms Pro entries | B | Forms family | M–L | Needs Pro license + fixtures; biggest missing forms name |
| 6 | fluent-smtp | B | Settings mapper (connections) | M | GSMTP has settings; Fluent connection UI is canvas-ish → likely **L** |

**Matrix fixes this sweep:** gravity-smtp bulk → **Y**; cf7-flamingo / cfdb7 / ninja-forms bulk → **Y** (code already had bulk; cells were stale).

**Deliberate non-goals:** GSMTP routing tree authoring; GF form builder / notifications events; backup restores; WP Mail SMTP Pro log without Pro; forminator "status card" (inbox plugins don't need GSMTP-style charts); ecommerce (shipped v0.14); re-proposing forminator/formidable tabs (already wired).

**Fixtures snapshot (minnadmin):** mail residents FluentSMTP + WP Mail Logging + Gravity SMTP active, Post SMTP + WP Mail SMTP inactive; forms pack active (GF not listed in grep but usually is, Fluent Forms, Ninja, Forminator, Formidable, Everest, Flamingo, CFDB7, Elementor Pro); activity-log WSAL + LLA-R + **Solid active**; redirects Redirection active, SRM/S301/EPS inactive; backups Updraft/WPvivid/Duplicator/Disembark/BackWPup active.

When a sweep updates cells or ships work, append a row and set `Reviewed` on
touched adapters (or stamp `// last-sweep: YYYY-MM-DD` in the adapter header).
