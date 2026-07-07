<?php
/**
 * WooPaymentsPaymentType class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * WooPayments-compatible payment type value object.
 *
 * @since 11.0.0
 * @internal Transitional compatibility object for WooPayments metadata filters.
 */
class WooPaymentsPaymentType {

	/**
	 * Single payment type.
	 *
	 * @var string
	 */
	public const SINGLE = 'single';

	/**
	 * Recurring payment type.
	 *
	 * @var string
	 */
	public const RECURRING = 'recurring';

	/**
	 * Payment type value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $value Payment type value.
	 */
	private function __construct( string $value ) {
		$this->value = self::RECURRING === $value ? self::RECURRING : self::SINGLE;
	}

	/**
	 * Create a single payment type.
	 *
	 * @return self
	 */
	public static function single(): self {
		return new self( self::SINGLE );
	}

	/**
	 * Create a recurring payment type.
	 *
	 * @return self
	 */
	public static function recurring(): self {
		return new self( self::RECURRING );
	}

	/**
	 * Compare payment types.
	 *
	 * @param self $other Payment type to compare.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}

	/**
	 * Get the string value.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}
}
