<?php
/**
 * v0.30 order authorization regression.
 *
 * Run: wp eval-file tests/security-v030-orders.test.php --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
	echo "SKIP  WooCommerce Subscriptions is inactive\n";
	return;
}

$order_id = 0;
foreach ( wcs_get_subscriptions( array( 'subscriptions_per_page' => 20 ) ) as $subscription ) {
	$related = array_map( 'absint', array_keys( (array) $subscription->get_related_orders( 'all' ) ) );
	if ( $related ) {
		$order_id = reset( $related );
		break;
	}
}

$check( 'Fixture has an order related to a subscription', 0 < $order_id, (string) $order_id );
if ( ! $order_id ) {
	printf( "\nsecurity-v030-orders: 0/1 passed\n" );
	exit( 1 );
}

$previous_user   = get_current_user_id();
$restricted_user = get_user_by( 'login', 'minn-editor' );
$check( 'Fixture has the restricted authenticated user', $restricted_user instanceof WP_User );
if ( ! $restricted_user ) {
	printf( "\nsecurity-v030-orders: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( 1 );
}
$cap_filter = function ( $allcaps ) {
	$allcaps['edit_shop_orders']           = true;
	$allcaps['edit_others_shop_orders']    = false;
	$allcaps['edit_published_shop_orders'] = false;
	$allcaps['edit_private_shop_orders']   = false;
	$allcaps['read_private_shop_orders']   = false;
	return $allcaps;
};

wp_set_current_user( $restricted_user->ID );
add_filter( 'user_has_cap', $cap_filter, 10, 1 );
$check( 'Restricted caller passes the route-level order capability', current_user_can( 'edit_shop_orders' ) );
$check( 'Restricted caller cannot edit the target order', ! current_user_can( 'edit_post', $order_id ) );

$restricted_request = new WP_REST_Request( 'GET', '/minn-admin/v1/wc/orders/subscription-relations' );
$restricted_request->set_param( 'ids', (string) $order_id );
$restricted_response = rest_do_request( $restricted_request );
$restricted_data     = (array) $restricted_response->get_data();
$check(
	'Restricted caller receives no relation for an unauthorized order',
	200 === $restricted_response->get_status() && ! isset( $restricted_data[ (string) $order_id ] ),
	wp_json_encode( $restricted_response->get_data() )
);

$restricted_refunds = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/wc/orders/' . $order_id . '/refund-state' ) );
$restricted_emails  = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/orders/' . $order_id . '/emails' ) );
$check( 'Restricted caller cannot read the target order refund state', 403 === $restricted_refunds->get_status(), (string) $restricted_refunds->get_status() );
$check( 'Restricted caller cannot read the target order email controls', 403 === $restricted_emails->get_status(), (string) $restricted_emails->get_status() );

remove_filter( 'user_has_cap', $cap_filter, 10 );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
$admin  = $admins ? (int) reset( $admins ) : 0;
wp_set_current_user( $admin );

$admin_request = new WP_REST_Request( 'GET', '/minn-admin/v1/wc/orders/subscription-relations' );
$admin_request->set_param( 'ids', (string) $order_id );
$admin_response = rest_do_request( $admin_request );
$admin_data     = (array) $admin_response->get_data();
$check(
	'Administrator still receives the authorized relation',
	200 === $admin_response->get_status() && isset( $admin_data[ (string) $order_id ] ),
	wp_json_encode( $admin_response->get_data() )
);

$admin_refunds = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/wc/orders/' . $order_id . '/refund-state' ) );
$admin_emails  = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/orders/' . $order_id . '/emails' ) );
$check( 'Administrator still reads the target order refund state', 200 === $admin_refunds->get_status(), (string) $admin_refunds->get_status() );
$check( 'Administrator still reads the target order email controls', 200 === $admin_emails->get_status(), (string) $admin_emails->get_status() );

$gateways_request = new WP_REST_Request( 'GET', '/minn-admin/v1/wc/gateways' );
$gateways_request->set_param( 'id', (string) $order_id );
$gateways_response = rest_do_request( $gateways_request );
$check( 'A non-route id query parameter does not change gateway authorization', 200 === $gateways_response->get_status(), (string) $gateways_response->get_status() );

wp_set_current_user( $previous_user );
$failed = count( array_filter( $results, function ( $result ) { return ! $result; } ) );
printf( "\nsecurity-v030-orders: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
