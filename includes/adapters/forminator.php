<?php
/**
 * Bundled adapter: Forminator — forms family (entries + forms).
 *
 * Forminator stores submissions in {prefix}frmt_form_entry (+ per-answer
 * rows in frmt_form_entry_meta), with complex answers (name fields,
 * checkboxes, uploads) as arrays under one meta key. Reads are
 * prefix-scoped SQL over the entry table (the shim convention) with
 * answers hydrated through Forminator's OWN entry model (its meta layer
 * owns the array shapes), labels from its form models at runtime, and
 * delete through Forminator_API::delete_entry — its complete cleanup
 * (meta rows go with the entry). Forminator has no entry trash: delete is
 * permanent and the confirm says so.
 *
 * Clock: entries stamp date_i18n() — SITE-LOCAL, emitted naked (the
 * client parses zoneless datetimes as local).
 *
 * Caps mirror the plugin: forminator_is_user_allowed('forminator-entries')
 * resolves its own permission model (manage_options / manage_forminator /
 * the granular manage_forminator_submissions its Permissions settings can
 * grant), so a site that grants submissions to editors grants Minn's view.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_forminator_active() {
	global $wpdb;
	if ( ! class_exists( 'Forminator_API' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'frmt_form_entry';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

function minn_admin_forminator_can() {
	if ( function_exists( 'forminator_is_user_allowed' ) ) {
		return forminator_is_user_allowed( 'forminator-entries' );
	}
	return current_user_can( 'manage_options' );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_forminator_skip_types() {
	return array( 'html', 'section', 'page-break', 'pagination', 'captcha', 'cloudflare-turnstile' );
}

/**
 * Input fields for a form via Forminator's own models:
 * [element_id => label], in form order. Degrades to raw meta keys.
 *
 * @param int $form_id Form id.
 * @return array<string,string>
 */
