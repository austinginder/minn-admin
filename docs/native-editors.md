# Native editors and developer surfaces — the rungs above the adapter ladder

**Status: parked, deliberately.** Nothing here is scheduled. This doc exists so
that when one of these pulls hard enough to build, the scope argument is already
made and the boundaries are already drawn. It extends `full-ui-adapters.md`,
whose four-rung ladder ends at "delegate the bespoke screens"; this is the map
of what could sit above that, and the test for deciding whether something
belongs there.

## The decision test

The adapter ladder's Rung 4 says: when a plugin screen can't be expressed in
descriptors, deep-link to it. That rule conflates two different situations, and
separating them is the whole insight:

1. **The screen is a canvas.** The interaction itself is the product: dragging
   Elementor sections, painting Bricks layouts, GF's live form preview. The
   artifact these produce is either opaque (builder meta blobs) or inseparable
   from the rendering loop. Delegation is permanently correct here. This never
   changes.
2. **The screen is a fancy form over a clean document.** The UI looks
   complex, but what it *produces* is an enumerable, round-trippable data
   document with a public write path. Here Minn doesn't need the plugin's
   screen at all: it can build its own editor for the document, in Minn's
   idiom, and the plugin can't tell the difference.

So the test is: **ignore the UI, look at the artifact.** Is it a clean document
(JSON through a REST route, an enumerable schema, a versioned option shape)?
Then a native editor is *possible* and the question becomes product scope. Is
it opaque, or is the interaction the product? Then the deep link is the answer
forever, and no amount of demand should reopen it.

Two families pass the test today, and they are different enough to treat
separately: **native editors over clean documents** (the Gravity Forms case)
and **developer surfaces over site primitives** (database viewer, file
browser), where the "document" is the site itself.

## Case study 1: a Gravity Forms form editor (the 80% editor)

### Why it passes the test

From the 2026-07-06 source research (details in `full-ui-adapters.md`):

- A GF form is ONE JSON document (`display_meta`) that round-trips through
  `PUT gf/v2/forms/{id}`. Fields, settings, notifications and confirmations
  all live inside it.
- The field palette is programmatic: `GF_Fields::get_all()` enumerates every
  registered field type, including add-on-provided ones.
- Per-type settings keys are enumerable, and conditional logic is a small rule
  JSON (`{ actionType, logicType, rules: [{ fieldId, operator, value }] }`),
  which is Minn's own `when`-condition shape, grown up.
- GF's own editor is hand-authored inline JS on top of this document. Nothing
  it does is privileged; a foreign editor writing the same document produces
  forms GF renders and edits interchangeably.

### The scope decision: 80%, not parity

A parity editor (GF's drag-drop canvas, ~100 hand-authored setting panels,
live preview) fails the canvas test and would put Minn on a permanent parity
treadmill. The Stackable lesson applies: chasing a plugin's own React UI is a
race Minn loses by winning.

The 80% editor is a different product: **form management with field editing**,
in Minn's list-first idiom, covering the edits people actually make to forms
that already exist:

- **Field list** — add, remove, reorder (the menu-drag pattern), duplicate.
  Rendered as rows, not a canvas; a static preview panel can render the form
  via GF's own front-end markup in an island-style preview, read-only.
- **Per-field basics** through the shared form engine: label, description,
  required, placeholder, choices (add/reorder/default), field size, admin
  label. Field types whose settings map onto the form vocabulary get full
  editing; the rest render their mapped subset plus a locked count with
  "edit in Gravity Forms ↗" (the ACF locked-fields pattern).
- **Form settings, notifications, confirmations** — the Phase-2 GF Settings
  mapper ✅ shipped (v0.13.0 cycle, 2026-07-12): per-form settings via the
  item-scoped settings view, notifications as a list view with daily-field
  editing; confirmations editing was deliberately skipped (form-build-time
  work, not daily). The 80% editor composes with them rather than
  containing them.
- **Conditional logic, read-first**: render existing rules as sentences
  ("Show when Budget is greater than 500"). Editing rules is a v2 decision;
  the rule JSON is trivial but the UX of building rules well is not.

Explicitly OUT, permanently: drag-drop canvas, live styled preview, layout
columns, the pricing/product field wiring, add-on feed UIs beyond what the
settings mapper covers. The deep link is one click and GF's editor is good.

### Load-bearing risks

- **Full-form PUT concurrency.** One notification edit round-trips the whole
  form JSON; two editors can clobber each other, and GF's own locking is
  admin-screen-bound so it cannot help. Mitigation: read-modify-write inside
  the shim with a `date_updated` check before write, plus Minn-side locking
  if this ever ships (the `_edit_lock` pattern proved core and Minn can share
  a lock protocol; GF would need the same idea via form meta).
- **Version drift.** The mapper reads schemas at runtime so *fields* self-heal,
  but the editor's assumptions about `display_meta` structure are code. Guard
  every read, degrade to the deep link, never fatal (the standing shim rule).
