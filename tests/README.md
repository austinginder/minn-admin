# Browser tests

Plain node scripts driving a real Chrome via `playwright-core` — no test framework, no
build step, matching the plugin's architecture. Each suite is self-contained: it creates
its own posts through the app's REST credentials and deletes them on exit.

## Run

```bash
cd tests && npm install          # once — installs playwright-core only
MINN_TEST_PASS=<admin password> node markdown.test.js
MINN_TEST_PASS=<admin password> node autosave.test.js   # slow (~90s) by design
MINN_TEST_PASS=<admin password> node paste.test.js      # Word/Docs/web fixtures → saved markup
MINN_TEST_PASS=<admin password> node localnet.test.js   # crash net: snapshot → hard-leave → recover
MINN_TEST_PASS=<admin password> node lock.test.js       # slow (~60s): two sessions ride the 30s lock refresh
MINN_TEST_PASS=<admin password> node media-flow.test.js # paste/drop image files → library; inline captions
MINN_TEST_PASS=<admin password> node etch-text.test.js  # SKIPs unless Etch is active (run on etch.localhost)
MINN_TEST_PASS=<admin password> node editor-sidebar.test.js # slug, discussion, visibility, sticky (~20s)
MINN_TEST_PASS=<admin password> node system.test.js     # diagnostics endpoint + page + copy-report
MINN_TEST_PASS=<admin password> node undo-toast.test.js # structural-deletion Undo (islands, tables)
MINN_TEST_PASS=<admin password> node order-layout.test.js # order detail layout: columns, dialogs, items editing
MINN_TEST_PASS=<admin password> node order-filters.test.js # list filter bar: chips, server-side query, URL round trip
MINN_TEST_PASS=<admin password> node subscription-filters.test.js # the same bar on subscriptions (SKIPs without WCS)
MINN_TEST_PASS=<admin password> node subscription-page.test.js # /subscriptions/{id}: items + schedule editing, GMT dates
MINN_TEST_PASS=<admin password> node yith-gift-cards.test.js # gift cards (YITH): buys one for a fixture, issues one by hand (SKIPs without YITH)
MINN_TEST_PASS=<admin password> node woocommerce-gift-cards.test.js # gift cards (official WC): issues one by hand (SKIPs without the plugin)
MINN_TEST_PASS=<admin password> node pw-gift-cards.test.js # gift cards (PW): activates Pro if inactive, issues one by hand (SKIPs without PW)
MINN_TEST_PASS=<admin password> node woocommerce-memberships.test.js # memberships (WooCommerce Memberships): grants one to a disposable customer, walks every verb (SKIPs without the plugin)

# lab seeders (NOT suites: they leave what they create behind, so point them
# at a throwaway site). seed-gateways enables cheque/bacs/cod so the payment
# picker has entries on a fresh WooCommerce; seed-orders creates one order per
# status for walking the order detail by hand.
MINN_TEST_PASS=<admin password> node seed-gateways.js
MINN_TEST_PASS=<admin password> node seed-orders.js

# multisite (network) — runs against a SEPARATE subdomain-multisite lab, not
# minnadmin. SKIPs (exit 0) with no MINN_MS_SUPER_PASS or no lab, so run-all is
# unaffected on the single-site dev box. Create the lab first:
#   cove add minnms --multisite=subdomain   (see project_minn_multisite_lab)
MINN_MS_SUPER_PASS=<lab super-admin password> MINN_MS_WP=<the network's root path> node multisite.test.js
MINN_MS_SUPER_PASS=<lab super-admin password> node wp-multi-network.test.js

# all suites (release pre-flight / overnight): sequential, settle-guarded,
# one retry per failed suite, full logs + summary in the output dir
MINN_TEST_PASS=… ./run-all.sh /tmp/minn-run

# every PHP suite (wp eval-file), beside run-all.sh in the release pre-flight:
# single-site ones on the site this checkout sits in (or MINN_PHP_SITE),
# network ones on a multisite lab (SKIPPED without one), full logs + summary
# in the output dir
MINN_PHP_NETWORK=<network root> MINN_PHP_SUBSITE=https://store.<lab>/ \
  MINN_PHP_NET2_URL=https://lab2.<lab>/ MINN_PHP_NET2_USER=<its super admin> \
  ./run-php.sh /tmp/minn-php

# the round-trip harnesses (what each kind of save really stores); see below
MINN_TEST_PASS=… ./roundtrip.sh

# the NEXT WordPress (release pre-flight): updates a dedicated Cove site to
# whatever core is ahead of stable, then runs the core-coupled suites there
MINN_TEST_PASS=minn-core-latest-1 ./core-latest.sh /tmp/minn-core-latest
./core-latest.sh --resolve      # print the target core version and exit
./core-latest.sh --list         # print the core-coupled suite list
```

