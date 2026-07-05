# Browser tests

Plain node scripts driving a real Chrome via `playwright-core` — no test framework, no
build step, matching the plugin's architecture. Each suite is self-contained: it creates
its own posts through the app's REST credentials and deletes them on exit.

## Run

```bash
cd tests && npm install          # once — installs playwright-core only
MINN_TEST_PASS=<admin password> node markdown.test.js
MINN_TEST_PASS=<admin password> node autosave.test.js   # slow (~90s) by design

# all suites
for f in *.test.js; do MINN_TEST_PASS=… node "$f" || break; done
```

Environment (all optional except the password):

| Var | Default |
|---|---|
| `MINN_TEST_URL` | `https://minnadmin.localhost` |
| `MINN_TEST_USER` | `admin` |
| `MINN_TEST_PASS` | — required |
| `MINN_TEST_CHROME` | macOS system Chrome path |

## Conventions (read before writing a suite)

- **Use `helpers.js`** — `launch()` (system Chrome, cert + HTTP/2 flags), `login()`,
  `createPost()`/`deletePost()` (REST via the app's own `window.MINN` nonce),
  `openEditor()` (with retries), `freshParagraph()`, `reporter()`.
- **Zero console errors is a standing assertion.** `reporter.done()` fails the run if the
  page logged any error other than resource 404s. Never weaken this.
- **Self-contained fixtures.** Create posts in the test, delete them at the end — even on
  the local dev site. Never depend on existing content or hardcoded post IDs.
- **Expect flakes, retry loads.** Editor loads intermittently fail right after server
  churn (a wp-cli run, a PHP edit). `openEditor()` retries; if a whole run fails at load,
  run it again before debugging.
- **Assert through real input.** Drive `page.keyboard` / `page.mouse`, not DOM mutation —
  the contenteditable quirks (whitespace rebalancing, block merges, selection loss) only
  reproduce under real events. Setting the caret via `page.evaluate` is fine; the
  keystroke that follows must be real.
- **innerHTML shows entities.** A boundary space may serialize as `&nbsp;` in innerHTML
  reads — match with `(?:&nbsp;| )`, not a character class.
- **Verify what got SAVED, not just the DOM.** For serializer-touching changes, ⌘S in the
  test and check the stored markup (`wp post get <id> --field=post_content` and/or
  `parse_blocks()` via `wp eval`) — the DOM lying is exactly the bug class these tests
  exist to catch.

## What's covered vs. not (yet)

Covered here: markdown typing rules, autosave semantics. The v0.5.x cycle also verified
(in session scratchpads, worth porting on next touch): inline-code boundary escape, slash
menu filtering, table/image chips + ops, island backspace guards, image delete/undo,
embed render pipeline, backup-restore notice, alignment, link popover. When you fix a bug
in any of those areas, port its scratchpad suite into this directory as part of the fix.
