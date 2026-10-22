<?php
/**
 * Bundled adapter: Breakwater — every checked submission, and the two calls
 * an owner makes on one.
 *
 * Breakwater keeps every comment, sign-up and form submission it checks in
 * {prefix}breakwater_log for 90 days, spam or clean. The log is a kernel
 * resource over that table; what a row means (held, let through, released),
 * its reasons and fields, the counts and both writes come from
 * Breakwater\Api, the surface Breakwater keeps for screens like this one, so
 * none of it is guessed from columns here.
 *
 * "Not spam" is Breakwater's release: the submission goes back to the form
 * plugin that sent it (saved, its emails and integrations run) or is emailed
 * to the form's recipients, and the sentence Breakwater keeps on the row is
 * the reply. "Was spam" marks a clean one. Both answer to Breakwater's own
 * rule (manage_options) and check it again inside Api.
 *
 * Clock: created_at is UTC (gmdate).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_breakwater_active() {
	return class_exists( '\Breakwater\Api' ) && Minn_Admin_Kernel_SQL::exists( 'breakwater_log' );
}

function minn_admin_breakwater_can() {
	return class_exists( '\Breakwater\Api' ) && \Breakwater\Api::canManage();
}

/** The integration's name for a log source ("Gravity Forms"), or the source as stored. */
function minn_admin_breakwater_source_label( $source ) {
	static $labels = null;
	if ( null === $labels ) {
		$labels = \Breakwater\Api::sources();
	}
	return isset( $labels[ $source ] ) ? (string) $labels[ $source ] : (string) $source;
}

/** A row's state as Breakwater's own log words it. */
function minn_admin_breakwater_state_label( $state ) {
	$labels = \Breakwater\Api::states();
	return isset( $labels[ $state ] ) ? (string) $labels[ $state ] : (string) $state;
}

/** One log row as its line in the list. */
function minn_admin_breakwater_row( array $r ) {
	$state = \Breakwater\Api::state( $r );
	$from  = '' !== (string) $r['email'] ? (string) $r['email'] : __( 'No email given', 'minn-admin' );
	return array(
		'id'         => (int) $r['id'],
		'summary'    => '' !== (string) $r['excerpt'] ? $from . ' · ' . wp_trim_words( (string) $r['excerpt'], 18 ) : $from,
		'form_title' => minn_admin_breakwater_source_label( (string) $r['source'] ) . ( '' !== (string) $r['form'] ? ' · ' . $r['form'] : '' ),
		'status'     => minn_admin_breakwater_state_label( $state ),
		'state'      => $state,
		'score'      => (int) $r['score'],
		'date'       => $r['created_at'],
	);
}

/** One log row's detail: what was sent, why it scored what it did, and what became of it. */
function minn_admin_breakwater_view( array $r ) {
	$answers = array();
	foreach ( \Breakwater\Api::fields( $r ) as $key => $value ) {
		if ( '' !== $value ) {
			$answers[] = minn_admin_forms_typed( ucfirst( str_replace( array( '-', '_' ), ' ', (string) $key ) ), $value, (string) $key );
		}
	}
	$why = array();
	foreach ( \Breakwater\Api::reasons( $r ) as $reason ) {
		$why[] = array(
			'label' => '' !== $reason['detail'] ? $reason['detail'] : $reason['code'],
			/* translators: %d: points a spam signal added to the score. */
			'value' => sprintf( _n( '%d point', '%d points', abs( $reason['points'] ), 'minn-admin' ), $reason['points'] ),
		);
	}
	$state = \Breakwater\Api::state( $r );
	$meta  = minn_admin_forms_meta_dated(
		date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( (string) $r['created_at'] ) ) ),
		minn_admin_breakwater_source_label( (string) $r['source'] ) . ( '' !== (string) $r['form'] ? ' · ' . $r['form'] : '' )
	);
	$meta[] = array( 'label' => __( 'Score', 'minn-admin' ), 'value' => (string) (int) $r['score'] );
	if ( '' !== (string) $r['ip'] ) {
		$meta[] = array( 'label' => 'IP', 'value' => (string) $r['ip'] );
	}
	$page = \Breakwater\Api::page( $r );
	if ( '' !== $page ) {
		$meta[] = array( 'label' => __( 'Page', 'minn-admin' ), 'value' => $page, 'type' => 'url' );
	}
	if ( '' !== (string) $r['other_verdict'] ) {
		$other  = \Breakwater\Api::status()['other'];
		$meta[] = array( 'label' => '' !== $other ? $other : __( 'Other spam service', 'minn-admin' ), 'value' => 'spam' === $r['other_verdict'] ? __( 'Spam', 'minn-admin' ) : __( 'Clean', 'minn-admin' ) );
	}
	if ( '' !== (string) $r['outcome'] ) {
		$meta[] = array( 'label' => __( 'Outcome', 'minn-admin' ), 'value' => (string) $r['outcome'] );
	}
	$more = $why ? array( array( 'title' => __( 'Why', 'minn-admin' ), 'rows' => $why ) ) : array();
	return minn_admin_forms_entry( $answers, $meta, admin_url( 'tools.php?page=breakwater' ), array(
		'status' => minn_admin_breakwater_state_label( $state ),
		'more'   => $more,
	) );
}

