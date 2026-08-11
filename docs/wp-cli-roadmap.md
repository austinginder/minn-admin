# WP-CLI roadmap: make Minn's operational model scriptable

*Audit completed 2026-08-11 against Minn Admin v0.27.0. This is a future-cycle
plan, not a commitment for the current development cycle.*

Minn should add a focused `wp minn` namespace. It should expose the parts of
Minn that are genuinely different from core WordPress: cross-provider license
state, integration-contract diagnostics, the System health model, provider-aware
cache clearing and site-visibility state. It should not recreate the SPA at a
shell prompt.

The decision test is simple. A Minn command earns its place when it does at
least one of these things:

1. Normalizes several plugins behind one stable command.
2. Exposes a Minn-owned diagnostic or safety judgment to automation.
3. Gives integration authors feedback they cannot get from core WP-CLI.
4. Produces machine-readable state that is useful across many sites.

If core WP-CLI or a vendor command already owns the job, Minn should stay out
of the way.

## Audit snapshot

The primary development fixture currently resolves 32 surfaces, 6 editor
panels, 3 design sources, 43 detected license providers and 50 license or
connection rows. Its integration validator reports no contract problems. The
numbers will change with installed plugins, but they demonstrate that the PHP
registries are already substantial enough to justify a command-line view.

| Area | What is distinctive in Minn | CLI verdict |
|---|---|---|
| Licenses and connections | One local-state inventory across vendor SDKs and dedicated adapters, with normalized valid, expired, invalid, missing and unknown states | Build first. This is Minn's clearest unique command-line value |
| Plugin surfaces | Declarative surfaces, editor panels, design sources, providers, owner attribution and contract validation | Build first as inspection and validation. Do not build a generic surface executor |
| System | Minn combines core health, database weight, cron, backups, visibility, security, mail, cache and license posture | Build first as `doctor`, with stable check IDs and exit codes |
| Cache | One registry clears page cache, CDN, object cache and generated CSS across many providers | Build in the second wave. Core `wp cache flush` only covers the object cache |
| Visibility | One detector and writer registry understands maintenance, coming-soon, password and partial-store modes across plugins | Build in the second wave. It is useful for deploy checks and incident response |
| Editor platform | Safe writing, block islands, builder fencing, block descriptors and render probes | Add diagnostics only when useful. Do not edit content through a Minn command |
| Backups | Several providers appear in one family, but their start, status and restore models still differ | Report freshness through `doctor`. Wait for a first-class backup provider contract before adding a generic runner |
| Notices | Extraction depends on a real wp-admin page load as the current user | Skip. A CLI version would not observe the same notice-registration paths |
| Content, users, media, terms and updates | Minn provides a calmer UI over mostly core operations | Skip broad parity. Core WP-CLI already owns these jobs |

## Recommended command tree

### Wave 1: read-only foundations

#### `wp minn doctor`

Print Minn's health checks as a table and make them usable in deploy scripts.

```bash
wp minn doctor
wp minn doctor --strict
wp minn doctor --status=warn,fail --format=json
wp minn doctor --http
```

Default fields: `id`, `label`, `status`, `detail`, `source`. Every check needs a
stable machine ID. Human labels are not an API.

- Exit `0` when there are no failing checks.
- Exit `1` when any check fails.
- `--strict` also exits `1` on warnings.
- `--http` adds the slower loopback and public REST probes. The current web
  System check is cookie-aware and deliberately skips those rows under CLI, so
  the CLI probes need their own honest implementation.
- Local checks must not trigger updates, backups, scans or vendor network calls.

This command should include the checks already assembled for System, including
backup freshness, visibility, license state, security posture, mail health,
database hygiene, cron and cache posture. It should not print the full extension
manifest unless a later `--include=manifest` use case proves useful.

#### `wp minn license list`

Expose the existing normalized license and connection inventory without ever
showing a secret.

```bash
wp minn license list
wp minn license list --state=expired,invalid,missing
wp minn license list --category=license --format=json
wp minn license list --fields=provider,name,state,expires,active,note
```

