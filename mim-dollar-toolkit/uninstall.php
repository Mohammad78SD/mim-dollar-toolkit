<?php
/**
 * Uninstall: let every module clean up after itself, then remove toolkit options.
 *
 * @package MimDollarToolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'MDT_TK_DIR', plugin_dir_path( __FILE__ ) );

require_once MDT_TK_DIR . 'includes/class-logger.php';
require_once MDT_TK_DIR . 'includes/class-module.php';
require_once MDT_TK_DIR . 'includes/class-toolkit.php';

foreach ( MDT_Toolkit::module_classes() as $mdt_tk_class ) {
	MDT_Toolkit::load_module_file( $mdt_tk_class );
	$mdt_tk_module = new $mdt_tk_class();
	$mdt_tk_module->uninstall();
}

delete_option( MDT_Toolkit::OPTION );
delete_option( 'mdt_migration_version' );
delete_option( 'mdt_migration_lock' );
delete_option( 'mdt_migration_notice' );
MDT_Logger::delete( 'toolkit' );
