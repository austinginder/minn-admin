# Project goals

Minn Admin exists because the WordPress admin buries everyday work under twenty years of
accumulated chrome. These are the principles that decide what gets built and how.

## Goals

1. **Get out of the way.** The daily work — writing, moderating, checking on the site, managing
   files and users — should be one click away and visually calm. Density serves the reader, not
   the toolbar.
2. **Real replacement, not a demo.** Minn must hold up on real sites with hundreds of thousands
   of rows (it's developed against a production-scale dataset). Pagination, `_fields` allowlists
   and capability gating are non-negotiable, not optimizations.
3. **Zero lock-in.** Everything Minn writes is native WordPress: Gutenberg block markup, core
   options, core REST calls. Deactivate the plugin and nothing is lost or broken. Classic
   wp-admin remains fully available at all times — Minn is additive.
4. **No build step.** One vanilla-JS file, one stylesheet, PHP that reads top to bottom. Anyone
   can read the whole codebase in an afternoon. Frameworks are a dependency treadmill this
   project deliberately stays off.
5. **Defensive by architecture.** Never run other plugins' render paths in list contexts (a
   misbehaving plugin must not be able to take Minn down), never unserialize foreign blobs,
   check capabilities server-side, escape everything at the edge.
6. **An ecosystem invitation.** Third-party plugins integrate through one declarative filter —
   see `for-plugin-authors.md`. Minn ships adapters for popular plugins; plugin authors can ship
   their own without writing JavaScript.
7. **The user outranks every integration.** Nothing a plugin registers may grab attention the
   user did not ask for, and anything a plugin registers can be hidden or muted by the user.
   WordPress lost this fight in its notification system; Minn enforces it with architecture
   (descriptors only, extraction not hosting), budgets and per-user controls — never with
   trust in plugin authors' restraint. See `v1-readiness.md`.

## Non-goals

- **Gutenberg parity.** Complex block layouts belong in the block editor; Minn links to it
  cleanly (`editor-direction.md`).
- **Settings-page parity.** Minn surfaces the settings people actually change. The long tail
  stays in wp-admin.
- **Multisite network admin, for now.**
- **Being a page builder.**

## Quality bar

Every feature lands with: browser-level verification on a clean site *and* a production-scale
site, zero console errors, capability checks proven by the UI hiding/showing correctly, and an
Emoji-Log commit. If a feature can't be verified end-to-end, it isn't done.
