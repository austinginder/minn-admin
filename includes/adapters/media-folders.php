<?php
/**
 * Media folders — the provider contract plus the bundled FileBird provider.
 *
 * Browse-first by design: a folder combobox on the existing Media view, fed
 * by whichever folder plugin the site runs. The provider hands Minn its
 * folder list and, per folder, the attachment ids; Minn then filters the
 * normal wp/v2/media query with include= so search, type tabs, Unattached
 * and pagination all keep working. Minn NEVER owns a folder tree of its own
 * (a fifth folder standard invisible to wp-admin and builder pickers), and
 * moving files stays in the plugin's UI for now.
 *
 * Contract (first non-null wins, like minn_admin_traffic):
 *
 *   add_filter( 'minn_admin_media_folders', function ( $provider ) {
 *       if ( null !== $provider ) return $provider;   // someone else answered
 *       return array(
 *           'name'    => 'My Folders',
 *           'folders' => function () {
 *               // Flat list; parent 0 = root. id 0 is reserved for an
 *               // optional "Uncategorized" row (files in no folder) and is
 *               // never treated as a parent. count is optional.
 *               return array( array( 'id' => 12, 'label' => 'Logos', 'parent' => 0, 'count' => 8 ) );
 *           },
 *           'ids'     => function ( $folder_id ) {
 *               // Attachment ids in this folder, or WP_Error. Minn orders
 *               // by date and caps at 500 (newest first) before querying.
 *               return array( 101, 102 );
 *           },
 *       );
 *   } );
 */

defined( 'ABSPATH' ) || exit;

/**
 * The active provider descriptor, shape-validated, or null.
 */
function minn_admin_media_folders_provider() {
	$p = apply_filters( 'minn_admin_media_folders', null );
	if ( ! is_array( $p ) || empty( $p['name'] )
		|| empty( $p['folders'] ) || ! is_callable( $p['folders'] )
		|| empty( $p['ids'] ) || ! is_callable( $p['ids'] ) ) {
		return null;
	}
	return $p;
}

/**
 * Boot payload: { name } when a provider is active and the user can browse
 * media, else null (gates the folder combobox client-side).
 */
function minn_admin_media_folders_boot() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return null;
	}
	$p = minn_admin_media_folders_provider();
	return $p ? array( 'name' => (string) $p['name'] ) : null;
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_media_folders_provider() ) {
		return;
	}

	// The folder list, tree-ordered with depth for combobox indentation.
	register_rest_route(
		'minn-admin/v1',
		'/media/folders',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'callback'            => function () {
				$p = minn_admin_media_folders_provider();
				try {
					$raw = call_user_func( $p['folders'] );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'minn_folders_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				$rows = array();
				foreach ( (array) $raw as $f ) {
					if ( ! is_array( $f ) || ! isset( $f['id'] ) || ! isset( $f['label'] ) ) {
						continue;
					}
					$rows[] = array(
						'id'     => (int) $f['id'],
						'label'  => (string) $f['label'],
						'parent' => isset( $f['parent'] ) ? (int) $f['parent'] : 0,
						'count'  => isset( $f['count'] ) && null !== $f['count'] ? (int) $f['count'] : null,
					);
				}
				// Depth-first walk from the roots, provider order preserved
				// (the terms-manager tree convention; orphans surface at root).
				// id 0 is the reserved Uncategorized row: emitted first, never
				// a parent.
				$special = array_values( array_filter( $rows, function ( $r ) {
					return 0 === $r['id'];
				} ) );
				$rows    = array_values( array_filter( $rows, function ( $r ) {
					return 0 !== $r['id'];
				} ) );
				$by_parent = array();
				foreach ( $rows as $r ) {
					$by_parent[ $r['parent'] ][] = $r;
				}
				$seen = array();
				$out  = array();
				$walk = function ( $parent, $depth ) use ( &$walk, &$by_parent, &$seen, &$out ) {
					if ( $depth > 12 || empty( $by_parent[ $parent ] ) ) {
						return;
					}
					foreach ( $by_parent[ $parent ] as $r ) {
						if ( isset( $seen[ $r['id'] ] ) ) {
							continue;
						}
						$seen[ $r['id'] ] = true;
						$r['depth']       = $depth;
						unset( $r['parent'] );
						$out[] = $r;
						$walk( $r['id'], $depth + 1 );
					}
				};
				$walk( 0, 0 );
				foreach ( $rows as $r ) { // orphans (parent id missing)
					if ( ! isset( $seen[ $r['id'] ] ) ) {
						$r['depth'] = 0;
						unset( $r['parent'] );
						$out[] = $r;
					}
				}
				foreach ( $special as $s ) {
					$s['depth'] = 0;
					unset( $s['parent'] );
					array_unshift( $out, $s );
				}
				return rest_ensure_response( array(
					'name'    => (string) $p['name'],
					'folders' => array_slice( $out, 0, 500 ),
				) );
			},
		)
	);

	// A folder's attachment ids, date-ordered newest-first and capped at 500
	// so the include= URL stays sane. capped=true says the folder holds more.
	register_rest_route(
		'minn-admin/v1',
		'/media/folders/(?P<id>\d+)/ids',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'callback'            => function ( $req ) {
				global $wpdb;
				$p = minn_admin_media_folders_provider();
				try {
					$ids = call_user_func( $p['ids'], (int) $req['id'] );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'minn_folder_ids_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
				if ( ! $ids ) {
					return rest_ensure_response( array( 'ids' => array(), 'capped' => false ) );
				}
				$in      = implode( ',', $ids );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast list built above
				$ordered = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) AND post_type = 'attachment' AND post_status != 'trash' ORDER BY post_date DESC LIMIT 501"
				);
				$capped  = count( $ordered ) > 500;
				return rest_ensure_response( array(
					'ids'    => array_map( 'intval', array_slice( $ordered, 0, 500 ) ),
					'capped' => $capped,
				) );
			},
		)
	);
} );

