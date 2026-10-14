<?php
/**
 * The kernel's entry points for adapters (internal until a second family
 * ports; see docs/adapter-kernel.md).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declare a resource: its list, item and actions get kernel-owned routes.
 *
 * @param string         $id  Route base under minn-admin/v1, e.g. "everest/entries".
 * @param array|callable $def Declaration (Minn_Admin_Kernel's header lists the keys),
 *                            or a function returning it so its strings are
 *                            translated after Minn's translations load.
 */
function minn_admin_resource( $id, $def ) {
	Minn_Admin_Kernel::resource( $id, $def );
}

/**
 * Declare a handler-tier endpoint: the adapter keeps its logic, the kernel
 * owns the route, the gate, typed ids and the error shape.
 *
 * @param string $path Route under minn-admin/v1.
 * @param array  $def  methods, gate, run, active, args.
 */
function minn_admin_endpoint( $path, array $def ) {
	Minn_Admin_Kernel::endpoint( $path, $def );
}
