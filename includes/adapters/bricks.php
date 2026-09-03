<?php
/**
 * Bundled adapter: Bricks templates (theme, bricks.io).
 *
 * Bricks stores templates as `bricks_template` posts (capability_type
 * 'post', deliberately not REST-exposed upstream, so Minn's content
 * switcher never sees them). The template kind lives in postmeta
 * `_bricks_template_type` (Bricks' own `templateTypes` control options:
 * header / footer / content ["Single"] / section / popup / archive /
 * search / error, plus password_protection while that feature is enabled,
 * plus the WooCommerce types while WooCommerce is active); display
 * conditions live as a repeater under
 * `_bricks_template_settings['templateConditions']`; tags/bundles are the
 * `template_tag` / `template_bundle` taxonomies. The builder edit URL is
 * the template's permalink with `?bricks=run` (pure front-end app, same as
 * the page-builders descriptor).
 *
 * Capability model: list/edit/trash ride the CPT's standard post caps, but
 * template CREATION (and duplication, which is creation) goes through
 * Bricks' own permission model — Builder_Permissions::user_has_permission(
 * 'create_templates' ), resolved against their bricks_full_access /
 * bricks_edit_content role caps and any custom capability sets. Never check
 * those raw caps directly. The Edit-in-Bricks link only renders for users
 * their Capabilities class lets into the builder.
 *
 * Deliberately not built: template canvas editing (the builder is one click
 * away), condition editing (their repeater has live ajax-fed term/post
 * pickers; conditions render read-only here), the remote template
 * library (element trees, not blocks — nothing Minn's editor could insert),
 * and zip-of-JSON bulk import (their admin form; a single JSON file is
 * the interchange Minn already exports).
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_bricks_active() {
	return defined( 'BRICKS_VERSION' ) && class_exists( '\Bricks\Templates' );
}

/**
 * The template-type vocabulary (raw meta value => label).
 *
 * Reads Bricks' own `templateTypes` control options so password protection
 * and every WooCommerce type join on the same terms they do in Bricks
 * (the Woo types are filtered onto that list only while WooCommerce is
 * active). A static fallback covers the eight core types if their setup
 * class has not run yet.
 *
 * @return array
 */
function minn_admin_bricks_template_types() {
	if ( class_exists( '\Bricks\Setup' ) && method_exists( '\Bricks\Setup', 'get_control_options' ) ) {
		try {
			$from_bricks = \Bricks\Setup::get_control_options( 'templateTypes' );
		} catch ( \Throwable $e ) {
			$from_bricks = null;
		}
		if ( is_array( $from_bricks ) && $from_bricks ) {
			$out = array();
			foreach ( $from_bricks as $value => $label ) {
				$out[ (string) $value ] = (string) $label;
			}
			return $out;
		}
	}
	$types = array(
		'header'  => __( 'Header', 'minn-admin' ),
		'footer'  => __( 'Footer', 'minn-admin' ),
		'content' => __( 'Single', 'minn-admin' ),
		'section' => __( 'Section', 'minn-admin' ),
		'popup'   => __( 'Popup', 'minn-admin' ),
		'archive' => __( 'Archive', 'minn-admin' ),
		'search'  => __( 'Search results', 'minn-admin' ),
		'error'   => __( 'Error page', 'minn-admin' ),
	);
	if ( class_exists( '\Bricks\Database' ) && \Bricks\Database::get_setting( 'passwordProtectionEnabled', false ) ) {
		$types['password_protection'] = __( 'Password protection', 'minn-admin' );
	}
	return $types;
}

/**
 * Whether the current user may see the template library, through Bricks' own
 * resolver.
 *
 * Bricks only puts its Templates menu in front of a non-administrator when its
 * own permission matrix allows it, and that matrix is a stored setting rather
 * than a capability. Gating on the plain post capability named the plugin, and
 * listed every template in it, to exactly the roles Bricks shows no Templates
 * screen at all. Export and duplicate in this file already ask Bricks; the
 * list and the sidebar entry ask the same question now.
 */
/**
 * Bricks' page/template settings carry three raw-script keys that the vendor
 * gates on unfiltered_html. Its own guard is a meta filter shaped for an
 * admin-ajax request (it early-returns when there is no $_POST['postId'] and
 * no global post), so on a REST write it does nothing. Reproduce the strip.
 *
 * Keeps any value already stored by someone who does hold the capability,
 * which is what the vendor's filter does when it runs at all.
 */
function minn_admin_bricks_strip_unfiltered_html( $settings, $post_id = 0 ) {
	if ( ! is_array( $settings ) || current_user_can( 'unfiltered_html' ) ) {
		return $settings;
	}
	$script_keys = array( 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' );
	$existing    = $post_id ? get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true ) : array();
	foreach ( $script_keys as $key ) {
		unset( $settings[ $key ] );
		if ( is_array( $existing ) && isset( $existing[ $key ] ) ) {
			$settings[ $key ] = $existing[ $key ];
		}
	}
	return $settings;
}

/**
 * The fallback when Bricks exposes no permission resolver.
 *
 * Falling back to edit_posts would hand an Author create/import/delete of
 * templates that render site-wide, so the fallback has to be at least as
 * strict as the thing it stands in for.
 */
function minn_admin_bricks_fallback_access() {
	if ( class_exists( '\Bricks\Capabilities' ) && method_exists( '\Bricks\Capabilities', 'current_user_has_full_access' ) ) {
		try {
			return (bool) \Bricks\Capabilities::current_user_has_full_access();
		} catch ( \Throwable $e ) {
			return current_user_can( 'manage_options' );
		}
	}
	return current_user_can( 'manage_options' );
}

/**
 * Whether the current user may change Bricks' global settings.
 *
 * Templates go through Bricks' permission model, but settings do not: Bricks
 * gates save_settings() on plain manage_options and refuses everyone else,
 * whatever builder access a site has granted. Builder access is a licence to
 * design pages, not to change what the theme does site-wide, and the settings
 * this surface writes include maintenance mode, which decides whether the
 * public site is reachable at all. So this asks the question Bricks itself
 * asks for these routes rather than the one it asks for templates.
 */
function minn_admin_bricks_can_manage_settings() {
	return current_user_can( 'manage_options' );
}

