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
MINN_TEST_PASS=<admin password> node editor-sidebar.test.js # slug, discussion, visibility, sticky (~20s)
MINN_TEST_PASS=<admin password> node system.test.js     # diagnostics endpoint + page + copy-report
MINN_TEST_PASS=<admin password> node undo-toast.test.js # structural-deletion Undo (islands, tables)

# all suites (release pre-flight / overnight): sequential, settle-guarded,
# one retry per failed suite, full logs + summary in the output dir
MINN_TEST_PASS=… ./run-all.sh /tmp/minn-run
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

Environment (all optional except the password):

| Var | Default |
|---|---|
| `MINN_TEST_URL` | `https://minnadmin.localhost` |
| `MINN_TEST_USER` | `admin` |
| `MINN_TEST_PASS` | — required |
| `MINN_TEST_CHROME` | macOS system Chrome path |
| `MINN_TEST_USER2` | `minn-editor` (lock.test.js's second session; needs Editor role) |
| `MINN_TEST_PASS2` | `minn-editor-pass-1` |

## Conventions (read before writing a suite)

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
- **Verify what got SAVED, not just the DOM.** For serializer-touching changes, ⌘S in the
  test and check the stored markup (`wp post get <id> --field=post_content` and/or
  `parse_blocks()` via `wp eval`) — the DOM lying is exactly the bug class these tests
  exist to catch.

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
