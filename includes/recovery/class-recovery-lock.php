<?php
/**
 * Per-order recovery lock (idempotency).
 *
 * @package PaidRadar
 */

namespace PaidRadar\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents concurrent / double recovery of the same order using an
 * order meta flag plus a short-lived transient.
 */
class Recovery_Lock {

	const META_KEY       = '_paidradar_recovering';
	const TRANSIENT_TTL  = 300; // 5 minutes.

	/**
	 * Attempt to acquire the lock for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool True when acquired, false when already locked.
	 */
	public function acquire( \WC_Order $order ): bool {
		$key = $this->transient_key( $order->get_id() );

		if ( get_transient( $key ) ) {
			return false;
		}
		if ( 'yes' === (string) $order->get_meta( self::META_KEY ) ) {
			return false;
		}

		set_transient( $key, 1, self::TRANSIENT_TTL );
		$order->update_meta_data( self::META_KEY, 'yes' );
		$order->save();

		return true;
	}

	/**
	 * Release the lock for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function release( \WC_Order $order ) {
		delete_transient( $this->transient_key( $order->get_id() ) );
		$order->delete_meta_data( self::META_KEY );
		$order->save();
	}

	/**
	 * Transient key for an order id.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function transient_key( int $order_id ): string {
		return 'paidradar_lock_' . $order_id;
	}
}
