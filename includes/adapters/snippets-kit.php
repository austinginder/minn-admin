<?php
/**
 * The snippets family's kit: what every snippet adapter would otherwise build
 * again. A snippet list's surface, its Status filter and columns, its switch
 * on, switch off and delete (row and bulk), and the count, running and last
 * change rows of its status card.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates, queries and every code guard stay the kernel's and the adapter's;
 * each adapter passes its vendor's values.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * A snippet list's surface.
 *
 * @param array $o sub, plugin, cap, status (route under minn-admin/v1),
 *                 collection, and any further surface keys (contentTypes).
 * @return array
 */
function minn_admin_snippets_surface( array $o ) {
	$surface = array(
		'label'  => __( 'Snippets', 'minn-admin' ),
		'family' => 'snippets',
		'sub'    => $o['sub'],
		'plugin' => $o['plugin'],
		'icon'   => 'code',
		'cap'    => $o['cap'],
		'status' => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
	);
	unset( $o['sub'], $o['plugin'], $o['cap'], $o['status'] );
	return array_merge( $surface, $o );
}

/**
 * The Status filter, sent as the vendor's own values.
 *
 * @param string $on  The value for snippets that run ('active', '1').
 * @param string $off The value for those that do not.
 * @return array
 */
function minn_admin_snippets_filter( $on = 'active', $off = 'inactive' ) {
	return array(
		'label'   => __( 'Status', 'minn-admin' ),
		'options' => array( array( 'all', 'All' ), array( $on, 'Active' ), array( $off, 'Inactive' ) ),
		'query'   => 'active={v}',
	);
}

/**
 * A snippet list's columns: its name, the vendor's scope, whether it runs,
 * its priority when the vendor has one, and when it last changed.
 *
 * @param string $scope The scope column's label ("Type · location").
 * @param array  $o     width (the scope column's), priority (false for
 *                      none), utc (a modified stamp in UTC without a zone).
 * @return array[]
 */
function minn_admin_snippets_columns( $scope, array $o = array() ) {
	$o   += array( 'width' => 'minmax(0,1fr)', 'priority' => true, 'utc' => false );
	$cols = array(
		array( 'key' => 'name', 'label' => __( 'Snippet', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
		array( 'key' => 'scope', 'label' => $scope, 'format' => 'mono', 'width' => $o['width'] ),
		array( 'key' => 'active', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
	);
	if ( $o['priority'] ) {
		$cols[] = array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'num', 'width' => '80px' );
	}
	$cols[] = array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago' ) + ( $o['utc'] ? array( 'utc' => true ) : array() );
	return $cols;
}

/**
 * A snippet list's row and bulk actions: switch on, switch off, the vendor's
 * own screen, delete. A kernel resource names its declared 'active' and
 * 'delete' actions; an endpoint list passes its route.
 *
 * @param string $res Resource id ("wpcode/snippets"), or a route
 *                    ("minn-admin/v1/fluent-snippets").
 * @param array  $o   edit_in (the vendor screen's action), delete (the row
 *                    action's label), confirm and many (the row and bulk
 *                    delete's confirms).
 * @return array array( actions, bulk )
 */
function minn_admin_snippets_actions( $res, array $o ) {
	$o  += array(
		'delete'  => __( 'Delete', 'minn-admin' ),
		'confirm' => __( 'Delete this snippet permanently?', 'minn-admin' ),
		'many'    => __( 'Delete the selected snippets permanently?', 'minn-admin' ),
	);
	$at  = function ( $slug, array $ui ) use ( $res ) {
		if ( 0 !== strpos( $res, 'minn-admin/v1/' ) ) {
			return Minn_Admin_Kernel::ui( $res, $slug, $ui + array( 'key' => 'active' ) );
		}
		$out = array(
			'label'  => $ui['label'],
			'method' => 'delete' === $slug ? 'DELETE' : 'POST',
			'route'  => $res . '/{id}' . ( 'delete' === $slug ? '' : '/' . $slug ),
		);
		foreach ( array( 'confirm', 'danger', 'body' ) as $k ) {
			if ( isset( $ui[ $k ] ) ) {
				$out[ $k ] = $ui[ $k ];
			}
		}
		if ( isset( $ui['when'] ) ) {
			$out['when'] = array( 'key' => 'active', 'equals' => $ui['when'] );
		}
		return $out;
	};
	$on  = $at( 'active', array( 'label' => __( 'Activate', 'minn-admin' ), 'body' => array( 'active' => true ), 'when' => false ) );
	$off = $at( 'active', array( 'label' => __( 'Deactivate', 'minn-admin' ), 'body' => array( 'active' => false ), 'when' => true ) );
	return array(
		array( $on, $off, $o['edit_in'], $at( 'delete', array( 'label' => $o['delete'], 'confirm' => $o['confirm'], 'danger' => true ) ) ),
		array( $on, $off, $at( 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => $o['many'], 'danger' => true ) ) ),
	);
}

/**
 * A status card's first row: how many snippets run, and how many do not.
 *
 * @param int      $active   Snippets that run.
 * @param int      $inactive Those that do not.
 * @param string[] $more     Further hints ("2 trashed").
 * @param string   $label    The row's label, 'Active snippets' by default.
 * @return array
 */
function minn_admin_snippets_count_row( $active, $inactive, array $more = array(), $label = '' ) {
	$hint = array();
	if ( $inactive ) {
		/* translators: %d: number of inactive snippets. */
		$hint[] = sprintf( __( '%d inactive', 'minn-admin' ), $inactive );
	}
	$hint = array_merge( $hint, $more );
	return array(
		'label' => '' === $label ? __( 'Active snippets', 'minn-admin' ) : $label,
		'value' => (string) $active,
		'hint'  => $hint ? implode( ' · ', $hint ) : __( 'nothing inactive', 'minn-admin' ),
	);
}

/**
 * A status card's row of what runs most ("3 php · 1 css"), or null when
 * nothing does.
 *
 * @param string $label  The row's label ('Running types').
 * @param array  $counts Minn_Admin_Kernel_SQL::counts() rows: value, count.
 * @return array|null
 */
function minn_admin_snippets_running_row( $label, array $counts ) {
	if ( ! $counts ) {
		return null;
	}
	return array(
		'label' => $label,
		'value' => implode( ' · ', array_map( function ( $c ) {
			return $c['count'] . ' ' . $c['value'];
		}, $counts ) ),
	);
}

/**
 * A status card's last-change row: the snippet's name and when.
 *
 * @param string $name Snippet name.
 * @param string $when The day, as the vendor's clock reads it.
 * @return array
 */
function minn_admin_snippets_last_row( $name, $when ) {
	return array(
		'label' => __( 'Last change', 'minn-admin' ),
		'value' => (string) $name,
		'hint'  => (string) $when,
	);
}
