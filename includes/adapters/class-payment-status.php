<?php
/**
 * Normalized payment status value object.
 *
 * @package PaidRadar
 */

namespace PaidRadar\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object describing the gateway's view of a payment.
 */
final class Payment_Status {

	const PAID     = 'PAID';
	const UNPAID   = 'UNPAID';
	const REFUNDED = 'REFUNDED';
	const DISPUTED = 'DISPUTED';
	const UNKNOWN  = 'UNKNOWN';

	/**
	 * One of the state constants.
	 *
	 * @var string
	 */
	public $state;

	/**
	 * Raw gateway response snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public $raw;

	/**
	 * Gateway transaction id used to complete the order, if any.
	 *
	 * @var string|null
	 */
	public $txn_id;

	/**
	 * ISO-8601 timestamp of when the check ran.
	 *
	 * @var string|null
	 */
	public $checked_at;

	/**
	 * Constructor.
	 *
	 * @param string              $state      One of the state constants.
	 * @param array<string,mixed> $raw        Raw gateway response.
	 * @param string|null         $txn_id     Transaction id.
	 * @param string|null         $checked_at ISO-8601 timestamp.
	 */
	public function __construct( string $state = self::UNKNOWN, array $raw = array(), ?string $txn_id = null, ?string $checked_at = null ) {
		$this->state      = $state;
		$this->raw        = $raw;
		$this->txn_id     = $txn_id;
		$this->checked_at = $checked_at ?? gmdate( 'c' );
	}

	/**
	 * Convenience factory for PAID.
	 *
	 * @param string|null         $txn_id Transaction id.
	 * @param array<string,mixed> $raw    Raw response.
	 * @return self
	 */
	public static function paid( ?string $txn_id, array $raw = array() ): self {
		return new self( self::PAID, $raw, $txn_id );
	}

	/**
	 * Convenience factory for UNKNOWN.
	 *
	 * @param array<string,mixed> $raw Raw response / error context.
	 * @return self
	 */
	public static function unknown( array $raw = array() ): self {
		return new self( self::UNKNOWN, $raw );
	}

	/**
	 * Whether the gateway unambiguously reports the payment as captured.
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return self::PAID === $this->state;
	}
}