/** An action's reply: Breakwater's own sentence and where the row stands now. */
function minn_admin_breakwater_reply( $id, $fresh, $ran ) {
	$out = array( 'state' => $fresh ? \Breakwater\Api::state( $fresh ) : '' );
	if ( is_string( $ran ) && '' !== $ran ) {
		$out['message'] = $ran;
	}
	return $out;
}

minn_admin_resource( 'breakwater/log', function () {
	// Breakwater's own words for a submission still waiting on someone.
	$held = array( 'verdict' => 'spam', 'action' => 'blocked', 'feedback' => '', 'delivered_at' => null );
	return array(
		'active'  => 'minn_admin_breakwater_active',
		'gates'   => array(
			'view'   => 'minn_admin_breakwater_can',
			'manage' => 'minn_admin_breakwater_can',
		),
		'labels'  => array(
			'not_found' => __( 'Submission not found.', 'minn-admin' ),
			'forbidden' => __( 'You cannot access that submission.', 'minn-admin' ),
		),
		'source'  => array(
			'table'   => 'breakwater_log',
			'key'     => 'id',
			'columns' => array( 'id', 'created_at', 'source', 'form', 'verdict', 'action', 'score', 'email', 'excerpt', 'feedback', 'delivered_at' ),
			'detail'  => array( 'id', 'created_at', 'source', 'form', 'verdict', 'action', 'score', 'other_verdict', 'signals', 'ip', 'email', 'excerpt', 'payload', 'feedback', 'delivered_at', 'outcome' ),
		),
		'list'    => array(
			// The Tools screen's tabs, with Held (what waits on a decision) beside them.
			'filter' => array(
				'param'   => 'status',
				'column'  => 'verdict',
				'values'  => array(
					'spam'  => array( 'verdict' => 'spam' ),
					'held'  => $held,
					'clean' => array( 'verdict' => 'ham' ),
					'all'   => array( 'verdict' => array( 'spam', 'ham' ) ),
				),
				'default' => 'spam',
			),
			'facets' => array( array( 'param' => 'source', 'column' => 'source', 'type' => 'key' ) ),
			'search' => array( 'columns' => array( 'email', 'excerpt', 'form' ) ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'utc' ),
			'order'  => array( 'column' => 'id', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_breakwater_row',
			'fields' => minn_admin_forms_fields( array(
				'state' => 'text',
				'score' => 'int',
				'date'  => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'naked' ),
			) ),
		),
		'item'    => array( 'view' => 'minn_admin_breakwater_view' ),
		'actions' => array(
			'release' => array(
				'gate'   => 'manage',
				'when'   => function ( $row ) {
					return \Breakwater\Api::canRelease( $row );
				},
				'code'   => 'not_waiting',
				'refuse' => __( 'This submission is not waiting for a decision.', 'minn-admin' ),
				'run'    => function ( $id ) {
					$done = \Breakwater\Api::release( (int) $id );
					return $done['ok'] ? $done['outcome'] : new WP_Error( 'minn_breakwater_release', $done['outcome'], array( 'status' => 500 ) );
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'released' === \Breakwater\Api::state( $fresh );
				},
				'fail'   => __( 'Breakwater did not record the release. Nothing was delivered.', 'minn-admin' ),
				'reply'  => 'minn_admin_breakwater_reply',
			),
			'spam'    => array(
				'gate'   => 'manage',
				'when'   => function ( $row ) {
					return \Breakwater\Api::canMarkSpam( $row );
				},
				'code'   => 'not_clean',
				'refuse' => __( 'Only clean submissions can be marked as spam.', 'minn-admin' ),
				'run'    => function ( $id ) {
					return \Breakwater\Api::markSpam( (int) $id );
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'was_spam' === \Breakwater\Api::state( $fresh );
				},
				'reply'  => 'minn_admin_breakwater_reply',
				'done'   => __( 'Marked as spam. Breakwater no longer trusts this sender.', 'minn-admin' ),
			),
		),
	);
} );

