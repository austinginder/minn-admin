<?php
/**
 * Custom post type management (namespace minn-admin/v1).
 *
 * Lists every registered post type and edits definitions through whichever
 * manager owns them — a storage-adapter model:
 *
 *   acf   ACF 6.1+ post types (acf-post-type posts, written via ACF's own
 *         internal-post-type API so definitions stay fully editable in ACF)
 *   cptui Custom Post Type UI (the cptui_post_types option, its shape)
 *   minn  Minn's own lightweight store (minn_admin_post_types option,
 *         registered on init below) — the fallback when no manager is active
 *   code  registered by a theme/plugin in code — shown read-only
 *
 * New definitions go to the preferred writable backend (ACF > CPT UI > Minn)
 * unless the request names one. Deleting a definition never deletes content —
 * the posts stay in the database, same as deactivating any CPT plugin.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_CPT {

	const OPTION = 'minn_admin_post_types';

	const SUPPORTS = array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'author' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_native_types' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register Minn-native definitions. Slugs sanitized again on the way out
	 * so a hand-edited option can't register something register_post_type
	 * would choke on.
	 */
	public static function register_native_types() {
		foreach ( (array) get_option( self::OPTION, array() ) as $slug => $def ) {
			$slug = sanitize_key( $slug );
			if ( ! $slug || post_type_exists( $slug ) ) {
				continue;
			}
			register_post_type( $slug, self::register_args( (array) $def ) );
		}
	}

	private static function register_args( array $def ) {
		$singular = $def['singular'] ?: 'Item';
		$plural   = $def['plural'] ?: $singular . 's';
		return array(
			'labels'       => array(
				'name'          => $plural,
				'singular_name' => $singular,
				'add_new_item'  => 'Add New ' . $singular,
				'edit_item'     => 'Edit ' . $singular,
				'not_found'     => 'No ' . strtolower( $plural ) . ' found.',
			),
			'description'  => (string) ( $def['description'] ?? '' ),
			'public'       => ! empty( $def['public'] ),
			'hierarchical' => ! empty( $def['hierarchical'] ),
			'has_archive'  => ! empty( $def['has_archive'] ),
			'show_in_rest' => ! empty( $def['show_in_rest'] ),
			'show_ui'      => true,
			'menu_icon'    => 'dashicons-admin-post',
			'supports'     => array_values( array_intersect( (array) ( $def['supports'] ?? array( 'title', 'editor' ) ), self::SUPPORTS ) ),
			'taxonomies'   => array_values( array_intersect( (array) ( $def['taxonomies'] ?? array() ), array( 'category', 'post_tag' ) ) ),
		);
	}

	/* ===== REST ===== */

	public static function register_routes() {
		$perm = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'minn-admin/v1',
			'/post-types',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_types' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_type' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			'minn-admin/v1',
			'/post-types/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_type' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_type' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/* ===== Backends ===== */

	private static function acf_available() {
		return function_exists( 'acf_get_internal_post_type_posts' )
			&& function_exists( 'acf_import_internal_post_type' )
			&& function_exists( 'acf_get_setting' )
			&& acf_get_setting( 'enable_post_types' );
	}

	private static function cptui_available() {
		return defined( 'CPTUI_VERSION' );
	}

	/** Map of slug => ACF settings array for ACF-managed post types. */
	private static function acf_types() {
		if ( ! self::acf_available() ) {
			return array();
		}
		$map = array();
		foreach ( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) as $settings ) {
			if ( ! empty( $settings['post_type'] ) ) {
				$map[ $settings['post_type'] ] = $settings;
			}
		}
		return $map;
	}

	private static function writable_backends() {
		$backends = array();
		if ( self::acf_available() ) {
			$backends[] = 'acf';
		}
		if ( self::cptui_available() ) {
			$backends[] = 'cptui';
		}
		$backends[] = 'minn';
		return $backends;
	}

	private static function source_of( $slug ) {
		if ( in_array( $slug, array( 'post', 'page', 'attachment' ), true ) ) {
			return 'core';
		}
		$minn = (array) get_option( self::OPTION, array() );
		if ( isset( $minn[ $slug ] ) ) {
			return 'minn';
		}
		if ( array_key_exists( $slug, self::acf_types() ) ) {
			return 'acf';
		}
		if ( self::cptui_available() ) {
			$cptui = (array) get_option( 'cptui_post_types', array() );
			if ( isset( $cptui[ $slug ] ) ) {
				return 'cptui';
			}
		}
		return 'code';
	}

	/* ===== Handlers ===== */

	public static function list_types() {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( ! $pt->public && ! $pt->show_ui ) {
				continue; // internals: revisions, nav items…
			}
			// Storage/plumbing types other plugins manage themselves.
			if ( preg_match( '/^(acf-|wp_|edd_|elementor_)/', $pt->name )
				|| in_array( $pt->name, array( 'attachment', 'shop_order', 'shop_coupon', 'shop_order_refund', 'scheduled-action', 'product_variation' ), true ) ) {
				continue;
			}
			$counts = (array) wp_count_posts( $pt->name );
			$source = self::source_of( $pt->name );
			$out[]  = array(
				'slug'         => $pt->name,
				'plural'       => $pt->labels->name,
				'singular'     => $pt->labels->singular_name,
				'description'  => $pt->description,
				'public'       => (bool) $pt->public,
				'hierarchical' => (bool) $pt->hierarchical,
				'has_archive'  => (bool) $pt->has_archive,
				'show_in_rest' => (bool) $pt->show_in_rest,
				'rest_base'    => $pt->rest_base ?: $pt->name,
				'supports'     => array_keys( array_filter( get_all_post_type_supports( $pt->name ) ) ),
				'taxonomies'   => array_values( get_object_taxonomies( $pt->name ) ),
				'count'        => array_sum( array_intersect_key( $counts, array_flip( array( 'publish', 'future', 'draft', 'pending', 'private' ) ) ) ),
				'source'       => $source,
				'editable'     => in_array( $source, array( 'acf', 'cptui', 'minn' ), true ),
			);
		}
		return rest_ensure_response(
			array(
				'types'    => $out,
				'backends' => self::writable_backends(),
			)
		);
	}

	private static function def_from_request( WP_REST_Request $request ) {
		return array(
			'singular'     => sanitize_text_field( (string) $request['singular'] ),
			'plural'       => sanitize_text_field( (string) $request['plural'] ),
			'description'  => sanitize_text_field( (string) $request['description'] ),
			'public'       => ! empty( $request['public'] ),
			'hierarchical' => ! empty( $request['hierarchical'] ),
			'has_archive'  => ! empty( $request['has_archive'] ),
			'show_in_rest' => ! empty( $request['show_in_rest'] ),
			'supports'     => array_values( array_intersect( (array) $request['supports'], self::SUPPORTS ) ),
			'taxonomies'   => array_values( array_intersect( (array) $request['taxonomies'], array( 'category', 'post_tag' ) ) ),
		);
	}

	public static function create_type( WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request['slug'] );
		if ( ! $slug || strlen( $slug ) > 20 ) {
			return new WP_Error( 'invalid_slug', 'Slug is required: lowercase letters, numbers, dashes or underscores, 20 characters max.', array( 'status' => 400 ) );
		}
		if ( post_type_exists( $slug ) ) {
			return new WP_Error( 'exists', "A “{$slug}” post type already exists.", array( 'status' => 409 ) );
		}
		$def      = self::def_from_request( $request );
		$backends = self::writable_backends();
		$backend  = in_array( $request['backend'], $backends, true ) ? $request['backend'] : $backends[0];
		if ( ! $def['singular'] || ! $def['plural'] ) {
			return new WP_Error( 'missing_labels', 'Singular and plural labels are required.', array( 'status' => 400 ) );
		}

		if ( 'acf' === $backend ) {
			$result = self::acf_write( $slug, $def, null );
		} elseif ( 'cptui' === $backend ) {
			$result = self::cptui_write( $slug, $def );
		} else {
			$result = self::minn_write( $slug, $def );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		flush_rewrite_rules();
		return rest_ensure_response( array( 'created' => $slug, 'backend' => $backend ) );
	}

	public static function update_type( WP_REST_Request $request ) {
		$slug   = sanitize_key( (string) $request['slug'] );
		$source = self::source_of( $slug );
		if ( ! post_type_exists( $slug ) ) {
			return new WP_Error( 'not_found', 'No such post type.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $source, array( 'acf', 'cptui', 'minn' ), true ) ) {
			return new WP_Error( 'not_editable', 'This post type is registered in code and can only be changed there.', array( 'status' => 400 ) );
		}
		$def = self::def_from_request( $request );
		if ( ! $def['singular'] || ! $def['plural'] ) {
			return new WP_Error( 'missing_labels', 'Singular and plural labels are required.', array( 'status' => 400 ) );
		}

		if ( 'acf' === $source ) {
			$existing = self::acf_types()[ $slug ] ?? null;
			$result   = self::acf_write( $slug, $def, $existing );
		} elseif ( 'cptui' === $source ) {
			$result = self::cptui_write( $slug, $def );
		} else {
			$result = self::minn_write( $slug, $def );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		flush_rewrite_rules();
		return rest_ensure_response( array( 'updated' => $slug ) );
	}

	public static function delete_type( WP_REST_Request $request ) {
		$slug   = sanitize_key( (string) $request['slug'] );
		$source = self::source_of( $slug );
		if ( 'minn' === $source ) {
			$types = (array) get_option( self::OPTION, array() );
			unset( $types[ $slug ] );
			update_option( self::OPTION, $types );
		} elseif ( 'acf' === $source ) {
			$existing = self::acf_types()[ $slug ] ?? null;
			if ( ! $existing || empty( $existing['ID'] ) ) {
				return new WP_Error( 'not_found', 'ACF definition not found.', array( 'status' => 404 ) );
			}
			wp_trash_post( (int) $existing['ID'] ); // trash, not delete — recoverable in ACF's UI
		} elseif ( 'cptui' === $source ) {
			$types = (array) get_option( 'cptui_post_types', array() );
			unset( $types[ $slug ] );
			update_option( 'cptui_post_types', $types );
		} else {
			return new WP_Error( 'not_editable', 'This post type is registered in code and can only be removed there.', array( 'status' => 400 ) );
		}
		flush_rewrite_rules();
		return rest_ensure_response( array( 'deleted' => $slug, 'note' => 'Definition removed; existing content stays in the database.' ) );
	}

	/* ===== Backend writers ===== */

	private static function minn_write( $slug, array $def ) {
		$types          = (array) get_option( self::OPTION, array() );
		$types[ $slug ] = $def;
		update_option( self::OPTION, $types );
		register_post_type( $slug, self::register_args( $def ) ); // live for this request
		return true;
	}

	/**
	 * Write through ACF's internal-post-type API — the same path its importer
	 * uses — so the result is a first-class ACF post type.
	 */
	private static function acf_write( $slug, array $def, $existing ) {
		$settings = array(
			'key'          => $existing['key'] ?? uniqid( 'post_type_' ),
			'title'        => $def['plural'],
			'post_type'    => $slug,
			'active'       => true,
			'labels'       => array_merge(
				(array) ( $existing['labels'] ?? array() ),
				array(
					'name'          => $def['plural'],
					'singular_name' => $def['singular'],
				)
			),
			'description'  => $def['description'],
			'public'       => $def['public'],
			'hierarchical' => $def['hierarchical'],
			'has_archive'  => $def['has_archive'],
			'show_in_rest' => $def['show_in_rest'],
			'supports'     => $def['supports'],
			'taxonomies'   => $def['taxonomies'],
		);
		if ( $existing ) {
			$settings = array_merge( $existing, $settings );
			$saved    = acf_update_internal_post_type( $settings, 'acf-post-type' );
		} else {
			$saved = acf_import_internal_post_type( $settings, 'acf-post-type' );
		}
		if ( empty( $saved ) ) {
			return new WP_Error( 'acf_failed', 'ACF could not save the post type.', array( 'status' => 500 ) );
		}
		return true;
	}

	/** Write in CPT UI's option shape (string booleans and all). */
	private static function cptui_write( $slug, array $def ) {
		$b     = function ( $v ) {
			return $v ? 'true' : 'false';
		};
		$types = (array) get_option( 'cptui_post_types', array() );
		$types[ $slug ] = array_merge(
			(array) ( $types[ $slug ] ?? array() ),
			array(
				'name'           => $slug,
				'label'          => $def['plural'],
				'singular_label' => $def['singular'],
				'description'    => $def['description'],
				'public'         => $b( $def['public'] ),
				'show_ui'        => 'true',
				'show_in_rest'   => $b( $def['show_in_rest'] ),
				'has_archive'    => $b( $def['has_archive'] ),
				'hierarchical'   => $b( $def['hierarchical'] ),
				'supports'       => $def['supports'],
				'taxonomies'     => $def['taxonomies'],
			)
		);
		update_option( 'cptui_post_types', $types );
		return true;
	}
}
