<?php
/**
 * Minn's core icon set survives WordPress's icon registry untouched.
 *
 * assets/icons/core-icons.json is what Minn Engine registers under
 * WordPress's core icon names, and what WordPress 7.1's own registry would
 * accept: it keeps only <svg> (xmlns, viewBox and a few attributes), <path>
 * and <polygon> with fill attributes, and strips strokes, circles, rects and
 * lines. So every icon must be a filled path that the registry's sanitizer
 * hands back unchanged, and that registers and reads back the same. The
 * stroke drawings in bin/icons are the source; outline.py makes this file.
 *
 * Run: wp eval-file tests/core-icons.test.php --path=<a WordPress 7.1+ site>
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

$file  = dirname( __DIR__ ) . '/assets/icons/core-icons.json';
$icons = json_decode( (string) file_get_contents( $file ), true );
$check( 'core-icons.json is a JSON object', is_array( $icons ) && $icons );
if ( ! is_array( $icons ) ) {
	exit( 1 );
}

// The names WordPress 7.1 registers as core/<name>.
$names = explode( ' ', 'arrow-down-left arrow-down-right arrow-down arrow-left arrow-right arrow-up-left arrow-up-right arrow-up at-symbol audio bell block-default block-meta block-table calendar capture-photo capture-video cart category caution chart-bar check chevron-down chevron-down-small chevron-left chevron-left-small chevron-right chevron-right-small chevron-up chevron-up-down chevron-up-small comment cover create desktop download drawer-left drawer-right envelope error external file gallery group heading help home image info key language map-marker menu mobile more-horizontal more-vertical next paragraph payment pencil people plus plus-circle previous published quote receipt rss scheduled search settings shadow share shield shuffle star-empty star-filled star-half store styles symbol symbol-filled table tablet tag tip upload verse' );
$missing = array_diff( $names, array_keys( $icons ) );
$extra   = array_diff( array_keys( $icons ), $names );
$check( 'all 88 core names, and only those', 88 === count( $names ) && ! $missing && ! $extra, 'missing: ' . implode( ', ', $missing ) . '; extra: ' . implode( ', ', $extra ) );

// Shape: one <svg> with xmlns and viewBox only, paths filled with currentColor.
$shape = array();
foreach ( $icons as $name => $svg ) {
	if ( ! preg_match( '#^<svg xmlns="http://www\.w3\.org/2000/svg" viewBox="0 0 24 24">(<path d="[^"]+" fill="currentColor"( fill-rule="evenodd")?/>)+</svg>$#', $svg ) ) {
		$shape[] = $name;
	}
}
$check( 'every icon is filled paths in a bare 24px svg (no strokes, shapes or other colors)', ! $shape, implode( ', ', array_slice( $shape, 0, 8 ) ) );

if ( ! class_exists( 'WP_Icons_Registry' ) || ! class_exists( 'WP_Icon_Collections_Registry' ) ) {
	echo "SKIP  this WordPress has no icon registry (7.1+)\n";
} else {
	$registry = WP_Icons_Registry::get_instance();
	$sanitize = new ReflectionMethod( $registry, 'sanitize_icon_content' );
	$sanitize->setAccessible( true );
	// Attribute case and self-closing spacing are the sanitizer's own spelling.
	$norm = static function ( $s ) {
		return str_replace( ' />', '/>', strtolower( (string) $s ) );
	};
	$stripped = array();
	foreach ( $icons as $name => $svg ) {
		if ( $norm( $sanitize->invoke( $registry, $svg ) ) !== $norm( $svg ) ) {
			$stripped[] = $name;
		}
	}
	$check( "WordPress's icon sanitizer leaves every icon unchanged", ! $stripped, implode( ', ', array_slice( $stripped, 0, 8 ) ) );

	$collections = WP_Icon_Collections_Registry::get_instance();
	$collections->register( 'minn-icons-check', array( 'label' => 'Minn icons check' ) );
	$differ = array();
	foreach ( $icons as $name => $svg ) {
		$key = 'minn-icons-check/' . $name;
		$registry->register( $key, array( 'label' => $name, 'content' => $svg ) );
		$got = $registry->get_registered_icon( $key );
		if ( ! is_array( $got ) || $norm( $got['content'] ?? '' ) !== $norm( $svg ) ) {
			$differ[] = $name;
		}
		$registry->unregister( $key );
	}
	$collections->unregister( 'minn-icons-check' );
	$check( 'every icon registers and reads back as shipped', ! $differ, implode( ', ', array_slice( $differ, 0, 8 ) ) );
}

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\ncore-icons: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
