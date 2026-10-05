<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use WC_Product;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;

/**
 * Decides whether Pay Later messaging may render, and whether it is filtered out.
 *
 * Ports the eligibility half of the v5 SmartButton::should_load_messages()
 * and is_pay_later_filter_enabled_for_location().
 */
class MessagesEligibility {

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;
	/**
	 * The settings status.
	 *
	 * @var SettingsStatus
	 */
	private SettingsStatus $settings_status;
	/**
	 * The messages apply.
	 *
	 * @var MessagesApply
	 */
	private MessagesApply $messages_apply;
	/**
	 * The free trial helper.
	 *
	 * @var FreeTrialSubscriptionHelper
	 */
	private FreeTrialSubscriptionHelper $free_trial_helper;

	/**
	 * MessagesEligibility constructor.
	 *
	 * @param SettingsProvider            $settings_provider The settings provider.
	 * @param SettingsStatus              $settings_status   The settings status.
	 * @param MessagesApply               $messages_apply    The messages apply.
	 * @param FreeTrialSubscriptionHelper $free_trial_helper The free trial helper.
	 */
	public function __construct(
		SettingsProvider $settings_provider,
		SettingsStatus $settings_status,
		MessagesApply $messages_apply,
		FreeTrialSubscriptionHelper $free_trial_helper
	) {
		$this->settings_provider = $settings_provider;
		$this->settings_status   = $settings_status;
		$this->messages_apply    = $messages_apply;
		$this->free_trial_helper = $free_trial_helper;
	}

	/**
	 * Whether messaging is enabled for a messaging settings location.
	 *
	 * The location must already be normalized to the settings vocabulary
	 * (cart, checkout, product, ...) — see SdkV6Manager::messages_settings_location().
	 *
	 * @param string $location The messaging settings location.
	 */
	public function is_enabled_for_location( string $location ): bool {
		if ( '' === $location ) {
			return false;
		}

		/**
		 * The filter returning whether Pay Later messaging should render at all.
		 * Shared with the v5 SmartButton.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $render Whether to render Pay Later messaging; true by default.
		 */
		if ( ! apply_filters( 'woocommerce_paypal_payments_should_render_pay_later_messaging', true ) ) {
			return false;
		}

		if ( ! $this->settings_provider->gateway_enabled( PayPalGateway::ID ) ) {
			return false;
		}

		if ( ! $this->settings_status->is_pay_later_messaging_enabled()
			|| ! $this->settings_status->has_pay_later_messaging_locations() ) {
			return false;
		}

		if ( ! $this->messages_apply->for_country() ) {
			return false;
		}

		if ( $this->free_trial_helper->is_free_trial_cart() ) {
			return false;
		}

		return $this->settings_status->is_pay_later_messaging_enabled_for_location( $location );
	}

	/**
	 * Whether a merchant filter has switched Pay Later off for this context.
	 *
	 * @param string $context The page context (product, cart, checkout, ...).
	 */
	public function is_hidden( string $context ): bool {
		if ( 'product' === $context ) {
			/**
			 * Allows to decide if Pay Later should be disabled for a given product.
			 * Shared with the v5 SmartButton.
			 *
			 * @since 11.3.0
			 *
			 * @param bool  $disabled Whether the Pay Later button or message is disabled for the product; false by default.
			 * @param array $context  The product context data.
			 */
			return (bool) apply_filters(
				'woocommerce_paypal_payments_product_buttons_paylater_disabled',
				false,
				$this->product_filter_context_data()
			);
		}

		/**
		 * Allows to decide if Pay Later should be disabled on a given context.
		 * Shared with the v5 SmartButton.
		 *
		 * @since 11.3.0
		 *
		 * @param bool   $disabled Whether the Pay Later button or message is disabled in the location; false by default.
		 * @param string $context  The location, such as cart or checkout.
		 */
		return (bool) apply_filters(
			'woocommerce_paypal_payments_buttons_paylater_disabled',
			false,
			$context
		);
	}

	/**
	 * The context data passed to the product-level Pay Later filter.
	 */
	private function product_filter_context_data(): array {
		$product = wc_get_product();
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		return array(
			'product'     => $product,
			'order_total' => (float) $product->get_price( 'raw' ),
		);
	}
}
