<?php
/**
 * My Account (logged-out): OTP login form above the WooCommerce login form.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Login_Form {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'render' ) );
	}

	private static function active() {
		return function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() && WTT_ACC_OTP::available();
	}

	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'wtt-acc-otp', WTT_TK_URL . 'modules/accounts/assets/otp.css', array(), WTT_TK_VERSION );
		wp_enqueue_script( 'wtt-acc-otp', WTT_TK_URL . 'modules/accounts/assets/otp.js', array(), WTT_TK_VERSION, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect target is validated server side in the verify endpoint.
		$redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		wp_localize_script(
			'wtt-acc-otp',
			'wttAccOtp',
			array(
				'restUrl'      => esc_url_raw( rest_url( WTT_ACC_REST::NS . '/otp/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'redirect'     => $redirect,
				'codeLength'   => (int) WTT_ACC_Settings::get( 'code_length' ),
				'cooldown'     => (int) WTT_ACC_Settings::get( 'cooldown' ),
				'isFa'         => function_exists( 'wtt_is_fa_locale' ) && wtt_is_fa_locale(),
				'showPassword' => (bool) WTT_ACC_Settings::get( 'show_password' ),
				'i18n'         => array(
					'resend'    => __( 'Resend code', 'woo-toman-toolkit' ),
					'resendIn'  => __( 'Resend code in %s seconds', 'woo-toman-toolkit' ),
					'network'   => __( 'Connection problem. Please try again.', 'woo-toman-toolkit' ),
					'badMobile' => __( 'Please enter a valid Iranian mobile number, for example 09123456789.', 'woo-toman-toolkit' ),
					'badCode'   => __( 'Please enter the code you received.', 'woo-toman-toolkit' ),
					'sending'   => __( 'Please wait...', 'woo-toman-toolkit' ),
					'hidePw'    => __( 'Hide password login', 'woo-toman-toolkit' ),
					'showPw'    => __( 'Log in with password', 'woo-toman-toolkit' ),
				),
			)
		);
	}

	public static function render() {
		if ( ! self::active() ) {
			return;
		}
		$len     = (int) WTT_ACC_Settings::get( 'code_length' );
		$wc_btn  = function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '';
		$btn_cls = 'woocommerce-button button wp-element-button' . ( $wc_btn && 'wp-element-button' !== $wc_btn ? ' ' . $wc_btn : '' );
		?>
		<div class="wtt-acc-otp" id="wtt-acc-otp" hidden>
			<h2><?php esc_html_e( 'Log in with your mobile number', 'woo-toman-toolkit' ); ?></h2>
			<p class="wtt-acc-msg" role="status" aria-live="polite"></p>

			<form class="wtt-acc-step wtt-acc-step-mobile" method="post" novalidate>
				<p class="woocommerce-form-row form-row">
					<label for="wtt-acc-mobile"><?php esc_html_e( 'Mobile number', 'woo-toman-toolkit' ); ?></label>
					<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" id="wtt-acc-mobile" name="mobile" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="09123456789" maxlength="20" />
				</p>
				<p class="form-row"><button type="submit" class="<?php echo esc_attr( $btn_cls ); ?> wtt-acc-send"><?php esc_html_e( 'Send code', 'woo-toman-toolkit' ); ?></button></p>
			</form>

			<form class="wtt-acc-step wtt-acc-step-code" method="post" novalidate hidden>
				<p class="wtt-acc-sent-to"></p>
				<p class="woocommerce-form-row form-row">
					<label for="wtt-acc-code"><?php esc_html_e( 'Verification code', 'woo-toman-toolkit' ); ?></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" id="wtt-acc-code" name="code" dir="ltr" style="text-align:left" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="<?php echo esc_attr( (string) $len ); ?>" />
				</p>
				<p class="form-row"><button type="submit" class="<?php echo esc_attr( $btn_cls ); ?> woocommerce-form-login__submit wtt-acc-verify"><?php esc_html_e( 'Log in', 'woo-toman-toolkit' ); ?></button></p>
				<p class="wtt-acc-links">
					<button type="button" class="wtt-acc-link wtt-acc-change"><?php esc_html_e( 'Change number', 'woo-toman-toolkit' ); ?></button>
					<button type="button" class="wtt-acc-link wtt-acc-resend" disabled></button>
				</p>
			</form>
			<noscript><p><?php esc_html_e( 'JavaScript is required for login with a code. You can log in with your password below.', 'woo-toman-toolkit' ); ?></p></noscript>
		</div>
		<p class="wtt-acc-pw-toggle-wrap" hidden><button type="button" class="wtt-acc-link wtt-acc-pw-toggle" aria-expanded="false"></button></p>
		<?php
	}
}
