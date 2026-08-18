# YITH WooCommerce Gift Cards

> Adapter: `includes/adapters/yith-gift-cards.php`. Suite: `tests/yith-gift-cards.test.js`. Family: `gift-cards`.
> The ladder this sits on is in [full-ui-adapters.md](full-ui-adapters.md).

A gift card is a money instrument a store sold and still owes. That is the whole reason
this surface exists: the balances are a liability, and until now the only place to read
them was YITH's own list screen.

## The source audit (premium 4.26.0 and free 4.36.0, checked 2026-08-17)

Four findings decided the shape of the adapter, and each was verified at runtime rather
than read off a changelog:

1. **There is no REST, in either edition.** The `gift_card` CPT registers with
   `show_in_rest => false` and no `rest_base`, `GET wp/v2/gift_card` answers 404, and the
   CPT never appears in `wp/v2/types`. Neither edition calls `register_rest_route` for
   gift cards at all (premium's only route belongs to its licensing vendor library).
2. **There are no custom tables.** Everything is posts plus postmeta
   (`_ywgc_amount_total`, `_ywgc_balance_total`, `_ywgc_recipient`, `_ywgc_expiration`,
   `_ywgc_order_id`, …), which is why the list can be one prepared query.
3. **Free and premium share the record model exactly.** The `META_*` and `STATUS_*`
   constants on `YITH_YWGC_Gift_Card` match byte for byte, and the class differs only in
   phpcs noise. One adapter serves both, with no edition branch anywhere.
4. **The merchant verbs are not callable as services.** In wp-admin they are query-arg
   handlers on the list screen (`?enable-gift-card&id=…`) that end in `wp_safe_redirect`,
   so a REST request cannot use them. The shim calls the object's own methods instead:
   `set_enabled_status()`, `update_balance()`, and for the email
   `YITH_YWGC_Emails_Premium::get_instance()->send_gift_card_email( $id, false )` with a
   fallback down to `YITH_YWGC_Emails` and finally the `yith_ywgc_send_gift_card_email`
   action.

This is the Gravity SMTP situation with a friendlier object underneath: the plugin has no
REST, so Minn's routes read the posts and write through the plugin's own PHP.

## Why the descriptor is hand-declared

YITH declares its gift card edit screen as data (a plugin-fw metabox: tabs of fields with
`type` text / onoff / textarea and `deps` for conditionals), which is exactly the kind of
schema `full-ui-adapters.md` says to import rather than hand-copy. It is not imported
here, for two reasons found by trying:

- `init_metabox()` runs only under `is_admin() && ! DOING_AJAX`, so during a REST request
  the descriptor is never registered. Reading it would mean invoking that method under
  REST, which also registers `save_post`, `add_meta_boxes` and `admin_enqueue_scripts` as
  a side effect.
- `YIT_Metabox` keeps its options in a property with no getter.

Seven stable fields, identical across editions, are not worth that. A plugin-fw mapper is
still the right idea for the YITH catalogue as a whole, and it is a decision of its own,
not a thing to smuggle in behind one surface.

## The list does not instantiate the object

`YITH_YWGC_Gift_Card::__construct` **migrates as it loads**: when `_ywgc_amount_total` is
empty it calls `update_amount()`, which writes meta. That is correct for YITH and wrong
for a list, where it would turn painting a page into twenty-five writes. So the row
builder reads post and meta directly, and the object is used only where a single card is
touched: the detail, and each verb.

The search reaches both the code (`post_title`) and the recipient (`_ywgc_recipient`) in
one ordered, paginated query, because a merchant searches with whatever the customer read
them over the phone. That is why it is a prepared `$wpdb` query rather than `WP_Query`.

## Statuses are translated, not passed through

YITH's post statuses (`ywgc-disabled`, `ywgc-code-not-valid`, …) are storage details that
humanize into things nobody would write. The adapter maps them onto Minn's shared pill
vocabulary (`active`, `disabled`, `dismissed`, `pre-printed`, `code-not-valid`) and the
filter bar, the columns and the action gates all speak that. Two consequences worth knowing:

- **Expiration is meta, not a status.** An expired card is still `publish` in the
  database. The list shows it as `expired` anyway, because a merchant scanning balances
  needs to see what can no longer be spent.
- **The action gates read `enabled`, not `status`.** Otherwise an expired card could not
  be disabled, since its displayed status is no longer "active".

## Two things the detail modal taught us

- **A `sectionsRoute` response should carry `title`.** Without it the modal header falls
  back to the surface label, so every card in a stack reads "Gift cards". It carries the
  code now. The Code row survives beside it because a code gets read out loud, and
  monospace is what makes that possible.
- **A `url` row renders the href as its own text.** Putting the order there printed a raw
  `http://…/minn-admin/orders/35` into a card of otherwise short values. The row shows
  `#35` now, and a **View order** action (gated on `has_order`, so a hand-issued card
  does not offer it) is the way in. It stays unmarked because it is a same-site app link,
  not an exit.

## The list wears the orders bar

Gift cards is the first plugin surface to declare `filterBar`, so it renders the same bar
WooCommerce Orders does rather than a pill strip: a status dropdown, Add filter, chips and
the whole narrowing in the URL. It is the same markup, the same ids and the same binder,
so there is no second bar to drift; the adapter supplies only its own status vocabulary
and answers the parameters the bar sends (`status=any`, `status[]`, `after`, `before`).

Two statuses at once is the capability the tab strip could never express, and it is why
this was worth doing rather than restyling tabs. Customer and product are deliberately not
offered: this shim has no lookup to serve them, and narrowing them in the browser would
lie about the count the moment the list paginates.

## Money and dates

`wc_price()` returns HTML and every surface value is escaped at render, so amounts are
stripped and decoded server-side, where the store's own currency and decimals still
apply. `_ywgc_expiration` is a unix timestamp, not a formatted date. Created times are
`post_date_gmt`, so the column declares `utc: true`.

## Issuing a card by hand

Both editions offer this (the free edition's empty-state links to
`post-new.php?post_type=gift_card` too), and it is a real counter transaction: a refund
made good, a comp, a promotion. Minn declares a `create` on the collection and renders
nothing of its own; the primitive supplies the Add button, the modal and the form.

Only the amount is required, because a value is the one thing a gift card cannot exist
without. A blank code is filled in by **YITH's** generator, so a card issued from Minn
looks like every other card the store issues.

Four things the source decided for us:

- **Write through the deepest class installed.** `YITH_YWGC_Gift_Card`,
  `YITH_YWGC_Gift_Card_Extended` and `YWGC_Gift_Card_Premium` each override `save()`, and
  each writes meta the one below it does not. Creating with the base class on a premium
  site leaves a card YITH's own screens read as half written. `minn_admin_ywgc_new()`
  resolves downwards, exactly as YITH's own generator does. This is the mirror image of
  the read path, which must NOT touch the object at all.
- **`is_digital` is not cosmetic.** YITH's mailer returns early on `is_virtual()`, without
  a word, so a card carrying a recipient address but left non-digital would accept Resend
  email forever and send nothing. Minn sets it from whether a recipient was given. The
  same discovery added a guard to the resend route, which until then would have answered
  "sent" for a physical card.
- **Uniqueness is checked against every status, not YITH's helper.**
  `get_gift_card_by_code()` runs `get_posts()`, which defaults to `post_status =>
  'publish'`, so it cannot see a disabled, dismissed or pre-printed card and would hand
  out a code that is already in circulation. A code is the whole security of the
  instrument, so `minn_admin_ywgc_code_taken()` queries the posts table directly.
- **Expiry comes from the store, and there is no field for it.** The field vocabulary has
  no date type, and inventing an expiry rule here would be worse than not offering one: a
  hand-issued card that expires on a different rule than the ones YITH issues is the kind
  of difference nobody notices until a customer is turned away. `ywgc_usage_expiration`
  (a number of months, premium only) is read the way YITH reads it. Two departures, both
  bugs in YITH's own generator: it tests `'0' !== $option`, which is TRUE for the empty
  string a free site returns, and then hands `strtotime` a monthless `"+ month"`.

Everything that can be refused is refused **before** the card exists, including sending
with no recipient, so a failed create never leaves a card behind to tidy up. A refusal
returns a `WP_Error` whose message the modal toasts, leaving the form open as typed.

The result is a record YITH treats as its own: its list screen labels it *Created
manually*, prices it, and reports it as *Not yet sent* rather than *Physical product*.

`YITH_YWGC()->current_user_can_create()` gates the button as well as the capability. It is
the switch YITH gives a site to turn hand-issued cards off, and it gates YITH's own create
UI, so Minn honours it rather than offering a button the store has decided against.

**Not covered:** bulk generation (premium's quantity-and-amount generator), which stays in
YITH. One card at a time is the counter transaction; five hundred is a campaign, and
campaigns have their own screen.

## Capability

`manage_woocommerce`, checked by one helper that both the descriptor and every
`permission_callback` call. The CPT declares `capability_type => 'product'`, but a
redeemable balance an admin can change by hand is shop-manager work, not
everyone-who-edits-products work.

## What stays in YITH

The gift-card product's own fields (its amount options), bulk generation, CSV import and
export, PDF and QR. The detail carries `adminUrl`, so the modal always offers the way
there.

## Tests

`tests/yith-gift-cards.test.js` builds its fixture the honest way: it creates a gift-card
product, completes an order holding it, and lets YITH issue the card exactly as a
customer's purchase would. It asserts the query string alongside the rows for every
filter (one status, two statuses at once, a date window, the search) and the URL round
trip through a reload, opens the detail, and checks each write against what was STORED. The
balance check reads back through the sections route, which hydrates YITH's own object, so
a shim agreeing with itself would still fail. It skips with exit 0 when YITH is not
active.

Create is driven through the real modal: it types a card in, then reads it back through
YITH's object to prove it stored as active, digital, and worth what was typed, and emails
it. Each refusal has its own case, and a count taken either side of all of them proves
that not one wrote a card before saying no.

**Known gap:** the cards the suite issues are left behind, bought and hand-issued alike.
The adapter has no delete verb and the CPT is not in REST, so there is nothing to delete
them with. The product and the order are cleaned up.

One branch stays uncovered: resend refusing a card that has a recipient but is not
digital. Create cannot produce that combination, and only YITH itself writes it, from a
physical gift-card purchase.
