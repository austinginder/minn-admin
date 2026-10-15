<?php
/**
 * The activity-log family's kit: what every activity log adapter would
 * otherwise build again. A log's surface (the Activity Log label and family,
 * the vendor's tabs, the Event title column first and When last with the
 * vendor's columns between) and its status card over the kernel's log counts
 * (the event rows, the vendor's rows after them, the fourteen-day chart, the
 * vendor's screen).
 *
 * Adapter tier: presentation only. Routes, gates and queries stay the
 * kernel's; each adapter passes its vendor's values (where the log lives,
 * who may read it, how a row reads, the vendor's own words).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * An activity log's surface.
 *
 * @param string|null $res Resource id ("wsal/events"), or null for a list the
 *                         vendor's own route serves (head then holds its
 *                         route, paging and query keys).
 * @param array       $o   sub, plugin, status (the card's route under
 *                         minn-admin/v1), tabs (array( param, value => label
 *                         or a tab list's route under minn-admin/v1, the All
 *                         tab's label )), columns (between Event and When),
 *                         event and when (keys merged over those two),
 *                         detail, actions, head (collection keys before the
 *                         tabs), icon and cap. cap is manage_options unless
 *                         the adapter says otherwise: one that passes read
 *                         relies on the active check it registers the
 *                         surface with, in its vendor's own capability
 *                         model, as the real gate.
 * @return array
 */
function minn_admin_activity_surface( $res, array $o ) {
	$o += array( 'icon' => 'shield', 'cap' => 'manage_options', 'event' => array(), 'columns' => array(), 'when' => array(), 'head' => array() );
	list( $param, $values, $all ) = $o['tabs'];
	if ( is_string( $values ) ) {
		$tabs = array( 'route' => 'minn-admin/v1/' . $values, 'valueKey' => 'id', 'labelKey' => 'title', 'param' => $param, 'allLabel' => $all );
	} else {
		$static = array();
		foreach ( $values as $value => $label ) {
			$static[] = array( (string) $value, $label );
		}
		$tabs = array( 'param' => $param, 'static' => $static, 'allLabel' => $all );
	}
	$list = array_merge( $o['head'], array(
		'tabs'    => $tabs,
		'columns' => array_merge(
			array( array_merge( array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ), $o['event'] ) ),
			$o['columns'],
			array( array_merge( array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ), $o['when'] ) )
		),
		'detail'  => $o['detail'],
	), array_intersect_key( $o, array( 'actions' => true ) ) );
	return array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
		'family'     => 'activity-log',
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => $o['icon'],
		'cap'        => $o['cap'],
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
		// Route, paging, search and the chart's day range come from the
		// resource, so they cannot drift from it.
		'collection' => null === $res ? $list : Minn_Admin_Kernel::collection( $res, $list ),
	);
}

/**
 * The status card while there is nothing to count: the Events row with why,
 * and the vendor's screen.
 *
 * @param array  $open Actions (the vendor's screen).
 * @param string $hint Why ("Log table not found").
 * @return array
 */
function minn_admin_activity_none( array $open, $hint ) {
	return array(
		'rows'    => array( array( 'label' => __( 'Events', 'minn-admin' ), 'value' => '—', 'hint' => $hint ) ),
		'actions' => $open,
	);
}

/**
 * An activity log's status card over its counts: the last day's events (the
 * week's as the hint), all time and the last event, the vendor's own row
 * after them, fourteen days of events and the vendor's screen.
 *
 * @param array  $n         Minn_Admin_Kernel::log_counts().
 * @param array  $open      Actions (the vendor's screen).
 * @param string $label     The vendor's row: its label.
 * @param string $value     Its value.
 * @param string $secondary The chart's second series ('' for none).
 * @return array
 */
function minn_admin_activity_card( array $n, array $open, $label, $value, $secondary = '' ) {
	return array(
		'rows'    => array_merge( array_values( Minn_Admin_Kernel::event_rows( $n ) ), array( array( 'label' => $label, 'value' => $value ) ) ),
		'chart'   => minn_admin_chart_build( $n['days'], __( 'Events', 'minn-admin' ), $secondary ),
		'actions' => $open,
	);
}
