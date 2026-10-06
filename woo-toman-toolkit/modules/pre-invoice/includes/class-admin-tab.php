<?php
/**
 * Pre-invoice settings tab (WooCommerce > Toman Toolkit > Pre-invoice).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Admin_Tab {

	const TAB = 'pre-invoice';

	public static function init() {
		add_action( 'admin_post_wtt_pi_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function tab_url() {
		return admin_url( 'admin.php?page=wtt-toolkit&tab=' . self::TAB );
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'wtt-toolkit' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		if ( ! isset( $_GET['tab'] ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'wtt-pi-admin', WTT_TK_URL . 'modules/pre-invoice/assets/admin.js', array( 'jquery' ), WTT_TK_VERSION, true );
		wp_localize_script(
			'wtt-pi-admin',
			'wttPiAdmin',
			array(
				'choose' => __( 'Choose image', 'woo-toman-toolkit' ),
				'use'    => __( 'Use this image', 'woo-toman-toolkit' ),
			)
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-toman-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wtt_pi_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in WTT_PI_Settings::sanitize().
		$raw = isset( $_POST['wtt_pi'] ) && is_array( $_POST['wtt_pi'] ) ? wp_unslash( $_POST['wtt_pi'] ) : array();
		update_option( WTT_PI_Settings::OPTION, WTT_PI_Settings::sanitize( $raw ) );
		set_transient( 'wtt_pi_flash_' . get_current_user_id(), __( 'Settings saved.', 'woo-toman-toolkit' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-toman-toolkit' ), '', array( 'response' => 403 ) );
		}
		$flash_key = 'wtt_pi_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $flash ) . '</p></div>';
		}

		$values = WTT_PI_Settings::all();
		$groups = array(
			'shop'  => __( 'Shop information', 'woo-toman-toolkit' ),
			'flow'  => __( 'Behaviour', 'woo-toman-toolkit' ),
			'texts' => __( 'Texts', 'woo-toman-toolkit' ),
		);

		echo '<h2>' . esc_html__( 'Pre-invoice', 'woo-toman-toolkit' ) . '</h2>';
		$gw_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . WTT_PI_Settings::GATEWAY_ID );
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to gateway settings */
				__( 'Gateway title, description and button text are in %s. Emails are in WooCommerce &rarr; Settings &rarr; Emails.', 'woo-toman-toolkit' ),
				'<a href="' . esc_url( $gw_url ) . '">' . esc_html__( 'WooCommerce payment settings', 'woo-toman-toolkit' ) . '</a>'
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wtt_pi_save" />';
		wp_nonce_field( 'wtt_pi_save' );

		foreach ( $groups as $gid => $glabel ) {
			echo '<h3>' . esc_html( $glabel ) . '</h3><table class="form-table" role="presentation"><tbody>';
			foreach ( WTT_PI_Settings::fields() as $key => $def ) {
				if ( $def[3] !== $gid ) {
					continue;
				}
				self::row( $key, $def, $values[ $key ] );
			}
			echo '</tbody></table>';
		}
		submit_button( __( 'Save settings', 'woo-toman-toolkit' ) );
		echo '</form>';

		self::render_log();
	}

	private static function row( $key, $def, $value ) {
		$name = 'wtt_pi[' . $key . ']';
		$id   = 'wtt_pi_' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $def[1] ) . '</label></th><td>';
		switch ( $def[0] ) {
			case 'checkbox':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . ' /> ' . esc_html__( 'Yes', 'woo-toman-toolkit' ) . '</label>';
				break;
			case 'textarea':
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( str_replace( '<br />', "\n", (string) $value ) ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" min="0" step="1" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
				break;
			case 'media':
				$url = $value ? wp_get_attachment_image_url( (int) $value, 'thumbnail' ) : '';
				echo '<div class="wtt-pi-media">';
				echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '" />';
				echo '<div class="wtt-pi-media-preview" style="margin-bottom:6px">' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="" style="max-width:120px;height:auto" />' : '' ) . '</div>';
				echo '<button type="button" class="button wtt-pi-media-pick">' . esc_html__( 'Choose image', 'woo-toman-toolkit' ) . '</button> ';
				echo '<button type="button" class="button wtt-pi-media-clear">' . esc_html__( 'Remove', 'woo-toman-toolkit' ) . '</button>';
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
		$log = WTT_Logger::all( 'pre-invoice' );
		echo '<h3>' . esc_html__( 'Log', 'woo-toman-toolkit' ) . '</h3>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'woo-toman-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( array_slice( $log, 0, 20 ) as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td><td>' . esc_html( WTT_Logger::level_label( $entry['level'] ) ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