Relaunching `run-all.sh` with the same output dir **resumes**: suites already
recorded PASS are skipped, failures and unrun suites run again. Two things worth
knowing before you believe a full-run failure:

- **The first suite alphabetically pays the cold-start tax.** It runs against a
  cold site (empty caches, the full boot burst) and can miss timing windows that
  pass everywhere else. On 2026-07-23 `a11y-chrome` failed twice cold, on a focus
  check with an 800ms window, then passed 26/26 warm with no code change. Re-run a
  first-position failure warm before treating it as a regression.
- **A long run outlives a terminal.** For an overnight run, detach it
  (`nohup ./run-all.sh <dir> &`) so it survives the shell that started it; stop it
  with `pkill -f run-all.sh`.

## Round trips (`roundtrip.sh`)

A save is only as good as what it stores. `roundtrip.sh` runs the four round-trip
harnesses in order, each replaying what the real client sends for one kind of write and
checking what got stored: a value typed reads back as typed, a save that changes nothing
stores nothing, and a change to one field leaves the rest.

1. **Panels** (`roundtrip-panels.test.php`): every editor field panel, per post type,
   for fidelity, no-op, isolation, a partial body that names one field, and a change
   made elsewhere surviving this page saving another field.
2. **Settings** (`roundtrip-settings.test.php`): every surface settings tab. These are
   live options on a shared lab, so each one is backed up raw and put back after every
   tab, and on any exit.
3. **Surface edit dialogs**: `roundtrip-surfaces-capture.js` opens each surface's first
   item in the real app, presses Save without touching anything and records the request
   (answered in the browser, so it never reaches the server); `roundtrip-surfaces.test.php`
   replays the bodies for no-op, isolation and concurrency.
4. **Minn's own pages**: `roundtrip-pages-capture.js` does the same for settings
   sections, the builders, the email pages, membership plans, orders, products, users
   and store settings, and the same PHP harness replays them. A page whose clean save
   sends anything fails, unless it proves freshness with a stamp (409 on a stale copy).

Passes 3 and 4 replay inside a database transaction that is rolled back, so the lab's
items end exactly as they started. Each harness keeps a ratchet of open findings in its
`$known` list: a listed failure reports KNOWN and does not fail the run, one that starts
passing fails until its line is removed, and an unlisted failure is a regression, so the
list only shrinks. Narrow a run with `MINN_RT_PANEL=<panel id>` (panels) or
`MINN_RT_SURFACE=<surface id>` (settings); `MINN_RT_VERBOSE=1` prints the stored values
around each replay's first difference, and `MINN_RT_DEBUG=1` explains skipped panel
concurrency pairs. `MINN_RT_CAPTURE` names the capture file a replay reads; the runner
sets it. `run-php.sh` skips the replay pass because it needs a browser capture first:
run `./roundtrip.sh`.

## Route gates (`route-gates.test.php`)

Who reaches each `minn-admin/v1` route is pinned in
`tests/fixtures/route-gates-single.json` and `route-gates-network.json`. The suite asks
every endpoint's permission callback as each kind of account (signed out, Subscriber
through Administrator, a Shop Manager, and on a network a site administrator and an
account holding network capabilities only through a site role) and compares. A route the
snapshot does not list fails, and so does a gate that got wider; a narrower gate, or a
route whose plugin is off, is only reported. After adding or changing a route, review
its gate, then rewrite the snapshot:

