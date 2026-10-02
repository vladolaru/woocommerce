<?php
/**
 * WalletStubsModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use WooCommerce\PayPalCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * "Not eligible" services for the Fastlane, card fields, Google Pay, Apple Pay and local APM checks.
 *
 * The owning modules stay loaded because kept modules read their services, so these stubs override
 * the real eligibility services and each module takes its own "not eligible" path. Passed to the
 * vendored bootstrap as an additional module, so it is added after the extension's own modules;
 * Modularity's container configurator lets the later registration win.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class WalletStubsModule implements ServiceModule {
	use ModuleClassNameIdTrait;

	/**
	 * The service IDs this module stubs, each resolving to a callable that returns false.
	 */
	public const STUBBED_SERVICE_IDS = array(
		'axo.eligibility.check',
		'card-fields.eligibility.check',
		'googlepay.eligibility.check',
		'applepay.eligibility.check',
	);

	/**
	 * The service IDs this module stubs, each resolving to the plain bool false.
	 */
	public const STUBBED_FLAG_IDS = array(
		'ppcp-local-apms.eligibility.check',
	);

	/**
	 * Services provided by this module.
	 *
	 * @return array<string, callable(ContainerInterface): mixed>
	 */
	public function services(): array {
		$not_eligible = static function (): callable {
			return static function (): bool {
				return false;
			};
		};

		$services = array();
		foreach ( self::STUBBED_SERVICE_IDS as $id ) {
			$services[ $id ] = $not_eligible;
		}
		foreach ( self::STUBBED_FLAG_IDS as $id ) {
			$services[ $id ] = static function (): bool {
				return false;
			};
		}

		return $services;
	}
}
