<?php
/**
 * Bundled adapter: WooCommerce Memberships (SkyVerge).
 *
 * A membership is a `wc_user_membership` post: post_author is the member,
 * post_parent the plan, post_status one of the plugin's `wcm-*` statuses,
 * and the dates live in UTC postmeta. The plugin ships wc/v3/memberships
 * REST, but that list cannot search below v4, has no ordering, no notes and
 * no counts, and every meaningful write (pause, cancel, resume, notes,
 * transfer) runs through the plugin's own object, which is where the
 * status-transition side effects live (notes, paused intervals, emails, the
 * member role). So Minn lists through WP_Query the way their own screen
 * does, reads through WC_Memberships_User_Membership, and writes only
 * through that object's public methods.
 *
 * See docs/woocommerce-memberships.md for the source audit.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether WooCommerce Memberships is up (plugin loaded on top of WooCommerce).
 *
 * @return bool
 */
function minn_admin_wcm_active() {
	return class_exists( 'WooCommerce' )
		&& function_exists( 'wc_memberships' )
		&& function_exists( 'wc_memberships_get_user_membership' )
		&& class_exists( 'WC_Memberships_User_Membership' );
}

/**
 * The plugin's own gate for the members screens.
 *
 * `manage_woocommerce_user_memberships` is granted to shop managers and
 * administrators on every init by WC_Memberships_Post_Types; their list
 * screen's bulk delete and CSV export check exactly this one.
 *
 * @return bool
 */
function minn_admin_wcm_can() {
	return current_user_can( 'manage_woocommerce_user_memberships' );
}

/**
 * The plugin's own gate for plans (their meta boxes and block sidebar).
 *
 * @return bool
 */
function minn_admin_wcm_can_plans() {
	return current_user_can( 'manage_woocommerce_membership_plans' );
}

/**
 * Unprefixed status slugs with the plugin's own labels.
 *
 * @return array<string,string> slug => label
 */
function minn_admin_wcm_statuses() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	if ( function_exists( 'wc_memberships_get_user_membership_statuses' ) ) {
		foreach ( (array) wc_memberships_get_user_membership_statuses( true, false ) as $slug => $data ) {
			$out[ (string) $slug ] = is_array( $data ) && ! empty( $data['label'] ) ? (string) $data['label'] : ucfirst( (string) $slug );
		}
	}
	if ( ! $out ) {
		$out = array(
			'active'        => __( 'Active', 'minn-admin' ),
			'delayed'       => __( 'Delayed', 'minn-admin' ),
			'complimentary' => __( 'Complimentary', 'minn-admin' ),
			'pending'       => __( 'Pending cancellation', 'minn-admin' ),
			'paused'        => __( 'Paused', 'minn-admin' ),
			'expired'       => __( 'Expired', 'minn-admin' ),
			'cancelled'     => __( 'Cancelled', 'minn-admin' ),
		);
	}
	return $out;
}

/**
 * The statuses a membership's edit screen offers, unprefixed. The plugin
 * lets a site hide options per membership through
 * `wc_memberships_edit_user_membership_screen_status_options` (keyed by
 * the prefixed status); the page offers and the save accepts that same set.
 *
 * @param int $membership_id User membership id.
 * @return array status => label
 */
function minn_admin_wcm_status_options( $membership_id ) {
	$prefixed = array();
	foreach ( minn_admin_wcm_statuses() as $slug => $label ) {
		$prefixed[ minn_admin_wcm_prefix( $slug ) ] = $label;
	}
	$filtered = apply_filters( 'wc_memberships_edit_user_membership_screen_status_options', $prefixed, (int) $membership_id );
	$out      = array();
	foreach ( (array) $filtered as $slug => $label ) {
		$slug = preg_replace( '/^wcm-/', '', (string) $slug );
		if ( isset( minn_admin_wcm_statuses()[ $slug ] ) ) {
			$out[ $slug ] = (string) $label;
		}
	}
	return $out;
}

/**
 * Statuses that grant access right now, unprefixed.
 *
 * @return string[]
 */
function minn_admin_wcm_active_statuses() {
	$set = array();
	if ( function_exists( 'wc_memberships' ) ) {
		$um = wc_memberships()->get_user_memberships_instance();
		if ( $um && method_exists( $um, 'get_active_access_membership_statuses' ) ) {
			$set = (array) $um->get_active_access_membership_statuses();
		}
	}
	if ( ! $set ) {
		$set = array( 'active', 'complimentary', 'free_trial', 'pending' );
	}
	return array_values( array_map( function ( $s ) {
		return preg_replace( '/^wcm-/', '', (string) $s );
	}, $set ) );
}

/**
 * Prefix a status for a post query.
 *
 * @param string $slug Status slug.
 * @return string
 */
function minn_admin_wcm_prefix( $slug ) {
	$slug = (string) $slug;
	return 0 === strpos( $slug, 'wcm-' ) ? $slug : 'wcm-' . $slug;
}

/**
 * A UTC MySQL datetime as ISO 8601 with a Z, or '' when empty.
 *
 * @param string $mysql UTC datetime.
 * @return string
 */
function minn_admin_wcm_iso( $mysql ) {
	$mysql = trim( (string) $mysql );
	if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
		return '';
	}
	$ts = strtotime( $mysql . ' UTC' );
	return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
}

/**
 * A UTC MySQL datetime as site-local display text, or '' when empty.
 *
 * @param string $mysql UTC datetime.
 * @param bool   $time  Include the time.
 * @return string
 */
function minn_admin_wcm_local( $mysql, $time = false ) {
	$mysql = trim( (string) $mysql );
	if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
		return '';
	}
	$ts = strtotime( $mysql . ' UTC' );
	if ( ! $ts ) {
		return '';
	}
	$format = get_option( 'date_format' );
	if ( $time ) {
		$format .= ' ' . get_option( 'time_format' );
	}
	return wp_date( $format, $ts );
}

/**
 * Load one membership, or a WP_Error. `$write` also asks the plugin's
 * per-object cap (map_meta_cap resolves edit_post to edit_user_membership).
 *
 * @param int    $id    Membership post id.
 * @param string $write '' for a read, else the meta cap to hold (edit_post | delete_post).
 * @return WC_Memberships_User_Membership|WP_Error
 */
