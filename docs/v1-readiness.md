# v1.0 readiness — audit and the v0.17.0 charter

*Written 2026-07-16 at v0.16.0, from a full audit of the plugin, the author-facing
docs (including a fresh-eyes read by someone new to the project), and the roadmap
docs. This is the working charter for the v0.17.0 cycle.*

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
  readable. Fifty-plus bundled adapters prove the primitives generalize across
  four different settings-schema frameworks.
- **The quality bar**: ~150 browser suites, verification on clean and
  production-scale sites, zero-console-error gates.

## What is not working (the honest list)

### Author experience

1. **The quickstart is buried.** for-plugin-authors.md opens with a 19-row hook
   table, file conventions and the compatibility section before the first win.
   A first-timer wades through `minn_admin_visibility_providers` before seeing
   the one filter they need.
2. **extension-api.md is a trap.** It is the original proposal doc, still titled
   "(proposal)", still linked from the readme, and its example uses keys that do
   not exist in the shipped vocabulary (`stats`, an old `tabs` shape). Two
   conflicting descriptor dialects, no banner.
3. **The custom-table path has no tutorial.** Most forms/logs plugins store in
   custom tables. The doc's answer is "read the Gravity SMTP adapter." The shim
   is the majority case and deserves an annotated walkthrough, not a source
   pointer.
4. **cap semantics are scattered and the reference adapters teach a different
   pattern than the doc** (surface `cap: 'read'` plus adapter-side gating, the
   GF precedent, is undocumented).
5. **No screenshots.** Authors choose `format` values, detail `kind`s and status
   layouts blind.
6. **Inconsistent icon story.** The doc lists 11 icon names; bundled adapters use
   names not on the list; editor commands describe a third vocabulary.
7. **Missing basics**: required-vs-optional per key, since-version annotations,
   i18n guidance, `manage` vs `views` differentiation, `group` value list, and a
   "test your adapter" section (the Playground blueprint exists but the author
   guide never mentions it).

### Abuse resistance

8. **No user override.** A user cannot hide or mute a plugin's surface, editor
   panel, palette entries or slash commands from Minn's UI. User sovereignty is
   the backstop for every other control and it does not exist yet.
9. **No placement or count budgets.** Any plugin can claim `group: 'workspace'`,
   register any number of surfaces, land unlimited entries in the ⌘K palette
   (every surface becomes a palette row with a plugin-controlled label) and put
   entries in the default slash menu. The "workspace is for inbox shapes"
   rule is documented convention, enforced nowhere.
10. **External links are not uniformly marked.** Descriptor `href` actions and
    status-card links can carry any URL; some render sites add ↗, but it is not
    a guarantee, so a row action can silently be an upsell link.
11. **No stated etiquette with consequences.** The docs never say what happens to
    an integration that ships promotional labels or nag-shaped surfaces.

## v1.0 gates

v1.0 ships when all of these are true:

- [ ] **G1 — Afternoon test.** A developer who has never seen Minn wires a
      custom-table plugin into a full surface (list, detail, actions, status
      card) using only the docs, in under half a day. Verified with a real
      outside tester, not just internally.
- [ ] **G2 — User sovereignty.** Every registered integration point (surface,
      panel, commands, design source) can be hidden per user from the UI, and
      the hide survives updates. *(Surfaces + editor panels shipped 2026-07-16,
      v0.17.0 cycle: `minn_admin_hidden_integrations` user meta,
      `minn-admin/v1/integrations/hide|unhide`, nav/door right-click, restore
      on Your profile. Remaining: slash-command namespaces and design
      sources.)*
- [ ] **G3 — Attention budget.** Placement and count limits are enforced by the
      validator and the client, not by convention. A plugin cannot add more than
      its budget to the nav, palette or default slash menu; overflow degrades
      gracefully (search-only, collapsed groups) instead of being dropped.
- [ ] **G4 — External-link honesty.** Every plugin-supplied link that leaves the
      site renders with the external affordance. No descriptor can make an
      upsell look like an app action.
