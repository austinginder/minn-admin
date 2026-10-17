# Security policy

Minn Admin is an admin surface: it manages content, users, plugins and
settings on real sites, so security reports get priority over everything
else.

## Reporting a vulnerability

Email **security@minnadmin.com**. You can expect an acknowledgment within
48 hours and a fix or a concrete timeline within a week for anything
exploitable. Credit is yours unless you ask otherwise; coordinated
disclosure timing is negotiable and reasonable.

Please include the Minn Admin version, the role of the user in your
reproduction (admin-only issues are still issues, but capability context
changes severity), and steps or a request trace.

## Supported versions

The latest release. Minn ships small releases frequently and the built-in
updater verifies each download against a published sha256, so staying
current is cheap; fixes are not backported.

## Scope

The `minn-admin` plugin: its REST routes, the admin app, bundled adapters
and the self-updater. Out of scope: the minnadmin.com website, third-party
plugins that integrate with Minn (report those to their authors; if Minn's
handling of their data is the problem, that part is in scope), and issues
requiring an already-compromised administrator account.

## Design notes for reviewers

A few properties worth knowing before auditing (details in
[docs/goals.md](docs/goals.md) and the code):

- The app gate requires a logged-in user with `edit_posts`; every REST
  route carries its own server-side `permission_callback` on top of that.
- Third-party plugins integrate as data descriptors only. Their HTML/CSS/JS
  never reaches the app and their PHP never prints into Minn's render paths
  (the one place it runs, a check of which blocks render cleanly enough to
  offer for insertion, discards the output); values are escaped at the
  render edge. Preview CSS that exists only once a page has run comes from a
  sandboxed copy of that page: an opaque origin with no cookies, no storage
  and no reach into the app, which can still load the page's scripts and
  stylesheets. A published page is fetched as a visitor sees it; only a
  draft's preview carries the editor's session. Only stylesheet URLs and CSS
  text come back from it.
- When a shim must read third-party serialized storage, it uses a bounded
  parser or constrained decoding, validates the resulting shape, and does
  not instantiate arbitrary classes. An exact vendor class allowlist is
  used only where the vendor API requires its own value objects. Shim SQL
  is prefix-scoped and prepared.
- Updates install only after the downloaded zip's sha256 matches the value
  published in the release manifest. That pin is only as strong as the TLS
  on the manifest fetch, which is why the manifest URL is pinned in code and
  why the dev-mode flag that relaxes certificate checking is refused on a
  site whose environment type is production, and never applies to the
  package download.
- Maintenance mode holds back the front end (including form posts that
  plugins process once the request is routed, from `parse_request`
  priority 15 on, such as Contact Form 7 and Gravity Forms; a plugin that
  answers earlier, on `init`, `wp_loaded` or `parse_request` below 15, is
  not held: Formidable's form posts, LatePoint's booking route and JetTabs'
  template requests, for example), feeds,
  the REST API, admin-ajax, admin-post, XML-RPC, comment and trackback
  posting, signup and activation. Signing in (`wp-login.php`, or a login
  screen a plugin has moved), `wp-cron.php` and WooCommerce's payment
  callbacks (`?wc-api=` or `/wc-api/`) are deliberate exemptions: you have
  to be able to log in to a site you are staging, scheduled work should keep
  running behind the holding page, and a payment provider's callback about
  an existing order must not be lost.
