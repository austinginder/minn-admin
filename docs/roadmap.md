# Roadmap

*This file is about what has not happened yet. Principles live in
[goals.md](goals.md); what already shipped lives in
[changelog.md](../changelog.md); the technical plans behind each area live in
the per-area docs (the editor in [editor-roadmap.md](editor-roadmap.md),
adapters in [plugin-support.md](plugin-support.md), WooCommerce products in
[woocommerce-products.md](woocommerce-products.md), WooCommerce orders in
[woocommerce-orders.md](woocommerce-orders.md), WP-CLI in
[wp-cli-roadmap.md](wp-cli-roadmap.md), and the v1.0 charter in
[v1-readiness.md](v1-readiness.md)).*

Two kinds of thing live here. **Goals** are outcomes with a number attached, so
progress is a measurement rather than an opinion. **Unlocks** are pieces of work
not started, each one a step change rather than an increment.

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
- **Now:** untracked.
- **Target:** 30 consecutive days, twice. First across a quiet month, then across
  a release cut, with the release itself managed through Minn.

## Goal 2 — real people do real work in it

Not stars for a screenshot. Sites that update, and users who file the kind of bug
you only hit doing real work.

- **Metric:** an active-sites estimate computed from public release counters: the
  largest closed-release download cohort of the trailing 30 days. The self-updater
  serves only the current release, so a superseded release's downloads approximate
  the distinct sites that updated during its reign. Plus issues filed by people
  other than the author.
- **Now (2026-08-13):** 84 stars, 1,685 downloads across 30 releases, 3 open
  issues. The active-sites estimate is **not currently trustworthy**: the v0.27.0
  cohort reads 608 against 17 to 69 for every neighbouring release, which is
  burst-shaped rather than ten-fold growth. The defensible read of the last few
  months is a few dozen active sites. Fixing the estimate is an unlock below.
- **Target:** a trustworthy estimate first, then 100 and 500 real sites. Keep
  external issue intake healthy: every real-work report answered, and the fix
  suite-pinned, in the cycle it arrives in.

## Goal 3 — plugin authors wire in without us

Fifty-plus bundled adapters prove the primitives generalize. The ecosystem claim
is only proven when authors nobody here has met ship their own.

- **Metric:** third-party plugins shipping their own Minn adapter, from their own
  repository, not bundled in Minn's.
- **Now:** zero external. The one that exists (Anchor Blocks) shares an author
  with Minn, so it does not count.
- **Target:** the first three. The feeders are already built: the quickstart-first
  author guide, the shim tutorial with its suite-enforced example plugin, and the
  Integrations card that flags contract problems instead of failing silently.

## Goal 4 — v1.0 ships when the promises hold

v1.0 is not a feature count. It is two promises, with gates, in
[v1-readiness.md](v1-readiness.md): authors enjoy wiring in, and authors cannot
abuse Minn.

- **Metric:** the charter gates green, plus Goals 1 and 2 showing real-world proof.
- **Now:** the architecture and author-experience gates hold. One gate is open:
  a developer who has never seen Minn wiring a plugin in using only the docs, in
  under half a day, verified with a real outside tester. That is scheduling work
  rather than engineering work.
- **Target:** cut v1.0 when a release goes out that changed nothing about the two
  promises, because nothing needed changing.

## Unlocks not started

Each of these is a step change rather than an increment, and none has been begun.

- **A `wp minn` command namespace.** Minn's operational model is currently only
  reachable by hand. A command line makes it scriptable across a fleet: license
  state normalized across vendors, integration-contract diagnostics, the System
  health model, provider-aware cache clearing, site-visibility state. The audit
  and the proposed command set are in [wp-cli-roadmap.md](wp-cli-roadmap.md). The
  rule there is the important part: a command earns its place only when it
  normalizes several plugins behind one interface, exposes a judgment Minn owns,
  or produces state worth reading across many sites. Anything core WP-CLI already
  does stays core WP-CLI's.
- **An active-sites estimate that survives a burst.** The current formula trusts
  the largest recent cohort, which a single scraper or a link on an aggregator
  can inflate by an order of magnitude. Until it is robust, the number on the
  marketing site is a claim that cannot be defended. Likely shape: a median or
  trimmed mean across closed cohorts, with an outlier guard and a floor on how
  much one cohort may exceed its neighbours.
- **Minn in a language other than English.** Asked for by a user (German), and
  the machinery is already built: the text domain loads, PHP uses the core
  functions, and the app carries its own `__()`, `_n()` and `sprintf()` fed from
  the boot payload, so a locale is a matter of dropping the normal `.mo` and
  `wp i18n make-json` output into `languages/`. The gap is coverage, not
  plumbing. 585 strings are extractable today and roughly the same number is
  still hardcoded in the app: about 340 unique pieces of visible text and 226
  placeholder, title and aria-label attributes. The shipped `.pot` compounds it
  by being four releases stale, stamped 0.25.0 against 353 strings. So a
  translator handed today's file would produce an admin that reverts to English
  the moment anyone does real work, since Cancel, Save changes, Status and most
  placeholders are still literals, and a half-translated interface reads worse
  than an English one. The order is: regenerate the `.pot`, sweep the remaining
  literals view by view the way the convention already says, then ship a locale.
  German is the natural first because the suite already exercises it as a
  fixture. The open question is not engineering but upkeep. Minn is distributed
  from GitHub rather than wp.org, so there is no translate.wordpress.org and no
  volunteer translation community attached, which means every locale shipped
  becomes a standing obligation on each release. The likely answer is to finish
  the sweep and publish the `.pot`, then accept locales from contributors who
  will maintain them, rather than owning a language here.
- **Somebody else's fleet.** Every install today is one person's or one agency's
  choice. The unlock is a host or an agency standardizing on Minn for client
  sites, which is the first time the multi-user and per-user-hiding work gets
  tested by people who did not choose it themselves.

## What this file is not

Not a feature list. Feature-level plans live in the per-area docs and collapse
into the changelog once they ship. The never-build list
([editor-roadmap.md](editor-roadmap.md)) and the non-goals
([goals.md](goals.md)) stand unchanged.
