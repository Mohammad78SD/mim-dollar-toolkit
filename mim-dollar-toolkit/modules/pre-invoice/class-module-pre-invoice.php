<?php
/**
 * Pre-invoice module.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'MDT_PI_DIR', MDT_TK_DIR . 'modules/pre-invoice/' );

require_once MDT_PI_DIR . 'includes/class-settings.php';
require_once MDT_PI_DIR . 'includes/class-format.php';
require_once MDT_PI_DIR . 'includes/class-number.php';
require_once MDT_PI_DIR . 'includes/class-order-status.php';
require_once MDT_PI_DIR . 'includes/class-orders.php';
require_once MDT_PI_DIR . 'includes/class-page.php';
require_once MDT_PI_DIR . 'includes/class-emails.php';
require_once MDT_PI_DIR . 'includes/class-admin-tab.php';

class MDT_Module_Pre_Invoice extends MDT_Module {

	const SETUP_OPTION = 'mdt_pi_setup_version';

	public function id() {
		return 'pre-invoice';
	}

	public function title() {
		return __( 'Pre-invoice', 'mim-dollar-toolkit' );
	}

	public function description() {
		return __( 'Checkout without online payment: issue a numbered pre-invoice (email + printable page), reserve stock and auto-cancel unpaid requests.', 'mim-dollar-toolkit' );
	}

	public function register_early() {
		add_action( MDT_PI_Orders::CRON_HOOK, array( 'MDT_PI_Orders', 'run_auto_cancel' ) );
	}

	public function init() {
		MDT_PI_Order_Status::init();
		MDT_PI_Orders::init();
		MDT_PI_Page::init();
		MDT_PI_Emails::init();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ) );
		add_action( 'init', array( $this, 'ensure_setup' ), 20 );

		if ( is_admin() ) {
			MDT_PI_Admin_Tab::init();
		}
	}

	public function add_gateway( $gateways ) {
		require_once MDT_PI_DIR . 'includes/class-gateway.php';
		$gateways[] = 'MDT_PI_Gateway';
		return $gateways;
	}

	public function register_blocks( $registry ) {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once MDT_PI_DIR . 'includes/class-gateway.php';
		require_once MDT_PI_DIR . 'includes/class-blocks-integration.php';
		$registry->register( new MDT_PI_Blocks_Integration() );
	}

	/**
	 * Lazy lifecycle: covers the case where the module is enabled by default (no stored key),
	 * so the option-update hook never fired activate().
	 */
	public function ensure_setup() {
		if ( get_option( self::SETUP_OPTION ) !== MDT_TK_VERSION ) {
			$this->activate();
		} else {
			MDT_PI_Orders::schedule();
		}
	}

	public function render_settings_tab() {
		MDT_PI_Admin_Tab::render();
	}

	public function activate() {
		$key      = 'woocommerce_mdt_preinvoice_settings';
		$settings = get_option( $key, false );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! isset( $settings['enabled'] ) ) {
			$settings['enabled'] = 'yes';
			update_option( $key, $settings );
		}
		MDT_PI_Orders::schedule();
		update_option( self::SETUP_OPTION, MDT_TK_VERSION );
	}

	public function deactivate() {
		MDT_PI_Orders::unschedule();
		delete_option( self::SETUP_OPTION );
	}

	public function uninstall() {
		wp_clear_scheduled_hook( 'mdt_pi_auto_cancel' );
		delete_option( 'mdt_pi_settings' );
		delete_option( 'mdt_pi_counter' );
		delete_option( 'mdt_pi_setup_version' );
		delete_option( 'woocommerce_mdt_preinvoice_settings' );
		MDT_Logger::delete( 'pre-invoice' );
	}
}