- Writes that would hand over the site are fenced under every
  `minn-admin/v1` request, whatever the route's own gate let through
  (`includes/class-minn-admin-fences.php`): the registration, default-role,
  admin-email, address and upload-path options, active plugins and the
  theme, role definitions, network settings, a role or capability grant or
  removal that carries power, and a powerful account's login, password,
  email or password-reset key each need the caller's own capability, the
  one WordPress asks on its own screens. Names are matched without regard
  to case or trailing spaces, as MySQL matches them. A refusal answers 403
  `minn_write_fenced` and logs one line,
  `minn_write_fenced user= route= refused=`. The main network's super
  admin (`Minn_Admin::install_owner()`) is not fenced. Writes made with raw
  SQL are outside the fence, as they are outside every WordPress hook.
  Minn's own raw writes touch no fenced option: under WP Freighter,
  switching Minn on or off for a tenant edits that tenant's
  `active_plugins` row directly, for a caller with `manage_options`
  (Freighter's owner role), and touches only Minn's own entry; a refused
  licence paste puts back the licence rows the vendor's call changed, and
  a licence change clears the licence check caches some vendors keep in
  options. A refused account change is put back after core has acted on
  it, so a listener on `wp_set_password` has already run and the "your
  password or email changed" notice may still go out; no Minn route below
  `edit_user` reaches that write, so the fence there is a backstop. A role
  grant is judged by what the role can do when it is granted; making that
  role more powerful later is a role-definition write, which is fenced. A
  network setting deleted under another spelling of its name is refused and
  logged but only the super admins and network-active plugins can be put
  back, since core fires no hook before that delete. The fence guards the
  stored value, not code that runs before it: activating a plugin loads it
  and switching a theme fires its hooks first, so Minn's routes that do
  either ask the activation capability themselves (a plugin update leaves a
  plugin the update switched off inactive for a caller who may not activate
  plugins, and says so).
- No credential the database browser would redact leaves in a `minn-admin/v1`
  response, whatever route built it (`includes/class-minn-admin-egress.php`).
  The response is checked as it is sent, as JSON text, so keys, nested
  objects and links count too: every copy of a credential, raw,
  JSON-escaped, URL-encoded or inside a base64 token, becomes
  `[redacted:<fingerprint>]`, and the response logs one line,
  `minn_egress_redacted route= count=`. A route that hands a credential to its
  owner on purpose (the WP Migrate connection key, the Disembark command,
  a one-time login link) names that exact value, which passes in that
  response only. A save that sends a redacted value back gets the real one
  restored, but only into the place in the option that held it (the same
  keys down to it) and only where the browser still counts it as a
  credential, so a screen that showed it cannot overwrite it; a token
  anywhere else refuses the save (400) and the stored value stays. Every
  other store (snippet code, posts, table rows, files) has no restore: its
  routes treat a field sent back exactly as the read served it as
  unchanged, and refuse a write that would store a token, with two
  exceptions: a kernel edit action passes the body to its adapter without
  that compare, and an editor field panel (ACF, Meta Box, Pods, ACPT,
  JetEngine) skips an untouched value but writes an edited one, or a
  repeater edited beside a masked row, as sent. Because a field served
  masked is compared against the real value, a save's 200 or 400 tells
  whether that field holds a given masked key. An export file
  that would carry a credential is refused rather than handed out with the
  token in it, keys holding a "/" included however the JSON escapes it. The transients list hides a value whose whole stored form
  the browser would redact or withhold, before cutting it short. A copy in
  another case, in hex, cut short or split across two values is not
  caught, as it is not in the browser either. Keys shorter than 16
  characters (Akismet's 12-character key, short CleanTalk keys, WP
  Rocket's 8-character key) are hidden by their row's name only: the
  database browser redacts those rows and the licence readers report them
  as present or absent, but the fence does not search for their values,
  since a needle that short would match ordinary text.
- The database browser is read-only and redacts credentials three ways: by
  column name (a column named like a password, secret or token, in any
  table, which also cannot be sorted or filtered), by known vendor row
  (options and user meta that hold keys, tokens or license answers, and
  copies of those tables: a table counts as a copy of user meta, network
  options or post meta when it has `user_id`, `site_id` or `post_id` beside
  `meta_key` and `meta_value`, and of options when it has `option_name` and
  `option_value`, whatever it is called and whatever other columns it kept,
  or when its name contains the table's name, such as `wp_usermeta_backup`;
  copies of Wordfence's and Search & Filter's key stores, of Wordfence's
  two-factor secrets and of OttoKit's request log keep the live table's
  redaction, and a plugin's own table that happens to match only has those
  credential rows redacted), and by value (a
  cell holding a copy of a redacted credential, raw or base64-encoded,
  under any name). Value searches leave those rows out, long text, binary
  and JSON columns are not sort keys, and a value search counts at most
  1,000 rows. On a site holding too many credentials to check (more than
  about 200), value searches are refused and every cell of 16 bytes or more
  is withheld rather than printed unchecked. A table whose plugin keeps the
  viewer out of its own screen is left out for them, as Minn's own view of
  that plugin is: each log and store read through the kernel (its view
  gate and row scope), and WP Activity Log's, Simple History's, Stream's,
  Activity Log's, PW Gift Cards' and WP Mail Logging's tables, copies
  included, also while the plugin is inactive, and Gravity Forms' entries
  and payment add-on records, Burst's, Koko's, WP Statistics',
  Independent Analytics', Matomo's and AnalyticsWP's statistics, Rank
  Math's 404 and analytics tables, Redirection's logs and 404s (each at
  its own capability) and Solid Security's tables, which follow their
  plugins' own rules. A table is shown only to someone the resource shows
  every column of it, so a plugin's store whose rows mask a column for
  some roles (a gift card code, a mail body, booking meta) is left out for
  them, with its copies and other tables, and a health check names no
  table the viewer may not open. A table holding a list its
  plugin keeps to the viewer's own rows (Amelia providers, LatePoint agents,
  JetBooking vendors) is shown only to someone that list shows everything,
  and so are the tables the list reads. A plugin's other tables holding the
  same data (entry fields, payments, booking and log meta) follow the rule
  of the list they belong to. Copies count by name, and Stream's also by
  their columns. The activity-log tables that are shown are shown
  as their own screens show them. WooCommerce's own tables (orders,
  addresses, sessions, and its logs when it keeps them in the database), and
  copies of them (a name containing `woocommerce`, or `wc` with no letter
  directly before or after it, such as `wp_bak_wc_orders`), are left out for
  anyone without `manage_woocommerce`, as WooCommerce's log
  screen is; store data a site keeps in core's tables (orders in the posts
  table when High-Performance Order Storage is off, customer addresses in
  user meta) stays readable to whoever may use the browser, and so do
  wpDiscuz's own `wc_` tables.
