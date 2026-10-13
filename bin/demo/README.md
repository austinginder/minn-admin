# Plugin catalog and live demo

minnadmin.com lists every plugin Minn integrates with and, for the plugins
that have their own screen in Minn, opens that screen in a **live demo**: the
real `app.js` running in the visitor's browser on recorded sample data, with no
WordPress behind it. This folder holds everything that produces it. The
marketing theme only reads what `build.js` writes into its `demo/` folder.

| File | Holds |
|---|---|
| `catalog.json` | Every plugin by category: name, home page, the adapter files that serve it (`includes/adapters/`), and `surface`, the surface its demo opens. `shared` lists adapter files that serve Minn itself rather than one plugin. One plugin per line |
| `record.js` | Signs in to a dev site, captures the boot payload, then walks the overview, a few core views and every catalog surface active there the way a visitor would click: each view, tab and filter, the first rows of each list (a row opens its own page), the next page. Keeps every API GET and upload image, scrubs, then refuses to write if anything identifying survives |
| `replay.js` | Loads before `app.js` in the demo page: answers the app's API calls from the recording and refuses writes |
| `build.js` | Writes `app.js`, `app.css` and fonts from a git ref, plus `replay.js`, the recording and the catalog (with `demo: true` per recorded surface), into `<theme>/demo/`. The recording splits: `data.js` holds the boot payload and what several views share, `data/<view>.json` what one view alone asked for |

`tests/catalog.test.js` keeps the catalog honest: every adapter file is in it,
every file it names exists, each `surface` is registered by that plugin's own
adapter files, and every adapter that registers a surface has one linked.

## A new adapter

Add its plugin to `catalog.json` (with `surface` when it registers one) in the
same commit as the adapter, then run `node tests/catalog.test.js`. The demo
picks it up at the next recording if its plugin is active on the recording
site.

## Record and build

```bash
# from the plugin root, against minnadmin.localhost (fixture data only:
# never record a site that holds real people's content)
MINN_TEST_PASS=… MINN_DEMO_SCRUB=~/minn-demo-scrub.json node bin/demo/record.js /tmp/minn-demo
node bin/demo/build.js /tmp/minn-demo ../../themes/minn-admin-theme
```

Three browsers walk at once (`--jobs=N` changes that). `--only=gravity-forms,content`
records just those views, to try the walk on a few; `MINN_DEMO_TRACE=1` prints
each tab, row and return as it goes. Every write the app tries during the walk
is refused in the browser (opening an entry would otherwise mark it read), so
recording never changes the site. The one kind of POST let through is an email
preview (a route ending in `/preview`), which only renders.

The walk, per view: open the first four rows of the default list, one by one,
and come back (so a page's next and previous lead somewhere); turn to the next
page; then click each other tab and filter on its own, and the first two tab
groups together when that makes 12 lists or fewer, opening the first row each
shows that the walk has not opened yet. A surface's view switcher (Entries,
Forms, Notifications…) repeats all of that per view. Tabs inside a row's own
page are left alone: they are mostly form controls, and changing one leaves the
page asking to save. A view gets five minutes before its walk stops.

`MINN_DEMO_SCRUB` is an optional JSON file of literal replacements
(`{ "real@person.example": "jordan@example.com" }`) for fixture rows that carry
a real person's details. Keep it outside the repository. The recorder already
replaces the site's host and path, Gravatar URLs, nonces and the session
nonce, strips image metadata (WordPress thumbnails keep a phone photo's camera,
time and GPS position), and hides every stored credential: it reads the site's
options through WP-CLI and takes each value kept under a name that says
password, token, secret, key or licence (also inside serialized and JSON
options) and shaped like one, plus the keys and salts in `wp-config.php`. A
Disembark view, for one, prints its connect command with the site's token.
Those values stay in memory and show as dots in the demo. It stops without
writing when a `.localhost` host, a filesystem path, a Gravatar hash, a nonce,
one of those credentials or any email outside the reserved domains
(`example.com`, `.org`, `.net`, and any `.test`, `.example` or `.invalid`)
remains. A refused run keeps its unscrubbed capture at `<out-dir>.raw.json`:
add the mapping, then `node bin/demo/record.js <out-dir> --from-raw` scrubs it
again without recording. That file holds the site's real data; it is deleted
once a scrub passes.

A run on a loaded dev box retries each view once (after waiting for the
server to answer again) and relaunches Chrome if it dies. Views that still
fail are left out and get no demo link; when more than a quarter fail, the run
writes nothing and the last good recording stands.

`build.js` takes `app.js`, `app.css` and the fonts as committed at a git ref
(`HEAD`, or `--ref=v0.45.0` at release), never from the working tree, so
unfinished work never reaches the site. It refuses a recording made by a
different version than that ref, so the demo always runs the app build its
data came from. Commit the theme's `demo/` folder and deploy the theme as
usual.

## What the demo does and doesn't do

- Reads replay from the recording: the exact URL first, else the recording
  of the same route whose query shares the most with it, so a search shows
  the list it searched and a combination of tabs the walk skipped shows the
  nearest one. List totals (`X-WP-Total`, `X-WP-TotalPages`) replay with them.
- Writes answer "This is a demo, so nothing is saved." Email previews answer
  with the recorded one.
- A view's own file loads the first time the app asks for one of its routes,
  so opening the demo on one plugin doesn't download every other plugin's
  pages.
- Links into the recorded site (wp-admin, front-end pages) point at
  `example.com` after scrubbing; clicks on them stay in the demo with a note.
- A view the recording never visited says it is not part of the demo.
