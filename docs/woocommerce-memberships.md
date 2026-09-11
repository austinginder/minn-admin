# WooCommerce Memberships

> Adapter: `includes/adapters/woocommerce-memberships.php`. Suite: `tests/woocommerce-memberships.test.js`. Family: `memberships`. Audited against 1.30.0.

WooCommerce Memberships (SkyVerge) stores each membership as a `wc_user_membership` post: `post_author` is the member, `post_parent` the plan, `post_status` one of the plugin's own `wcm-active`, `wcm-delayed`, `wcm-complimentary`, `wcm-pending` (pending cancellation), `wcm-paused`, `wcm-expired`, `wcm-cancelled`. Dates (`_start_date`, `_end_date`, `_paused_date`, `_cancelled_date`) are MySQL datetimes in UTC; the end date slides forward while a membership is paused, and the plugin's own getter (`get_end_date()`) applies that shift. Plans are `wc_membership_plan` posts with `_access_method`, `_access_length` and `_product_ids` meta; their rules live in the option `wc_memberships_rules`. Notes are comments of type `user_membership_note` that the plugin hides from every normal comment query, so they are only reachable through `get_notes()`.

The plugin ships `wc/v3/memberships/members` and `/plans`, but the members list cannot search below the v4 namespace, has no ordering, and answers neither notes nor counts; the plans routes are read-only and carry no member counts. Minn therefore:

- lists memberships through `WP_Query` on the CPT, exactly the way the plugin's own list screen and REST controller do (status, plan, customer and product filters map onto `post_status`, `post_parent`, `author` and the `_product_id` meta; the date window and the status chart both work on `post_date`), and resolves each row through `wc_memberships_get_user_membership()`
- searches members as accounts (login, email, display name, then first and last name) and lists their memberships, since a member is a WordPress user
- pauses, resumes and cancels through `pause_membership()`, `activate_membership()` and `cancel_membership()`, which are status transitions: the note, the paused interval, the expiry schedule, the member role and any email all happen in the plugin's `transition_post_status` handler, never in Minn
- sets an end date the way the plugin's own edit screen does: a site-local day converted to UTC before `set_end_date()`, a date in the past expires the membership unless it is cancelled, a future date on an expired membership reactivates it, and a blank date clears the end
- adds notes through `add_note( $text, $notify )`, so "email this note to the member" sends the plugin's Membership Note email
- transfers through `transfer_ownership()` and surfaces the plugin's own refusal messages (same customer, already a member of that plan)
- grants a membership by hand through `wc_memberships_create_user_membership()` after the one-membership-per-user-and-plan check their REST controller does (their creator does not), then leaves a note naming who granted it
- deletes through `deleteUserMembership()`, whose hook unschedules the expiry, fixes the member role and drops orphan profile-field values; the plugin removes Trash for this type, so delete is permanent and the confirm says so

Never call `is_active()` or `is_delayed()` from a read path: both write on read (they can expire, delay or activate the membership as a side effect). Rows use `has_status()`.

Capabilities are the plugin's own: `manage_woocommerce_user_memberships` gates the members list, routes and bulk delete (their CSV export and bulk delete check exactly this); `manage_woocommerce_membership_plans` gates the Plans view; every write also asks the per-object `edit_post` / `delete_post`, which `map_meta_cap` resolves to the `*_user_membership` primitives. Both custom caps are granted to shop managers and administrators on every `init` by the plugin. Editors and authors get nothing.

Plans are read-only in Minn (name, access method, length, product list, members by status, rule counts) with the plugin's own edit screen one click away. Plan authoring, restriction rules, grant-access-retroactively, CSV import/export, profile-field definitions and the members directory stay in WooCommerce, and the status card links to the import/export page.
