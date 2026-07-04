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
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Minn Admin — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<?php
// The site icon (settable from Minn's own Settings → General), when one exists.
if ( has_site_icon() ) {
	wp_site_icon();
}
?>
<link rel="stylesheet" href="<?php echo esc_url( MINN_ADMIN_URL . 'assets/css/app.css?ver=' . MINN_ADMIN_VERSION ); ?>">
<script>
// Apply saved theme before first paint to avoid a flash.
try {
	var t = localStorage.getItem( 'minn-theme' );
	if ( t ) { document.documentElement.setAttribute( 'data-theme', t ); }
} catch ( e ) {}
window.MINN = <?php echo wp_json_encode( $boot ); ?>;
</script>
</head>
<body>
<div id="minn-app"><div class="minn-boot-spinner"></div></div>
<script src="<?php echo esc_url( MINN_ADMIN_URL . 'assets/js/app.js?ver=' . MINN_ADMIN_VERSION ); ?>"></script>
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
