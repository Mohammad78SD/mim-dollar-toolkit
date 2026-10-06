<?php
/**
 * SMS provider contract and registry.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

interface WTT_SMS_Provider {

	/**
	 * Send one pattern (template) SMS to one recipient.
	 *
	 * @param string $to     Recipient, canonical E.164 (+989xxxxxxxxx); each provider formats it as its API needs.
	 * @param string $code   Pattern code.
	 * @param array  $params Pattern variables name => value.
	 * @return array {ok:bool, id:?string, error:?string, retry:bool (optional: worth retrying), code:?string}
	 */
	public function send_pattern( $to, $code, array $params );

	// Optional: public function check() returning {ok:bool, error:?string, balance:mixed, lines:string[]}; call via method_exists().
}

class WTT_SMS_Providers {

	/**
	 * Registered providers: id => array( 'label' => string, 'class' => class name ).
	 * The class constructor receives the settings array (api_key, sender, ...).
	 *
	 * @return array
	 */
	public static function all() {
		$providers = array(
			'farazsms'     => array(
				'label' => __( 'FarazSMS (IranPayamak)', 'woo-toman-toolkit' ),
				'class' => 'WTT_SMS_Provider_FarazSMS',
			),
			'ippanel_edge' => array(
				'label' => __( 'IPPanel Edge (legacy)', 'woo-toman-toolkit' ),
				'class' => 'WTT_SMS_Provider_IPPanel_Edge',
			),
		);
		$providers = apply_filters( 'wtt_sms_providers', $providers );
		return is_array( $providers ) ? $providers : array();
	}

	/**
	 * id => label.
	 *
	 * @return array
	 */
	public static function labels() {
		$out = array();
		foreach ( self::all() as $id => $def ) {
			$out[ sanitize_key( $id ) ] = isset( $def['label'] ) ? (string) $def['label'] : (string) $id;
		}
		return $out;
	}

	/**
	 * Instance for the configured provider.
	 *
	 * @param array|null $settings Settings, defaults to the saved ones.
	 * @return WTT_SMS_Provider|null
	 */
	public static function make( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : WTT_SMS_Settings::all();
		$all      = self::all();
		$id       = isset( $settings['provider'] ) ? $settings['provider'] : 'farazsms';
		if ( ! isset( $all[ $id ]['class'] ) || ! class_exists( $all[ $id ]['class'] ) ) {
			return null;
		}
		$provider = new $all[ $id ]['class']( $settings );
		return $provider instanceof WTT_SMS_Provider ? $provider : null;
	}
}
