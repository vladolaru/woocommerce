<?php
/**
 * NativePaymentsGatewayRegistry class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Payment_Gateway;

/**
 * Registers native payments gateways when the native runtime owns the site.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class NativePaymentsGatewayRegistry implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Native payment gateway providers.
	 *
	 * @var array<string,PaymentGatewayProviderContract>
	 */
	private array $providers = array();

	/**
	 * Provider resolvers, called the first time WooCommerce builds its gateway list.
	 *
	 * @var array<int,callable>
	 */
	private array $provider_resolvers = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register a provider that publishes native payment gateways.
	 *
	 * The composition root owns provider selection so this registry remains provider-neutral.
	 *
	 * @param PaymentGatewayProviderContract $provider Payment gateway provider.
	 */
	public function register_provider( PaymentGatewayProviderContract $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Register a provider that is resolved only when WooCommerce builds its gateway list.
	 *
	 * Most requests never build the gateway list, so they do not pay for the provider and its payment services.
	 *
	 * @since 11.2.0
	 *
	 * @param callable $resolver Returns the payment gateway provider.
	 * @phpstan-param callable(): PaymentGatewayProviderContract $resolver
	 */
	public function register_provider_resolver( callable $resolver ): void {
		$this->provider_resolvers[] = $resolver;
	}

	/**
	 * Register gateway hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) ) ) {
			add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		}
	}

	/**
	 * Add native payment gateway instances.
	 *
	 * A non-array from an earlier callback becomes an empty list, as WooCommerce's own loop loads nothing from null or a string.
	 *
	 * @param mixed $gateways Registered gateway classes or instances.
	 * @return array<int|string,mixed>
	 */
	public function register_gateway( $gateways ): array {
		if ( ! is_array( $gateways ) ) {
			$gateways = array();
		}

		if ( ! $this->arbiter->should_native_register() ) {
			return $gateways;
		}

		$resolvers                = $this->provider_resolvers;
		$this->provider_resolvers = array();
		foreach ( $resolvers as $resolver ) {
			$provider = $resolver();
			if ( $provider instanceof PaymentGatewayProviderContract ) {
				$this->register_provider( $provider );
			}
		}

		foreach ( $this->providers as $provider ) {
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