/** The status card: what waits on a decision, the mode, and the last 14 days. */
function minn_admin_breakwater_status_model() {
	$s    = \Breakwater\Api::status();
	$open = array( 'label' => __( 'Open Breakwater ↗', 'minn-admin' ), 'href' => admin_url( 'tools.php?page=breakwater' ) );
	$n    = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'breakwater/log',
		'chart'    => array( 'column' => 'created_at', 'clock' => 'utc', 'split' => 'verdict', 'secondary' => array( 'spam' ) ),
	) );
	return array(
		'rows'    => array(
			array(
				'label' => __( 'Held for you', 'minn-admin' ),
				'value' => number_format_i18n( $s['held'] ),
				/* translators: 1: submissions checked, 2: days kept. */
				'hint'  => sprintf( __( '%1$s checked in %2$s days', 'minn-admin' ), number_format_i18n( $s['checked'] ), number_format_i18n( $s['days'] ) ),
			),
			array( 'label' => __( 'Mode', 'minn-admin' ), 'value' => $s['mode_label'], 'hint' => $s['mode_help'] ),
			array( 'label' => __( 'Protecting', 'minn-admin' ), 'value' => $s['protecting'] ? implode( ', ', $s['protecting'] ) : __( 'Comments', 'minn-admin' ) ),
		),
		'chart'   => minn_admin_chart_build( (array) $n['days'], __( 'Clean', 'minn-admin' ), __( 'Spam', 'minn-admin' ) ),
		'actions' => array( $open ),
	);
}

minn_admin_endpoint( 'breakwater/status', array(
	'active' => 'minn_admin_breakwater_active',
	'gate'   => function () {
		return minn_admin_breakwater_can();
	},
	'run'    => 'minn_admin_breakwater_status_model',
) );

// The tab list: every integration that has logged something here.
minn_admin_endpoint( 'breakwater/forms', array(
	'active' => 'minn_admin_breakwater_active',
	'gate'   => function () {
		return minn_admin_breakwater_can();
	},
	'run'    => function () {
		$out = array();
		foreach ( Minn_Admin_Kernel::distinct( 'breakwater/log', 'source' ) as $source ) {
			$out[] = array( 'id' => $source, 'title' => minn_admin_breakwater_source_label( $source ) );
		}
		return $out;
	},
) );

