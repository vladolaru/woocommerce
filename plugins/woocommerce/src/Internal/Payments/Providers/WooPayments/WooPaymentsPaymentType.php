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
class WooPaymentsPaymentType implements \JsonSerializable {

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
	 * Static object cache.
	 *
	 * @var array<string,self>
	 */
	private static array $instances = array();

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
		return self::from_value( self::SINGLE );
	}

	/**
	 * Create a recurring payment type.
	 *
	 * @return self
	 */
	public static function recurring(): self {
		return self::from_value( self::RECURRING );
	}

	/**
	 * Compare payment types.
	 *
	 * @param mixed $other Payment type to compare.
	 * @return bool
	 */
	public function equals( $other = null ): bool {
		return $this === $other;
	}

	/**
	 * Get the string value.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}

	/**
	 * Get the enum value.
	 *
	 * @return string
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Specify the value serialized to JSON.
	 *
	 * @return string
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Register the legacy WooPayments payment type class name when the extension is absent.
	 */
	public static function register_legacy_alias(): void {
		if ( ! class_exists( 'WCPay\\Constants\\Payment_Type' ) ) {
			class_alias( self::class, 'WCPay\\Constants\\Payment_Type' );
		}
	}

	/**
	 * Get a cached payment type instance.
	 *
	 * @param string $value Payment type value.
	 * @return self
	 */
	private static function from_value( string $value ): self {
		$value = self::RECURRING === $value ? self::RECURRING : self::SINGLE;
		if ( ! isset( self::$instances[ $value ] ) ) {
			self::$instances[ $value ] = new self( $value );
		}

		return self::$instances[ $value ];
	}
}
