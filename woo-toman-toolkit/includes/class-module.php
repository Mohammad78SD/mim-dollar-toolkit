<?php
/**
 * Base class for toolkit modules.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

abstract class WTT_Module {

	/**
	 * Unique module id (also the admin tab slug).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human readable title.
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * One-line description shown on the Modules tab.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Register hooks. Called on plugins_loaded only when the module is enabled and WooCommerce is active.
	 */
	abstract public function init();

	/**
	 * Output the module's admin tab content (inside the toolkit wrap, below the tabs).
	 */
	abstract public function render_settings_tab();

	/**
	 * Whether the module is enabled when no choice has been saved yet.
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Hooks that must exist regardless of enabled state or WooCommerce (e.g. cron schedules).
	 */
	public function register_early() {}

	/**
	 * Plugin activated or module enabled.
	 */
	public function activate() {}

	/**
	 * Plugin deactivated or module disabled. Must keep data.
	 */
	public function deactivate() {}

	/**
	 * Plugin uninstalled: delete options and events.
	 */
	public function uninstall() {}
}
