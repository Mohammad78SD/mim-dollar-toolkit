<?php
/**
 * Plugin Name:       MiM Dollar Toolkit
 * Plugin URI:        https://github.com/Mohammad78SD/mim-dollar-toolkit
 * Description:       USD-based Toman pricing, pre-invoice checkout (no online payment), SMS notifications (FarazSMS) and mobile OTP login for Iranian WooCommerce stores.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MiM Script
 * Author URI:        https://mimscript.com
 * Text Domain:       mim-dollar-toolkit
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'MDT_TK_VERSION', '1.2.0' );
define( 'MDT_TK_FILE', __FILE__ );
define( 'MDT_TK_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDT_TK_URL', plugin_dir_url( __FILE__ ) );

require_once MDT_TK_DIR . 'includes/helpers-jalali.php';
require_once MDT_TK_DIR . 'includes/helpers-fa.php';
require_once MDT_TK_DIR . 'includes/class-logger.php';
require_once MDT_TK_DIR . 'includes/class-module.php';
require_once MDT_TK_DIR . 'includes/class-admin.php';
require_once MDT_TK_DIR . 'includes/class-toolkit.php';
require_once MDT_TK_DIR . 'includes/class-migration.php';
require_once MDT_TK_DIR . 'includes/class-legacy.php';

register_activation_hook( MDT_TK_FILE, array( 'MDT_Toolkit', 'activate' ) );
register_deactivation_hook( MDT_TK_FILE, array( 'MDT_Toolkit', 'deactivate' ) );

// Always registered (even without WooCommerce) so WP-Cron never drops module events.
MDT_Toolkit::register_early();

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MDT_TK_FILE, true );
		}
	}
);

/**
 * Boot the toolkit once WooCommerce is available.
 */
function mdt_tk_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'mdt_tk_missing_wc_notice' );
		return;
	}
	MDT_Toolkit::init();
}
add_action( 'plugins_loaded', 'mdt_tk_boot', 20 );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function mdt_tk_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'MiM Dollar Toolkit requires WooCommerce to be installed and active.', 'mim-dollar-toolkit' ) . '</p></div>';
}
