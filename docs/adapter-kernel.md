# The resource kernel: adapters as declarations

*Plan for v0.46 onward. Progress is measured, not described here: every figure
lives in `node bin/adapter-inventory.js` and its history in
`bin/adapter-inventory/history.json`, charted on the rollout dashboard.*

## Why

Every adapter re-implements the same plumbing around a small core of vendor
knowledge, and the plumbing is where the security findings and the display
bugs come from.

- **The adapters are the biggest part of Minn,** larger than the core PHP and
  the app together.
- **They grow faster than anything else.** Every release adds adapters, and
  each brings its own hand-written routes.
- **One shape dominates.** Most adapters are list, detail and actions screens
  over a vendor's data, and they hold most of the hand-written routes and
  permission callbacks.
- **Each one repeats the same decisions.** What only a vendor's adapter knows
  (its table, field list, status meanings, per-form access rule and status
  call) is a minority of its lines. The rest is routes, parameters, paging,
  search, sorting, counts, date and entity handling, error shapes, and actions
  written twice, once for the row and once for the detail.
- **The findings follow the plumbing.** The v0.45.0 release candidate's
  findings were integrity (a save changed what nobody touched), disclosure,
  gate and mechanical ones (path parameters read from the body), and the
  demo-site bugs were mostly times in the wrong zone, stored entities printed
  raw, raw status slugs and sort defaults. Each was the same mistake in another
  adapter, which is why fixing them one at a time never ends.

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

### Three tiers: nothing is dropped

Many hand-written routes exist because nothing generic could do the job. The
kernel keeps every one of them, in one of three tiers. The inventory sorts
every hand-written route by what it does (a route that builds its namespace in
code shows as unclassified):

| Tier | Kinds |
|---|---|
| **Declared** | lists, status cards and charts, single items (read, save, delete), item actions (spam, trash, approve, mark read, recheck) |
| **Kernel handler** | jobs (run, backup, import, export, purge), schema and builder, send, preview and retry, other writes (create, reorder, cleanup), settings tabs, activate, toggle and licence, pickers |

1. **Declared.** The generic shapes. Even here the vendor-specific part stays
   in the adapter as a small callback: a status card's numbers, the call that
   marks an entry spam.
2. **Kernel handler.** Anything specialised registers through the kernel,
   `minn_admin_endpoint()`, and keeps its own logic in a handler. The kernel
   still owns the route under `minn-admin/v1`, the required gate, ids from the
   URL, the write fence, the error shape and refusal reporting. This is the
   escape hatch: nothing becomes impossible, and these routes count as
   kernel-owned for the ratchet.
3. **Left as it is.** Shapes the kernel does not fit stay untouched until a
   primitive exists: the licence manager, the editor field panels, the
   settings mappers and the Gravity Forms builder. No port is forced.

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

### Parity before a port counts

A port must not drop or change anything people use, and that is checked, not
promised:

1. **Capture before the port.** For every route an adapter has, record its
   path and methods and, on the lab, its response shape: keys, types and
   nesting for a list, an item and each action, as each role the route admits.
   Record which browser suites cover the adapter.
2. **Diff after the port.** The ported adapter must register the same routes
   with the same methods, answer with the same shapes for the same roles, and
   pass its suites. The bespoke client pages (the booking page, entry pages,
   the subscription page) read exact response shapes, so a shape change is
   the realistic way a feature breaks, and the diff is where it shows.
3. **Only then is it ported.** An adapter counts as kernel on the dashboard
   once its parity diff is empty and its suites are green. A difference is
   either fixed in the port or recorded as a deliberate change with its
   reason.

### A ratchet

The number of hand-written REST routes in `includes/adapters/` may only go
down. The inventory measures it; a suite fails when it
rises.

## v0.46 scope

1. **The parity capture and diff tool, first.** Before any kernel code, so the
   current behaviour of every adapter is on record. It also captures the
   whole lab once as the v0.45.0 reference.
2. **The kernel and the conformance runner,** with both the declared and the
   handler tier. It gets the release's
   full audit attention: a kernel bug would reach every ported adapter at once.
3. **Port form entries first.** The densest bug family in both the audit and
   the demo. Keep route paths and descriptor shapes, so the forms suites
   are the regression net with no rewrites. Delete the code
   and per-adapter security checks the kernel replaces, prove parity, and
   record the net line change in the inventory.
4. **Turn on the ratchet.**
5. **Two light fences for unported adapters:** value redaction on every
   `minn-admin/v1` response, and the shared preview policy already applied to
   Gravity Forms.
6. **Leftovers from the v0.45 hardening:** the screens that still send whole
   records (post type and taxonomy definitions, site appearance, role
   defaults, the global-styles Undo), and the multisite subsite Users list.