function minn_admin_forminator_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		$form = Forminator_API::get_form( (int) $form_id );
		if ( ! is_wp_error( $form ) && $form ) {
			foreach ( (array) $form->get_fields() as $field ) {
				// Their field model resolves properties via __get with no
				// __isset — isset() is always false; read directly.
				$type = (string) $field->type;
				if ( in_array( $type, minn_admin_forminator_skip_types(), true ) ) {
					continue;
				}
				$slug  = (string) $field->slug;
				$label = trim( wp_strip_all_tags( (string) $field->field_label ) );
				if ( '' !== $slug ) {
					$out[ $slug ] = $label ? $label : ucfirst( str_replace( '-', ' ', $slug ) );
				}
			}
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form display titles: [id => title] (settings.formName is the human name). */
function minn_admin_forminator_titles() {
	static $titles = null;
	if ( null === $titles ) {
		$titles = array();
		try {
			foreach ( (array) Forminator_API::get_forms( null, 1, 100 ) as $f ) {
				$name = isset( $f->settings['formName'] ) && '' !== $f->settings['formName']
					? (string) $f->settings['formName']
					: (string) $f->name;
				$titles[ (int) $f->id ] = $name;
			}
		} catch ( \Throwable $e ) {
			$titles = array();
		}
	}
	return $titles;
}

/**
 * Answers for one entry as [element_id => flat string], hydrated through
 * Forminator's own entry model (arrays flatten to comma lists; addon and
 * internal keys are skipped).
 *
 * @param int $entry_id Entry id.
 * @return array<string,string>
 */
function minn_admin_forminator_answers( $entry_id ) {
	$out = array();
	try {
		$entry = new Forminator_Form_Entry_Model( (int) $entry_id );
		foreach ( (array) $entry->meta_data as $key => $meta ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'forminator_addon_' ) || 0 === strpos( $key, '_' ) ) {
				continue;
			}
			$v = isset( $meta['value'] ) ? $meta['value'] : '';
			if ( is_array( $v ) ) {
				$flat = array();
				array_walk_recursive( $v, function ( $leaf ) use ( &$flat ) {
					if ( '' !== trim( (string) $leaf ) ) {
						$flat[] = (string) $leaf;
					}
				} );
				$v = implode( ', ', $flat );
			}
			$out[ $key ] = trim( (string) $v );
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_forminator_active() || ! minn_admin_forminator_can() ) {
		return $surfaces;
	}

	$surfaces['forminator'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Forminator',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is the filter above (their permission model)
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/forminator/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/forminator/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/forminator/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/forminator/entries/{id}',
					'confirm' => 'Delete this entry permanently? Forminator has no entry trash — there is no undo.',
					'danger'  => true,
				),
			),
		),
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/forminator/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Forminator ↗',
					'href'  => admin_url( 'admin.php?page=forminator-cform-wizard&id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_forminator_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/forminator/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_forminator_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$manage = ! empty( $request['manage'] );
			$out    = array();
			try {
				foreach ( (array) Forminator_API::get_forms( null, 1, 100 ) as $f ) {
					$row = array(
						'id'    => (int) $f->id,
						'title' => isset( $f->settings['formName'] ) && '' !== $f->settings['formName']
							? (string) $f->settings['formName']
							: (string) $f->name,
					);
					if ( $manage ) {
						$row['entries'] = (int) Forminator_Form_Entry_Model::count_entries( (int) $f->id );
						$row['status']  = 'publish' === (string) $f->status ? 'active' : (string) $f->status;
					}
					$out[] = $row;
				}
			} catch ( \Throwable $e ) {
				$out = array();
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/forminator/entries', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_forminator_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$entry    = $wpdb->prefix . 'frmt_form_entry';
			$meta     = $wpdb->prefix . 'frmt_form_entry_meta';

			// Active custom-form entries only (drafts/abandoned/spam are
			// Forminator's own workflows on its screen).
			$where = "WHERE e.entry_type = 'custom-forms' AND e.status = 'active' AND e.is_spam = 0";
			$args  = array();
			if ( $request['form_id'] ) {
				$where .= ' AND e.form_id = %d';
				$args[] = (int) $request['form_id'];
			}
			if ( $request['search'] ) {
				// Same shape as Forminator's own entries search: a LIKE over
				// answer meta, excluding its addon bookkeeping rows.
				$where .= " AND EXISTS ( SELECT 1 FROM {$meta} m WHERE m.entry_id = e.entry_id AND m.meta_key NOT LIKE %s AND m.meta_value LIKE %s )";
				$args[] = $wpdb->esc_like( 'forminator_addon_' ) . '%';
				$args[] = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
			}
			$total = (int) $wpdb->get_var( $args
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$entry} e {$where}", ...$args ) // phpcs:ignore
				: "SELECT COUNT(*) FROM {$entry} e {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.entry_id, e.form_id, e.date_created FROM {$entry} e {$where} ORDER BY e.entry_id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				...array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$titles = minn_admin_forminator_titles();
			$items  = array();
			foreach ( (array) $rows as $row ) {
				$form_id = (int) $row->form_id;
				$fields  = minn_admin_forminator_fields( $form_id );
				$answers = minn_admin_forminator_answers( (int) $row->entry_id );
				$parts   = array();
				foreach ( $fields as $slug => $label ) {
					if ( isset( $answers[ $slug ] ) && '' !== $answers[ $slug ] ) {
						$parts[] = $answers[ $slug ];
					}
					if ( count( $parts ) >= 3 ) {
						break;
					}
				}
				$items[] = array(
					'id'         => (int) $row->entry_id,
					'summary'    => $parts ? implode( ' · ', $parts ) : '(empty entry)',
					'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
					// date_i18n stamp — site-local, emitted naked.
					'date'       => str_replace( ' ', 'T', (string) $row->date_created ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/forminator/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_forminator_can',
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT entry_id, form_id, date_created FROM {$wpdb->prefix}frmt_form_entry WHERE entry_id = %d AND entry_type = 'custom-forms'", // phpcs:ignore
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				$form_id = (int) $row->form_id;
				$fields  = minn_admin_forminator_fields( $form_id );
				$answers = minn_admin_forminator_answers( (int) $row->entry_id );
				$titles  = minn_admin_forminator_titles();

				$rows = array();
				foreach ( $fields as $slug => $label ) {
					$rows[] = array(
						'label' => $label,
						'value' => isset( $answers[ $slug ] ) && '' !== $answers[ $slug ] ? $answers[ $slug ] : '—',
					);
				}
				// Answers whose field no longer exists on the form still show.
				foreach ( $answers as $slug => $value ) {
					if ( ! isset( $fields[ $slug ] ) && '' !== $value ) {
						$rows[] = array(
							'label' => ucfirst( str_replace( '-', ' ', $slug ) ),
							'value' => $value,
						);
					}
				}
				$meta = array(
					array( 'label' => 'Form', 'value' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id ),
					array( 'label' => 'Entry', 'value' => '#' . (int) $row->entry_id ),
					array( 'label' => 'Submitted', 'value' => date_i18n( 'M j, Y g:i a', strtotime( $row->date_created ) ) ),
				);
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'sections' => array(
						array( 'title' => 'Answers', 'rows' => $rows ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=forminator-entries&form_type=forminator_forms&form_id=' . $form_id ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_forminator_can',
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id  = (int) $request['id'];
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT entry_id, form_id FROM {$wpdb->prefix}frmt_form_entry WHERE entry_id = %d AND entry_type = 'custom-forms'", // phpcs:ignore
					$id
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				// Their complete cleanup (meta and upload bookkeeping go too).
				$result = Forminator_API::delete_entry( (int) $row->form_id, $id );
				if ( is_wp_error( $result ) ) {
					$result->add_data( array( 'status' => 400 ) );
					return $result;
				}
				$still = $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}frmt_form_entry WHERE entry_id = %d", $id ) ); // phpcs:ignore
				if ( $still ) {
					return new WP_Error( 'delete_failed', 'Forminator reported success but the entry still exists.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => 'Entry deleted permanently.' ) );
			},
		),
	) );
} );
