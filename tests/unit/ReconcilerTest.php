<?php
/**
 * Unit tests for the reconciliation decision matrix.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PaidRadar\Adapters\Adapter_Registry;
use PaidRadar\Adapters\Payment_Status;
use PaidRadar\Adapters\Status_Adapter;
use PaidRadar\Audit\Audit_Log;
use PaidRadar\Recovery\Recovery_Lock;
use PaidRadar\Recovery\Reconciler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PaidRadar\Recovery\Reconciler
 */
class ReconcilerTest extends TestCase {

	/**
	 * Set up Brain Monkey + WP function stubs shared by the tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Persistence side-effects are no-ops in the unit context.
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a mocked WC_Order with the common getters.
	 *
	 * @param string $status Current order status.
	 * @return Mockery\MockInterface
	 */
	private function make_order( string $status = 'pending' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_id' )->andReturn( 1042 );
		$order->allows( 'get_status' )->andReturn( $status );
		$order->allows( 'get_total' )->andReturn( 99.0 );
		$order->allows( 'get_currency' )->andReturn( 'EUR' );
		$order->allows( 'get_payment_method' )->andReturn( 'stripe' );
		$order->allows( 'save' )->andReturn( 1042 );
		return $order;
	}

	/**
	 * Build a reconciler with a registry that always returns the given adapter.
	 *
	 * @param Status_Adapter $adapter Adapter mock.
	 * @param Audit_Log      $audit   Audit mock.
	 * @return Reconciler
	 */
	private function make_reconciler( $adapter, $audit ): Reconciler {
		$registry = Mockery::mock( Adapter_Registry::class );
		$registry->allows( 'for_order' )->andReturn( $adapter );

		$lock = Mockery::mock( Recovery_Lock::class );
		$lock->allows( 'acquire' )->andReturn( true );
		$lock->allows( 'release' )->andReturnNull();

		return new Reconciler( $registry, $audit, $lock );
	}

	/**
	 * PAID order gets completed via payment_complete() and audited as recovered.
	 */
	public function test_paid_order_is_completed() {
		$order = $this->make_order( 'pending' );
		// payment_complete MUST be called exactly once with the txn id.
		$order->shouldReceive( 'payment_complete' )->once()->with( 'pi_paid' )->andReturn( true );

		$adapter = Mockery::mock( Status_Adapter::class );
		$adapter->allows( 'gateway_slug' )->andReturn( 'stripe' );
		$adapter->allows( 'fetch_status' )->andReturn( Payment_Status::paid( 'pi_paid', array( 'status' => 'succeeded' ) ) );

		$audit = Mockery::mock( Audit_Log::class );
		$audit->shouldReceive( 'record' )->once()->andReturn( 1 );

		$reconciler = $this->make_reconciler( $adapter, $audit );
		$summary    = $reconciler->run( array( $order ), 'manual' );

		$this->assertSame( 1, $summary['recovered'] );
		$this->assertSame( 99.0, $summary['recovered_total'] );
	}

	/**
	 * UNPAID order is never completed, logged as confirmed_unpaid.
	 */
	public function test_unpaid_order_is_not_completed() {
		$order = $this->make_order( 'pending' );
		$order->shouldReceive( 'payment_complete' )->never();

		$adapter = Mockery::mock( Status_Adapter::class );
		$adapter->allows( 'gateway_slug' )->andReturn( 'stripe' );
		$adapter->allows( 'fetch_status' )->andReturn( new Payment_Status( Payment_Status::UNPAID, array(), 'pi_x' ) );

		$audit = Mockery::mock( Audit_Log::class );
		$audit->shouldReceive( 'record' )->once()->andReturn( 1 );

		$reconciler = $this->make_reconciler( $adapter, $audit );
		$summary    = $reconciler->run( array( $order ), 'manual' );

		$this->assertSame( 0, $summary['recovered'] );
		$this->assertSame( 1, $summary['unpaid'] );
	}

	/**
	 * REFUNDED order is never completed; logged as drift.
	 */
	public function test_refunded_order_is_not_completed() {
		$order = $this->make_order( 'pending' );
		$order->shouldReceive( 'payment_complete' )->never();

		$adapter = Mockery::mock( Status_Adapter::class );
		$adapter->allows( 'gateway_slug' )->andReturn( 'stripe' );
		$adapter->allows( 'fetch_status' )->andReturn( new Payment_Status( Payment_Status::REFUNDED, array(), 'pi_r' ) );

		$audit = Mockery::mock( Audit_Log::class );
		$audit->shouldReceive( 'record' )->once()->andReturn( 1 );

		$reconciler = $this->make_reconciler( $adapter, $audit );
		$summary    = $reconciler->run( array( $order ), 'manual' );

		$this->assertSame( 0, $summary['recovered'] );
		$this->assertSame( 1, $summary['drift'] );
	}

	/**
	 * PAID without a txn id is treated conservatively: not completed.
	 */
	public function test_paid_without_txn_id_is_not_completed() {
		$order = $this->make_order( 'pending' );
		$order->shouldReceive( 'payment_complete' )->never();

		$adapter = Mockery::mock( Status_Adapter::class );
		$adapter->allows( 'gateway_slug' )->andReturn( 'stripe' );
		$adapter->allows( 'fetch_status' )->andReturn( new Payment_Status( Payment_Status::PAID, array(), null ) );

		$audit = Mockery::mock( Audit_Log::class );
		$audit->shouldReceive( 'record' )->once()->andReturn( 1 );

		$reconciler = $this->make_reconciler( $adapter, $audit );
		$summary    = $reconciler->run( array( $order ), 'manual' );

		$this->assertSame( 0, $summary['recovered'] );
		$this->assertSame( 1, $summary['failed'] );
	}
}
