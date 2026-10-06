<?php
/**
 * USD pricing module.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

// Legacy constants used by the module classes.
define( 'WTT_USD_VERSION', WTT_TK_VERSION );
define( 'WTT_USD_FILE', WTT_TK_FILE );
define( 'WTT_USD_DIR', WTT_TK_DIR . 'modules/usd-pricing/' );
define( 'WTT_USD_URL', WTT_TK_URL . 'modules/usd-pricing/' );

require_once WTT_USD_DIR . 'includes/class-settings.php';
require_once WTT_USD_DIR . 'includes/class-logger.php';
require_once WTT_USD_DIR . 'includes/class-rate-providers.php';
require_once WTT_USD_DIR . 'includes/class-cache-purge.php';
require_once WTT_USD_DIR . 'includes/class-rate-manager.php';
require_once WTT_USD_DIR . 'includes/class-price-sync.php';
require_once WTT_USD_DIR . 'includes/class-product-fields.php';
require_once WTT_USD_DIR . 'includes/class-admin-page.php';
require_once WTT_USD_DIR . 'includes/class-plugin.php';

class WTT_Module_USD_Pricing extends WTT_Module {

	public function id() {
		return 'usd-pricing';
	}

	public function title() {
		return __( 'USD Pricing', 'woo-toman-toolkit' );
	}

	public function description() {
		return __( 'Enter product prices in USD; Toman prices are calculated automatically from the live USDT/Toman rate.', 'woo-toman-toolkit' );
	}

	public function register_early() {
		add_filter( 'cron_schedules', array( 'WTT_USD_Plugin', 'cron_schedules' ) );
	}

	public function init() {
		WTT_USD_Plugin::init();
	}

	public function render_settings_tab() {
		WTT_USD_Admin_Page::render_tab();
	}

	public function activate() {
		WTT_USD_Plugin::activate();
	}

	public function deactivate() {
		WTT_USD_Plugin::deactivate();
	}

	/**
	 * Remove options and scheduled events. Product meta is intentionally kept.
	 */
	public function uninstall() {
		wp_clear_scheduled_hook( 'wtt_usd_fetch_rate' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wtt_usd_sync_batch', array(), 'wtt_usd' );
		}

		delete_option( 'wtt_usd_settings' );
		delete_option( 'wtt_usd_rate_state' );
		delete_option( 'wtt_usd_log' );
		delete_option( 'wtt_usd_dismissed' );
		delete_option( 'wtt_usd_sync_run' );
		delete_option( 'wtt_usd_sync_parents' );
	}
}
