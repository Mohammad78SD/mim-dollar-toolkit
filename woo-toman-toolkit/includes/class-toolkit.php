<?php
/**
 * Module registry and lifecycle.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_Toolkit {

	const OPTION = 'wtt_tk_modules';

	/**
	 * Module registry: module id => class. Add new modules here (and drop the files in modules/<id>/).
	 *
	 * @return array
	 */
	public static function module_classes() {
		return array(
			'usd-pricing' => 'WTT_Module_USD_Pricing',
			'pre-invoice' => 'WTT_Module_Pre_Invoice',
			'sms'         => 'WTT_Module_SMS',
			'accounts'    => 'WTT_Module_Accounts',
		);
	}

	/**
	 * Require a module's main file (modules/<id>/class-module-<id>.php).
	 *
	 * @param string $class_name Module class.
	 */
	public static function load_module_file( $class_name ) {
		$id = array_search( $class_name, self::module_classes(), true );
		if ( false === $id ) {
			return;
		}
		$file = WTT_TK_DIR . 'modules/' . $id . '/class-module-' . $id . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Instances of all registered modules, keyed by id.
	 *
	 * @return WTT_Module[]
	 */
	public static function modules() {
		static $modules = null;
		if ( null === $modules ) {
			$modules = array();
			foreach ( self::module_classes() as $id => $class_name ) {
				self::load_module_file( $class_name );
				if ( class_exists( $class_name ) ) {
					$modules[ $id ] = new $class_name();
				}
			}
		}
		return $modules;
	}

	/**
	 * Enabled map (id => bool), merged with module defaults.
	 *
	 * @param mixed $stored Optional stored value; defaults to the option.
	 * @return array
	 */
	public static function enabled_map( $stored = null ) {
		if ( null === $stored ) {
			$stored = get_option( self::OPTION, array() );
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$map = array();
		foreach ( self::modules() as $id => $module ) {
			$map[ $id ] = array_key_exists( $id, $stored ) ? (bool) $stored[ $id ] : (bool) $module->default_enabled();
		}
		return $map;
	}

	public static function is_enabled( $id ) {
		$map = self::enabled_map();
		return ! empty( $map[ $id ] );
	}

	/**
	 * Enabled modules, keyed by id.
	 *
	 * @return WTT_Module[]
	 */
	public static function enabled_modules() {
		$out = array();
		foreach ( self::modules() as $id => $module ) {
			if ( self::is_enabled( $id ) ) {
				$out[ $id ] = $module;
			}
		}
		return $out;
	}

	/**
	 * Hooks needed even without WooCommerce. Called when the plugin file loads.
	 */
	public static function register_early() {
		foreach ( self::modules() as $module ) {
			$module->register_early();
		}
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		WTT_Migration::init();
		WTT_Legacy::init();
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'on_modules_updated' ), 10, 2 );
	}

	/**
	 * Boot (WooCommerce is active).
	 */
	public static function init() {
		foreach ( self::enabled_modules() as $module ) {
			$module->init();
		}

		if ( is_admin() ) {
			WTT_Admin::init();
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'woo-toman-toolkit', false, dirname( plugin_basename( WTT_TK_FILE ) ) . '/languages' );
	}

	/**
	 * Fan out enable/disable changes to the modules.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public static function on_modules_updated( $old, $new ) {
		$before = self::enabled_map( $old );
		$after  = self::enabled_map( $new );
		foreach ( self::modules() as $id => $module ) {
			if ( $before[ $id ] && ! $after[ $id ] ) {
				$module->deactivate();
			} elseif ( ! $before[ $id ] && $after[ $id ] ) {
				$module->activate();
			}
		}
	}

	public static function activate() {
		// Import data from the legacy plugin first, so defaults below never shadow it.
		WTT_Migration::run();
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::enabled_map( array() ) );
		}
		foreach ( self::enabled_modules() as $module ) {
			$module->activate();
		}
	}

	public static function deactivate() {
		foreach ( self::enabled_modules() as $module ) {
			$module->deactivate();
		}
	}
}
