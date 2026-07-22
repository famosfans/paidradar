<?php
/**
 * Unit tests for the PayPal (PPCP) adapter.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PaidRadar\Adapters\Payment_Status;
use PaidRadar\Adapters\PayPal_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * Exposes the protected credential resolver for assertions.
 */
class TestablePayPalAdapter extends PayPal_Adapter {

	/**
	 * @return array{client_id:string,secret:string,sandbox:bool}|null
	 */
	public function resolve_credentials(): ?array {
		return $this->get_credentials();
	}
}

/**
 * @covers \PaidRadar\Adapters\PayPal_Adapter
 */
class PayPalAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * supports(): only PPCP / legacy PayPal method ids.
	 */
	public function test_supports_paypal_methods() {
		$adapter = new PayPal_Adapter();
		$this->assertTrue( $adapter->supports( 'ppcp-gateway' ) );
		$this->assertTrue( $adapter->supports( 'paypal' ) );
		$this->assertFalse( $adapter->supports( 'stripe' ) );
	}

	/**
	 * Legacy fallback: sandbox credentials are read from the flat option
	 * when PPCP 4.x's container is unavailable (as in the unit context).
	 */
	public function test_legacy_sandbox_credentials() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'sandbox_on'            => 'yes',
				'client_id_sandbox'     => 'sb-client',
				'client_secret_sandbox' => 'sb-secret',
			)
		);

		$creds = ( new TestablePayPalAdapter() )->resolve_credentials();

		$this->assertIsArray( $creds );
		$this->assertSame( 'sb-client', $creds['client_id'] );
		$this->assertSame( 'sb-secret', $creds['secret'] );
		$this->assertTrue( $creds['sandbox'] );
	}

	/**
	 * Legacy fallback: production credentials when sandbox is off.
	 */
	public function test_legacy_production_credentials() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'sandbox_on'               => 'no',
				'client_id_production'     => 'live-client',
				'client_secret_production' => 'live-secret',
			)
		);

		$creds = ( new TestablePayPalAdapter() )->resolve_credentials();

		$this->assertIsArray( $creds );
		$this->assertSame( 'live-client', $creds['client_id'] );
		$this->assertSame( 'live-secret', $creds['secret'] );
		$this->assertFalse( $creds['sandbox'] );
	}

	/**
	 * No credentials anywhere → null (adapter must fail soft, never guess).
	 */
	public function test_missing_credentials_return_null() {
		Functions\when( 'get_option' )->justReturn( false );

		$creds = ( new TestablePayPalAdapter() )->resolve_credentials();

		$this->assertNull( $creds );
	}

	/**
	 * map(): a completed capture is PAID and prefers the capture id as txn id.
	 */
	public function test_map_completed_capture_is_paid() {
		$adapter = new PayPal_Adapter();

		$status = $adapter->map(
			array(
				'id'             => 'ORDER123',
				'status'         => 'COMPLETED',
				'purchase_units' => array(
					array(
						'payments' => array(
							'captures' => array(
								array(
									'id'     => 'CAPTURE999',
									'status' => 'COMPLETED',
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( Payment_Status::PAID, $status->state );
		$this->assertSame( 'CAPTURE999', $status->txn_id );
	}

	/**
	 * map(): a refunded capture becomes REFUNDED even if the order says COMPLETED.
	 */
	public function test_map_refunded_capture_is_refunded() {
		$adapter = new PayPal_Adapter();

		$status = $adapter->map(
			array(
				'id'             => 'ORDER456',
				'status'         => 'COMPLETED',
				'purchase_units' => array(
					array(
						'payments' => array(
							'captures' => array(
								array(
									'id'     => 'CAPTURE888',
									'status' => 'REFUNDED',
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( Payment_Status::REFUNDED, $status->state );
	}

	/**
	 * map(): a voided order with no capture is UNPAID.
	 */
	public function test_map_voided_is_unpaid() {
		$adapter = new PayPal_Adapter();

		$status = $adapter->map(
			array(
				'id'     => 'ORDER789',
				'status' => 'VOIDED',
			)
		);

		$this->assertSame( Payment_Status::UNPAID, $status->state );
	}

	/**
	 * map(): an approved-but-not-captured order is UNKNOWN (never auto-completed).
	 */
	public function test_map_approved_is_unknown() {
		$adapter = new PayPal_Adapter();

		$status = $adapter->map(
			array(
				'id'     => 'ORDER000',
				'status' => 'APPROVED',
			)
		);

		$this->assertSame( Payment_Status::UNKNOWN, $status->state );
	}
}
