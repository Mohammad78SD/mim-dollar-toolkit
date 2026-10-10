<?php
/**
 * WooCommerce > MiM Dollar Toolkit admin page: Modules tab + one tab per enabled module.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_Admin {

	const SLUG       = 'mdt-toolkit';
	const CAPABILITY = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mdt_tk_save_modules', array( __CLASS__, 'handle_save_modules' ) );
		add_action( 'admin_post_mdt_tk_remove_legacy', array( __CLASS__, 'handle_remove_legacy' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'MiM Dollar Toolkit', 'mim-dollar-toolkit' ),
			__( 'MiM Dollar Toolkit', 'mim-dollar-toolkit' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin URL of a tab.
	 *
	 * @param string $tab Tab id ('modules' or a module id).
	 * @return string
	 */
	public static function tab_url( $tab = 'modules' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Save enabled modules (the option update hook fans out activate/deactivate).
	 */
	public static function handle_save_modules() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mdt_tk_save_modules' );

		$posted = array();
		if ( isset( $_POST['mdt_modules'] ) && is_array( $_POST['mdt_modules'] ) ) {
			$posted = array_map( 'sanitize_key', wp_unslash( $_POST['mdt_modules'] ) );
		}
		$map = array();
		foreach ( MDT_Toolkit::modules() as $id => $module ) {
			$map[ $id ] = in_array( $id, $posted, true );
		}
		update_option( MDT_Toolkit::OPTION, $map );
		set_transient( 'mdt_tk_flash_' . get_current_user_id(), __( 'Modules saved.', 'mim-dollar-toolkit' ), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::tab_url( 'modules' ) );
		exit;
	}

	/**
	 * Delete the options of earlier plugin versions that the migration copied.
	 */
	public static function handle_remove_legacy() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mdt_tk_remove_legacy' );

		$n = MDT_Migration::remove_legacy_options();
		/* translators: %d: number of options */
		set_transient( 'mdt_tk_flash_' . get_current_user_id(), sprintf( __( 'Legacy data removed (%d options).', 'mim-dollar-toolkit' ), $n ), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::tab_url( 'modules' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}

		$enabled = MDT_Toolkit::enabled_modules();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'modules';
		if ( 'modules' !== $tab && ! isset( $enabled[ $tab ] ) ) {
			$tab = 'modules';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'MiM Dollar Toolkit', 'mim-dollar-toolkit' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( self::tab_url( 'modules' ) ) . '" class="nav-tab' . ( 'modules' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Modules', 'mim-dollar-toolkit' ) . '</a>';
		foreach ( $enabled as $id => $module ) {
			echo '<a href="' . esc_url( self::tab_url( $id ) ) . '" class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $module->title() ) . '</a>';
		}
		echo '</nav>';

		if ( 'modules' === $tab ) {
			self::render_modules_tab();
		} else {
			$enabled[ $tab ]->render_settings_tab();
		}

		echo '</div>';
	}

	private static function render_modules_tab() {
		$flash_key = 'mdt_tk_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $flash ) . '</p></div>';
		}

		$map = MDT_Toolkit::enabled_map();

		echo '<h2>' . esc_html__( 'Modules', 'mim-dollar-toolkit' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_tk_save_modules" />';
		wp_nonce_field( 'mdt_tk_save_modules' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( MDT_Toolkit::modules() as $id => $module ) {
			echo '<tr><th scope="row">' . esc_html( $module->title() ) . '</th><td>';
			echo '<label><input type="checkbox" name="mdt_modules[]" value="' . esc_attr( $id ) . '" ' . checked( ! empty( $map[ $id ] ), true, false ) . ' /> ' . esc_html__( 'Enabled', 'mim-dollar-toolkit' ) . '</label>';
			echo '<p class="description">' . esc_html( $module->description() ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save modules', 'mim-dollar-toolkit' ) );
		echo '</form>';

		if ( MDT_Migration::legacy_options_remain() ) {
			echo '<h2>' . esc_html__( 'Legacy data', 'mim-dollar-toolkit' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Settings from the old plugin were copied to this plugin. Once you have confirmed everything works, you can delete the old copies. This cannot be undone.', 'mim-dollar-toolkit' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="mdt_tk_remove_legacy" />';
			wp_nonce_field( 'mdt_tk_remove_legacy' );
			submit_button( __( 'Remove legacy data', 'mim-dollar-toolkit' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Delete the old plugin settings? This cannot be undone.', 'mim-dollar-toolkit' ) ) . "');" ) );
			echo '</form>';
		}
	}
}