function minn_admin_wcm_load( $id, $write = '' ) {
	$id   = (int) $id;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'wc_user_membership' !== $post->post_type ) {
		return new WP_Error( 'minn_wcm_not_found', __( 'Membership not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$m = wc_memberships_get_user_membership( $post );
	if ( ! $m || ! $m->get_id() ) {
		return new WP_Error( 'minn_wcm_not_found', __( 'Membership not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( '' !== $write && ! current_user_can( $write, $id ) ) {
		return new WP_Error( 'minn_wcm_forbidden', __( 'You are not allowed to change this membership.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	return $m;
}

/**
 * Display name for a member.
 *
 * @param WP_User|null $user User.
 * @param int          $user_id Fallback id.
 * @return string
 */
function minn_admin_wcm_member_name( $user, $user_id = 0 ) {
	if ( $user instanceof WP_User ) {
		$name = trim( $user->first_name . ' ' . $user->last_name );
		return $name ? $name : ( $user->display_name ? $user->display_name : $user->user_login );
	}
	/* translators: %d: WordPress user id of an account that no longer exists. */
	return sprintf( __( 'Deleted user #%d', 'minn-admin' ), (int) $user_id );
}

/**
 * One list row from a membership object. Reads only: never is_active(),
 * which writes on read (it can expire or activate the membership).
 *
 * @param WC_Memberships_User_Membership $m Membership.
 * @return array
 */
function minn_admin_wcm_row( $m ) {
	$user     = $m->get_user();
	$user_id  = (int) $m->get_user_id();
	$plan     = $m->get_plan();
	$status   = (string) $m->get_status();
	$order_id = (int) $m->get_order_id();
	$end      = (string) $m->get_end_date( 'mysql' );
	$paused   = $m->has_status( 'paused' );
	$done     = $m->has_status( 'cancelled' );

	return array(
		'id'          => (int) $m->get_id(),
		'member'      => minn_admin_wcm_member_name( $user, $user_id ),
		'email'       => $user instanceof WP_User ? (string) $user->user_email : '',
		'user_id'     => $user_id,
		'plan'        => $plan ? (string) $plan->get_name() : __( '(deleted plan)', 'minn-admin' ),
		'plan_id'     => (int) $m->get_plan_id(),
		'status'      => $status,
		'since'       => minn_admin_wcm_iso( $m->get_start_date( 'mysql' ) ),
		'expires'     => minn_admin_wcm_iso( $end ),
		'order'       => $order_id ? '#' . $order_id : '',
		'order_id'    => $order_id,
		'has_order'   => $order_id > 0,
		'product_id'  => (int) $m->get_product_id(),
		'pausable'    => ! $paused && ! $done,
		'resumable'   => $paused,
		'cancellable' => ! $done,
	);
}

/**
 * Resolve a typed customer (email, login or numeric id) to a user.
 *
 * @param string $raw Typed value.
 * @return WP_User|null
 */
function minn_admin_wcm_find_user( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	$user = null;
	if ( is_email( $raw ) ) {
		$user = get_user_by( 'email', $raw );
	}
	// Login before id: a site can have numeric usernames, and the field asks
	// for an email or username, never an id. Only fall back to an id lookup
	// when nothing else matched, so "1234" the username wins over user 1234.
	if ( ! $user ) {
		$user = get_user_by( 'login', $raw );
	}
	if ( ! $user && ctype_digit( $raw ) ) {
		$user = get_user_by( 'id', (int) $raw );
	}
	return $user instanceof WP_User ? $user : null;
}

/**
 * Apply a typed end date the way the plugin's own edit screen does.
 *
 * Empty clears the end (unlimited) and revives an expired membership; a
 * date in the past expires it unless it is cancelled; a future date on an
 * expired membership reactivates it. Input is a site-local day
 * (YYYY-MM-DD, optional time) converted to UTC before the setter.
 *
 * @param WC_Memberships_User_Membership $m   Membership.
 * @param string                         $raw Typed date.
 * @return true|WP_Error
 */
function minn_admin_wcm_apply_end_date( $m, $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		if ( $m->has_status( 'expired' ) ) {
			$m->update_status( 'active' );
		}
		$m->set_end_date( '' );
		return true;
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $raw ) || ! strtotime( $raw ) ) {
		return new WP_Error( 'minn_wcm_date', __( 'Enter the end date as YYYY-MM-DD, or leave it blank for no end.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$local = gmdate( 'Y-m-d H:i:s', strtotime( $raw ) );
	$utc   = get_gmt_from_date( $local );
	if ( strtotime( $utc . ' UTC' ) <= time() ) {
		if ( ! $m->is_cancelled() ) {
			$m->update_status( 'expired' );
		}
	} elseif ( $m->has_status( 'expired' ) ) {
		$m->update_status( 'active' );
	}
	$m->set_end_date( $utc );
	return true;
}

/**
 * Published plans as select options for the create form.
 *
 * @return array<int,array{0:string,1:string}>
 */
function minn_admin_wcm_plan_options() {
	$out = array();
	if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
		return $out;
	}
	try {
		foreach ( (array) wc_memberships_get_membership_plans() as $plan ) {
			if ( is_object( $plan ) && method_exists( $plan, 'get_id' ) ) {
				$out[] = array( (string) $plan->get_id(), (string) $plan->get_name() );
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	return $out;
}

/**
 * Access method labels, from the plugin's own vocabulary.
 *
 * @param string $method manual-only | signup | purchase.
 * @return string
 */
function minn_admin_wcm_access_label( $method ) {
	static $labels = null;
	if ( null === $labels ) {
		$labels = array();
		if ( function_exists( 'wc_memberships' ) ) {
			$plans = wc_memberships()->get_plans_instance();
			if ( $plans && method_exists( $plans, 'get_membership_plans_access_methods' ) ) {
				$labels = (array) $plans->get_membership_plans_access_methods( true );
			}
		}
	}
	$method = (string) $method;
	if ( isset( $labels[ $method ] ) && is_string( $labels[ $method ] ) ) {
		return $labels[ $method ];
	}
	$fallback = array(
		'manual-only' => __( 'Manual assignment only', 'minn-admin' ),
		'signup'      => __( 'Free sign-up', 'minn-admin' ),
		'purchase'    => __( 'Product purchase', 'minn-admin' ),
	);
	return isset( $fallback[ $method ] ) ? $fallback[ $method ] : $method;
}

/**
 * One plans-view row.
 *
 * @param WC_Memberships_Membership_Plan $plan Plan.
 * @return array
 */
function minn_admin_wcm_plan_row( $plan ) {
	$post       = get_post( $plan->get_id() );
	$rules      = 0;
	$active_now = false;
	try {
		$active_now = (bool) $plan->has_active_memberships();
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	try {
		$rules = count( (array) $plan->get_rules( 'all', false ) );
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	return array(
		'id'       => (int) $plan->get_id(),
		'name'     => (string) $plan->get_name(),
		'slug'     => (string) $plan->get_slug(),
		'access'   => minn_admin_wcm_access_label( $plan->get_access_method() ),
		'length'   => (string) $plan->get_human_access_length(),
		'members'  => (int) $plan->get_memberships_count( minn_admin_wcm_active_statuses() ),
		'total'    => (int) $plan->get_memberships_count( 'any' ),
		// One column per status the plugin's own Members screen filters by,
		// so a plan reads as a row of its member counts, not one number.
		'free_trial' => (int) $plan->get_memberships_count( 'free_trial' ),
		'pending'    => (int) $plan->get_memberships_count( 'pending' ),
		'paused'     => (int) $plan->get_memberships_count( 'paused' ),
		'expired'    => (int) $plan->get_memberships_count( 'expired' ),
		'cancelled'  => (int) $plan->get_memberships_count( 'cancelled' ),
		'products' => count( (array) $plan->get_product_ids() ),
		'rules'    => $rules,
		'status'   => $post ? (string) $post->post_status : '',
		'deletable' => ! $active_now,
	);
}

/**
 * Local (site-timezone) day for a UTC MySQL datetime, or '' when empty.
 *
 * @param string $mysql UTC datetime.
 * @return string Y-m-d
 */
function minn_admin_wcm_local_day( $mysql ) {
	$mysql = trim( (string) $mysql );
	if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
		return '';
	}
	$ts = strtotime( $mysql . ' UTC' );
	return $ts ? wp_date( 'Y-m-d', $ts ) : '';
}

/**
 * Notes for the page and the detail card, newest first.
 *
 * @param WC_Memberships_User_Membership $m Membership.
 * @return array
 */
function minn_admin_wcm_notes( $m ) {
	$out = array();
	try {
		$all = array_filter( (array) $m->get_notes( 'all' ), function ( $n ) {
			return $n instanceof WP_Comment;
		} );
		// Their getter's order follows the comment query; pin newest first.
		usort( $all, function ( $a, $b ) {
			$cmp = strcmp( (string) $b->comment_date_gmt, (string) $a->comment_date_gmt );
			return 0 !== $cmp ? $cmp : (int) $b->comment_ID - (int) $a->comment_ID;
		} );
		foreach ( $all as $note ) {
			$out[] = array(
				'id'       => (int) $note->comment_ID,
				'date'     => minn_admin_wcm_iso( $note->comment_date_gmt ),
				'when'     => minn_admin_wcm_local( $note->comment_date_gmt, true ),
				'author'   => $note->comment_author ? (string) $note->comment_author : 'WooCommerce',
				'text'     => wp_strip_all_tags( (string) $note->comment_content ),
				'notified' => (bool) get_comment_meta( $note->comment_ID, 'notified', true ),
			);
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	return $out;
}

/**
 * The membership page model: everything the /memberships/{id} page paints
 * and every vocabulary its form needs, in one response.
 *
 * @param WC_Memberships_User_Membership $m Membership.
 * @return array
 */
function minn_admin_wcm_page_model( $m ) {
	$id      = (int) $m->get_id();
	$post    = get_post( $id );
	$user    = $m->get_user();
	$user_id = (int) $m->get_user_id();
	$plan    = $m->get_plan();
	$status  = (string) $m->get_status();
	$labels  = minn_admin_wcm_statuses();

	$member = array(
		'id'      => $user_id,
		'name'    => minn_admin_wcm_member_name( $user, $user_id ),
		'email'   => $user instanceof WP_User ? (string) $user->user_email : '',
		'login'   => $user instanceof WP_User ? (string) $user->user_login : '',
		'avatar'  => $user instanceof WP_User ? (string) get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
		'since'   => '',
		'address' => '',
	);
	try {
		$um = wc_memberships()->get_user_memberships_instance();
		if ( $user_id && $um && method_exists( $um, 'get_user_member_since_date' ) ) {
			$member['since'] = minn_admin_wcm_local( (string) $um->get_user_member_since_date( $user_id, 'mysql' ) );
		}
		if ( $user_id && function_exists( 'wc_get_account_formatted_address' ) ) {
			$address = (string) wc_get_account_formatted_address( 'billing', $user_id );
			$member['address'] = trim( wp_strip_all_tags( str_replace( array( '<br/>', '<br />', '<br>' ), ', ', $address ) ) );
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$plans = array();
	try {
		$rows = (array) wc_memberships_get_membership_plans( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) );
		foreach ( $rows as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'get_id' ) ) {
				$plans[] = array( 'id' => (int) $p->get_id(), 'name' => (string) $p->get_name() );
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$statuses = array();
	foreach ( minn_admin_wcm_status_options( $id ) as $slug => $label ) {
		$statuses[] = array( $slug, $label );
	}

	$order    = null;
	$order_id = (int) $m->get_order_id();
	if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
		$o = wc_get_order( $order_id );
		if ( $o ) {
			$created = $o->get_date_created();
			$order   = array(
				'id'     => $order_id,
				'number' => (string) $o->get_order_number(),
				'status' => (string) $o->get_status(),
				'total'  => html_entity_decode( wp_strip_all_tags( (string) $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
				'date'   => $created ? wp_date( get_option( 'date_format' ), $created->getTimestamp() ) : '',
			);
		} else {
			$order = array( 'id' => $order_id, 'number' => (string) $order_id, 'status' => '', 'total' => '', 'date' => '' );
		}
	}

	$product    = null;
	$product_id = (int) $m->get_product_id();
	if ( $product_id > 0 ) {
		$pr      = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$product = array( 'id' => $product_id, 'name' => $pr ? (string) $pr->get_name() : '#' . $product_id );
	}

	// A WooCommerce Subscriptions link, read through the plugin's own
	// integration when both are up; never a guess from order meta.
	$subscription = null;
	try {
		if ( class_exists( 'WC_Subscriptions' ) && method_exists( wc_memberships(), 'get_integrations_instance' ) ) {
			$integrations = wc_memberships()->get_integrations_instance();
			$subs         = $integrations && method_exists( $integrations, 'get_subscriptions_instance' ) ? $integrations->get_subscriptions_instance() : null;
			$sid          = 0;
			if ( $subs && method_exists( $subs, 'get_user_membership_subscription_id' ) ) {
				$sid = (int) $subs->get_user_membership_subscription_id( $id );
			}
			if ( $sid > 0 && function_exists( 'wcs_get_subscription' ) ) {
				$sub          = wcs_get_subscription( $sid );
				$subscription = array( 'id' => $sid, 'status' => $sub ? (string) $sub->get_status() : '' );
				if ( $sub ) {
					// The subscription is what decides whether this member
					// stays a member, so the page shows what a shop manager
					// checks first: when it bills next, and how the last
					// renewal went. A failed renewal is the usual reason a
					// membership quietly goes on hold.
					$next = $sub->get_date( 'next_payment' );
					$subscription['next_payment'] = $next && '0' !== (string) $next ? gmdate( 'c', strtotime( $next . ' UTC' ) ) : '';
					$subscription['customer_id']  = (int) $sub->get_user_id();
					$last = $sub->get_last_order( 'all', array( 'renewal' ) );
					if ( $last instanceof WC_Order ) {
						$subscription['last_renewal'] = array(
							'id'     => (int) $last->get_id(),
							'number' => (string) $last->get_order_number(),
							'status' => (string) $last->get_status(),
							'date'   => $last->get_date_created() ? $last->get_date_created()->date( 'c' ) : '',
						);
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$profile = array();
	try {
		foreach ( (array) $m->get_profile_fields() as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'get_definition' ) ) {
				continue;
			}
			$def   = $field->get_definition();
			$value = method_exists( $field, 'get_formatted_value' ) ? $field->get_formatted_value() : $field->get_value();
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$profile[] = array(
				'label' => $def && method_exists( $def, 'get_name' ) ? (string) $def->get_name() : (string) $field->get_slug(),
				'value' => wp_strip_all_tags( (string) $value ),
			);
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$memberships = array();
	try {
		foreach ( (array) wc_memberships_get_user_memberships( $user_id, array( 'status' => 'any' ) ) as $other ) {
			if ( ! is_object( $other ) || ! method_exists( $other, 'get_id' ) ) {
				continue;
			}
			$op            = $other->get_plan();
			$memberships[] = array(
				'id'      => (int) $other->get_id(),
				'plan'    => $op ? (string) $op->get_name() : __( '(deleted plan)', 'minn-admin' ),
				'plan_id' => (int) $other->get_plan_id(),
				'status'  => (string) $other->get_status(),
				'current' => (int) $other->get_id() === $id,
			);
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	$held = array_map( function ( $x ) {
		return $x['plan_id'];
	}, $memberships );

	$active_seconds = 0;
	try {
		$active_seconds = (int) $m->get_total_active_time( 'timestamp' );
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$end = (string) $m->get_end_date( 'mysql' );

	return array(
		'id'             => $id,
		'status'         => $status,
		'statusLabel'    => isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status ),
		'member'         => $member,
		'plan'           => array( 'id' => (int) $m->get_plan_id(), 'name' => $plan ? (string) $plan->get_name() : __( '(deleted plan)', 'minn-admin' ) ),
		'plans'          => $plans,
		'availablePlans' => array_values( array_filter( $plans, function ( $p ) use ( $held ) {
			return ! in_array( $p['id'], $held, true );
		} ) ),
		'statuses'       => $statuses,
		'start'          => minn_admin_wcm_local_day( $m->get_start_date( 'mysql' ) ),
		'end'            => minn_admin_wcm_local_day( $end ),
		'endsText'       => $end ? minn_admin_wcm_local( $end ) : '',
		'pausedSince'    => $m->has_status( 'paused' ) ? minn_admin_wcm_local( $m->get_paused_date( 'mysql' ), true ) : '',
		'cancelledAt'    => $m->has_status( 'cancelled' ) ? minn_admin_wcm_local( $m->get_cancelled_date( 'mysql' ), true ) : '',
		'timeActive'     => $active_seconds > 0 ? human_time_diff( time() - $active_seconds, time() ) : '',
		'created'        => $post ? minn_admin_wcm_local( $post->post_date_gmt, true ) : '',
		'order'          => $order,
		'product'        => $product,
		'subscription'   => $subscription,
		'profile'        => $profile,
		'notes'          => minn_admin_wcm_notes( $m ),
		'memberships'    => $memberships,
		'pausable'       => ! $m->has_status( array( 'paused', 'cancelled' ) ),
		'resumable'      => $m->has_status( 'paused' ),
		'cancellable'    => ! $m->is_cancelled(),
		'can'            => array(
			'edit'   => current_user_can( 'edit_post', $id ),
			'delete' => current_user_can( 'delete_post', $id ),
		),
		'adminUrl'       => admin_url( 'post.php?post=' . $id . '&action=edit' ),
	);
}

/* ===== Plan page (membership-plans/{id}) ===== */

/**
 * The content types a rule of one type may target, as [value, label] pairs
 * with value "post_type:name" | "taxonomy:name", from the plugin's own lists
 * (the same class its meta box uses; it loads under REST by design).
 *
 * @param string $rule_type content_restriction | product_restriction | purchasing_discount.
 * @return array<int,array{0:string,1:string}>
 */
function minn_admin_wcm_rule_targets( $rule_type ) {
	$out = array();
	if ( ! class_exists( 'WC_Memberships_Admin_Membership_Plan_Rules' ) ) {
		return $out;
	}
	try {
		if ( 'content_restriction' === $rule_type ) {
			foreach ( (array) WC_Memberships_Admin_Membership_Plan_Rules::get_valid_post_types_for_content_restriction_rules() as $name => $pt ) {
				if ( minn_admin_wcm_target_editable( 'post_type', $name ) ) {
					$out[] = array( 'post_type:' . $name, $pt->labels->name );
				}
			}
			foreach ( (array) WC_Memberships_Admin_Membership_Plan_Rules::get_valid_taxonomies_for_content_restriction_rules() as $name => $tx ) {
				if ( minn_admin_wcm_target_editable( 'taxonomy', $name ) ) {
					$out[] = array( 'taxonomy:' . $name, $tx->labels->name );
				}
			}
		} else {
			$product = get_post_type_object( 'product' );
			if ( minn_admin_wcm_target_editable( 'post_type', 'product' ) ) {
				$out[] = array( 'post_type:product', $product ? $product->labels->name : __( 'Products', 'minn-admin' ) );
			}
			$taxes   = 'product_restriction' === $rule_type
				? WC_Memberships_Admin_Membership_Plan_Rules::get_valid_taxonomies_for_product_restriction_rules()
				: WC_Memberships_Admin_Membership_Plan_Rules::get_valid_taxonomies_for_purchasing_discounts_rules();
			foreach ( (array) $taxes as $name => $tx ) {
				if ( minn_admin_wcm_target_editable( 'taxonomy', $name ) ) {
					$out[] = array( 'taxonomy:' . $name, $tx->labels->name );
				}
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
	return $out;
}

/**
 * Whether the current user may author rules for a content type: the test
 * the plugin's own rule editor applies before it stores a new rule (post
 * types need edit_posts plus edit_others_posts, taxonomies manage_terms plus
 * edit_terms), and the one its wc_memberships_edit_rule meta cap applies
 * to existing rules. A shop manager therefore cannot fence off, rewrite or
 * republish an LMS course or a forum it could not edit itself.
 *
 * @param string $content_type post_type | taxonomy.
 * @param string $name         Post type or taxonomy name.
 * @return bool
 */
function minn_admin_wcm_target_editable( $content_type, $name ) {
	if ( 'taxonomy' === $content_type ) {
		$tx = get_taxonomy( $name );
		return $tx && current_user_can( $tx->cap->manage_terms ) && current_user_can( $tx->cap->edit_terms );
	}
	$pt = get_post_type_object( $name );
	return $pt && current_user_can( $pt->cap->edit_posts ) && current_user_can( $pt->cap->edit_others_posts );
}

/**
 * Whether the current user may change or remove an existing rule (the
 * plugin's wc_memberships_edit_rule meta cap; a rule whose content type no
 * longer exists is locked the way the plugin locks it).
 *
 * @param WC_Memberships_Membership_Plan_Rule $rule Rule.
 * @return bool
 */
function minn_admin_wcm_rule_editable( $rule ) {
	return (bool) current_user_can( 'wc_memberships_edit_rule', $rule->get_id() );
}

/**
 * Display labels for the objects a rule targets.
 *
 * @param string $content_type post_type | taxonomy.
 * @param string $name         Post type or taxonomy name.
 * @param int[]  $ids          Object ids.
 * @return array<int,array{id:int,label:string}>
 */
function minn_admin_wcm_rule_objects( $content_type, $name, $ids ) {
	$out = array();
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		if ( $id <= 0 ) {
			continue;
		}
		$label = '';
		if ( 'taxonomy' === $content_type ) {
			$term  = get_term( $id, $name );
			$label = $term && ! is_wp_error( $term ) ? $term->name : '';
		} else {
			$post  = get_post( $id );
			// Only name posts of the rule's own type the caller may read;
			// anything else shows as a bare id rather than leaking a title.
			$label = $post && $post->post_type === $name && current_user_can( 'read_post', $post->ID ) ? get_the_title( $post ) : '';
		}
		$out[] = array( 'id' => $id, 'label' => '' !== $label ? $label : '#' . $id );
	}
	return $out;
}

/**
 * One rule, in the shape the plugin's own JSON serializer emits plus labels.
 *
 * @param WC_Memberships_Membership_Plan_Rule $rule Rule.
 * @return array
 */
/**
 * Rule meta as scalars keyed by a sane key; anything else is dropped.
 *
 * @param mixed $meta Stored or submitted meta map.
 * @return array
 */
function minn_admin_wcm_rule_meta_clean( $meta ) {
	$out = array();
	foreach ( (array) $meta as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ( ! is_scalar( $value ) && null !== $value ) ) {
			continue;
		}
		$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}
	return $out;
}

function minn_admin_wcm_rule_model( $rule ) {
	$data = class_exists( '\SkyVerge\WooCommerce\Memberships\Plans\Adapters\JsonSerializers\MembershipPlanRuleSerializer' )
		? \SkyVerge\WooCommerce\Memberships\Plans\Adapters\JsonSerializers\MembershipPlanRuleSerializer::convert( $rule )
		: array(
			'id'                => $rule->get_id(),
			'content_type'      => $rule->get_content_type(),
			'content_type_name' => $rule->get_content_type_name(),
			'object_ids'        => $rule->get_object_ids(),
		);
	$data['object_ids'] = array_map( 'intval', (array) $data['object_ids'] );
	$data['objects']    = minn_admin_wcm_rule_objects( $data['content_type'], $data['content_type_name'], $data['object_ids'] );
	$data['target']     = $data['content_type'] . ':' . $data['content_type_name'];
	// The serializer never carries the Subscriptions "exclude free trial"
	// flag; without it a save would rebuild the rule with trial included.
	if ( 'purchasing_discount' !== $rule->get_rule_type() && method_exists( $rule, 'is_access_schedule_excluding_trial' ) ) {
		$data['access_schedule_exclude_trial'] = (bool) $rule->is_access_schedule_excluding_trial();
	}
	// Integration flags (Courseware's auto-enrol) live in the rule's meta;
	// the serializer drops them, so they ride the model to survive a save.
	$data['meta_data'] = method_exists( $rule, 'get_meta_data' ) ? minn_admin_wcm_rule_meta_clean( $rule->get_meta_data() ) : array();
	$data['locked'] = ! minn_admin_wcm_rule_editable( $rule );
	// A locked rule's type is not in the vocabulary the page offers, so it
	// carries its own label.
	$obj = 'taxonomy' === $data['content_type'] ? get_taxonomy( $data['content_type_name'] ) : get_post_type_object( $data['content_type_name'] );
	$data['target_label'] = $obj && isset( $obj->labels->name ) ? (string) $obj->labels->name : (string) $data['content_type_name'];
	if ( isset( $data['discount_amount'] ) ) {
		$data['discount_amount'] = (string) $data['discount_amount'];
	}
	return $data;
}

/**
 * The plan page model. A null plan yields the blank model for /new.
 *
 * @param WC_Memberships_Membership_Plan|null $plan Plan.
 * @return array
 */
function minn_admin_wcm_plan_model( $plan ) {
	$sections = array();
	if ( function_exists( 'wc_memberships_get_members_area_sections' ) ) {
		foreach ( (array) wc_memberships_get_members_area_sections( $plan ? $plan->get_id() : '' ) as $key => $label ) {
			$sections[] = array( (string) $key, (string) $label );
		}
	}
	$vocab = array(
		'targets'  => array(
			'content_restriction' => minn_admin_wcm_rule_targets( 'content_restriction' ),
			'product_restriction' => minn_admin_wcm_rule_targets( 'product_restriction' ),
			'purchasing_discount' => minn_admin_wcm_rule_targets( 'purchasing_discount' ),
		),
		'periods'  => array(
			array( 'days', __( 'days', 'minn-admin' ) ),
			array( 'weeks', __( 'weeks', 'minn-admin' ) ),
			array( 'months', __( 'months', 'minn-admin' ) ),
			array( 'years', __( 'years', 'minn-admin' ) ),
		),
		'sections' => $sections,
		'methods'  => array(
			array( 'manual-only', minn_admin_wcm_access_label( 'manual-only' ) ),
			array( 'signup', minn_admin_wcm_access_label( 'signup' ) ),
			array( 'purchase', minn_admin_wcm_access_label( 'purchase' ) ),
		),
	);

	if ( ! $plan ) {
		return array(
			'id'          => 0,
			'name'        => '',
			'slug'        => '',
			'description' => '',
			'status'      => 'publish',
			'access'      => array( 'method' => 'manual-only', 'products' => array() ),
			'length'      => array( 'type' => 'unlimited', 'amount' => 1, 'period' => 'months', 'start' => '', 'end' => '' ),
			'sections'    => array(),
			'rules'       => array( 'content_restriction' => array(), 'product_restriction' => array(), 'purchasing_discount' => array() ),
			'vocab'       => $vocab,
			'counts'      => array( 'active' => 0, 'total' => 0, 'byStatus' => array() ),
			'deletable'   => false,
			'can'         => array( 'edit' => minn_admin_wcm_can_plans(), 'delete' => false ),
			'adminUrl'    => '',
		);
	}

	$post  = get_post( $plan->get_id() );
	$rules = array( 'content_restriction' => array(), 'product_restriction' => array(), 'purchasing_discount' => array() );
	try {
		foreach ( (array) $plan->get_rules( 'all', true ) as $rule ) {
			if ( ! $rule instanceof WC_Memberships_Membership_Plan_Rule ) {
				continue;
			}
			$type = (string) $rule->get_rule_type();
			if ( isset( $rules[ $type ] ) ) {
				$rules[ $type ][] = minn_admin_wcm_rule_model( $rule );
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	$products = array();
	foreach ( (array) $plan->get_product_ids() as $pid ) {
		$pr         = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $pid ) : null;
		$products[] = array( 'id' => (int) $pid, 'label' => $pr ? (string) $pr->get_name() : '#' . (int) $pid );
	}

	$length_type = (string) $plan->get_access_length_type();
	$length      = array(
		'type'   => in_array( $length_type, array( 'unlimited', 'specific', 'fixed' ), true ) ? $length_type : 'unlimited',
		'amount' => max( 1, (int) $plan->get_access_length_amount() ),
		'period' => (string) $plan->get_access_length_period() ?: 'months',
		'start'  => 'fixed' === $length_type ? minn_admin_wcm_local_day( $plan->get_access_start_date( 'mysql' ) ) : '',
		'end'    => 'fixed' === $length_type ? minn_admin_wcm_local_day( $plan->get_access_end_date( 'mysql' ) ) : '',
	);

	$by_status = array();
	foreach ( minn_admin_wcm_statuses() as $slug => $label ) {
		$n = (int) $plan->get_memberships_count( $slug );
		if ( $n > 0 ) {
			$by_status[] = array( 'label' => $label, 'value' => $n, 'status' => $slug );
		}
	}
	$has_active = false;
	try {
		$has_active = (bool) $plan->has_active_memberships();
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	return array(
		'id'          => (int) $plan->get_id(),
		'name'        => (string) $plan->get_name(),
		'slug'        => (string) $plan->get_slug(),
		'description' => $post ? (string) $post->post_content : '',
		'status'      => $post ? (string) $post->post_status : 'publish',
		'access'      => array( 'method' => (string) $plan->get_access_method(), 'products' => $products ),
		'length'      => $length,
		'sections'    => array_values( array_map( 'strval', (array) $plan->get_members_area_sections() ) ),
		'rules'       => $rules,
		'vocab'       => $vocab,
		'counts'      => array(
			'active'   => (int) $plan->get_memberships_count( minn_admin_wcm_active_statuses() ),
			'total'    => (int) $plan->get_memberships_count( 'any' ),
			'byStatus' => $by_status,
		),
		'deletable'   => ! $has_active,
		'can'         => array(
			'edit'   => current_user_can( 'edit_post', $plan->get_id() ),
			'delete' => current_user_can( 'delete_post', $plan->get_id() ),
		),
		'adminUrl'    => admin_url( 'post.php?post=' . (int) $plan->get_id() . '&action=edit' ),
		'membersUrl'  => admin_url( 'edit.php?post_type=wc_user_membership&post_parent=' . (int) $plan->get_id() ),
	);
}

/**
 * Normalize the page's rule payload into the shape the plugin's SetPlanRules
 * action accepts, validating targets against the plugin's own lists.
 *
 * @param array $rules_in { type => [ rule, ... ] } from the request.
 * @return array|WP_Error
 */
function minn_admin_wcm_plan_rules_clean( $rules_in, $plan = null ) {
	$out = array( 'content_restriction' => array(), 'product_restriction' => array(), 'purchasing_discount' => array() );
	if ( ! is_array( $rules_in ) ) {
		return $out;
	}
	// Existing rules this user may not change (the plugin's own edit meta
	// cap) ride through untouched: the page sends them back as it got them,
	// and apply() keeps the stored rule instead of rebuilding it.
	$locked = array();
	if ( $plan ) {
		foreach ( (array) $plan->get_rules( 'all', true ) as $rule ) {
			if ( $rule instanceof WC_Memberships_Membership_Plan_Rule && ! minn_admin_wcm_rule_editable( $rule ) ) {
				$locked[ (string) $rule->get_id() ] = true;
			}
		}
	}
	$periods = array( 'days', 'weeks', 'months', 'years' );
	foreach ( $out as $type => $_ ) {
		$targets = array_map( function ( $t ) {
			return $t[0];
		}, minn_admin_wcm_rule_targets( $type ) );
		foreach ( (array) ( isset( $rules_in[ $type ] ) ? $rules_in[ $type ] : array() ) as $i => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			if ( ! empty( $r['id'] ) && is_string( $r['id'] ) && isset( $locked[ sanitize_key( $r['id'] ) ] ) ) {
				$out[ $type ][] = array( 'id' => sanitize_key( $r['id'] ), 'locked' => true );
				continue;
			}
			$target = (string) ( isset( $r['target'] ) ? $r['target'] : '' );
			if ( '' === $target && isset( $r['content_type'], $r['content_type_name'] ) ) {
				$target = $r['content_type'] . ':' . $r['content_type_name'];
			}
			if ( ! in_array( $target, $targets, true ) ) {
				return new WP_Error( 'minn_wcm_rule', sprintf(
					/* translators: %d: 1-based position of the rule in its list. */
					__( 'Rule %d targets a content type this plan cannot restrict, or one you cannot edit.', 'minn-admin' ),
					(int) $i + 1
				), array( 'status' => 400 ) );
			}
			list( $content_type, $name ) = explode( ':', $target, 2 );
			$clean = array(
				'content_type'      => $content_type,
				'content_type_name' => $name,
				'object_ids'        => array_values( array_filter( array_map( 'intval', (array) ( isset( $r['object_ids'] ) ? $r['object_ids'] : array() ) ) ) ),
			);
			if ( ! empty( $r['id'] ) && is_string( $r['id'] ) ) {
				$clean['id'] = sanitize_key( $r['id'] );
			}
			if ( array_key_exists( 'meta_data', $r ) ) {
				$clean['meta_data'] = minn_admin_wcm_rule_meta_clean( $r['meta_data'] );
			}
			if ( 'purchasing_discount' !== $type ) {
				$sched = isset( $r['access_schedule'] ) && is_array( $r['access_schedule'] ) ? $r['access_schedule'] : array();
				$stype = isset( $sched['type'] ) && 'delayed' === $sched['type'] ? 'delayed' : 'immediate';
				$clean['access_schedule'] = array( 'type' => $stype );
				if ( 'delayed' === $stype ) {
					$amount = isset( $sched['amount'] ) ? (int) $sched['amount'] : 0;
					$period = isset( $sched['period'] ) ? (string) $sched['period'] : '';
					if ( $amount < 1 || ! in_array( $period, $periods, true ) ) {
						return new WP_Error( 'minn_wcm_rule', __( 'A delayed rule needs a number of days, weeks, months or years.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$clean['access_schedule']['amount'] = $amount;
					$clean['access_schedule']['period'] = $period;
				}
				// Sent explicitly = set it; absent = keep what the stored
				// rule has (apply() copies it from the existing rule).
				if ( array_key_exists( 'access_schedule_exclude_trial', $r ) ) {
					$clean['access_schedule_exclude_trial'] = rest_sanitize_boolean( $r['access_schedule_exclude_trial'] );
				}
			}
			if ( 'product_restriction' === $type ) {
				$clean['access_type'] = isset( $r['access_type'] ) && 'purchase' === $r['access_type'] ? 'purchase' : 'view';
			}
			if ( 'purchasing_discount' === $type ) {
				$dtype  = isset( $r['discount_type'] ) && 'amount' === $r['discount_type'] ? 'amount' : 'percentage';
				$amount = isset( $r['discount_amount'] ) ? $r['discount_amount'] : '';
				if ( '' === (string) $amount || ! is_numeric( $amount ) || (float) $amount < 0 ) {
					return new WP_Error( 'minn_wcm_rule', __( 'Each discount needs an amount.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$clean['discount_type']   = $dtype;
				$clean['discount_amount'] = (float) $amount;
				$clean['active']          = ! isset( $r['active'] ) || rest_sanitize_boolean( $r['active'] );
			}
			$out[ $type ][] = $clean;
		}
	}
	return $out;
}

/**
 * Write the page's rules onto a plan through the plugin's own SetPlanRules
 * configuration, keeping the id of every rule the page sent back so an
 * unchanged rule stays the same rule, and deleting the ones it dropped.
 *
 * @param WC_Memberships_Membership_Plan $plan  Plan.
 * @param array                          $rules Cleaned rules by type.
 * @return true|WP_Error
 */
function minn_admin_wcm_plan_rules_apply( $plan, $rules ) {
	if ( ! class_exists( '\SkyVerge\WooCommerce\Memberships\Plans\Actions\SetPlanRules' ) ) {
		return new WP_Error( 'minn_wcm_rule', __( 'This version of WooCommerce Memberships cannot set rules from here.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	// configureRule() is protected; the subclass only exposes it, all the
	// validation and field mapping is theirs.
	$builder = new class() extends \SkyVerge\WooCommerce\Memberships\Plans\Actions\SetPlanRules {
		public function build( $plan, $type, $data ) {
			return $this->configureRule( $plan, $type, $data );
		}
	};
	$existing = array();
	foreach ( (array) $plan->get_rules( 'all', true ) as $rule ) {
		if ( $rule instanceof WC_Memberships_Membership_Plan_Rule ) {
			$existing[] = (string) $rule->get_id();
		}
	}
	$keep = array();
	// Rules this user may not change stay exactly as stored, whether the page
	// echoed them back (locked entries) or not.
	foreach ( (array) $plan->get_rules( 'all', true ) as $rule ) {
		if ( $rule instanceof WC_Memberships_Membership_Plan_Rule && ! minn_admin_wcm_rule_editable( $rule ) ) {
			$keep[] = (string) $rule->get_id();
		}
	}
	$collected = array();
	try {
		foreach ( $rules as $type => $list ) {
			foreach ( $list as $data ) {
				if ( ! empty( $data['locked'] ) ) {
					continue;
				}
				$rule = $builder->build( $plan, $type, $data );
				$prev = null;
				if ( ! empty( $data['id'] ) && in_array( $data['id'], $existing, true ) && ! in_array( $data['id'], $keep, true ) ) {
					$rule->set_id( $data['id'] );
					$keep[] = $data['id'];
					$prev   = $plan->get_rule( $data['id'] );
				}
				// Their builder never carries rule meta, so a save would drop a
				// Courseware auto-enrol flag; re-apply what was sent, else what
				// the stored rule holds.
				if ( method_exists( $rule, 'set_meta' ) ) {
					$meta = array_key_exists( 'meta_data', $data )
						? (array) $data['meta_data']
						: ( $prev && method_exists( $prev, 'get_meta_data' ) ? minn_admin_wcm_rule_meta_clean( $prev->get_meta_data() ) : array() );
					foreach ( $meta as $mk => $mv ) {
						$rule->set_meta( (string) $mk, $mv );
					}
				}
				if ( 'purchasing_discount' !== $type && method_exists( $rule, 'set_access_schedule_exclude_trial' ) ) {
					$exclude = array_key_exists( 'access_schedule_exclude_trial', $data )
						? (bool) $data['access_schedule_exclude_trial']
						: ( $prev && method_exists( $prev, 'is_access_schedule_excluding_trial' ) && $prev->is_access_schedule_excluding_trial() );
					if ( $exclude ) {
						$rule->set_access_schedule_exclude_trial();
					} else {
						$rule->set_access_schedule_include_trial();
					}
				}
				$collected[] = $rule;
			}
		}
	} catch ( \Throwable $e ) {
		return new WP_Error( 'minn_wcm_rule', $e->getMessage() ? $e->getMessage() : __( 'A rule could not be saved.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$remove = array_values( array_diff( $existing, $keep ) );
	if ( $remove ) {
		$plan->delete_rules( $remove );
	}
	if ( $collected ) {
		$plan->set_rules( $collected );
	}
	return true;
}

/**
 * Validate the page's General fields against the plan the way the
 * plugin's meta box does, without writing anything. A partial save
 * inherits what it does not mention.
 *
 * @param WC_Memberships_Membership_Plan $plan Plan.
 * @param array                          $in   Request body.
 * @return array|WP_Error Cleaned fields, or the first refusal.
 */
function minn_admin_wcm_plan_general_clean( $plan, $in ) {
	$method = isset( $in['access_method'] ) ? (string) $in['access_method'] : (string) $plan->get_access_method();
	if ( ! in_array( $method, array( 'manual-only', 'signup', 'purchase' ), true ) ) {
		return new WP_Error( 'minn_wcm_plan', __( 'Pick how members get access.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	// A partial save inherits what it does not mention: the page always
	// sends every field, the row actions send one.
	$product_ids = array_values( array_filter( array_map( 'intval', (array) ( array_key_exists( 'product_ids', $in ) ? $in['product_ids'] : $plan->get_product_ids() ) ) ) );
	if ( 'purchase' === $method ) {
		if ( ! $product_ids ) {
			return new WP_Error( 'minn_wcm_plan', __( 'Access on purchase needs at least one product.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		foreach ( $product_ids as $pid ) {
			if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $pid ) ) {
				return new WP_Error( 'minn_wcm_plan', sprintf(
					/* translators: %d: product id. */
					__( 'Product #%d does not exist.', 'minn-admin' ),
					$pid
				), array( 'status' => 400 ) );
			}
		}
	}

	$ltype = isset( $in['length_type'] ) ? (string) $in['length_type'] : (string) $plan->get_access_length_type();
	if ( ! in_array( $ltype, array( 'unlimited', 'specific', 'fixed' ), true ) ) {
		return new WP_Error( 'minn_wcm_plan', __( 'Pick a membership length.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = isset( $in['length_amount'] ) ? (int) $in['length_amount'] : (int) $plan->get_access_length_amount();
	$period = isset( $in['length_period'] ) ? (string) $in['length_period'] : (string) $plan->get_access_length_period();
	$start  = isset( $in['length_start'] ) ? trim( (string) $in['length_start'] ) : minn_admin_wcm_local_day( $plan->get_access_start_date( 'mysql' ) );
	$end    = isset( $in['length_end'] ) ? trim( (string) $in['length_end'] ) : minn_admin_wcm_local_day( $plan->get_access_end_date( 'mysql' ) );
	if ( 'specific' === $ltype && ( $amount < 1 || ! in_array( $period, array( 'days', 'weeks', 'months', 'years' ), true ) ) ) {
		return new WP_Error( 'minn_wcm_plan', __( 'A specific length needs a number of days, weeks, months or years.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( 'fixed' === $ltype ) {
		$re = '/^\d{4}-\d{2}-\d{2}$/';
		if ( ! preg_match( $re, $start ) || ! preg_match( $re, $end ) || ! strtotime( $start ) || ! strtotime( $end ) ) {
			return new WP_Error( 'minn_wcm_plan', __( 'Fixed dates need a start and an end day.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( strtotime( $start ) >= strtotime( $end ) ) {
			return new WP_Error( 'minn_wcm_plan', __( 'The access end date has to come after the start date.', 'minn-admin' ), array( 'status' => 400 ) );
		}
	}
	return compact( 'method', 'product_ids', 'ltype', 'amount', 'period', 'start', 'end' );
}

/**
 * Write the general fields (access method, length, products, sections).
 *
 * Validation lives in minn_admin_wcm_plan_general_clean() so a route can
 * refuse the whole request before any post row is touched; pass its
 * result as $clean to skip re-validating, or omit it to validate here.
 *
 * @param WC_Memberships_Membership_Plan $plan  Plan.
 * @param array                          $in    Request body.
 * @param array|null                     $clean Output of the clean step.
 * @return true|WP_Error
 */
function minn_admin_wcm_plan_apply_general( $plan, $in, $clean = null ) {
	if ( null === $clean ) {
		$clean = minn_admin_wcm_plan_general_clean( $plan, $in );
	}
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$method      = $clean['method'];
	$product_ids = $clean['product_ids'];
	$ltype       = $clean['ltype'];
	$amount      = $clean['amount'];
	$period      = $clean['period'];
	$start       = $clean['start'];
	$end         = $clean['end'];

	$plan->set_access_method( $method );
	// Their meta box starts every save from unlimited and re-applies.
	$plan->delete_access_length();
	$plan->delete_access_start_date();
	$plan->delete_access_end_date();
	if ( 'specific' === $ltype ) {
		$plan->set_access_length( $amount . ' ' . $period );
	} elseif ( 'fixed' === $ltype ) {
		// Local midnight converted to UTC, as their save does.
		$plan->set_access_start_date( get_gmt_from_date( $start . ' 00:00:00' ) );
		$plan->set_access_end_date( get_gmt_from_date( $end . ' 00:00:00' ) );
	}
	if ( 'purchase' === $method ) {
		$plan->set_product_ids( $product_ids );
	} else {
		$plan->delete_product_ids();
	}

	if ( array_key_exists( 'sections', $in ) ) {
		$plan->set_members_area_sections( array_values( array_map( 'sanitize_key', (array) $in['sections'] ) ) );
	}
	return true;
}

/**
 * Load a plan for the page routes, or a WP_Error.
 *
 * @param int    $id    Plan id.
 * @param string $write '' | edit_post | delete_post.
 * @return WC_Memberships_Membership_Plan|WP_Error
 */
function minn_admin_wcm_plan_load( $id, $write = '' ) {
	$post = (int) $id ? get_post( (int) $id ) : null;
	$plan = $post && 'wc_membership_plan' === $post->post_type ? wc_memberships_get_membership_plan( $post ) : false;
	if ( ! $plan ) {
		return new WP_Error( 'minn_wcm_not_found', __( 'Membership plan not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( '' !== $write && ! current_user_can( $write, (int) $id ) ) {
		return new WP_Error( 'minn_wcm_forbidden', __( 'You are not allowed to change this plan.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	return $plan;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wcm_active() ) {
		return $surfaces;
	}

	$statuses = array();
	foreach ( minn_admin_wcm_statuses() as $slug => $label ) {
		$statuses[] = array( $slug, $label );
	}

	$member_actions = array(
		array(
			'label' => __( 'Pause', 'minn-admin' ),
			'route' => 'minn-admin/v1/wcm/members/{id}/pause',
			'when'  => array( 'key' => 'pausable', 'equals' => true ),
		),
		array(
			'label' => __( 'Resume', 'minn-admin' ),
			'route' => 'minn-admin/v1/wcm/members/{id}/resume',
			'when'  => array( 'key' => 'resumable', 'equals' => true ),
		),
		array(
			'label'   => __( 'Cancel membership', 'minn-admin' ),
			'route'   => 'minn-admin/v1/wcm/members/{id}/cancel',
			'when'    => array( 'key' => 'cancellable', 'equals' => true ),
			'confirm' => __( 'Cancel this membership? The member loses access now, and a cancelled membership can only be renewed, not resumed.', 'minn-admin' ),
			'danger'  => true,
		),
		array(
			'label'  => __( 'Set end date', 'minn-admin' ),
			'route'  => 'minn-admin/v1/wcm/members/{id}/end-date',
			'fields' => array(
				array(
					'key'         => 'end_date',
					'label'       => __( 'Ends on', 'minn-admin' ),
					'placeholder' => __( 'YYYY-MM-DD, blank for no end', 'minn-admin' ),
					'required'    => false,
				),
			),
		),
		array(
			'label'  => __( 'Add note', 'minn-admin' ),
			'route'  => 'minn-admin/v1/wcm/members/{id}/notes',
			'list'   => true,
			'fields' => array(
				array(
					'key'   => 'note',
					'label' => __( 'Note', 'minn-admin' ),
					'type'  => 'textarea',
					'rows'  => 3,
				),
				array(
					'key'      => 'notify',
					'label'    => __( 'Email this note to the member', 'minn-admin' ),
					'type'     => 'toggle',
					'required' => false,
				),
			),
		),
		array(
			'label'  => __( 'Transfer to another customer', 'minn-admin' ),
			'route'  => 'minn-admin/v1/wcm/members/{id}/transfer',
			'fields' => array(
				array(
					'key'         => 'user',
					'label'       => __( 'New member', 'minn-admin' ),
					'placeholder' => __( 'Email address or username', 'minn-admin' ),
				),
			),
		),
		array(
			'label' => __( 'View customer', 'minn-admin' ),
			'href'  => home_url( '/minn-admin/customers/{user_id}' ),
		),
		array(
			'label' => __( 'View order', 'minn-admin' ),
			'href'  => home_url( '/minn-admin/orders/{order_id}' ),
			'when'  => array( 'key' => 'has_order', 'equals' => true ),
		),
		array(
			'label'   => __( 'Delete', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/wcm/members/{id}',
			'confirm' => __( 'Delete this membership permanently? Its notes go with it. To end access but keep the record, cancel it instead.', 'minn-admin' ),
			'danger'  => true,
		),
	);

	$surfaces['woocommerce-memberships'] = array(
		'label'      => __( 'Memberships', 'minn-admin' ),
		'family'     => 'memberships',
		'sub'        => 'WooCommerce',
		'plugin'     => 'woocommerce-memberships',
		'icon'       => 'key',
		'cap'        => 'manage_woocommerce_user_memberships',
		'group'      => 'commerce',
		'status'     => array( 'route' => 'minn-admin/v1/wcm/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wcm/members',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => __( 'Members', 'minn-admin' ),
			'search'    => 'search={q}',
			'sortQuery' => 'orderby={by}&order={dir}',
			'dateQuery' => 'after={from}&before={to}',
			'filterBar' => array(
				'searchPlaceholder' => __( 'Search members (name, email, username…)', 'minn-admin' ),
				'statuses'          => $statuses,
				// customer narrows on the member; product on the product that
				// granted the membership; date on when it was created.
				'kinds'             => array( 'status', 'date', 'customer', 'product', 'plan' ),
			),
			'columns'   => array(
				array( 'key' => 'member', 'label' => __( 'Member', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'email', 'label' => __( 'Email', 'minn-admin' ), 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'plan', 'label' => __( 'Plan', 'minn-admin' ), 'width' => 'minmax(0,1fr)', 'sort' => 'plan' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '150px' ),
				array( 'key' => 'since', 'label' => __( 'Member since', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'sort' => 'since' ),
				array( 'key' => 'expires', 'label' => __( 'Expires', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'sort' => 'expires' ),
				array( 'key' => 'order', 'label' => __( 'Order', 'minn-admin' ), 'format' => 'mono', 'width' => '90px' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wcm/members/{id}/view',
			),
			// A membership earns a page: it has notes, billing and the member's
			// other plans as sub-resources, and a form-shaped edit.
			'open'      => array( 'route' => 'memberships/{id}' ),
			'actions'   => $member_actions,
			'bulk'      => array(
				array(
					'label' => __( 'Pause', 'minn-admin' ),
					'route' => 'minn-admin/v1/wcm/members/{id}/pause',
					'when'  => array( 'key' => 'pausable', 'equals' => true ),
				),
				array(
					'label' => __( 'Resume', 'minn-admin' ),
					'route' => 'minn-admin/v1/wcm/members/{id}/resume',
					'when'  => array( 'key' => 'resumable', 'equals' => true ),
				),
				array(
					'label'   => __( 'Cancel', 'minn-admin' ),
					'route'   => 'minn-admin/v1/wcm/members/{id}/cancel',
					'when'    => array( 'key' => 'cancellable', 'equals' => true ),
					'confirm' => __( 'Cancel the selected memberships? Those members lose access now.', 'minn-admin' ),
					'danger'  => true,
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wcm/members/{id}',
					'confirm' => __( 'Delete the selected memberships permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'create'    => array(
				'label'  => __( 'Add member', 'minn-admin' ),
				'route'  => 'minn-admin/v1/wcm/members',
				'fields' => array(
					array(
						'key'         => 'customer',
						'label'       => __( 'Customer', 'minn-admin' ),
						'placeholder' => __( 'Email address or username of an existing account', 'minn-admin' ),
					),
					array(
						'key'     => 'plan_id',
						'label'   => __( 'Plan', 'minn-admin' ),
						'type'    => 'select',
						'options' => minn_admin_wcm_plan_options(),
					),
					array(
						'key'         => 'end_date',
						'label'       => __( 'Ends on', 'minn-admin' ),
						'placeholder' => __( 'YYYY-MM-DD, blank to use the plan\'s length', 'minn-admin' ),
						'required'    => false,
					),
				),
			),
		),
		'manage'     => array(
			'route'     => 'minn-admin/v1/wcm/plans',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => __( 'Plans', 'minn-admin' ),
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'status',
				'allLabel' => __( 'All', 'minn-admin' ),
				'static'   => array(
					array( 'publish', __( 'Published', 'minn-admin' ) ),
					array( 'draft', __( 'Draft', 'minn-admin' ) ),
				),
			),
			// Thirteen columns once rode here, ten of them fixed-width counts
			// that added up to a thousand pixels before Plan, Access and Length
			// got anything: on a laptop the plan names truncated to three
			// letters. The list keeps the counts a reader scans plans by
			// (members, active, expired) and floors the name column; the
			// full per-status breakdown lives on the plan's own page, and
			// View members narrows the list by plan and status. The rows still
			// carry every count for the detail and the suite.
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Plan', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(150px,1.6fr)' ),
				array( 'key' => 'access', 'label' => __( 'Access', 'minn-admin' ), 'width' => 'minmax(100px,1fr)' ),
				array( 'key' => 'length', 'label' => __( 'Length', 'minn-admin' ), 'width' => 'minmax(100px,1fr)' ),
				array( 'key' => 'total', 'label' => __( 'Members', 'minn-admin' ), 'format' => 'num', 'width' => '76px' ),
				array( 'key' => 'members', 'label' => __( 'Active', 'minn-admin' ), 'format' => 'num', 'width' => '72px' ),
				array( 'key' => 'expired', 'label' => __( 'Expired', 'minn-admin' ), 'format' => 'num', 'width' => '72px' ),
				array( 'key' => 'products', 'label' => __( 'Products', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'rules', 'label' => __( 'Rules', 'minn-admin' ), 'format' => 'num', 'width' => '64px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wcm/plans/{id}/view',
			),
			// A plan earns a page too: rules, products and members are its
			// sub-resources, and every field is form-shaped.
			'open'      => array( 'route' => 'membership-plans/{id}' ),
			'create'    => array(
				'label'  => __( 'Add plan', 'minn-admin' ),
				'route'  => 'minn-admin/v1/wcm/plans',
				'fields' => array(
					array( 'key' => 'name', 'label' => __( 'Plan name', 'minn-admin' ) ),
					array(
						'key'     => 'status',
						'label'   => __( 'Status', 'minn-admin' ),
						'type'    => 'select',
						'value'   => 'draft',
						'options' => array(
							array( 'draft', __( 'Draft', 'minn-admin' ) ),
							array( 'publish', __( 'Published', 'minn-admin' ) ),
						),
					),
				),
			),
			'actions'   => array(
				// The members list, narrowed to this plan: the same page a
				// count on the plugin's own summary would open.
				array(
					'label' => __( 'View members', 'minn-admin' ),
					'href'  => home_url( '/minn-admin/woocommerce-memberships?plan={id}' ),
				),
				array(
					'label' => __( 'Duplicate', 'minn-admin' ),
					'route' => 'minn-admin/v1/wcm/plans/{id}/duplicate',
				),
				array(
					'label' => __( 'Edit plan in WooCommerce', 'minn-admin' ),
					'href'  => admin_url( 'post.php?post={id}&action=edit' ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wcm/plans/{id}',
					'when'    => array( 'key' => 'deletable', 'equals' => true ),
					'confirm' => __( 'Delete this plan permanently? Every membership on it, active or not, is deleted with it, along with its rules.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wcm_active() ) {
		return;
	}

	$permission = 'minn_admin_wcm_can';

	register_rest_route( 'minn-admin/v1', '/wcm/members', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = trim( (string) $request->get_param( 'search' ) );
			$known    = minn_admin_wcm_statuses();

			$wanted = $request->get_param( 'status' );
			$wanted = is_array( $wanted ) ? $wanted : ( ( '' === (string) $wanted ) ? array() : array( $wanted ) );
			$wanted = array_values( array_filter( array_map( 'strval', $wanted ), function ( $s ) use ( $known ) {
				return 'any' !== $s && isset( $known[ preg_replace( '/^wcm-/', '', $s ) ] );
			} ) );
			$status = $wanted ? array_map( 'minn_admin_wcm_prefix', $wanted ) : array_map( 'minn_admin_wcm_prefix', array_keys( $known ) );

			$args = array(
				'post_type'      => 'wc_user_membership',
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			$orderby = (string) $request->get_param( 'orderby' );
			$dir     = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';
			if ( 'since' === $orderby || 'expires' === $orderby ) {
				// The plugin's own list sorts these columns on the same meta.
				$args['meta_key'] = 'since' === $orderby ? '_start_date' : '_end_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value';
				$args['order']    = $dir;
			} elseif ( 'plan' === $orderby ) {
				$args['orderby'] = 'parent';
				$args['order']   = $dir;
			}

			$customer = (int) $request->get_param( 'customer' );
			if ( $customer > 0 ) {
				$args['author'] = $customer;
			}
			$plan = (int) $request->get_param( 'plan' );
			if ( $plan > 0 ) {
				$args['post_parent'] = $plan;
			}
			$product = (int) $request->get_param( 'product' );
			if ( $product > 0 ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_product_id', 'value' => $product ),
				);
			}

			// The date window narrows on when the membership was created
			// (post_date, site-local), the column the status chart buckets.
			$date_query = array( 'inclusive' => true );
			foreach ( array( 'after', 'before' ) as $bound ) {
				$raw = trim( (string) $request->get_param( $bound ) );
				if ( '' !== $raw && strtotime( $raw ) ) {
					$date_query[ $bound ] = $raw;
				}
			}
			if ( isset( $date_query['after'] ) || isset( $date_query['before'] ) ) {
				$args['date_query'] = array( $date_query );
			}

			if ( '' !== $search ) {
				// Members are users: match the account, then list its
				// memberships. A bare number also matches a membership id.
				$users = new WP_User_Query( array(
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
					'fields'         => 'ID',
					'number'         => 300,
				) );
				$ids = array_map( 'intval', (array) $users->get_results() );
				if ( ! $ids ) {
					$named = new WP_User_Query( array(
						'fields'     => 'ID',
						'number'     => 300,
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							'relation' => 'OR',
							array( 'key' => 'first_name', 'value' => $search, 'compare' => 'LIKE' ),
							array( 'key' => 'last_name', 'value' => $search, 'compare' => 'LIKE' ),
						),
					) );
					$ids = array_map( 'intval', (array) $named->get_results() );
				}
				if ( $customer > 0 ) {
					$ids = in_array( $customer, $ids, true ) ? array( $customer ) : array();
				}
				if ( $ids ) {
					unset( $args['author'] );
					$args['author__in'] = $ids;
				} elseif ( ctype_digit( $search ) ) {
					$args['post__in'] = array( (int) $search );
				} else {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
			}

			$q     = new WP_Query( $args );
			$items = array();
			foreach ( $q->posts as $post ) {
				$m = wc_memberships_get_user_membership( $post );
				if ( $m ) {
					$items[] = minn_admin_wcm_row( $m );
				}
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$row  = minn_admin_wcm_row( $m );
			$user = $m->get_user();
			$plan = $m->get_plan();

			$membership = array(
				array( 'label' => __( 'Plan', 'minn-admin' ), 'value' => $row['plan'] ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $row['status'], 'type' => 'pill' ),
			);
			$since = minn_admin_wcm_local( $m->get_start_date( 'mysql' ) );
			if ( $since ) {
				$membership[] = array( 'label' => __( 'Member since', 'minn-admin' ), 'value' => $since );
			}
			$end = (string) $m->get_end_date( 'mysql' );
			$membership[] = array(
				'label' => __( 'Ends', 'minn-admin' ),
				'value' => $end ? minn_admin_wcm_local( $end ) : __( 'Never', 'minn-admin' ),
			);
			if ( $m->has_status( 'paused' ) ) {
				$membership[] = array( 'label' => __( 'Paused since', 'minn-admin' ), 'value' => minn_admin_wcm_local( $m->get_paused_date( 'mysql' ), true ) );
			}
			if ( $m->has_status( 'cancelled' ) ) {
				$membership[] = array( 'label' => __( 'Cancelled', 'minn-admin' ), 'value' => minn_admin_wcm_local( $m->get_cancelled_date( 'mysql' ), true ) );
			}
			try {
				$active_seconds = (int) $m->get_total_active_time( 'timestamp' );
				if ( $active_seconds > 0 ) {
					$membership[] = array( 'label' => __( 'Time active', 'minn-admin' ), 'value' => human_time_diff( time() - $active_seconds, time() ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$member = array();
			if ( $user instanceof WP_User ) {
				$member[] = array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $row['member'] );
				$member[] = array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $user->user_email, 'type' => 'email' );
				$member[] = array( 'label' => __( 'Username', 'minn-admin' ), 'value' => $user->user_login );
			} else {
				$member[] = array( 'label' => __( 'Account', 'minn-admin' ), 'value' => $row['member'] );
			}
			$previous = array();
			try {
				foreach ( (array) $m->get_previous_owners() as $ts => $uid ) {
					$prev = get_user_by( 'id', (int) $uid );
					$previous[] = array(
						'label' => wp_date( get_option( 'date_format' ), (int) $ts ),
						'value' => $prev instanceof WP_User ? $prev->user_email : minn_admin_wcm_member_name( null, (int) $uid ),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( $previous ) {
				$member[] = array( 'label' => __( 'Previous members', 'minn-admin' ), 'value' => $previous, 'type' => 'kv-table' );
			}

			$origin = array();
			if ( $row['has_order'] ) {
				$origin[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => $row['order'] );
			}
			if ( $row['product_id'] > 0 ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $row['product_id'] ) : null;
				$origin[] = array(
					'label' => __( 'Product', 'minn-admin' ),
					'value' => $product ? $product->get_name() : '#' . $row['product_id'],
				);
			}
			$post = get_post( $m->get_id() );
			if ( $post ) {
				$origin[] = array( 'label' => __( 'Created', 'minn-admin' ), 'value' => minn_admin_wcm_local( $post->post_date_gmt, true ) );
			}
			if ( ! $origin ) {
				$origin[] = array( 'label' => __( 'Granted', 'minn-admin' ), 'value' => __( 'By hand', 'minn-admin' ) );
			}

			$profile = array();
			try {
				foreach ( (array) $m->get_profile_fields() as $field ) {
					if ( ! is_object( $field ) || ! method_exists( $field, 'get_definition' ) ) {
						continue;
					}
					$def   = $field->get_definition();
					$value = method_exists( $field, 'get_formatted_value' ) ? $field->get_formatted_value() : $field->get_value();
					if ( is_array( $value ) ) {
						$value = implode( ', ', array_map( 'strval', $value ) );
					}
					$profile[] = array(
						'label' => $def && method_exists( $def, 'get_name' ) ? (string) $def->get_name() : (string) $field->get_slug(),
						'value' => wp_strip_all_tags( (string) $value ),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$notes = array();
			foreach ( minn_admin_wcm_notes( $m ) as $note ) {
				$notes[] = array(
					'label' => $note['when'] . ' · ' . $note['author'],
					'value' => $note['text'],
				);
			}

			$sections = array(
				array( 'title' => __( 'Membership', 'minn-admin' ), 'rows' => $membership ),
				array( 'title' => __( 'Member', 'minn-admin' ), 'rows' => $member ),
				array( 'title' => __( 'Origin', 'minn-admin' ), 'rows' => $origin ),
			);
			if ( $profile ) {
				$sections[] = array( 'title' => __( 'Profile fields', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Answers', 'minn-admin' ), 'value' => $profile, 'type' => 'kv-table' ) ) );
			}
			if ( $notes ) {
				$sections[] = array( 'title' => __( 'Notes', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Newest first', 'minn-admin' ), 'value' => $notes, 'type' => 'kv-table' ) ) );
			}

			return rest_ensure_response( array(
				'title'    => sprintf(
					/* translators: 1: member name, 2: membership plan name. */
					__( '%1$s · %2$s', 'minn-admin' ),
					$row['member'],
					$row['plan']
				),
				'sections' => $sections,
				'adminUrl' => admin_url( 'post.php?post=' . (int) $m->get_id() . '&action=edit' ),
			) );
		},
	) );

	// Pause / resume / cancel through the plugin's own wrappers: each one is
	// a status transition, and the transition handler is where the note, the
	// paused interval, the expiry schedule and the member role are kept
	// consistent. Every route records who did it in the transition note.
	$transition = function ( $verb ) {
		return function ( $request ) use ( $verb ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$actor = wp_get_current_user();
			$who   = $actor && $actor->user_login ? $actor->user_login : __( 'a site administrator', 'minn-admin' );
			/* translators: %s: username of the person who made the change. */
			$note = sprintf( __( 'Changed in Minn Admin by %s.', 'minn-admin' ), $who );
			$name = minn_admin_wcm_member_name( $m->get_user(), $m->get_user_id() );
			try {
				if ( 'pause' === $verb ) {
					if ( $m->has_status( array( 'paused', 'cancelled' ) ) ) {
						return new WP_Error( 'minn_wcm_state', __( 'This membership cannot be paused from its current status.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$m->pause_membership( $note );
					/* translators: %s: member name. */
					$message = sprintf( __( 'Paused %s\'s membership.', 'minn-admin' ), $name );
				} elseif ( 'resume' === $verb ) {
					if ( ! $m->has_status( 'paused' ) ) {
						return new WP_Error( 'minn_wcm_state', __( 'Only a paused membership can be resumed.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$m->activate_membership( $note );
					/* translators: %s: member name. */
					$message = sprintf( __( 'Resumed %s\'s membership.', 'minn-admin' ), $name );
				} else {
					if ( $m->is_cancelled() ) {
						return new WP_Error( 'minn_wcm_state', __( 'This membership is already cancelled.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$m->cancel_membership( $note );
					/* translators: %s: member name. */
					$message = sprintf( __( 'Cancelled %s\'s membership.', 'minn-admin' ), $name );
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_failed', $e->getMessage() ? $e->getMessage() : __( 'The change could not be saved.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'message' => $message ) );
		};
	};
	foreach ( array( 'pause', 'resume', 'cancel' ) as $verb ) {
		register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/' . $verb, array(
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'callback'            => $transition( $verb ),
		) );
	}

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/end-date', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$ok = minn_admin_wcm_apply_end_date( $m, $request->get_param( 'end_date' ) );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$end = (string) $m->get_end_date( 'mysql' );
			return rest_ensure_response( array(
				'message' => $end
					? sprintf(
						/* translators: %s: formatted date. */
						__( 'Membership now ends on %s.', 'minn-admin' ),
						minn_admin_wcm_local( $end )
					)
					: __( 'Membership no longer has an end date.', 'minn-admin' ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/notes', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
			if ( '' === $note ) {
				return new WP_Error( 'minn_wcm_note', __( 'Write the note first.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$notify = rest_sanitize_boolean( $request->get_param( 'notify' ) );
			$id     = $m->add_note( $note, $notify );
			if ( ! $id ) {
				return new WP_Error( 'minn_wcm_note', __( 'The note could not be saved.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'message' => $notify ? __( 'Note added and emailed to the member.', 'minn-admin' ) : __( 'Note added.', 'minn-admin' ),
				'notes'   => minn_admin_wcm_notes( $m ),
			) );
		},
	) );

	// Link the membership to a subscription, move it to another one, or
	// unlink it: the plugin's own Edit Link control on its membership screen.
	// Its handler is mirrored exactly, ownership included: it never checks that
	// the subscription belongs to the member, because a gifted subscription is
	// owned by the buyer while the membership belongs to the recipient.
	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/subscription', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) Minn_Admin::path_param( $request, 'id' );
			$m  = minn_admin_wcm_load( $id, 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			if ( ! class_exists( 'WC_Subscriptions' ) || ! class_exists( 'WC_Memberships_Integration_Subscriptions_User_Membership' ) || ! function_exists( 'wcs_get_subscription' ) ) {
				return new WP_Error( 'unsupported', __( 'WooCommerce Subscriptions is not active on this site.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$integration = wc_memberships()->get_integrations_instance()->get_subscriptions_instance();
			$linked      = new WC_Memberships_Integration_Subscriptions_User_Membership( get_post( $id ) );
			$old         = (int) $linked->get_subscription_id();
			$new         = (int) $request->get_param( 'subscription_id' );
			try {
				if ( $new > 0 ) {
					$sub = wcs_get_subscription( $new );
					if ( ! $sub ) {
						/* translators: %d: subscription id. */
						return new WP_Error( 'not_found', sprintf( __( 'Subscription #%d was not found.', 'minn-admin' ), $new ), array( 'status' => 400 ) );
					}
					if ( $new !== $old ) {
						if ( ! $linked->set_subscription_id( $new ) ) {
							return new WP_Error( 'link_failed', __( 'The subscription could not be linked.', 'minn-admin' ), array( 'status' => 500 ) );
						}
						$trial_end = $integration && method_exists( $integration, 'get_subscription_event_date' ) ? $integration->get_subscription_event_date( $sub, 'trial_end' ) : null;
						if ( $trial_end ) {
							$linked->set_free_trial_end_date( $trial_end );
						}
					}
				} elseif ( $old > 0 ) {
					$integration->unlink_membership( $linked, $old );
				} else {
					return new WP_Error( 'not_linked', __( 'This membership is not linked to a subscription.', 'minn-admin' ), array( 'status' => 400 ) );
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'link_failed', __( 'The subscription link could not be changed.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			if ( $integration && method_exists( $integration, 'prune_membership_link_cache' ) ) {
				$integration->prune_membership_link_cache( $linked );
			}
			$fresh = wc_memberships_get_user_membership( $id );
			return rest_ensure_response( $fresh ? minn_admin_wcm_page_model( $fresh ) : array( 'ok' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/transfer', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$user = minn_admin_wcm_find_user( $request->get_param( 'user' ) );
			if ( ! $user ) {
				return new WP_Error( 'minn_wcm_user', __( 'No account matches that email address or username.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			try {
				// Their method validates (same user, already a member of the
				// plan, not transferable) and throws with a human message.
				$m->transfer_ownership( $user );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_transfer', $e->getMessage() ? $e->getMessage() : __( 'The membership could not be transferred.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: %s: email address of the new member. */
					__( 'Membership transferred to %s.', 'minn-admin' ),
					$user->user_email
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'delete_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$name = minn_admin_wcm_member_name( $m->get_user(), $m->get_user_id() );
			$plan = $m->get_plan();
			try {
				// Their deleter force-deletes (the plugin removes Trash for
				// this type) and its delete_post hook unschedules expiry,
				// fixes the member role and drops orphan profile values.
				wc_memberships()->get_user_memberships_instance()->deleteUserMembership( (int) $m->get_id() );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_delete', $e->getMessage() ? $e->getMessage() : __( 'The membership could not be deleted.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			if ( get_post( (int) Minn_Admin::path_param( $request ) ) ) {
				return new WP_Error( 'minn_wcm_delete', __( 'The membership could not be deleted.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: 1: member name, 2: plan name. */
					__( 'Deleted %1$s\'s %2$s membership.', 'minn-admin' ),
					$name,
					$plan ? $plan->get_name() : __( 'plan', 'minn-admin' )
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_wcm_can() && current_user_can( 'publish_user_memberships' );
		},
		'callback'            => function ( $request ) {
			$user = minn_admin_wcm_find_user( $request->get_param( 'customer' ) );
			if ( ! $user ) {
				return new WP_Error( 'minn_wcm_user', __( 'No account matches that email address or username. Create the customer first.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$plan_id = (int) $request->get_param( 'plan_id' );
			$plan    = $plan_id ? wc_memberships_get_membership_plan( $plan_id ) : false;
			if ( ! $plan ) {
				return new WP_Error( 'minn_wcm_plan', __( 'Pick a membership plan.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			// Their creator does not check for an existing membership; their
			// REST controller does, and one membership per user and plan is
			// the model (a second one would be invisible to their screens).
			if ( wc_memberships_get_user_membership( $user->ID, $plan_id ) ) {
				return new WP_Error( 'minn_wcm_exists', sprintf(
					/* translators: 1: member email, 2: plan name. */
					__( '%1$s already has a %2$s membership. Renew or edit that one instead.', 'minn-admin' ),
					$user->user_email,
					$plan->get_name()
				), array( 'status' => 400 ) );
			}
			try {
				$m = wc_memberships_create_user_membership( array(
					'plan_id' => $plan_id,
					'user_id' => (int) $user->ID,
				) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_create', $e->getMessage() ? $e->getMessage() : __( 'The membership could not be created.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! $m || ! $m->get_id() ) {
				return new WP_Error( 'minn_wcm_create', __( 'The membership could not be created.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$raw_end = trim( (string) $request->get_param( 'end_date' ) );
			if ( '' !== $raw_end ) {
				$ok = minn_admin_wcm_apply_end_date( $m, $raw_end );
				if ( is_wp_error( $ok ) ) {
					// The membership exists with the plan's own length; say so.
					return new WP_Error( 'minn_wcm_date', sprintf(
						/* translators: %s: the date validation message. */
						__( 'Membership created with the plan\'s length, but the end date was not applied: %s', 'minn-admin' ),
						$ok->get_error_message()
					), array( 'status' => 400 ) );
				}
			}
			$actor = wp_get_current_user();
			$m->add_note( sprintf(
				/* translators: %s: username of the person who granted the membership. */
				__( 'Membership granted in Minn Admin by %s.', 'minn-admin' ),
				$actor && $actor->user_login ? $actor->user_login : __( 'a site administrator', 'minn-admin' )
			), false );
			return rest_ensure_response( array(
				'id'      => (int) $m->get_id(),
				'message' => sprintf(
					/* translators: 1: member email, 2: plan name. */
					__( '%1$s is now a %2$s member.', 'minn-admin' ),
					$user->user_email,
					$plan->get_name()
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			return rest_ensure_response( minn_admin_wcm_page_model( $m ) );
		},
	) );

	// The page's Save: plan, status and the two dates, applied in the order
	// the plugin's own edit screen applies them (status first, then the dates
	// with their expire-in-the-past / revive-in-the-future rule). Only fields
	// that arrived AND changed are written, so an untouched date never gets
	// its time rounded to midnight.
	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$id      = (int) $m->get_id();
			$changed = array();
			$actor   = wp_get_current_user();
			/* translators: %s: username of the person who made the change. */
			$note = sprintf( __( 'Changed in Minn Admin by %s.', 'minn-admin' ), $actor && $actor->user_login ? $actor->user_login : __( 'a site administrator', 'minn-admin' ) );

			$plan_id = $request->get_param( 'plan_id' );
			if ( null !== $plan_id && (int) $plan_id > 0 && (int) $plan_id !== (int) $m->get_plan_id() ) {
				$plan = wc_memberships_get_membership_plan( (int) $plan_id );
				if ( ! $plan ) {
					return new WP_Error( 'minn_wcm_plan', __( 'That membership plan no longer exists.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$other = wc_memberships_get_user_membership( $m->get_user_id(), (int) $plan_id );
				if ( $other && (int) $other->get_id() !== $id ) {
					return new WP_Error( 'minn_wcm_exists', sprintf(
						/* translators: %s: plan name. */
						__( 'This member already holds a %s membership, so this one cannot be moved onto that plan.', 'minn-admin' ),
						$plan->get_name()
					), array( 'status' => 400 ) );
				}
				// Their REST controller moves a membership between plans with
				// exactly this write.
				wp_update_post( array( 'ID' => $id, 'post_parent' => (int) $plan_id ) );
				$changed[] = 'plan';
			}

			$status = $request->get_param( 'status' );
			if ( null !== $status && '' !== (string) $status ) {
				$status = preg_replace( '/^wcm-/', '', (string) $status );
				if ( ! isset( minn_admin_wcm_status_options( $id )[ $status ] ) ) {
					return new WP_Error( 'minn_wcm_status', __( 'That is not a membership status.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( $status !== (string) $m->get_status() ) {
					try {
						$m->update_status( $status, $note );
					} catch ( \Throwable $e ) {
						return new WP_Error( 'minn_wcm_status', $e->getMessage() ? $e->getMessage() : __( 'The status could not be changed.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$changed[] = 'status';
				}
			}

			$start = $request->get_param( 'start_date' );
			if ( null !== $start && '' !== trim( (string) $start ) ) {
				$start = trim( (string) $start );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! strtotime( $start ) ) {
					return new WP_Error( 'minn_wcm_date', __( 'Enter the start date as YYYY-MM-DD.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( $start !== minn_admin_wcm_local_day( $m->get_start_date( 'mysql' ) ) ) {
					$m->set_start_date( get_gmt_from_date( $start . ' 00:00:00' ) );
					$changed[] = 'start';
				}
			}

			$end = $request->get_param( 'end_date' );
			if ( null !== $end ) {
				$end = trim( (string) $end );
				$cur = minn_admin_wcm_local_day( $m->get_end_date( 'mysql' ) );
				if ( $end !== $cur ) {
					$ok = minn_admin_wcm_apply_end_date( $m, $end );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					$changed[] = 'end';
				}
			}

			$fresh = wc_memberships_get_user_membership( $id );
			return rest_ensure_response( array(
				'changed' => $changed,
				'message' => $changed ? __( 'Membership saved.', 'minn-admin' ) : __( 'Nothing to save.', 'minn-admin' ),
				'model'   => minn_admin_wcm_page_model( $fresh ? $fresh : $m ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/notes/(?P<note>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $m ) ) {
				return $m;
			}
			$note = get_comment( (int) Minn_Admin::path_param( $request, 'note' ) );
			if ( ! $note || 'user_membership_note' !== $note->comment_type || (int) $note->comment_post_ID !== (int) $m->get_id() ) {
				return new WP_Error( 'minn_wcm_note', __( 'Note not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			// Their own delete-note handler is wp_delete_comment, nothing more.
			if ( ! wp_delete_comment( (int) $note->comment_ID, true ) ) {
				return new WP_Error( 'minn_wcm_note', __( 'The note could not be deleted.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'message' => __( 'Note deleted.', 'minn-admin' ), 'notes' => minn_admin_wcm_notes( $m ) ) );
		},
	) );

	// ----- Plan page: model, create, update, duplicate, delete, lookup -----

	register_rest_route( 'minn-admin/v1', '/wcm/plans/blank', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_wcm_plan_model( null ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) {
			$plan = minn_admin_wcm_plan_load( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return rest_ensure_response( minn_admin_wcm_plan_model( $plan ) );
		},
	) );

	// The page's fields, shared by create and update. Both run the plugin's
	// own code: createPlan() inserts, the setters and SetPlanRules configure.
	$apply_plan = function ( $plan, $request, $is_new ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) {
			$in = array();
		}
		$id = (int) $plan->get_id();
		$post_update = array( 'ID' => $id );
		if ( isset( $in['name'] ) ) {
			$name = sanitize_text_field( (string) $in['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'minn_wcm_plan', __( 'A plan needs a name.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$post_update['post_title'] = $name;
		}
		if ( isset( $in['slug'] ) && '' !== trim( (string) $in['slug'] ) ) {
			$post_update['post_name'] = sanitize_title( (string) $in['slug'] );
		}
		if ( isset( $in['description'] ) ) {
			$post_update['post_content'] = current_user_can( 'unfiltered_html' ) ? (string) $in['description'] : wp_kses_post( (string) $in['description'] );
		}
		if ( isset( $in['status'] ) ) {
			if ( ! in_array( (string) $in['status'], array( 'publish', 'draft' ), true ) ) {
				return new WP_Error( 'minn_wcm_plan', __( 'A plan is either published or a draft.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			// Publishing is its own capability on the plan post type.
			if ( 'publish' === $in['status'] && 'publish' !== get_post_status( $id ) && ! current_user_can( 'publish_post', $id ) ) {
				return new WP_Error( 'minn_wcm_plan', __( 'You are not allowed to publish this plan.', 'minn-admin' ), array( 'status' => 403 ) );
			}
			$post_update['post_status'] = (string) $in['status'];
		}
		// Validate the rules before anything is written, so a refused rule
		// does not leave a renamed or republished plan behind.
		$rules = null;
		if ( array_key_exists( 'rules', $in ) ) {
			$rules = minn_admin_wcm_plan_rules_clean( $in['rules'], $plan );
			if ( is_wp_error( $rules ) ) {
				return $rules;
			}
		}
		$general = minn_admin_wcm_plan_general_clean( $plan, $in );
		if ( is_wp_error( $general ) ) {
			return $general;
		}
		if ( count( $post_update ) > 1 ) {
			$r = wp_update_post( $post_update, true );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'minn_wcm_plan', $r->get_error_message(), array( 'status' => 400 ) );
			}
		}
		$ok = minn_admin_wcm_plan_apply_general( $plan, $in, $general );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( null !== $rules ) {
			$ok = minn_admin_wcm_plan_rules_apply( $plan, $rules );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$fresh = wc_memberships_get_membership_plan( $id );
		return rest_ensure_response( array(
			'id'      => $id,
			'message' => $is_new ? __( 'Plan created.', 'minn-admin' ) : __( 'Plan saved.', 'minn-admin' ),
			'model'   => minn_admin_wcm_plan_model( $fresh ? $fresh : $plan ),
		) );
	};

	register_rest_route( 'minn-admin/v1', '/wcm/plans', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_wcm_can_plans() && current_user_can( 'publish_membership_plans' );
		},
		'callback'            => function ( $request ) use ( $apply_plan ) {
			$in   = $request->get_json_params();
			$name = sanitize_text_field( (string) ( is_array( $in ) && isset( $in['name'] ) ? $in['name'] : $request->get_param( 'name' ) ) );
			if ( '' === $name ) {
				return new WP_Error( 'minn_wcm_plan', __( 'A plan needs a name.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$status = is_array( $in ) && isset( $in['status'] ) && 'draft' === $in['status'] ? 'draft' : ( 'draft' === (string) $request->get_param( 'status' ) ? 'draft' : 'publish' );
			try {
				// Their action inserts the post and configures a manual,
				// unlimited plan; the page's fields are applied on top.
				$plan = wc_memberships()->get_plans_instance()->createPlan( array( 'name' => $name, 'status' => $status ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_plan', $e->getMessage() ? $e->getMessage() : __( 'The plan could not be created.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! is_array( $in ) || count( $in ) <= 2 ) {
				// The list's quick create: name and status only.
				return rest_ensure_response( array(
					'id'      => (int) $plan->get_id(),
					'message' => sprintf(
						/* translators: %s: plan name. */
						__( '%s created. Open it to set access, length and rules.', 'minn-admin' ),
						$plan->get_name()
					),
				) );
			}
			$res = $apply_plan( $plan, $request, true );
			if ( is_wp_error( $res ) ) {
				// The page's fields were refused: keep nothing half-made.
				wp_delete_post( (int) $plan->get_id(), true );
			}
			return $res;
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) use ( $apply_plan ) {
			$plan = minn_admin_wcm_plan_load( (int) Minn_Admin::path_param( $request ), 'edit_post' );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return $apply_plan( $plan, $request, false );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans/(?P<id>\d+)/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_wcm_can_plans() && current_user_can( 'publish_membership_plans' );
		},
		'callback'            => function ( $request ) {
			$source = minn_admin_wcm_plan_load( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$model = minn_admin_wcm_plan_model( $source );
			try {
				$copy = wc_memberships()->get_plans_instance()->createPlan( array(
					/* translators: %s: the name of the plan being copied. */
					'name'        => sprintf( __( '%s (Copy)', 'minn-admin' ), $model['name'] ),
					'status'      => 'draft',
					'description' => $model['description'],
				) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'minn_wcm_plan', $e->getMessage() ? $e->getMessage() : __( 'The plan could not be copied.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$general = array(
				'access_method' => $model['access']['method'],
				'product_ids'   => array_map( function ( $p ) {
					return $p['id'];
				}, $model['access']['products'] ),
				'length_type'   => $model['length']['type'],
				'length_amount' => $model['length']['amount'],
				'length_period' => $model['length']['period'],
				'length_start'  => $model['length']['start'],
				'length_end'    => $model['length']['end'],
				'sections'      => $model['sections'],
			);
			$ok = minn_admin_wcm_plan_apply_general( $copy, $general );
			if ( ! is_wp_error( $ok ) ) {
				$rules = array();
				foreach ( $model['rules'] as $type => $list ) {
					// A copy gets its own rule ids, and only the rules this
					// user could author (the plugin skips the rest on save).
					$rules[ $type ] = array_values( array_map( function ( $r ) {
						unset( $r['id'] );
						return $r;
					}, array_filter( $list, function ( $r ) {
						return empty( $r['locked'] );
					} ) ) );
				}
				$clean = minn_admin_wcm_plan_rules_clean( $rules );
				if ( ! is_wp_error( $clean ) ) {
					minn_admin_wcm_plan_rules_apply( $copy, $clean );
				}
			}
			return rest_ensure_response( array(
				'id'      => (int) $copy->get_id(),
				'message' => sprintf(
					/* translators: %s: the new plan's name. */
					__( 'Copied as a draft: %s.', 'minn-admin' ),
					$copy->get_name()
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) {
			$plan = minn_admin_wcm_plan_load( (int) Minn_Admin::path_param( $request ), 'delete_post' );
			if ( is_wp_error( $plan ) ) {
				// Their user_has_cap filter withholds delete_post on a plan with
				// active members, so say that rather than "not allowed".
				$check = minn_admin_wcm_plan_load( (int) Minn_Admin::path_param( $request ) );
				if ( ! is_wp_error( $check ) && $check->has_active_memberships() ) {
					return new WP_Error( 'minn_wcm_plan_delete', __( 'This plan still has active members. Cancel or move their memberships first.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				return $plan;
			}
			$name = (string) $plan->get_name();
			try {
				// Their deleter refuses while active members exist, then
				// force-deletes; its delete_post hook removes every
				// membership on the plan and the plan's rules.
				wc_memberships()->get_plans_instance()->deletePlan( (int) $plan->get_id() );
			} catch ( \Throwable $e ) {
				$msg = $e->getMessage();
				if ( false !== stripos( get_class( $e ), 'NotDeletable' ) || '' === $msg ) {
					$msg = __( 'This plan still has active members. Cancel or move their memberships first.', 'minn-admin' );
				}
				return new WP_Error( 'minn_wcm_plan_delete', $msg, array( 'status' => 400 ) );
			}
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: %s: plan name. */
					__( 'Deleted the %s plan.', 'minn-admin' ),
					$name
				),
			) );
		},
	) );

	// Object search for the rule pickers: posts of one type or terms of one
	// taxonomy, validated against the same target lists the rules use.
	register_rest_route( 'minn-admin/v1', '/wcm/lookup', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) {
			$target = (string) $request->get_param( 'target' );
			$q      = trim( (string) $request->get_param( 'q' ) );
			$known  = array();
			foreach ( array( 'content_restriction', 'product_restriction', 'purchasing_discount' ) as $type ) {
				foreach ( minn_admin_wcm_rule_targets( $type ) as $t ) {
					$known[ $t[0] ] = true;
				}
			}
			if ( ! isset( $known[ $target ] ) ) {
				return new WP_Error( 'minn_wcm_lookup', __( 'Unknown content type.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			list( $kind, $name ) = explode( ':', $target, 2 );
			$items = array();
			if ( 'taxonomy' === $kind ) {
				$terms = get_terms( array( 'taxonomy' => $name, 'hide_empty' => false, 'number' => 20, 'search' => $q ) );
				foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $term ) {
					$items[] = array( 'id' => (int) $term->term_id, 'label' => (string) $term->name );
				}
			} else {
				// The plugin's own picker offers published content only.
				$posts = get_posts( array( 'post_type' => $name, 'post_status' => 'publish', 'numberposts' => 20, 's' => $q, 'orderby' => 'title', 'order' => 'ASC' ) );
				foreach ( (array) $posts as $post ) {
					$items[] = array( 'id' => (int) $post->ID, 'label' => (string) get_the_title( $post ) );
				}
			}
			return rest_ensure_response( array( 'items' => $items ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = trim( (string) $request->get_param( 'search' ) );
			$status   = (string) $request->get_param( 'status' );
			$args     = array(
				'post_type'      => 'wc_membership_plan',
				'post_status'    => in_array( $status, array( 'publish', 'draft', 'private', 'pending' ), true ) ? $status : array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'title',
				'order'          => 'ASC',
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			$q     = new WP_Query( $args );
			$items = array();
			foreach ( $q->posts as $post ) {
				$plan = wc_memberships_get_membership_plan( $post );
				if ( $plan ) {
					$items[] = minn_admin_wcm_plan_row( $plan );
				}
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/plans/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wcm_can_plans',
		'callback'            => function ( $request ) {
			$post = get_post( (int) Minn_Admin::path_param( $request ) );
			$plan = $post && 'wc_membership_plan' === $post->post_type ? wc_memberships_get_membership_plan( $post ) : false;
			if ( ! $plan ) {
				return new WP_Error( 'minn_wcm_not_found', __( 'Membership plan not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$row = minn_admin_wcm_plan_row( $plan );

			$about = array(
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $row['status'], 'type' => 'pill' ),
				array( 'label' => __( 'Slug', 'minn-admin' ), 'value' => $row['slug'] ),
				array( 'label' => __( 'Access', 'minn-admin' ), 'value' => $row['access'] ),
				array( 'label' => __( 'Length', 'minn-admin' ), 'value' => $row['length'] ),
			);
			if ( $plan->is_access_length_type( 'fixed' ) ) {
				$about[] = array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_wcm_local( $plan->get_access_start_date( 'mysql' ) ) );
				$about[] = array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_wcm_local( $plan->get_access_end_date( 'mysql' ) ) );
			}
			if ( $post->post_content ) {
				$about[] = array( 'label' => __( 'Description', 'minn-admin' ), 'value' => wp_strip_all_tags( $post->post_content ) );
			}

			$products = array();
			foreach ( (array) $plan->get_product_ids() as $pid ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $pid ) : null;
				$products[] = array( 'label' => '#' . (int) $pid, 'value' => $product ? $product->get_name() : __( '(deleted product)', 'minn-admin' ) );
			}
			if ( $products ) {
				$about[] = array( 'label' => __( 'Grants access on purchase of', 'minn-admin' ), 'value' => $products, 'type' => 'kv-table' );
			}

			$counts = array();
			foreach ( minn_admin_wcm_statuses() as $slug => $label ) {
				$n = (int) $plan->get_memberships_count( $slug );
				if ( $n > 0 ) {
					$counts[] = array( 'label' => $label, 'value' => number_format_i18n( $n ) );
				}
			}
			$members = array(
				array( 'label' => __( 'Active now', 'minn-admin' ), 'value' => number_format_i18n( $row['members'] ) ),
				array( 'label' => __( 'All time', 'minn-admin' ), 'value' => number_format_i18n( $row['total'] ) ),
			);
			if ( $counts ) {
				$members[] = array( 'label' => __( 'By status', 'minn-admin' ), 'value' => $counts, 'type' => 'kv-table' );
			}

			$rules = array();
			try {
				$rules[] = array( 'label' => __( 'Content restriction', 'minn-admin' ), 'value' => number_format_i18n( count( (array) $plan->get_content_restriction_rules() ) ) );
				$rules[] = array( 'label' => __( 'Product restriction', 'minn-admin' ), 'value' => number_format_i18n( count( (array) $plan->get_product_restriction_rules() ) ) );
				$rules[] = array( 'label' => __( 'Purchasing discounts', 'minn-admin' ), 'value' => number_format_i18n( count( (array) $plan->get_purchasing_discount_rules() ) ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return rest_ensure_response( array(
				'title'    => $row['name'],
				'sections' => array(
					array( 'title' => __( 'Plan', 'minn-admin' ), 'rows' => $about ),
					array( 'title' => __( 'Members', 'minn-admin' ), 'rows' => $members ),
					array( 'title' => __( 'Rules', 'minn-admin' ), 'rows' => $rules ),
				),
				'adminUrl' => admin_url( 'post.php?post=' . (int) $plan->get_id() . '&action=edit' ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/status', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function () {
			global $wpdb;
			$active   = array_map( 'minn_admin_wcm_prefix', minn_admin_wcm_active_statuses() );
			$in       = implode( ',', array_fill( 0, count( $active ), '%s' ) );
			$now      = gmdate( 'Y-m-d H:i:s' );
			$soon     = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
			$counts   = (array) wp_count_posts( 'wc_user_membership' );
			$paused   = isset( $counts['wcm-paused'] ) ? (int) $counts['wcm-paused'] : 0;
			$pending  = isset( $counts['wcm-pending'] ) ? (int) $counts['wcm-pending'] : 0;
			$active_n = 0;
			foreach ( $active as $slug ) {
				$active_n += isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
			$expiring = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_end_date'
				 WHERE p.post_type = 'wc_user_membership' AND p.post_status IN ({$in}) AND m.meta_value > %s AND m.meta_value <= %s",
				array_merge( $active, array( $now, $soon ) )
			) );
			// phpcs:enable

			$month_start = wp_date( 'Y-m-01 00:00:00' );
			$new_month   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership' AND post_status LIKE 'wcm-%%' AND post_date >= %s",
				$month_start
			) );

			$rows = array(
				array(
					'label' => __( 'Active members', 'minn-admin' ),
					'value' => number_format_i18n( $active_n ),
					'hint'  => sprintf(
						/* translators: 1: number of paused memberships, 2: number of memberships pending cancellation. */
						__( '%1$s paused · %2$s pending cancellation', 'minn-admin' ),
						number_format_i18n( $paused ),
						number_format_i18n( $pending )
					),
				),
				array(
					'label' => __( 'New this month', 'minn-admin' ),
					'value' => number_format_i18n( $new_month ),
				),
			);
			if ( $expiring ) {
				$rows[] = array(
					'label' => __( 'Ending soon', 'minn-admin' ),
					'value' => number_format_i18n( $expiring ),
					'hint'  => __( 'within 30 days', 'minn-admin' ),
				);
			}

			$out = array(
				'rows'    => $rows,
				'actions' => array(
					array(
						'label' => __( 'Open Memberships ↗', 'minn-admin' ),
						'href'  => admin_url( 'edit.php?post_type=wc_user_membership' ),
					),
					array(
						'label' => __( 'Import / export CSV ↗', 'minn-admin' ),
						'href'  => admin_url( 'admin.php?page=wc_memberships_import_export' ),
					),
				),
			);

			if ( function_exists( 'minn_admin_chart_days' ) ) {
				// New memberships bucket on post_date (site-local, the column
				// the date window narrows on); cancellations on their UTC meta.
				$days  = minn_admin_chart_days( 14 );
				$since = minn_admin_chart_local_since( 14 );
				$made  = $wpdb->get_results( $wpdb->prepare(
					"SELECT DATE(post_date) AS d, COUNT(*) AS n FROM {$wpdb->posts}
					 WHERE post_type = 'wc_user_membership' AND post_status LIKE 'wcm-%%' AND post_date >= %s GROUP BY d",
					$since
				) );
				foreach ( (array) $made as $r ) {
					minn_admin_chart_bump( $days, (string) $r->d, false, (int) $r->n );
				}
				$ended = $wpdb->get_col( $wpdb->prepare(
					"SELECT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE p.post_type = 'wc_user_membership' AND m.meta_key = '_cancelled_date' AND m.meta_value >= %s",
					minn_admin_chart_utc_since( 14 )
				) );
				foreach ( (array) $ended as $stamp ) {
					$day = minn_admin_chart_utc_day( $stamp );
					if ( $day ) {
						minn_admin_chart_bump( $days, $day, true );
					}
				}
				$out['chart'] = minn_admin_chart_build( $days, __( 'New', 'minn-admin' ), __( 'Cancelled', 'minn-admin' ) );
			}

			return rest_ensure_response( $out );
		},
	) );
} );
