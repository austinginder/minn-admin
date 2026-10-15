<?php
/**
 * Bundled adapter: Elementor Pro form submissions.
 *
 * Forms live in Elementor Pro only (free Elementor has no form widget).
 * Submissions sit in {prefix}e_submissions + e_submissions_values; Elementor
 * exposes elementor/v1/form-submissions, but the envelope (data/meta.pagination)
 * and nested form/main shapes don't match Minn's collection primitives. The
 * submissions are a kernel resource (docs/adapter-kernel.md) whose source is
 * Elementor's own Query class: the kernel owns the routes, gates and errors,
 * this file maps Elementor's rows. Cap matches theirs: manage_options.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro submissions module is present and usable.
 */
function minn_admin_elementor_forms_ready() {
	if ( ! class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' ) ) {
		return false;
	}
	// Elementor withdraws submissions wholesale when their Advanced settings
	// set Form Submissions to Disable: the module returns before building the
	// component, so their own screen, their data routes and even the job that
	// ages out trashed rows all stop registering, while the rows already
	// recorded stay in the database. Their autoloader still resolves the class
	// above, so asking whether the code is present sees a feature that is
	// switched off. No capability check can see a setting, so ask the setting:
	// these rows carry names, email addresses, IP addresses and the page
	// someone came from.
	if ( '1' === get_option( 'elementor_form-submissions' ) ) {
		return false;
	}
	// That setting is only half of it. Elementor reads it inside a branch it
	// enters only when the licence covers submissions, so on an unlicensed,
	// lapsed or downgraded Pro the component is never built at all: no screen,
	// no routes, no clean-up job, and the rows already recorded still sitting
	// in the table. Ask whether the component actually registered rather than
	// asking the licence API directly, because that is true only when BOTH of
	// their conditions passed and it does not depend on their internal class
	// names staying put.
	if ( class_exists( '\ElementorPro\Plugin' ) ) {
		try {
			$forms = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'forms' );
			if ( $forms && method_exists( $forms, 'get_component' ) ) {
				return (bool) $forms->get_component( 'form-submissions' );
			}
		} catch ( \Throwable $e ) {
			return true; // their internals moved; the setting above still stands
		}
	}
	return true;
}

/**
 * Same gate Elementor's form-submissions REST uses.
 */
function minn_admin_elementor_forms_can_view() {
	return current_user_can( 'manage_options' );
}

/** Elementor's Query, which owns every read and write of a submission. */
function minn_admin_elementor_forms_query() {
	return \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
}

/**
 * Flatten a submission row for Minn's list/detail.
 *
 * @param array $sub Query::get_submission(s) body.
 * @return array
 */
