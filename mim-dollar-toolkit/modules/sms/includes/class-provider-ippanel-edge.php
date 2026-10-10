<?php
/**
 * IPPanel Edge API provider (legacy; FarazSMS now uses its own IranPayamak API).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Provider_IPPanel_Edge implements MDT_SMS_Provider {

	const BASE_URL = 'https://edge.ippanel.com/v1';

	/** @var string */
	private $api_key;

	/** @var string */
	private $sender;

	/**
	 * @param array $settings Plugin settings (api_key, sender).
	 */
	public function __construct( $settings = array() ) {
		$this->api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		$this->sender  = isset( $settings['sender'] ) ? (string) $settings['sender'] : '';
	}

	public function send_pattern( $to, $code, array $params ) {
		$result = array(
			'ok'    => false,
			'id'    => null,
			'error' => null,
			'retry' => false,
			'code'  => null,
		);

		$clean = array();
		foreach ( $params as $k => $v ) {
			$clean[ (string) $k ] = (string) $v;
		}
		$body = array(
			'sending_type' => 'pattern',
			'from_number'  => $this->sender,
			'code'         => (string) $code,
			'recipients'   => array( (string) $to ),
			'params'       => (object) $clean,
		);

		$response = wp_remote_post(
			self::BASE_URL . '/api/send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			$result['retry'] = true;
			return $result;
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$meta = is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		if ( $http >= 200 && $http < 300 && isset( $meta['status'] ) && true === $meta['status'] ) {
			$result['ok'] = true;
			if ( isset( $data['data']['message_outbox_ids'][0] ) ) {
				$result['id'] = (string) $data['data']['message_outbox_ids'][0];
			}
			return $result;
		}

		$message = isset( $meta['message'] ) && is_scalar( $meta['message'] ) ? wp_strip_all_tags( (string) $meta['message'] ) : '';
		$mcode   = isset( $meta['message_code'] ) && is_scalar( $meta['message_code'] ) ? preg_replace( '/[^0-9A-Za-z._-]/', '', (string) $meta['message_code'] ) : '';
		/* translators: 1: provider message, 2: provider message code, 3: HTTP status */
		$result['error'] = sprintf( __( '%1$s (code %2$s, HTTP %3$d)', 'mim-dollar-toolkit' ), '' !== $message ? $message : __( 'Request failed', 'mim-dollar-toolkit' ), '' !== $mcode ? $mcode : '-', $http );
		$result['code']  = $mcode;
		$result['retry'] = ( $http >= 500 );
		return $result;
	}
}
