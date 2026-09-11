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
	protected $value;

	/**
	 * Static object cache.
	 *
	 * @var array<string,self>
	 */
	protected static $object_cache = array();

	/**
	 * Constructor.
	 *
	 * @param string $value Payment type constant name.
	 * @throws \InvalidArgumentException When the constant name does not exist.
	 */
	final private function __construct( string $value ) {
		if ( ! defined( static::class . "::$value" ) ) {
			throw new \InvalidArgumentException( "Constant with name '$value' does not exist." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserves the WooPayments oracle exception contract.
		}

		$this->value = $value;
	}

	/**
	 * Create a single payment type.
	 *
	 * @return self
	 */
	public static function single(): self {
		return static::from_name( 'SINGLE' );
	}

	/**
	 * Create a recurring payment type.
	 *
	 * @return self
	 */
	public static function recurring(): self {
		return static::from_name( 'RECURRING' );
	}

	/**
	 * Create a payment type from an oracle constant name.
	 *
	 * @param string $name      Constant name.
	 * @param array  $arguments Unused static-call arguments.
	 * @return self
	 * @throws \InvalidArgumentException When the constant name does not exist.
	 */
	public static function __callStatic( string $name, array $arguments ): self {
		unset( $arguments );

		return static::from_name( $name );
	}

	/**
	 * Compare payment types.
	 *
	 * @param mixed $other Payment type to compare.
	 * @return bool
	 */
	final public function equals( $other = null ): bool {
		return $this === $other;
	}

	/**
	 * Get the string value.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return (string) constant( get_class( $this ) . '::' . $this->get_value() );
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
	 * Find a payment type constant name by its exact value.
	 *
	 * @param string $value Constant value.
	 * @return string Constant name.
	 * @throws \InvalidArgumentException When the constant value does not exist.
	 */
	public static function search( string $value ): string {
		$constants = ( new \ReflectionClass( static::class ) )->getConstants();
		$name      = array_search( $value, $constants, true );
		if ( false === $name ) {
			throw new \InvalidArgumentException( "Constant with value '$value' does not exist." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserves the WooPayments oracle exception contract.
		}

		return $name;
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
	 * @param string $name Payment type constant name.
	 * @return self
	 */
	protected static function from_name( string $name ): self {
		if ( ! isset( static::$object_cache[ $name ] ) ) {
			static::$object_cache[ $name ] = new static( $name );
		}

		return static::$object_cache[ $name ];
	}
}
