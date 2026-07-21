<?php
/**
 * PayPal (PPCP) read-only status adapter.
 *
 * @package OrderMend
 */

namespace OrderMend\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Re-queries the PayPal Orders API v2 for an order and maps its
 * status onto a {@see Payment_Status}. Uses wp_remote_* only.
 */
class PayPal_Adapter implements Status_Adapter {

	const LIVE_BASE    = 'https://api-m.paypal.com';
	const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

	const TOKEN_TRANSIENT = 'ordermend_ppcp_token';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $payment_method_id Payment method id.
	 * @return bool
	 */
	public function supports( string $payment_method_id ): bool {
		return in_array( $payment_method_id, array( 'ppcp-gateway', 'paypal' ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function gateway_slug(): string {
		return 'paypal';
	}

	/**
	 * Read PPCP settings.
	 *
	 * @return array{client_id:string,secret:string,sandbox:bool}|null
	 */
	protected function get_credentials(): ?array {
		$settings = get_option( 'woocommerce-ppcp-settings' );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$sandbox = isset( $settings['sandbox_on'] ) && ( 'yes' === $settings['sandbox_on'] || true === $settings['sandbox_on'] );

		if ( $sandbox ) {
			$client_id = $settings['client_id_sandbox'] ?? ( $settings['client_id'] ?? '' );
			$secret    = $settings['client_secret_sandbox'] ?? ( $settings['client_secret'] ?? '' );
		} else {
			$client_id = $settings['client_id_production'] ?? ( $settings['client_id'] ?? '' );
			$secret    = $settings['client_secret_production'] ?? ( $settings['client_secret'] ?? '' );
		}

		$client_id = is_string( $client_id ) ? trim( $client_id ) : '';
		$secret    = is_string( $secret ) ? trim( $secret ) : '';

		if ( '' === $client_id || '' === $secret ) {
			return null;
		}

		return array(
			'client_id' => $client_id,
			'secret'    => $secret,
			'sandbox'   => $sandbox,
		);
	}

	/**
	 * API base for the current mode.
	 *
	 * @param bool $sandbox Sandbox flag.
	 * @return string
	 */
	protected function api_base( bool $sandbox ): string {
		return $sandbox ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Obtain (and cache) an OAuth bearer token.
	 *
	 * @param array{client_id:string,secret:string,sandbox:bool} $creds Credentials.
	 * @return string|null Access token or null on failure.
	 */
	protected function get_access_token( array $creds ): ?string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->api_base( $creds['sandbox'] ) . '/v1/oauth2/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $creds['client_id'] . ':' . $creds['secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return null;
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3000;
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires - 120 ) );

		return $token;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order.
	 * @return Payment_Status
	 */
	public function fetch_status( \WC_Order $order ): Payment_Status {
		$creds = $this->get_credentials();
		if ( null === $creds ) {
			return Payment_Status::unknown( array( 'error' => 'missing_ppcp_credentials' ) );
		}

		$order_id = $order->get_transaction_id();
		if ( ! is_string( $order_id ) || '' === $order_id ) {
			$order_id = (string) $order->get_meta( '_ppcp_paypal_order_id' );
		}
		if ( '' === $order_id ) {
			return Payment_Status::unknown( array( 'error' => 'no_paypal_order_id' ) );
		}

		$token = $this->get_access_token( $creds );
		if ( null === $token ) {
			return Payment_Status::unknown( array( 'error' => 'oauth_failed' ) );
		}

		$response = wp_remote_get(
			$this->api_base( $creds['sandbox'] ) . '/v2/checkout/orders/' . rawurlencode( $order_id ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return Payment_Status::unknown( array( 'error' => $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			// Soft backoff: skip this run.
			return Payment_Status::unknown( array( 'error' => 'rate_limited', 'http' => 429 ) );
		}

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return Payment_Status::unknown( array( 'error' => 'paypal_http_' . $code, 'body' => $body ) );
		}

		return $this->map( $body );
	}

	/**
	 * Map a PayPal Orders v2 payload onto a normalized status.
	 *
	 * @param array<string,mixed> $order_data Orders API payload.
	 * @return Payment_Status
	 */
	public function map( array $order_data ): Payment_Status {
		$status = isset( $order_data['status'] ) ? strtoupper( (string) $order_data['status'] ) : '';
		$txn_id = isset( $order_data['id'] ) ? (string) $order_data['id'] : null;

		// Inspect the capture where available (more authoritative than order status).
		$capture = $this->first_capture( $order_data );
		if ( is_array( $capture ) ) {
			$cap_status = isset( $capture['status'] ) ? strtoupper( (string) $capture['status'] ) : '';
			if ( isset( $capture['id'] ) ) {
				$txn_id = (string) $capture['id'];
			}

			if ( 'REFUNDED' === $cap_status || 'PARTIALLY_REFUNDED' === $cap_status ) {
				return new Payment_Status( Payment_Status::REFUNDED, $order_data, $txn_id );
			}
			if ( 'COMPLETED' === $cap_status ) {
				return new Payment_Status( Payment_Status::PAID, $order_data, $txn_id );
			}
			if ( in_array( $cap_status, array( 'DECLINED', 'FAILED', 'VOIDED' ), true ) ) {
				return new Payment_Status( Payment_Status::UNPAID, $order_data, $txn_id );
			}
		}

		switch ( $status ) {
			case 'COMPLETED':
				return new Payment_Status( Payment_Status::PAID, $order_data, $txn_id );
			case 'VOIDED':
			case 'DECLINED':
				return new Payment_Status( Payment_Status::UNPAID, $order_data, $txn_id );
			default:
				// CREATED / SAVED / APPROVED / PAYER_ACTION_REQUIRED.
				return new Payment_Status( Payment_Status::UNKNOWN, $order_data, $txn_id );
		}
	}

	/**
	 * Extract the first capture object from an Orders v2 payload.
	 *
	 * @param array<string,mixed> $order_data Payload.
	 * @return array<string,mixed>|null
	 */
	protected function first_capture( array $order_data ): ?array {
		if ( empty( $order_data['purchase_units'][0]['payments']['captures'][0] ) ) {
			return null;
		}
		$capture = $order_data['purchase_units'][0]['payments']['captures'][0];
		return is_array( $capture ) ? $capture : null;
	}
}