function minn_admin_elementor_forms_item( $sub ) {
	$values = isset( $sub['values'] ) && is_array( $sub['values'] ) ? $sub['values'] : array();
	$parts  = array();
	foreach ( $values as $v ) {
		$val = isset( $v['value'] ) ? trim( (string) $v['value'] ) : '';
		if ( '' !== $val ) {
			$parts[] = $val;
		}
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	if ( ! $parts && ! empty( $sub['main']['value'] ) ) {
		$parts[] = (string) $sub['main']['value'];
	}

	$status = 'new';
	if ( ! empty( $sub['status'] ) && 'trash' === $sub['status'] ) {
		$status = 'trash';
	} elseif ( ! empty( $sub['is_read'] ) ) {
		$status = 'read';
	} else {
		$status = 'unread';
	}

	$form_name = '';
	if ( ! empty( $sub['form']['name'] ) ) {
		$form_name = (string) $sub['form']['name'];
	}

	$date = ! empty( $sub['created_at_gmt'] )
		? str_replace( ' ', 'T', (string) $sub['created_at_gmt'] ) . 'Z'
		: '';

	$form_key = '';
	if ( ! empty( $sub['post']['id'] ) && ! empty( $sub['element_id'] ) ) {
		$form_key = (int) $sub['post']['id'] . '_' . $sub['element_id'];
	}

	return array(
		'id'        => (int) $sub['id'],
		'summary'   => $parts ? implode( ' · ', $parts ) : __( '(empty submission)', 'minn-admin' ),
		'form_name' => $form_name ?: 'Form',
		'form_key'  => $form_key,
		'status'    => $status,
		// bucket drives when-gates across Received / Trash filters.
		'bucket'    => 'trash' === $status ? 'trash' : 'inbox',
		'date'      => $date,
		'referer'   => isset( $sub['referer'] ) ? (string) $sub['referer'] : '',
	);
}

/**
 * One submission as Elementor's Query reads it, or null. It carries the
 * Received / Trash bucket the actions are offered by.
 *
 * @param int $id Submission id.
 * @return array|null
 */
function minn_admin_elementor_forms_get( $id ) {
	$raw = minn_admin_elementor_forms_query()->get_submission( (int) $id );
	if ( ! $raw || empty( $raw['data'] ) ) {
		return null;
	}
	$sub           = $raw['data'];
	$sub['bucket'] = ( ! empty( $sub['status'] ) && 'trash' === $sub['status'] ) ? 'trash' : 'inbox';
	return $sub;
}

/** Whether the submission row is still in Elementor's table. */
function minn_admin_elementor_forms_exists( $id ) {
	return null !== Minn_Admin_Kernel_SQL::row( 'e_submissions', 'id', (int) $id, array( 'id' ) );
}

/**
 * A page of submissions through Elementor's own Query: their status filter
 * (all is everything not trashed), their search, their form key
 * ("<post>_<element>"), and their date filter for a status-chart day rather
 * than a hand-built clause: it takes the day only (Y-m-d), appends the day's
 * edges itself and converts to UTC with get_gmt_from_date, which is exactly
 * the conversion the site-local bounds need.
 *
 * @param array $q The kernel's list query.
 * @return array [ rows, total ]
 */
function minn_admin_elementor_forms_list( array $q ) {
	$request = $q['request'];
	$filters = array( 'status' => array( 'value' => $q['filter'] ) );
	if ( '' !== $q['search'] ) {
		$filters['search'] = array( 'value' => $q['search'] );
	}
	if ( $request['form'] ) {
		$filters['form'] = array( 'value' => (string) $request['form'] );
	}
	foreach ( array( 'after', 'before' ) as $bound_key ) {
		$bound = (string) $request->get_param( $bound_key );
		if ( '' !== $bound ) {
			$filters[ $bound_key ] = array( 'value' => substr( $bound, 0, 10 ) );
		}
	}
	$result = minn_admin_elementor_forms_query()->get_submissions( array(
		'page'             => $q['page'],
		'per_page'         => $q['per_page'],
		'filters'          => $filters,
		'order'            => array( 'order' => 'desc', 'by' => 'created_at' ),
		'with_meta'        => true,
		'with_form_fields' => false,
	) );
	$rows  = (array) ( $result['data'] ?? array() );
	$total = isset( $result['meta']['pagination']['total'] ) ? (int) $result['meta']['pagination']['total'] : count( $rows );
	return array( $rows, $total );
}

/** A submission's detail; opening it marks it read, as their screen does. */
function minn_admin_elementor_forms_view( array $sub ) {
	// Prefer field labels from the form snapshot when present.
	$labels = array();
	if ( ! empty( $sub['form']['fields'] ) && is_array( $sub['form']['fields'] ) ) {
		foreach ( $sub['form']['fields'] as $field ) {
			if ( empty( $field['id'] ) ) {
				continue;
			}
			$labels[ $field['id'] ] = ! empty( $field['label'] )
				? wp_strip_all_tags( (string) $field['label'] )
				: (string) $field['id'];
		}
	}

	$answers = array();
	foreach ( (array) ( $sub['values'] ?? array() ) as $v ) {
		$key = isset( $v['key'] ) ? (string) $v['key'] : '';
		$val = isset( $v['value'] ) ? (string) $v['value'] : '';
		if ( '' !== trim( $val ) ) {
			$answers[] = minn_admin_forms_typed( $labels[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $key ) ), $val, $key );
		}
	}

	$meta = minn_admin_forms_meta_dated( ! empty( $sub['created_at'] ) ? date_i18n( 'M j, Y g:i a', strtotime( $sub['created_at'] ) ) : '', ! empty( $sub['form']['name'] ) ? $sub['form']['name'] : null );
	if ( ! empty( $sub['referer'] ) ) {
		$meta[] = array(
			'label' => __( 'Page', 'minn-admin' ),
			'value' => ! empty( $sub['referer_title'] )
				? $sub['referer_title'] . ' · ' . $sub['referer']
				: $sub['referer'],
			'type'  => 'url',
		);
	}
	if ( ! empty( $sub['user_ip'] ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => $sub['user_ip'] );
	}
	if ( ! empty( $sub['user_name'] ) ) {
		$meta[] = array( 'label' => __( 'User', 'minn-admin' ), 'value' => $sub['user_name'] );
	}

	$item = minn_admin_elementor_forms_item( $sub );

	// The entry counts as read only when their update landed: the item below
	// carries that state to the page's gates.
	if ( empty( $sub['is_read'] ) && ( empty( $sub['status'] ) || 'trash' !== $sub['status'] )
		&& minn_admin_elementor_forms_query()->update_submission( (int) $sub['id'], array( 'is_read' => 1 ) ) ) {
		$item['status'] = 'read';
	}

	return minn_admin_forms_entry( $answers, $meta, admin_url( 'admin.php?page=e-form-submissions#/form-submissions/' . (int) $sub['id'] ), array(
		'responses' => true,
		// Form name in the title; answers render in the entry body.
		'title'     => $item['form_name'] ?: 'Submission',
		'status'    => $item['status'],
		// The row as it stands now, after opening marked it read:
		// the page's actions gate on it, not on the list's copy.
		'item'      => array(
			'id'     => $item['id'],
			'status' => $item['status'],
			'bucket' => $item['bucket'],
		),
		// Editable answers (adapters/elementor-forms-entry-edit.php).
		'edit'      => function_exists( 'minn_admin_elementor_edit_block' ) ? minn_admin_elementor_edit_block( $sub ) : null,
	) );
}

