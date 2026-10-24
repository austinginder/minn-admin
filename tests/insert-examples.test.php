<?php
/**
 * A dynamic parent block whose registered example names children is
 * offered in the slash menu with those children (Minn_Admin::insertable_blocks
 * "template"), so a list block never arrives empty with nothing to edit; a
 * block with no example children keeps the bare self-closing comment.
 *
 * Run: wp eval-file tests/insert-examples.test.php
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

$registry = WP_Block_Type_Registry::get_instance();
$render   = static function ( $attributes, $content ) {
	return '<div class="zz-list">' . $content . '</div>';
};
$registry->register(
	'zz-minn-test/list',
	array(
		'title'           => 'ZZ list',
		'render_callback' => $render,
		'example'         => array(
			'attributes'  => array(),
			'innerBlocks' => array(
				array( 'name' => 'zz-minn-test/item', 'attributes' => array( 'label' => 'First <one>' ) ),
				array( 'name' => 'zz-minn-test/item', 'attributes' => array( 'label' => 'Second' ) ),
			),
		),
	)
);
$registry->register(
	'zz-minn-test/item',
	array(
		'title'           => 'ZZ item',
		'parent'          => array( 'zz-minn-test/list' ),
		'attributes'      => array( 'label' => array( 'type' => 'string', 'default' => '' ) ),
		'render_callback' => static function ( $attributes ) {
			return '<p>' . esc_html( $attributes['label'] ) . '</p>';
		},
	)
);
$registry->register(
	'zz-minn-test/leaf',
	array(
		'title'           => 'ZZ leaf',
		'render_callback' => static function () {
			return '<p>leaf</p>';
		},
	)
);

$out    = Minn_Admin::insertable_blocks( array() );
$byName = array();
foreach ( $out as $entry ) {
	$byName[ $entry['name'] ] = $entry;
}
$list = isset( $byName['zz-minn-test/list'] ) ? $byName['zz-minn-test/list'] : null;
$check( 'the parent is offered', null !== $list );
$template = $list['template'] ?? '';
$want = '<!-- wp:zz-minn-test/list --><!-- wp:zz-minn-test/item {"label":"First \u003cone\u003e"} /--><!-- wp:zz-minn-test/item {"label":"Second"} /--><!-- /wp:zz-minn-test/list -->';
$check( 'its template carries the example children, attributes encoded', $want === $template, wp_json_encode( $template ) );
$parsed = parse_blocks( $template );
$check( 'the template parses back to the parent and both children', 1 === count( $parsed ) && 2 === count( $parsed[0]['innerBlocks'] ) && 'First <one>' === $parsed[0]['innerBlocks'][0]['attrs']['label'] );
$check( 'a child block is not offered on its own', ! isset( $byName['zz-minn-test/item'] ) );
$check( 'a block without example children has no template', isset( $byName['zz-minn-test/leaf'] ) && ! isset( $byName['zz-minn-test/leaf']['template'] ) );

foreach ( array( 'zz-minn-test/list', 'zz-minn-test/item', 'zz-minn-test/leaf' ) as $name ) {
	$registry->unregister( $name );
}
echo ( in_array( false, $results, true ) ? 'FAIL' : 'PASS' ) . '  insert-examples: ' . count( array_filter( $results ) ) . '/' . count( $results ) . " passed\n";
exit( in_array( false, $results, true ) ? 1 : 0 );
