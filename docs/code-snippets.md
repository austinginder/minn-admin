# Code-snippet plugins — source audit

Audited 2026-07-09 against live installs of the free plugins on a disposable
local WordPress site with minn-admin active. Goal: rank candidates for a Minn
surface (same pattern as audit-log adapters).

## Plugins audited

| Plugin | Slug | Version | Status during audit |
|---|---|---|---|
| Code Snippets | `code-snippets` | 3.9.6 | Active |
| WPCode | `insert-headers-and-footers` | 2.3.7 | Active |
| FluentSnippets | `easy-code-manager` | 10.55 | Active |
| Header Footer Code Manager | `header-footer-code-manager` | 1.1.45 | Active |
| Woody | `insert-php` | 2.7.5 | **Install OK; activate fatals** (`add_capabilities()` on null in `class.plugin.php:168` under that WP/CLI stack) |

Fixtures created: Code Snippets #5/#6, WPCode post 82, Fluent `1-minn-audit-fluent.php`, HFCM row 1.

---

## Ranked for Minn adapters

### 1. Code Snippets — **best first adapter**

| Concern | Finding |
|---|---|
| Storage | Custom table `{prefix}snippets` (and `{base}ms_snippets` on multisite). Columns: `id, name, description, code, tags, scope, condition_id, priority, active, modified, revision, cloud_id`. |
| Caps | Filterable `code_snippets_cap` (default **`manage_options`**); network cap `manage_network_options`. |
| REST | **First-class.** Namespace `code-snippets/v1`. |
| Routes | `GET/POST /snippets`, `GET/PUT/DELETE /snippets/{id}`, `POST …/activate`, `POST …/deactivate`, `GET …/export`, `GET …/export-code`, schema, plus importers. |
| Pagination | `page` / `per_page` + `X-WP-Total` / `X-WP-TotalPages`. |
| List shape | `{ id, name, desc, code, tags[], scope, condition_id, active, priority, network, shared_network, modified, code_error }` |
| Toggle | `PUT /snippets/{id}` with `{ "active": true\|false }` works cleanly (200). Dedicated activate/deactivate routes exist; prefer PUT for predictable JSON. |
| Scopes | At least `global`, `front-end`, `content` (sample data). |
| Admin deep link | Snippets admin menu under `snippets` (manage + edit screens). |
| Safe mode | Query-var safe mode documented for white-screen recovery. |

**Minn surface shape:** pure REST, cookie + nonce. List columns: name, scope, active toggle, tags, modified. Detail: code (read-only first) + meta + "Edit in Code Snippets ↗". Actions: activate/deactivate/trash. No shim required for v1.

Also interesting: it already has **importers** for WPCode, HFCM, and Insert PHP Code Snippet — so migrating users can land in Code Snippets and Minn only needs one surface.

---

### 2. WPCode — **must-have for reach (3M+), needs a shim**

| Concern | Finding |
|---|---|
| Storage | CPT **`wpcode`** (`public=false`, `show_ui=false`, **`show_in_rest=false`**). Code in `post_content`. Active = `post_status` **publish** vs **draft**. |
| Taxonomies | `wpcode_type` (php/js/css/html/text…), `wpcode_location` (everywhere, header, after_paragraph…), `wpcode_tags`. |
| Meta (prefix `_wpcode_`) | `_wpcode_auto_insert`, `_wpcode_auto_insert_number`, `_wpcode_conditional_logic(_enabled)`, `_wpcode_priority`, `_wpcode_note`, `_wpcode_device_type`, `_wpcode_custom_shortcode`, `_wpcode_last_error`, library/cloud fields, etc. |
| Caps | Custom: `wpcode_edit_snippets`, `wpcode_activate_snippets`, plus type-scoped (`wpcode_edit_php_snippets`, …) mapped via `map_meta_cap`. Admins get them. |
| REST | **None** for snippets. No `wp/v2/wpcode`. Abilities API hooks exist (WP 6.9+) but are not a full CRUD surface. |
| Write path | `WPCode_Snippet` class: `save()`, `activate()`, `deactivate()`. Admin toggle is AJAX `wp_ajax_wpcode_update_snippet_status`. |
| Admin deep link | `admin.php?page=wpcode-snippet-manager&snippet_id={id}` · list `admin.php?page=wpcode`. |

**Minn surface shape:** bundled shim `minn-admin/v1/wpcode/snippets` (list + status toggle) calling `WPCode_Snippet` / `get_posts`, gated on `wpcode_edit_snippets` / `wpcode_activate_snippets`. Same pattern as Stream/Aryo audit shims. Do **not** enable `show_in_rest` on their CPT from Minn.

---

### 3. FluentSnippets — **strong #3 (REST + free/OSS)**

