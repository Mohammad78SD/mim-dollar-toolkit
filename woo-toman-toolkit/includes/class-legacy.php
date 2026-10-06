<?php
/**
 * Backward-compatibility shims for the earlier internal toolkit plugin (prefix azk_).
 *
 * All compat code lives in this single file so it can be deleted once old links/shortcodes are gone:
 *  - the old shortcode [azk_usd_rate] keeps working as an alias of [wtt_usd_rate];
 *  - old pre-invoice links already sent in emails (/?azk-pi=ID&key=KEY) 301-redirect to the new /?wtt-pi=ID&key=KEY.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_Legacy {

	/** Old query var of the printable pre-invoice page. */
	const OLD_QUERY_VAR = 'azk-pi';

	/** Old shortcode tag. */
	const OLD_SHORTCODE = 'azk_usd_rate';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_preinvoice_url' ), 0 );
		add_action( 'init', array( __CLASS__, 'register_old_shortcode' ), 30 );
	}

	/**
	 * [azk_usd_rate] -> [wtt_usd_rate] (only when the new shortcode exists, i.e. the USD module is enabled).
	 */
	public static function register_old_shortcode() {
		if ( shortcode_exists( 'wtt_usd_rate' ) && ! shortcode_exists( self::OLD_SHORTCODE ) ) {
			add_shortcode( self::OLD_SHORTCODE, array( __CLASS__, 'shortcode_alias' ) );
		}
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_alias( $atts ) {
		return WTT_USD_Plugin::shortcode_rate( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * /?azk-pi=ID&key=KEY -> 301 to /?wtt-pi=ID&key=KEY.
	 */
	public static function redirect_old_preinvoice_url() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability URL secured by the order key; we only redirect.
		if ( ! isset( $_GET[ self::OLD_QUERY_VAR ] ) || ! class_exists( 'WTT_PI_Page' ) ) {
			return;
		}
		$args = array( WTT_PI_Page::QUERY_VAR => absint( wp_unslash( $_GET[ self::OLD_QUERY_VAR ] ) ) );
		if ( isset( $_GET['key'] ) ) {
			$args['key'] = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}
		// phpcs:enable
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( add_query_arg( $args, home_url( '/' ) ), 301 );
		exit;
	}
}