/**
 * Bundled provider: FileBird (6.x, custom fbv tables). Everything goes
 * through FileBird's own model so its per-user folder mode (the
 * fbv_folder_created_by filter), counter-type setting and exclusions all
 * apply exactly as on its own screens.
 */
add_filter( 'minn_admin_media_folders', function ( $provider ) {
	if ( null !== $provider || ! defined( 'NJFB_VERSION' ) || ! class_exists( '\FileBird\Model\Folder' ) ) {
		return $provider;
	}
	return array(
		'name'    => 'FileBird',
		'folders' => function () {
			$rows   = \FileBird\Model\Folder::allFolders( 'id, name, parent' );
			$counts = \FileBird\Model\Folder::countAttachments();
			$counts = isset( $counts['display'] ) ? (array) $counts['display'] : array();
			// FileBird's own Uncategorized (files in none of this scope's
			// folders); it computes no count for it and neither do we.
			$out = array( array( 'id' => 0, 'label' => 'Uncategorized', 'parent' => 0, 'count' => null ) );
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'id'     => (int) $r->id,
					'label'  => (string) $r->name,
					'parent' => (int) $r->parent,
					'count'  => isset( $counts[ $r->id ] ) ? (int) $counts[ $r->id ] : 0,
				);
			}
			return $out;
		},
		'ids'     => function ( $folder_id ) {
			global $wpdb;
			if ( 0 === (int) $folder_id ) {
				// Mirror FileBird's getRelationsWithFolderUser clause: an
				// attachment is uncategorized when no folder of the current
				// scope (0, or the user in per-user mode) claims it.
				$scope = (int) apply_filters( 'fbv_folder_created_by', 0 );
				return $wpdb->get_col( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 WHERE p.post_type = 'attachment' AND p.ID NOT IN (
						SELECT fbva.attachment_id FROM {$wpdb->prefix}fbv_attachment_folder fbva
						INNER JOIN {$wpdb->prefix}fbv fbv ON fbva.folder_id = fbv.id AND fbv.created_by = %d
					 )",
					$scope
				) );
			}
			$per_user = get_option( 'njt_fbv_folder_per_user', '0' ) === '1';
			if ( ! \FileBird\Model\Folder::verifyAuthor( (int) $folder_id, get_current_user_id(), $per_user ) ) {
				return new WP_Error( 'minn_folder_denied', 'That folder belongs to another user.', array( 'status' => 403 ) );
			}
			return \FileBird\Classes\Helpers::getAttachmentIdsByFolderId( (int) $folder_id );
		},
	);
} );

