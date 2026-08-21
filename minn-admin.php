<?php
/**
 * Plugin Name:       Minn Admin
 * Plugin URI:        https://minnadmin.com
 * Description:       A reimagined WordPress admin experience. Fast, focused and beautiful. Served at /minn-admin/.
 * Version:           0.34.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       minn-admin
 */

defined( 'ABSPATH' ) || exit;

// Breakdance 2.8.x promotes every reported PHP deprecation to an exception
// during its builder AJAX requests. On PHP 8.5 that lets an unrelated plugin's
// legacy cast syntax abort the builder before WordPress reaches the AJAX action.
// Keep real warnings and errors visible; only omit deprecations for the exact
// request class where Breakdance installs that exception handler.
// Narrowed to the request class this was written for. The header alone is
// something any client can send on any request, including an unauthenticated
// one, which let a caller quiet the deprecation notices their own probing
// would otherwise leave in the log. Require Breakdance to actually be present
// and the request to be the builder's own admin-ajax POST. wp_doing_ajax() is
// not available this early, so test the entry point directly.
if (
	isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
	&& 'breakdancexmlhttprequest' === strtolower( (string) $_SERVER['HTTP_X_REQUESTED_WITH'] )
	&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
	&& isset( $_SERVER['SCRIPT_NAME'] ) && 'admin-ajax.php' === basename( (string) $_SERVER['SCRIPT_NAME'] )
	&& defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/breakdance/plugin.php' )
) {
	error_reporting( error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED );
}

define( 'MINN_ADMIN_VERSION', '0.34.0' );
define( 'MINN_ADMIN_FILE', __FILE__ );
define( 'MINN_ADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINN_ADMIN_URL', plugin_dir_url( __FILE__ ) );

require_once MINN_ADMIN_DIR . 'includes/class-minn-admin.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-rest.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-surfaces.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-cpt.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-notices.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-logs.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-db.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-updater.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-bar.php';

// Bundled adapters for third-party plugins (each guards on its plugin).
require_once MINN_ADMIN_DIR . 'includes/adapters/jetpack-tiled-gallery.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/fluent-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/ninja-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/forminator.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/formidable.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/everest-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/sureforms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wpforms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cf7-flamingo.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cfdb7.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/elementor-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/fluent-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-mail-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/post-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-mail-logging.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/suremails.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/site-mailer.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/ottokit.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/amelia.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/latepoint.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/bookly.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acf-field-groups.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acpt.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/meta-box.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/pods.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/seriously-simple-podcasting.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/powerpress.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/the-events-calendar.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-job-manager.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/safe-svg.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/koko-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/burst-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/independent-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/analyticswp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/plausible-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/matomo.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/site-kit.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/jetpack-stats.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-history.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/aryo-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/stream.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wordfence.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/limit-login-attempts.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/solid-security.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/all-in-one-security.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/code-snippets.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wpcode.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/fluent-snippets.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/custom-css-js.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/hfcm.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/redirection.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/safe-redirect-manager.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-301-redirects.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/eps-301-redirects.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/query-monitor.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/scrutoscope.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-crontrol.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/transients-manager.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/rewrite-rules-inspector.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cache-purge.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/updraftplus.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/disembark.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/duplicator.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wpvivid.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/backwpup.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/ai1wm.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/page-builders.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/seo.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/media-localize.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/stackable.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/kadence.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/generateblocks.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/otter.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/essential-blocks.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/etch.php';
// Loaded after every provider so it can gather what they contribute.
require_once MINN_ADMIN_DIR . 'includes/adapters/option-pages.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/field-groups.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/spam.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cleantalk.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/site-status.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/user-switching.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/one-time-login.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/public-post-preview.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/regenerate-thumbnails.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/enable-media-replace.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/media-folders.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wcpdf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/yith-gift-cards.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/woocommerce-gift-cards.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/pw-gift-cards.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-migrate.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/licenses.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/perfmatters.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/autoptimize.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/asset-cleanup.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/performance-lab.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/network.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-multi-network.php';

Minn_Admin::init();
Minn_Admin_REST::init();
Minn_Admin_DB::init();
Minn_Admin_Notices::init();
Minn_Admin_CPT::init();
Minn_Admin_Bar::init();
new Minn_Admin_Updater();

register_activation_hook( __FILE__, function ( $network_wide ) {
	Minn_Admin::register_route();
	flush_rewrite_rules();
	if ( $network_wide ) {
		Minn_Admin::invalidate_network_rewrites();
	}
	// Prime language packs. A fresh install ships no catalogs (packs are
	// separate downloads), so a non-English site would read English until
	// the next scheduled update check noticed the manifest's translations
	// and cron installed them, up to half a day later. Clearing the
	// updater's manifest cache and re-running the plugin update check here
	// puts the packs into the transient immediately; core's translation
	// auto-updater installs them on its next pass, and the Updates screen
	// offers them right away either way. English sites fetch a manifest and
	// download nothing.
	delete_transient( 'minn_admin_updater' );
	if ( function_exists( 'wp_update_plugins' ) ) {
		wp_update_plugins();
	}
} );

register_deactivation_hook( __FILE__, function ( $network_wide ) {
	// Not flush_rewrite_rules(): init already registered the route this
	// request, so a flush here would regenerate the rules WITH it and leave
	// /minn-admin/ serving the homepage after deactivation. Dropping the
	// option makes the site rebuild on its next request, route omitted.
	delete_option( 'rewrite_rules' );
	if ( $network_wide ) {
		Minn_Admin::invalidate_network_rewrites();
	}
} );
