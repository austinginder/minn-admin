# The resource kernel: adapters as declarations

*Plan for v0.46 onward. Status: proposed 2026-10-14, nothing built yet.
Progress is measured, not described: `node bin/adapter-inventory.js` and its
history in `bin/adapter-inventory/history.json`, charted on the rollout
dashboard.*

## Why

Every adapter re-implements the same plumbing around a small core of vendor
knowledge, and the plumbing is where the security findings and the display
bugs come from.

- **The adapters are the biggest part of Minn.** 141 files and 86,450 lines at
  v0.45.0, against 26,406 lines of core PHP and 59,327 of `app.js`.
- **They grow faster than anything else.** 53,329 lines at v0.35.0
  (2026-08-25), 86,450 at v0.45.0 seven weeks later: up 62%. Hand-written REST
  routes went from 325 to 489.
- **One shape dominates.** 75 adapters (52,151 lines) are list, detail and
  actions screens over a vendor's data. Between them they hand-write 405 routes
  and 486 permission callbacks.
- **Each one repeats the same decisions.** Everest Forms is 902 lines. What
  only Everest Forms knows (its table, field list, status meanings, per-form
  access rule and status call) is about 300 of them. The rest is routes,
  parameters, paging, search, sorting, counts, date and entity handling, error
  shapes, and actions written twice, once for the row and once for the detail.
- **The findings follow the plumbing.** The v0.45.0 release candidate's 33
  findings were 13 integrity (a save changed what nobody touched), 11
  disclosure, 8 gate and 1 mechanical (path parameters read from the body).
  The 41 demo-site bugs were mostly times in the wrong zone, stored entities
  printed raw, raw status slugs and sort defaults. Each was the same mistake in
  another adapter, which is why fixing them one at a time never ends.

Guards catch these classes (the route-gates snapshot, value redaction, the write
fence, the round-trip suites), but every guard is more security code and more
tests. The kernel removes the code the classes live in.

## What stays true

- **Minn keeps every write and never links out** to a plugin's own screen
  (Austin, 2026-10-11). Security comes from making writes safe by
  construction, never from demoting a surface to read-only.
- **The UI half already exists.** Adapters describe screens as data and Minn
  renders them ([full-ui-adapters.md](full-ui-adapters.md), rungs 1 to 3). The
  kernel builds the data half and emits the descriptors the renderer already
  reads, so the client needs no rewrite.
- **Vendor knowledge stays in the adapter.** The kernel never guesses a
  vendor's rule; the adapter names it.

## The shape

An adapter declares a resource. Minn generates its routes, its descriptor and
its conformance checks.

```php
minn_admin_resource( 'everest/entries', array(
	'source'  => array( 'table' => 'evf_entries', 'key' => 'entry_id' ),
	'gate'    => array( 'read' => 'minn_admin_everest_can', 'write' => array( 'minn_admin_everest_can', 'manage' ) ),
	'scope'   => 'minn_admin_everest_allowed_form_ids', // the vendor's own row rule
	'fields'  => array(
		'form_id'      => array( 'type' => 'ref', 'options' => 'minn_admin_everest_titles' ),
		'status'       => array( 'type' => 'enum', 'labels' => array( 'publish' => __( 'Received' ), 'spam' => __( 'Spam' ), 'trash' => __( 'Trash' ) ) ),
		'date_created' => array( 'type' => 'datetime', 'stored' => 'utc', 'sort' => 'default' ),
		'answers'      => array( 'type' => 'fields', 'load' => 'minn_admin_everest_answers', 'detail' => true ),
	),
	'views'   => array( 'status' => array( 'publish', 'spam', 'trash' ) ),
	'search'  => array( 'answers' ),
	'actions' => array(
		'spam'   => array( 'when' => array( 'status' => 'publish' ), 'run' => fn( $id ) => minn_admin_everest_set_status( $id, 'spam' ) ),
		'delete' => array( 'when' => array( 'status' => 'trash' ), 'confirm' => true, 'run' => 'minn_admin_everest_delete' ),
	),
) );
```

### Sources, vendor first

In order of preference:

1. **The vendor's REST route**, called through as the current user. The
   vendor's own permission check, sanitizing and output shaping run every
   time, and Minn's gate is a floor under it, not the whole decision.
2. **The vendor's PHP API** (an entries class, a service container).
3. **A table**, as the fallback. The kernel builds the query from the declared
   table and columns, identifiers from a fixed list and every value prepared;
   the adapter writes no SQL for a list or a detail.

### What the kernel owns

- **Routes** under `minn-admin/v1`, so the write fence always arms. A ported
  adapter keeps its route paths, so the existing suites and API callers keep
  working.
- **Gates, deny by default.** Every verb needs a declared gate; no gate means
  no route. The app floor sits under each gate, and row scoping is declared,
  not hand-written into each query.
