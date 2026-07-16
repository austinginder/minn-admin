# Extending Minn Admin to other plugins (historical proposal)

> **This is the original design proposal, kept for rationale.** The shipped
> descriptor vocabulary has moved on: keys in the example below (like `stats`
> and the old `tabs` shape) no longer exist. The ground truth for integrating a
> plugin is [for-plugin-authors.md](for-plugin-authors.md). Do not copy code
> from this file.

How does Minn surface Gravity Forms entries, Gravity SMTP logs, or any other plugin's data —
without hand-building a UI for every plugin in the ecosystem?

## The three possible strategies

1. **Bespoke views per plugin** — what Orders is for WooCommerce. Highest quality, highest cost.
   Only justifiable for a handful of high-value plugins.
2. **Iframe the plugin's wp-admin pages.** Works for anything, looks like wp-admin stuffed inside
   Minn. Rejected — it reintroduces exactly the chrome Minn removes.
3. **A declarative "surface" API** — plugins (or Minn-bundled adapters) *describe* their data and
   Minn renders it with the generic primitives that already exist (stat cards, tables with tabs,
   detail modals, row actions, Load-more pagination). This is the scalable path.

## Recommendation: descriptor-driven surfaces, with bespoke as the escalation

Most plugin admin screens are one of three shapes: a **list** (form entries, SMTP logs, orders,
submissions), a **detail** view of one item, and a few **stat numbers**. Minn already renders all
three shapes generically — Orders/Users/Comments are hand-wired instances of them. The proposal is
to make that wiring data-driven:

```php
add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
    $surfaces['gravity-forms'] = [
        'label'  => 'Forms',
        'icon'   => 'inbox',
        'cap'    => 'gravityforms_view_entries',
        'stats'  => [ /* optional stat-card descriptors */ ],
        'collection' => [
            'route'   => 'gf/v2/entries',           // any REST route, called with cookie+nonce
            'tabs'    => [ 'form_id' => 'gf/v2/forms' ],  // tab list fed by another route
            'columns' => [
                [ 'key' => 'date_created', 'label' => 'Date',   'format' => 'ago' ],
                [ 'key' => '1',            'label' => 'Name' ],   // GF field IDs are keys
                [ 'key' => 'status',       'label' => 'Status', 'format' => 'pill' ],
            ],
            'detail'  => [ 'route' => 'gf/v2/entries/{id}' ],   // rows → detail modal
            'actions' => [
                [ 'label' => 'Spam',  'method' => 'PUT', 'route' => 'gf/v2/entries/{id}', 'body' => [ 'status' => 'spam' ], 'confirm' => false ],
                [ 'label' => 'Trash', 'method' => 'DELETE', 'route' => 'gf/v2/entries/{id}', 'confirm' => true ],
            ],
        ],
    ];
    return $surfaces;
} );
```

Minn's PHP passes registered surfaces into the boot payload (filtered by capability); the JS adds
a nav item per surface and renders it entirely from the descriptor. **No plugin needs to ship
JavaScript**, and third-party plugins can integrate without knowing anything about Minn's
internals — it's one filter.

### Two registration paths

- **Native**: a plugin hooks `minn_admin_surfaces` itself (the long-term ecosystem play).
- **Bundled adapters**: Minn ships descriptors for popular plugins that will never know about it
  (`includes/adapters/gravity-forms.php`, registered only when `class_exists( 'GFAPI' )`). This is
  how coverage grows immediately without waiting for anyone.

### When a descriptor isn't enough

The escalation ladder: **descriptor surface → bespoke JS view → "Open in wp-admin" link.**
WooCommerce Orders stays bespoke (summary cards + currency handling earn it). A plugin with no
REST API at all needs a small PHP shim first — a `minn-admin/v1/proxy/<surface>` endpoint the
adapter registers server-side (e.g. Gravity SMTP's email log lives in custom tables; the adapter
would query them directly and expose a REST collection Minn can consume).

## Reality check on the two named plugins

- **Gravity Forms** ships a real REST API (`gf/v2/forms`, `gf/v2/entries`) with cookie-auth
  support and per-cap permissions — a bundled adapter is buildable today with list/detail/spam/
  trash actions. Good first proof of the descriptor format.
