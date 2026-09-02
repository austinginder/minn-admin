<?php
/**
 * Bundled adapter: Elementor Pro form submissions.
 *
 * Forms live in Elementor Pro only (free Elementor has no form widget).
 * Submissions sit in {prefix}e_submissions + e_submissions_values; Elementor
 * exposes elementor/v1/form-submissions, but the envelope (data/meta.pagination)
 * and nested form/main shapes don't match Minn's collection primitives, so
 * this shim normalizes via Elementor's own Query class. Cap matches theirs:
 * manage_options.
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
 * Status card: unread/total from Elementor's own count_submissions_by_status,
 * form count from distinct widgets that have actually received a submission.
 *
 * @return array
 */
function minn_admin_elementor_forms_status_model() {
	$query  = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
	$counts = $query->count_submissions_by_status();
	$arr    = ( is_object( $counts ) && method_exists( $counts, 'all' ) ) ? $counts->all() : (array) $counts;
	$unread = isset( $arr['unread'] ) ? (int) $arr['unread'] : 0;
	$total  = isset( $arr['all'] ) ? (int) $arr['all'] : 0;
	$trash  = isset( $arr['trash'] ) ? (int) $arr['trash'] : 0;

	global $wpdb;
	$table = $wpdb->prefix . 'e_submissions';
	$nforms = 0;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table.
	$like = $wpdb->esc_like( $table );
	$has  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	if ( $has ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table, no user input.
		$nforms = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT CONCAT(post_id, '_', element_id)) FROM `{$table}` WHERE status != 'trash'"
		);
	}

	$hint = number_format_i18n( $total ) . ' total';
	if ( $trash ) {
		$hint .= ', ' . number_format_i18n( $trash ) . ' trash';
	}

	// created_at_gmt is UTC: bucket each row onto the site's day in PHP.
	$chart = minn_admin_chart_days();
	if ( $has ) {
		$chart_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT created_at_gmt FROM `{$table}` WHERE status != 'trash' AND created_at_gmt >= %s", // phpcs:ignore
			minn_admin_chart_utc_since()
		) );
		foreach ( (array) $chart_rows as $cr ) {
			minn_admin_chart_bump( $chart, minn_admin_chart_utc_day( $cr->created_at_gmt ) );
		}
	}
	return array(
		'rows'    => array(
			array( 'label' => __( 'Unread entries', 'minn-admin' ), 'value' => number_format_i18n( $unread ), 'hint' => $hint ),
			array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => number_format_i18n( $nforms ) ),
		),
		'chart'   => minn_admin_chart_build( $chart, __( 'Submissions', 'minn-admin' ) ),
		'actions' => array(
			array(
				'label' => __( 'Open Elementor ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=e-form-submissions' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_elementor_forms_ready() ) {
		return $surfaces;
	}
	if ( ! minn_admin_elementor_forms_can_view() ) {
		return $surfaces;
	}

	$surfaces['elementor-forms'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Elementor',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gating above + in the shim.
		'status'     => array( 'route' => 'minn-admin/v1/elementor/status' ),
		'collection' => array(
			'viewLabel' => __( 'Entries', 'minn-admin' ),
			'route'     => 'minn-admin/v1/elementor/submissions',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/elementor/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form',
				'allLabel' => __( 'All entries', 'minn-admin' ),
			),
			// Their Query filter_status: all (not trash) / unread / read / trash.
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				'options' => array(
					array( 'all', 'Received' ),
					array( 'unread', 'Unread' ),
					array( 'read', 'Read' ),
					array( 'trash', 'Trash' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_name', 'label' => __( 'Form', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/elementor/submissions/{id}',
			),
			'actions'   => array(
				array(
					'label'  => __( 'Mark as read', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/elementor/submissions/{id}/read',
					'when'   => array( 'key' => 'status', 'equals' => 'unread' ),
				),
				array(
					'label'   => __( 'Trash submission', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/elementor/submissions/{id}/trash',
					'confirm' => __( 'Move this submission to trash?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'  => __( 'Restore', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/elementor/submissions/{id}/restore',
					'when'   => array( 'key' => 'bucket', 'equals' => 'trash' ),
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/elementor/submissions/{id}',
					'confirm' => __( 'Delete this submission permanently? There is no undo.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'trash' ),
				),
				array(
					'label' => __( 'Open in Elementor ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=e-form-submissions#/form-submissions/{id}' ),
				),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Trash', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/elementor/submissions/{id}/trash',
					'confirm' => __( 'Move the selected submissions to trash?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'  => __( 'Restore', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/elementor/submissions/{id}/restore',
					'when'   => array( 'key' => 'bucket', 'equals' => 'trash' ),
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/elementor/submissions/{id}',
					'confirm' => __( 'Delete the selected submissions permanently?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'trash' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_elementor_forms_ready() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/elementor/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_elementor_forms_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function () {
			global $wpdb;
			$table = $wpdb->prefix . 'e_submissions';
			// Distinct forms that actually have submissions (snapshot repo is
			// empty until a real Form widget saves; fixtures still need tabs).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table.
			$rows = $wpdb->get_results(
				"SELECT DISTINCT post_id, element_id, form_name
				 FROM `{$table}`
				 WHERE status != 'trash'
				 ORDER BY form_name ASC"
			);
			$out = array();
			foreach ( (array) $rows as $r ) {
				$key = (int) $r->post_id . '_' . $r->element_id;
				$out[] = array(
					'id'    => $key,
					'title' => $r->form_name ? (string) $r->form_name : $key,
				);
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/submissions', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			$query    = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );

			// Their filter_status: all | unread | read | trash.
			$status = sanitize_key( (string) ( $request['status'] ?: 'all' ) );
			if ( ! in_array( $status, array( 'all', 'unread', 'read', 'trash' ), true ) ) {
				$status = 'all';
			}
			$filters = array(
				'status' => array( 'value' => $status ),
			);
			if ( $request['search'] ) {
				$filters['search'] = array( 'value' => (string) $request['search'] );
			}
			if ( $request['form'] ) {
				// Elementor filter expects post_id_element_id.
				$filters['form'] = array( 'value' => (string) $request['form'] );
			}

			$result = $query->get_submissions( array(
				'page'             => $page,
				'per_page'         => $per_page,
				'filters'          => $filters,
				'order'            => array( 'order' => 'desc', 'by' => 'created_at' ),
				'with_meta'        => true,
				'with_form_fields' => false,
			) );

			$items = array();
			foreach ( (array) ( $result['data'] ?? array() ) as $sub ) {
				$items[] = minn_admin_elementor_forms_item( $sub );
			}

			$total = isset( $result['meta']['pagination']['total'] )
				? (int) $result['meta']['pagination']['total']
				: count( $items );

			return rest_ensure_response( array(
				'items' => $items,
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/submissions/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_elementor_forms_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
				$raw   = $query->get_submission( (int) $request['id'] );
				if ( ! $raw || empty( $raw['data'] ) ) {
					return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$sub = $raw['data'];

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
					if ( '' === trim( $val ) ) {
						continue;
					}
					$label = $labels[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
					$answers[] = array(
						'label' => $label,
						'value' => $val,
						'type'  => ( false !== strpos( $key, 'email' ) || is_email( $val ) ) ? 'email'
							: ( ( 0 === strpos( $val, 'http' ) ) ? 'url' : 'text' ),
					);
				}

				$meta   = array();
				$meta[] = array(
					'label' => __( 'Submitted', 'minn-admin' ),
					'value' => ! empty( $sub['created_at'] )
						? date_i18n( 'M j, Y g:i a', strtotime( $sub['created_at'] ) )
						: '',
				);
				if ( ! empty( $sub['form']['name'] ) ) {
					$meta[] = array( 'label' => __( 'Form', 'minn-admin' ), 'value' => $sub['form']['name'] );
				}
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

				// Opening a detail marks unread → read (their screen semantics).
				if ( empty( $sub['is_read'] ) && ( empty( $sub['status'] ) || 'trash' !== $sub['status'] ) ) {
					$query->update_submission( (int) $sub['id'], array( 'is_read' => 1 ) );
					$item['status'] = 'read';
				}

				return rest_ensure_response( array(
					'kind'     => 'entry',
					// Form name in the title; answers render in the entry body.
					'title'    => $item['form_name'] ?: 'Submission',
					'status'   => $item['status'],
					'sections' => array(
						array( 'title' => __( 'Responses', 'minn-admin' ), 'rows' => $answers ),
						array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=e-form-submissions#/form-submissions/' . (int) $sub['id'] ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_elementor_forms_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
				$id    = (int) $request['id'];
				$raw   = $query->get_submission( $id );
				if ( ! $raw || empty( $raw['data'] ) ) {
					return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Permanent delete only for trashed rows (Received → Trash first).
				if ( empty( $raw['data']['status'] ) || 'trash' !== $raw['data']['status'] ) {
					return new WP_Error( 'not_trashed', __( 'Move the submission to trash before deleting permanently.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$ok = $query->delete_submission( $id );
				if ( false === $ok ) {
					return new WP_Error( 'delete_failed', __( 'Could not delete submission.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'id' => $id, 'deleted' => true, 'message' => __( 'Submission deleted permanently.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/submissions/(?P<id>\d+)/trash', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$id    = (int) $request['id'];
			$raw   = $query->get_submission( $id );
			if ( ! $raw || empty( $raw['data'] ) ) {
				return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$ok = $query->move_to_trash_submission( $id );
			if ( false === $ok ) {
				return new WP_Error( 'trash_failed', __( 'Could not trash submission.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'id' => $id, 'status' => 'trash', 'message' => __( 'Moved to trash.', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/submissions/(?P<id>\d+)/restore', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$id    = (int) $request['id'];
			$raw   = $query->get_submission( $id );
			if ( ! $raw || empty( $raw['data'] ) ) {
				return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$ok = $query->restore( $id );
			if ( false === $ok ) {
				return new WP_Error( 'restore_failed', __( 'Could not restore submission.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'id' => $id, 'status' => 'unread', 'message' => __( 'Submission restored.', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/submissions/(?P<id>\d+)/read', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_elementor_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$id    = (int) $request['id'];
			$raw   = $query->get_submission( $id );
			if ( ! $raw || empty( $raw['data'] ) ) {
				return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$query->update_submission( $id, array( 'is_read' => 1 ) );
			return rest_ensure_response( array( 'id' => $id, 'status' => 'read', 'message' => __( 'Marked as read.', 'minn-admin' ) ) );
		},
	) );
} );
