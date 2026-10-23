# Roadmap

*This file is about what has not happened yet. Principles live in
[goals.md](goals.md); what already shipped lives in
[changelog.md](../changelog.md); the technical plans behind each area live in
the per-area docs (the editor in [editor-roadmap.md](editor-roadmap.md),
adapters in [plugin-support.md](plugin-support.md), the resource kernel in
[adapter-kernel.md](adapter-kernel.md), WooCommerce products in
[woocommerce-products.md](woocommerce-products.md), WooCommerce orders in
[woocommerce-orders.md](woocommerce-orders.md), WooCommerce subscriptions in
[woocommerce-subscriptions.md](woocommerce-subscriptions.md), WP-CLI in
[wp-cli-roadmap.md](wp-cli-roadmap.md), and the v1.0 charter in
[v1-readiness.md](v1-readiness.md)).*

*Last reviewed 2026-10-23, after v0.47.0.*

Three kinds of thing live here. **Goals** are outcomes with a number attached, so
progress is a measurement rather than an opinion. **Unlocks** are pieces of work
not shipped, each one a step change rather than an increment. **Open decisions**
are calls a plan scheduled and nobody has made yet.

Minn phones home to no one, so every number here is one that can be read without
telemetry: GitHub's public counters, the pattern-corpus probes, the suite ledger,
and disciplined dogfooding. Nothing here is a promise. A goal's number moving the
wrong way is information, not an obligation to build.

## Goal 1 — Minn is someone's whole admin

The founding claim is that daily WordPress work needs nothing wp-admin has. The
only honest way to prove it is to live it.

