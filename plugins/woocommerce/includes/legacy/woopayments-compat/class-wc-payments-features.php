<?php
// phpcs:disable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps -- The WooPayments extension's class name and file shape are the compatibility contract.
/**
 * WC_Payments_Features facade.
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Utilities\WooPaymentsCompatibilityNotice;

/**
 * Answers feature checks from plugins written for the WooPayments extension.
 *
 * Removed in WooCommerce 12.0.0.
 *
 * @since 11.2.0
 * @deprecated 11.2.0 Scheduled for removal in WooCommerce 12.0.0.
 */
class WC_Payments_Features {
	// phpcs:enable Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Classes.ValidClassName.NotCamelCaps

	/** Option for bundled subscriptions. */
	public const WCPAY_SUBSCRIPTIONS_FLAG_NAME = '_wcpay_feature_subscriptions';

	/** Option for Stripe Billing. */
	public const STRIPE_BILLING_FLAG_NAME = '_wcpay_feature_stripe_billing';

	/** Option for WooPay express checkout. */
	public const WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME = '_wcpay_feature_woopay_express_checkout';

	/** Option for WooPay direct checkout. */
	public const WOOPAY_DIRECT_CHECKOUT_FLAG_NAME = '_wcpay_feature_woopay_direct_checkout';

	/** The built-in WooPayments does not read this option. */
	public const DISPUTE_ISSUER_EVIDENCE = '_wcpay_feature_dispute_issuer_evidence';

	/** The built-in WooPayments does not read this option. */
	public const DISPUTE_ADDITIONAL_EVIDENCE_TYPES = '_wcpay_feature_dispute_additional_evidence_types';

	/** The built-in WooPayments does not read this option. */
	public const DISPUTE_OUTCOME_VIEW = '_wcpay_feature_dispute_outcome_view';

	/** The built-in WooPayments does not read this option. */
	public const DISPUTE_READINESS_OVERVIEW = '_wcpay_feature_dispute_readiness_overview';

	/** The built-in WooPayments does not read this option. */
	public const WOOPAY_GLOBAL_THEME_SUPPORT_FLAG_NAME = '_wcpay_feature_woopay_global_theme_support';

	/** Option for the dynamic checkout button. */
	public const WCPAY_DYNAMIC_CHECKOUT_PLACE_ORDER_BUTTON_FLAG_NAME = '_wcpay_feature_dynamic_checkout_place_order_button';

	/** The built-in WooPayments does not read this option. */
	public const AMAZON_PAY_FLAG_NAME = '_wcpay_feature_amazon_pay';

	/** The built-in WooPayments does not read this option. */
	public const MC_CACHE_OPTIMIZED_FLAG_NAME = '_wcpay_feature_mc_cache_optimized';

	/** Option for the reports area. */
	public const REPORTS_AREA_FLAG_NAME = '_wcpay_feature_reports_area';

	/**
	 * Report that the extension's bundled subscriptions feature is off, as it always is while the built-in WooPayments owns payments.
	 *
	 * Removed in WooCommerce 12.0.0.
	 *
	 * @since 11.2.0
	 * @deprecated 11.4.0-dev No public replacement.
	 *
	 * @return bool
	 */
	public static function is_wcpay_subscriptions_enabled() {
		WooPaymentsCompatibilityNotice::warn( __METHOD__ );

		return false;
	}

