<?php
/**
 * Core reconciliation loop.
 *
 * @package OrderMend
 */

namespace OrderMend\Recovery;

use OrderMend\Adapters\Adapter_Registry;
use OrderMend\Adapters\Payment_Status;
use OrderMend\Audit\Audit_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the decision matrix per candidate order:
 *
 *  - PAID + not-paid-status  → payment_complete() + audit 'recovered' + alert
 *  - REFUNDED / DISPUTED     → audit 'drift' + alert (never complete)
 *  - UNPAID                  → audit 'confirmed_unpaid'
 *  - UNKNOWN / error         → audit 'check_failed'
 *
 * Conservative by default: only completes on unambiguous PAID + txn id.
 */
class Reconciler {

	/**
	 * Order statuses that already count as paid — never re-complete these.
	 *
	 * @var string[]
	 */
	private $paid_statuses = array( 'processing', 'completed', 'refunded' );

	/**
	 * Adapter registry.
	 *
	 * @var Adapter_Registry
	 */
	private $registry;

	/**
	 * Audit log.
	 *
	 * @var Audit_Log
	 */
	private $audit;

	/**
	 * Recovery lock.
	 *
	 * @var Recovery_Lock
	 */
	private $lock;

	/**
	 * Constructor.
	 *
	 * @param Adapter_Registry $registry Adapter registry.
	 * @param Audit_Log        $audit    Audit log.
	 * @param Recovery_Lock    $lock     Recovery lock.
	 */
	public function __construct( Adapter_Registry $registry, Audit_Log $audit, Recovery_Lock $lock ) {
		$this->registry = $registry;
		$this->audit    = $audit;
		$this->lock     = $lock;
	}

	/**
	 * Run reconciliation for a set of orders.
	 *
	 * @param \WC_Order[] $orders Candidate orders.
	 * @param string      $actor  'cron' | 'manual'.
	 * @return array{scanned:int,recovered:int,drift:int,unpaid:int,failed:int,recovered_total:float,recovered_orders:array<int,array<string,mixed>>}
	 */
	public function run( array $orders, string $actor = 'cron' ): array {
		$summary = array(
			'scanned'          => 0,
			'recovered'        => 0,
			'drift'            => 0,
			'unpaid'           => 0,
			'failed'           => 0,
			'recovered_total'  => 0.0,
			'recovered_orders' => array(),
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$summary['scanned']++;

			$adapter = $this->registry->for_order( $order );
			if ( null === $adapter ) {
				$this->audit->record( $this->row( $order, 'unsupported', null, Payment_Status::UNKNOWN, $actor ) );
				continue;
			}

			if ( ! $this->lock->acquire( $order ) ) {
				continue; // Already being processed.
			}

			try {
				$status  = $adapter->fetch_status( $order );
				$gateway = $adapter->gateway_slug();
				$this->handle( $order, $status, $gateway, $actor, $summary );
			} catch ( \Throwable $e ) {
				$this->audit->record( $this->row( $order, 'check_failed', null, Payment_Status::UNKNOWN, $actor, array( 'error' => $e->getMessage() ) ) );
				$summary['failed']++;
			} finally {
				$this->lock->release( $order );
			}
		}

		update_option( 'ordermend_last_run', time() );
		update_option(
			'ordermend_last_run_summary',
			array(
				'scanned'         => $summary['scanned'],
				'recovered'       => $summary['recovered'],
				'drift'           => $summary['drift'],
				'unpaid'          => $summary['unpaid'],
				'failed'          => $summary['failed'],
				'recovered_total' => $summary['recovered_total'],
				'at'              => time(),
			)
		);

		if ( $summary['recovered'] > 0 ) {
			$total = (float) get_option( 'ordermend_recovered_lifetime_total', 0 );
			update_option( 'ordermend_recovered_lifetime_total', $total + $summary['recovered_total'] );
			// Stash for the admin notice after a run.
			set_transient( 'ordermend_recent_recoveries', $summary['recovered_orders'], DAY_IN_SECONDS );
		}

		return $summary;
	}

	/**
	 * Apply the decision for a single order's status.
	 *
	 * @param \WC_Order      $order   Order.
	 * @param Payment_Status $status  Normalized status.
	 * @param string         $gateway Gateway slug.
	 * @param string         $actor   Actor.
	 * @param array          $summary Summary accumulator (by reference).
	 * @return void
	 */
	private function handle( \WC_Order $order, Payment_Status $status, string $gateway, string $actor, array &$summary ) {
		$before = $order->get_status();

		switch ( $status->state ) {
			case Payment_Status::PAID:
				// Conservative: require an unambiguous txn id and a not-yet-paid status.
				if ( empty( $status->txn_id ) ) {
					$this->audit->record( $this->row( $order, 'check_failed', $gateway, $status->state, $actor, array( 'error' => 'paid_without_txn_id' ) ) );
					$summary['failed']++;
					return;
				}
				if ( in_array( $before, $this->paid_statuses, true ) ) {
					// Already paid locally — nothing to do.
					return;
				}

				$order->payment_complete( $status->txn_id );
				$order->save();

				$after = $order->get_status();
				$this->audit->record( $this->row( $order, 'recovered', $gateway, $status->state, $actor, $status->raw, $before, $after ) );

				$summary['recovered']++;
				$summary['recovered_total'] += (float) $order->get_total();
				$summary['recovered_orders'][] = array(
					'order_id' => $order->get_id(),
					'gateway'  => $gateway,
					'amount'   => (float) $order->get_total(),
					'currency' => $order->get_currency(),
				);
				break;

			case Payment_Status::REFUNDED:
			case Payment_Status::DISPUTED:
				// Money moved back / contested — report only, never complete.
				$this->audit->record( $this->row( $order, 'drift', $gateway, $status->state, $actor, $status->raw, $before ) );
				$summary['drift']++;
				break;

			case Payment_Status::UNPAID:
				$this->audit->record( $this->row( $order, 'confirmed_unpaid', $gateway, $status->state, $actor, $status->raw, $before ) );
				$summary['unpaid']++;
				break;

			case Payment_Status::UNKNOWN:
			default:
				$this->audit->record( $this->row( $order, 'check_failed', $gateway, $status->state, $actor, $status->raw, $before ) );
				$summary['failed']++;
				break;
		}
	}

	/**
	 * Build an audit row.
	 *
	 * @param \WC_Order    $order      Order.
	 * @param string       $event      Event name.
	 * @param string|null  $gateway    Gateway slug.
	 * @param string       $psp_status Normalized PSP status.
	 * @param string       $actor      Actor.
	 * @param array        $raw        Raw response snapshot.
	 * @param string|null  $before     Status before.
	 * @param string|null  $after      Status after.
	 * @return array<string,mixed>
	 */
	private function row( \WC_Order $order, string $event, ?string $gateway, string $psp_status, string $actor, array $raw = array(), ?string $before = null, ?string $after = null ): array {
		return array(
			'order_id'      => $order->get_id(),
			'gateway'       => $gateway ?? $order->get_payment_method(),
			'event'         => $event,
			'psp_status'    => $psp_status,
			'status_before' => $before ?? $order->get_status(),
			'status_after'  => $after,
			'amount'        => (float) $order->get_total(),
			'currency'      => $order->get_currency(),
			'psp_response'  => $raw,
			'actor'         => $actor,
		);
	}
}