- **Gravity SMTP** stores its email log in custom tables and drives its React UI through internal
  endpoints — no stable public REST surface. It's the motivating case for the PHP-shim adapter:
  ~40 lines of SQL-to-REST in `includes/adapters/gravity-smtp.php`, then the generic descriptor
  renders the log (subject, to, status, opened) with a detail modal showing the message body.

## Integration classes — not every plugin is a list

Collection surfaces (built: Gravity Forms, Gravity SMTP, and WooCommerce bespoke) cover plugins
whose admin is fundamentally a **list of records**. Popular plugins fall into three classes, and
only the first one wants an adapter of the kind we've built:

| Class | Shape | Examples | Minn strategy |
|---|---|---|---|
| **1. Collections** | Lists of records (entries, logs, orders, submissions) | Gravity Forms, Gravity SMTP, WooCommerce | Surface descriptors — **built** |
| **2. Editor companions** | Per-post fields and metaboxes | ACF / ACF Pro, Rank Math, Yoast SEO | **Editor panels** (planned, below) — not a sidebar surface |
| **3. Configuration** | One options page, set-and-forget | Perfmatters, caching/security plugins | Mostly *don't* — link out; optionally a small settings surface for high-frequency toggles |

### Class 2: editor panels (the next framework investment)

ACF and the SEO plugins live *inside the post*, so their Minn integration belongs in the editor
sidebar, not the nav. The plan mirrors surfaces — declarative panels registered via a
`minn_admin_editor_panels` filter:

- **ACF / ACF Pro** — field groups with "Show in REST" enabled already expose an `acf` object on
  the post REST response, readable and writable. A panel descriptor maps field names/types to
  Minn inputs; complex field types (repeaters, flexible content) stay in wp-admin with a
  link-out, exactly like the editor's locked mode. ACF is the flagship candidate.
- **Rank Math / Yoast SEO** — the valuable 90% is three fields: SEO title, meta description,
  focus keyword. Neither plugin registers its post meta for REST, so the adapter ships a tiny
  shim (`register_post_meta` with `show_in_rest` + an `auth_callback` requiring `edit_post`) for
  `rank_math_title` / `rank_math_description` / `rank_math_focus_keyword` or
  `_yoast_wpseo_title` / `_yoast_wpseo_metadesc` / `_yoast_wpseo_focuskw`. A snippet-preview
  panel then reads/writes them through the normal post endpoint. Scores and content analysis
  stay in wp-admin — that's their moat, not ours.

### Class 3: configuration plugins

Perfmatters and friends are dense option pages people touch a few times per site. Rebuilding
them declaratively is high effort, low frequency, high breakage risk. Default answer: an
Extensions-card "Settings ↗" link into wp-admin. If a specific toggle turns out to be touched
weekly, promote just that toggle into a small settings surface.

## Status

Everything in the original plan has shipped: collection surfaces, editor panels and traffic
providers are live with the bundled adapters (Gravity Forms, Gravity SMTP, ACF, Simple History,
Redirection, Safe Redirect Manager, Simple 301 Redirects, Query Monitor, Koko Analytics,
WP Statistics, Burst Statistics, Independent Analytics, AnalyticsWP, plus the bespoke
WooCommerce Orders view). The public reference lives in `for-plugin-authors.md`. The **SEO
editor panel** (the last planned piece) landed in the v0.5.x cycle — `adapters/seo.php`
detects Yoast or Rank Math and registers a dedicated read/write `minn_seo` REST field
(context=edit only) for SEO title, meta description and focus keyword, rather than the
originally-sketched raw `register_post_meta` shim: the editor writes its whole panel object
back on save, so a dedicated field keeps that write scoped to exactly these three values.

## Suggested build order (historical)

1. Extract the generic collection renderer from Orders/Users into a descriptor interpreter.
2. Boot-payload plumbing: `minn_admin_surfaces` filter → capability filter → `window.MINN.surfaces`.
3. Bundled Gravity Forms adapter (pure descriptor, no shim) — proves the format.
4. Bundled Gravity SMTP adapter (descriptor + REST shim) — proves the shim pattern.
5. Document the filter publicly; that's the ecosystem invitation.
