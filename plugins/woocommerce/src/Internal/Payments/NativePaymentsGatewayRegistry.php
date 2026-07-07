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
	 * WooPayments provider.
	 *
	 * @var Providers\WooPayments\WooPaymentsProvider
	 */
	private Providers\WooPayments\WooPaymentsProvider $provider;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter              $arbiter  Runtime owner arbiter.
	 * @param Providers\WooPayments\WooPaymentsProvider $provider WooPayments provider.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, Providers\WooPayments\WooPaymentsProvider $provider ): void {
		$this->arbiter  = $arbiter;
		$this->provider = $provider;
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
	 * Add the native WooPayments gateway instances.
	 *
	 * @param array<int|string,mixed> $gateways Registered gateway classes or instances.
	 * @return array<int|string,mixed>
	 */
	public function register_gateway( array $gateways ): array {
		if ( ! $this->arbiter->should_native_register() || ! $this->provider->can_process_payments() ) {
			return $gateways;
		}

		foreach ( $this->provider->get_payment_gateways() as $gateway ) {
			if ( ! $gateway instanceof WC_Payment_Gateway || $this->has_gateway( $gateways, $gateway ) ) {
				continue;
			}

			$gateways[] = $gateway;
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
