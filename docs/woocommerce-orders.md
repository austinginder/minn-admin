# WooCommerce orders

> The store's other half lives in [woocommerce-products.md](woocommerce-products.md).
> Principles are in [goals.md](goals.md).

An order is where a store owner spends their attention, and it is the one screen a
merchant already has a strong opinion about, because they have used Shopify. That
opinion is the design brief here: **the order detail follows Shopify's shape, and
WooCommerce's rules.** Shape means the layout and the interaction patterns. Rules means
every value written goes through WooCommerce's own REST API and its own vocabulary, so
nothing here is Minn's private idea of what an order is.

## The layout

`/minn-admin/orders/{id}` is two columns on a wide screen and one column below about
1020px. The quick-view modal (the eye on a row, or right-click) renders the same body in
a single column, so anything added to the page arrives in the quick view too.

**The header** carries the order number, the status badge, a Paid or Pending chip, the
date, and the actions on the right: Refund, Send email, Copy payment URL, any PDF
documents, and the escape hatch to WooCommerce.

**The main column** is the work: the items with the money broken down (subtotal with an
item count, discount, shipping, tax, refunds, total, paid), the status control with Save
changes, the payment card, the WooCommerce email resend, and the timeline. The timeline
puts the composer above the list, the way a conversation reads.

**The sidebar** is the context: the customer note, the customer with their contact
information and billing address, the shipping address, attribution, related
subscriptions, and the customer's other orders.

## Read first, edit in a dialog

Sidebar cards show text, not form fields. A pencil on the card opens a dialog with the
form, and its Save rides the same save flow as the page's own Save changes button. Three
reasons this is not decoration:

1. **A card of inputs reads as work to do.** An order is mostly read: who bought what,
   did it get paid. Fields shout for attention the screen does not owe them.
2. **The dialog is where the whole field set fits.** A billing address is ten fields. In
   a 340px sidebar those wrap into a column of slivers.
3. **It made the save honest.** The save reads its values from the DOM, so a form that is
   not on screen used to mean empty strings, which is data loss dressed as a save. Every
   field now falls back to the order's stored value when its input is absent, and the
   suite pins that: editing the customer must not blank the shipping address.

Refund is the same idea for the same reason. It used to be a permanent card carrying
per-line steppers, a restock checkbox and a gateway checkbox, which is a lot of screen
for something that happens rarely and never by accident. It is a header action opening a
dialog now. It appears whenever the order has a refundable amount, paid or not, which is
what wp-admin does: a refund record without a captured payment is a real thing that
happens (money returned outside the site).

## Editing items

An order whose payment has not completed is editable, exactly as WooCommerce decides it
(`is_editable`), and the items card grows a pencil there. The dialog changes quantities,
removes lines and adds products through a search of the catalogue.

One hard-won detail: **a quantity change has to rewrite the line's money.** WooCommerce's
REST keeps the old `subtotal` and `total` when only `quantity` moves, so an order for
three would still be billed as one. Minn rescales both from the line's unit price.
Removing a line is `quantity: 0`, which is WooCommerce's own idiom for it. Taxes and
shipping are not recalculated, and the dialog says so rather than quietly leaving a wrong
number on screen.

## The list: filters over native parameters

The list used to offer one status tab strip and a search box. The strip could not
express two statuses at once, though WooCommerce's REST has always accepted an array
there, and everything else the collection can narrow by was unreachable.

The toolbar is one row now: a status dropdown, the search box, and Add filter, with the
active filters as chips beneath. The filters are status (multi), a date window, customer
and product. Each is a **native WooCommerce collection parameter**
(`status[]`, `after`, `customer`, `product`), which is the whole point:

> Filtering in the browser would be a lie the moment the list paginates. Page 2 of an
> unfiltered query is not page 2 of a filtered one, and the count would describe neither.

The suite asserts the query string alongside the rows for exactly that reason.

**The tabs did not simply die.** They live inside the dropdown, so the common one-click
triage ("show me Processing") did not get slower in the name of a nicer bar.

**Filters live in the URL**, so a filtered list survives a reload and can be pasted to
someone else. Only Minn's own keys are touched, because with plain permalinks the app
itself rides on a query argument. Everything read back is validated: a status has to be
one WooCommerce registered, ids have to be positive integers, dates have to look like
dates. A URL can only carry an id, so a restored customer or product filter fetches its
name and paints it into the chip.