- [ ] **G5 — Contract freeze.** The documented descriptor vocabulary is complete
      (no load-bearing undocumented keys), annotated with since-versions, and
      covered by a contract suite that drives a fixture third-party plugin
      through every documented key.
- [ ] **G6 — One docs entry point.** A single author guide that starts with the
      quickstart, includes the shim tutorial and screenshots, and has no stale
      sibling contradicting it. *(Restructure shipped 2026-07-16: quickstart
      first, shim tutorial + suite-enforced example plugin, test-your-adapter
      and AI-agent sections, capability patterns documented, canonical icon
      list, run-on cells split, since-versions, extension-api.md deleted.
      Remaining: screenshots of each primitive.)*

## v0.17.0 — the plugin-author cycle (proposed scope)

Two tracks, both in service of the gates above.

### Track A: author joy

- **Docs restructure** (G6): quickstart first; hooks table demoted to a
  reference section; split the run-on table cells; document `manage` vs `views`,
  the `group` vocabulary, cap patterns (including the adapter-side gating
  precedent as a first-class documented pattern), required-vs-optional, and
  since-versions. Add the canonical icon list (generated from the `icon()` map
  so it cannot drift).
- **The shim tutorial** (G1): an annotated walkthrough building a surface for a
  fictional custom-table plugin, start to finish, ending at the Integrations
  card showing green. Ship the finished example as a tiny real plugin
  (`docs/examples/` or a separate repo) that authors copy, and add it to the
  Playground blueprint so "try the API" is one click.
- **Screenshots** of every primitive (surface list, detail kinds, status card,
  setup gate, settings view, editor panel, slash entry) captured the same way
  the marketing shots are.
- ~~**The forms-family companion**~~ *(shipped 2026-07-16, same day as the
  re-review that asked for it: the "Make your forms plugin a first-class
  Forms provider" section — worked descriptor with per-form dynamic tabs,
  `entry-summary` semantics, the entry-kind hero/body heuristics, `manage`,
  `family` coexistence, the `href` export pattern — plus the small facts in
  their natural homes: DELETE actions, column sortability stated, `WP_Error`
  on create, older-Minn degrade behavior, `detail.labels` response shape.)*
- **Kill the extension-api.md trap**: historical banner on top pointing at
  for-plugin-authors.md (done in this cycle's audit commit), then fold the
  still-useful rationale into the author guide and delete the file.
- **Agent-ready authoring**: a short "hand this to your coding agent" section
  (or AGENTS.md) — authors increasingly wire integrations with AI tools, and
  the descriptor contract is unusually well suited to it.

### Track B: abuse resistance

- **Hide any integration** (G2): per-user hide for surfaces (nav right-click and
  a control on the Integrations card), editor panels, a plugin's palette
  entries and slash namespace. Same interaction shape as notice Hide (user meta,
  Undo toast, survives re-registration).
- **Attention budgets** (G3): enforce in `Minn_Admin_Surfaces` and the client —
  workspace placement requires an inbox-shaped collection (validator rule);
  per-plugin caps on default slash entries and palette rows with automatic
  demotion to search-only; more than N surfaces from one owner collapses into
  one nav item with the existing family switcher mechanics.
- **External-link affordance guarantee** (G4): one shared renderer for every
  descriptor href; off-site links always get ↗ and open in a new tab; the
  validator flags off-site hrefs so the Integrations card shows them.
- **Integration etiquette section** in the author guide with the enforcement
  story: what the validator blocks, what degrades, what users can hide, and the
  plain statement that labels are for naming, not marketing.

### Explicitly out of scope for v0.17.0

- New adapters and adapter-depth waves (the families are healthy; they resume
  after the cycle).
- Intent policing (no "promotional language detector"; budgets plus user
  sovereignty plus link honesty make marketing unprofitable without Minn
  judging copy).
- Multisite, native editors, and the parked items in native-editors.md.

## Roadmap corrections made during this audit (2026-07-16)

- extension-api.md marked historical (its example contradicted the shipped
  vocabulary).
- goals.md gains the user-sovereignty principle that Track B implements.
- plugin-support.md roadmap points here for the v0.17.0 cycle; adapter waves
  pause rather than disappear.
