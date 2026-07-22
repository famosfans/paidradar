<?php
/**
 * Plugin container / bootstrap.
 *
 * @package PaidRadar
 */

namespace PaidRadar;

use PaidRadar\Adapters\Adapter_Registry;
use PaidRadar\Adapters\Stripe_Adapter;
use PaidRadar\Adapters\PayPal_Adapter;
use PaidRadar\Recovery\Order_Scanner;
use PaidRadar\Recovery\Reconciler;
use PaidRadar\Recovery\Recovery_Lock;
use PaidRadar\Audit\Audit_Log;
use PaidRadar\Scheduler\Scheduler;
use PaidRadar\Admin\Admin;
use PaidRadar\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton container. Wires all services and registers hooks.
 */
final class PaidRadar {

	/**
	 * Singleton instance.
	 *
	 * @var PaidRadar|null
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
	 * @return PaidRadar
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

		load_plugin_textdomain( 'paidradar', false, dirname( PAIDRADAR_BASENAME ) . '/languages' );

		$scheduler->register_hooks();
		$admin->register_hooks();
		$notices->register_hooks();

		/**
		 * Fires once all PaidRadar services are wired and hooks registered.
		 *
		 * Extensions (e.g. PaidRadar Pro) hook this to register additional
		 * payment adapters, admin settings sections and alert channels via the
		 * container's service accessor, e.g.
		 * `$plugin->get( 'registry' )->register( new My_Adapter() )`.
		 *
		 * @param PaidRadar $plugin The plugin container (use ->get( $name )).
		 */
		do_action( 'paidradar_loaded', $this );
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