- **The treadmill boundary must be written down.** The moment a request needs
  per-field-type bespoke UI beyond the form vocabulary, the answer is the
  locked count, not a new control. This is the same discipline that keeps the
  block inspector honest.

### Cost, honestly

This is "Minn builds a second editor" scale: think the block-inspector effort,
not an adapter. Ballpark a full cycle for field-list + basics + suites. The
prerequisite plumbing (item-scoped settings, the Settings-framework mapper,
notifications write path) shipped in the v0.13.0 cycle; what remains before
committing is dogfooding that form-management depth on a real site with
active Gravity Forms use, so the bet is earned, not assumed.

### The generalization

GF is the reference, not the target. The same 80%-editor shape fits any plugin
whose artifact is a clean document: Fluent Forms (forms are JSON in its own
tables with REST), Redirection groups, WP Mail SMTP connection wizards. The
rule: build the document editor ONCE against the hardest well-shaped case
(GF), extract what generalizes into the form engine, and let other adapters
opt in with mappers, exactly like the settings-surface multiplier.

## Case study 2: a database viewer (developer surface)

### Why it passes the test

The "document" is the database itself: enumerable (information_schema),
pageable, and already partially surfaced (the System page's table-size card).
A read-only browser is the surface engine pointed at a `$wpdb` shim: table
list → paged rows → row detail. No plugin required, no new client machinery;
the build is almost embarrassingly small next to its usefulness as a
diagnostic.

### The boundary that makes it shippable

**Read-only is not a v1 compromise; it is the product.** A database *editor*
bypasses every plugin's invariants (serialized blobs, caches, foreign-key-ish
conventions WordPress fakes in code) and converts a diagnostic into a foot-gun.
Writes are a permanent non-goal, stated in the UI ("read-only by design").

Scope sketch:

- System-page adjacent (Manage group, `manage_options`, and consider gating
  behind the same spirit as the wp-config debug tools: visible only where
  file-mod-style trust already exists).
- Table list with row counts and sizes (the System card, promoted), search
  by table name, prefix-scoped by default with an explicit toggle for
  foreign-prefix tables.
- Paged rows with the surface engine's table renderer; a row detail modal
  reusing the entry-detail shape. LONGTEXT/blob columns render truncated with
  a copy control, serialized values render RAW and are never unserialized
  (the standing shim rule protects even a viewer).
- Column sort, simple per-column contains-filter. No JOINs, no query box: a
  SQL console is a different product with a different threat model, and the
  answer to "I need real SQL" is WP-CLI or Adminer, honestly.

### Risks

Small and mostly about restraint: the temptation to add "just one" write path
(fix a typo in an option) is how viewers become editors. The one real
technical caution: SELECTs against huge tables need LIMIT discipline and a
row-count cap read from information_schema estimates, or the viewer becomes a
site-killer on the exact sites where it is most needed.

## Case study 3: a file browser (developer surface)

The weakest of the three cases, recorded mostly to draw its boundary.

- **Read-only listing + file viewer** (browse wp-content, view a log or a
  config with the debug-log overlay pattern) is defensible and cheap; the
  System debug tools already read files by path.
- **A file MANAGER (write, upload, chmod, edit PHP) is a non-goal.** It is
  the single most abused surface in WordPress security, it exists in
  wp-admin-adjacent plugins for those who accept the risk, and nothing about
  Minn's product (calm daily admin) needs it.
- Disembark note: its connector already exposes file/database primitives over
  REST (`disembark/v1/database`, `/stream-file`, `/file/save`, ...) and could
  in principle power this. Before Minn ever points UI at those routes, they
  need real auth upstream: as of v2.7.0 they register without
  `permission_callback` (token checks live elsewhere), which is already
  flagged as an upstream fix. Server-side shim access under Minn's own cap
  gate would be the pattern regardless (the rest_do_request precedent).

## What we will never build (this doc's additions to the standing list)

- Parity clones of drag-drop canvases (form builders included: the 80% editor
  is a different product, and the canvas stays GF's).
- Database writes or a SQL console.
- File write/upload/manager surfaces.
- Any of these behind a feature flag "just to try": the boundaries above are
  product decisions, not staging.

## Sequencing, if any of this ever schedules

1. ~~GF Settings mapper ships first~~ ✅ shipped (v0.13.0 cycle,
   2026-07-12) — the prerequisite plumbing for the 80% editor now exists:
   item-scoped settings views, the Settings-framework mapper, and the
   notifications write path through `save_form_notifications`.
2. Database viewer is the cheapest full item here and the best trial balloon
   for "developer surfaces": one cycle fragment, no plugin dependency.
3. The GF 80% editor is a full cycle and should be a deliberate product bet,
   made when form management in Minn (entries + settings + notifications,
   all live as of v0.13.0) has proven that users stay in Minn for form
   work. Dogfooding on a real site with active Gravity Forms traffic is the
   honest test before committing.
4. File browsing only ever ships as read-only, and only if a real diagnostic
   need surfaces that the debug-log viewer doesn't already cover.
