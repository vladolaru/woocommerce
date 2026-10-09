<?php
/**
 * ProviderGatewaysController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Payment_Gateway;

/**
 * Adds the payment provider's gateways to the WooCommerce gateway list while the provider's check passes.
 *
 * The provider resolves lazily, on the first gateway list build that needs it.
 *
 * @since 11.0.0
 * @internal
 */
class ProviderGatewaysController implements RegisterHooksInterface {

	/**
	 * Returns the provider; null once it has run.
	 *
	 * @var callable|null
	 * @phpstan-var (callable(): PaymentGatewayProviderInterface)|null
	 */
	private $resolver = null;

	/**
	 * Whether the provider's gateways belong in the gateway list now; null when no provider is set.
	 *
	 * @var callable|null
	 * @phpstan-var (callable(): bool)|null
	 */
	private $should_register_gateways = null;

	/**
	 * The resolved provider.
	 *
	 * @var PaymentGatewayProviderInterface|null
	 */
	private ?PaymentGatewayProviderInterface $provider = null;

	/**
	 * Set the gateway provider, resolved only when WooCommerce builds its gateway list while the provider's check passes.
	 *
	 * Most requests never build the gateway list, so they do not pay for the provider and its payment services. The check
	 * is consulted at registration and on every gateway list build, so the provider's gateways leave the list when it fails.
	 *
	 * @since 11.2.0
	 *
	 * @param callable $resolver                 Returns the payment gateway provider.
	 * @param callable $should_register_gateways Whether the provider's gateways belong in the gateway list now.
	 * @phpstan-param callable(): PaymentGatewayProviderInterface $resolver
	 * @phpstan-param callable(): bool $should_register_gateways
	 */
	public function set_provider( callable $resolver, callable $should_register_gateways ): void {
		$this->resolver                 = $resolver;
		$this->should_register_gateways = $should_register_gateways;
		$this->provider                 = null;
	}

	/**
	 * Register gateway hooks while the provider's check passes.
	 */
	public function register() {
		if ( ! $this->provider_check_passes() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_payment_gateways', array( $this, 'add_provider_gateways' ) ) ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_provider_gateways' ) );
		}
	}

	/**
	 * Add the provider's gateways to the gateway list while its check passes.
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

		if ( ! $this->provider_check_passes() ) {
			return $gateways;
		}

		$provider = $this->resolve_provider();
		if ( null === $provider ) {
			return $gateways;
		}

		foreach ( $provider->get_payment_gateways() as $gateway ) {
			if ( ! $gateway instanceof WC_Payment_Gateway || $this->has_gateway( $gateways, $gateway ) ) {
				continue;
			}

			$gateways[] = $gateway;
		}

		return $gateways;
	}

	/**
	 * Resolve the provider on first use. A resolver that returns no provider leaves no provider set.
	 *
	 * @return PaymentGatewayProviderInterface|null
	 */
	private function resolve_provider(): ?PaymentGatewayProviderInterface {
		if ( null === $this->provider && null !== $this->resolver ) {
			$provider       = ( $this->resolver )();
			$this->resolver = null;
			if ( $provider instanceof PaymentGatewayProviderInterface ) {
				$this->provider = $provider;
			} else {
				$this->should_register_gateways = null;
			}
		}

		return $this->provider;
	}

	/**
	 * Tell whether a provider is set and its check passes now.
	 *
	 * @return bool
	 */
	private function provider_check_passes(): bool {
		return null !== $this->should_register_gateways && ( $this->should_register_gateways )();
	}

	/**
	 * Tell whether a gateway has already been registered.
	 *
	 * A gateway already in the list, as the same object, its class name or its ID, is not added again, so this controller
	 * never appends a provider gateway the list already contains.
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
