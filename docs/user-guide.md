# Using Minn Admin

*A guide for the people who run their site with Minn: writing, moderating,
checking on things, keeping plugins current. No code in here. If you build
plugins, you want [for-plugin-authors.md](for-plugin-authors.md) instead.*

*Current as of v0.31.0. This file ships inside the plugin, so the copy you
are reading always matches the version you have installed.*

## What Minn is (and is not)

Minn Admin is a second front door to your WordPress site. It lives at
`yoursite.com/minn-admin/` and covers the daily work: content, media,
comments, orders, users, plugins, updates and settings, in a calm interface
with none of the banners and upsells that crowd a typical dashboard.

Three things to know before anything else:

1. **wp-admin is still there.** Minn replaces nothing. The classic admin
   keeps working at `/wp-admin/`, and Minn links you to it whenever a job is
   better done there. You can use both side by side forever.
2. **Everything Minn writes is native WordPress.** Posts are normal block
   markup, settings are normal options. Deactivate the plugin and your site
   is exactly as it was, with nothing to migrate and nothing lost.
3. **You only see what your account can do.** Minn checks the same
   WordPress permissions as wp-admin. An author sees writing tools; the
   Extensions and Settings areas only appear for accounts allowed to use
   them.

## Getting around

**The sidebar** has four groups. Empty groups stay out of the way, so a site
without a store still has the shorter navigation it needs:

- **Workspace** — publishing and incoming material: Overview, Content, Media,
  Comments, and plugin inboxes such as form entries.
- **Commerce** — store operations in a stable order: Orders, Subscriptions,
  Bookings, Customers, Products and Coupons. Only the items provided by the
  site's plugins appear. Opening an appointment takes you to its own page,
  laid out like an order: the service and time on one side, the customer on
  the other.
- **Tools** — site plumbing contributed by your plugins: mail
  logs, activity logs, redirects, backups, snippets. Plugins whose Minn
  presence is purely a settings screen, and a theme's options pages,
  gather under a single **Site Options** entry here rather than each
  claiming its own sidebar spot.
- **Manage** — the site itself: Extensions, Users, Menus and Widgets (on
  classic themes), Structure, System, Settings.

Group headings collapse when clicked, and the sidebar remembers your
arrangement. Counts on Content, Comments and Orders show pending work.
Lists show recent dates as a day and month and add the year once a date
is from a different year, so an old site's content list reads in order at
a glance. On Content you can sort by clicking the Title or Date heading,
and clicking the same heading again reverses it.
Clicking the sidebar item for the view you are already on refreshes its
list in place, so a tab left open all day stays current without a page
reload.

**The command palette** is the fastest way anywhere. Press **⌘K**
(Ctrl+K on Windows and Linux), start typing, and jump to any view, any
post, or run a command directly: "Clear site cache", "Back up site now",
"Create new page". If you learn one thing from this guide, learn ⌘K.