/**
 * Status card: unread/total from Elementor's own count_submissions_by_status,
 * form count from distinct widgets that have actually received a submission.
 *
 * @return array
 */
function minn_admin_elementor_forms_status_model() {
	$counts = minn_admin_elementor_forms_query()->count_submissions_by_status();
	$arr    = ( is_object( $counts ) && method_exists( $counts, 'all' ) ) ? $counts->all() : (array) $counts;
	$unread = isset( $arr['unread'] ) ? (int) $arr['unread'] : 0;
	$total  = isset( $arr['all'] ) ? (int) $arr['all'] : 0;
	$trash  = isset( $arr['trash'] ) ? (int) $arr['trash'] : 0;

	// A form is a widget on a post: each distinct pair outside the trash.
	$nforms = count( Minn_Admin_Kernel_SQL::totals( 'e_submissions', array( 'post_id', 'element_id' ), array( 'n' => array( 'count' => '*' ) ), array( 'status' => array( 'not' => array( 'trash' ) ) ), array(), 5000 ) );

	// created_at_gmt is UTC: the chart buckets each row onto the site's day.
	// The table may not exist yet (a fresh or CLI install): the chart reads
	// it only once it does.
	$has  = Minn_Admin_Kernel_SQL::exists( 'e_submissions' );
	$days = $has ? Minn_Admin_Kernel::inbox_counts( array(
		'table' => 'e_submissions',
		'chart' => array( 'column' => 'created_at_gmt', 'clock' => 'utc', 'where' => array( 'status' => array( 'not' => array( 'trash' ) ) ), 'split' => 'status' ),
	) )['days'] : minn_admin_chart_days();
	return minn_admin_forms_card(
		minn_admin_forms_unread( $unread, $total, $trash, 'trash' ),
		$nforms,
		$days,
		array( 'label' => __( 'Open Elementor ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=e-form-submissions' ) ),
		'',
		__( 'Submissions', 'minn-admin' )
	);
}

