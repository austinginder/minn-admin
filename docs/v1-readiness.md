# v1.0 charter

*The two promises a v1.0 has to make, and the gates that decide when it can.
Written 2026-07-16 at v0.16.0 from a full audit of the plugin and its docs; the
audit's own findings and the cycle that cleared them have been folded into
[changelog.md](../changelog.md) rather than kept here. Forward-looking work lives
in [roadmap.md](roadmap.md).*

The bar for v1.0 is not feature count. It is two promises Minn has to be able to
make out loud:

1. **Plugin authors enjoy wiring in.** A competent WordPress developer gets their
   plugin into Minn in an afternoon, without reading Minn's source, and knows the
   contract will not break under them.
2. **Plugin authors cannot abuse Minn.** Nothing a plugin registers can grab
   attention the user did not ask for, and everything a plugin registers can be
   muted by the user. WordPress lost this fight in its notification system; Minn
   must win it by architecture and by user control, not by policy documents.

## What is already working (keep, and say it louder)

- **The architecture is inherently abuse-resistant.** Third-party PHP never hooks
  into Minn's render path and third-party HTML/CSS/JS never reaches the SPA.
  Integrations are pure data descriptors; Minn escapes every value at the edge.
  The entire class of wp-admin abuse (arbitrary admin HTML, giant banners, fake
  buttons) does not exist here. This is goal #5 and it holds.
- **Notifications are already solved.** Plugins cannot inject anything into Minn's
  notification panel. Their wp-admin notices arrive only through extraction:
  reduced to text, severity and up to three links, attributed to their owner,
  hideable per user with Undo, with action links running in the background. This
  is the exact answer to the WordPress notice problem and it should be a
  headline claim, not a changelog footnote.
- **The quickstart payoff ratio.** Twenty declarative lines produce a paginated,
  searchable, capability-gated admin view with zero JavaScript and no build step.
- **The Integrations card.** A live registry of everything registered, attributed
  per plugin, with contract problems flagged instead of failing silently. Most
  ecosystems never build this.
- **A written compatibility promise** (additive-only, deprecation windows, "keys
  not on this page are internal") and reference adapters that are genuinely
  readable. Ninety-four bundled adapter modules prove the primitives generalize
  across four different settings-schema frameworks.
- **The quality bar**: 286 browser suites, verification on clean and
  production-scale sites, zero-console-error gates.

## v1.0 gates

*Status check 2026-08-18 (v0.32.0 released; current main has 286 browser
suites): G2 through G6 remain shipped and represented by their canonical
gate suites (tests/hide-integrations.test.js,
hide-slash-designs.test.js, design-sources.test.js,
attention-budgets.test.js, offsite-links.test.js, contract.test.js and
example-adapter.test.js). G1, the afternoon test with a real outside
tester, is still the only open gate. Its material is all in place
(docs/shim-tutorial.md, the suite-enforced
docs/examples/minn-example-adapter and the Playground blueprint); finding
that tester remains scheduling work, not engineering work.*

v1.0 ships when all of these are true:

- [ ] **G1 — Afternoon test.** A developer who has never seen Minn wires a
      custom-table plugin into a full surface (list, detail, actions, status
      card) using only the docs, in under half a day. Verified with a real
      outside tester, not just internally.
- [x] **G2 — User sovereignty.** Every registered integration point (surface,
      panel, commands, design source) can be hidden per user from the UI, and
      the hide survives updates. *(Surfaces + editor panels shipped 2026-07-16,
      v0.17.0 cycle: `minn_admin_hidden_integrations` user meta,
      `minn-admin/v1/integrations/hide|unhide`, nav/door right-click, restore
      on Your profile. Design sources (`design:<id>`) and slash namespaces
      (`slash:<ns>`, covering auto blocks + insert templates + patterns +
      namespaced commands together) completed the gate later the same day:
      right-click a block-picker group heading; hidden entries leave the
      server payloads and the inline slash menu prunes in place. Palette rows
      ride their surface's hide.)*
- [x] **G3 — Attention budget.** Placement and count limits are enforced by the
      validator and the client, not by convention. A plugin cannot add more than
      its budget to the nav, palette or default slash menu; overflow degrades
      gracefully (search-only, collapsed groups) instead of being dropped.
      *(Shipped 2026-07-16, v0.17.0 cycle. Workspace requires an inbox-shaped
      collection (an `ago` column) or it degrades to Tools with a validator
      flag. One owner holds at most 3 nav slots: past that, family-less
      surfaces collapse into a synthetic family (one nav item, one palette
      row, existing switcher mechanics; Minn's bundled adapters exempt since
      each registers only while its subject plugin is active, and Unknown
      owners exempt so attribution failures never merge strangers). One
      namespace holds at most 3 default slash entries; overflow demotes to
      search-only. The Integrations card flags the workspace problem and
      notes over-budget owners informationally.)*
- [x] **G4 — External-link honesty.** Every plugin-supplied link that leaves the
      site renders with the external affordance. No descriptor can make an
      upsell look like an app action. *(Shipped 2026-07-16, v0.17.0 cycle:
      shared `hrefLabel()` renderer marks off-site hrefs ↗ at every render
      site (status-card actions, detail-modal actions, row menus, every
      openMinnMenu link), and the validator lists descriptor-carried
      off-site hrefs per surface on the Integrations card, informationally.
      Status-card links arrive in route responses at runtime, so they get
      the render-time mark but not the static flag. The author guide's
      Integration etiquette section states the guarantee.)*
- [x] **G5 — Contract freeze.** The documented descriptor vocabulary is complete
      (no load-bearing undocumented keys), annotated with since-versions, and
      covered by a contract suite that drives a fixture third-party plugin
      through every documented key. *(Shipped 2026-07-16, v0.17.0 cycle:
      tests/contract.test.js, 36 checks. Half one is DOC LOCKSTEP — every
      key in the validator's vocabulary constants must appear in
      for-plugin-authors.md, so a new key can't land undocumented. Half two
      drives the minn_test_contract_surface kitchen-sink fixture (route
      tabs + allRoute, query/pageQuery, every column format + altKey/utc/
      width, detail with labels/messageKey/skip/edit + preserve + the full
      field vocabulary, every action key, when-gated bulk, filter, create
      with defaults, manage, views[], status rows/chart/command/actions,
      item-scoped settings via settingsItem) end to end with server-state
      verification; the same descriptor validating with ZERO problems on
      the Integrations card is itself a contract check. Setup gates, plain
      settings views, editor panels and design sources are covered by their
      own dedicated fixture suites.)*
- [x] **G6 — One docs entry point.** A single author guide that starts with the
      quickstart, includes the shim tutorial and screenshots, and has no stale
      sibling contradicting it. *(Restructure shipped 2026-07-16: quickstart
      first, shim tutorial + suite-enforced example plugin, test-your-adapter
      and AI-agent sections, capability patterns documented, canonical icon
      list, run-on cells split, since-versions, extension-api.md deleted.
      Screenshots landed later the same day: nine dark-theme 2x captures in
      docs/img covering every primitive (surface list, status card, detail
      modal, contact-card entry, setup gate, settings view, sidebar doors +
      opened panel, slash namespace entries), shot from the live app with
      the Campfire example adapter as the subject so the tutorial and its
      pictures can't diverge; the marketing site's guide modal renders them
      via GitHub raw.)*
