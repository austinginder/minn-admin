<?php
/**
 * The redirects family's kit: what every redirect adapter would otherwise
 * build again. A redirect list's surface, its source, target and status
 * fields, its delete and bulk delete, and the rules, hits and top-redirect
 * rows of its status card.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries stay the kernel's; each adapter passes its vendor's
 * values.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * A redirect list's surface.
 *
 * @param array $o sub, plugin, cap, status (route under minn-admin/v1),
 *                 collection, and any further surface keys (setup,
 *                 settings, views).
 * @return array
 */
function minn_admin_redirects_surface( array $o ) {
	$surface = array(
		'label'  => __( 'Redirects', 'minn-admin' ),
		'family' => 'redirects',
		'sub'    => $o['sub'],
		'plugin' => $o['plugin'],
		'icon'   => 'shuffle',
		'cap'    => $o['cap'],
	);
	if ( ! empty( $o['status'] ) ) {
		$surface['status'] = array( 'route' => 'minn-admin/v1/' . $o['status'] );
	}
	unset( $o['sub'], $o['plugin'], $o['cap'], $o['status'] );
	return array_merge( $surface, $o );
}

/**
 * The source field of a redirect form.
 *
 * @param string $key    Field key.
 * @param bool   $create With the create form's placeholder.
 * @return array
 */
function minn_admin_redirects_source( $key, $create = true ) {
	$f = array( 'key' => $key, 'label' => __( 'Source URL', 'minn-admin' ), 'mono' => true );
	return $create ? $f + array( 'placeholder' => __( '/old-page', 'minn-admin' ) ) : $f;
}

/**
 * The target field of a redirect form.
 *
 * @param string      $key         Field key.
 * @param bool        $create      With the create form's placeholder.
 * @param string|null $placeholder Another placeholder (a post ID too, say).
 * @return array
 */
function minn_admin_redirects_target( $key, $create = true, $placeholder = null ) {
	$f = array( 'key' => $key, 'label' => __( 'Target URL', 'minn-admin' ), 'mono' => true );
	return $create ? $f + array( 'placeholder' => null === $placeholder ? __( '/new-page or https://…', 'minn-admin' ) : $placeholder ) : $f;
}

/**
 * The HTTP status field of a redirect form, 301 to start with on create.
 *
 * @param string $key    Field key.
 * @param bool   $create With the create form's starting value.
 * @return array
 */
function minn_admin_redirects_code( $key, $create = true ) {
	$f = array( 'key' => $key, 'label' => __( 'HTTP status', 'minn-admin' ), 'type' => 'number' );
	return $create ? $f + array( 'value' => 301 ) : $f;
}

/**
 * A redirect list's delete and bulk delete: permanent, with the family's
 * confirms. A kernel resource names its declared action; anything else its
 * route.
 *
 * @param string $res   Resource id ("eps301/redirects"), or a route.
 * @param string $label The row action's label.
 * @return array array( action, bulk action )
 */
function minn_admin_redirects_delete( $res, $label = '' ) {
	$label = '' === $label ? __( 'Delete redirect', 'minn-admin' ) : $label;
	$one   = array( 'confirm' => __( 'Delete this redirect permanently?', 'minn-admin' ), 'danger' => true );
	$many  = array( 'confirm' => __( 'Delete the selected redirects permanently?', 'minn-admin' ), 'danger' => true );
	if ( 0 === strpos( $res, 'minn-admin/v1/' ) ) {
		$at = array( 'method' => 'DELETE', 'route' => $res );
		return array( array( 'label' => $label ) + $at + $one, array( 'label' => __( 'Delete', 'minn-admin' ) ) + $at + $many );
	}
	return array(
		Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => $label ) + $one ),
		Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ) ) + $many ),
	);
}

/**
 * A status card's hits and top-redirect rows.
 *
 * @param int         $hits  Hits, all time.
 * @param string|null $top   The most-hit source, or null for none.
 * @param int         $count Its hits.
 * @return array[]
 */
function minn_admin_redirects_hits( $hits, $top = null, $count = 0 ) {
	$rows = array( array( 'label' => __( 'Hits, all time', 'minn-admin' ), 'value' => number_format_i18n( (int) $hits ) ) );
	if ( null !== $top ) {
		$rows[] = array(
			'label' => __( 'Top redirect', 'minn-admin' ),
			'value' => (string) $top,
			'hint'  => number_format_i18n( (int) $count ) . ' hits',
		);
	}
	return $rows;
}