/** An action's reply: the submission and the state the page shows next. */
function minn_admin_elementor_forms_reply( $status ) {
	return function ( $id ) use ( $status ) {
		return array( 'id' => (int) $id, 'status' => $status );
	};
}

// Elementor gates its submissions screen and data routes, reads and writes
// alike, on manage_options. Trash, restore and mark read take a submission in
// any state; a permanent delete only from the trash.
minn_admin_resource( 'elementor/submissions', function () {
	return array(
		'active'  => 'minn_admin_elementor_forms_ready',
		'gates'   => array(
			'view'   => 'minn_admin_elementor_forms_can_view',
			'manage' => 'minn_admin_elementor_forms_can_view',
		),
		'labels'  => array(
			'not_found' => __( 'Submission not found.', 'minn-admin' ),
		),
		'source'  => array(
			'list'   => 'minn_admin_elementor_forms_list',
			'get'    => 'minn_admin_elementor_forms_get',
			'exists' => 'minn_admin_elementor_forms_exists',
		),
		'list'    => array(
			// Their Query filter_status: all (not trash) / unread / read / trash.
			'filter' => array( 'param' => 'status', 'column' => 'status', 'values' => array( 'all', 'unread', 'read', 'trash' ), 'default' => 'all' ),
			'search' => array( 'param' => 'search' ),
			// Their date filter applies the day range (above); declared so the
			// descriptor's day query comes from the resource.
			'range'  => array( 'column' => 'created_at_gmt', 'clock' => 'utc' ),
			'row'    => 'minn_admin_elementor_forms_item',
			'fields' => array(
				'id'        => 'int',
				'summary'   => 'text',
				'form_name' => 'text',
				'form_key'  => 'text',
				'status'    => 'text',
				'bucket'    => 'text',
				// created_at_gmt with a Z, built in the row.
				'date'      => 'text',
				'referer'   => 'text',
			),
		),
		'item'    => array( 'view' => 'minn_admin_elementor_forms_view' ),
		'actions' => array(
			'read'    => array(
				'gate'   => 'manage',
				'run'    => function ( $id ) {
					return minn_admin_elementor_forms_query()->update_submission( $id, array( 'is_read' => 1 ) );
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && ! empty( $fresh['is_read'] );
				},
				'reply'  => minn_admin_elementor_forms_reply( 'read' ),
				'done'   => __( 'Marked as read.', 'minn-admin' ),
			),
			'trash'   => array(
				'gate'      => 'manage',
				'run'       => function ( $id ) {
					return false === minn_admin_elementor_forms_query()->move_to_trash_submission( $id )
						? new WP_Error( 'trash_failed', __( 'Could not trash submission.', 'minn-admin' ), array( 'status' => 500 ) )
						: true;
				},
				'expect'    => array( 'status' => 'trash' ),
				'fail_code' => 'trash_failed',
				'fail'      => __( 'Could not trash submission.', 'minn-admin' ),
				'reply'     => minn_admin_elementor_forms_reply( 'trash' ),
				'done'      => __( 'Moved to trash.', 'minn-admin' ),
			),
			'restore' => array(
				'gate'      => 'manage',
				'run'       => function ( $id ) {
					return false === minn_admin_elementor_forms_query()->restore( $id )
						? new WP_Error( 'restore_failed', __( 'Could not restore submission.', 'minn-admin' ), array( 'status' => 500 ) )
						: true;
				},
				'expect'    => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'trash' !== (string) ( $fresh['status'] ?? '' );
				},
				'fail_code' => 'restore_failed',
				'fail'      => __( 'Could not restore submission.', 'minn-admin' ),
				'reply'     => minn_admin_elementor_forms_reply( 'unread' ),
				'done'      => __( 'Submission restored.', 'minn-admin' ),
			),
			// Permanent delete only for trashed rows (Received goes to Trash first).
			'delete'  => minn_admin_forms_delete( function ( $id ) {
				return false === minn_admin_elementor_forms_query()->delete_submission( $id )
					? new WP_Error( 'delete_failed', __( 'Could not delete submission.', 'minn-admin' ), array( 'status' => 500 ) )
					: true;
			}, __( 'Could not delete submission.', 'minn-admin' ), array(
				'when'   => array( 'bucket' => array( 'trash' ) ),
				'code'   => 'not_trashed',
				'refuse' => __( 'Move the submission to trash before deleting permanently.', 'minn-admin' ),
				'reply'  => 'minn_admin_forms_deleted',
				'done'   => __( 'Submission deleted permanently.', 'minn-admin' ),
			) ),
		),
	);
} );