- **Parameters.** Ids come from the URL only and are typed by the key; page
  sizes are capped; every sort breaks ties by id.
- **Output.** Only declared fields leave the server. A `secret` field is never
  serialized, and value redaction runs on every response.
- **Writes, through one path.** The save primitive chosen on 2026-10-11, made
  generic: only changed fields are sent, the base stamp is checked and a stale
  copy answers 409, the vendor's own save function and capability are used
  where they exist, and the write fence sits underneath. Batches report every
  refused item. Every write stays; none becomes a link out.
- **Field types.** Each type knows how its value is stored and turns it into
  one shape for the client:
  - `datetime` with its stored zone (`site`, `utc`, `epoch`, `epoch_site`,
    `db`), always sent as ISO-8601 with an offset;
  - `text` with its stored encoding (`plain`, `entities`, `kses`, `json`,
    `serialized`), always sent as plain text for the client to escape;
  - `enum` with the vendor's labels, the raw value kept for gates;
  - `ref`, `bool`, `int`, `money`, `bytes`, `fields` and `secret`.
- **The descriptor.** Columns come from fields, tabs from views, and row and
  detail actions from one list. An adapter overrides only what is unusual.

### One conformance runner

Tests scale with field types, not with adapters. One runner walks every
registered resource and checks:

- each role against its gate, and the gate against the vendor's own
  capability (the route-gates snapshot, folded in);
- a secret seeded into every `secret` field never appears in any response;
- each field type round-trips the awkward shapes vendors really store
  (seconds, old link formats, entities, a change made in another tab);
- an unchanged save leaves storage byte-identical, one changed field changes
  only that field, and a stale save answers 409 (the round-trip harness,
  folded in);
- a refused write never reports success.

Per-adapter suites then cover only vendor-specific behaviour, and their
security checks can be deleted as each family ports.

### A ratchet

The number of hand-written REST routes in `includes/adapters/` (489 at
v0.45.0) may only go down. The inventory measures it; a suite fails when it
rises.

## v0.46 scope

1. **The kernel and the conformance runner.** Roughly 2,000 to 3,000 lines of
   PHP. It gets the release's full audit attention: a kernel bug would reach
   every ported adapter at once.
2. **Port form entries first.** The densest bug family in both the audit and
   the demo. Keep route paths and descriptor shapes, so the forms suites
   (about 300 checks) are the regression net with no rewrites. Delete the code
   and per-adapter security checks the kernel replaces, and record the net
   line change in the inventory.
3. **Turn on the ratchet.**
4. **Two light fences for unported adapters:** value redaction on every
   `minn-admin/v1` response, and the shared preview policy already applied to
   Gravity Forms.
5. **Leftovers from the v0.45 hardening:** the screens that still send whole
   records (post type and taxonomy definitions, site appearance, role
   defaults, the global-styles Undo), and the multisite subsite Users list.
6. **Keep the kernel API internal** until a second family ports cleanly.

Out of scope for v0.46: the licence manager (7,060 lines), the editor field
panels (ACF, ACPT, Meta Box), the settings mappers, network and WooCommerce
settings. Their shapes differ; the field types still remove their date and
entity handling.

## What to expect

An estimate, to be replaced by measurement:

- collection adapters about 50 to 65% smaller (52,151 lines today);
- everything else about 5 to 10% smaller at first;
- the adapter layer from 86,450 lines to roughly 52,000 to 60,000, with the
  kernel adding 2,000 to 3,000;
- hand-written routes and permission callbacks in ported families close to
  zero, replaced by a few gate declarations per resource.

## Later

- **v0.47:** port activity logs, mail, bookings and backups; publish the kernel
  API for third-party authors. A kernel-only adapter shows as such on the
  Integrations card, and one with its own routes is flagged, which is what the
  site-owner extension policy acts on.
- **Batteries.** A Minn-native forms, redirects or snippets feature is just a
  resource over Minn's own table, so the kernel makes the 2026-09-04 audit's
  "batteries included" idea cheap. Whether Minn should ship its own instead of
  adapting ten plugins is a product decision, not part of this plan.

## Risks

- **Concentration.** A kernel mistake breaks every ported adapter. The runner,
  the audit focus and porting one family at a time are the mitigation.
- **Vendor drift.** A vendor's REST shape or API can change; sources guard on
  class and method existence, as the adapters already do, and fall back to the
  table read.
- **Scope.** The failure mode is a kernel that tries to cover every shape on
  day one. It covers list, detail, actions and status first, because that is
  where 60% of the adapter code lives.

## Decisions

- Resource kernel as the direction, with form entries first.
- Ported adapters keep their route paths (recommended) or move to a new
  namespace.
- Kernel API internal in v0.46, public in v0.47 (recommended).
- The default extension policy for third-party adapters: allowed and flagged
  (recommended), reviewed only, or bundled only.
