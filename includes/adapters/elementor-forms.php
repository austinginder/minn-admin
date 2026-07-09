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
	return class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' );
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
		'summary'   => $parts ? implode( ' · ', $parts ) : '(empty submission)',
		'form_name' => $form_name ?: 'Form',
		'form_key'  => $form_key,
		'status'    => $status,
		'date'      => $date,
		'referer'   => isset( $sub['referer'] ) ? (string) $sub['referer'] : '',
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
		'label'      => 'Forms',
		'family'     => 'forms',
		'sub'        => 'Elementor',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gating above + in the shim.
		'collection' => array(
			'viewLabel' => 'Entries',
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
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_name', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/elementor/submissions/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Trash submission',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/elementor/submissions/{id}',
					'confirm' => 'Move this submission to trash?',
					'danger'  => true,
				),
				array(
					'label' => 'Open in Elementor ↗',
					'href'  => admin_url( 'admin.php?page=e-form-submissions#/form-submissions/{id}' ),
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

			$filters = array(
				// Hide trash from the default list (matches Elementor admin).
				'status' => array( 'value' => 'all' ),
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
				// Skip trash rows when filter is "all" (Elementor still returns them
				// under some filter combos; keep list clean).
				if ( isset( $sub['status'] ) && 'trash' === $sub['status'] ) {
					continue;
				}
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
					return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
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
					'label' => 'Submitted',
					'value' => ! empty( $sub['created_at'] )
						? date_i18n( 'M j, Y g:i a', strtotime( $sub['created_at'] ) )
						: '',
				);
				if ( ! empty( $sub['form']['name'] ) ) {
					$meta[] = array( 'label' => 'Form', 'value' => $sub['form']['name'] );
				}
				if ( ! empty( $sub['referer'] ) ) {
					$meta[] = array(
						'label' => 'Page',
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
					$meta[] = array( 'label' => 'User', 'value' => $sub['user_name'] );
				}

				$item = minn_admin_elementor_forms_item( $sub );

				return rest_ensure_response( array(
					'kind'     => 'entry',
					// Form name in the title; answers render in the entry body.
					'title'    => $item['form_name'] ?: 'Submission',
					'status'   => $item['status'],
					'sections' => array(
						array( 'title' => 'Responses', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=e-form-submissions#/form-submissions/' . (int) $sub['id'] ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_elementor_forms_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				$query  = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
				$id     = (int) $request['id'];
				$raw    = $query->get_submission( $id );
				if ( ! $raw || empty( $raw['data'] ) ) {
					return new WP_Error( 'not_found', 'Submission not found.', array( 'status' => 404 ) );
				}
				$ok = $query->move_to_trash_submission( $id );
				if ( false === $ok ) {
					return new WP_Error( 'trash_failed', 'Could not trash submission.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'id' => $id, 'status' => 'trash' ) );
			},
		),
	) );
} );
