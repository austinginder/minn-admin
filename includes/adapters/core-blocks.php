<?php
/**
 * Core blocks the editor could not otherwise add — bundled descriptors.
 *
 * Some core blocks are dynamic but render NOTHING from a bare self-closing
 * comment (their content comes from inner blocks), so the auto-insert
 * render probe rightly excludes them, and Minn's curated basics never
 * covered them. Each gets an `insert.template` carrying markup the block
 * editor itself produces (harvested from Gutenberg's own serializer, so it
 * validates clean there), and where the block's configuration lives in one
 * object attribute, a `dataForm` for the daily controls:
 *
 * - core/query — Gutenberg's canonical title-and-date loop (pagination +
 *   no-results) plus a dataForm over the `query` object. Layout, taxonomy
 *   filters and the inner template stay the block editor's job; the
 *   `locked` count says so honestly.
 * - core/tabs — two starter tabs (labels are tab-panel attrs, so the ⚙
 *   inspector edits them; the tab list itself renders server-side).
 * - core/accordion — two starter items (headings live in saved HTML, so
 *   island text runs edit them in place).
 *
 * The widget-shaped core blocks that DO render bare (Latest Posts,
 * Archives, Calendar…) need no descriptor: they ride the auto-insert
 * allowlist in Minn_Admin::insertable_blocks().
 *
 * Types matter in the query dataForm: the form engine writes real JSON
 * types (number controls store numbers, a key already holding a boolean
 * stays boolean), which core's build_query_vars_from_query_block and
 * Gutenberg's own controls both read correctly; unknown keys already in
 * the object (taxQuery, author, parents…) are preserved untouched.
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
				array( 'name' => 'inherit', 'label' => __( 'Inherit the page\'s default query', 'minn-admin' ), 'control' => 'toggle' ),
			),
			// Taxonomy and author filters, and the loop's inner layout, are
			// the block editor's job.
			'locked' => 2,
		),
	);

	$tab_panel = function ( $label, $text ) {
		// The label rides comment-attr JSON: encode it, never splice it (a
		// translation carrying a quote would corrupt the comment).
		return '<!-- wp:tab-panel {"label":' . wp_json_encode( $label ) . '} -->'
			. "\n" . '<section role="tabpanel" tabindex="0" class="wp-block-tab-panel"><!-- wp:paragraph -->'
			. "\n" . '<p>' . $text . '</p>'
			. "\n" . '<!-- /wp:paragraph --></section>'
			. "\n" . '<!-- /wp:tab-panel -->';
	};
	$forms['core/tabs'] = array(
		'insert'     => array(
			'label'    => __( 'Tabs', 'minn-admin' ),
			'template' => '<!-- wp:tabs -->'
				. "\n" . '<div class="wp-block-tabs"><!-- wp:tab-list -->'
				. "\n" . '<div role="tablist" class="wp-block-tab-list"></div>'
				. "\n" . '<!-- /wp:tab-list -->'
				. "\n\n" . '<!-- wp:tab-panels -->'
				. "\n" . '<div class="wp-block-tab-panels">'
				. $tab_panel( esc_html__( 'Tab 1', 'minn-admin' ), esc_html__( 'First tab content.', 'minn-admin' ) )
				. "\n\n" . $tab_panel( esc_html__( 'Tab 2', 'minn-admin' ), esc_html__( 'Second tab content.', 'minn-admin' ) ) . '</div>'
				. "\n" . '<!-- /wp:tab-panels --></div>'
				. "\n" . '<!-- /wp:tabs -->',
		),
		'attributes' => array(
			// Runtime state the editor manages, never a setting.
			'activeTabIndex'       => array( 'hide' => true ),
			'editorActiveTabIndex' => array( 'hide' => true ),
		),
	);

	$accordion_item = function ( $title, $text ) {
		return '<!-- wp:accordion-item -->'
			. "\n" . '<div class="wp-block-accordion-item"><!-- wp:accordion-heading -->'
			. "\n" . '<h3 class="wp-block-accordion-heading has-icon has-icon-right"><button type="button" class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">' . $title . '</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h3>'
			. "\n" . '<!-- /wp:accordion-heading -->'
			. "\n\n" . '<!-- wp:accordion-panel -->'
			. "\n" . '<div role="region" class="wp-block-accordion-panel"><!-- wp:paragraph -->'
			. "\n" . '<p>' . $text . '</p>'
			. "\n" . '<!-- /wp:paragraph --></div>'
			. "\n" . '<!-- /wp:accordion-panel --></div>'
			. "\n" . '<!-- /wp:accordion-item -->';
	};
	$forms['core/accordion'] = array(
		'insert' => array(
			'label'    => __( 'Accordion', 'minn-admin' ),
			'template' => '<!-- wp:accordion -->'
				. "\n" . '<div role="group" class="wp-block-accordion">'
				. $accordion_item( esc_html__( 'First question', 'minn-admin' ), esc_html__( 'First answer.', 'minn-admin' ) )
				. "\n\n" . $accordion_item( esc_html__( 'Second question', 'minn-admin' ), esc_html__( 'Second answer.', 'minn-admin' ) ) . '</div>'
				. "\n" . '<!-- /wp:accordion -->',
		),
	);
	return $forms;
} );
