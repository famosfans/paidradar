<?php
/**
 * Candidate order scanner.
 *
 * @package OrderMend
 */

namespace OrderMend\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Finds orders that are stuck (pending/on-hold/failed) yet carry a
 * transaction id and a supported payment method — i.e. re-queryable.
 *
 * HPOS-safe: uses wc_get_orders exclusively.
 */
class Order_Scanner {

	/**
	 * Supported payment methods (v1: Stripe + PayPal).
	 *
	 * @var string[]
	 */
	private $supported_methods = array( 'stripe', 'stripe_cc', 'ppcp-gateway', 'paypal' );

	/**
	 * Fetch candidate orders.
	 *
	 * @param array<string,mixed> $overrides Optional overrides (lookback_days, batch_size, gateways).
	 * @return \WC_Order[]
	 */
	public function get_candidates( array $overrides = array() ): array {
		$lookback = isset( $overrides['lookback_days'] )
			? (int) $overrides['lookback_days']
			: (int) get_option( 'ordermend_lookback_days', 14 );
		$lookback = max( 1, $lookback );

		$batch = isset( $overrides['batch_size'] )
			? (int) $overrides['batch_size']
			: (int) get_option( 'ordermend_batch_size', 50 );
		$batch = max( 1, $batch );

		$gateways = isset( $overrides['gateways'] )
			? (array) $overrides['gateways']
			: (array) get_option( 'ordermend_enabled_gateways', $this->supported_methods );
		$gateways = array_values( array_intersect( $gateways, $this->supported_methods ) );
		if ( empty( $gateways ) ) {
			$gateways = $this->supported_methods;
		}

		$args = array(
			'status'         => array( 'pending', 'on-hold', 'failed' ),
			'date_created'   => '>' . ( time() - $lookback * DAY_IN_SECONDS ),
			'limit'          => $batch,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'payment_method' => $gateways,
			'return'         => 'objects',
		);

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		// Only keep orders that actually carry a re-queryable transaction id.
		return array_values(
			array_filter(
				$orders,
				static function ( $order ) {
					if ( ! $order instanceof \WC_Order ) {
						return false;
					}
					$txn = (string) $order->get_transaction_id();
					if ( '' !== $txn ) {
						return true;
					}
					// Stripe stores the intent id in meta even before txn id is set.
					return '' !== (string) $order->get_meta( '_stripe_intent_id' )
						|| '' !== (string) $order->get_meta( '_ppcp_paypal_order_id' );
				}
			)
		);
	}
}