**Right-click is real in Minn.** This is the least discoverable thing in
the app, so here it is in plain terms: rows in Content and Media have
right-click menus (open, duplicate, publish, trash), comments have them,
the theme toggle has one, sidebar entries have one whether a plugin added
them or Minn did (that's how you hide them), and group headings in the
editor's block library have one too. When in doubt, try a right-click.

**One view, many plugins.** Where several plugins do the same job (three
form plugins, two backup plugins), Minn shows one sidebar entry with a
switcher inside, instead of three lookalike menus. The view stays the same;
the provider changes.

**On a network**, a small chevron sits beside the site name at the top of
the sidebar. It opens a short list of sites you can work on and takes you
straight to Minn on any other. Network
administrators also get a link to Network Admin from there, since account
creation and deletion, the long tail of network settings, restores, exports
and network setup stay with WordPress. The palette knows the sites too: press
⌘K and type "switch", or just start typing a site's name. The chevron does
not appear when you belong to only one site. Its menu opens with search already
focused and lists up to eight sites. Typing fuzzy-matches names and addresses
across every site you can use, not just the ones already listed, and narrows to
the five closest, so a shorthand like "tm10" still finds Team 10 on a large
network.

**If you run the network**, a fourth sidebar group appears: Network. Sites
lists every site with its address, members and state, filters for archived
and spam sites, and search; from a row you can open a site in Minn, visit it,
archive or restore it, mark it as spam, or delete it. Adding a site asks for
an address, a title and the email of someone who already has an account.
Network users lists every account on the network, shows how many sites each
belongs to, and lets you promote someone to network administrator or take
that away. Network settings covers registration, uploads, what site
administrators may do, and where network mail goes. If WP Multi Network is
active, Networks lists every network with its address, site count and
administrator count. You can create a network and its root site there, move
an ordinary site to another network from its right-click menu or detail card, and delete a
non-primary network with all of its sites after an explicit warning. Changing
a network's domain or path links to WP Multi Network's own form because that
operation rewrites every site address in the network. Two things Minn will not
do: it never offers to archive or delete the main site of the network or the
site you are currently working in, and it never lets you remove your own
network-administrator status or the last one on the network. Creating and
deleting accounts stays in Network Admin, because deleting a network account
removes that person's posts from every site and WordPress's own flow offers
to reassign them first.

Network-wide plugin and theme controls stay in Extensions. A network
administrator can activate or deactivate a plugin for the whole network and
enable or disable a theme for every site. Per-site activation stays separate,
and Minn will not offer to deactivate itself network-wide from inside Minn.

**The topbar** carries the page title, a button that shows or hides the
sidebar (⌘. does the same), the version badge (click it for what's new),
a View site link, the theme toggle (click switches light and dark; right-click to follow the system), notifications,
and the New button. Amber chips appear here only when something needs
you: a pending WordPress update, or a site that is not fully public.

## Writing

Press **New** (or ⌘K, "Create new post") and write. The editor is built
for writing, from quick posts to full pages assembled from groups and
columns; heavy page design still belongs to the block editor, one click
away:

- **Type markdown, get formatting.** `#` for a heading, `-` for a list,
  `>` for a quote, `**bold**`, `` `code` ``, `[link](url)`, `---` for a
  divider, triple backticks for a code block. All applied as you type.
- **The slash menu**: type `/` on an empty line for headings, lists,
  images, embeds, tables and more. **⌘/** opens the full block library,
  including your plugins' blocks and entire designs and patterns you can
  drop in.
- **Paste from anywhere.** Word, Google Docs and web pages paste in clean.
  A pasted image or a lone URL to a tweet or video becomes the real thing,
  and raw block markup (from an AI tool or a tutorial) pastes in as real,
  editable blocks.
- **Your patterns come along.** Patterns you save in WordPress (synced or
  not) appear in the slash menu and the block library, and a Patterns
  entry in the Content list manages them. A synced pattern inserts as a
  live reference: edit the pattern once and every post using it follows,
  and Minn reminds you of that reach before you save one. An unsynced
  pattern drops in as a detached copy you can edit freely.
- **Layout blocks open for writing.** Groups, columns, covers and
  media-and-text blocks are writing surfaces, nested to any depth: click
  in and type, with markdown, the slash menu and paste all working
  inside, and each column its own surface laid out like the front end.
  Stackable's columns open the same way, each column its own writing
  surface. The layout itself (widths, colors, spacing) is preserved
  exactly, and anything you do not touch saves back byte for byte, so
  the block editor always reopens the page cleanly.
- **Complex blocks are kept safe, untouched.** If a post contains a block
  Minn's editor doesn't natively edit (a plugin's fancy block, a spacer,
  a row of buttons), it appears as a framed card rendered with your
  site's real styling, right where it lives, even inside a group.
  Hovering the card shows a short note saying why it is protected and
  where edits live. Its text and images are usually editable in place,
  and the ⚙ chip holds its settings plus duplicate and move arrows, so
  a testimonial can hop into the next column without leaving Minn; its
  structure is preserved byte for byte. A theme's ACF block opens its
  real fields there, with their own labels, instead of the raw plumbing,
  and a block that keeps a link only in its markup lists it as an
  editable field. Hovering any ⚙ chip outlines the
  block it configures, so nested blocks read as distinct controls. One
  click on "Block editor ↗" opens the same post in Gutenberg whenever you
  want the full toolkit. Posts built with page builders (Elementor,
  Breakdance, Bricks, Divi and friends) open read-only with an "Edit in your builder"
  button, because the builder owns that content.
- **Galleries and sliders open an images editor.** Hover a gallery-shaped
  block and the card names the action: click anywhere on it for a tile
  grid where you reorder (drag or arrows), replace (click a tile), add,
  remove, duplicate and caption photos. Dropping image files on the grid
  uploads them into that block. Each photo moves as an exact unit, so its
  caption and settings travel with it, and this covers sliders and
  carousels, layouts with a fixed set of openings, and blocks that keep
  their pictures in settings. A gallery's ⚙ chip also offers columns,
  crop and random order; link, size and lightbox options stay in the
  block editor and the popover says so.
- **Groups of styled text open an Edit content window.** A container
  holding several styled blocks (a stats strip of label-and-value
  paragraphs, say) edits in a roomy window: one card per block with its
  text front and center and the other settings tucked behind a toggle,
  plus reorder, remove and add. Enter it from the container's ⚙ chip
  ("Content · N blocks") or, on protected cards, by clicking the card
  itself; the block you clicked opens highlighted.
- **Saving is status-aware.** Drafts autosave as you write. Published
  posts never change under you: edits back up silently, and the live post
  only updates when you press Update. A crash net keeps a local copy of
  unsaved work in your browser and offers to restore it. A live post
  holding edits you never published wears a small amber dot on its status
  in the content list, and the Modified filter there shows only those.
- **History** shows revisions with side-by-side comparisons, and a
  revision also reports what changed in the custom fields, so a page
  whose edits all live in ACF fields no longer claims to be identical.
  Restore takes one click. If someone else has the post open (in Minn or
  wp-admin), you will be told before you can both edit it.
- **Focus modes:** ⌘⇧D fades everything but the paragraph you are writing;
  ⌘⇧O reduces the screen to the text and an outline. ⌘⇧F is find and
  replace. A word-count pill sits at the bottom right; click it to set a
  session writing goal.

## Custom fields

Many sites carry structured fields beside the post body: a subtitle, an
event date, a team list, a page assembled from sections. With Advanced
Custom Fields active, all of it edits in Minn (sites using Pods or Meta
Box get their fields in the same place):

- **Every field group appears.** The editor sidebar's Custom fields card
  opens a dialog with every group that applies to what you are editing,
  with no plugin settings to change first, and everything saves with the
  post. Fields show and hide the way their rules intend as you flip the
  switches that control them, and a hidden field always keeps its value.
- **The everyday types edit in place**: text, choices, switches,
  checkboxes, colors with a real picker, dates and times on the same
  calendar the editor's scheduling uses, files and images from the media
  library, and links to other content, where the picker only ever offers
  what the field allows.
- **The structured types are real too.** A repeater is a stack of row
  cards you add, reorder and remove. Flexible content, the field theme
  page builders are made of, reads as a list of named sections, each
  opening into its own fields. A gallery field opens the same images
  editor the editor's gallery blocks use, and a rich-text field opens a
  focused writing window that hands you back to the dialog when you
  finish. Anything a row or dialog cannot show is preserved untouched
  through every edit, reorder and removal.
- **Theme settings pages.** ACF options pages (a footer, an announcement
  banner, a logo strip) live under the sidebar's Site Options entry with
  their tabs intact, editing the same field types and saving through
  ACF's own storage, with each page's own permissions honored.

Designing the fields themselves lives under **Field Groups** in the
sidebar: see every group and where it shows, then open one into a
builder page where fields stack, configure inline, drag to reorder, and
save as one change. Location rules edit as readable sentences over your
site's real post types, templates and roles; repeaters get their own
sub-field builder; and groups export and import as the same files ACF's
own tools produce, with an import that updates an existing group in
place instead of quietly duplicating it. Field names lock after the
first save so stored content can never be orphaned, groups registered
in code open read-only because their source of truth is the codebase,
and anything the builder does not model keeps an honest pointer to
ACF's own editor.

## Daily site care

**Comments**: approve, reply, edit in place, mark spam, or block a
commenter (future comments from that address go straight to the trash,
with Undo). Bulk-select works across the list.

**Media**: drop files anywhere in the app to upload. Images can be
cropped and rotated right in Minn, and regenerating thumbnails is one
button when that plugin is installed.

**Notifications** (the bell) collects what actually needs attention:
pending comments, available updates, and Notices.

**About Notices**: other plugins' wp-admin banners never render inside
Minn. Instead, Minn reads them in the background and reduces each one to
plain text with its buttons intact, attributed to the plugin that posted
it. Act on one ("No thanks", "Allow") without leaving the panel, or press
**Hide** and it stays gone for you, with Undo. This is the calm version of
the notice wall, and it is permanent policy: plugins cannot buy space in
Minn's interface.

**Updates**: the Updates tab shows everything pending, and **Update
everything** runs plugins, then themes, then any waiting translations, then
WordPress core, telling you exactly what will change and what is untouched
before it starts. Translations are worth calling out because WordPress keeps
language packs apart from everything else: a site can be current on plugins,
themes and core and still owe translations, so they are counted here rather
than left for the WordPress updates screen to mention. While a
run is going, a progress pill in the top bar names the current phase and
stays visible on every screen, so you can close the panel or move around
Minn and still see that updates are working; click it to come back to the
panel. Minn updates itself the same way: each release is fetched from the
project's GitHub releases and checked against a published checksum before
it is allowed to install. After a WordPress core update on multisite, Minn
runs the required database upgrade across the network's sites. Very large
networks are sent to WordPress's own Upgrade Network screen instead.

**Traffic**: with a supported analytics plugin active (Koko Analytics,
Plausible Analytics, Matomo, Independent Analytics, Jetpack Stats, Site Kit and others), the
Overview chart shows daily visitors and pageviews from that plugin's own
numbers. Click a day for its top pages and referrers, and step through
days with the arrow keys without closing the dialog.

**Cache and backups**: if a caching plugin or host cache is active, ⌘K
"Clear site cache" purges all of them at once. If a backup plugin is
installed, "Back up site now" is there too, and the System page reports
how fresh your last backup is.

## You are in control of the interface

Anything in Minn's menus can be hidden, per user, without touching the
site itself:

- Right-click any sidebar entry, whether a plugin added it or it is one
  of Minn's own views (Comments on a site that never uses them, Widgets
  you never touch), and choose **Hide for you**. Hiding is cosmetic:
  every screen stays reachable from the command palette and by its
  address.
- Right-click a group heading in the block library to hide that plugin's
  blocks or designs from your menus.
- Hide any notice from the Notices tab.
- If a developer already hid admin menus in wp-admin with code (a common
  client-site setup), Minn notices and keeps those entries out of its
  sidebar too, automatically. Like the wp-admin original this is
  cosmetic; the screens stay reachable by address and from the palette.

Everything hidden is listed under **Your profile**, where one click
restores it, and an administrator can restore items for you from your
entry on the Users page. Hiding is personal: your co-editors see their
own layout.
Plugins also have hard budgets for how much space they may claim in the
sidebar, palette and menus, so the interface stays calm as you install
more of them.

## Minn in your language

Pick a language on **Your profile** and all of Minn follows: buttons,
table headings, empty states, confirmations, error messages, counts.
Twenty-four languages ship with the plugin. The switch applies the
moment you save; a language you have not installed yet downloads in the
background, with plugin translations following behind, so the interface
never sits waiting on them. Your pick is yours alone; it never changes
what anybody else sees.

Right-to-left languages such as Arabic, Hebrew and Persian get a
genuinely mirrored interface: the sidebar, menus and settings move to
the correct side, while addresses, file paths and version numbers stay
readable left to right inside the surrounding text.

Translations arrive as WordPress language packs through the same
Updates screen as everything else and update alongside the plugin, so
there is nothing extra to install or maintain.

## Your store

With WooCommerce active, the sidebar gains a Commerce group for Orders,
Customers, Products and Coupons. WooCommerce Subscriptions adds Subscriptions,
and a supported appointment plugin adds Bookings in the same operational group.

- **Orders** open as their own page, at their own address, so an order can
  be linked to or kept in a second tab. The main column holds the work:
  items with the money broken down, status, payment, refunds, mail and the
  timeline. The sidebar holds the context: customer, shipping,
  attribution and other orders from the same customer, each card showing
  text until its pencil opens the form. You can take a payment by hand,
  refund whole lines or an arbitrary amount, apply or remove a coupon,
  resend an email and read the order's notes without leaving Minn, and
  the Back button returns you to wherever the visit started.
- **The orders list filters like a storefront back office.** One row
  holds a status view, the search box and Add filter; active filters sit
  beneath as removable chips. Status accepts more than one at a time, and
  a date window, customer or product filter narrows on the server, so it
  survives paging. Filters live in the address, so a filtered list can be
  reloaded or pasted to someone else. Orders that belong to a
  subscription carry a small badge that opens a summary of it.
- **Subscriptions** open on their own page in the order page's shape,
  with editable items and schedule, coupons, a notes timeline and a quick
  view of related orders. The subscriptions list wears the same filter
  bar with its own status vocabulary.
- **Products** open as a page too, in the order page's shape. The main
  column holds what the product is: name and summary, pictures, pricing
  with an optional sale schedule and tax class, inventory (SKU, GTIN,
  whether you track quantity, backorders, a low stock threshold, the
  one-per-order limit), shipping (weight, dimensions, shipping class),
  attributes and variations. The sidebar holds what it is filed under:
  published or not, product type, categories, tags and brands, the address
  slug, featured, and linked products. A purchase note sits with the rest.
  Everything saves together: a bar rises along the bottom as soon as
  something changes, offering Discard beside Save, so a long product is
  never a scroll away from either.

  A few things follow the product rather than sitting there always. Marking
  a product virtual removes the shipping fields, because nothing ships.
  Marking it downloadable adds a Downloads card where you name each file
  and either paste a URL or pick it from your media library. An external
  product asks for the address and button text that send shoppers away. A
  variable product gains a Variations card: give an attribute some values
  and turn on its Variations switch, then Generate from attributes builds
  every combination you do not already have. The card reads as a list of
  what the product sells, one row per variation with its picture, its name,
  its price and what is available. Click a row to open that variation on
  its own: attribute values, regular and sale price, SKU, stock status,
  quantity tracking, and its picture. Cancel leaves it untouched; Done
  writes it back, and the whole set still saves with the page's Save.

  Categories, tags and brands drop a list when you click them, with a tick
  box per row and a tick where the product already sits. A row toggles
  without closing the list, and typing searches the whole site. When the
  term you want does not exist yet, Add new opens a small dialog for it,
  which is where a category is asked for its parent.

  Images are tiles you drag to reorder. The first one is the picture your
  shop shows, so promoting a gallery photo is a drag rather than a trip to
  WooCommerce. Click a tile to swap it, hover it for the × that removes it.
  Adding pictures takes a whole selection at once, from the file dialog or
  dropped straight in, and names each file as it uploads.

  The long description opens in Minn's own editor, with blocks, autosave
  and revisions, rather than sending you elsewhere, and the editor carries
  a button back to the product you came from. If you would rather see
  a product without leaving the list, hover its row and click the eye for a
  quick look with the same fields.

  Two jobs stay in WooCommerce on purpose: creating a brand new store-wide
  attribute, which belongs to the whole shop rather than to one product,
  and the children of a grouped product.

## Managing the site

- **Extensions** — three tabs: Plugins, Themes, and Licenses. Install by
  search, upload, or dropping a zip on the dialog; uploading a zip of
  something already installed shows what is installed against what you
  uploaded and offers to replace it, files swapped, settings and content
  untouched. Toggle, update and
  delete with plain confirmations. Every plugin and theme card carries an
  Auto pill for WordPress automatic updates, the same setting wp-admin
  manages, and inactive themes offer a Live preview so you can walk the
  site in a candidate theme before switching. The Licenses tab
  gathers every external service relationship in one place: paid plugins'
  license keys (activate, verify or deactivate without hunting through
  each plugin's settings screen), service keys like Akismet's, account
  connections like Envato Market and WPMU DEV, and the AI connector keys
  WordPress itself manages, which link out to Settings → Connectors for
  editing. Small chips mark which rows are service keys or connections
  rather than licenses.
- **Users** — create, edit, change roles in bulk, reset passwords, sign
  out sessions, and (with the User Switching plugin) switch into an
  account to see what they see. Opening a user is a full page: identity,
  public profile, language, password and sessions, plus their Minn
  appearance. An administrator can set another user's color scheme and
  defaults there, so a client's Minn looks right before their first
  sign-in, and restore anything that user hid from their own menus.
  Light or dark mode is the one thing that stays personal to each
  person's device. On a subsite, this page manages site membership rather
  than network accounts: add an existing account by email or username,
  change its role, or remove it from that site. Network administrators are
  protected from per-site role and removal controls.
- **Settings** — the settings people actually change: identity and logo,
  reading and discussion, permalinks, visibility (search engines,
  maintenance mode, and a switch that turns a detected coming-soon or
  maintenance plugin's mode off in place, with Undo), site language, spam
  protection (with Akismet, paste or change your key right on its card),
  custom CSS. The long
  tail of rarely-touched options deliberately stays in wp-admin, one
  click away. If you look for a setting and don't find it, that is the
  reason, not a bug.
- **System** — a health check (PHP, HTTPS, caching, backups, loopback),
  an activity view of what is installed, and debug tools when someone
  technical asks you to turn on logging. It also says plainly when a
  custom post type is hidden from Minn by its own REST setting, naming
  the post types affected and where the fix lives.
- **Structure** — post types, taxonomies and terms: rename, merge and
  re-parent categories and tags safely.
- **Database** — a window into where your site actually stores things.
  Most sites never need it, so it is not in the sidebar: you reach it from
  the System page's Database card, the command palette ("Browse database"),
  or the /minn-admin/database address. It has three parts. **Tables** lists
  every table with its size and rough row count, and opening one shows its
  rows, with sorting, a per-column filter and a detail view for any single
  row. **Structure** (a tab on any open table) shows that table's columns
  and indexes, which is what someone technical will ask for when a page is
  slow. **Health** runs a set of storage checks: leftover rows pointing at
  posts or users that no longer exist, missing indexes, tables using an
  old storage engine, space that could be reclaimed, and backlogs like
  expired shop sessions.

  Two things are true of all of it. It is **read-only**: Minn will never
  change or delete anything in the database, because doing so behind a
  plugin's back is how sites break in ways nobody can trace. And where a
  Health check finds something worth cleaning up, Minn gives you the exact
  command to run rather than a button that runs it: copy it, back up, and
  run it yourself or hand it to whoever looks after the site. If you need
  to genuinely edit the database, that is a job for your host's tools or
  the WP-CLI command line, and Minn says so plainly rather than pretending
  otherwise.

## Your profile

Your account page covers your name and avatar, password and sessions,
interface language (each user can pick their own), appearance, everything
you have hidden, and **AI Access**: application passwords for connecting
an AI assistant or other tool to your site over the standard WordPress
API, created and revoked per tool.

## Keyboard shortcuts

| Keys | Does |
|---|---|
| **⌘K** | Command palette (with text selected in the editor: link) |
| **⌘S** | Save, keeping the current status |
| **⌘⏎** | Publish, Update or Schedule |
| **⌘/** | Block library |
| **⌘⇧F** | Find and replace in the post |
| **⌘⇧D** | Focus mode |
| **⌘⇧O** | Outline mode |
| **⌘.** | Show or hide the navigation |
| **← →** | Previous / next item in a media or entry dialog; previous / next day in the traffic dialog |
| **Esc** | Close menus and dialogs |

On Windows and Linux, use Ctrl wherever ⌘ appears.

## Safety, honestly stated

- **Is my content locked in?** No. Minn writes standard WordPress data
  and nothing else. Deactivating or deleting the plugin changes nothing
  about your content, users or settings.
- **How do updates arrive?** From the project's GitHub releases, through
  the normal WordPress updates screen. Since v0.21.0 every download is
  verified against a checksum published with the release before it
  installs; a tampered or broken download refuses to install.
- **Can a plugin misbehave inside Minn?** Not in the ways you are used
  to. Plugins describe their screens to Minn as plain data; their own
  code never draws inside the app, so a broken or pushy plugin cannot
  take the interface down with it or plaster it with banners.
- **Who can open `/minn-admin/`?** Only logged-in users your site already
  trusts to edit content, and each person sees only what their role
  allows. Everything is re-checked on the server on every action.
- **Multisite?** Yes. Site members can switch among the sites they may use,
  subsite administrators can manage local membership, and network
  administrators get daily Sites, Users, Settings, plugin and theme controls.
  WP Multi Network adds a Networks directory, network creation and deletion,
  and guarded site moves between networks.
  Account creation and deletion, the long settings tail and very-large-network
  database upgrades stay in Network Admin.
- **Something looks wrong?** Hard-refresh first (a cached stylesheet
  after an update is the usual cause), check the System page's health
  strip second, and report bugs at
  [github.com/austinginder/minn-admin/issues](https://github.com/austinginder/minn-admin/issues).
  wp-admin always remains as the fallback while anything is sorted out.