/**
 * Bundled provider: Real Media Library Lite (custom realmedialibrary tables,
 * public wp_rml_* API). RML's root (id -1, "Unorganized") maps onto the
 * contract's reserved id 0; everything reads through their API so counts and
 * ordering match their own screens. Lite has no per-user folders.
 */
add_filter( 'minn_admin_media_folders', function ( $provider ) {
	if ( null !== $provider || ! function_exists( 'wp_rml_objects' ) || ! function_exists( 'wp_rml_get_attachments' ) ) {
		return $provider;
	}
	return array(
		'name'    => 'Real Media Library',
		'folders' => function () {
			// RML calls its no-folder root "Unorganized" — the reserved id 0.
			$out = array( array( 'id' => 0, 'label' => 'Unorganized', 'parent' => 0, 'count' => null ) );
			foreach ( (array) wp_rml_objects() as $f ) {
				if ( ! is_object( $f ) || ! method_exists( $f, 'getId' ) ) {
					continue;
				}
				$parent = (int) $f->getParent();
				$out[]  = array(
					'id'     => (int) $f->getId(),
					'label'  => (string) $f->getName(),
					'parent' => $parent > 0 ? $parent : 0, // RML root is -1
					'count'  => method_exists( $f, 'getCnt' ) ? (int) $f->getCnt() : null,
				);
			}
			return $out;
		},
		'ids'     => function ( $folder_id ) {
			// 0 = the contract's Uncategorized = RML's root (-1, Unorganized).
			$ids = wp_rml_get_attachments( 0 === (int) $folder_id ? -1 : (int) $folder_id );
			return null === $ids
				? new WP_Error( 'minn_folder_missing', 'That folder no longer exists.', array( 'status' => 404 ) )
				: $ids;
		},
	);
} );

/**
 * Bundled provider: Folders by Premio (plain WordPress taxonomy
 * `media_folder`, registered only while "attachment" is enabled on their
 * settings). Terms are the folders; their own admin view includes child
 * folders when filtering, so the ids query does too.
 */
add_filter( 'minn_admin_media_folders', function ( $provider ) {
	if ( null !== $provider || ! defined( 'WCP_FOLDER_VERSION' ) || ! taxonomy_exists( 'media_folder' ) ) {
		return $provider;
	}
	return array(
		'name'    => 'Folders',
		'folders' => function () {
			$terms = get_terms( array( 'taxonomy' => 'media_folder', 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				return array();
			}
			// Their sidebar's "Unassigned" view (media_folder = -1) = id 0.
			$out = array( array( 'id' => 0, 'label' => 'Unassigned', 'parent' => 0, 'count' => null ) );
			foreach ( $terms as $t ) {
				$out[] = array(
					'id'     => (int) $t->term_id,
					'label'  => (string) $t->name,
					'parent' => (int) $t->parent,
					'count'  => (int) $t->count,
				);
			}
			return $out;
		},
		'ids'     => function ( $folder_id ) {
			global $wpdb;
			if ( 0 === (int) $folder_id ) {
				// Their "Unassigned": attachments carrying no media_folder term.
				return $wpdb->get_col(
					"SELECT p.ID FROM {$wpdb->posts} p
					 WHERE p.post_type = 'attachment' AND p.ID NOT IN (
						SELECT tr.object_id FROM {$wpdb->term_relationships} tr
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
						WHERE tt.taxonomy = 'media_folder'
					 )"
				);
			}
			if ( ! term_exists( (int) $folder_id, 'media_folder' ) ) {
				return new WP_Error( 'minn_folder_missing', 'That folder no longer exists.', array( 'status' => 404 ) );
			}
			return get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit,private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( array(
					'taxonomy'         => 'media_folder',
					'field'            => 'term_id',
					'terms'            => (int) $folder_id,
					'include_children' => true, // matches their admin filter
				) ),
			) );
		},
	);
} );
