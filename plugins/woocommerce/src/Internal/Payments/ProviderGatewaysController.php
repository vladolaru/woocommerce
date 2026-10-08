<?php
/**
 * ProviderGatewaysController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Payment_Gateway;

/**
 * Adds the gateways of each payment provider to the WooCommerce gateway list while that provider's check passes.
 *
 * Providers resolve lazily, on the first gateway list build that needs them.
 *
 * @since 11.0.0
 * @internal
 */
class ProviderGatewaysController implements RegisterHooksInterface {

	/**
	 * Resolved gateway providers, by provider ID.
	 *
	 * @var array<string,PaymentGatewayProviderContract>
	 */
	private array $providers = array();

	/**
	 * Gateway checks of the resolved providers, by provider ID.
	 *
	 * @var array<string,callable>
	 */
	private array $provider_checks = array();

	/**
	 * Providers not resolved yet, each with its resolver and its gateway check.
	 *
	 * @var array<int,array{resolver:callable,should_register_gateways:callable}>
	 */
	private array $pending_providers = array();

	/**
	 * Add a gateway provider, resolved only when WooCommerce builds its gateway list while the provider's check passes.
	 *
	 * Most requests never build the gateway list, so they do not pay for the provider and its payment services. The check
	 * is consulted at registration and on every gateway list build, so the provider's gateways leave the list when it fails.
	 *
	 * @since 11.2.0
	 *
	 * @param callable $resolver                 Returns the payment gateway provider.
	 * @param callable $should_register_gateways Whether the provider's gateways belong in the gateway list now.
	 * @phpstan-param callable(): PaymentGatewayProviderContract $resolver
	 * @phpstan-param callable(): bool $should_register_gateways
	 */
	public function add_provider( callable $resolver, callable $should_register_gateways ): void {
		$this->pending_providers[] = array(
			'resolver'                 => $resolver,
			'should_register_gateways' => $should_register_gateways,
		);
	}

	/**
	 * Register gateway hooks while at least one provider's check passes.
	 */
	public function register() {
		if ( ! $this->has_provider_with_passing_check() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_payment_gateways', array( $this, 'add_provider_gateways' ) ) ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_provider_gateways' ) );
		}
	}

	/**
	 * Add the gateways of the providers whose check passes to the gateway list.
	 *
	 * A non-array from an earlier callback becomes an empty list, as WooCommerce's own loop loads nothing from null or a string.
	 *
	 * @param mixed $gateways Registered gateway classes or instances.
	 * @return array<int|string,mixed>
	 */
	public function add_provider_gateways( $gateways ): array {
		if ( ! is_array( $gateways ) ) {
			$gateways = array();
		}

		$this->resolve_providers_with_passing_checks();

		foreach ( $this->providers as $provider_id => $provider ) {
			if ( ! ( $this->provider_checks[ $provider_id ] )() ) {
				continue;
			}

			foreach ( $provider->get_payment_gateways() as $gateway ) {
				if ( ! $gateway instanceof WC_Payment_Gateway || $this->has_gateway( $gateways, $gateway ) ) {
					continue;
				}

				$gateways[] = $gateway;
			}
		}

		return $gateways;
	}

	/**
	 * Resolve the pending providers whose check passes now; the others wait for a later gateway list build.
	 */
	private function resolve_providers_with_passing_checks(): void {
		$pending                 = $this->pending_providers;
		$this->pending_providers = array();
		foreach ( $pending as $entry ) {
			if ( ! ( $entry['should_register_gateways'] )() ) {
				$this->pending_providers[] = $entry;
				continue;
			}

			$provider = ( $entry['resolver'] )();
			if ( $provider instanceof PaymentGatewayProviderContract ) {
				$this->providers[ $provider->get_id() ]       = $provider;
				$this->provider_checks[ $provider->get_id() ] = $entry['should_register_gateways'];
			}
		}
	}

	/**
	 * Tell whether the check of any added provider, resolved or not, passes now.
	 *
	 * @return bool
	 */
	private function has_provider_with_passing_check(): bool {
		foreach ( $this->provider_checks as $should_register_gateways ) {
			if ( $should_register_gateways() ) {
				return true;
			}
		}

		foreach ( $this->pending_providers as $entry ) {
			if ( ( $entry['should_register_gateways'] )() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether a gateway has already been registered.
	 *
	 * @param array<int|string,mixed> $gateways Registered gateway classes or instances.
	 * @param WC_Payment_Gateway      $provider_gateway Provider gateway instance.
	 * @return bool
	 */
	private function has_gateway( array $gateways, WC_Payment_Gateway $provider_gateway ): bool {
		$provider_gateway_class = get_class( $provider_gateway );

		foreach ( $gateways as $gateway ) {
			if ( $gateway === $provider_gateway ) {
				return true;
			}

			if ( is_string( $gateway ) && $gateway === $provider_gateway_class ) {
				return true;
			}

			if ( $gateway instanceof WC_Payment_Gateway && $gateway->id === $provider_gateway->id ) {
				return true;
			}
		}

		return false;
	}
}