minn_admin_endpoint( 'elementor/status', array(
	'active' => 'minn_admin_elementor_forms_ready',
	'gate'   => 'minn_admin_elementor_forms_can_view',
	'run'    => function () {
		return minn_admin_elementor_forms_status_model();
	},
) );

// Distinct forms that actually have submissions (the snapshot repository is
// empty until a real Form widget saves; fixtures still need tabs).
minn_admin_endpoint( 'elementor/forms', array(
	'active' => 'minn_admin_elementor_forms_ready',
	'gate'   => 'minn_admin_elementor_forms_can_view',
	'run'    => function () {
		$rows = Minn_Admin_Kernel_SQL::totals( 'e_submissions', array( 'post_id', 'element_id', 'form_name' ), array( 'n' => array( 'count' => '*' ) ), array( 'status' => array( 'not' => array( 'trash' ) ) ), array( 'form_name' => 'ASC' ), 5000 );
		$out  = array();
		foreach ( array_map( function ( $r ) {
			return (object) $r;
		}, $rows ) as $r ) {
			$key   = (int) $r->post_id . '_' . $r->element_id;
			$out[] = array(
				'id'    => $key,
				'title' => $r->form_name ? (string) $r->form_name : $key,
			);
		}
		return $out;
	},
) );

minn_admin_add_surface( 'elementor-forms', function () {
	return minn_admin_elementor_forms_ready() && minn_admin_elementor_forms_can_view();
}, function () {
	$ui = minn_admin_forms_ui( 'elementor/submissions' );
	return minn_admin_forms_surface( 'elementor/submissions', array(
		'sub'     => 'Elementor',
		'plugin'  => array( 'elementor-pro', 'elementor' ),
		'api'     => 'elementor',
		// The list source reads the form key ("<post>_<element>") as `form`.
		'tab'     => 'form',
		'filter'  => array( 'all' => 'Received', 'unread' => 'Unread', 'read' => 'Read', 'trash' => 'Trash' ),
		'form'    => 'form_name',
		'width'   => '100px',
		'actions' => array(
			$ui( 'read', array( 'label' => __( 'Mark as read', 'minn-admin' ), 'when' => 'unread' ) ),
			$ui( 'trash', array( 'label' => __( 'Trash submission', 'minn-admin' ), 'confirm' => __( 'Move this submission to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'key' => 'bucket', 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this submission permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
			array(
				'label' => __( 'Open in Elementor ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=e-form-submissions#/form-submissions/{id}' ),
			),
		),
		'bulk'    => array(
			$ui( 'trash', array( 'label' => __( 'Trash', 'minn-admin' ), 'confirm' => __( 'Move the selected submissions to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'key' => 'bucket', 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected submissions permanently?', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
		),
	) );
} );
