# Roadmap — goals and the numbers that judge them

*Principles live in [goals.md](goals.md). Deep technical plans live in the per-area docs
(the editor in [editor-roadmap.md](editor-roadmap.md) and [editor-direction.md](editor-direction.md),
adapters in [plugin-support.md](plugin-support.md) and [adapter-coverage.md](adapter-coverage.md),
WP-CLI in [wp-cli-roadmap.md](wp-cli-roadmap.md), and the v1.0 charter in
[v1-readiness.md](v1-readiness.md)). This document is the scoreboard:
a short list of outcomes, each judged by a real-world number we can measure honestly.*

Minn phones home to no one, so every metric here is one we can read without telemetry:
GitHub's public counters, the pattern-corpus probes, the suite ledger, and disciplined
dogfooding. Statuses get refreshed at each release cut; feature checklists live in the
area docs and collapse to one-line ledger entries once shipped.

## Goal 1 — Minn is someone's whole admin

The founding claim is that daily WordPress work needs nothing wp-admin has. The only
honest way to prove it is to live it.

- **Metric:** consecutive days running anchor.host and minnadmin.com entirely through
  Minn. Opening wp-admin through a link Minn itself offers (the block editor escape,
  a plugin's own settings screen) counts as Minn working; opening wp-admin because Minn
  could not do the job breaks the streak and files an issue.
- **Now:** untracked. The streak starts with the v0.26.0 dogfood soak.
- **Target:** 30 consecutive days, twice. First across a quiet month, then across a
  release cut (the release itself managed through Minn).

## Goal 2 — the editor writes real pages, not just posts

"The writing editor for WordPress" has to hold on layout-shaped content, because that is
what real sites are made of. The nesting cycle (2026-08-08/09) was this goal's build-out.

- **Metric:** the share of the Twenty Twenty-Four/Five pattern corpus (155 patterns,
  494 container units) that opens writable in Minn, and byte-identity for untouched
  content across a save, enforced by suites.
- **Now:** every parseable group, columns, cover and media-text container opens as a
  writing surface with complex children as protected cards. The 2026-08-08 baseline
  probe measured 2% of top-level containers writable; the cycle ended with the gate
  itself deleted. Byte-identity is suite-pinned (nested-islands, container-slots,
  attr-carry, island-runs: 119 checks).
- **Target:** hold the corpus at effectively 100% writable with zero byte-identity
  regressions, release over release. A regression here blocks the release.

## Goal 3 — real people do real work in it

Not stars for a screenshot. Sites that update, and users who file the kind of bug you
only hit doing real work.

- **Metric:** the ≈active-sites estimate, computed daily by minnadmin.com: the
  largest closed-release download cohort of the trailing 30 days. The self-updater
  serves only the current release, so a superseded release's downloads are the
  distinct sites that updated during its reign, and the per-release data saturates
  within two to three days — the largest recent cohort is the honest lower bound.
  Plus issues filed by people other than the author.
- **Now (2026-08-09):** ≈69 active sites · 644 total downloads across 27 releases ·
  43 stars · 10 external issues, several of them exactly the doing-real-work kind
  (grouped-content editing, stale update offers, entity rendering).
- **Targets:** ≈100 active sites, then ≈500. The marketing site's ethos strip shows
  the active-sites claim automatically from ≈50 up. Keep external issue
  intake healthy: every real-work report answered, and the fix suite-pinned, within
  the release cycle it arrives in.

## Goal 4 — plugin authors wire in without us

Fifty-plus bundled adapters prove the primitives generalize. The ecosystem claim is only
proven when authors we have never met ship their own.

- **Metric:** third-party plugins shipping their own Minn adapter, not bundled in
  Minn's repo.
- **Now:** 1 (Anchor Blocks), and it shares an author with Minn, so honestly: 0
  external.
- **Target:** the first 3 external adapters. Feeders: the quickstart-first author
  guide, the shim tutorial with its suite-enforced example plugin, and the
  Integrations card flagging contract problems instead of failing silently.

## Goal 5 — v1.0 ships when the promises hold

v1.0 is not a feature count. The two promises and their gates live in
[v1-readiness.md](v1-readiness.md): authors enjoy wiring in, and authors cannot abuse
Minn. The charter's eleven audit items all shipped at v0.17.0.

- **Metric:** the charter gates green, plus Goals 1 and 3 showing real-world proof
  (a completed 30-day streak; active sites growing under real external use).
- **Now:** architecture and author-experience gates hold; the real-world proof is
  what remains.
- **Target:** cut v1.0 when a release goes out that changed nothing about the two
  promises because nothing needed changing.

## What this file is not

The never-build list ([editor-roadmap.md](editor-roadmap.md)) and the non-goals
([goals.md](goals.md)) stand unchanged. Nothing here is a feature commitment; a goal's
metric moving the wrong way is information, not an obligation to build.
