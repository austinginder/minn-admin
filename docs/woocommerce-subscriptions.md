# The subscriptions surface

WooCommerce Subscriptions is a bundled integration like any other: every function guards
on the plugin, and with it absent nothing in this document exists. What follows is the
reasoning behind the screen, not a tour of it. Read `woocommerce-orders.md` first. A
subscription wears the order detail's shape on purpose, and the two share their
implementation rather than resembling each other.

## A subscription is a place

It used to be a modal and nothing else. Now `/minn-admin/subscriptions/{id}` is the
primary surface: a URL you can link, reload, and hand to someone. The modal survives as
Quick view behind the row's eye, for the case it was always good at, which is a glance
without losing your place in the list.

Both hosts render one body (`subscriptionDetailInnerHtml`) and run one binder
(`bindSubscriptionDetail`). There is no second implementation to drift. The page lays out
two columns and the modal stacks them, which is the only difference between the two.

## What the sidebar carries

The schedule, the customer, the payment method, and the attribution card. Attribution is
literally the order card: WooCommerce Subscriptions records the same
`_wc_order_attribution_*` meta on a subscription that WooCommerce records on an order, so
`attributionCardHtml()` serves both. As on an order, absent meta means no card rather than
an invented "direct".

## Dates are GMT, in both directions, with different names

This is the trap the whole surface turns on. WooCommerce Subscriptions **reads** dates as
`next_payment_date_gmt`, `trial_end_date_gmt`, `start_date_gmt`. It **writes** them as
`next_payment_date`, `trial_end_date`, and interprets whatever it is given as GMT. Two
vocabularies for one field, and no suffix on the side where the ambiguity would actually
hurt.

So every read goes through `subTime()`, which forces UTC, and every write goes through
`siteInputToGmt()`. Getting this wrong is invisible on a site whose timezone is UTC, which
is exactly how it survived unnoticed: on a site at UTC-5 a subscription that started
seconds ago rendered as "in 5h", a subscription that has not started yet. The tests assert
against a computed offset rather than a literal, so they fail on any site where the
conversion is dropped.

The dialog shows site time, like every other date in Minn. The value it holds is the
picker's own machine format, and Minn's picker is used rather than a native
`datetime-local`, which Chrome refuses to style.

## Editing

Items and schedule are editable; the sidebar cards are read-first with a pencil, as on an
order.

**Items** are not a second implementation. A subscription's `line_items` take the byte
identical PUT an order's do, so `bindItemsDialog()` is parameterized by route rather than
copied: same product search, same quantity rescale from the unit price, same
`quantity: 0` removal. The pencil appears only when WooCommerce says `is_editable`, which
is WooCommerce's judgment to make and not ours.

**The schedule** sends a diff. An untouched field is never transmitted, so WooCommerce
never validates a date the user did not open the dialog for. It enforces the order
`trial end ≤ next payment ≤ end` and rejects anything else with a 400, which surfaces as
the error toast rather than as a silent no-op.

**Start date is deliberately read only.** Moving a live subscription's start rewrites the
billing history hanging off it. It is shown in the dialog for reference and cannot be
changed there. Making it editable is a decision to take on purpose, not a gap to fill.

The pencils carry `data-soedit`, not the order surface's `data-oedit`. `bindOrderDetail`
queries `[data-oedit]` across the whole document, so a shared name would let an order host
bind controls that are not its own.

## The list

Subscriptions run the same filter machinery as orders, with their own vocabulary: their
own statuses (active, pending-cancel, expired, switched) against the same native
collection parameters. Filters live in the URL and survive a reload. The two lists never
share filter state.

Columns follow WooCommerce's own list: subscription, customer, items, status, start date,
next payment, total. The items cell shows the first product and a `+N`, with the rest one
hover or click away. That cell swallows its own clicks so the list can open the popover
instead of navigating, which means a click in the middle of a row does not open the
subscription. That is Shopify's behavior and it is deliberate.

## What stays in WooCommerce

Switching a subscription's product, retrying a failed renewal, changing payment method on
behalf of a customer, and everything a payment gateway owns. The link out is one click and
is never hidden.

## Tests

`tests/subscription-page.test.js` covers the page: the deep link, the two columns and
their stacking, the attribution card, the status save, items editing asserted against what
WooCommerce stored, schedule editing asserted as GMT against the site's own offset, the
themed picker, related-order navigation, and the modal as Quick view.
`tests/subscription-filters.test.js` covers the list: each filter against the rows and the
query string, the URL round trip, the start date column, and the GMT reading of a start
date. `tests/wcs-subscriptions.test.js` covers the integration underneath both.