```bash
MINN_ROUTE_SNAPSHOT=write wp eval-file tests/route-gates.test.php --user=admin --path=<site>
```

The network snapshot comes from the lab's WooCommerce subsite (the one `run-php.sh`
uses, `--url=<that site>`), since accounts and routes differ from site to site. Commit
the rewritten snapshot with the route.

## The kernel (`kernel-conformance.test.php`, `route-ratchet.test.js`)

Adapters ported to the resource kernel (`docs/adapter-kernel.md`) get their routes, gates,
SQL, output and write path from it, so its checks scale with the kernel, not with
adapters. `kernel-conformance.test.php` (in `run-php.sh`) drives a synthetic resource over
a temporary table through every mechanism: no gate means no route; not found, then
forbidden, then wrong state, in that order; ids, page sizes and filters from a fixed set;
an empty scope that never widens; search and filter values that never reach SQL
unprepared; only declared fields leaving and a secret never; each field type on awkward
stored values; a write whose effect is not there never reporting success; a throw that
answers in one shape with nothing of the exception in it. It then checks every real
declaration: each action names a declared gate and a post-condition, each descriptor
action pointing at a kernel route names a declared action with its method and a state the
action accepts, and each collection agrees with the resource it reads.

`route-ratchet.test.js` holds the number of hand-written `register_rest_route` calls in
`includes/adapters/` (489 at v0.45.0) to `tests/fixtures/route-ratchet.json`: more fails,
and fewer fails until the ceiling is lowered in the same commit as the port
(`MINN_RATCHET=write node tests/route-ratchet.test.js`).

## Parity (`parity/`)

A port to the resource kernel (`docs/adapter-kernel.md`) must keep every route, method,
gate and response shape the app and its callers depend on. `parity/capture.js` records
them and `parity/diff.js` compares two captures:

```bash
node tests/parity/capture.js --out tests/.parity/before.json --only everest --writes everest-forms
# … port the adapter …
node tests/parity/capture.js --out tests/.parity/after.json --only everest --writes everest-forms
node tests/parity/diff.js tests/.parity/before.json tests/.parity/after.json --only everest
```

