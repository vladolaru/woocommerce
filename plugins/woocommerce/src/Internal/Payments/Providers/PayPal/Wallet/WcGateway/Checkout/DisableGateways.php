<?php
/**
 * Determines whether specific gateways need to be disabled.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;

/**
 * Class DisableGateways
 */
class DisableGateways {
	private Context $context;
	private SettingsProvider $settings_provider;
	protected SettingsStatus $settings_status;
	private SubscriptionHelper $subscription_helper;

	public function __construct(
		SettingsProvider $settings_provider,
		SettingsStatus $settings_status,
		SubscriptionHelper $subscription_helper,
		Context $context
	) {
		$this->settings_provider   = $settings_provider;
		$this->settings_status     = $settings_status;
		$this->subscription_helper = $subscription_helper;
		$this->context             = $context;
	}

	/**
	 * Controls the logic for enabling/disabling gateways.
	 *
	 * @param array $methods The Gateways.
	 *
	 * @return array
	 */
	public function handler( array $methods ): array {
		if ( ! isset( $methods[ PayPalGateway::ID ] ) ) {
			return $methods;
		}
		if ( $this->disable_all_gateways() ) {
			unset( $methods[ PayPalGateway::ID ] );
			return $methods;
		}

		if ( ! $this->settings_status->is_smart_button_enabled_for_location( 'checkout' ) ) {
			if ( $this->subscription_helper->cart_contains_subscription() ) {
				unset( $methods[ PayPalGateway::ID ] );
			}
		}

		// Hide the PayPal gateway when the subscription cart cannot be processed (e.g. vaulting
		// disabled for a subscription that requires it, with no PayPal plan or manual renewals),
		// instead of showing it with a disabled button.
		if (
			isset( $methods[ PayPalGateway::ID ] )
			&& ! $this->subscription_helper->subscription_cart_processable( $this->settings_provider )
		) {
			unset( $methods[ PayPalGateway::ID ] );
		}

		if ( ! $this->needs_to_disable_gateways() ) {
			return $methods;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payment_method = wc_clean( wp_unslash( $_POST['payment_method'] ?? '' ) );
		if ( $payment_method && is_string( $payment_method ) ) {
			return array( $payment_method => $methods[ $payment_method ] );
		}

		return array( PayPalGateway::ID => $methods[ PayPalGateway::ID ] );
	}

	/**
	 * Whether all gateways should be disabled or not.
	 *
	 * @return bool
	 */
	private function disable_all_gateways(): bool {
		if ( is_null( WC()->payment_gateways ) ) {
			return false;
		}

		foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
			if ( PayPalGateway::ID === $gateway->id && $gateway->enabled !== 'yes' ) {
				return true;
			}
		}

		$merchant_email = $this->settings_provider->merchant_email();
		if ( empty( $merchant_email ) || ! is_email( $merchant_email ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the Gateways need to be disabled. When we come to the checkout with a running PayPal
	 * session, we need to disable the other Gateways, so the customer can smoothly sail through the
	 * process.
	 *
	 * @return bool
	 */
	private function needs_to_disable_gateways(): bool {
		return $this->context->is_paypal_continuation();
	}
}
