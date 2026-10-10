<?php
/**
 * My Account (logged-out): OTP login form above the WooCommerce login form.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_ACC_Login_Form {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'render' ) );
	}

	private static function active() {
		return function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() && MDT_ACC_OTP::available();
	}

	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'mdt-acc-otp', MDT_TK_URL . 'modules/accounts/assets/otp.css', array(), MDT_TK_VERSION );
		wp_enqueue_script( 'mdt-acc-otp', MDT_TK_URL . 'modules/accounts/assets/otp.js', array(), MDT_TK_VERSION, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect target is validated server side in the verify endpoint.
		$redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		wp_localize_script(
			'mdt-acc-otp',
			'mdtAccOtp',
			array(
				'restUrl'      => esc_url_raw( rest_url( MDT_ACC_REST::NS . '/otp/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'redirect'     => $redirect,
				'codeLength'   => (int) MDT_ACC_Settings::get( 'code_length' ),
				'cooldown'     => (int) MDT_ACC_Settings::get( 'cooldown' ),
				'isFa'         => function_exists( 'mdt_is_fa_locale' ) && mdt_is_fa_locale(),
				'showPassword' => (bool) MDT_ACC_Settings::get( 'show_password' ),
				'i18n'         => array(
					'resend'    => __( 'Resend code', 'mim-dollar-toolkit' ),
					'resendIn'  => __( 'Resend code in %s seconds', 'mim-dollar-toolkit' ),
					'network'   => __( 'Connection problem. Please try again.', 'mim-dollar-toolkit' ),
					'badMobile' => __( 'Please enter a valid Iranian mobile number, for example 09123456789.', 'mim-dollar-toolkit' ),
					'badCode'   => __( 'Please enter the code you received.', 'mim-dollar-toolkit' ),
					'sending'   => __( 'Please wait...', 'mim-dollar-toolkit' ),
					'hidePw'    => __( 'Hide password login', 'mim-dollar-toolkit' ),
					'showPw'    => __( 'Log in with password', 'mim-dollar-toolkit' ),
				),
			)
		);
	}

	public static function render() {
		if ( ! self::active() ) {
			return;
		}
		$len     = (int) MDT_ACC_Settings::get( 'code_length' );
		$wc_btn  = function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '';
		$btn_cls = 'woocommerce-button button wp-element-button' . ( $wc_btn && 'wp-element-button' !== $wc_btn ? ' ' . $wc_btn : '' );
		?>
		<div class="mdt-acc-otp" id="mdt-acc-otp" hidden>
			<h2><?php esc_html_e( 'Log in with your mobile number', 'mim-dollar-toolkit' ); ?></h2>
			<p class="mdt-acc-msg" role="status" aria-live="polite"></p>

			<form class="mdt-acc-step mdt-acc-step-mobile" method="post" novalidate>
				<p class="woocommerce-form-row form-row">
					<label for="mdt-acc-mobile"><?php esc_html_e( 'Mobile number', 'mim-dollar-toolkit' ); ?></label>
					<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" id="mdt-acc-mobile" name="mobile" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="09123456789" maxlength="20" />
				</p>
				<p class="form-row"><button type="submit" class="<?php echo esc_attr( $btn_cls ); ?> mdt-acc-send"><?php esc_html_e( 'Send code', 'mim-dollar-toolkit' ); ?></button></p>
			</form>

			<form class="mdt-acc-step mdt-acc-step-code" method="post" novalidate hidden>
				<p class="mdt-acc-sent-to"></p>
				<p class="woocommerce-form-row form-row">
					<label for="mdt-acc-code"><?php esc_html_e( 'Verification code', 'mim-dollar-toolkit' ); ?></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="mdt-acc-code" name="code" dir="ltr" style="text-align:left" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="<?php echo esc_attr( (string) $len ); ?>" />
				</p>
				<p class="form-row"><button type="submit" class="<?php echo esc_attr( $btn_cls ); ?> woocommerce-form-login__submit mdt-acc-verify"><?php esc_html_e( 'Log in', 'mim-dollar-toolkit' ); ?></button></p>
				<p class="mdt-acc-links">
					<button type="button" class="mdt-acc-link mdt-acc-change"><?php esc_html_e( 'Change number', 'mim-dollar-toolkit' ); ?></button>
					<button type="button" class="mdt-acc-link mdt-acc-resend" disabled></button>
				</p>
			</form>
			<noscript><p><?php esc_html_e( 'JavaScript is required for login with a code. You can log in with your password below.', 'mim-dollar-toolkit' ); ?></p></noscript>
		</div>
		<p class="mdt-acc-pw-toggle-wrap" hidden><button type="button" class="mdt-acc-link mdt-acc-pw-toggle" aria-expanded="false"></button></p>
		<?php
	}
}
