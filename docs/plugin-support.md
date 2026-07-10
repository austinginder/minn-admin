# Plugin support in Minn Admin

Minn works on any WordPress site; classic wp-admin always stays one click
away for anything Minn doesn't surface. On top of that baseline, Minn ships
**adapters** that bring specific plugins into the Minn UI natively. Every
adapter is a thin, read-mostly shim: it reaches into a plugin's data through
the plugin's own API or a prefix-scoped query, never runs foreign PHP inside
Minn's UI, and never unserializes third-party blobs. Plugin authors can add
their own coverage through the documented filters (see `for-plugin-authors.md`)
without Minn shipping code.

This page is the map of what's covered today. "Surface" = a nav item;
"panel" = a card in the editor sidebar; "provider" = feeds an existing
shared view; "action" = a ⌘K / menu command.

## Coverage at a glance

| Area | Plugins | How it shows up |
|---|---|---|
| **SEO** | Yoast, Rank Math, AIOSEO, SEOPress | Editor panel (title, meta description, focus keyword) |
| **Forms** | Gravity Forms, Fluent Forms, Elementor Pro, Contact Form 7 (via Flamingo), CFDB7 | **Forms** surface — entries as contact cards |
| **Email log** | Gravity SMTP, FluentSMTP, WP Mail SMTP, Post SMTP | **Email Log** surface — sent mail, resend |
| **Redirects** | Redirection, Safe Redirect Manager, Simple 301 Redirects | **Redirects** surface — list + in-place edit |
| **Activity log** | Simple History, WP Activity Log, Aryo, Stream, **Wordfence** | **Activity Log** surface (Wordfence = login security) |
| **Snippets** | Code Snippets, WPCode, FluentSnippets | **Snippets** surface — list, toggle, edit |
| **Analytics** | Koko, WP Statistics, Burst, Independent Analytics, AnalyticsWP, **Site Kit** | Overview **Traffic** chart |
| **Backups** | UpdraftPlus, Disembark | **Backups** surface; health check + "Back up now" (UpdraftPlus); status card, CLI command, sessions + cleanup (Disembark) |
| **Caching** | Kinsta, LiteSpeed, WP Super Cache, W3TC, WP Rocket, WP Fastest Cache, SiteGround, Autoptimize, WP-Optimize, Cache Enabler, Hummingbird, Elementor CSS | **Clear site cache** action (⌘K) |
| **Custom fields** | ACF (+ Pro) | Editor panel |
| **Ecommerce** | WooCommerce | **Orders** surface + Overview stats |
| **Spam filtering** | Akismet, Antispam Bee, CleanTalk | Settings → Spam provider cards; open via `minn_admin_spam_providers` |
| **Page builders** | Elementor, Beaver Builder, Brizy, Divi, Bricks, WPBakery, Etch | Detected, fenced, "Edit in ⟨builder⟩" |
| **Block libraries** | Stackable, Kadence, GenerateBlocks | Design library in the editor's Browse-all; open to any plugin via `minn_admin_design_sources` |
| **Block previews** | Otter, Essential Blocks, Spectra, Kadence, GenerateBlocks, Stackable | Real front-end styling in island previews |
| **Dev tools** | Query Monitor | QM panel on Minn pages |

Beyond the named plugins: any plugin's standalone dynamic blocks and
registered patterns appear in the editor automatically (no adapter), and
any plugin's **admin notices** are extracted into Minn's notification
panel. Third-party analytics, cache, forms and other plugins can register
themselves through the extension filters.

## Notes and limits

- **One provider per family shows at a time.** The Email Log, Redirects,
  Activity Log and Snippets surfaces collapse multiple plugins into one nav
  item with a provider switcher when more than one is active.
- **SEO is one plugin at a time**, in install-base order (Yoast → Rank Math
  → AIOSEO → SEOPress); the first active one wins. SEO *scores* and content
  analysis stay in wp-admin.
- **Backups**: restores stay in wp-admin (surgery, not daily work); Minn
  lists sets, reports freshness, and triggers a new backup.
- **Disembark is a connector, not a scheduler.** Backups are pulled off-site
  by its CLI (or disembark.host), and the site keeps no record that a pull
  completed, so Minn never claims freshness for it. The surface shows the
  backup profile (last scan, database size, working files on disk), hands
  over the exact `disembark connect` command (also in ⌘K as "Copy Disembark
  backup command"), lists scan sessions, and cleans up the whole-site
  archives sessions can leave in uploads. The scan itself runs from
  Disembark's own UI or CLI.
- **Contact Form 7 stores nothing itself** — entries need a storage plugin.
  Minn covers both popular ones: Flamingo (spam/unspam and trash through
  Flamingo's own handlers, CF7 forms in the Manage view) and CFDB7 (entries
  parsed from its serialized rows without ever running `unserialize`,
  open-marks-read, permanent delete). Building forms stays in CF7's editor.
- **Page builders** that store content outside `post_content` (Elementor,
  Beaver, Brizy, Bricks, WPBakery) open read-only in Minn's editor with an
  "Edit in ⟨builder⟩" button; block-native builders (Etch, Divi 5) stay
  editable through the island system.
- **What Minn deliberately doesn't reimplement**: form builders, SEO score
  UIs, firewall/scan config, cache plugin settings pages, builder canvases.
  Those are each plugin's product; Minn links out.

## Roadmap candidates

Ranked by install base among plugins not yet covered:

- **One-place license activation** — paste a Pro plugin's key once and Minn
  hands it to the right plugin, so the "activate your license" nags clear
  without a wp-admin scavenger hunt. Genuinely valuable but genuinely hard
  (no shared license API across vendors); full design in
  `docs/license-manager.md`.
- **WPForms** entries — Pro-gated storage; needs a Pro license for fixtures.
- **Really Simple SSL, iThemes/Solid Security** — security posture on the
  System page.
- **Jetpack** — module-dependent; large surface, needs scoping.
- **Multilingual (WPML / Polylang / TranslatePress)** — needs a language
  dimension in content lists; parked as structural.
- **Consent/GDPR, popups, sliders, image optimization** — mostly config
  UIs; the notice digest, Extensions cards and link-outs are the honest
  answer there rather than a bespoke surface.

See `docs/for-plugin-authors.md` to add coverage from your own plugin, and
`docs/extension-api.md` for the surface/panel/provider contracts.
