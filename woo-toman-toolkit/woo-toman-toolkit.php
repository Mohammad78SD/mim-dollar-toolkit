<?php
/**
 * Plugin Name:       Toman Toolkit for WooCommerce
 * Plugin URI:        https://github.com/Mohammad78SD/woo-toman-toolkit
 * Description:       USD→Toman pricing, pre-invoice checkout (no online payment), SMS notifications (FarazSMS), mobile OTP login — for Iranian WooCommerce stores.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mohammad Sabet Daryani
 * Author URI:        https://mimscript.com
 * Text Domain:       woo-toman-toolkit
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'WTT_TK_VERSION', '1.1.0' );
define( 'WTT_TK_FILE', __FILE__ );
define( 'WTT_TK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTT_TK_URL', plugin_dir_url( __FILE__ ) );

require_once WTT_TK_DIR . 'includes/helpers-jalali.php';
require_once WTT_TK_DIR . 'includes/helpers-fa.php';
require_once WTT_TK_DIR . 'includes/class-logger.php';
require_once WTT_TK_DIR . 'includes/class-module.php';
require_once WTT_TK_DIR . 'includes/class-admin.php';
require_once WTT_TK_DIR . 'includes/class-toolkit.php';
require_once WTT_TK_DIR . 'includes/class-migration.php';
require_once WTT_TK_DIR . 'includes/class-legacy.php';

register_activation_hook( WTT_TK_FILE, array( 'WTT_Toolkit', 'activate' ) );
register_deactivation_hook( WTT_TK_FILE, array( 'WTT_Toolkit', 'deactivate' ) );

// Always registered (even without WooCommerce) so WP-Cron never drops module events.
WTT_Toolkit::register_early();

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WTT_TK_FILE, true );
		}
	}
);

/**
 * Boot the toolkit once WooCommerce is available.
 */
function wtt_tk_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wtt_tk_missing_wc_notice' );
		return;
	}
	WTT_Toolkit::init();
}
add_action( 'plugins_loaded', 'wtt_tk_boot', 20 );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function wtt_tk_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Toman Toolkit for WooCommerce requires WooCommerce to be installed and active.', 'woo-toman-toolkit' ) . '</p></div>';
}
