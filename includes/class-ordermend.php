<?php
/**
 * Plugin container / bootstrap.
 *
 * @package OrderMend
 */

namespace OrderMend;

use OrderMend\Adapters\Adapter_Registry;
use OrderMend\Adapters\Stripe_Adapter;
use OrderMend\Adapters\PayPal_Adapter;
use OrderMend\Recovery\Order_Scanner;
use OrderMend\Recovery\Reconciler;
use OrderMend\Recovery\Recovery_Lock;
use OrderMend\Audit\Audit_Log;
use OrderMend\Scheduler\Scheduler;
use OrderMend\Admin\Admin;
use OrderMend\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton container. Wires all services and registers hooks.
 */
final class OrderMend {

	/**
	 * Singleton instance.
	 *
	 * @var OrderMend|null
	 */
	private static $instance = null;

	/**
	 * Service instances keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return OrderMend
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (use instance()).
	 */
	private function __construct() {}

	/**
	 * Instantiate services, register hooks. Idempotent.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$audit    = new Audit_Log();
		$registry = new Adapter_Registry();
		$registry->register( new Stripe_Adapter() );
		$registry->register( new PayPal_Adapter() );

		$scanner    = new Order_Scanner();
		$lock       = new Recovery_Lock();
		$reconciler = new Reconciler( $registry, $audit, $lock );
		$scheduler  = new Scheduler( $scanner, $reconciler );
		$notices    = new Notices();
		$admin      = new Admin( $audit, $scheduler );

		$this->services = array(
			'audit'      => $audit,
			'registry'   => $registry,
			'scanner'    => $scanner,
			'lock'       => $lock,
			'reconciler' => $reconciler,
			'scheduler'  => $scheduler,
			'notices'    => $notices,
			'admin'      => $admin,
		);

		load_plugin_textdomain( 'ordermend', false, dirname( ORDERMEND_BASENAME ) . '/languages' );

		$scheduler->register_hooks();
		$admin->register_hooks();
		$notices->register_hooks();
	}

	/**
	 * Resolve a service by short name.
	 *
	 * @param string $name Service key.
	 * @return object|null
	 */
	public function get( $name ) {
		return isset( $this->services[ $name ] ) ? $this->services[ $name ] : null;
	}
}
