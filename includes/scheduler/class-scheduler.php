<?php
/**
 * Scan scheduling via Action Scheduler.
 *
 * @package OrderMend
 */

namespace OrderMend\Scheduler;

use OrderMend\Recovery\Order_Scanner;
use OrderMend\Recovery\Reconciler;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the daily recurring scan and exposes a manual dispatch.
 */
class Scheduler {

	const HOOK  = 'ordermend_daily_scan';
	const GROUP = 'ordermend';

	/**
	 * Order scanner.
	 *
	 * @var Order_Scanner
	 */
	private $scanner;

	/**
	 * Reconciler.
	 *
	 * @var Reconciler
	 */
	private $reconciler;

	/**
	 * Constructor.
	 *
	 * @param Order_Scanner $scanner    Scanner.
	 * @param Reconciler    $reconciler Reconciler.
	 */
	public function __construct( Order_Scanner $scanner, Reconciler $reconciler ) {
		$this->scanner    = $scanner;
		$this->reconciler = $reconciler;
	}

	/**
	 * Register hooks: schedule the recurring action and bind the runner.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( self::HOOK, array( $this, 'run_scan' ) );
	}

	/**
	 * Ensure a daily recurring action is registered (Action Scheduler ships with WooCommerce).
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Unschedule the recurring action (used on deactivation/uninstall).
	 *
	 * @return void
	 */
	public function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Scheduled scan runner (actor = cron).
	 *
	 * @return array<string,mixed>
	 */
	public function run_scan() {
		return $this->dispatch( 'cron' );
	}

	/**
	 * Immediately scan + reconcile. Used by the admin "Check now" button.
	 *
	 * @param string $actor 'cron' | 'manual'.
	 * @return array<string,mixed> Run summary.
	 */
	public function dispatch( string $actor = 'manual' ): array {
		$candidates = $this->scanner->get_candidates();
		return $this->reconciler->run( $candidates, $actor );
	}
}