- **Metric:** consecutive days running anchor.host and minnadmin.com entirely
  through Minn. Opening wp-admin through a link Minn itself offers (the block
  editor escape, a plugin's own settings screen) counts as Minn working; opening
  wp-admin because Minn could not do the job breaks the streak and files an issue.
- **Now:** untracked. A goal nobody measures is decoration: either start the
  count with a one-line daily log, or drop the goal.
- **Target:** 30 consecutive days, twice. First across a quiet month, then across
  a release cut, with the release itself managed through Minn.

## Goal 2 — real people do real work in it

Not stars for a screenshot. Sites that update, and users who file the kind of bug
you only hit doing real work.

- **Metric:** an active-sites estimate computed from public release counters, plus
  issues filed by people other than the author. The self-updater serves only the
  current release, so a superseded release's downloads approximate the distinct
  sites that updated during its reign.
- **Now (2026-10-23, v0.47.0):** 143 stars, 12 forks, 8,263 plugin-package
  downloads across 49 releases. 54 issues filed by 15 people other than the
  author, none open, and outside pull requests merged from two contributors.
  The last six closed releases (v0.41.0 through v0.46.0) each drew 240 to 474
  downloads. That level has now held for two months and about twenty releases,
  which makes a single scraper a less likely explanation than in August, but
  public counters still cannot tell sites from automated acquisition. The
  defensible claim remains recurring package demand in the hundreds, not a
  verified active-site count. The marketing site still shows the largest
  closed cohort of the trailing 30 days (v0.42.0, so about 360); fixing that is
  an unlock below.
- **Target:** a trustworthy estimate first, then 100 and 500 real sites. Keep
  external issue intake healthy: every real-work report answered, and the fix
  suite-pinned, in the cycle it arrives in.

## Goal 3 — plugin authors wire in without us

A hundred and forty bundled adapter modules prove the primitives generalize. The
ecosystem claim is only proven when authors nobody here has met ship their own.

- **Metric:** third-party plugins shipping their own Minn integration, from their
  own repository, not bundled in Minn's.
- **Now:** one. In October 2026 a plugin with no connection to Minn shipped two
  surfaces through `minn_admin_surfaces` in one of its own releases, with its
  routes and permission checks in its own REST namespace. It was found by
  searching public code, not reported, so others may exist unseen. The earlier
  one (Anchor Blocks) shares an author with Minn and still does not count.
- **Target:** the first three. The feeders are already built: the quickstart-first
  author guide, the shim tutorial with its suite-enforced example plugin, and the
  Integrations card that flags contract problems instead of failing silently.
  Search public code for `minn_admin_surfaces` once a cycle to keep the count
  honest.

## Goal 4 — v1.0 ships when the promises hold

v1.0 is not a feature count. It is two promises, with gates, in
[v1-readiness.md](v1-readiness.md): authors enjoy wiring in, and authors cannot
abuse Minn.

- **Metric:** the charter gates green, plus Goals 1 and 2 showing real-world proof.
- **Now:** the architecture and author-experience gates hold. One gate is open:
  a developer who has never seen Minn wiring a plugin in using only the docs, in
  under half a day, verified with a real outside tester. The first outside
  integration (Goal 3) means such a developer may already exist; asking them how
  long it took and where the docs fell short could close the gate after the fact.
- **Target:** cut v1.0 when a release goes out that changed nothing about the two
  promises, because nothing needed changing.

## Unlocks not shipped

Each of these is a step change rather than an increment. Planning may exist, but
none has shipped in full.

- **The operational `wp minn` commands.** The namespace exists since v0.46.0, but
  only for switching a site to Minn (`wp minn preflight`, `wp minn install`).
  Minn's operational model is still only reachable by hand. The unlock is the
  wave 1 set in [wp-cli-roadmap.md](wp-cli-roadmap.md): `doctor`, `license list`,
  `integration list` and `integration validate`, which make it scriptable across
  a fleet. `integration validate` is the smallest and serves Goal 3 directly;
  `doctor` carries the most value. The rule there is the important part: a
  command earns its place only when it normalizes several plugins behind one
  interface, exposes a judgment Minn owns, or produces state worth reading
  across many sites. Anything core WP-CLI already does stays core WP-CLI's.
- **An active-sites estimate that survives a burst.** The current formula trusts
  the largest recent cohort, which a single scraper or a link on an aggregator
  can inflate by an order of magnitude. Until it is robust, the number on the
  marketing site is a claim that cannot be defended. Likely shape: the median of
  the closed cohorts in the trailing window (about 300 today against the 360
  shown), with an outlier guard and a floor on how much one cohort may exceed
  its neighbours.
- **The kernel's last shapes.** Adapters as declarations shipped across v0.46.0
  and v0.47.0 (see below). What is left are the routes the plan named out of
  scope: WooCommerce settings, ACF (field groups and its own few routes), ACPT,
  network administration and the licence manager. Progress is
  the inventory in `bin/adapter-inventory.js`.
- **Somebody else's fleet.** Every install today is one person's or one agency's
  choice. The unlock is a host or an agency standardizing on Minn for client
  sites, which is the first time the multi-user and per-user-hiding work gets
  tested by people who did not choose it themselves. Client sites built and run
  by Minn's own author do not count, for the same reason Anchor Blocks does not
  count toward Goal 3.

## Open decisions

- **Is the kernel API public?** [adapter-kernel.md](adapter-kernel.md) kept it
  internal for v0.46 and scheduled the call for v0.47 (recommended: public, with
  a kernel-only integration shown as such on the Integrations card and one with
  its own routes flagged). v0.47.0 shipped without it. The first outside
  integration registers its own routes and gates, which is exactly the case the
  decision and the default extension policy are about.

## Shipped since the last review

- **Adapters as declarations: the resource kernel.** The plugin's adapters now
  declare a resource (its vendor source, gate, fields and actions) while Minn
  owns the routes, the one safe write path, the field types, the descriptor and
  one conformance runner. Every family the plan put in scope moved in v0.46.0;
  v0.47.0 added the fields, settings and credential tiers, so editor panels and
  settings screens save through one checked path and each integration declares
  where its plugin keeps its keys. Every write stayed. Details in
  [adapter-kernel.md](adapter-kernel.md) and the changelog.

## What this file is not

Not a feature list. Feature-level plans live in the per-area docs and collapse
into the changelog once they ship. The never-build list
([editor-roadmap.md](editor-roadmap.md)) and the non-goals
([goals.md](goals.md)) stand unchanged.
