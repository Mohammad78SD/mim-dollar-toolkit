<?php
/**
 * Mobile field on the user profile (admin), Users list column and My Account > Account details.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Profile {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_details_row' ) );
	}

	/**
	 * @param WP_User $user Edited user.
	 */
	public static function render_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) || ! $user instanceof WP_User ) {
			return;
		}
		$mobile = (string) get_user_meta( $user->ID, WTT_ACC_Users::META_MOBILE, true );
		wp_nonce_field( 'wtt_acc_profile_' . $user->ID, 'wtt_acc_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Login with mobile', 'woo-toman-toolkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wtt_acc_mobile"><?php esc_html_e( 'Mobile (OTP login)', 'woo-toman-toolkit' ); ?></label></th>
				<td>
					<input type="text" dir="ltr" class="regular-text" name="wtt_acc_mobile" id="wtt_acc_mobile" value="<?php echo esc_attr( $mobile ); ?>" placeholder="09123456789" />
					<p class="description"><?php esc_html_e( 'The customer can log in to My Account with a code sent to this number. Must be unique. Accounts with editor/shop manager/admin rights can never log in with a code.', 'woo-toman-toolkit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Raw submitted value, or null when our field was not part of an authorised submission.
	 *
	 * @param int $user_id User being saved.
	 * @return string|null
	 */
	private static function submitted( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return null;
		}
		if ( ! isset( $_POST['wtt_acc_profile_nonce'], $_POST['wtt_acc_mobile'] ) || ! is_string( $_POST['wtt_acc_profile_nonce'] ) || ! is_string( $_POST['wtt_acc_mobile'] ) ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wtt_acc_profile_nonce'] ) ), 'wtt_acc_profile_' . $user_id ) ) {
			return null;
		}
		return trim( sanitize_text_field( wp_unslash( $_POST['wtt_acc_mobile'] ) ) );
	}

	/**
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Updating an existing user.
	 * @param stdClass $user   User object being saved.
	 */
	public static function validate( $errors, $update, $user ) {
		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;
		if ( ! $update || ! $user_id ) {
			return;
		}
		$raw = self::submitted( $user_id );
		if ( null === $raw || '' === $raw ) {
			return;
		}
		$mobile = WTT_ACC_Users::normalize_mobile( $raw );
		if ( '' === $mobile ) {
			$errors->add( 'wtt_acc_mobile', __( '<strong>Error:</strong> the mobile number is not a valid Iranian mobile.', 'woo-toman-toolkit' ) );
		} elseif ( WTT_ACC_Users::mobile_taken( $mobile, $user_id ) ) {
			$errors->add( 'wtt_acc_mobile', __( '<strong>Error:</strong> another user already has this mobile number.', 'woo-toman-toolkit' ) );
		}
	}

	/**
	 * @param int $user_id User id.
	 */
	public static function save( $user_id ) {
		$user_id = (int) $user_id;
		$raw     = self::submitted( $user_id );
		if ( null === $raw ) {
			return;
		}
		$old = (string) get_user_meta( $user_id, WTT_ACC_Users::META_MOBILE, true );
		if ( '' === $raw ) {
			delete_user_meta( $user_id, WTT_ACC_Users::META_MOBILE );
			if ( '' !== $old ) {
				update_user_meta( $user_id, WTT_ACC_OTP::META_VERIFIED, '0' );
			}
			return;
		}
		$mobile = WTT_ACC_Users::normalize_mobile( $raw );
		if ( '' !== $mobile && ! WTT_ACC_Users::mobile_taken( $mobile, $user_id ) ) {
			update_user_meta( $user_id, WTT_ACC_Users::META_MOBILE, $mobile );
			if ( $mobile !== $old ) {
				update_user_meta( $user_id, WTT_ACC_OTP::META_VERIFIED, '0' );
			}
		}
	}

	public static function column( $columns ) {
		$columns['wtt_mobile'] = __( 'Mobile', 'woo-toman-toolkit' );
		return $columns;
	}

	public static function column_value( $value, $column, $user_id ) {
		if ( 'wtt_mobile' !== $column ) {
			return $value;
		}
		$mobile = (string) get_user_meta( (int) $user_id, WTT_ACC_Users::META_MOBILE, true );
		return '' === $mobile ? '&mdash;' : '<span dir="ltr">' . esc_html( $mobile ) . '</span>';
	}

	public static function account_details_row() {
		$mobile = (string) get_user_meta( get_current_user_id(), WTT_ACC_Users::META_MOBILE, true );
		if ( '' === $mobile ) {
			return;
		}
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="wtt_acc_mobile_ro"><?php esc_html_e( 'Mobile number', 'woo-toman-toolkit' ); ?></label>
			<input type="text" class="woocommerce-Input input-text" id="wtt_acc_mobile_ro" dir="ltr" value="<?php echo esc_attr( $mobile ); ?>" readonly="readonly" disabled="disabled" />
			<span><em><?php esc_html_e( 'This is the number you log in with. To change it, please contact us.', 'woo-toman-toolkit' ); ?></em></span>
		</p>
		<?php
	}
}
