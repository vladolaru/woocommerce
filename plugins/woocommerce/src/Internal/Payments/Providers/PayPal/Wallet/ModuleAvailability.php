<?php
/**
 * Answers whether an optional module is loaded, eligible and available.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Optional modules (Fastlane, card fields, local APMs,
 * order tracking, PayPal Subscriptions) load behind feature flags or may be
 * left out by a host. Code in other modules asks this service instead of
 * reading those modules' container IDs directly, so an absent module reads as
 * "not loaded, not eligible, not available" rather than as a missing service.
 *
 * Convention: an optional module registers `<prefix>.available` (bool) and,
 * when it has an eligibility rule, `<prefix>.eligibility.check` (a callable
 * returning bool, or a bool). The container's has() never instantiates a
 * service, so is_loaded() is free; the callables are lazy, so a product-status
 * lookup behind `.available` runs only when a caller asks.
 */
class ModuleAvailability {

	/**
	 * The plugin container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * ModuleAvailability constructor.
	 *
	 * @param ContainerInterface $container The plugin container.
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Whether the module registered its services.
	 *
	 * @param string $prefix The module's service prefix, for example 'axo'.
	 * @return bool
	 */
	public function is_loaded( string $prefix ): bool {
		return $this->container->has( "$prefix.available" );
	}

	/**
	 * The module's eligibility check as a callable, or one that always says no.
	 *
	 * A bool service is wrapped so callers can treat every check the same way.
	 *
	 * @param string $prefix The service prefix, for example 'axo' or 'ppcp-local-apms.pwc'.
	 * @return callable(): bool
	 */
	public function eligibility_check( string $prefix ): callable {
		$id = "$prefix.eligibility.check";
		if ( ! $this->container->has( $id ) ) {
			return static function (): bool {
				return false;
			};
		}

		$check = $this->container->get( $id );
		if ( is_callable( $check ) ) {
			return $check;
		}

		return static function () use ( $check ): bool {
			return (bool) $check;
		};
	}

	/**
	 * Whether the module is loaded and its eligibility check passes.
	 *
	 * A loaded module without an eligibility rule is eligible.
	 *
	 * @param string $prefix The module's service prefix.
	 * @return bool
	 */
	public function is_eligible( string $prefix ): bool {
		if ( ! $this->is_loaded( $prefix ) ) {
			return false;
		}
		if ( ! $this->container->has( "$prefix.eligibility.check" ) ) {
			return true;
		}

		return ( $this->eligibility_check( $prefix ) )();
	}

	/**
	 * A lazy check: loaded, eligible, and `<prefix>.available` is true.
	 *
	 * Eligibility runs first, so `.available` (which may call PayPal) is only
	 * read for eligible modules.
	 *
	 * @param string $prefix The module's service prefix.
	 * @return callable(): bool
	 */
	public function availability_check( string $prefix ): callable {
		return function () use ( $prefix ): bool {
			if ( ! $this->is_eligible( $prefix ) ) {
				return false;
			}

			return (bool) $this->container->get( "$prefix.available" );
		};
	}

	/**
	 * Whether the module is loaded, eligible and available, evaluated now.
	 *
	 * @param string $prefix The module's service prefix.
	 * @return bool
	 */
	public function is_available( string $prefix ): bool {
		return ( $this->availability_check( $prefix ) )();
	}
}
