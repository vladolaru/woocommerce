<?php
/**
 * Helper class for the subscriptions. Contains methods to determine
 * whether the cart contains a subscription, the current product is
 * a subscription or the subscription plugin is activated in the first place.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper;

use WC_Product;
use WC_Product_Variable;
use WC_Subscriptions;
use WC_Subscriptions_Product;
use WCS_Manual_Renewal_Manager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\NotFoundException;

/**
 * Class SubscriptionHelper
 */
class SubscriptionHelper {

	public const SUBSCRIPTION_MODE_VALUE_VAULTING = 'vaulting_api';
	public const SUBSCRIPTION_MODE_VALUE_DISABLED = 'disable_paypal_subscriptions';

	/**
	 * Whether the current product is a subscription.
	 *
	 * @return bool
	 */
	public function current_product_is_subscription(): bool {
		if ( ! $this->plugin_is_active() ) {
			return false;
		}
		$product = wc_get_product();
		return $product && WC_Subscriptions_Product::is_subscription( $product );
	}

	/**
	 * Whether the current cart contains subscriptions.
	 *
	 * @return bool
	 */
	public function cart_contains_subscription(): bool {
		if ( ! $this->plugin_is_active() ) {
			return false;
		}
		$cart = WC()->cart;
		/**
		 * Don't use `$cart->is_empty()` for checking for an empty cart.
		 * This is maybe called so early that it can corrupt it because it loads it than from session
		 */
		if ( ! $cart || empty( $cart->cart_contents ) ) {
			return false;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item['data'] ) || ! is_a( $item['data'], WC_Product::class ) ) {
				continue;
			}
			if ( WC_Subscriptions_Product::is_subscription( $item['data'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the cart contains a subscription renewal payment (e.g., a customer manually
	 * renewing a subscription), as opposed to a new subscription being purchased. WooCommerce
	 * Subscriptions may route a manual renewal through the cart/Checkout block instead of the
	 * classic order-pay endpoint, so this can't be inferred from the URL alone.
	 *
	 * @return bool
	 */
	public function cart_contains_renewal(): bool {
		if ( ! $this->plugin_is_active() || ! function_exists( 'wcs_cart_contains_renewal' ) ) {
			return false;
		}

		return (bool) wcs_cart_contains_renewal();
	}

	/**
	 * Whether pay for order contains subscriptions.
	 *
	 * @return bool
	 */
	public function order_pay_contains_subscription(): bool {
		if ( ! $this->plugin_is_active() || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		global $wp;
		$order_id = (int) $wp->query_vars['order-pay'];
		if ( 0 === $order_id ) {
			return false;
		}

		return $this->has_subscription( $order_id );
	}

	/**
	 * Whether manual renewals are accepted.
	 *
	 * @return bool
	 */
	public function accept_manual_renewals(): bool {
		if ( ! class_exists( WCS_Manual_Renewal_Manager::class ) ) {
			return false;
		}
		return WCS_Manual_Renewal_Manager::is_manual_renewal_enabled();
	}

	/**
	 * Whether the subscription plugin is active or not.
	 *
	 * @return bool
	 */
	public function plugin_is_active(): bool {

		return class_exists( WC_Subscriptions::class ) && class_exists( WC_Subscriptions_Product::class );
	}

	/**
	 * Checks if order contains subscription.
	 *
	 * @param  int $order_id The order Id.
	 * @return boolean Whether order is a subscription or not.
	 */
	public function has_subscription( $order_id ): bool {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * Checks if page is pay for order and change subscription payment page.
	 *
	 * @return bool Whether page is change subscription or not.
	 */
	public function is_subscription_change_payment(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['pay_for_order'] ) || ! isset( $_GET['change_payment_method'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the PayPal button is allowed for the current (subscription) cart.
	 *
	 * This is the single rule shared by the classic cart, block cart and mini-cart so the
	 * button is displayed (or hidden) consistently:
	 * - A non-subscription cart is always allowed.
	 * - A manual-renewal-only subscription (Accept Manual Renewals enabled) is always
	 *   allowed, since it is processed as a plain Orders API payment.
	 * - Otherwise a vault token must be savable.
	 *
	 * @param bool $can_save_vault_token Whether a vault token can be saved.
	 * @return bool
	 */
	public function paypal_subscription_button_allowed( bool $can_save_vault_token ): bool {
		if ( ! $this->cart_contains_subscription() ) {
			return true;
		}

		if ( $this->accept_manual_renewals() ) {
			return true;
		}

		return $can_save_vault_token;
	}

	/**
	 * Whether the current (subscription) cart can actually be processed by the PayPal gateway.
	 *
	 * This resolves the {@see self::paypal_subscription_button_allowed()} rule from settings, so
	 * callers that only have a {@see SettingsProvider} (such as the classic-checkout
	 * gateway-availability filter) can hide the PayPal gateway when it could not fulfil the payment
	 * instead of leaving it visible with a disabled button. A non-subscription cart is always
	 * processable.
	 *
	 * @param SettingsProvider $settings_provider The settings provider.
	 * @return bool
	 * @throws NotFoundException If setting is not found.
	 */
	public function subscription_cart_processable( SettingsProvider $settings_provider ): bool {
		if ( ! $this->cart_contains_subscription() ) {
			return true;
		}

		// Mirrors SmartButton::can_save_vault_token(): a token can only be saved with a
		// connected merchant and vaulting ("Save PayPal and Venmo") enabled.
		$can_save_vault_token = ! empty( $settings_provider->merchant_data()->client_id )
			&& $settings_provider->save_paypal_and_venmo();

		return $this->paypal_subscription_button_allowed( $can_save_vault_token );
	}

	/**
	 * Resolves how a subscription checkout must be routed.
	 *
	 * This is the single deciding function for the subscriptions mode. Rules:
	 * - WooCommerce Subscriptions inactive yields an empty string.
	 * - The `woocommerce_paypal_payments_subscription_mode_disabled` filter forces the
	 *   disabled mode.
	 * - Otherwise vaulting ("Save PayPal and Venmo") yields the vaulting mode, and no
	 *   vaulting yields the disabled mode.
	 *
	 * @param SettingsProvider $settings_provider The settings provider.
	 * @return string One of the SUBSCRIPTION_MODE_VALUE_* constants, or an empty string
	 *                when WooCommerce Subscriptions is not active.
	 */
	public function resolve_subscription_mode( SettingsProvider $settings_provider ): string {
		if ( ! $this->plugin_is_active() ) {
			return '';
		}

		/**
		 * Filters whether the subscription mode is forced to disabled.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $subscription_mode_disabled True to disable the subscription mode. Default false.
		 */
		$subscription_mode_disabled = (bool) apply_filters(
			'woocommerce_paypal_payments_subscription_mode_disabled',
			false
		);

		if ( $subscription_mode_disabled ) {
			return self::SUBSCRIPTION_MODE_VALUE_DISABLED;
		}

		return $settings_provider->save_paypal_and_venmo()
			? self::SUBSCRIPTION_MODE_VALUE_VAULTING
			: self::SUBSCRIPTION_MODE_VALUE_DISABLED;
	}

	/**
	 * Returns PayPal subscription plan id from WC subscription product.
	 *
	 * @return string
	 */
	public function paypal_subscription_id(): string {
		if ( $this->current_product_is_subscription() ) {
			$product = wc_get_product();
			assert( $product instanceof WC_Product );

			if ( 'subscription' === $product->get_type() && $product->meta_exists( 'ppcp_subscription_plan' ) ) {
				return $product->get_meta( 'ppcp_subscription_plan' )['id'];
			}
		}

		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return '';
		}
		$items = $cart->get_cart_contents();
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );
			assert( $product instanceof WC_Product );

			if ( 'subscription' === $product->get_type() && $product->meta_exists( 'ppcp_subscription_plan' ) ) {
				return $product->get_meta( 'ppcp_subscription_plan' )['id'];
			}

			if ( 'variable-subscription' === $product->get_type() ) {
				assert( $product instanceof WC_Product_Variable );

				$product_variations = $product->get_available_variations();
				foreach ( $product_variations as $variation ) {
					/**
					 * The product is a subscription variation, whose methods Psalm does not know.
					 *
					 * @psalm-suppress UndefinedMethod
					 */
					$variation_product = wc_get_product( $variation['variation_id'] ) ?? '';
					if ( $variation_product && $variation_product->meta_exists( 'ppcp_subscription_plan' ) ) {
						return $variation_product->get_meta( 'ppcp_subscription_plan' )['id'];
					}
				}
			}
		}

		return '';
	}

	/**
	 * Returns the locations on the page which have subscription products.
	 *
	 * @return array
	 */
	public function locations_with_subscription_product(): array {
		$cart_contains_renewal = $this->cart_contains_renewal();

		return array(
			'product'  => is_product() && $this->current_product_is_subscription(),
			'payorder' => ( is_wc_endpoint_url( 'order-pay' ) && $this->order_pay_contains_subscription() ) || $cart_contains_renewal,
			'cart'     => $this->cart_contains_subscription() && ! $cart_contains_renewal,
		);
	}
}
