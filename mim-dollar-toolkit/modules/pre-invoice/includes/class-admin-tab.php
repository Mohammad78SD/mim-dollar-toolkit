<?php
/**
 * Pre-invoice settings tab (WooCommerce > MiM Dollar Toolkit > Pre-invoice).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Admin_Tab {

	const TAB = 'pre-invoice';

	public static function init() {
		add_action( 'admin_post_mdt_pi_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function tab_url() {
		return admin_url( 'admin.php?page=mdt-toolkit&tab=' . self::TAB );
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'mdt-toolkit' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		if ( ! isset( $_GET['tab'] ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'mdt-pi-admin', MDT_TK_URL . 'modules/pre-invoice/assets/admin.js', array( 'jquery' ), MDT_TK_VERSION, true );
		wp_localize_script(
			'mdt-pi-admin',
			'mdtPiAdmin',
			array(
				'choose' => __( 'Choose image', 'mim-dollar-toolkit' ),
				'use'    => __( 'Use this image', 'mim-dollar-toolkit' ),
			)
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mdt_pi_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in MDT_PI_Settings::sanitize().
		$raw = isset( $_POST['mdt_pi'] ) && is_array( $_POST['mdt_pi'] ) ? wp_unslash( $_POST['mdt_pi'] ) : array();
		update_option( MDT_PI_Settings::OPTION, MDT_PI_Settings::sanitize( $raw ) );
		set_transient( 'mdt_pi_flash_' . get_current_user_id(), __( 'Settings saved.', 'mim-dollar-toolkit' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
		$flash_key = 'mdt_pi_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $flash ) . '</p></div>';
		}

		$values = MDT_PI_Settings::all();
		$groups = array(
			'shop'  => __( 'Shop information', 'mim-dollar-toolkit' ),
			'flow'  => __( 'Behaviour', 'mim-dollar-toolkit' ),
			'texts' => __( 'Texts', 'mim-dollar-toolkit' ),
		);

		echo '<h2>' . esc_html__( 'Pre-invoice', 'mim-dollar-toolkit' ) . '</h2>';
		$gw_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . MDT_PI_Settings::GATEWAY_ID );
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to gateway settings */
				__( 'Gateway title, description and button text are in %s. Emails are in WooCommerce &rarr; Settings &rarr; Emails.', 'mim-dollar-toolkit' ),
				'<a href="' . esc_url( $gw_url ) . '">' . esc_html__( 'WooCommerce payment settings', 'mim-dollar-toolkit' ) . '</a>'
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_pi_save" />';
		wp_nonce_field( 'mdt_pi_save' );

		foreach ( $groups as $gid => $glabel ) {
			echo '<h3>' . esc_html( $glabel ) . '</h3><table class="form-table" role="presentation"><tbody>';
			foreach ( MDT_PI_Settings::fields() as $key => $def ) {
				if ( $def[3] !== $gid ) {
					continue;
				}
				self::row( $key, $def, $values[ $key ] );
			}
			echo '</tbody></table>';
		}
		submit_button( __( 'Save settings', 'mim-dollar-toolkit' ) );
		echo '</form>';

		self::render_log();
	}

	private static function row( $key, $def, $value ) {
		$name = 'mdt_pi[' . $key . ']';
		$id   = 'mdt_pi_' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $def[1] ) . '</label></th><td>';
		switch ( $def[0] ) {
			case 'checkbox':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . ' /> ' . esc_html__( 'Yes', 'mim-dollar-toolkit' ) . '</label>';
				break;
			case 'textarea':
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( str_replace( '<br />', "\n", (string) $value ) ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" min="0" step="1" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
				break;
			case 'media':
				$url = $value ? wp_get_attachment_image_url( (int) $value, 'thumbnail' ) : '';
				echo '<div class="mdt-pi-media">';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '" />';
				echo '<div class="mdt-pi-media-preview" style="margin-bottom:6px">' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="" style="max-width:120px;height:auto" />' : '' ) . '</div>';
				echo '<button type="button" class="button mdt-pi-media-pick">' . esc_html__( 'Choose image', 'mim-dollar-toolkit' ) . '</button> ';
				echo '<button type="button" class="button mdt-pi-media-clear">' . esc_html__( 'Remove', 'mim-dollar-toolkit' ) . '</button>';
				echo '</div>';
				break;
			default:
				$type = in_array( $def[0], array( 'email', 'url' ), true ) ? $def[0] : 'text';
				echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		if ( '' !== $def[2] ) {
			echo '<p class="description">' . esc_html( $def[2] ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function render_log() {
		$log = MDT_Logger::all( 'pre-invoice' );
		echo '<h3>' . esc_html__( 'Log', 'mim-dollar-toolkit' ) . '</h3>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'mim-dollar-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( array_slice( $log, 0, 20 ) as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td><td>' . esc_html( MDT_Logger::level_label( $entry['level'] ) ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
