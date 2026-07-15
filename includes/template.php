<?php
/**
 * Minn Admin app shell. Rendered standalone at /minn-admin/ — no theme, no wp-admin chrome.
 *
 * @var array $boot Boot payload prepared in Minn_Admin::maybe_render_app().
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Minn Admin — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<?php
// The site icon (settable from Minn's own Settings → General), when one exists.
if ( has_site_icon() ) {
	wp_site_icon();
}
?>
<?php
// Self-busting asset versions: version + file mtime, so every edit or update
// invalidates the browser cache without a constant bump (stale app.js after
// an update repeatedly masked real fixes during development).
$minn_asset_ver = function ( $rel ) {
	$mtime = @filemtime( MINN_ADMIN_DIR . $rel );
	return MINN_ADMIN_VERSION . ( $mtime ? '.' . $mtime : '' );
};
?>
<link rel="stylesheet" href="<?php echo esc_url( MINN_ADMIN_URL . 'assets/css/app.css?ver=' . $minn_asset_ver( 'assets/css/app.css' ) ); ?>">
<script>
// Apply the theme before first paint to avoid a flash. Default is System
// (follow the OS live). Explicit light/dark wins when the user locked one.
try {
	var stored = localStorage.getItem( 'minn-theme' );
	// First visit: persist System so the default is an explicit preference.
	if ( ! stored ) {
		localStorage.setItem( 'minn-theme', 'system' );
		stored = 'system';
	}
	var follow = stored === 'system';
	if ( follow && window.matchMedia ) {
		var mq = window.matchMedia( '(prefers-color-scheme: light)' );
		document.documentElement.setAttribute( 'data-theme', mq.matches ? 'light' : 'dark' );
		mq.addEventListener( 'change', function ( e ) {
			if ( localStorage.getItem( 'minn-theme' ) === 'system' ) {
				document.documentElement.setAttribute( 'data-theme', e.matches ? 'light' : 'dark' );
				document.dispatchEvent( new CustomEvent( 'minn-theme-change' ) );
			}
		} );
	} else if ( stored === 'light' || stored === 'dark' ) {
		document.documentElement.setAttribute( 'data-theme', stored );
	}
} catch ( e ) {}
window.MINN = <?php echo wp_json_encode( $boot ); ?>;
// Accent palette from user meta (boot.user.appearance) — apply before paint
// so a custom/preset color doesn't flash the default violet.
(function () {
	try {
		var ap = ( window.MINN && window.MINN.user && window.MINN.user.appearance ) || { accent: 'minn', custom: '' };
		var root = document.documentElement;
		var accent = ap.accent || 'minn';
		root.setAttribute( 'data-accent', accent );
		if ( accent !== 'custom' || ! ap.custom ) {
			root.style.removeProperty( '--accent' );
			root.style.removeProperty( '--accent2' );
			root.style.removeProperty( '--accent-soft' );
			root.style.removeProperty( '--accent-fg' );
			return;
		}
		// Compact custom derivation (mirrored in app.js applyAppearance).
		var hex = String( ap.custom ).replace( /^#/, '' );
		if ( hex.length === 3 ) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		if ( ! /^[0-9a-fA-F]{6}$/.test( hex ) ) return;
		var r = parseInt( hex.slice( 0, 2 ), 16 ), g = parseInt( hex.slice( 2, 4 ), 16 ), b = parseInt( hex.slice( 4, 6 ), 16 );
		var mode = root.getAttribute( 'data-theme' ) || 'dark';
		function clamp( n ) { return Math.max( 0, Math.min( 255, Math.round( n ) ) ); }
		function toHex( rr, gg, bb ) {
			return '#' + [ rr, gg, bb ].map( function ( n ) {
				var s = clamp( n ).toString( 16 );
				return s.length === 1 ? '0' + s : s;
			} ).join( '' );
		}
		function mix( t ) {
			// t > 0 lighten toward white; t < 0 darken toward black.
			if ( t >= 0 ) return toHex( r + ( 255 - r ) * t, g + ( 255 - g ) * t, b + ( 255 - b ) * t );
			var k = 1 + t;
			return toHex( r * k, g * k, b * k );
		}
		var base = '#' + hex.toLowerCase();
		var accent2 = mode === 'light' ? mix( -0.12 ) : mix( 0.18 );
		var softA = mode === 'light' ? 0.10 : 0.15;
		var lum = ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;
		root.style.setProperty( '--accent', base );
		root.style.setProperty( '--accent2', accent2 );
		root.style.setProperty( '--accent-soft', 'rgba(' + r + ',' + g + ',' + b + ',' + softA + ')' );
		root.style.setProperty( '--accent-fg', lum > 0.62 ? '#14141a' : '#ffffff' );
	} catch ( e2 ) {}
})();
</script>
</head>
<body>
<div id="minn-app"><div class="minn-boot-spinner"></div></div>
<script src="<?php echo esc_url( MINN_ADMIN_URL . 'assets/js/app.js?ver=' . $minn_asset_ver( 'assets/js/app.js' ) ); ?>"></script>
<?php
/**
 * Fires at the end of Minn's app document — the ONLY hook inside it. Minn
 * deliberately never fires wp_head/wp_footer (a random plugin injecting into
 * the SPA is exactly what this document avoids); developer tooling that knows
 * about Minn can attach here. The bundled Query Monitor adapter uses it.
 */
do_action( 'minn_admin_template_footer' );
?>
</body>
</html>
