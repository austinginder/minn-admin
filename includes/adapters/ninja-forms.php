<?php
/**
 * Bundled adapter: Ninja Forms — forms family (entries + forms).
 *
 * Ninja Forms keeps form definitions in {prefix}nf3_forms / nf3_fields but
 * stores every submission as an `nf_sub` post with plain postmeta
 * (`_form_id`, `_seq_num`, `_field_{id}` per answer), so the entries shim
 * is WP_Query + postmeta reads: no custom-table SQL and nothing serialized.
 * Field labels come from Ninja Forms' own field models at runtime, so a
 * form edit updates the entry cards with no adapter change. Trash routes
 * through their own Submission model (their screen's semantics); restoring
 * stays on their screen and the confirm says so honestly.
 *
 * Caps mirror the plugin: everything gates through its own
 * `ninja_forms_admin_submissions_capabilities` filter (default
 * manage_options via `ninja_forms_admin_menu_capabilities`), so a site
 * that grants submissions to editors grants Minn's view too.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_ninja_forms_active() {
	global $wpdb;
	if ( ! class_exists( 'Ninja_Forms' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'nf3_forms';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

function minn_admin_ninja_forms_can() {
	$cap = apply_filters( 'ninja_forms_admin_menu_capabilities', 'manage_options' );
	$cap = apply_filters( 'ninja_forms_admin_submissions_capabilities', $cap );
	return current_user_can( $cap );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_ninja_forms_skip_types() {
	return array( 'submit', 'html', 'hr', 'divider', 'recaptcha', 'recaptcha_v3', 'spam', 'note' );
}

/**
 * Input fields for a form via Ninja Forms' own models: [id => label],
 * in form order. A model-layer change degrades to raw meta keys.
 *
 * @param int $form_id Form id.
 * @return array<int,string>
 */
function minn_admin_ninja_forms_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		$fields = Ninja_Forms()->form( (int) $form_id )->get_fields();
		$sort   = array();
		foreach ( (array) $fields as $field ) {
			$type = (string) $field->get_setting( 'type' );
			if ( in_array( $type, minn_admin_ninja_forms_skip_types(), true ) ) {
				continue;
			}
			$label  = trim( wp_strip_all_tags( (string) $field->get_setting( 'label' ) ) );
			$sort[] = array(
				'id'    => (int) $field->get_id(),
				'order' => (int) $field->get_setting( 'order' ),
				'label' => $label ? $label : 'Field ' . $field->get_id(),
			);
		}
		usort( $sort, function ( $a, $b ) {
			return $a['order'] - $b['order'];
		} );
		foreach ( $sort as $f ) {
			$out[ $f['id'] ] = $f['label'];
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form titles map from nf3_forms. */
function minn_admin_ninja_forms_titles() {
	static $titles = null;
	if ( null === $titles ) {
		global $wpdb;
		$titles = array();
		$rows   = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}nf3_forms" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$titles[ (int) $r->id ] = (string) $r->title;
		}
	}
	return $titles;
}

/**
 * Answers for one nf_sub post as [field_id => flat string], from its own
 * postmeta (plain core meta; checkbox arrays flatten to comma lists).
 *
 * @param int $post_id Submission post id.
 * @return array<int,string>
 */
