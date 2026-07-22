<?php
/**
 * Stripe read-only status adapter.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Re-queries the Stripe PaymentIntent for an order and maps its
 * status onto a {@see Payment_Status}. Uses wp_remote_get so it works
 * without the stripe-php SDK / Composer.
 */
class Stripe_Adapter implements Status_Adapter {

	const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $payment_method_id Payment method id.
	 * @return bool
	 */
	public function supports( string $payment_method_id ): bool {
		return in_array( $payment_method_id, array( 'stripe', 'stripe_cc' ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function gateway_slug(): string {
		return 'stripe';
	}

	/**
	 * Resolve the active Stripe secret key from WC-Stripe settings.
	 *
	 * @return string|null Secret key or null when unavailable.
	 */
	protected function get_secret_key(): ?string {
		$settings = get_option( 'woocommerce_stripe_settings' );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$testmode = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
		$key      = $testmode
			? ( $settings['test_secret_key'] ?? '' )
			: ( $settings['secret_key'] ?? '' );

		$key = is_string( $key ) ? trim( $key ) : '';

		return '' !== $key ? $key : null;
	}

	/**
	 * Extract the PaymentIntent id from the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null
	 */
	protected function get_intent_id( \WC_Order $order ): ?string {
		$txn = $order->get_transaction_id();
		if ( is_string( $txn ) && 0 === strpos( $txn, 'pi_' ) ) {
			return $txn;
		}

		$meta = $order->get_meta( '_stripe_intent_id' );
		if ( is_string( $meta ) && '' !== $meta ) {
			return $meta;
		}

		// Fall back to the raw transaction id if present (charge id etc.).
		return ( is_string( $txn ) && '' !== $txn ) ? $txn : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Order $order Order.
	 * @return Payment_Status
	 */
	public function fetch_status( \WC_Order $order ): Payment_Status {
		$secret = $this->get_secret_key();
		if ( null === $secret ) {
			return Payment_Status::unknown( array( 'error' => 'missing_stripe_secret_key' ) );
		}

		$intent_id = $this->get_intent_id( $order );
		if ( null === $intent_id || 0 !== strpos( $intent_id, 'pi_' ) ) {
			return Payment_Status::unknown( array( 'error' => 'no_payment_intent_id' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . '/payment_intents/' . rawurlencode( $intent_id ) . '?expand[]=latest_charge',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $secret,
					'Stripe-Version' => '2023-10-16',
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
			return Payment_Status::unknown( array( 'error' => 'stripe_http_' . $code, 'body' => $body ) );
		}

		return $this->map( $body );
	}

	/**
	 * Map a PaymentIntent payload onto a normalized status.
	 *
	 * @param array<string,mixed> $intent PaymentIntent payload.
	 * @return Payment_Status
	 */
	public function map( array $intent ): Payment_Status {
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$txn_id = isset( $intent['id'] ) ? (string) $intent['id'] : null;

		$charge = array();
		if ( isset( $intent['latest_charge'] ) && is_array( $intent['latest_charge'] ) ) {
			$charge = $intent['latest_charge'];
		}

		// Dispute takes precedence over anything else.
		if ( ! empty( $charge['disputed'] ) ) {
			return new Payment_Status( Payment_Status::DISPUTED, $intent, $txn_id );
		}

		// Refund detection on the latest charge.
		if ( ! empty( $charge['refunded'] ) || ( isset( $charge['amount_refunded'] ) && (int) $charge['amount_refunded'] > 0 ) ) {
			return new Payment_Status( Payment_Status::REFUNDED, $intent, $txn_id );
		}

		switch ( $status ) {
			case 'succeeded':
				return new Payment_Status( Payment_Status::PAID, $intent, $txn_id );
			case 'canceled':
			case 'requires_payment_method':
				return new Payment_Status( Payment_Status::UNPAID, $intent, $txn_id );
			default:
				// requires_action / processing / requires_capture / requires_confirmation.
				return new Payment_Status( Payment_Status::UNKNOWN, $intent, $txn_id );
		}
	}
}
