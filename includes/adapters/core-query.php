<?php
/**
 * Query Loop (core/query) — insert + configure support for the core block.
 *
 * Core's Query Loop is the one core block the editor could neither add nor
 * configure: it is dynamic, but a bare self-closing comment renders nothing
 * (the post list comes from its inner post-template), so the auto-insert
 * render probe rightly excludes it — and its whole configuration lives in
 * one `query` object attribute, which the generic inspector form rightly
 * skips as too structural. Both gaps close with a bundled descriptor:
 * an `insert.template` carrying Gutenberg's canonical title-and-date shape
 * (pagination + no-results included, so the saved markup is exactly what
 * the block editor itself produces), and a `dataForm` over the `query`
 * object for the daily controls. Layout, taxonomy filters and the inner
 * template stay the block editor's job — the island's escape hatch is one
 * click away, and the `locked` count says so honestly.
 *
 * Types matter here: the form engine writes real JSON types (number
 * controls store numbers, checkboxes 1/0), which core's
 * build_query_vars_from_query_block and Gutenberg's own controls both
 * read correctly; unknown keys already in the object (taxQuery, author,
 * parents…) are preserved untouched by the collect path.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_block_forms', function ( $forms ) {
	// The post types Gutenberg's own Post Type control offers: viewable
	// and REST-exposed. Built at request time so CPTs registered by other
	// plugins are always current.
	$type_options = array();
	foreach ( get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'objects' ) as $pt ) {
		if ( 'attachment' === $pt->name ) {
			continue;
		}
		$type_options[] = array( $pt->name, (string) $pt->labels->name );
	}

	$template = '<!-- wp:query {"query":{"perPage":5,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->'
		. "\n" . '<div class="wp-block-query"><!-- wp:post-template -->'
		. "\n" . '<!-- wp:post-title {"isLink":true} /-->'
		. "\n" . '<!-- wp:post-date /-->'
		. "\n" . '<!-- /wp:post-template -->'
		. "\n\n" . '<!-- wp:query-pagination -->'
		. "\n" . '<!-- wp:query-pagination-previous /-->'
		. "\n" . '<!-- wp:query-pagination-numbers /-->'
		. "\n" . '<!-- wp:query-pagination-next /-->'
		. "\n" . '<!-- /wp:query-pagination -->'
		. "\n\n" . '<!-- wp:query-no-results -->'
		. "\n" . '<!-- wp:paragraph -->'
		. "\n" . '<p>' . esc_html__( 'No posts found.', 'minn-admin' ) . '</p>'
		. "\n" . '<!-- /wp:paragraph -->'
		. "\n" . '<!-- /wp:query-no-results --></div>'
		. "\n" . '<!-- /wp:query -->';

	$forms['core/query'] = array(
		'insert'     => array(
			'label'    => __( 'Query Loop', 'minn-admin' ),
			'template' => $template,
		),
		'attributes' => array(
			// Wrapper plumbing the form should not offer: queryId is
			// assigned by the block editor, tagName/namespace are markup
			// concerns, and the query object itself is the dataForm's.
			'queryId'            => array( 'hide' => true ),
			'query'              => array( 'hide' => true ),
			'tagName'            => array( 'hide' => true ),
			'namespace'          => array( 'hide' => true ),
			'displayLayout'      => array( 'hide' => true ),
			'enhancedPagination' => array( 'label' => __( 'Instant pagination (no page reload)', 'minn-admin' ) ),
		),
		'dataForm'   => array(
			'attr'   => 'query',
			'fields' => array(
				array(
					'name'    => 'postType',
					'label'   => __( 'Post type', 'minn-admin' ),
					'control' => 'select',
					'options' => $type_options,
				),
				array( 'name' => 'perPage', 'label' => __( 'Items per page', 'minn-admin' ), 'control' => 'number' ),
				array(
					'name'    => 'orderBy',
					'label'   => __( 'Order by', 'minn-admin' ),
					'control' => 'select',
					'options' => array(
						array( 'date', __( 'Date', 'minn-admin' ) ),
						array( 'title', __( 'Title', 'minn-admin' ) ),
						array( 'modified', __( 'Last modified', 'minn-admin' ) ),
						array( 'rand', __( 'Random', 'minn-admin' ) ),
					),
				),
				array(
					'name'    => 'order',
					'label'   => __( 'Order', 'minn-admin' ),
					'control' => 'select',
					'options' => array(
						array( 'desc', __( 'Descending', 'minn-admin' ) ),
						array( 'asc', __( 'Ascending', 'minn-admin' ) ),
					),
				),
				array(
					'name'    => 'sticky',
					'label'   => __( 'Sticky posts', 'minn-admin' ),
					'control' => 'select',
					'options' => array(
						array( '', __( 'Include', 'minn-admin' ) ),
						array( 'only', __( 'Only sticky', 'minn-admin' ) ),
						array( 'exclude', __( 'Exclude sticky', 'minn-admin' ) ),
					),
				),
				array( 'name' => 'search', 'label' => __( 'Search term', 'minn-admin' ), 'control' => 'text' ),
				array( 'name' => 'offset', 'label' => __( 'Skip first N items', 'minn-admin' ), 'control' => 'number' ),
				array( 'name' => 'inherit', 'label' => __( 'Inherit the page\'s default query', 'minn-admin' ), 'control' => 'checkbox' ),
			),
			// Taxonomy and author filters, and the loop's inner layout, are
			// the block editor's job.
			'locked' => 2,
		),
	);
	return $forms;
} );
