<?php
/**
 * Backward-compatibility shims for earlier internal versions of this plugin (prefixes azk_ and wtt_).
 *
 * All compat code lives in this single file so it can be deleted once old links/shortcodes are gone:
 *  - the old shortcodes [azk_usd_rate] and [wtt_usd_rate] keep working as aliases of [mdt_usd_rate];
 *  - old pre-invoice links already sent in emails (/?azk-pi=ID&key=KEY or /?wtt-pi=ID&key=KEY) 301-redirect to
 *    the new /?mdt-pi=ID&key=KEY.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_Legacy {

	/** Old query vars of the printable pre-invoice page. */
	const OLD_QUERY_VARS = array( 'azk-pi', 'wtt-pi' );

	/** Old shortcode tags. */
	const OLD_SHORTCODES = array( 'azk_usd_rate', 'wtt_usd_rate' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_preinvoice_url' ), 0 );
		add_action( 'init', array( __CLASS__, 'register_old_shortcodes' ), 30 );
	}

	/**
	 * [azk_usd_rate] / [wtt_usd_rate] -> [mdt_usd_rate] (only when the new shortcode exists, i.e. the USD module is
	 * enabled; an old plugin that is still active keeps its own handler).
	 */
	public static function register_old_shortcodes() {
		if ( ! shortcode_exists( 'mdt_usd_rate' ) ) {
			return;
		}
		foreach ( self::OLD_SHORTCODES as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( __CLASS__, 'shortcode_alias' ) );
			}
		}
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_alias( $atts ) {
		return MDT_USD_Plugin::shortcode_rate( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * /?azk-pi=ID&key=KEY and /?wtt-pi=ID&key=KEY -> 301 to /?mdt-pi=ID&key=KEY.
	 */
	public static function redirect_old_preinvoice_url() {
		if ( ! class_exists( 'MDT_PI_Page' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability URL secured by the order key; we only redirect.
		foreach ( self::OLD_QUERY_VARS as $var ) {
			if ( ! isset( $_GET[ $var ] ) ) {
				continue;
			}
			$args = array( MDT_PI_Page::QUERY_VAR => absint( wp_unslash( $_GET[ $var ] ) ) );
			if ( isset( $_GET['key'] ) ) {
				$args['key'] = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			}
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			wp_safe_redirect( add_query_arg( $args, home_url( '/' ) ), 301 );
			exit;
		}
		// phpcs:enable
	}
}
