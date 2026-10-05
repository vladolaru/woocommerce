<?php
/**
 * WooPaymentsExpressPaymentMethodTypes class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Provider-owned helpers for WooPayments Express Checkout Stripe payment method types.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsExpressPaymentMethodTypes {

	/**
	 * WooPayments Express Checkout method ID for Apple Pay / Google Pay.
	 */
	public const EXPRESS_METHOD_PAYMENT_REQUEST = 'payment_request';

	/**
	 * WooPayments Express Checkout method ID for Amazon Pay.
	 */
	public const EXPRESS_METHOD_AMAZON_PAY = 'amazon_pay';

	/**
	 * Stripe card payment method type.
	 */
	public const STRIPE_TYPE_CARD = 'card';

	/**
	 * Stripe Amazon Pay payment method type.
	 */
	public const STRIPE_TYPE_AMAZON_PAY = 'amazon_pay';

	/**
	 * Checkout field carrying Stripe payment method types used to initialize ECE.
	 */
	public const CHECKOUT_FIELD = 'wcpay-express-payment-method-types';

	/**
	 * Checkout field carrying the express checkout surface context.
	 */
	public const CONTEXT_FIELD = 'wcpay-express-checkout-context';

	/**
	 * Provider data key for validated submitted express payment method types.
	 */
	public const PROVIDER_DATA_KEY = 'express_payment_method_types';

	/**
	 * Provider data key for the submitted express checkout context.
	 */
	public const PROVIDER_CONTEXT_KEY = 'express_checkout_context';

	/**
	 * Get allowed Stripe payment method types for the enabled express methods in a context.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param array<int,string>         $enabled_methods Enabled WooPayments express method IDs.
	 * @param string                    $context         Express checkout context.
	 * @param string                    $currency        Optional order/cart currency.
	 * @return array<int,string>
	 */
	public static function get_allowed_payment_method_types_for_methods( WooPaymentsAccountService $account_service, array $enabled_methods, string $context = 'checkout', string $currency = '' ): array {
		$allowed = array();
		$context = self::normalize_context( $context );

		if ( in_array( self::EXPRESS_METHOD_PAYMENT_REQUEST, $enabled_methods, true ) ) {
			$allowed[] = self::STRIPE_TYPE_CARD;
		}

		if ( in_array( self::EXPRESS_METHOD_AMAZON_PAY, $enabled_methods, true ) && self::can_use_amazon_pay( $account_service, $context, $currency ) ) {
			$allowed[] = self::STRIPE_TYPE_AMAZON_PAY;
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Get the express checkout methods offered in a context.
	 *
	 * The one resolver for the buttons and for the charge-time allowlist, so a method a shopper can start is a method
	 * the charge accepts, and the reverse. Client 11.1.0 derives both from gateway enablement (gateway :5284-5315).
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $context         Express checkout context.
	 * @param string                    $currency        Optional order/cart currency; the store currency when empty.
	 * @return array<int,string>
	 */
	public static function get_enabled_methods_for_context( WooPaymentsAccountService $account_service, string $context = 'checkout', string $currency = '' ): array {
		$context = self::normalize_context( $context );
		$methods = self::get_configured_methods_for_context( $account_service, $context );
		// The buttons pass no currency and the charge passes the order's, so the filter always gets a real one to key on.
		$currency = '' === $currency ? get_woocommerce_currency() : $currency;

		/**
		 * Filters native WooPayments platform express checkout methods for a context.
		 *
		 * Applies to the express buttons and to the payment types the charge accepts from them.
		 *
		 * @param array<int,string> $methods  Enabled method IDs.
		 * @param string            $context  Express checkout context.
		 * @param string            $currency Order or cart currency; the store currency when none is given.
		 *
		 * @since 11.0.0
		 */
		$filtered_methods = apply_filters( 'woocommerce_woopayments_express_checkout_enabled_methods', $methods, $context, $currency );
		$filtered_methods = is_array( $filtered_methods ) ? self::normalize_express_method_ids( $filtered_methods ) : $methods;

		return array_values(
			array_filter(
				$filtered_methods,
				static function ( string $method ) use ( $account_service, $context, $currency ): bool {
					return self::EXPRESS_METHOD_AMAZON_PAY !== $method || self::is_amazon_pay_usable( $account_service, $context, $currency );
				}
			)
		);
	}

	/**
	 * Get the Stripe payment method types the express methods of a context use.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $context         Express checkout context.
	 * @param string                    $currency        Optional order/cart currency.
	 * @return array<int,string>
	 */
	public static function get_allowed_payment_method_types_for_context( WooPaymentsAccountService $account_service, string $context = 'checkout', string $currency = '' ): array {
		return self::get_allowed_payment_method_types_for_methods(
			$account_service,
			self::get_enabled_methods_for_context( $account_service, $context, $currency ),
			$context,
			$currency
		);
	}

	/**
	 * Tell whether Amazon Pay is usable for express checkout, whichever locations list it.
	 *
	 * The client's `can_use_amazon_pay()` (11.1.0 class-wc-payments-express-checkout-button-helper.php:362-388).
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $context         Express checkout context.
	 * @param string                    $currency        Optional order/cart currency; the store currency when empty.
	 * @return bool
	 */
	public static function is_amazon_pay_usable( WooPaymentsAccountService $account_service, string $context = 'checkout', string $currency = '' ): bool {
		if ( ! self::is_amazon_pay_button_available( $account_service ) ) {
			return false;
		}

		return in_array(
			self::STRIPE_TYPE_AMAZON_PAY,
			self::get_allowed_payment_method_types_for_methods( $account_service, array( self::EXPRESS_METHOD_AMAZON_PAY ), $context, $currency ),
			true
		);
	}

	/**
	 * Tell whether the client's button-only Amazon Pay guards hold.
	 *
	 * Mirrors WooPayments' `can_use_amazon_pay()`: no Amazon Pay button while express methods sit in the
	 * payment-method list, and the base gateway availability (gateway enabled, HTTPS in live mode outside admin).
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	private static function is_amazon_pay_button_available( WooPaymentsAccountService $account_service ): bool {
		if ( self::is_express_checkout_in_payment_methods_enabled( $account_service ) ) {
			return false;
		}

		if ( ! $account_service->is_gateway_enabled() ) {
			return false;
		}

		return is_admin() || $account_service->is_test_mode_enabled() || wc_checkout_is_https();
	}

	/**
	 * Get the express checkout methods configured for a context.
	 *
	 * The location setting, or Apple Pay / Google Pay alone while the legacy switch is on and no location setting exists.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $context         Express checkout context.
	 * @return array<int,string>
	 */
	private static function get_configured_methods_for_context( WooPaymentsAccountService $account_service, string $context ): array {
		$setting_context = 'pay_for_order' === $context ? 'checkout' : $context;
		$methods         = $account_service->get_gateway_setting( 'express_checkout_' . $setting_context . '_methods', null );

		if ( is_array( $methods ) ) {
			return self::normalize_express_method_ids( $methods );
		}

		return $account_service->is_payment_request_enabled() ? array( self::EXPRESS_METHOD_PAYMENT_REQUEST ) : array();
	}

	/**
	 * Tell whether express checkout methods are shown in the checkout payment-method list instead of as buttons.
	 *
	 * Client 11.1.0 `is_express_checkout_in_payment_methods_enabled()` (gateway :1020-1023).
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	public static function is_express_checkout_in_payment_methods_enabled( WooPaymentsAccountService $account_service ): bool {
		return WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled()
			&& self::is_truthy_gateway_setting( $account_service, 'express_checkout_in_payment_methods' );
	}

	/**
	 * Tell whether taxes are on and calculated from the shopper's billing address.
	 *
	 * Express wallet sheets only recalculate totals when the shipping address changes, so the billing address
	 * they send at placement can add tax the sheet never showed.
	 *
	 * @return bool
	 */
	public static function is_tax_based_on_billing_address(): bool {
		return function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && 'billing' === get_option( 'woocommerce_tax_based_on' );
	}

	/**
	 * Create provider data from the submitted checkout field value.
	 *
	 * @param mixed $value Submitted checkout field value.
	 * @return array<string,array<int,string>>
	 */
	public static function provider_data_from_checkout_value( $value ): array {
		$payment_method_types = self::normalize_payment_method_types( self::decode_payment_method_types( $value ) );

		return empty( $payment_method_types ) ? array() : array( self::PROVIDER_DATA_KEY => $payment_method_types );
	}

	/**
	 * Create provider data from the submitted express checkout context field value.
	 *
	 * @param mixed $value Submitted checkout field value.
	 * @return array<string,string>
	 */
	public static function provider_context_from_checkout_value( $value ): array {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return array();
		}

		return array( self::PROVIDER_CONTEXT_KEY => self::normalize_context( (string) $value ) );
	}

	/**
	 * Normalize an express checkout context.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function normalize_context( string $context ): string {
		$context = sanitize_key( $context );

		return in_array( $context, array( 'product', 'cart', 'checkout', 'pay_for_order' ), true ) ? $context : 'checkout';
	}

	/**
	 * Validate submitted Stripe payment method types against the server-side allowlist.
	 *
	 * @param mixed             $submitted_types Submitted payment method types.
	 * @param array<int,string> $allowed_types   Server-allowed payment method types.
	 * @return array<int,string>
	 */
	public static function validate_submitted_payment_method_types( $submitted_types, array $allowed_types ): array {
		if ( ! is_array( $submitted_types ) ) {
			return array();
		}

		$submitted_types = self::normalize_payment_method_types( $submitted_types );

		return array_values( array_intersect( $submitted_types, $allowed_types ) );
	}

	/**
	 * Normalize submitted Stripe payment method types.
	 *
	 * @param array<int,mixed> $payment_method_types Raw payment method types.
	 * @return array<int,string>
	 */
	private static function normalize_payment_method_types( array $payment_method_types ): array {
		$normalized = array();

		foreach ( $payment_method_types as $payment_method_type ) {
			if ( ! is_scalar( $payment_method_type ) ) {
				continue;
			}

			$payment_method_type = sanitize_key( (string) $payment_method_type );
			if ( in_array( $payment_method_type, array( self::STRIPE_TYPE_CARD, self::STRIPE_TYPE_AMAZON_PAY ), true ) ) {
				$normalized[] = $payment_method_type;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Decode a JSON-encoded checkout field value.
	 *
	 * @param mixed $value Submitted field value.
	 * @return array<int,mixed>
	 */
	private static function decode_payment_method_types( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize WooPayments express method IDs.
	 *
	 * @param array<int,mixed> $methods Method IDs.
	 * @return array<int,string>
	 */
	private static function normalize_express_method_ids( array $methods ): array {
		$normalized = array();

		foreach ( $methods as $method ) {
			if ( ! is_scalar( $method ) ) {
				continue;
			}

			$method = sanitize_key( (string) $method );
			if ( '' !== $method ) {
				$normalized[] = $method;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Tell whether Amazon Pay can be used for express checkout.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $context         Express checkout context.
	 * @param string                    $currency        Optional order/cart currency.
	 * @return bool
	 */
	private static function can_use_amazon_pay( WooPaymentsAccountService $account_service, string $context, string $currency = '' ): bool {
		$context = self::normalize_context( $context );

		$account_data = $account_service->get_cached_account_data();
		if ( ! WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) ) {
			return false;
		}

		if ( ! self::is_amazon_pay_enabled_by_merchant( $account_service ) ) {
			return false;
		}

		if ( self::is_tax_based_on_billing_address() && 'pay_for_order' !== $context ) {
			return false;
		}

		if ( ! self::is_amazon_pay_enabled_for_account( $account_service, $account_data ) ) {
			return false;
		}

		$currency = '' === $currency ? get_woocommerce_currency() : $currency;

		return self::is_amazon_pay_currency_supported( strtolower( $currency ), strtoupper( (string) ( $account_data['country'] ?? '' ) ) );
	}

	/**
	 * Tell whether the merchant has Amazon Pay switched on in the WooPayments settings.
	 *
	 * The client's Amazon Pay gateway is enabled exactly while `amazon_pay` is in the enabled payment
	 * method IDs, and its express payment-type allowlist drops Amazon Pay when that gateway is disabled.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	private static function is_amazon_pay_enabled_by_merchant( WooPaymentsAccountService $account_service ): bool {
		$enabled = $account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( self::STRIPE_TYPE_CARD ) );

		return is_array( $enabled ) && in_array( self::EXPRESS_METHOD_AMAZON_PAY, self::normalize_express_method_ids( $enabled ), true );
	}

	/**
	 * Tell whether Amazon Pay is available and enabled on the connected account.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param array<string,mixed>       $account_data    Cached account data.
	 * @return bool
	 */
	private static function is_amazon_pay_enabled_for_account( WooPaymentsAccountService $account_service, array $account_data ): bool {
		$available = $account_service->get_gateway_setting( 'upe_available_payment_methods', array() );

		$available = is_array( $available ) ? self::normalize_express_method_ids( $available ) : array();

		if ( ! empty( $available ) && ! in_array( self::EXPRESS_METHOD_AMAZON_PAY, $available, true ) ) {
			return false;
		}

		if ( isset( $account_data['payments_enabled'] ) && ! wc_string_to_bool( $account_data['payments_enabled'] ) ) {
			return false;
		}

		$capabilities = $account_data['capabilities'] ?? array();
		$fees         = $account_data['fees'] ?? array();

		return is_array( $capabilities )
			&& is_array( $fees )
			&& 'active' === ( $capabilities['amazon_pay_payments'] ?? null )
			&& is_array( $fees[ self::STRIPE_TYPE_AMAZON_PAY ] ?? null );
	}

	/**
	 * Tell whether a yes/no gateway setting is enabled.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $key             Setting key.
	 * @return bool
	 */
	private static function is_truthy_gateway_setting( WooPaymentsAccountService $account_service, string $key ): bool {
		$value = $account_service->get_gateway_setting( $key, 'no' );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Tell whether Amazon Pay supports a currency for the connected account country.
	 *
	 * @param string $currency        Currency code.
	 * @param string $account_country Connected account country.
	 * @return bool
	 */
	private static function is_amazon_pay_currency_supported( string $currency, string $account_country ): bool {
		if ( 'US' === $account_country ) {
			return 'usd' === $currency;
		}

		return in_array(
			$currency,
			array(
				'usd',
				'aud',
				'gbp',
				'dkk',
				'eur',
				'hkd',
				'jpy',
				'nzd',
				'nok',
				'sek',
				'chf',
				'zar',
			),
			true
		);
	}
}
