<?php
/**
 * Rate providers (USDT -> Toman).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider contract.
 */
interface WTT_USD_Rate_Provider {

	/**
	 * Machine name (slug).
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Fetch Toman per 1 USDT.
	 *
	 * @return float|WP_Error
	 */
	public function fetch();
}

/**
 * Shared HTTP helpers.
 */
abstract class WTT_USD_Provider_Base implements WTT_USD_Rate_Provider {

	/**
	 * GET a JSON endpoint.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	protected function request_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WooTomanToolkit/' . WTT_USD_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'wtt_usd_http', sprintf( __( 'HTTP status %d', 'woo-toman-toolkit' ), $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wtt_usd_json', __( 'Invalid JSON response', 'woo-toman-toolkit' ) );
		}
		return $data;
	}

	/**
	 * Validate and convert a value to a positive float.
	 *
	 * @param mixed $value   Raw value.
	 * @param float $divisor Divide by (e.g. 10 for Rial -> Toman).
	 * @return float|WP_Error
	 */
	protected function validate( $value, $divisor = 1 ) {
		if ( is_string( $value ) ) {
			$value = str_replace( ',', '', trim( $value ) );
		}
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return new WP_Error( 'wtt_usd_value', __( 'Missing or invalid price in response', 'woo-toman-toolkit' ) );
		}
		return (float) $value / $divisor;
	}
}

class WTT_USD_Provider_Bitpin extends WTT_USD_Provider_Base {

	public function get_name() {
		return 'bitpin';
	}

	public function get_label() {
		return 'Bitpin';
	}

	public function fetch() {
		$data = $this->request_json( 'https://api.bitpin.org/api/v1/mkt/tickers/' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			$data = $data['results'];
		}
		foreach ( $data as $item ) {
			if ( is_array( $item ) && isset( $item['symbol'], $item['price'] ) && 'USDT_IRT' === $item['symbol'] ) {
				return $this->validate( $item['price'] );
			}
		}
		return new WP_Error( 'wtt_usd_missing', __( 'USDT_IRT market not found', 'woo-toman-toolkit' ) );
	}
}

class WTT_USD_Provider_Nobitex extends WTT_USD_Provider_Base {

	public function get_name() {
		return 'nobitex';
	}

	public function get_label() {
		return 'Nobitex';
	}

	public function fetch() {
		$data = $this->request_json( 'https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! isset( $data['stats']['usdt-rls']['latest'] ) ) {
			return new WP_Error( 'wtt_usd_missing', __( 'usdt-rls stats not found', 'woo-toman-toolkit' ) );
		}
		// Nobitex reports Rial; convert to Toman.
		return $this->validate( $data['stats']['usdt-rls']['latest'], 10 );
	}
}

class WTT_USD_Provider_Wallex extends WTT_USD_Provider_Base {

	public function get_name() {
		return 'wallex';
	}

	public function get_label() {
		return 'Wallex';
	}

	public function fetch() {
		$data = $this->request_json( 'https://api.wallex.ir/v1/markets' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! isset( $data['result']['symbols']['USDTTMN']['stats']['lastPrice'] ) ) {
			return new WP_Error( 'wtt_usd_missing', __( 'USDTTMN stats not found', 'woo-toman-toolkit' ) );
		}
		return $this->validate( $data['result']['symbols']['USDTTMN']['stats']['lastPrice'] );
	}
}

/**
 * Provider registry.
 */
class WTT_USD_Rate_Providers {

	/**
	 * All registered providers keyed by name.
	 *
	 * @return WTT_USD_Rate_Provider[]
	 */
	public static function get_all() {
		$providers = array(
			new WTT_USD_Provider_Bitpin(),
			new WTT_USD_Provider_Nobitex(),
			new WTT_USD_Provider_Wallex(),
		);
		$providers = apply_filters( 'wtt_usd_rate_providers', $providers );

		$out = array();
		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider ) {
				if ( $provider instanceof WTT_USD_Rate_Provider ) {
					$out[ $provider->get_name() ] = $provider;
				}
			}
		}
		return $out;
	}

	/**
	 * Providers enabled in settings.
	 *
	 * @return WTT_USD_Rate_Provider[]
	 */
	public static function get_enabled() {
		$enabled = (array) WTT_USD_Settings::get( 'providers' );
		$out     = array();
		foreach ( self::get_all() as $name => $provider ) {
			if ( in_array( $name, $enabled, true ) ) {
				$out[ $name ] = $provider;
			}
		}
		return $out;
	}
}
