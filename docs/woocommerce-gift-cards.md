# WooCommerce Gift Cards (official)

> Adapter: `includes/adapters/woocommerce-gift-cards.php`. Suite: `tests/woocommerce-gift-cards.test.js`. Family: `gift-cards`.

Official WooCommerce Gift Cards (Kestrel) stores cards in `{prefix}woocommerce_gc_cards`. Remaining balance is the `remaining` column; issued amount is `balance`. Dates are UTC unix.

Their `wc/v3/gift-cards` REST exists, but the list cannot search or filter, and remaining is schema-readonly. Minn therefore:

- lists through `WC_GC()->db->giftcards->query()`
- sets remaining through `WC_GC_Gift_Card_Data::set_balance()` + `save()`
- creates through `rest_do_request` on `POST /wc/v3/gift-cards` (issued activity and `_currency` stay theirs)
- emails through `woocommerce_gc_force_send_gift_card_to_customer`

Redeemed means the card is linked to a customer account, not that remaining is zero. Product form, email designer, CSV import/export stay in WooCommerce.