	/**
	 * Report whether the account can take payments.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function are_payments_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->has_working_account();
	}

	/**
	 * Report whether WooPay is enabled.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, '1' )
			&& wc_get_container()->get( Eligibility::class )->is_woopay_enabled();
	}

	/**
	 * Report whether WooCommerce runs Multi-Currency.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_customer_multi_currency_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->is_builtin_owner();
	}

	/**
	 * Report whether the store has subscriptions or products using bundled billing.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_wcpay_subscriptions_eligible(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( StripeBillingModule::class )->is_bundled_subscriptions_eligible();
	}

	/**
	 * Report whether the account is eligible for WooPay.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_eligible(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( Eligibility::class )->is_woopay_eligible();
	}

	/**
	 * Report whether WooPay requires signed requests.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_force_signed_requests_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( Eligibility::class )->is_woopay_force_signed_requests_enabled();
	}

	/**
	 * Report whether the account has access to documents.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_documents_section_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_documents_enabled();
	}

	/**
	 * Report whether the WooPay express button is available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_express_checkout_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, '1' )
			&& wc_get_container()->get( Eligibility::class )->is_woopay_eligible();
	}

	/**
	 * Report whether WooPay direct checkout is available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_direct_checkout_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME, '1' )
			&& wc_get_container()->get( Eligibility::class )->is_woopay_direct_checkout_enabled();
	}

	/**
	 * Report whether the account supports global theme styles.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_woopay_global_theme_support_eligible(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return ! empty( wc_get_container()->get( WooPaymentsAccountService::class )->get_cached_account_data()['platform_global_theme_support_enabled'] );
	}

	/**
	 * Report whether fraud protection review is enabled.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_frt_review_feature_active(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( 'wcpay_frt_review_feature_active', '0' );
	}

	/**
	 * Report whether the fraud protection tour was dismissed.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_fraud_protection_welcome_tour_dismissed(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( 'wcpay_fraud_protection_welcome_tour_dismissed', '0' );
	}

	/**
	 * Report whether the Stripe Billing option is on.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_stripe_billing_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( StripeBillingModule::TOGGLE_OPTION, '0' );
	}

	/**
	 * Report whether the store is in the US.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_stripe_billing_eligible(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return 'US' === ( wc_get_base_location()['country'] ?? '' );
	}

	/**
	 * Report whether WooCommerce Subscriptions uses Stripe Billing.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function should_use_stripe_billing(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( StripeBillingModule::TOGGLE_OPTION, '0' )
			&& SubscriptionMethodPolicy::is_woocommerce_subscriptions_active();
	}

	/**
	 * Report whether issuer evidence is available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_dispute_issuer_evidence_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return false;
	}

	/**
	 * Report whether additional dispute evidence types are available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_dispute_additional_evidence_types_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return true;
	}

	/**
	 * Report whether dispute outcomes are visible.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_dispute_outcome_view_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return true;
	}

	/**
	 * Report whether the dispute readiness overview is available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_dispute_readiness_overview_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return true;
	}

	/**
	 * Report whether reports are available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_reports_area_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_reports_enabled();
	}

	/**
	 * Report whether the next deposit notice was dismissed.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_next_deposit_notice_dismissed(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return '1' === get_option( 'wcpay_next_deposit_notice_dismissed', '0' );
	}

	/**
	 * Report whether the dynamic checkout button is enabled.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_dynamic_checkout_place_order_button_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled();
	}

	/**
	 * Report whether Multi-Currency supports cache-optimized rendering.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_mc_cache_optimized_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return true;
	}

	/**
	 * Report whether Amazon Pay is available.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_amazon_pay_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( PaymentMethodRegistry::class )->is_amazon_pay_enabled( wc_get_container()->get( WooPaymentsAccountService::class ) );
	}

	/**
	 * Report whether express checkout uses confirmation tokens.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return bool
	 */
	public static function is_ece_confirmation_tokens_enabled(): bool {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return wc_get_container()->get( WooPaymentsAccountService::class )->is_ece_confirmation_tokens_enabled();
	}

	/**
	 * Report the enabled features published by the extension.
	 *
	 * @since 11.4.0
	 * @deprecated 11.4.0 Removed in WooCommerce 12.0.0.
	 *
	 * @return array<string,bool>
	 */
	public static function to_array(): array {
		wc_deprecated_function( __METHOD__, '11.4.0', '' );

		return array_filter(
			array(
				'multiCurrency'                            => self::is_customer_multi_currency_enabled(),
				'woopay'                                   => self::is_woopay_eligible(),
				'documents'                                => self::is_documents_section_enabled(),
				'woopayExpressCheckout'                    => self::is_woopay_express_checkout_enabled(),
				'isDisputeIssuerEvidenceEnabled'           => self::is_dispute_issuer_evidence_enabled(),
				'isDisputeAdditionalEvidenceTypesEnabled'  => self::is_dispute_additional_evidence_types_enabled(),
				'isDisputeOutcomeViewEnabled'              => self::is_dispute_outcome_view_enabled(),
				'isDisputeReadinessOverviewEnabled'        => self::is_dispute_readiness_overview_enabled(),
				'isFRTReviewFeatureActive'                 => self::is_frt_review_feature_active(),
				'isDynamicCheckoutPlaceOrderButtonEnabled' => self::is_dynamic_checkout_place_order_button_enabled(),
				'amazonPay'                                => self::is_amazon_pay_enabled(),
				'isEceUsingConfirmationTokens'             => self::is_ece_confirmation_tokens_enabled(),
				'reportsArea'                              => self::is_reports_area_enabled(),
			)
		);
	}
}