**Four lists wear the same bar.** Orders, subscriptions, products and coupons are the
same shape in four vocabularies, so the machinery is parameterized by a spec (status
vocabulary, state slot, loader, renderer, and the dimensions that list offers) and the
active list comes from the route. Each list keeps its own filters.

A **dimension** is data rather than a branch in the popover: a label, how the value is
picked (`choices` for a fixed list, `lookup` for an async picker, plus the shared status
and date slots), and the WooCommerce parameter it becomes. Adding a filter is an entry in
that table.

| List | Status | Dimensions |
|---|---|---|
| Orders | multi (`status[]`) | date, customer, product |
| Subscriptions | multi (`status[]`) | date, customer, product |
| Products | single (`status`) | stock, category, tag, type, featured, on sale |
| Coupons | single (`status`) | date |

Status is multi where WooCommerce registers it as an array and single where it registers
an enum: products and coupons take one status per query, so their dropdown IS the status
control and there is no second multi-select behind Add filter.

Two dimensions people ask for are deliberately absent, both for the same reason. **Brand**
is not a `wc/v3/products` parameter (the collection returns brands but cannot filter on
them; only the Store API can), and **coupon discount type** is not a `wc/v3/coupons`
parameter at all. Offering either would mean narrowing one page in the browser, which the
rule above forbids.

Products keeps one exception, and it is honest about it: **Low stock** is not a
`stock_status` value. It is the `wc-analytics/products/low-in-stock` lookup with a
managed-stock scan behind it, so it contributes no query parameter of its own, and the
suite asserts that no `stock_status=low` ever reaches wc/v3.

Two loads on products cannot ask the server: that Low stock lookup, and the exact-id hit
for a numeric search. Both re-check the active filters against the rows they hand back.
That is why `featured` is in the list's `_fields`: a flag the row does not carry cannot
be honored.

The products search box promised "name, SKU, ID" while WooCommerce's `search` matches the
name only. It now sends `search_name_or_sku` alongside `search`: WC 11+ honors the former
and ignores the latter, and an older build ignores the former and honors the latter, so
the search degrades to name-only instead of returning the whole catalogue.

Not here yet: channel (`created_via` accepts an array but has no enum, so the store's
actual values need a server-side lookup), a custom date range (the themed picker carries
editor-specific chrome today), and payment status, which is not a WooCommerce collection
parameter at all: it lives in `date_paid`, so it would need Minn's own endpoint and a meta
query.

## Status labels belong to WooCommerce

Badges, the status picker and the list tabs read `wc_get_order_statuses()`, shipped in the
boot payload as `B.wcOrderStatuses`. A slug is a database detail: "pending" is called
Pending payment, a translated site reads its own vocabulary, and a status another plugin
registered gets its real name instead of a humanized slug. An unknown slug still falls
through to the humanized form, so a missing entry costs nothing.

## Attribution

WooCommerce 8.5 records where an order came from in order meta
(`_wc_order_attribution_*`). When that meta exists the sidebar shows a card with the
origin, device, referrer and session counts. When it does not, there is no card. Minn
never fabricates a "direct" reading out of an absence.

## What stays in WooCommerce

Tax and shipping recalculation, coupon editing on an existing order, fulfillment
workflows, and anything a payment gateway owns. The header's link to WooCommerce is one
click and is never hidden.

## Tests

`tests/order-filters.test.js` covers the filter bar: each filter against the rows AND the
query string, the multi-status chip, chip removal, clear all, the URL round trip through a
reload, junk in the URL being ignored, the picker's loading state and its thumbnails.
`tests/subscription-filters.test.js` does the same for subscriptions and proves the two
lists do not share filters. `tests/product-filters.test.js` and
`tests/coupon-filters.test.js` cover the other two, including the Low stock exception and
the absent discount-type filter. `tests/order-layout.test.js` covers the detail layout: the two columns on desktop and
their stacking on a narrow viewport and in the modal, the header's badges and actions,
the read-first sidebar and its dialogs, the copy-from-billing button, refund as a header
action, items editing (quantity, add, remove) against the saved order, the attribution
card, and the status label. `order-page`, `order-payment` and `order-refunds` cover the
behavior underneath it.

For walking the screen by hand, `tests/seed-orders.js` creates one order per status on a
throwaway lab.
