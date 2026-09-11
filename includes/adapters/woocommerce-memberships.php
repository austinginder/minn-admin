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
	if ( ! $user && ctype_digit( $raw ) ) {
		$user = get_user_by( 'id', (int) $raw );
	}
	if ( ! $user ) {
		$user = get_user_by( 'login', $raw );
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
	$post      = get_post( $plan->get_id() );
	$rules     = 0;
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
		'products' => count( (array) $plan->get_product_ids() ),
		'rules'    => $rules,
		'status'   => $post ? (string) $post->post_status : '',
	);
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
				'kinds'             => array( 'status', 'date', 'customer', 'product' ),
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
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Plan', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'access', 'label' => __( 'Access', 'minn-admin' ), 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'length', 'label' => __( 'Length', 'minn-admin' ), 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'members', 'label' => __( 'Active members', 'minn-admin' ), 'format' => 'num', 'width' => '130px' ),
				array( 'key' => 'total', 'label' => __( 'All time', 'minn-admin' ), 'format' => 'num', 'width' => '90px' ),
				array( 'key' => 'products', 'label' => __( 'Products', 'minn-admin' ), 'format' => 'num', 'width' => '90px' ),
				array( 'key' => 'rules', 'label' => __( 'Rules', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wcm/plans/{id}/view',
			),
			'actions'   => array(
				array(
					'label' => __( 'Edit plan in WooCommerce', 'minn-admin' ),
					'href'  => admin_url( 'post.php?post={id}&action=edit' ),
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
			$m = minn_admin_wcm_load( (int) $request['id'] );
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
					$stamp = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $note->comment_date_gmt . ' UTC' ) );
					$who   = $note->comment_author ? $note->comment_author : 'WooCommerce';
					$notes[] = array(
						'label' => $stamp . ' · ' . $who,
						'value' => wp_strip_all_tags( (string) $note->comment_content ),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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
			$m = minn_admin_wcm_load( (int) $request['id'], 'edit_post' );
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
			$m = minn_admin_wcm_load( (int) $request['id'], 'edit_post' );
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
			$m = minn_admin_wcm_load( (int) $request['id'], 'edit_post' );
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
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcm/members/(?P<id>\d+)/transfer', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$m = minn_admin_wcm_load( (int) $request['id'], 'edit_post' );
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
			$m = minn_admin_wcm_load( (int) $request['id'], 'delete_post' );
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
			if ( get_post( (int) $request['id'] ) ) {
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
			$post = get_post( (int) $request['id'] );
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