| Concern | Finding |
|---|---|
| Storage | **Flat files** under `WP_CONTENT_DIR/fluent-snippet-storage/` (override: `FLUENT_SNIPPETS_STORAGE_DIR`). Index cache `index.php`. Filenames like `1-minn-audit-fluent.php`. |
| Caps | REST permission = **`install_plugins`** (aggressive; note for gating). |
| REST | Namespace **`fluent-snippets`** (no version). |
| Routes | `GET snippets`, `POST snippets/create`, `POST snippets/update`, `POST snippets/update_status`, `POST snippets/delete_snippet`, `GET snippets/find`, settings routes. |
| List shape | `{ snippets: { data: [...], page, per_page, total, last_page }, tags, groups, time }`. Item: name, description, type, status (`draft`/`published`), tags, run_at, priority, file_name, condition… |
| Toggle | `POST …/update_status` with `fluent_saving_snippet_name` + `status` (`published` / `draft`). Verified 200. |
| Types | PHP, Content (PHP+HTML), CSS, JS. |

**Caveats:** create payload is picky (nested `condition` objects can TypeError in their sanitizer if shaped wrong). No classic integer IDs — key is `file_name`. Cap `install_plugins` is broader than ideal for a read-only list surface; shim could re-check `manage_options` if Minn prefers.

---

### 4. HFCM — **narrower product (scripts, not PHP functions)**

| Concern | Finding |
|---|---|
| Storage | Table `{prefix}hfcm_scripts` — `script_id, name, snippet, snippet_type (html/js/css), device_type, location, display_on, status (active/inactive), …`. **No PHP type.** |
| Caps | `manage_options` on menus and mutations. |
| REST | **None.** Admin + `wp_ajax_hfcm-request`. |
| Use case | Header/footer/content injection of tracking/CSS/JS — not `functions.php` replacement. |

Worth a shim only if Minn wants "all script managers," not just "code snippets." Overlaps WPCode's header/footer heritage.

---

### 5. Woody — **deprioritize for now**

- Snippets are a **CPT** (`WINP_SNIPPETS_POST_TYPE`) with rich postmeta (`wbcr_inp_*`).
- REST namespace `woody/v1` only exposes **license + settings**, not snippet CRUD.
- Free activate fatals on this lab (`add_capabilities()` on null) — treat as fragile until fixed upstream.
- Themeisle acquisition; product still in flux.

---

## Shared Minn surface sketch (audit-log style)

One nav item **"Snippets"** when any supported plugin is active (first match wins, or tabs if multiple — same convention as Redirects / Activity Log: dogfood one active).

| Column | Code Snippets | WPCode | Fluent |
|---|---|---|---|
| Title | `name` | `post_title` | `name` |
| Type/scope | `scope` | `wpcode_type` + location | `type` + `run_at` |
| Status | `active` bool | publish/draft | published/draft |
| Tags | `tags[]` | taxonomy | string |
| Modified | `modified` | `post_modified` | `updated_at` |
| Toggle | PUT active | shim → activate/deactivate | update_status |
| Deep link | CS edit screen | `wpcode-snippet-manager&snippet_id=` | Fluent admin SPA |

**v1 scope recommendation:** list + toggle + deep-link edit. In-Minn code editor is a later rung (security + validation + fatal recovery). PHP execution risk means Minn should never evaluate snippet code itself — only the host plugin should.

---

## Shipped adapters (same “Snippets” nav label, plugin badge as `sub`)

Same convention as Redirects / Activity Log: each plugin registers its own surface
id; when several are active the sidebar shows multiple Snippets entries with
different badges (Code Snippets / WPCode / FluentSnippets). Dogfood one at a time
on production sites if you want a single nav item.

1. ~~**Code Snippets**~~ — **SHIPPED** (`adapters/code-snippets.php`, pure
   `code-snippets/v1`). Suite: `tests/code-snippets.test.js`.
2. ~~**WPCode**~~ — **SHIPPED** (`adapters/wpcode.php`, shim
   `minn-admin/v1/wpcode/snippets` over `WPCode_Snippet`). List/create/edit/toggle/delete.
3. ~~**FluentSnippets**~~ — **SHIPPED** (`adapters/fluent-snippets.php`, shim
   `minn-admin/v1/fluent-snippets` normalizing file_name → `id` and draft/published →
   `active`). Suite: `tests/snippets-adapters.test.js` covers both shims.
4. **HFCM / Woody** — not yet. HFCM is scripts-only (html/js/css); Woody activate
   fatals on current lab.

Shared UX: name, scope column, active pill, priority, modified; wide detail form
(name/desc/code/scope or type·location/priority/tags); Activate/Deactivate; Delete;
Edit ↗ into the plugin’s own admin.

## Lab housekeeping

Woody was left inactive after the audit (activate fatals). Tear down the
disposable lab when snippet work is done.
