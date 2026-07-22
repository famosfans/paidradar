<?php
/**
 * Adapter registry: maps payment methods to status adapters.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Holds registered {@see Status_Adapter} instances and resolves the
 * right one for a given order.
 */
class Adapter_Registry {

	/**
	 * Registered adapters.
	 *
	 * @var Status_Adapter[]
	 */
	private $adapters = array();

	/**
	 * Register an adapter.
	 *
	 * @param Status_Adapter $adapter Adapter instance.
	 * @return void
	 */
	public function register( Status_Adapter $adapter ) {
		$this->adapters[] = $adapter;
	}

	/**
	 * All registered adapters.
	 *
	 * @return Status_Adapter[]
	 */
	public function all(): array {
		return $this->adapters;
	}

	/**
	 * Payment-method ids supported by any registered adapter.
	 *
	 * Used by the scanner to pre-filter candidate orders.
	 *
	 * @return string[]
	 */
	public function supported_methods(): array {
		return array( 'stripe', 'stripe_cc', 'ppcp-gateway', 'paypal' );
	}

	/**
	 * Resolve the adapter for an order by its payment method.
	 *
	 * @param \WC_Order $order Order.
	 * @return Status_Adapter|null
	 */
	public function for_order( \WC_Order $order ): ?Status_Adapter {
		$method = (string) $order->get_payment_method();
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports( $method ) ) {
				return $adapter;
			}
		}
		return null;
	}
}
