<?php
/**
 * Uninstall: let every module clean up after itself, then remove toolkit options.
 *
 * @package WooTomanToolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'WTT_TK_DIR', plugin_dir_path( __FILE__ ) );

require_once WTT_TK_DIR . 'includes/class-logger.php';
require_once WTT_TK_DIR . 'includes/class-module.php';
require_once WTT_TK_DIR . 'includes/class-toolkit.php';

foreach ( WTT_Toolkit::module_classes() as $wtt_tk_class ) {
	WTT_Toolkit::load_module_file( $wtt_tk_class );
	$wtt_tk_module = new $wtt_tk_class();
	$wtt_tk_module->uninstall();
}

delete_option( WTT_Toolkit::OPTION );
delete_option( 'wtt_migration_version' );
delete_option( 'wtt_migration_lock' );
delete_option( 'wtt_migration_notice' );
WTT_Logger::delete( 'toolkit' );