7. **Keep the kernel API internal** until a second family ports cleanly.

Out of scope for v0.46: the licence manager, the editor field
panels (ACF, ACPT, Meta Box), the settings mappers, network and WooCommerce
settings. Their shapes differ; the field types still remove their date and
entity handling.

## What to expect

The first estimate was that collection adapters would about halve. They shrink
by less: most of a form adapter is the vendor's own shape (how answers are stored
and decoded, which buckets an entry moves between, what the status card counts,
the descriptor's labels and confirms), and that stays. A family whose adapters
repeat the same plumbing (status cards that run the same queries, lists over a
table) shrinks more. The dashboard has each pass's measured change.

What goes is what the routes repeated, and it is where the bugs were: every
ported route passes the same gate check (a strict yes, so a WP_Error or a truthy
number never opens it), the same row scope on lists, items, actions and status
cards alike, the same not found, forbidden and wrong-state answers in that order,
the same post-condition on every write, and the same error shape. The case for the
kernel is that, not size.

## Porting an adapter

The kernel lives in `includes/kernel/`; `class-minn-admin-kernel.php` opens with the
declaration keys, and `includes/adapters/everest-forms.php` is the worked example (no
hand-written route left, parity empty); `includes/adapters/stream.php` is the smallest
activity-log one. A port, one adapter at a time:

1. **Read the adapter whole** and sort its routes: a list, an item and item actions
   become `minn_admin_resource()`; a status card, a forms tab list, a send, a job or an
   action with a body that picks its own capability becomes `minn_admin_endpoint()` and
   keeps its logic, using `Minn_Admin_Kernel::load( $resource, $id, $gate )` for the
   not-found and row-gate checks.
