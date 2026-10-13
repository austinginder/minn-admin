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
  render edge.
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
  to case, as MySQL matches them. A refusal answers 403
  `minn_write_fenced` and logs one line,
  `minn_write_fenced user= route= refused=`. The main network's super
  admin (`Minn_Admin::install_owner()`) is not fenced. Writes made with raw
  SQL are outside the fence, as they are outside every WordPress hook. Minn
  makes one itself: under WP Freighter, switching Minn on or off for a
  tenant edits that tenant's `active_plugins` row directly, for a caller
  with `manage_options` (Freighter's owner role), and touches only Minn's
  own entry. A refused account change is put back after core has acted on
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
- The database browser is read-only and redacts credentials three ways: by
  column name (a column named like a password, secret or token, in any
  table, which also cannot be sorted or filtered), by known vendor row
  (options and user meta that hold keys, tokens or license answers, and
  copies of those tables such as `wp_usermeta_backup`), and by value (a
  cell holding a copy of a redacted credential, raw or base64-encoded,
  under any name). Value searches leave those rows out, long text, binary
  and JSON columns are not sort keys, and a value search counts at most
  1,000 rows. On a site holding too many credentials to check (more than
  about 200), value searches are refused and every cell of 16 bytes or more
  is withheld rather than printed unchecked. Activity-log tables are shown
  as their own screens show them. WooCommerce's own tables (orders,
  addresses, sessions, and its logs when it keeps them in the database) are
  left out for anyone without `manage_woocommerce`, as WooCommerce's log
  screen is; store data a site keeps in core's tables (orders in the posts
  table when High-Performance Order Storage is off, customer addresses in
  user meta) stays readable to whoever may use the browser.
- On a network, Minn's network routes read network capabilities on the
  main site, as core's Network Admin does, so a network capability granted
  through one subsite's role counts for nothing. On an install with several
  networks (WP Multi Network), install-wide tools (the database browser,
  the logs, the wp-config switches, backup archives and migrations and
  their connection keys) belong to the main network's super admins.
- Hiding a plugin's screens because a menu tool (White Label CMS) hid its
  wp-admin menu is presentation only; the plugin's routes keep their own
  permission checks. It follows what Minn last saw in wp-admin for that
  user, so it starts with the first capture after the app opens, never
  reaches a user whose dashboard redirects or fails before the capture
  runs, and lapses a week after the last capture that came back. System ›
  Integrations leaves out the surfaces it hides.
- A browser test suite (430 suites at the time of writing, beside 29 PHP
  suites) includes an enforced zero-external-requests invariant for the
  app chrome.