- A field panel serves and accepts only what the plugin's own screen shows
  that user (location rules, box visibility, field permissions, a
  relational field's own post types and taxonomy). A value the plugin
  refuses is reported on the post's own answer with its status kept,
  never by ending the request. A preview made from a real entry fills the
  submitter's account fields only as far as the vendor's own entry screen
  shows them, and their profile only for someone who may list users.
- On a network, Minn's network routes read network capabilities on the
  main site, as core's Network Admin does, so a network capability granted
  through one subsite's role counts for nothing. On an install with several
  networks (WP Multi Network), install-wide tools (the database browser,
  the logs, the wp-config switches, backup archives and migrations and
  their connection keys) belong to the main network's super admins.
  Deleting a site, network plugin and theme switches (the site's own plugin
  switch included, when it would act network-wide), and network
  administrator changes need the network's own super admin, and are
  refused before anything runs.
- Hiding a plugin's screens because a menu tool (White Label CMS) hid its
  wp-admin menu is presentation only; the plugin's routes keep their own
  permission checks. It follows what Minn last saw in wp-admin for that
  user, so it starts with the first capture after the app opens, never
  reaches a user whose dashboard redirects or fails before the capture
  runs, and lapses a week after the last capture that came back. System ›
  Integrations leaves out the surfaces it hides.
- A browser test suite (443 suites at the time of writing, beside 62 PHP
  suites) includes an enforced zero-external-requests invariant for the
  app chrome.