Stable fields: `provider`, `name`, `kind`, `category`, `state`, `expires`,
`stale`, `active`, `has_key`, `actions`, `note`. `has_key` is boolean only.
Raw keys, masked fragments and vendor tokens never reach stdout, stderr or an
exception message.

This is an inventory command, not a second copy of `wp plugin list`. Its useful
question is: "Can every paid component on this site still receive updates?"

#### `wp minn integration list`

List everything connected to Minn's extension contracts.

```bash
wp minn integration list
wp minn integration list --type=surface
wp minn integration list --owner="My Plugin" --format=json
```

Types should cover surfaces, editor panels, design sources, cache purgers, spam
providers, license providers, page builders, block forms and listener hooks.
Stable fields: `type`, `id`, `label`, `owner`, `family`, `cap`, `problems`,
`notes`.

#### `wp minn integration validate`

Turn the existing System Integrations card into an author and CI tool.

```bash
wp minn integration validate
wp minn integration validate my-plugin-surface
wp minn integration validate --third-party --format=json
```

- Exit `0` when descriptors are valid.
- Exit `1` when contract problems exist.
- Report unknown keys, malformed fields, invalid view or settings shapes and
  missing routes. Keep attention-budget collapses as informational notes because
  Minn already handles them safely.
- Keep off-site link disclosures informational unless a future policy makes one
  invalid.
- Validate the raw registry. This command must not hide a bad descriptor because
  the current user lacks its capability.

This command directly serves the roadmap goal of getting external plugin authors
to ship their own integrations.

### Wave 2: narrow provider actions

#### `wp minn cache list` and `wp minn cache purge`

```bash
wp minn cache list
wp minn cache purge breeze
wp minn cache purge --all
```

Re-use `minn_admin_cache_purgers()`. Require either one provider ID or the
explicit `--all` flag. Continue after one provider fails, print one result row
per provider, and exit nonzero when any provider fails. The command must preserve
the current Throwable isolation because cache purges can recycle the PHP worker.

#### `wp minn visibility status`

```bash
wp minn visibility status --format=json
wp minn visibility off seedprod --yes
wp minn visibility on seedprod --yes
```

Status is read-only and reports `public`, `hidden`, `partial`, `password` or
`search-discouraged`, plus the responsible providers. Mutations use the existing
writer registry and require `--yes` because they change what visitors can see.
The `on` command must use Minn's stored restore context so two-mode providers
return to the exact mode that was disabled.

#### `wp minn license verify <provider>`

Run one provider's existing `verify` callable, then print its fresh rows. Never
add a bulk "verify everything" command by default. Vendor checks can be slow,
rate-limited or side-effectful in their update caches.

Activation and deactivation stay out of this wave. A key passed as a positional
argument or `--key=` leaks into shell history and process listings. If CLI
activation is ever added, it must read secrets from stdin or an explicit file,
never echo them, never retry a failure and preserve every provider's current
snapshot-and-restore guarantees. Deactivation also needs provider-specific
seat-release language and an explicit confirmation.

#### `wp minn integration describe <id>`

Print one sanitized descriptor for debugging. Replace callables with their
source owner or a boolean marker. Never serialize closures or expose captured
state. This is useful when an integration renders differently from what its
author expected, but it is less important than validation.

### Wave 3: editor diagnostics, only if support demand proves them

The editor is Minn's most distinctive feature, but it is not the best place to
start the CLI. Core already has `wp post create`, `wp post update`, block parsing
helpers and revision commands. A Minn content-write command would bypass the
browser serializer, post-lock flow, local recovery net and real contenteditable
behavior that make the editor safe.

Two read-only diagnostics may eventually earn a place:

#### `wp minn block inspect <namespace/block>`

Report the registered title, dynamic/static shape, PHP attribute schema,
parent/ancestor constraints, Minn block-form descriptor, bare-comment render
probe and auto-insert eligibility. Most importantly, state the reason a block is
not auto-insertable. This would turn the editor's compatibility knowledge into a
useful tool for block authors.

#### `wp minn editor inspect <post-id>`

Report facts, not a simulated editor:

- detected builder and whether it owns the content canvas;
- registered and unregistered block names in the post;
- applicable Minn editor panels;
- current core edit lock and newer autosave state;
- links to the Minn editor and the builder or block editor escape.

