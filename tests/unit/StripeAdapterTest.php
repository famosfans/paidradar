<?php
/**
 * Unit tests for the Stripe adapter.
 *
 * @package OrderMend
 */

namespace OrderMend\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use OrderMend\Adapters\Payment_Status;
use OrderMend\Adapters\Stripe_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OrderMend\Adapters\Stripe_Adapter
 */
class StripeAdapterTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey / Mockery.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * map(): a succeeded PaymentIntent becomes PAID with the intent id.
	 */
	public function test_map_succeeded_is_paid() {
		$adapter = new Stripe_Adapter();

		$status = $adapter->map(
			array(
				'id'     => 'pi_123',
				'status' => 'succeeded',
			)
		);

		$this->assertSame( Payment_Status::PAID, $status->state );
		$this->assertSame( 'pi_123', $status->txn_id );
	}

	/**
	 * map(): a refunded latest charge becomes REFUNDED even if status is succeeded.
	 */
	public function test_map_refunded_charge_is_refunded() {
		$adapter = new Stripe_Adapter();

		$status = $adapter->map(
			array(
				'id'            => 'pi_456',
				'status'        => 'succeeded',
				'latest_charge' => array(
					'refunded'        => true,
					'amount_refunded' => 500,
				),
			)
		);

		$this->assertSame( Payment_Status::REFUNDED, $status->state );
	}

	/**
	 * map(): canceled intent is UNPAID.
	 */
	public function test_map_canceled_is_unpaid() {
		$adapter = new Stripe_Adapter();

		$status = $adapter->map(
			array(
				'id'     => 'pi_789',
				'status' => 'canceled',
			)
		);

		$this->assertSame( Payment_Status::UNPAID, $status->state );
	}

	/**
	 * fetch_status(): a mocked HTTP 200 `succeeded` response maps to PAID.
	 */
	public function test_fetch_status_maps_succeeded_to_paid() {
		Functions\when( 'get_option' )->justReturn(
			array(
				'testmode'   => 'yes',
				'test_secret_key' => 'sk_test_x',
			)
		);
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '', 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode_stub( array( 'id' => 'pi_abc', 'status' => 'succeeded' ) ) );

		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_transaction_id' )->andReturn( 'pi_abc' );
		$order->allows( 'get_meta' )->andReturn( '' );

		$adapter = new Stripe_Adapter();
		$status  = $adapter->fetch_status( $order );

		$this->assertSame( Payment_Status::PAID, $status->state );
		$this->assertSame( 'pi_abc', $status->txn_id );
	}

	/**
	 * fetch_status(): missing secret key degrades to UNKNOWN (no fatal).
	 */
	public function test_fetch_status_without_settings_is_unknown() {
		Functions\when( 'get_option' )->justReturn( false );

		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_transaction_id' )->andReturn( 'pi_abc' );
		$order->allows( 'get_meta' )->andReturn( '' );

		$adapter = new Stripe_Adapter();
		$status  = $adapter->fetch_status( $order );

		$this->assertSame( Payment_Status::UNKNOWN, $status->state );
	}
}

/**
 * Minimal json_encode helper so the test does not depend on WP's wp_json_encode.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_stub( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
