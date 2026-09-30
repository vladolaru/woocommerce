<?php
/**
 * WooPaymentsGatewayListController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Shapes WooCommerce's payment gateway list around the WooPayments gateways, as the client does.
 *
 * The settings page lists only the card gateway; the split gateways stay registered for checkout
 * (client 11.1.0 `includes/class-wc-payments.php:742,1024-1031`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsGatewayListController implements RegisterHooksInterface {

	/**
	 * Runs before other listeners so they see the list without split gateways, as in the client.
	 */
	private const PAYMENT_GATEWAYS_DISPLAY_HOOK_PRIORITY = 5;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * Register the gateway list hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_admin_field_payment_gateways', array( $this, 'handle_woocommerce_admin_field_payment_gateways' ) ) ) {
			add_action(
				'woocommerce_admin_field_payment_gateways',
				array( $this, 'handle_woocommerce_admin_field_payment_gateways' ),
				self::PAYMENT_GATEWAYS_DISPLAY_HOOK_PRIORITY
			);
		}
	}

	/**
	 * Keep only the canonical native WooPayments gateway in settings displays.
	 *
	 * Split gateways remain registered for checkout and payment processing. This callback only
	 * changes the request-local collection after the settings display hook fires.
	 *
	 * @internal
	 */
	public function handle_woocommerce_admin_field_payment_gateways(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		$payment_gateways  = WC()->payment_gateways();
		$gateways          = $payment_gateways->payment_gateways;
		$canonical_gateway = null;

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof NativeWooPaymentsGateway && OrderPaymentStore::GATEWAY_ID === $gateway->id ) {
				$canonical_gateway = $gateway;
				break;
			}
		}

		if ( ! $canonical_gateway instanceof NativeWooPaymentsGateway ) {
			return;
		}

		foreach ( $gateways as $index => $gateway ) {
			if ( $gateway instanceof NativeWooPaymentsGateway && $gateway !== $canonical_gateway ) {
				unset( $payment_gateways->payment_gateways[ $index ] );
			}
		}
	}
}