A capture runs as one fresh account per role (signed out, Subscriber through
Administrator, a Shop Manager, and on a network a site role holding network capabilities
and, with `--super <login>`, a super admin), each in its own WP-CLI process. It records
every `minn-admin/v1` endpoint with its methods, declared arguments, callback file and who
its gate admits; the surfaces each account is sent, by value; and the shape of each GET
the account may make: every JSON path with the types and string formats seen there
(`string:iso-local`, `string:entities`, `int`, `absent`), never a value. The requests are
the ones the app makes from the descriptors (`surfaceRoute()`: the default filter, each
other filter option, the first tab, a search, the first item's detail) plus a walk of
every GET route, its path ids taken from the parent list. `--writes` also runs those
surfaces' row and bulk actions on an item their `when` matches, chaining from a sibling
action when no row is in that state (no spam on the lab: mark one, then "Not spam").

Every request runs inside a database transaction that is rolled back, with outbound HTTP
and mail refused, so a capture leaves the lab as it found it (a GET that marks an entry
viewed is undone too). Statements that would end the transaction or cannot roll back
(DDL, LOCK, a non-InnoDB table) are refused and noted on the request. Files a route
writes are outside the transaction, which is why actions run only for the surfaces you
name. A request that kills its process is skipped and the account's pass runs again.

`diff.js` prints FAIL for anything a caller could depend on that is gone or different
(an endpoint, an argument, a gate, a descriptor value, a status class, a JSON path, a
type, a key now sometimes missing, a string format no longer sent) and REVIEW for what is
new or looser; `--accept <file>` takes a list of deliberate changes with their reasons.
Capture both sides on the same lab data, right before and right after the change: paths
under a list that was empty on one side are counted, not judged. Captures describe a lab,
so they stay in `tests/.parity/` (ignored). `parity/plant.php` is the tool's own check:
captured with `--require tests/parity/plant.php`, the diff must FAIL on each planted
change.

## The next WordPress (`core-latest.sh`)

Every other run happens on minnadmin.localhost, which tracks a RELEASED
WordPress. A core release can break the plugin in ways testing against the
current version cannot reveal, and by then the breakage is already in front of
users. `core-latest.sh` runs the core-coupled part of the suite against
whatever core is ahead of stable, on its own site.

The site is **minnadmin-core-latest.localhost** (admin / `minn-core-latest-1`).
It is deliberately NOT a copy of minnadmin: it carries core, the plugin, and
the dev-fixtures mu-plugin, nothing else. Two reasons. Adapter suites answer
"did a plugin change", which is a plugin release's problem, not core's; and the
fixture farm holds real license keys whose vendors bind seats to a domain, so
cloning it onto a second host risks burning them.

Which core WordPress it runs is resolved at run time: a beta/RC when one is in
flight (wp.org's beta channel offers a version the stable channel does not),
otherwise trunk (nightly). **When the answer is nightly it skips and exits 0.**
A nightly is trunk on an ordinary day: it moves under you, breaks and un-breaks
on its own, and a failure in it is usually core's rather than ours, so the run
that earns its place is the one against a version real sites are about to get.
Between releases this legitimately skips for weeks at a time. `--allow-nightly`
runs it anyway, `--version=6.9-RC1` pins one (an explicit `--version=nightly`
counts as intent and runs), `--no-update` runs against whatever is installed.

Recreate the site if it is ever lost, or re-provision it any time:

```bash
cove add minnadmin-core-latest nightly   # only when it does not exist
./core-latest-seed.sh                    # idempotent: safe to re-run
```

**Theme-dependent phases.** The bare site runs a BLOCK theme (Twenty
Twenty-Five); the dev site runs the classic marketing theme. A suite that
asserts what Minn does without a block theme must not assume either, so
`activateClassicTheme( page )` in `helpers.js` activates an installed classic
theme when the site does not already run one, and returns false when none is
installed so the phase can skip honestly. Capture the active theme before
calling it and restore that theme in `finally`, as the Design suites do. The
first version of those suites assumed the dev site's start state and would have
failed on the bare site for a fixture reason rather than a real one.

The seeder is where the site's fixtures live, and it is deliberately short.
A handful of core-coupled suites need content of a shape core alone does not
create: a term tree (`terms`), two attachments with known titles
(`image-swap`), a second installed locale plus a user reading it
(`language-remove`), and a nav menu with items (`menu-drag`). Everything else
each suite creates and deletes itself.

Suites that need a third-party plugin registered to have a subject at all are
listed as deliberately absent at the top of `core-latest.sh`, with the reason.
They stay covered by `run-all.sh` on minnadmin.

`MINN_TEST_WP` is not optional for this site and the script exports it: the
plugin directory is a symlink to the dev site, so the harness's default (four
levels up from `tests/`) resolves through it, and every wp-cli call in a suite
would land on minnadmin.localhost instead. The suites would pass while testing
nothing.

Environment (all optional except the password):

| Var | Default |
|---|---|
| `MINN_TEST_URL` | `https://minnadmin.localhost` |
| `MINN_TEST_USER` | `admin` |
| `MINN_TEST_PASS` | — required |
| `MINN_TEST_WP` | WordPress root (default: four levels up from `tests/`) |
| `MINN_TEST_CHROME` | macOS system Chrome path |
| `MINN_TEST_FRESH_LOGIN` | unset. Set to `1` to skip the shared cookie and form-login |
| `MINN_TEST_AUTH_DIR` | `tests/.auth` (Playwright storageState, gitignored) |
| `MINN_TEST_USER2` | `minn-editor` (lock.test.js's second session; needs Editor role) |
| `MINN_TEST_PASS2` | `minn-editor-pass-1` |
| `MINN_MS_SUPER_PASS` | — (multisite.test.js only; unset ⇒ SKIP) |
| `MINN_MS_URL` / `MINN_MS_STORE` / `MINN_MS_BLOG` | `https://minnms.localhost` / `store.` / `blog.` |
| `MINN_MS_WP` | — (multisite.test.js: the network's root, to remove the invitation its add-existing check sends; unset ⇒ a NOTE) |
| `MINN_CORE_SITE` | `minnadmin-core-latest` (`core-latest.sh` only; the Cove site name) |
| `MINN_CORE_ROOT` | `~/Cove/Sites/$MINN_CORE_SITE.localhost/public` |
| `MINN_PHP_SITE` | the site this checkout sits in (`run-php.sh`'s single-site suites) |
| `MINN_PHP_NETWORK` / `MINN_PHP_SUBSITE` | — (`run-php.sh`'s network suites: the network root and a site on it other than the main one; unset ⇒ SKIP) |
| `MINN_PHP_NET2_URL` / `MINN_PHP_NET2_USER` | — (a site on a second network and that network's super admin; unset ⇒ SKIP) |
| `MINN_RT_PANEL` / `MINN_RT_SURFACE` | unset (round trips: run one panel or one settings surface) |
| `MINN_RT_VERBOSE` / `MINN_RT_DEBUG` | unset (round trips: print stored values at a difference, explain skipped pairs) |
| `MINN_RT_CAPTURE` | `$TMPDIR/minn-surface-saves.json` (the capture a replay reads; `roundtrip.sh` sets it) |
| `MINN_ROUTE_SNAPSHOT` | unset. `write` rewrites the route-gates snapshot instead of comparing |
| `MINN_COPY_SRC` | this checkout's `minn-admin.php` (duplicate-copy: another main file, for a before run) |

## Conventions (read before writing a suite)

- **Shared login cookie.** `login()` persists Playwright `storageState` under
  `tests/.auth/` (keyed by URL + user). The next suite in the same session
  skips `wp-login.php` and the wp-admin landing and goes straight to
  `/minn-admin/`. The REST nonce is minted on that page load, so it is never
  reused stale. `run-all.sh` primes the cookie once up front. Role logins
  (`loginAs`) and anonymous contexts are unchanged. Set `MINN_TEST_FRESH_LOGIN=1`
  to force a form login.
- **Use `helpers.js`** — `launch()` (system Chrome, cert + HTTP/2 flags), `login()`,
  `createPost()`/`deletePost()` (REST via the app's own `window.MINN` nonce),
  `openEditor()` (with retries), `freshParagraph()`, `reporter()`.
- **Zero console errors is a standing assertion.** `reporter.done()` fails the run if the
  page logged any error other than resource 404s. Never weaken this.
- **Self-contained fixtures.** Create posts in the test, delete them at the end — even on
  the local dev site. Never depend on existing content or hardcoded post IDs.
- **Seed the plugin state you depend on, and restore it.** A bundled adapter registers its
  routes and its surface only while its plugin is active, so a suite that assumes a plugin
  is active fails with `rest_no_route` the moment that plugin drifts inactive, which reads
  exactly like a product regression. Read the plugin's status first, activate it if needed,
  and restore what you found in `finally` (`solid-security.test.js` and `backups.test.js`
  are the pattern; `comment-postlink.test.js` does the same for deactivating a resident).
  This is not hypothetical: the family conventions keep one provider per family active, and
  plugins get toggled by hand while chasing bugs.
- **Restore on any exit, not only in `finally`.** `finally` never runs for a killed run
  (Ctrl-C, a harness timeout) or a crash before the `try` opens, and both have left a plugin
  armed or a fixture behind. Register the restore with `onExit()` from `helpers.js` before
  arming anything, and call what it returns from `finally`: it runs once, then or when the
  process ends. It must be synchronous (WP-CLI through `execSync`), since an exit handler
  cannot wait for a promise. `rank-math-redirections.test.js`, `cleantalk-key-tail.test.js`
  and `woocommerce-memberships.test.js` are the pattern.
- **Expect flakes, retry loads.** Editor loads intermittently fail right after server
  churn (a wp-cli run, a PHP edit). `openEditor()` retries; if a whole run fails at load,
  run it again before debugging.
- **Assert through real input.** Drive `page.keyboard` / `page.mouse`, not DOM mutation —
  the contenteditable quirks (whitespace rebalancing, block merges, selection loss) only
  reproduce under real events. Setting the caret via `page.evaluate` is fine; the
  keystroke that follows must be real. Exception: paste. A synthetic ClipboardEvent with
  a DataTransfer exercises the full sanitize/insert pipeline (the handler preventDefaults
  and does its own insertion), so fixtures don't need the OS clipboard — but keep one
  real `navigator.clipboard.write` + ⌘V case per suite to prove the wiring.
- **innerHTML shows entities.** A boundary space may serialize as `&nbsp;` in innerHTML
  reads — match with `(?:&nbsp;| )`, not a character class.
- **Poll on an interval after a native dialog.** A `confirm()` or `alert()` stalls
  `requestAnimationFrame` in a background window, so a default `waitForFunction` (rAF
  polling) right after one can time out though the page already changed. Pass
  `{ polling: 500 }` (acpt-field-group-builder does).
- **A page with unsaved edits asks before you leave it.** A suite that navigates away from
  a dirty builder, settings form or entry edit must answer the "Leave without saving?"
  dialog (gf-form-settings does).
- **Run PHP through `wp eval-file`, as a user when the plugin checks caps.** Write it to a
  temp file (the shell expands `$` inside `wp eval "…"`). WPForms' form reads are
  capability-checked and return false with no current user, so call
  `wp_set_current_user( 1 )` first.
- **Seed form-plugin data the way the plugin's own flow does.** Flamingo's
  `Flamingo_Inbound_Message::add()` doesn't create the form's channel term (CF7's module
  does on a form's first submission), so create it under `contact-form-7`
  (cf7-mail.test.js). Elementor reads a submission's field types from a form snapshot kept
  in post meta (`Form_Snapshot_Repository`): hang it on a throwaway page
  (elementor-forms-entry-edit.test.js).
- **Verify what got SAVED, not just the DOM.** For serializer-touching changes, ⌘S in the
  test and check the stored markup (`wp post get <id> --field=post_content` and/or
  `parse_blocks()` via `wp eval`) — the DOM lying is exactly the bug class these tests
  exist to catch.
- **Never wait for `networkidle`.** Some plugins' admin JS polls forever (Site Kit), so the
  wait never ends. Wait for a selector, a URL or a response.
- **Read a fixture option back before navigating.** A REST-exposed fixture option written
  and then navigated away from can read stale on the next request: write it, read it back
  with a cache-buster, retry (`setOpt()` in site-kit-traffic.test.js).
- **No absolute totals.** Live tables accumulate across runs (real logins land in the same
  security logs): assert a delta, a presence or a shape.
- **Wait on the request, not the paint.** After a save, wait for its POST response. Toast
  text from the previous save lingers and satisfies the wait; a bare `.minn-modal` matches
  the loading state; flat timeouts race. `waitForFunction` takes `( fn, arg, options )`:
  options in the arg slot keep the default timeout and polling.
- **Click with the mouse when hit-testing matters.** `el.click()` dispatches straight on the
  node and passes even when the element is covered or `pointer-events: none`; use
  `page.mouse.click()` at its box.
- **Comboboxes go through `pickCombo()`.** Upgraded selects are no longer `<select>`, so
  `page.selectOption` fails.
- **Fresh contexts run the light theme.** Playwright defaults to `colorScheme: 'light'` and
  the app follows the OS until a user picks; set `localStorage['minn-theme']` or
  `emulateMedia` for dark screenshots.
- **Seeders run once and use documentation IPs.** A seeder in the fixtures mu-plugin runs
  on `init` behind an atomic `add_option` lock so concurrent requests don't double-seed,
  and any IP it writes comes from 192.0.2.0/24, 198.51.100.0/24 or 203.0.113.0/24. Never
  lock out 127.0.0.1 or a suite login.
- **Fake a remote service from outside FrankenPHP.** A plugin that calls its remote URL
  with its own transport can't be mocked through `pre_http_request`, and a fake served by
  the same site deadlocks the worker pool (each request holds a worker waiting on its
  loopback). Run the fake as a Node `http.createServer` on 127.0.0.1
  (connect-matomo-traffic.test.js). `wp eval` holds no worker, so it passes against an
  in-site fake that wedges real requests.

## Debugging a flaky or slow suite

- **Find who re-rendered.** Wrap `Element.prototype.innerHTML`'s setter to log
  `new Error().stack` for swaps of `#minn-view`, and wrap `window.fetch` the same way: the
  stack names the call site even through promise chains.
- **Find a slow REST write.** A temporary mu-plugin timing `rest_pre_dispatch` /
  `rest_post_dispatch`, with an `http_api_debug` URL log, shows outbound loopbacks at once.
- **Read request DONE times, not starts.** A save can sit behind a fresh session's boot
  requests on the HTTP/1.1 connection pool and look slow while the server answered in a
  second.

## What's covered vs. not (yet)

Covered here: markdown typing rules, autosave semantics, paste cleanup (Google Docs /
Word / web fixtures, caret-context routing, undo integrity, classic mode, real ⌘V),
post locking (blocked open → takeover → detection → take-back → release-on-leave, two
real sessions), the localStorage crash net (snapshot → hard-leave → recovery →
clear-on-save, including never-saved new posts), and inline media flow (clipboard/drop
image files → upload at caret, blob-URL serialize guard, inline captions with edge
guards). Synthetic DragEvents need `Object.defineProperty(ev, 'dataTransfer', …)` —
Chrome's constructor silently drops the init-dict member.
The v0.5.x cycle also verified
(in session scratchpads, worth porting on next touch): inline-code boundary escape, slash
menu filtering, table/image chips + ops, island backspace guards, image delete/undo,
embed render pipeline, backup-restore notice, alignment, link popover. When you fix a bug
in any of those areas, port its scratchpad suite into this directory as part of the fix.

## Editor feel benchmark (perf, not pass/fail)

`perf-editor.bench.js` measures what a writer feels — per-keystroke latency
(Event Timing: event start → next paint), long tasks while typing, and editor
open time — across three reference documents (prose, nested layout, heavy). It
is a MEASUREMENT, not a gate: it prints a table and exits 0.

```
MINN_TEST_PASS=… node perf-editor.bench.js <label>
```

The number that matters is `worst_ms` on the **nested** fixture (deep
group/columns with nested cards) — that is where editor bloat shows up first.
To answer "did this cycle slow the editor?", bench HEAD and bench a baseline by
temporarily swapping in the old asset files:

```
cp assets/js/app.js /tmp/app.js.work; cp assets/css/app.css /tmp/app.css.work
git checkout <old-tag-or-commit> -- assets/js/app.js assets/css/app.css
( cd tests && MINN_TEST_PASS=… node perf-editor.bench.js baseline )
cp /tmp/app.js.work assets/js/app.js; cp /tmp/app.css.work assets/css/app.css
```

**Recorded findings.** v0.25.0 nested worst ≈ 56ms. The v0.26.0 chip-density
pass (deepest-wins chrome) first shipped as `:has()` selectors on the editor
surface and benched at **~300ms per keystroke** on the nested fixture — real,
writer-noticeable bloat. Rewritten as an event-driven marker class
(`.minn-island-covered`, set by cheap mouseover/focusin handlers), it returned
to ~56ms. **Rule that fell out: never use `:has()` in a selector that matches
inside `#minn-editor-body`.** `:has()` makes Blink re-evaluate the subject on
every descendant mutation, so it turns each keystroke into a full-subtree style
recalc. A marker class toggled by a pointer/focus handler is O(1). Run this
bench at release cut when a cycle touched editor rendering.
