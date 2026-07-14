<?php
/**
 * Plugin Name:       Minn Admin
 * Plugin URI:        https://github.com/austinginder/minn-admin
 * Description:       A reimagined WordPress admin experience. Fast, focused and beautiful. Served at /minn-admin/.
 * Version:           0.14.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       minn-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'MINN_ADMIN_VERSION', '0.14.0' );
define( 'MINN_ADMIN_FILE', __FILE__ );
define( 'MINN_ADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINN_ADMIN_URL', plugin_dir_url( __FILE__ ) );

require_once MINN_ADMIN_DIR . 'includes/class-minn-admin.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-rest.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-surfaces.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-cpt.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-notices.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-updater.php';

// Bundled adapters for third-party plugins (each guards on its plugin).
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/fluent-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/ninja-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/forminator.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/formidable.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/everest-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cf7-flamingo.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/cfdb7.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/elementor-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/fluent-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-mail-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/post-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-mail-logging.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/meta-box.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/pods.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/safe-svg.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/koko-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/burst-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/independent-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/analyticswp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/site-kit.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-history.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/aryo-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/stream.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wordfence.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/limit-login-attempts.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/solid-security.php';
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
require_once MINN_ADMIN_DIR . 'includes/adapters/spam.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/site-status.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/user-switching.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/one-time-login.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/public-post-preview.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/regenerate-thumbnails.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wcpdf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/licenses.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/perfmatters.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/autoptimize.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/asset-cleanup.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/performance-lab.php';

Minn_Admin::init();
Minn_Admin_REST::init();
Minn_Admin_Notices::init();
Minn_Admin_CPT::init();
new Minn_Admin_Updater();

register_activation_hook( __FILE__, function () {
	Minn_Admin::register_route();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
