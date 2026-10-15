<?php
/**
 * The mail family's kit: what every email log adapter would otherwise build
 * again. A log's surface (its columns, Sent and Failed tabs, Resend and
 * Delete, all agreeing with the kernel resource by construction), its
 * detail sections, its status card, and the delete and resend declarations.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries stay the kernel's; each adapter passes its vendor's
 * values (where the log lives, who may read it, how a row reads).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a surface while its plugin is active: how a kit's adapter registers
 * one (tests/catalog.test.js reads the id here as it reads $surfaces['id']).
 *
 * @param string   $id     Surface id.
 * @param callable $active Whether the plugin is active and the reader may see its log.
 * @param callable $build  The surface (minn_admin_mail_surface()), built when surfaces are.
 */
function minn_admin_add_surface( $id, callable $active, callable $build ) {
	add_filter( 'minn_admin_surfaces', function ( $surfaces ) use ( $id, $active, $build ) {
		if ( true === call_user_func( $active ) ) {
			$surfaces[ $id ] = call_user_func( $build );
		}
		return $surfaces;
	} );
}

/**
 * An email log's surface over its kernel resource.
 *
 * @param string $res Resource id ("post-smtp/emails").
 * @param array  $o   sub, plugin, cap, status (route under minn-admin/v1),
 *                    date (column key), utc, tabs (value => label), resend
 *                    (true, or ui() keys for it), columns (between Status and
 *                    Date), actions (after Resend), collection and surface
 *                    (keys merged over the built ones).
 * @return array
 */