function minn_admin_ninja_forms_answers( $post_id ) {
	$out = array();
	foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
		if ( ! preg_match( '/^_field_(\d+)$/', (string) $key, $m ) ) {
			continue;
		}
		$v = maybe_unserialize( $values[0] ); // core postmeta, not a foreign blob
		if ( is_array( $v ) ) {
			$flat = array();
			array_walk_recursive( $v, function ( $leaf ) use ( &$flat ) {
				if ( '' !== trim( (string) $leaf ) ) {
					$flat[] = (string) $leaf;
				}
			} );
			$v = implode( ', ', $flat );
		}
		$out[ (int) $m[1] ] = trim( (string) $v );
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ninja_forms_active() || ! minn_admin_ninja_forms_can() ) {
		return $surfaces;
	}

	$surfaces['ninja-forms'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Ninja Forms',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is the filter above (their cap filter)
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/ninja-forms/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/ninja-forms/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'seq', 'label' => '#', 'format' => 'num' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/ninja-forms/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Trash entry',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/ninja-forms/entries/{id}/trash',
					'confirm' => 'Trash this entry? Restoring happens on Ninja Forms\' own screen.',
					'danger'  => true,
				),
			),
		),
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/ninja-forms/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
				array( 'key' => 'date', 'label' => 'Created', 'format' => 'ago' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Ninja Forms ↗',
					'href'  => admin_url( 'admin.php?page=ninja-forms&form_id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ninja_forms_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/ninja-forms/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_ninja_forms_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$rows   = $wpdb->get_results( "SELECT id, title, created_at FROM {$wpdb->prefix}nf3_forms ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$manage = ! empty( $request['manage'] );
			$out    = array();
			foreach ( (array) $rows as $r ) {
				$row = array(
					'id'    => (int) $r->id,
					'title' => (string) $r->title,
				);
				if ( $manage ) {
					// Live count, not the plugin's all-time `subs` counter
					// (which keeps counting after entries are deleted).
					$row['entries'] = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
						 WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND m.meta_key = '_form_id' AND m.meta_value = %s",
						(string) $r->id
					) );
					$row['date'] = $r->created_at ? str_replace( ' ', 'T', (string) $r->created_at ) : '';
				}
				$out[] = $row;
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ninja-forms/entries', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_ninja_forms_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );

			$meta_query = array();
			if ( $request['form_id'] ) {
				$meta_query[] = array(
					'key'   => '_form_id',
					'value' => (string) (int) $request['form_id'],
				);
			}
			if ( $request['search'] ) {
				// Answers live in per-field postmeta; a keyless LIKE clause
				// searches across all of a submission's values.
				$meta_query[] = array(
					'value'   => (string) $request['search'],
					'compare' => 'LIKE',
				);
			}
			$q = new WP_Query( array(
				'post_type'      => 'nf_sub',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'no_found_rows'  => false,
			) );

			$titles = minn_admin_ninja_forms_titles();
			$items  = array();
			foreach ( $q->posts as $post ) {
				$form_id = (int) get_post_meta( $post->ID, '_form_id', true );
				$fields  = minn_admin_ninja_forms_fields( $form_id );
				$answers = minn_admin_ninja_forms_answers( $post->ID );
				$parts   = array();
				foreach ( $fields as $fid => $label ) {
					if ( isset( $answers[ $fid ] ) && '' !== $answers[ $fid ] ) {
						$parts[] = $answers[ $fid ];
					}
					if ( count( $parts ) >= 3 ) {
						break;
					}
				}
				$items[] = array(
					'id'         => (int) $post->ID,
					'summary'    => $parts ? implode( ' · ', $parts ) : '(empty entry)',
					'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
					'seq'        => (int) get_post_meta( $post->ID, '_seq_num', true ),
					// post_date is site-local; emit naked (client parses local).
					'date'       => str_replace( ' ', 'T', (string) $post->post_date ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ninja-forms/entries/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_ninja_forms_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || 'nf_sub' !== $post->post_type ) {
				return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
			}
			$form_id = (int) get_post_meta( $post->ID, '_form_id', true );
			$fields  = minn_admin_ninja_forms_fields( $form_id );
			$answers = minn_admin_ninja_forms_answers( $post->ID );
			$titles  = minn_admin_ninja_forms_titles();

			$rows = array();
			foreach ( $fields as $fid => $label ) {
				$rows[] = array(
					'label' => $label,
					'value' => isset( $answers[ $fid ] ) && '' !== $answers[ $fid ] ? $answers[ $fid ] : '—',
				);
			}
			// Answers whose field no longer exists on the form still show.
			foreach ( $answers as $fid => $value ) {
				if ( ! isset( $fields[ $fid ] ) && '' !== $value ) {
					$rows[] = array(
						'label' => 'Field ' . $fid,
						'value' => $value,
					);
				}
			}
			$meta = array(
				array( 'label' => 'Form', 'value' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id ),
				array( 'label' => 'Entry', 'value' => '#' . (int) get_post_meta( $post->ID, '_seq_num', true ) ),
				array( 'label' => 'Submitted', 'value' => date_i18n( 'M j, Y g:i a', strtotime( $post->post_date ) ) ),
			);
			return rest_ensure_response( array(
				'kind'     => 'entry',
				'sections' => array(
					array( 'title' => 'Answers', 'rows' => $rows ),
					array( 'title' => 'Submission', 'rows' => $meta ),
				),
				'adminUrl' => admin_url( 'admin.php?page=nf-submissions&form_id=' . $form_id ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ninja-forms/entries/(?P<id>\d+)/trash', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_ninja_forms_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || 'nf_sub' !== $post->post_type ) {
				return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
			}
			// Their own model's trash (their screen's semantics).
			try {
				Ninja_Forms()->form()->sub( $post->ID )->get()->trash();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'trash_failed', 'Ninja Forms could not trash: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( 'trash' !== get_post_status( $post->ID ) ) {
				return new WP_Error( 'trash_failed', 'Ninja Forms reported success but the entry is not in the trash.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => 'Moved to trash. Restore from Ninja Forms\' screen.' ) );
		},
	) );
} );
