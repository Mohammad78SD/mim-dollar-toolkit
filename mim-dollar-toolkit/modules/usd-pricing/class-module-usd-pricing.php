<?php
/**
 * USD pricing module.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

// Legacy constants used by the module classes.
define( 'MDT_USD_VERSION', MDT_TK_VERSION );
define( 'MDT_USD_FILE', MDT_TK_FILE );
define( 'MDT_USD_DIR', MDT_TK_DIR . 'modules/usd-pricing/' );
define( 'MDT_USD_URL', MDT_TK_URL . 'modules/usd-pricing/' );

require_once MDT_USD_DIR . 'includes/class-settings.php';
require_once MDT_USD_DIR . 'includes/class-logger.php';
require_once MDT_USD_DIR . 'includes/class-rate-providers.php';
require_once MDT_USD_DIR . 'includes/class-cache-purge.php';
require_once MDT_USD_DIR . 'includes/class-rate-manager.php';
require_once MDT_USD_DIR . 'includes/class-price-sync.php';
require_once MDT_USD_DIR . 'includes/class-product-fields.php';
require_once MDT_USD_DIR . 'includes/class-admin-page.php';
require_once MDT_USD_DIR . 'includes/class-plugin.php';

class MDT_Module_USD_Pricing extends MDT_Module {

	public function id() {
		return 'usd-pricing';
	}

	public function title() {
		return __( 'USD Pricing', 'mim-dollar-toolkit' );
	}

	public function description() {
		return __( 'Enter product prices in USD; Toman prices are calculated automatically from the live USDT/Toman rate.', 'mim-dollar-toolkit' );
	}

	public function register_early() {
		add_filter( 'cron_schedules', array( 'MDT_USD_Plugin', 'cron_schedules' ) );
	}

	public function init() {
		MDT_USD_Plugin::init();
	}

	public function render_settings_tab() {
		MDT_USD_Admin_Page::render_tab();
	}

	public function activate() {
		MDT_USD_Plugin::activate();
	}

	public function deactivate() {
		MDT_USD_Plugin::deactivate();
	}

	/**
	 * Remove options and scheduled events. Product meta is intentionally kept.
	 */
	public function uninstall() {
		wp_clear_scheduled_hook( 'mdt_usd_fetch_rate' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'mdt_usd_sync_batch', array(), 'mdt_usd' );
		}

		delete_option( 'mdt_usd_settings' );
		delete_option( 'mdt_usd_rate_state' );
		delete_option( 'mdt_usd_log' );
		delete_option( 'mdt_usd_dismissed' );
		delete_option( 'mdt_usd_sync_run' );
		delete_option( 'mdt_usd_sync_parents' );
	}
}
