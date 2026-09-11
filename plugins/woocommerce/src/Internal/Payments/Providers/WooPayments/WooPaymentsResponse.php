<?php
/**
 * WooPaymentsResponse class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use ArrayAccess;
use BadMethodCallException;

/**
 * Immutable compatibility response for legacy WooPayments request objects.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 * @implements ArrayAccess<mixed,mixed>
 */
class WooPaymentsResponse implements ArrayAccess {

	/**
	 * Response data.
	 *
	 * @var array<mixed>
	 */
	protected $data;

	/**
	 * Create a response.
	 *
	 * @param array<mixed> $data Response data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Register the legacy response alias when the WooPayments extension is absent.
	 */
	public static function register_legacy_alias(): void {
		if ( ! class_exists( 'WCPay\Core\Server\Response', false ) ) {
			class_alias( self::class, 'WCPay\Core\Server\Response' );
		}
	}

	/**
	 * Check whether a response key exists.
	 *
	 * @param mixed $offset Response key.
	 * @return bool
	 */
	public function offsetExists( $offset ): bool {
		return isset( $this->data[ $offset ] );
	}

	/**
	 * Get a response value.
	 *
	 * @param mixed $offset Response key.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->data[ $offset ];
	}

	/**
	 * Reject response mutation.
	 *
	 * @param mixed $offset Response key.
	 * @param mixed $value  Response value.
	 * @throws BadMethodCallException Responses are immutable.
	 */
	public function offsetSet( $offset, $value ): void {
		throw new BadMethodCallException( 'Server responses cannot be mutated.' );
	}

	/**
	 * Reject response mutation.
	 *
	 * @param mixed $offset Response key.
	 * @throws BadMethodCallException Responses are immutable.
	 */
	public function offsetUnset( $offset ): void {
		throw new BadMethodCallException( 'Server responses cannot be mutated.' );
	}

	/**
	 * Return the response data as an array.
	 *
	 * @return array<mixed>
	 */
	public function to_array() {
		return $this->data;
	}
}