// The mode, written through Breakwater's own setter. BREAKWATER_MODE in code
// pins it, and then the screen shows it and takes no save.
minn_admin_settings( 'breakwater', array(
	'route'  => 'breakwater/settings/(?P<tab>[a-z]+)',
	'active' => 'minn_admin_breakwater_active',
	'gate'   => function () {
		return minn_admin_breakwater_can();
	},
	'layout' => function ( $ctx ) {
		if ( 'general' !== $ctx['tab'] ) {
			return null;
		}
		$options = array();
		foreach ( \Breakwater\Api::modes() as $value => $label ) {
			$options[] = array( $value, $label );
		}
		$pinned = \Breakwater\Api::status()['pinned'];
		return array(
			'groups'   => array(
				array(
					'title'  => __( 'Protection', 'minn-admin' ),
					'fields' => array(
						array(
							'key'     => 'mode',
							'label'   => __( 'Mode', 'minn-admin' ),
							'type'    => 'select',
							'options' => $options,
							'help'    => __( 'Automatic monitors while CleanTalk is active and protects otherwise. Monitor only logs every submission and stops nothing.', 'minn-admin' ),
						),
					),
					'locked' => 0,
				),
			),
			'adminUrl' => admin_url( 'tools.php?page=breakwater' ),
			'closed'   => $pinned ? new WP_Error( 'minn_breakwater_pinned', __( 'The mode is set in code (BREAKWATER_MODE), so it cannot be changed here.', 'minn-admin' ), array( 'status' => 409 ) ) : null,
		);
	},
	'read'   => function () {
		return \Breakwater\Api::status()['configured'];
	},
	'validate' => function ( $changes ) {
		if ( isset( $changes['mode'] ) && ! isset( \Breakwater\Api::modes()[ (string) $changes['mode'] ] ) ) {
			return new WP_Error( 'minn_breakwater_mode', __( 'That is not a Breakwater mode.', 'minn-admin' ), array( 'status' => 400, 'field' => 'mode' ) );
		}
		return null;
	},
	'write'  => function ( $changes ) {
		if ( isset( $changes['mode'] ) && ! \Breakwater\Api::setMode( (string) $changes['mode'] ) ) {
			return new WP_Error( 'minn_breakwater_mode', __( 'Breakwater did not take that mode.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return true;
	},
) );

minn_admin_add_surface( 'breakwater', function () {
	return minn_admin_breakwater_active() && minn_admin_breakwater_can();
}, function () {
	$ui      = minn_admin_forms_ui( 'breakwater/log' );
	$release = array( 'label' => __( 'Not spam', 'minn-admin' ), 'confirm' => __( 'Hand this submission back to its form? It is saved and sent as if it had never been held, and the sender is trusted from now on.', 'minn-admin' ), 'key' => 'state' );
	$spam    = array( 'label' => __( 'Was spam', 'minn-admin' ), 'confirm' => __( 'Mark this clean submission as spam?', 'minn-admin' ), 'danger' => true, 'key' => 'state', 'when' => 'clean' );
	return minn_admin_forms_surface( 'breakwater/log', array(
		'sub'      => 'Breakwater',
		'plugin'   => 'breakwater',
		'api'      => 'breakwater',
		'tab'      => 'source',
		'messages' => true,
		'filter'   => array( 'spam' => __( 'Spam', 'minn-admin' ), 'held' => __( 'Held', 'minn-admin' ), 'clean' => __( 'Clean', 'minn-admin' ), 'all' => __( 'All', 'minn-admin' ) ),
		'more'     => array( array( 'key' => 'score', 'label' => __( 'Score', 'minn-admin' ), 'format' => 'num', 'width' => '72px' ) ),
		'width'    => '112px',
		'utc'      => true,
		'actions'  => array(
			$ui( 'release', $release + array( 'when' => 'held' ) ),
			$ui( 'release', $release + array( 'when' => 'logged' ) ),
			$ui( 'spam', $spam ),
		),
		'bulk'     => array(
			$ui( 'release', array( 'label' => __( 'Not spam', 'minn-admin' ), 'confirm' => __( 'Hand the selected submissions back to their forms?', 'minn-admin' ), 'key' => 'state', 'when' => 'held' ) ),
		),
	) ) + array(
		'settings' => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => array( array( 'id' => 'general', 'label' => __( 'General', 'minn-admin' ) ) ),
			'route' => 'minn-admin/v1/breakwater/settings/{tab}',
		),
	);
} );
