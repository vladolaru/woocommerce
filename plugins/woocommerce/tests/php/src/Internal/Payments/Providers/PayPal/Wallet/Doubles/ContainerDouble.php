<?php
/**
 * A container that serves the services a test hands it.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\NotFoundException;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * For the modules whose run() only registers hooks: the services the hooks ask for are given up front, and a service
 * nobody gave throws, so a hook that reaches for one more than the test expects fails the test instead of getting a
 * null. A service is returned as it is, nothing is built lazily.
 */
final class ContainerDouble implements ContainerInterface {

	/**
	 * The services, by ID.
	 *
	 * @var array<string, mixed>
	 */
	private array $services;

	/**
	 * The IDs of the services that were asked for, in order, each as often as it was asked.
	 *
	 * @var string[]
	 */
	private array $requested = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services The services, by ID.
	 */
	public function __construct( array $services ) {
		$this->services = $services;
	}

	/**
	 * The service with the ID.
	 *
	 * @param string $id The ID.
	 * @return mixed
	 * @throws NotFoundException When no service has the ID.
	 */
	public function get( string $id ) {
		if ( ! $this->has( $id ) ) {
			throw new NotFoundException( "No service \"$id\" was given to the container double" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
		}

		$this->requested[] = $id;

		return $this->services[ $id ];
	}

	/**
	 * Whether a service has the ID.
	 *
	 * @param string $id The ID.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return array_key_exists( $id, $this->services );
	}

	/**
	 * The IDs of the services that were asked for.
	 *
	 * @return string[]
	 */
	public function requested(): array {
		return $this->requested;
	}
}