Do not claim an exact `classic`, `blocks` or `locked` prediction unless the
client and server share one compatibility implementation. Rewriting the
JavaScript tokenizer in PHP would create two safety models that can drift.

## Explicit non-goals

- No `wp minn surface run`. Surface descriptors are a UI contract, not a stable
  automation protocol. Actions accept provider-specific bodies, conditional
  fields and per-user capabilities.
- No editor create, save, find-and-replace or block mutation commands. Use core
  WP-CLI for stored content and Minn's browser for Minn's editing guarantees.
- No generic database query or file-manager commands. Minn's database viewer is
  deliberately read-only, and core WP-CLI already has purpose-built database
  commands.
- No update, plugin, theme, user, media or term command mirrors unless Minn adds
  a genuinely cross-provider judgment core lacks.
- No implicit multisite fleet loop. Commands operate on the site selected by
  `--url`; fleet orchestration belongs to the caller.
- No hidden dependency on `--user=admin`. Raw inventory and validation should
  work in the normal privileged CLI context. A future command that answers what
  a particular user can see must require `--user` explicitly.

## Architecture

1. Add `includes/class-minn-admin-cli.php`, load it only when
   `defined( 'WP_CLI' ) && WP_CLI`, and register the `wp minn` namespace plus
   explicit command classes for `doctor`, `license`, `integration`, `cache` and
   `visibility`.
2. Do not make HTTP requests to Minn's own REST API. REST and CLI should be two
   adapters over the same PHP domain functions.
3. Re-use the registries that are already clean domain functions:
   `minn_admin_licenses()`, `Minn_Admin_Surfaces::integrations()`,
   `minn_admin_site_visibility()` and `minn_admin_cache_purgers()`.
4. Extract System check assembly from `Minn_Admin_REST::system_info()` into a
   service that accepts a `web` or `cli` context. Keep response formatting in
   the REST class and terminal formatting in the CLI class.
5. Extract license and visibility action runners from their REST closures before
   exposing actions. Their validation, memory, update-cache and restore behavior
   must have one implementation.
6. Use `WP_CLI\Utils\format_items()` for `table`, `json`, `csv`, `yaml`, `ids`
   and `count` output. Support the familiar `--fields` and `--format` options.
7. Use stable IDs and raw values in machine formats. Humanized labels, relative
   times and colored status words belong only in the default table output.
8. Test both normal CLI and admin context where vendor boot behavior differs.
   An adapter should bootstrap the small vendor files it needs, as the license
   manager already does, instead of making users remember `--context=admin`.

## Verification plan

Add `tests/cli.test.js` as a plain Node suite that shells out to the local
`wp` binary. It does not need Chrome or `MINN_TEST_PASS`.

The first wave is complete when the suite proves:

1. Every command appears under `wp help minn` with examples and a documented
   synopsis.
2. Table and JSON output contain the same stable rows and honor `--fields`.
3. `doctor` exit codes distinguish pass, fail and strict-warning cases using
   fixture checks.
4. License output contains fixture rows and never contains the fixture secret.
5. A malformed fixture surface makes `integration validate` fail, while a
   legitimate third-party surface remains accepted.
6. Provider exceptions become one failed row instead of a fatal command.
7. Commands target only the site selected by `--url` on the multisite lab.
8. Mutating second-wave commands require their explicit target or confirmation,
   preserve fixture state and remain idempotent where the provider permits it.

Do not assert absolute provider counts. The development site's plugin roster is
intentionally fluid. Seed the fixture row each test needs and restore its state.

## Recommended scheduling

Treat this as one focused future cycle with a stop point after Wave 1. The first
four commands are coherent, read-only and high-leverage. They also force the
right refactor: Minn's System and integration judgments become domain services
instead of REST-only behavior.

Only schedule Wave 2 after those commands are used in at least one real deploy or
fleet audit. Schedule the editor diagnostics separately, after a concrete support
case identifies which compatibility facts would have shortened the investigation.

The best first slice is:

1. `wp minn doctor`
2. `wp minn license list`
3. `wp minn integration list`
4. `wp minn integration validate`

That slice captures what makes Minn unusual without committing the project to a
second interface for every button in the app.
