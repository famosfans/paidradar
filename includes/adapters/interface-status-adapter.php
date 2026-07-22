<?php
/**
 * Gateway status adapter contract.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only adapter that maps a gateway's payment state onto a
 * normalized {@see Payment_Status} value object.
 */
interface Status_Adapter {

	/**
	 * Whether this adapter handles the given WooCommerce payment method id.
	 *
	 * @param string $payment_method_id e.g. 'stripe', 'ppcp-gateway'.
	 * @return bool
	 */
	public function supports( string $payment_method_id ): bool;

	/**
	 * Re-query the gateway (read-only) and return the normalized status.
	 *
	 * Implementations must never move money and must degrade to
	 * {@see Payment_Status::UNKNOWN} on missing config or transport errors.
	 *
	 * @param \WC_Order $order The order to check.
	 * @return Payment_Status
	 */
	public function fetch_status( \WC_Order $order ): Payment_Status;

	/**
	 * Gateway slug used for audit rows (e.g. 'stripe', 'paypal').
	 *
	 * @return string
	 */
	public function gateway_slug(): string;
}