function minn_admin_bricks_can_view_templates() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( class_exists( '\Bricks\Builder_Permissions' ) && method_exists( '\Bricks\Builder_Permissions', 'user_has_permission' ) ) {
		try {
			return (bool) \Bricks\Builder_Permissions::user_has_permission( 'edit_templates' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return minn_admin_bricks_fallback_access();
}

/** Whether the current user may create templates, through Bricks' own resolver. */
function minn_admin_bricks_can_create() {
	if ( class_exists( '\Bricks\Builder_Permissions' ) && method_exists( '\Bricks\Builder_Permissions', 'user_has_permission' ) ) {
		try {
			return (bool) \Bricks\Builder_Permissions::user_has_permission( 'create_templates' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return minn_admin_bricks_fallback_access();
}

/** Whether the current user may delete templates, through Bricks' own resolver. */
function minn_admin_bricks_can_delete() {
	if ( class_exists( '\Bricks\Builder_Permissions' ) && method_exists( '\Bricks\Builder_Permissions', 'user_has_permission' ) ) {
		try {
			return (bool) \Bricks\Builder_Permissions::user_has_permission( 'delete_templates' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return minn_admin_bricks_fallback_access();
}

/** Whether the current user may export templates, through Bricks' own resolver. */
function minn_admin_bricks_can_export() {
	if ( class_exists( '\Bricks\Builder_Permissions' ) && method_exists( '\Bricks\Builder_Permissions', 'user_has_permission' ) ) {
		try {
			return (bool) \Bricks\Builder_Permissions::user_has_permission( 'import_export_templates' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return minn_admin_bricks_fallback_access();
}

/**
 * Human-readable summary of a template's display conditions.
 *
 * Reads the templateConditions repeater: each row's `main` picks the shape
 * (any / frontpage / postType / archiveType / search / error / terms / ids /
 * hook) with sub-fields per shape. `exclude` inverts a row. Term values are
 * stored as "{taxonomy}::{term_id}"; ids are post IDs.
 *
 * @param int $post_id Template post ID.
 * @return string Semicolon-joined summaries, '' when unassigned.
 */
function minn_admin_bricks_conditions_summary( $post_id ) {
	$settings   = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_SETTINGS, true );
	$conditions = is_array( $settings ) && isset( $settings['templateConditions'] ) && is_array( $settings['templateConditions'] )
		? $settings['templateConditions']
		: array();
	$parts      = array();

	$post_type_names = function ( $slugs ) {
		$names = array();
		foreach ( (array) $slugs as $slug ) {
			$obj     = get_post_type_object( (string) $slug );
			$names[] = $obj ? $obj->labels->name : (string) $slug;
		}
		return implode( ', ', $names );
	};
	$term_names = function ( $values ) {
		$names = array();
		foreach ( (array) $values as $value ) {
			$value = (string) $value;
			if ( 'all' === $value ) {
				$names[] = __( 'All terms', 'minn-admin' );
				continue;
			}
			$id   = (int) substr( $value, strrpos( $value, ':' ) + 1 );
			$term = $id ? get_term( $id ) : null;
			$names[] = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
		}
		return implode( ', ', $names );
	};

	foreach ( $conditions as $c ) {
		if ( ! is_array( $c ) || empty( $c['main'] ) ) {
			continue;
		}
		$text = '';
		switch ( (string) $c['main'] ) {
			case 'any':
				$text = __( 'Entire website', 'minn-admin' );
				break;
			case 'frontpage':
				$text = __( 'Front page', 'minn-admin' );
				break;
			case 'postType':
				$text = __( 'Post type', 'minn-admin' ) . ': ' . $post_type_names( $c['postType'] ?? array() );
				break;
			case 'archiveType':
				$kinds = array();
				foreach ( (array) ( $c['archiveType'] ?? array() ) as $a ) {
					switch ( (string) $a ) {
						case 'any':
							$kinds[] = __( 'all archives', 'minn-admin' );
							break;
						case 'postType':
							$kinds[] = $post_type_names( $c['archivePostTypes'] ?? array() );
							break;
						case 'author':
							$kinds[] = __( 'author', 'minn-admin' );
							break;
						case 'date':
							$kinds[] = __( 'date', 'minn-admin' );
							break;
						case 'term':
							$kinds[] = $term_names( $c['archiveTerms'] ?? array() );
							break;
					}
				}
				$text = __( 'Archive', 'minn-admin' ) . ( $kinds ? ': ' . implode( ', ', array_filter( $kinds ) ) : '' );
				break;
			case 'search':
				$text = __( 'Search results', 'minn-admin' );
				break;
			case 'error':
				$text = __( 'Error page', 'minn-admin' );
				break;
			case 'terms':
				$text = __( 'Terms', 'minn-admin' ) . ': ' . $term_names( $c['terms'] ?? array() );
				break;
			case 'ids':
				$titles = array();
				foreach ( (array) ( $c['ids'] ?? array() ) as $id ) {
					$title    = get_the_title( (int) $id );
					$titles[] = '' !== $title ? $title : ( '#' . (int) $id );
				}
				$text = implode( ', ', $titles );
				break;
			case 'hook':
				$text = __( 'Hook', 'minn-admin' ) . ': ' . (string) ( $c['hookName'] ?? '' );
				break;
			default:
				$text = (string) $c['main'];
		}
		if ( '' === $text ) {
			continue;
		}
		if ( ! empty( $c['exclude'] ) ) {
			/* translators: %s: a display-condition summary, e.g. "Front page". */
			$text = sprintf( __( 'Exclude: %s', 'minn-admin' ), $text );
		}
		$parts[] = $text;
	}
	return implode( ' · ', $parts );
}

/** Normalize a template post into a surface item. */
function minn_admin_bricks_template_item( $post ) {
	$types = minn_admin_bricks_template_types();
	$type  = (string) \Bricks\Templates::get_template_type( $post->ID );
	$tags  = get_the_terms( $post, BRICKS_DB_TEMPLATE_TAX_TAG );
	$param = defined( 'BRICKS_BUILDER_PARAM' ) ? BRICKS_BUILDER_PARAM : 'bricks';
	return array(
		'id'         => (int) $post->ID,
		'title'      => '' !== $post->post_title ? html_entity_decode( $post->post_title, ENT_QUOTES ) : __( '(no title)', 'minn-admin' ),
		'type'       => $type,
		'typeLabel'  => isset( $types[ $type ] ) ? $types[ $type ] : ( '' !== $type ? $type : '—' ),
		'conditions' => minn_admin_bricks_conditions_summary( $post->ID ),
		'tags'       => ( $tags && ! is_wp_error( $tags ) ) ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '',
		'status'     => (string) $post->post_status,
		'inTrash'    => 'trash' === $post->post_status,
		'modified'   => (string) $post->post_modified_gmt,
		'editUrl'    => add_query_arg( $param, 'run', get_permalink( $post ) ),
	);
}

/**
 * Insert one Bricks template export object.
 *
 * Follows Templates::import_template's per-file loop using their Helpers
 * (sanitize, fresh element ids, template settings, global class merge).
 * Image sideloading stays their importer's job: same-site round-trips keep
 * local attachment ids, and remote URLs still render.
 *
 * @param array $data Decoded JSON object from Bricks' export.
 * @return int|WP_Error New post ID.
 */
function minn_admin_bricks_import_one( $data ) {
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invalid', __( 'That file is not a Bricks template export.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$title = '';
	if ( ! empty( $data['title'] ) ) {
		$title = (string) $data['title'];
	} elseif ( ! empty( $data['templateTitle'] ) ) {
		$title = (string) $data['templateTitle'];
	}
	if ( '' === $title ) {
		$title = __( '(no title)', 'minn-admin' );
	}
	$insert = array(
		'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
		'post_title'  => esc_html( $title ),
		'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
	);
	$tax    = array();
	if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
		$tax[ BRICKS_DB_TEMPLATE_TAX_TAG ] = array_values( array_filter( array_map( 'strval', $data['tags'] ) ) );
	}
	if ( ! empty( $data['bundles'] ) && is_array( $data['bundles'] ) ) {
		$tax[ BRICKS_DB_TEMPLATE_TAX_BUNDLE ] = array_values( array_filter( array_map( 'strval', $data['bundles'] ) ) );
	}
	if ( $tax ) {
		$insert['tax_input'] = $tax;
	}
	$id = wp_insert_post( $insert, true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$type = '';
	if ( ! empty( $data['templateType'] ) ) {
		$type = sanitize_key( (string) $data['templateType'] );
	} elseif ( ! empty( $data['type'] ) ) {
		$type = sanitize_key( (string) $data['type'] );
	}
	// Create and edit both validate the type against Bricks' live vocabulary;
	// import did not, so an unknown key stored here produced a template no
	// type tab and no Bricks dropdown could ever show again.
	if ( $type && ! isset( minn_admin_bricks_template_types()[ $type ] ) ) {
		$type = '';
	}
	if ( $type ) {
		update_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, $type );
	}
	if ( ! empty( $data['pageSettings'] ) && is_array( $data['pageSettings'] ) ) {
		// Bricks strips the three script keys in a meta filter that opens with
		// `$_POST['postId'] ?: get_the_ID()` and returns early when that is
		// falsy. Under REST neither exists, so the vendor's guard silently
		// no-ops and this write would land unfiltered. Its sibling guard
		// (Ajax::update_bricks_postmeta) covers only the content/header/footer
		// keys, not this one. Strip here rather than trusting a filter shaped
		// for an admin-ajax request.
		update_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, wp_slash( minn_admin_bricks_strip_unfiltered_html( $data['pageSettings'] ) ) );
	}
	if ( ! empty( $data['templateSettings'] ) && is_array( $data['templateSettings'] ) && class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'set_template_settings' ) ) {
		try {
			// set_template_settings is a bare update_post_meta, so the same
			// strip applies. wp_slash because update_metadata unslashes.
			\Bricks\Helpers::set_template_settings( $id, wp_slash( minn_admin_bricks_strip_unfiltered_html( $data['templateSettings'] ) ) );
		} catch ( \Throwable $e ) {
			/* settings are optional; the tree still imported */
		}
	}

	$area     = 'content';
	$meta_key = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
	if ( ! empty( $data['header'] ) && is_array( $data['header'] ) ) {
		$area     = 'header';
		$meta_key = defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2';
		$elements = $data['header'];
	} elseif ( ! empty( $data['footer'] ) && is_array( $data['footer'] ) ) {
		$area     = 'footer';
		$meta_key = defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2';
		$elements = $data['footer'];
	} else {
		$elements = ! empty( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array();
	}

	if ( $elements && class_exists( '\Bricks\Helpers' ) ) {
		if ( method_exists( '\Bricks\Helpers', 'sanitize_bricks_data' ) ) {
			// sanitize_bricks_data only unsets executeCode and the query
			// editor. It applies no kses at all.
			$elements = \Bricks\Helpers::sanitize_bricks_data( $elements );
		}
		// The function that actually enforces the unfiltered_html boundary on
		// an element tree. Bricks calls it at ten sites in its own save paths;
		// the meta-filter route that would otherwise catch this carries the
		// same request-shaped early return as the page-settings one above, so
		// under REST nothing runs it unless we do. Without it an imported Text
		// element's content reaches the front end raw.
		if ( method_exists( '\Bricks\Helpers', 'security_check_elements_before_save' ) ) {
			$elements = \Bricks\Helpers::security_check_elements_before_save( $elements, $id, $area );
		}
		if ( method_exists( '\Bricks\Helpers', 'generate_new_element_ids' ) ) {
			try {
				$elements = \Bricks\Helpers::generate_new_element_ids( $elements );
			} catch ( \Throwable $e ) { /* original ids still unique on a new post */ }
		}
	}

	if ( ! empty( $data['global_classes'] ) && is_array( $data['global_classes'] ) && class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'save_global_classes_in_db' ) ) {
		$global_classes = get_option( defined( 'BRICKS_DB_GLOBAL_CLASSES' ) ? BRICKS_DB_GLOBAL_CLASSES : 'bricks_global_classes', array() );
		if ( ! is_array( $global_classes ) ) {
			$global_classes = array();
		}
		$existing_ids   = array();
		$existing_names = array();
		foreach ( $global_classes as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['id'] ) ) {
				$existing_ids[ (string) $row['id'] ] = true;
			}
			if ( isset( $row['name'] ) ) {
				$existing_names[ (string) $row['name'] ] = true;
			}
		}
		$changed = false;
		foreach ( $data['global_classes'] as $incoming ) {
			if ( ! is_array( $incoming ) || empty( $incoming['id'] ) ) {
				continue;
			}
			if ( isset( $existing_ids[ (string) $incoming['id'] ] ) || ( isset( $incoming['name'] ) && isset( $existing_names[ (string) $incoming['name'] ] ) ) ) {
				continue;
			}
			$global_classes[] = $incoming;
			$changed          = true;
		}
		if ( $changed ) {
			try {
				\Bricks\Helpers::save_global_classes_in_db( $global_classes );
			} catch ( \Throwable $e ) { /* classes are additive; the tree still imported */ }
		}
	}

	if ( $elements ) {
		// update_metadata unslashes, and the tree came straight from
		// json_decode, so without this every backslash in custom CSS, a regex
		// or escaped _content is eaten. The type-move and duplicate paths in
		// this file already do it; the importer did not.
		update_post_meta( $id, $meta_key, wp_slash( $elements ) );
		if ( class_exists( '\Bricks\Database' ) && \Bricks\Database::get_setting( 'cssLoading' ) === 'file'
			&& class_exists( '\Bricks\Assets_Files' ) && method_exists( '\Bricks\Assets_Files', 'generate_post_css_file' ) ) {
			try {
				\Bricks\Assets_Files::generate_post_css_file( $id, $area, $elements );
			} catch ( \Throwable $e ) { /* inline CSS still renders */ }
		}
	}
	return (int) $id;
}

/**
 * Import one or more Bricks template JSON documents.
 *
 * @param string $content Raw JSON (a single object or an array of them).
 * @return array|WP_Error { message, ids }
 */
function minn_admin_bricks_import_templates( $content ) {
	$data = json_decode( (string) $content, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invalid', __( 'That file is not JSON, or is not a Bricks template export.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$looks_like_one = isset( $data['title'] ) || isset( $data['templateType'] ) || isset( $data['templateTitle'] )
		|| isset( $data['content'] ) || isset( $data['header'] ) || isset( $data['footer'] );
	$list = $looks_like_one ? array( $data ) : $data;
	if ( ! $list || ! isset( $list[0] ) || ! is_array( $list[0] ) ) {
		return new WP_Error( 'invalid', __( 'That file is not a Bricks template export.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	// Each row is an insert plus several meta writes, and on file-CSS sites a
	// stylesheet regeneration. One request should not be able to ask for
	// thousands of them.
	if ( count( $list ) > 50 ) {
		return new WP_Error(
			'too_many',
			sprintf(
				/* translators: %s: number of templates in the file. */
				__( 'That file holds %s templates. Import 50 or fewer at a time.', 'minn-admin' ),
				number_format_i18n( count( $list ) )
			),
			array( 'status' => 400 )
		);
	}
	$ids = array();
	foreach ( $list as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$one = minn_admin_bricks_import_one( $row );
		if ( is_wp_error( $one ) ) {
			return $one;
		}
		$ids[] = $one;
	}
	if ( ! $ids ) {
		return new WP_Error( 'invalid', __( 'That file did not contain a template.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$n = count( $ids );
	return array(
		/* translators: %d: number of templates imported. */
		'message' => sprintf( _n( 'Imported %d template.', 'Imported %d templates.', $n, 'minn-admin' ), $n ),
		'ids'     => $ids,
	);
}

/**
 * Front-end bar extras: Bricks templates rendering on THIS page (header,
 * content, footer, plus WooCommerce templates Bricks lists on cart /
 * checkout / account). Bricks' own admin bar lists these under "Edit
 * with Bricks"; Minn hides that bar, so the same list lives here.
 *
 * Reads Database::$active_templates (already resolved for this request)
 * and the builder URL through Helpers::get_builder_edit_link. Capability
 * is Bricks' own Capabilities::current_user_can_use_builder per template.
 *
 * @param array $edits Existing extras from other adapters.
 * @return array[] { url, label, sub, hint }
 */
function minn_admin_bricks_bar_template_edits( $edits ) {
	if ( ! minn_admin_bricks_active() ) {
		return $edits;
	}
	if ( ! class_exists( '\Bricks\Database' ) || ! class_exists( '\Bricks\Helpers' ) || ! class_exists( '\Bricks\Capabilities' ) ) {
		return $edits;
	}
	$active = \Bricks\Database::$active_templates;
	if ( ! is_array( $active ) || ( empty( $active['header'] ) && empty( $active['footer'] ) && empty( $active['content'] ) ) ) {
		if ( method_exists( '\Bricks\Database', 'set_active_templates' ) ) {
			try {
				\Bricks\Database::set_active_templates();
				$active = \Bricks\Database::$active_templates;
			} catch ( \Throwable $e ) {
				return $edits;
			}
		}
	}
	if ( ! is_array( $active ) ) {
		return $edits;
	}

	$slots = array(
		'header'  => __( 'Edit header', 'minn-admin' ),
		'content' => __( 'Edit content', 'minn-admin' ),
		'footer'  => __( 'Edit footer', 'minn-admin' ),
	);
	// Same skip Bricks' admin bar uses: a slot whose template IS the
	// current post is the page itself, not an extra template wrapping it.
	$current = get_queried_object_id();
	if ( is_home() ) {
		$current = (int) get_option( 'page_for_posts' );
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$current = (int) wc_get_page_id( 'shop' );
	}
	$seen = array();
	foreach ( $slots as $slot => $label ) {
		$id = isset( $active[ $slot ] ) ? (int) $active[ $slot ] : 0;
		if ( $id < 1 || isset( $seen[ $id ] ) || ( $current && $id === (int) $current ) ) {
			continue;
		}
		try {
			if ( ! \Bricks\Capabilities::current_user_can_use_builder( $id ) ) {
				continue;
			}
			$url = \Bricks\Helpers::get_builder_edit_link( $id );
		} catch ( \Throwable $e ) {
			continue;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			continue;
		}
		$title = get_the_title( $id );
		if ( '' === $title ) {
			$title = '#' . $id;
		}
		$edits[] = array(
			'url'   => $url,
			'label' => $label,
			'sub'   => $title,
			'hint'  => sprintf(
				/* translators: %s: a Bricks template title. */
				__( 'This page uses the %s template', 'minn-admin' ),
				$title
			),
		);
		$seen[ $id ] = true;
	}

	// WooCommerce cart / checkout / account templates. Bricks lists these
	// under Edit with Bricks on those pages; they are not header/content/
	// footer slots so the loop above never sees them.
	if ( class_exists( '\Bricks\Woocommerce' )
		&& method_exists( '\Bricks\Woocommerce', 'get_active_templates_for_current_page' ) ) {
		$woo_labels = method_exists( '\Bricks\Woocommerce', 'get_woo_templates' )
			? \Bricks\Woocommerce::get_woo_templates()
			: array();
		try {
			$woo = \Bricks\Woocommerce::get_active_templates_for_current_page();
		} catch ( \Throwable $e ) {
			$woo = array();
		}
		foreach ( (array) $woo as $slot => $tid ) {
			$tid = (int) $tid;
			if ( $tid < 1 || isset( $seen[ $tid ] ) || ( $current && $tid === (int) $current ) ) {
				continue;
			}
			try {
				if ( ! \Bricks\Capabilities::current_user_can_use_builder( $tid ) ) {
					continue;
				}
				$url = \Bricks\Helpers::get_builder_edit_link( $tid );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$title = get_the_title( $tid );
			if ( '' === $title ) {
				$title = '#' . $tid;
			}
			$label = isset( $woo_labels[ $slot ] ) ? (string) $woo_labels[ $slot ] : $title;
			$edits[] = array(
				'url'   => $url,
				/* translators: %s: a WooCommerce Bricks template type (Cart, Checkout…). */
				'label' => sprintf( __( 'Edit %s', 'minn-admin' ), $label ),
				'sub'   => $title,
				'hint'  => sprintf(
					/* translators: %s: a Bricks template title. */
					__( 'This page uses the %s template', 'minn-admin' ),
					$title
				),
			);
			$seen[ $tid ] = true;
		}
	}
	return $edits;
}
add_filter( 'minn_admin_bar_template_edits', 'minn_admin_bricks_bar_template_edits' );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_bricks_active() || ! minn_admin_bricks_can_view_templates() ) {
		return $surfaces;
	}
	$types       = minn_admin_bricks_template_types();
	$type_tabs   = array();
	$type_select = array();
	foreach ( $types as $value => $label ) {
		$type_tabs[]   = array( $value, $label );
		$type_select[] = array( $value, $label );
	}

	// Trash is a list tab, not a template type: it is never in the create
	// select, and create/edit refuse it the same way they refuse any other
	// key Bricks does not advertise.
	$type_tabs[] = array( 'trash', __( 'Trash', 'minn-admin' ) );

	$not_trash = array( 'key' => 'inTrash', 'equals' => false );
	$is_trash  = array( 'key' => 'inTrash', 'equals' => true );

	$actions = array();
	// Builder access is Bricks' own gate, not a WP capability — a user who can
	// manage the list without builder access just doesn't get the edit link.
	if ( class_exists( '\Bricks\Capabilities' ) && \Bricks\Capabilities::current_user_can_use_builder() ) {
		$actions[] = array(
			'label' => __( 'Edit in Bricks', 'minn-admin' ),
			'href'  => '{editUrl}',
			'when'  => $not_trash,
		);
	}
	if ( minn_admin_bricks_can_create() ) {
		$actions[] = array(
			'label' => __( 'Duplicate', 'minn-admin' ),
			'route' => 'minn-admin/v1/bricks/templates/{id}/duplicate',
			'when'  => $not_trash,
		);
	}
	if ( minn_admin_bricks_can_export() ) {
		$actions[] = array(
			'label'    => __( 'Export template', 'minn-admin' ),
			'route'    => 'minn-admin/v1/bricks/templates/{id}/export',
			'download' => true,
			'when'     => $not_trash,
		);
	}
	$actions[] = array(
		'label'   => __( 'Move to trash', 'minn-admin' ),
		'method'  => 'DELETE',
		'route'   => 'minn-admin/v1/bricks/templates/{id}',
		'confirm' => __( 'Move this template to the trash? Anywhere it is assigned stops using it.', 'minn-admin' ),
		'danger'  => true,
		'when'    => $not_trash,
	);
	$actions[] = array(
		'label'  => __( 'Restore', 'minn-admin' ),
		'method' => 'POST',
		'route'  => 'minn-admin/v1/bricks/templates/{id}/restore',
		'when'   => $is_trash,
	);
	$actions[] = array(
		'label'   => __( 'Delete permanently', 'minn-admin' ),
		'method'  => 'DELETE',
		'route'   => 'minn-admin/v1/bricks/templates/{id}',
		'confirm' => __( 'Delete this template permanently? There is no undo.', 'minn-admin' ),
		'danger'  => true,
		'when'    => $is_trash,
	);

	$surfaces['bricks-templates'] = array(
		'label'      => __( 'Templates', 'minn-admin' ),
		'sub'        => 'Bricks',
		'family'     => 'builder-templates',
		'icon'       => 'columns',
		// Their answer is a resolver, not a capability name; the guard above
		// is the real gate (the Solid Security / UpdraftPlus precedent).
		'cap'        => 'read',
		'settings'   => array(
			'cap'   => 'manage_options',
			'tabs'  => array(
				array( 'id' => 'general', 'label' => __( 'General', 'minn-admin' ) ),
				array( 'id' => 'templates', 'label' => __( 'Templates', 'minn-admin' ) ),
				array( 'id' => 'builder', 'label' => __( 'Builder', 'minn-admin' ) ),
				array( 'id' => 'maintenance', 'label' => __( 'Maintenance', 'minn-admin' ) ),
			),
			'route' => 'minn-admin/v1/bricks/settings/{tab}',
		),
		'collection' => array(
			'route'     => 'minn-admin/v1/bricks/templates',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'type',
				'static'   => $type_tabs,
				'allLabel' => __( 'All', 'minn-admin' ),
			),
			'search'    => 'search={q}',
			'sortQuery' => 'orderby={by}&order={dir}',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Template', 'minn-admin' ), 'format' => 'title', 'sort' => 'title' ),
				array( 'key' => 'typeLabel', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill', 'width' => '130px' ),
				array( 'key' => 'conditions', 'label' => __( 'Conditions', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'sort' => 'modified' ),
			),
			'create'    => minn_admin_bricks_can_create() ? array(
				'label'  => __( 'Add template', 'minn-admin' ),
				'route'  => 'minn-admin/v1/bricks/templates',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
				),
			) : null,
			'import'    => minn_admin_bricks_can_export() ? array(
				'label'  => __( 'Import', 'minn-admin' ),
				'route'  => 'minn-admin/v1/bricks/templates/import',
				'accept' => '.json,application/json',
				'hint'   => __( 'JSON exported from Bricks or from Minn. A zip of several files stays on Bricks\' own importer.', 'minn-admin' ),
			) : null,
			'detail'    => array(
				'skip' => array( 'id', 'type', 'typeLabel', 'editUrl', 'inTrash', 'status' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/bricks/templates/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
						array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
						// template_tag is not REST-exposed, so the Terms
						// manager never sees it — this is the one place tags
						// can be edited in Minn.
						array( 'key' => 'tags', 'label' => __( 'Tags', 'minn-admin' ), 'type' => 'tags', 'required' => false ),
					),
				),
			),
			'actions'   => $actions,
			'bulk'      => array(
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/bricks/templates/{id}',
					'confirm' => __( 'Move the selected templates to the trash?', 'minn-admin' ),
					'danger'  => true,
					'when'    => $not_trash,
				),
				array(
					'label'  => __( 'Restore', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/bricks/templates/{id}/restore',
					'when'   => $is_trash,
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/bricks/templates/{id}',
					'confirm' => __( 'Delete the selected templates permanently?', 'minn-admin' ),
					'danger'  => true,
					'when'    => $is_trash,
				),
			),
		),
	);
	if ( null === $surfaces['bricks-templates']['collection']['create'] ) {
		unset( $surfaces['bricks-templates']['collection']['create'] );
	}
	if ( empty( $surfaces['bricks-templates']['collection']['import'] ) ) {
		unset( $surfaces['bricks-templates']['collection']['import'] );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_bricks_active() ) {
		return;
	}
	$perm = function () {
		return minn_admin_bricks_can_view_templates();
	};

	register_rest_route( 'minn-admin/v1', '/bricks/templates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$type    = sanitize_key( (string) $request['type'] );
				$search  = trim( (string) $request['search'] );
				$page    = max( 1, (int) ( $request['page'] ?: 1 ) );
				$orderby = in_array( $request['orderby'], array( 'title', 'modified' ), true ) ? $request['orderby'] : 'modified';
				$order   = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
				$args    = array(
					'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					// Naming the unpublished statuses explicitly switches off
					// the scoping WP_Query would otherwise apply. 'readable'
					// does NOT restore it: core only author-scopes draft,
					// pending and future under 'editable' ('readable' scopes
					// private alone), so this needs the stricter value for
					// anyone who cannot read other people's posts. Bricks' own
					// list screen is scoped the same way.
					'perm'           => current_user_can( 'edit_others_posts' ) ? 'readable' : 'editable',
					'posts_per_page' => 25,
					'paged'          => $page,
					'orderby'        => $orderby,
					'order'          => $order,
				);
				if ( '' !== $search ) {
					$args['s'] = $search;
				}
				if ( 'trash' === $type ) {
					$args['post_status'] = 'trash';
				} elseif ( '' !== $type ) {
					$args['meta_query'] = array(
						array(
							'key'   => BRICKS_DB_TEMPLATE_TYPE,
							'value' => $type,
						),
					);
				}
				$query = new WP_Query( $args );
				return rest_ensure_response( array(
					'items' => array_map( 'minn_admin_bricks_template_item', $query->posts ),
					'total' => (int) $query->found_posts,
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_bricks_can_create();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$title = trim( (string) $request['title'] );
				$type  = sanitize_key( (string) $request['type'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A template needs a title.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( ! isset( minn_admin_bricks_template_types()[ $type ] ) ) {
					return new WP_Error( 'invalid', __( 'Pick a template type.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// Mirrors Bricks' own create_template: publish for publishers,
				// pending otherwise, type stored as postmeta after insert.
				$id = wp_insert_post( array(
					'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
					'post_title'  => esc_html( $title ),
					'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
				), true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				update_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, $type );
				if ( 'password_protection' === $type && class_exists( '\Bricks\Password_Protection' ) && method_exists( '\Bricks\Password_Protection', 'populate_template' ) ) {
					\Bricks\Password_Protection::populate_template( $id );
				}
				return rest_ensure_response( minn_admin_bricks_template_item( get_post( $id ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => function ( WP_REST_Request $request ) {
				// PUT was the last verb here with no Bricks-side check. It is
				// not a rename-only route: changing a template's type MOVES
				// the design tree between the content/header/footer meta keys,
				// so it can promote an arbitrary tree into the site header. A
				// site that took template editing away in their permission
				// matrix means that.
				return minn_admin_bricks_can_view_templates() && current_user_can( 'edit_post', (int) $request['id'] );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$title = trim( (string) $request['title'] );
				if ( '' !== $title && $title !== $post->post_title ) {
					$result = wp_update_post( array( 'ID' => $post->ID, 'post_title' => esc_html( $title ) ), true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				$type = sanitize_key( (string) $request['type'] );
				if ( '' !== $type ) {
					if ( ! isset( minn_admin_bricks_template_types()[ $type ] ) ) {
						return new WP_Error( 'invalid', __( 'Unknown template type.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$previous = (string) get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, true );
					update_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, $type );
					// A template's design is stored under one of three keys
					// chosen by its type, so changing the type without moving
					// the design leaves it behind and the template renders
					// empty everywhere it is used. Their own type control moves
					// it; so must this. Ask them which key each type reads,
					// rather than keeping a copy of that mapping here.
					if ( $previous && $previous !== $type && class_exists( '\Bricks\Database' )
						&& method_exists( '\Bricks\Database', 'get_bricks_data_key' ) ) {
						$from = \Bricks\Database::get_bricks_data_key( $previous );
						$to   = \Bricks\Database::get_bricks_data_key( $type );
						if ( $from && $to && $from !== $to ) {
							$tree = get_post_meta( $post->ID, $from, true );
							if ( ! empty( $tree ) ) {
								$stored = update_post_meta( $post->ID, $to, is_array( $tree ) ? wp_slash( $tree ) : $tree );
								// Only let go of the original once the copy is
								// safely in place.
								if ( $stored ) {
									delete_post_meta( $post->ID, $from );
								}
							}
						}
					}
				}
				// Tags ride as an array (an empty one clears); by NAME so new
				// tags create on the fly, like their tax_input create path.
				if ( is_array( $request['tags'] ) ) {
					$tags = array_values( array_filter( array_map( function ( $tag ) {
						return sanitize_text_field( (string) $tag );
					}, $request['tags'] ) ) );
					$set  = wp_set_object_terms( $post->ID, $tags, BRICKS_DB_TEMPLATE_TAX_TAG );
					if ( is_wp_error( $set ) ) {
						return $set;
					}
				}
				return rest_ensure_response( minn_admin_bricks_template_item( get_post( $post->ID ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function ( WP_REST_Request $request ) {
				// Trash was the one verb here with no Bricks-side check while
				// create, export and duplicate all route through their
				// resolver. A site that took template deletion away in their
				// permission matrix means it.
				return minn_admin_bricks_can_delete() && current_user_can( 'delete_post', (int) $request['id'] );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( 'trash' === $post->post_status ) {
					if ( ! wp_delete_post( $post->ID, true ) ) {
						return new WP_Error( 'failed', __( 'The template could not be deleted.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( array( 'deleted' => (int) $post->ID ) );
				}
				if ( ! wp_trash_post( $post->ID ) ) {
					return new WP_Error( 'failed', __( 'The template could not be trashed.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'trashed' => (int) $post->ID ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/import', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			// An import is a WRITE that lands site-wide markup, so it needs the
			// create permission too — can_export alone is the permission to
			// read a template out, not to put one in.
			return minn_admin_bricks_can_export() && minn_admin_bricks_can_create();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$result = minn_admin_bricks_import_templates( (string) $request['content'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)/restore', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_bricks_can_delete() && current_user_can( 'delete_post', (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			if ( 'trash' !== $post->post_status ) {
				return new WP_Error( 'not_trashed', __( 'That template is not in the trash.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! wp_untrash_post( $post->ID ) ) {
				return new WP_Error( 'failed', __( 'The template could not be restored.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( minn_admin_bricks_template_item( get_post( $post->ID ) ) );
		},
	) );

	// Export through Bricks' own exporter: called with an explicit id outside
	// ajax it returns { name, content } — the same payload their screen
	// downloads (element tree, settings, the global classes and variables the
	// template uses), so the file imports cleanly via Bricks' own importer.
	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)/export', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_bricks_can_export() && current_user_can( 'edit_post', (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$export = \Bricks\Templates::export_template( $post->ID );
			if ( ! is_array( $export ) || empty( $export['content'] ) ) {
				return new WP_Error( 'failed', __( 'Bricks could not export this template.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'filename' => (string) $export['name'],
				'content'  => (string) $export['content'],
				'mime'     => 'application/json',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_bricks_can_create() && current_user_can( 'edit_post', (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			/* translators: %s: the source template's title. */
			$copy_title = sprintf( __( '%s (copy)', 'minn-admin' ), $post->post_title );
			// Their own cloner is the only correct one. It starts the copy as a
			// draft, drops the rules saying where the template applies, and
			// gives every element a fresh id. Reimplementing it means
			// reimplementing all three and getting any of them wrong: a copy
			// that publishes itself inherits "applies to the entire website",
			// and a template holding a code element carries that code's
			// signature with it, because the signature is taken over the code
			// alone. Bricks runs such code on the public site, so a copy is
			// enough to put it there without ever being allowed to write it.
			// They also honour the site's own setting for whether duplication
			// is allowed at all.
			if ( class_exists( '\Bricks\Admin' ) && method_exists( '\Bricks\Admin', 'duplicate_content' ) ) {
				$new_id = \Bricks\Admin::duplicate_content( $post->ID );
				if ( ! $new_id ) {
					return new WP_Error(
						'duplicate_failed',
						__( 'Bricks declined to duplicate this template. Duplication may be turned off in its settings.', 'minn-admin' ),
						array( 'status' => 403 )
					);
				}
				wp_update_post( array( 'ID' => $new_id, 'post_title' => $copy_title ) );
				return rest_ensure_response( minn_admin_bricks_template_item( get_post( $new_id ) ) );
			}
			// A build without their cloner: a DRAFT copy, never a published
			// one, and the placement rules stay behind.
			$new_id = wp_insert_post( array(
				'post_status' => 'draft',
				'post_title'  => $copy_title,
				'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
			), true );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			// Everything Bricks knows about a template rides postmeta (type,
			// element tree, page/template settings) — copy it all except the
			// editor-session keys. Serialized values need the slash round-trip
			// or they double-serialize.
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					// Decoded without instantiating classes; see shared-meta.php.
					add_post_meta( $new_id, $key, wp_slash( minn_admin_meta_copy_value( $value ) ) );
				}
			}
			// Two templates claiming the same slot is their own reason for
			// stripping these from a copy.
			$settings = get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_SETTINGS, true );
			if ( is_array( $settings ) && isset( $settings['templateConditions'] ) ) {
				unset( $settings['templateConditions'] );
				update_post_meta( $new_id, BRICKS_DB_TEMPLATE_SETTINGS, wp_slash( $settings ) );
			}
			foreach ( array( BRICKS_DB_TEMPLATE_TAX_TAG, BRICKS_DB_TEMPLATE_TAX_BUNDLE ) as $tax ) {
				$terms = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
				if ( $terms && ! is_wp_error( $terms ) ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
			return rest_ensure_response( minn_admin_bricks_template_item( get_post( $new_id ) ) );
		},
	) );
} );

// Bricks generated-CSS regeneration joins "Clear site cache" (the Elementor
// CSS precedent). Assets_Files only autoloads while the cssLoading setting
// is 'file' (Assets' constructor gates the include), so the class check IS
// the external-files-mode check — inline-mode sites never see the provider.
add_filter( 'minn_admin_cache_purgers', function ( $purgers ) {
	if ( class_exists( '\Bricks\Assets_Files' ) && method_exists( '\Bricks\Assets_Files', 'regenerate_css_files' ) ) {
		$purgers[] = array(
			'id'    => 'bricks-css',
			'name'  => 'Bricks CSS files',
			// Synchronous is their own precedent: their post-update admin
			// notice runs the same call in-request.
			'purge' => function () {
				\Bricks\Assets_Files::regenerate_css_files();
			},
		);
	}
	return $purgers;
} );

/* ========================================================================
 * Bricks global settings — a curated subset on the Templates surface.
 *
 * Bricks' settings screen is one hand-rendered form with NO runtime schema
 * (unlike Perfmatters' Settings-API registry), so mapping all of it would be
 * a per-version treadmill. This maps the daily-ops subset and counts the
 * rest as locked with the wp-admin escape per tab. Storage semantics mirror
 * their save exactly: checkboxes store the literal 'on' and OFF means the
 * key is ABSENT (their save skips empty values); selects with a Disabled
 * option unset on ''. Writes are read-modify-write on the whitelisted keys
 * only — their own save replaces the whole option from the full form, which
 * Minn must never do from a partial one. The capability pickers, API keys
 * and custom-code estate are deliberately unmapped (the capability keys
 * route to role caps through their Capabilities class, not the option).
 * ======================================================================== */

/**
 * The curated schema: tab => groups of fields, each field carrying a `store`
 * spec the write path validates against ('toggle' | 'enum' | 'int' |
 * 'multicheck' | 'template'). Locked counts are the per-tab remainder of
 * the Bricks settings screen as of 2.3.12.
 *
 * @param string $tab Tab id.
 * @return array|null Groups array, null for an unknown tab.
 */
function minn_admin_bricks_settings_fields( $tab ) {
	if ( 'general' === $tab ) {
		$pt_options = array();
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_registered_post_types' ) ) {
			$types = \Bricks\Helpers::get_registered_post_types();
			unset( $types['attachment'] );
			foreach ( $types as $slug => $label ) {
				$pt_options[] = array( $slug, $label );
			}
		}
		return array(
			array(
				'title'  => __( 'Editing', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'postTypes', 'label' => __( 'Post types', 'minn-admin' ), 'type' => 'multicheck', 'options' => $pt_options, 'store' => 'multicheck', 'help' => __( 'Which post types can be edited with Bricks.', 'minn-admin' ) ),
				),
				'locked' => 12,
			),
			array(
				'title'  => __( 'Features', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'saveFormSubmissions', 'label' => __( 'Save form submissions', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle', 'help' => __( 'Store Bricks form submissions in the database. Turning this on adds a Forms surface to Minn.', 'minn-admin' ) ),
					array( 'key' => 'disableSeo', 'label' => __( 'Disable Bricks SEO controls', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle', 'help' => __( 'Right when a dedicated SEO plugin owns titles and metas.', 'minn-admin' ) ),
					array( 'key' => 'disableOpenGraph', 'label' => __( 'Disable Bricks Open Graph tags', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle' ),
				),
				'locked' => 18,
			),
		);
	}
	if ( 'templates' === $tab ) {
		return array(
			array(
				'title'  => __( 'Templates', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'defaultTemplatesDisabled', 'label' => __( 'Disable default templates', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle', 'help' => __( 'Without conditions, published header and footer templates apply everywhere. Turn on to require explicit conditions.', 'minn-admin' ) ),
					array( 'key' => 'publicTemplates', 'label' => __( 'Public templates', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle', 'help' => __( 'Whether template pages are viewable by anyone, or only logged-in users.', 'minn-admin' ) ),
					array( 'key' => 'myTemplatesAccess', 'label' => __( 'Remote template access', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle', 'help' => __( 'Allow other sites to browse and insert this site\'s templates. Anyone who knows the address can ask, so set a password or list the allowed sites in Bricks first.', 'minn-admin' ) ),
				),
				'locked' => 8,
			),
		);
	}
	if ( 'builder' === $tab ) {
		return array(
			array(
				'title'  => __( 'Autosave', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'builderAutosaveDisabled', 'label' => __( 'Disable autosave', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle' ),
					array( 'key' => 'builderAutosaveInterval', 'label' => __( 'Autosave interval (seconds)', 'minn-admin' ), 'type' => 'number', 'min' => 15, 'placeholder' => '60', 'store' => 'int', 'showWhen' => array( 'key' => 'builderAutosaveDisabled', 'equals' => false ), 'help' => __( 'Default 60, minimum 15.', 'minn-admin' ) ),
				),
			),
			array(
				'title'  => __( 'Appearance', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'builderMode', 'label' => __( 'Builder mode', 'minn-admin' ), 'type' => 'select', 'options' => array( array( 'dark', __( 'Dark', 'minn-admin' ) ), array( 'light', __( 'Light', 'minn-admin' ) ), array( 'custom', __( 'Custom', 'minn-admin' ) ) ), 'store' => 'enum', 'enum' => array( 'dark', 'light', 'custom' ), 'help' => __( 'Custom mode\'s CSS is edited on the Bricks settings screen.', 'minn-admin' ) ),
				),
				'locked' => 40,
			),
		);
	}
	if ( 'maintenance' === $tab ) {
		$template_options = array( array( '', __( 'Default', 'minn-admin' ) ) );
		$templates        = get_posts( array(
			'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_key'       => BRICKS_DB_TEMPLATE_TYPE,
			'meta_value'     => 'content',
		) );
		foreach ( $templates as $tpl ) {
			$template_options[] = array( (string) $tpl->ID, $tpl->post_title );
		}
		return array(
			array(
				'title'  => __( 'Maintenance mode', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'maintenanceMode', 'label' => __( 'Mode', 'minn-admin' ), 'type' => 'select', 'options' => array( array( '', __( 'Disabled', 'minn-admin' ) ), array( 'comingSoon', __( 'Coming soon', 'minn-admin' ) ), array( 'maintenance', __( 'Maintenance (503)', 'minn-admin' ) ) ), 'store' => 'enum', 'enum' => array( '', 'comingSoon', 'maintenance' ) ),
					array( 'key' => 'maintenanceTemplate', 'label' => __( 'Template', 'minn-admin' ), 'type' => 'select', 'options' => $template_options, 'store' => 'template', 'help' => __( 'A Single-type Bricks template to show; Default is Bricks\' built-in holding page.', 'minn-admin' ) ),
					array( 'key' => 'maintenanceRenderHeader', 'label' => __( 'Render header', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle' ),
					array( 'key' => 'maintenanceRenderFooter', 'label' => __( 'Render footer', 'minn-admin' ), 'type' => 'toggle', 'store' => 'toggle' ),
				),
				'locked' => 4,
			),
		);
	}
	return null;
}

/** Assemble one settings tab's GET payload (groups + current values). */
function minn_admin_bricks_settings_payload( $tab ) {
	$groups = minn_admin_bricks_settings_fields( $tab );
	if ( null === $groups ) {
		return new WP_Error( 'not_found', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$s      = get_option( 'bricks_global_settings' );
	$s      = is_array( $s ) ? $s : array();
	$values = array();
	$clean  = array();
	foreach ( $groups as $group ) {
		$fields = array();
		foreach ( $group['fields'] as $field ) {
			$key = $field['key'];
			switch ( $field['store'] ) {
				case 'toggle':
					$values[ $key ] = isset( $s[ $key ] );
					break;
				case 'multicheck':
					$values[ $key ] = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? array_values( $s[ $key ] ) : array();
					break;
				case 'int':
					$values[ $key ] = isset( $s[ $key ] ) ? (string) $s[ $key ] : '';
					break;
				default:
					$values[ $key ] = isset( $s[ $key ] ) ? (string) $s[ $key ] : ( 'builderMode' === $key ? 'dark' : '' );
			}
			unset( $field['store'], $field['enum'] );
			$fields[] = $field;
		}
		$group['fields'] = $fields;
		$clean[]         = $group;
	}
	$anchors = array( 'general' => 'general', 'templates' => 'templates', 'builder' => 'builder', 'maintenance' => 'maintenance' );
	return array(
		'groups'   => $clean,
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=bricks-settings#tab-' . $anchors[ $tab ] ),
	);
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_bricks_active() ) {
		return;
	}
	// Settings are the one thing this adapter does NOT route through Bricks'
	// permission model: see minn_admin_bricks_can_manage_settings().
	register_rest_route( 'minn-admin/v1', '/bricks/settings/(?P<tab>[a-z-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return minn_admin_bricks_can_manage_settings();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				return rest_ensure_response( minn_admin_bricks_settings_payload( (string) $request['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_bricks_can_manage_settings();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$tab    = (string) $request['tab'];
				$groups = minn_admin_bricks_settings_fields( $tab );
				if ( null === $groups ) {
					return new WP_Error( 'not_found', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$incoming = $request['values'];
				$incoming = is_array( $incoming ) ? $incoming : array();
				$specs    = array();
				foreach ( $groups as $group ) {
					foreach ( $group['fields'] as $field ) {
						$specs[ $field['key'] ] = $field;
					}
				}
				$s = get_option( 'bricks_global_settings' );
				$s = is_array( $s ) ? $s : array();
				$was_saving_submissions = isset( $s['saveFormSubmissions'] );
				// Only edited keys ride the save; each one validates against
				// its spec and lands (or unsets) in their storage shape.
				foreach ( $incoming as $key => $value ) {
					if ( ! isset( $specs[ $key ] ) ) {
						continue;
					}
					$spec = $specs[ $key ];
					switch ( $spec['store'] ) {
						case 'toggle':
							if ( ! empty( $value ) ) {
								// Remote template access is the only thing
								// standing in front of an endpoint that answers
								// anyone, and the two things that narrow it, a
								// password and a list of allowed sites, are
								// optional and live on their settings screen
								// beside the switch. Minn does not map either,
								// so turning this on here with neither set
								// would publish every template on the site to
								// anyone who asks for them.
								if ( 'myTemplatesAccess' === $key
									&& '' === trim( (string) ( $s['myTemplatesPassword'] ?? '' ) )
									&& empty( $s['myTemplatesWhitelist'] ) ) {
									return new WP_Error(
										'invalid',
										__( 'Set a remote templates password, or list the sites allowed to ask, in Bricks first. Without one of those this opens every template on this site to anyone.', 'minn-admin' ),
										array( 'status' => 400 )
									);
								}
								$s[ $key ] = 'on';
							} else {
								unset( $s[ $key ] );
							}
							break;
						case 'multicheck':
							$allowed = array_map( function ( $o ) {
								return $o[0];
							}, $spec['options'] );
							$picked  = array_values( array_intersect( array_map( 'strval', (array) $value ), $allowed ) );
							if ( $picked ) {
								$s[ $key ] = $picked;
							} else {
								unset( $s[ $key ] );
							}
							break;
						case 'enum':
							$value = (string) $value;
							if ( ! in_array( $value, $spec['enum'], true ) ) {
								return new WP_Error( 'invalid', __( 'That is not one of the offered choices.', 'minn-admin' ), array( 'status' => 400 ) );
							}
							if ( '' === $value ) {
								unset( $s[ $key ] );
							} else {
								$s[ $key ] = $value;
							}
							break;
						case 'int':
							if ( '' === trim( (string) $value ) ) {
								unset( $s[ $key ] );
								break;
							}
							$n = (int) $value;
							if ( isset( $spec['min'] ) && $n < $spec['min'] ) {
								/* translators: %d: the smallest allowed value. */
								return new WP_Error( 'invalid', sprintf( __( 'The smallest allowed value is %d.', 'minn-admin' ), $spec['min'] ), array( 'status' => 400 ) );
							}
							$s[ $key ] = (string) $n;
							break;
						case 'template':
							$value = (string) $value;
							if ( '' === $value ) {
								unset( $s[ $key ] );
								break;
							}
							$tpl = get_post( (int) $value );
							if ( ! $tpl || BRICKS_DB_TEMPLATE_SLUG !== $tpl->post_type || 'content' !== get_post_meta( $tpl->ID, BRICKS_DB_TEMPLATE_TYPE, true ) ) {
								return new WP_Error( 'invalid', __( 'Pick a Single-type Bricks template.', 'minn-admin' ), array( 'status' => 400 ) );
							}
							$s[ $key ] = (string) $tpl->ID;
							break;
					}
				}
				update_option( 'bricks_global_settings', $s );
				// Their own save creates the submissions table the moment the
				// feature turns on; without it the first submission fatals.
				if ( ! $was_saving_submissions && isset( $s['saveFormSubmissions'] )
					&& class_exists( '\Bricks\Integrations\Form\Submission_Database' ) ) {
					\Bricks\Integrations\Form\Submission_Database::maybe_create_table();
				}
				return rest_ensure_response( minn_admin_bricks_settings_payload( $tab ) );
			},
		),
	) );
} );

/* ========================================================================
 * Bricks form submissions — the forms-family surface.
 *
 * Rows live in {prefix}bricks_form_submissions (only while the
 * saveFormSubmissions setting is on; their save creates the table).
 * form_data is one JSON map of { field_id: { type, value } }; labels
 * resolve through Submission_Database::get_form_settings() against the
 * form element stored in the source post. created_at is UTC
 * (current_time('mysql', true)). Reading gates on Bricks' own resolved
 * access flag (Capabilities::$form_submission_access — user cap, then the
 * explicit _off cap, then role, then the administrator default; set on
 * init, so it is resolved by REST time). Deleting gates on manage_options,
 * exactly like their delete_data(); their own screen offers viewers no
 * read/favorite verbs either, so neither does this one.
 * ======================================================================== */

function minn_admin_bricks_forms_ready() {
	if ( ! minn_admin_bricks_active() || ! class_exists( '\Bricks\Integrations\Form\Submission_Database' ) ) {
		return false;
	}
	$s = get_option( 'bricks_global_settings' );
	if ( ! is_array( $s ) || ! isset( $s['saveFormSubmissions'] ) ) {
		return false;
	}
	global $wpdb;
	$table = \Bricks\Integrations\Form\Submission_Database::get_table_name();
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

function minn_admin_bricks_forms_can_view() {
	// Their accessor resolves the answer on demand; the raw property is only
	// filled in by their own start-up, so reading it directly bets on load
	// order for an authorization answer. Its default is false, so a cold read
	// says no to everyone, administrators included.
	if ( class_exists( '\Bricks\Capabilities' )
		&& method_exists( '\Bricks\Capabilities', 'current_user_can_form_submission_access' ) ) {
		try {
			return (bool) \Bricks\Capabilities::current_user_can_form_submission_access();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the property below.
		}
	}
	return class_exists( '\Bricks\Capabilities' ) && ! empty( \Bricks\Capabilities::$form_submission_access );
}

/** Field-id => label map for one form, through their own settings resolver. */
function minn_admin_bricks_form_labels( $post_id, $form_id ) {
	static $cache = array();
	$key = $post_id . ':' . $form_id;
	if ( ! isset( $cache[ $key ] ) ) {
		$labels   = array();
		$settings = \Bricks\Integrations\Form\Submission_Database::get_form_settings( $post_id, $form_id );
		foreach ( (array) ( $settings['fields'] ?? array() ) as $field ) {
			if ( isset( $field['id'] ) ) {
				$labels[ (string) $field['id'] ] = ! empty( $field['label'] ) ? (string) $field['label'] : (string) ( $field['type'] ?? $field['id'] );
			}
		}
		$cache[ $key ] = array(
			'labels' => $labels,
			'name'   => (string) ( $settings['submissionFormName'] ?? '' ),
		);
	}
	return $cache[ $key ];
}

/** Human name for a form: their submission name, else the source page's title. */
function minn_admin_bricks_form_title( $post_id, $form_id ) {
	$info = minn_admin_bricks_form_labels( $post_id, $form_id );
	if ( '' !== $info['name'] ) {
		return $info['name'];
	}
	$page = $post_id ? get_the_title( $post_id ) : '';
	return '' !== $page ? $page : $form_id;
}

/** One field value flattened to display text (arrays joined, files by name). */
function minn_admin_bricks_field_text( $field ) {
	$value = $field['value'] ?? '';
	$type  = $field['type'] ?? '';
	if ( is_array( $value ) ) {
		if ( 'file' === $type ) {
			$names = array();
			foreach ( $value as $file ) {
				if ( is_array( $file ) && isset( $file['name'] ) ) {
					$names[] = (string) $file['name'];
				}
			}
			return implode( ', ', $names );
		}
		return implode( ', ', array_map( 'strval', $value ) );
	}
	return (string) $value;
}

/** Normalize a submissions row into a list item. */
function minn_admin_bricks_entry_item( $row ) {
	$data  = json_decode( (string) $row->form_data, true );
	$parts = array();
	foreach ( (array) $data as $field ) {
		$text = is_array( $field ) ? minn_admin_bricks_field_text( $field ) : '';
		if ( '' !== trim( $text ) ) {
			$parts[] = $text;
		}
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	$summary = implode( ' · ', $parts );
	if ( function_exists( 'mb_substr' ) && mb_strlen( $summary ) > 110 ) {
		$summary = mb_substr( $summary, 0, 109 ) . '…';
	}
	return array(
		'id'      => (int) $row->id,
		'summary' => '' !== $summary ? $summary : __( '(empty submission)', 'minn-admin' ),
		'form'    => minn_admin_bricks_form_title( (int) $row->post_id, (string) $row->form_id ),
		'form_id' => (string) $row->form_id,
		'when'    => (string) $row->created_at,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_bricks_forms_ready() || ! minn_admin_bricks_forms_can_view() ) {
		return $surfaces;
	}
	$actions = array(
		array(
			'label' => __( 'Open in Bricks ↗', 'minn-admin' ),
			'href'  => admin_url( 'admin.php?page=bricks-form-submissions&view=form_entries&form_id={form_id}' ),
		),
	);
	$bulk    = array();
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $actions, array(
			'label'   => __( 'Delete entry', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/bricks/entries/{id}',
			'confirm' => __( 'Delete this submission permanently? Bricks keeps no trash for them.', 'minn-admin' ),
			'danger'  => true,
		) );
		$bulk[] = array(
			'label'   => __( 'Delete', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/bricks/entries/{id}',
			'confirm' => __( 'Delete the selected submissions permanently? Bricks keeps no trash for them.', 'minn-admin' ),
			'danger'  => true,
		);
	}
	$surfaces['bricks-forms'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace',
		'sub'        => 'Bricks',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/bricks/forms-status' ),
		'collection' => array(
			'viewLabel' => __( 'Submissions', 'minn-admin' ),
			'route'     => 'minn-admin/v1/bricks/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/bricks/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => __( 'All submissions', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Submission', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form', 'label' => __( 'Form', 'minn-admin' ) ),
				array( 'key' => 'when', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/bricks/entries/{id}',
			),
			'actions'   => $actions,
		),
	);
	if ( $bulk ) {
		$surfaces['bricks-forms']['collection']['bulk'] = $bulk;
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_bricks_forms_ready() ) {
		return;
	}
	$view_perm = function () {
		return minn_admin_bricks_forms_can_view();
	};
	$table = \Bricks\Integrations\Form\Submission_Database::get_table_name();

	// The forms tab list: entries grouped by form_id (their overview query).
	register_rest_route( 'minn-admin/v1', '/bricks/forms', array(
		'methods'             => 'GET',
		'permission_callback' => $view_perm,
		'callback'            => function () use ( $table ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL -- table name is prefix-built
			$rows  = $wpdb->get_results( "SELECT form_id, MAX(post_id) AS post_id, COUNT(*) AS entries FROM {$table} GROUP BY form_id ORDER BY MAX(id) DESC LIMIT 50" );
			$items = array();
			foreach ( (array) $rows as $row ) {
				$items[] = array(
					'id'      => (string) $row->form_id,
					'title'   => minn_admin_bricks_form_title( (int) $row->post_id, (string) $row->form_id ),
					'entries' => (int) $row->entries,
				);
			}
			return rest_ensure_response( $items );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/forms-status', array(
		'methods'             => 'GET',
		'permission_callback' => $view_perm,
		'callback'            => function () use ( $table ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.PreparedSQL -- table name is prefix-built
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$forms = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT form_id) FROM {$table}" );
			$last  = $wpdb->get_var( "SELECT created_at FROM {$table} ORDER BY id DESC LIMIT 1" );
			// phpcs:enable
			$rows = array(
				array( 'label' => __( 'Submissions', 'minn-admin' ), 'value' => number_format_i18n( $total ) ),
				array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => (string) $forms ),
			);
			if ( $last ) {
				$rows[] = array(
					'label' => __( 'Last submission', 'minn-admin' ),
					// created_at is UTC; show it in the site's timezone.
					'value' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( get_date_from_gmt( $last ) ) ),
				);
			}
			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => array(
					array( 'label' => __( 'Open Bricks form submissions ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=bricks-form-submissions' ) ),
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/entries', array(
		'methods'             => 'GET',
		'permission_callback' => $view_perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$form_id  = trim( (string) $request['form_id'] );
			$search   = trim( (string) $request['search'] );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$where    = '1=1';
			$args     = array();
			if ( '' !== $form_id ) {
				$where .= ' AND form_id = %s';
				$args[] = $form_id;
			}
			if ( '' !== $search ) {
				// Their screen searches the raw form_data JSON the same way.
				$where .= ' AND form_data LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			// phpcs:disable WordPress.DB.PreparedSQL -- table name is prefix-built, where prepared above
			$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			return rest_ensure_response( array(
				'items' => array_map( 'minn_admin_bricks_entry_item', (array) $rows ),
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $view_perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.PreparedSQL -- table name is prefix-built
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$info     = minn_admin_bricks_form_labels( (int) $row->post_id, (string) $row->form_id );
				$data     = json_decode( (string) $row->form_data, true );
				$response = array();
				foreach ( (array) $data as $field_id => $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$label = $info['labels'][ (string) $field_id ] ?? (string) $field_id;
					$type  = (string) ( $field['type'] ?? '' );
					$field_row = array(
						'label' => $label,
						'value' => minn_admin_bricks_field_text( $field ),
					);
					if ( 'email' === $type ) {
						$field_row['type'] = 'email';
					}
					$response[] = $field_row;
				}
				$meta   = array();
				$meta[] = array(
					'label' => __( 'Date', 'minn-admin' ),
					'value' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( get_date_from_gmt( (string) $row->created_at ) ) ),
				);
				$meta[] = array( 'label' => __( 'Form', 'minn-admin' ), 'value' => minn_admin_bricks_form_title( (int) $row->post_id, (string) $row->form_id ) . ' (' . (string) $row->form_id . ')' );
				if ( $row->post_id && get_post( (int) $row->post_id ) ) {
					$meta[] = array( 'label' => __( 'Page', 'minn-admin' ), 'value' => get_permalink( (int) $row->post_id ), 'type' => 'url' );
				}
				foreach ( array( 'browser' => __( 'Browser', 'minn-admin' ), 'os' => __( 'System', 'minn-admin' ), 'ip' => __( 'IP address', 'minn-admin' ), 'referrer' => __( 'Referrer', 'minn-admin' ) ) as $col => $label ) {
					if ( ! empty( $row->{$col} ) ) {
						$meta[] = array( 'label' => $label, 'value' => (string) $row->{$col} );
					}
				}
				if ( ! empty( $row->user_id ) ) {
					$user   = get_user_by( 'id', (int) $row->user_id );
					$meta[] = array( 'label' => __( 'User', 'minn-admin' ), 'value' => $user ? $user->display_name : ( '#' . (int) $row->user_id ) );
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'sections' => array(
						array( 'title' => __( 'Response', 'minn-admin' ), 'rows' => $response ),
						array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=bricks-form-submissions&view=form_entries&form_id=' . rawurlencode( (string) $row->form_id ) ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				// Their delete_data gate: viewing is submission access, but
				// removal is manage_options only.
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$id = (int) $request['id'];
				// phpcs:ignore WordPress.DB.PreparedSQL -- table name is prefix-built
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
				if ( ! $exists ) {
					return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$deleted = \Bricks\Integrations\Form\Submission_Database::delete_data( $id );
				if ( ! $deleted ) {
					return new WP_Error( 'failed', __( 'The submission could not be deleted.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'deleted' => $id ) );
			},
		),
	) );
} );
