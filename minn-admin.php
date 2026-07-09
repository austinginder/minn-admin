<?php
/**
 * Plugin Name:       Minn Admin
 * Plugin URI:        https://github.com/austinginder/minn-admin
 * Description:       A reimagined WordPress admin experience. Fast, focused and beautiful. Served at /minn-admin/.
 * Version:           0.9.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       minn-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'MINN_ADMIN_VERSION', '0.9.0' );
define( 'MINN_ADMIN_FILE', __FILE__ );
define( 'MINN_ADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINN_ADMIN_URL', plugin_dir_url( __FILE__ ) );

require_once MINN_ADMIN_DIR . 'includes/class-minn-admin.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-rest.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-surfaces.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-cpt.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-updater.php';

// Bundled adapters for third-party plugins (each guards on its plugin).
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/koko-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/burst-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/independent-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/analyticswp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-history.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/aryo-activity-log.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/stream.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/redirection.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/safe-redirect-manager.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-301-redirects.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/query-monitor.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/page-builders.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/seo.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/media-localize.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/stackable.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/kadence.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/generateblocks.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/otter.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/essential-blocks.php';

Minn_Admin::init();
Minn_Admin_REST::init();
Minn_Admin_CPT::init();
new Minn_Admin_Updater();

register_activation_hook( __FILE__, function () {
	Minn_Admin::register_route();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