2. **Keep everything observable.** Same route paths and methods, same gates per role
   (the gate callables are called with no arguments, so wrap a parameterised one in a
   closure), the same row-level check through `item_gate`, the same response keys and
   string formats (`'wire' => 'naked'` keeps a column's clock as it was sent), the same
   descriptor values. A status card's counts go through `Minn_Admin_Kernel::inbox_counts()`.
3. **Every action declares** a gate, the state it accepts (`when`), what must hold after
   it runs (`expect`), and its messages. The kernel then answers not found, forbidden and
   wrong state in that order, and never reports success when the expectation fails.
4. **Generate the plumbing parts of the descriptor** with `Minn_Admin_Kernel::collection()`
   (route, paging, search, keys, the day range, the filter query) and each row or bulk
   action with `Minn_Admin_Kernel::ui()`, so the client can never ask for something the
   routes do not serve. Presentation (columns, labels, tabs, confirms) stays in the adapter.
5. **Prove it:**
   ```bash
   node tests/parity/capture.js --out tests/.parity/<adapter>-after.json --only <adapter> --writes <surface id>
   node tests/parity/diff.js tests/.parity/v0.45.0-single.json tests/.parity/<adapter>-after.json --only <adapter>
   wp eval-file tests/kernel-conformance.test.php --user=admin --path=<absolute site path>
   MINN_RATCHET=write node tests/route-ratchet.test.js
   ```
   then the adapter's browser and PHP suites. The diff must show no FAIL. A deliberate
   change goes in an accept file with its reason; a REVIEW line (a key added, a format
   gained) is read and either fixed or explained in the commit.
6. **Delete what the kernel replaced:** the adapter's guard helpers, its hand-built SQL
   for lists and rows, its error plumbing.
7. **Shrink the family.** What every adapter of a family builds again goes into one kit,
   `includes/adapters/<family>-kit.php`: the mail kit builds a log's surface over its
   resource, its detail sections, its status card and its delete and resend declarations,
   and each mail adapter passes only its vendor's values. A kit is adapter tier
   (presentation and vendor-neutral formatting), never routes, gates or queries; an
   adapter on a kit registers its surface with `minn_admin_add_surface()`.

Declare with a function (`minn_admin_resource( 'x/y', function () { return array( … ); } )`)
when the declaration carries labels or messages: the kernel builds it on first use,
after Minn's translations load at `init` in the reader's language. A callable source
that proves deletion (`'expect' => 'gone'`) declares `exists`, asked of the store
directly, since its own read may hide a row from the caller.

The activity-log port (nine adapters) added what the forms ports had worked around:
a table the network shares (`'network' => true`), a fixed condition every read meets,
the list, an item, a status card and a tab list alike (`source.where`, matching nothing
when its function cannot answer), LIKE rules (`starts`, `contains`, `ends`) and `any`
(OR on one column), string and mapped narrowings (`facets`), a search that also matches
a meta table or the declaration's own conditions, a UTC wire format ending in `Z`, a
status card without a secondary series, and an array source for a vendor that keeps its
list in an option or a queue. A status card counts through its resource
(`Minn_Admin_Kernel::log_counts()`), so it can never count a row the list would hide.

The bookings port (five adapters, LatePoint's event registrations among them) added
joins: a source names the tables it LEFT JOINs to a unique key (or, with `first`, the
lowest row of a one-to-many table, a group booking's first customer), the main table is
`e`, and any column, condition, search or sort may name `alias.column`. Views may hold
conditions computed when the request runs and a sort of their own; `Minn_Admin_Kernel::at()`
gives "now" or a local midnight on whatever clock a column keeps (UTC or site datetime, a
date, an epoch or the site's wall clock read as one); and a status card reads through its
resource with `count()`, `first()` and `days()`, looking back or fourteen days ahead, with a
row counted in one series only.

The email-log port (seven adapters) added further reads of one item (`reads`: a log's
rendered message at `/<id>/view`, each read answering to its gate, the item's when it
names none, and sending only its declared fields), `none` (NOT any, decided in SQL only,
since negating PHP's narrower tests would widen them; a rule has exactly one kind), a
delete for a log table its vendor gives no delete for (`Minn_Admin_Kernel_SQL::delete()`,
by key and ids only), a send's post-condition (`'expect' => 'ran'`: the vendor's own
answer, and a `wp_mail_failed` during the run fails it), a chart split by a rule decided
in SQL with its day labels in a date format, the `sql` datetime wire for vendors whose
rows have always gone out as `Y-m-d H:i:s`, and date bounds held to the chart's own shape.

The redirects port (six adapters) added creates and edits: `create` is a POST on the
collection route behind its own gate, done only once a new row (on a table, its key above
every key before) reads back for the caller, and a source that is no table says how it proves
one; PUT, PATCH and a POST that says `item` answer on the item route, one action per method;
an action's `args` are JSON-schema checks the kernel runs after the gate and the row. Cards
gained `sum()` and `counts()`, the SQL layer `insert()` and `update()` (vendor tables only,
the key never written, every value checked against its column so MySQL never cuts or casts
it), and the database's own clock (`'stored' => 'db'`, read by the database for each date).

What the kernel does not have yet: the save path for edit forms (changed fields only,
a base stamp and a 409 on a stale copy), which the entry-edit adapters will need when
they port (the post type and taxonomy definitions use the same stamp and partial save
in `class-minn-admin-cpt.php`), OR between condition sets on different columns
(Wordfence's chart says it as one condition because its data allows it), and SQL
expressions in a column list (LatePoint's detail reads its order's comment with a second
read instead). The forms
adapters still carry their own workarounds for the shapes above; moving them onto the
new primitives is a pass of its own.

## Measuring progress

After each port, and at each release:

```bash
node bin/adapter-inventory.js --record "forms ported"   # appends a snapshot to bin/adapter-inventory/history.json
node bin/adapter-inventory.js --out /tmp/minn-inventory  # adapters.json and routes.json for the current tree
```

Commit the history with the port, then upload `history.json`,
`adapters.json` and `routes.json` to the rollout dashboard. The v0.45.0 adapter list is the
fixed baseline every change is measured against
(`node bin/adapter-inventory.js --ref v0.45.0 --out <dir>`).

## Later

- **Next:** redirects, snippets, gift cards, dev tools and backups, after forms,
  activity logs, bookings and mail; then the earlier families' kits.
  In v0.47, publish the kernel API for third-party authors. A kernel-only
  adapter shows as such on the Integrations card, and one with its own routes
  is flagged, which is what the site-owner extension policy acts on.
- **Batteries.** A Minn-native forms, redirects or snippets feature is just a
  resource over Minn's own table, so the kernel makes the 2026-09-04 audit's
  "batteries included" idea cheap. Whether Minn should ship its own instead of
  adapting ten plugins is a product decision, not part of this plan.

## Risks

- **Feature drop.** A port that silently loses or reshapes something. The
  handler tier means nothing has to be dropped, and the parity diff means
  nothing can be dropped unnoticed.
- **Concentration.** A kernel mistake breaks every ported adapter. The runner,
  the audit focus and porting one family at a time are the mitigation.
- **Vendor drift.** A vendor's REST shape or API can change; sources guard on
  class and method existence, as the adapters already do, and fall back to the
  table read.
- **Scope.** The failure mode is a kernel that tries to cover every shape on
  day one. It covers list, detail, actions and status first, because that is
  where most of the adapter code lives.

## Decisions

- Resource kernel as the direction, with form entries first.
- Ported adapters keep their route paths (recommended) or move to a new
  namespace.
- Kernel API internal in v0.46, public in v0.47 (recommended).
- The default extension policy for third-party adapters: allowed and flagged
  (recommended), reviewed only, or bundled only.
