<?php
/**
 * Admin-notice link labels keep accessibility-only suffixes out of the
 * visible notification action.
 *
 * Run: wp eval-file tests/notice-link-label.test.php --path=<site>
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $detail ? "\n      {$detail}" : '' ) . "\n";
};

$document = new DOMDocument();
$document->loadHTML(
	'<div class="notice"><a href="https://example.com/get">Get Example'
	. '<span class="screen-reader-text"> (Opens in a new browser tab)</span>'
	. '<span aria-hidden="true"> decorative</span></a></div>'
);
$notice = $document->getElementsByTagName( 'div' )->item( 0 );
$method = new ReflectionMethod( 'Minn_Admin_Notices', 'links_of' );
$method->setAccessible( true );
$links = $method->invoke( null, $notice );
$link  = $links[0] ?? array();

$check( 'External notice link is extracted', ! empty( $link['url'] ) && 'https://example.com/get' === $link['url'], wp_json_encode( $link ) );
$check( 'Visible label excludes screen-reader and aria-hidden suffixes', 'Get Example' === ( $link['text'] ?? '' ), $link['text'] ?? '' );

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