function minn_admin_mail_surface( $res, array $o ) {
	$o += array(
		'date'       => 'created_at',
		'utc'        => false,
		'tabs'       => array( 'sent' => 'Sent', 'failed' => 'Failed' ),
		'resend'     => false,
		'columns'    => array(),
		'actions'    => array(),
		'collection' => array(),
		'surface'    => array(),
	);
	$actions = array();
	if ( $o['resend'] ) {
		$actions[] = Minn_Admin_Kernel::ui( $res, 'resend', array_merge(
			array( 'label' => __( 'Resend', 'minn-admin' ), 'confirm' => __( 'Resend this email to the original recipients?', 'minn-admin' ) ),
			is_array( $o['resend'] ) ? $o['resend'] : array()
		) );
	}
	$actions   = array_merge( $actions, $o['actions'] );
	$actions[] = Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete this log entry permanently? There is no trash.', 'minn-admin' ), 'danger' => true ) );
	$tabs      = array();
	foreach ( $o['tabs'] as $value => $label ) {
		$tabs[] = array( $value, $label );
	}
	return array_merge( array(
		'label'      => __( 'Email', 'minn-admin' ),
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => 'send',
		'cap'        => $o['cap'],
		'family'     => 'mail',
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
		// A status-chart bar narrows the log to that day (the kernel's
		// dateQuery: the chart's points carry from/to).
		'collection' => Minn_Admin_Kernel::collection( $res, array_merge( array(
			'tabs'    => array( 'param' => 'status', 'static' => $tabs, 'allLabel' => 'All' ),
			'columns' => array_merge(
				array(
					array( 'key' => 'subject', 'label' => __( 'Subject', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'to', 'label' => __( 'To', 'minn-admin' ), 'format' => 'text' ),
					array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				),
				$o['columns'],
				array( array_merge( array( 'key' => $o['date'], 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago' ), $o['utc'] ? array( 'utc' => true ) : array() ) )
			),
			// Server-built sections (status pill, sandboxed HTML body).
			'detail'  => array( 'sectionsRoute' => 'minn-admin/v1/' . $res . '/{id}/view' ),
			'actions' => $actions,
			'bulk'    => array(
				Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete the selected log entries permanently?', 'minn-admin' ), 'danger' => true ) ),
			),
		), $o['collection'] ) ),
	), $o['surface'] );
}

/**
 * A logged email's detail: Delivery, then the subject and body, then any
 * further sections (a failure, the headers).
 *
 * @param array       $delivery Delivery rows.
 * @param string      $subject  Subject.
 * @param string|null $body     Body, or null where the reader may not see it.
 * @param array       $more     Sections after the message.
 * @param array       $html     Keys for an HTML body's row ('remote').
 * @return array
 */
function minn_admin_mail_sections( array $delivery, $subject, $body = null, array $more = array(), array $html = array() ) {
	$message = array( array( 'label' => __( 'Subject', 'minn-admin' ), 'value' => (string) $subject ) );
	if ( null !== $body ) {
		$body      = (string) $body;
		$message[] = preg_match( '/<\/?[a-z][^>]*>/i', $body )
			? array_merge( array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'html-preview' ), $html )
			: array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'code' );
	}
	return array(
		'sections' => array_merge(
			array(
				array( 'title' => __( 'Delivery', 'minn-admin' ), 'rows' => $delivery ),
				array( 'title' => __( 'Message', 'minn-admin' ), 'rows' => $message ),
			),
			$more
		),
	);
}

/** The Failure section of a detail, the error as a code row and any rows after it. */
function minn_admin_mail_failure( $error, array $rows = array() ) {
	return array(
		'title' => __( 'Failure', 'minn-admin' ),
		'rows'  => array_merge( array( array( 'label' => __( 'Error', 'minn-admin' ), 'value' => (string) $error, 'type' => 'code' ) ), $rows ),
	);
}

/**
 * An email log's status card over its resource: how many it holds, how
 * many failed, and the last fourteen days, counted as the list counts.
 *
 * @param string $res Resource id.
 * @param array  $o   failed (conditions), chart (Minn_Admin_Kernel::days()),
 *                    ok (the hint when none failed), primary, secondary,
 *                    rows (after the first), actions, missing (the hint
 *                    while the table is not there yet) and table.
 * @return array
 */
function minn_admin_mail_card( $res, array $o ) {
	$o += array(
		'ok'        => __( 'All logged sends', 'minn-admin' ),
		'primary'   => 'Sent',
		'secondary' => 'Failed',
		'rows'      => array(),
		'actions'   => array(),
	);
	if ( isset( $o['table'] ) && ! Minn_Admin_Kernel_SQL::exists( $o['table'] ) ) {
		return array(
			'rows'    => array( array( 'label' => __( 'Email log', 'minn-admin' ), 'value' => $o['missing_value'] ?? 'Not ready', 'hint' => $o['missing'] ?? '' ) ),
			'actions' => $o['missing_actions'] ?? $o['actions'],
		);
	}
	$failed = Minn_Admin_Kernel::count( $res, $o['failed'] );
	return array(
		'rows'    => array_merge(
			array(
				array(
					'label' => __( 'Logged emails', 'minn-admin' ),
					'value' => number_format_i18n( Minn_Admin_Kernel::count( $res ) ),
					/* translators: %s: number of failed sends. */
					'hint'  => $failed ? sprintf( _n( '%s failed', '%s failed', $failed, 'minn-admin' ), number_format_i18n( $failed ) ) : $o['ok'],
				),
			),
			$o['rows']
		),
		'chart'   => array(
			'title'     => __( 'Last 14 days', 'minn-admin' ),
			'primary'   => $o['primary'],
			'secondary' => $o['secondary'],
			'points'    => array_values( Minn_Admin_Kernel::days( $res, $o['chart'] ) ),
		),
		'actions' => $o['actions'],
	);
}

/**
 * The delete every log declares: permanent, said done once the row is gone.
 *
 * @param callable $run Deletes one entry: the vendor's own delete, or the kernel's.
 * @param array    $o   Keys merged over it (gate, fail).
 * @return array
 */
function minn_admin_mail_delete( callable $run, array $o = array() ) {
	return array_merge( array(
		'method' => 'DELETE',
		'gate'   => 'view',
		'run'    => $run,
		'expect' => 'gone',
		'reply'  => function () {
			return array( 'deleted' => true );
		},
		'done'   => __( 'Log entry deleted.', 'minn-admin' ),
	), $o );
}

/**
 * The resend a log declares: proven only by the vendor's own answer (the
 * kernel's 'ran'), and told whom it went to when the run says.
 *
 * @param callable $run Resends one entry: whom it sent to, true, or a WP_Error.
 * @param array    $o   Keys merged over it (gate, reply, done).
 * @return array
 */
function minn_admin_mail_resend( callable $run, array $o = array() ) {
	return array_merge( array(
		'gate'   => 'view',
		'run'    => $run,
		'expect' => 'ran',
		'reply'  => function ( $id, $fresh, $to ) {
			return is_string( $to ) ? array( 'resent' => true, 'to' => $to, 'message' => __( 'Resent to ', 'minn-admin' ) . $to ) : array( 'resent' => true );
		},
	), $o );
}

/** A plain wp_mail() resend of a stored subject and body, HTML when it holds tags. */
function minn_admin_mail_wp_mail( array $to, $subject, $body ) {
	$body = (string) $body;
	if ( ! wp_mail( $to, (string) $subject, $body, preg_match( '/<\/?[a-z][\s\S]*>/i', $body ) ? array( 'Content-Type: text/html; charset=UTF-8' ) : array() ) ) {
		return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
	}
	return implode( ', ', $to );
}
