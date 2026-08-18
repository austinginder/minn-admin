# PW WooCommerce Gift Cards

> Adapter: `includes/adapters/pw-gift-cards.php`. Suite: `tests/pw-gift-cards.test.js`. Family: `gift-cards`.

Free (`pw-woocommerce-gift-cards`) and Pro (`pw-gift-cards`) share class names and tables. Only one may load. The balance is `SUM(amount)` on `{prefix}pimwick_gift_card_activity`, never a column.

Writes go through `PW_Gift_Card`:

- create: `create_card( $note )` or `add_card( $code, $note )`, then `credit( $amount )`
- Pro then sets recipient / from / message
- set remaining: `adjust_balance( $target - $current )` (inactive cards refuse)
- disable / enable: `deactivate()` / `reactivate()`
- resend: `do_action( 'pw_gift_cards_send_email_manually', … )`

The Pro admin list template writes `recipient_email` as it paints. Minn never uses it. Pro REST (`wc-pimwick/v1/pw-gift-cards`) lists only active cards and has no total, so it is not the collection source.

Email designer, CSV, scheduled delivery and PDF stay in PW.
