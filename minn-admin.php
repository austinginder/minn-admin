<?php
/**
 * Plugin Name:       Minn Admin
 * Plugin URI:        https://github.com/austinginder/minn-admin
 * Description:       A reimagined WordPress admin experience. Fast, focused and beautiful — served at /minn-admin/.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Austin Ginder
 * Author URI:        https://austinginder.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       minn-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'MINN_ADMIN_VERSION', '0.3.0' );
define( 'MINN_ADMIN_FILE', __FILE__ );
define( 'MINN_ADMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINN_ADMIN_URL', plugin_dir_url( __FILE__ ) );

require_once MINN_ADMIN_DIR . 'includes/class-minn-admin.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-rest.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-surfaces.php';
require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-updater.php';

// Bundled adapters for third-party plugins (each guards on its plugin).
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-forms.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/gravity-smtp.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/acf.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/koko-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/wp-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/burst-statistics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/independent-analytics.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/simple-history.php';
require_once MINN_ADMIN_DIR . 'includes/adapters/redirection.php';

Minn_Admin::init();
Minn_Admin_REST::init();
new Minn_Admin_Updater();

register_activation_hook( __FILE__, function () {
	Minn_Admin::register_route();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
