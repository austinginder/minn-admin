# Security policy

Minn Admin is an admin surface: it manages content, users, plugins and
settings on real sites, so security reports get priority over everything
else.

## Reporting a vulnerability

Preferred: **[GitHub private vulnerability reporting](https://github.com/austinginder/minn-admin/security/advisories/new)**
(the "Report a vulnerability" button on the repo's Security tab). Reports
stay private while a fix is prepared.

Email works too: **austin@anchor.host**. Either way you can expect an
acknowledgment within 48 hours and a fix or a concrete timeline within a
week for anything exploitable. Credit is yours unless you ask otherwise;
coordinated disclosure timing is negotiable and reasonable.

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
- Third-party plugins integrate as data descriptors only. Their PHP never
  runs in Minn's render paths and their HTML/CSS/JS never reaches the app;
  values are escaped at the render edge.
- Shims never `unserialize()` third-party blobs, and shim SQL is
  prefix-scoped and prepared.
- Updates install only after the downloaded zip's sha256 matches the value
  published in the release manifest.
- A browser test suite (192 suites at the time of writing) includes an
  enforced zero-external-requests invariant for the app chrome.
