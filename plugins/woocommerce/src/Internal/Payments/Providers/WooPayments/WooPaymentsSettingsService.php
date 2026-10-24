<?php
/**
 * WooPaymentsSettingsService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use WP_Error;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAppearanceStore;

/**
 * Builds the data the WooPayments settings page shows and saves the page's changes: account and fraud settings on the
 * platform, the gateway settings, payment method capability requests and the page's own options.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsSettingsService {

	private const WOOPAY_EXPRESS_CHECKOUT_FLAG_OPTION = '_wcpay_feature_woopay_express_checkout';

	private const DYNAMIC_CHECKOUT_PLACE_ORDER_BUTTON_FLAG_OPTION = '_wcpay_feature_dynamic_checkout_place_order_button';

	/**
	 * Payment methods that stay enabled while manual capture is on.
	 *
	 * Link is kept because it follows the card's capture, as the extension's settings save keeps it (client 11.1.0
	 * `includes/admin/class-wc-rest-payments-settings-controller.php:711-713`).
	 */
	public const MANUAL_CAPTURE_PAYMENT_METHOD_IDS = array(
		'amazon_pay',
		'apple_pay',
		'card',
		'google_pay',
		'link',
	);

	private const EXPRESS_CHECKOUT_METHOD_IDS = array(
		'payment_request',
		'amazon_pay',
		'woopay',
		'link',
	);

	private const ALLOWED_OPTIONS = array(
		MultiCurrencyFeatureController::SETUP_COMPLETED_OPTION => 'bool',
		'woocommerce_dismissed_todo_tasks'                 => 'array',
		'woocommerce_remind_me_later_todo_tasks'           => 'array',
		'woocommerce_deleted_todo_tasks'                   => 'array',
		'wcpay_fraud_protection_welcome_tour_dismissed'    => 'bool',
		'wcpay_onboarding_eligibility_modal_dismissed'     => 'bool',
		'wcpay_connection_success_modal_dismissed'         => 'bool',
		'wcpay_next_deposit_notice_dismissed'              => 'bool',
		'wcpay_duplicate_payment_method_notices_dismissed' => 'array',
		'wcpay_instant_deposit_notice_dismissed'           => 'bool',
		'wcpay_exit_survey_last_shown'                     => 'string',
	);

	private const LOCAL_SETTING_MAP = array(
		'is_wcpay_enabled'                               => array( 'enabled', 'bool' ),
		'is_manual_capture_enabled'                      => array( 'manual_capture', 'bool' ),
		'is_test_mode_enabled'                           => array( 'test_mode', 'bool' ),
		'is_debug_log_enabled'                           => array( 'enable_logging', 'bool' ),
		'is_saved_cards_enabled'                         => array( 'saved_cards', 'bool' ),
		'is_express_checkout_in_payment_methods_enabled' => array( 'express_checkout_in_payment_methods', 'bool' ),
		'payment_request_button_size'                    => array( 'payment_request_button_size', 'string' ),
		'payment_request_button_type'                    => array( 'payment_request_button_type', 'string' ),
		'payment_request_button_theme'                   => array( 'payment_request_button_theme', 'string' ),
		'payment_request_button_border_radius'           => array( 'payment_request_button_border_radius', 'int' ),
		'is_woopay_enabled'                              => array( 'platform_checkout', 'bool' ),
		'is_woopay_global_theme_support_enabled'         => array( 'is_woopay_global_theme_support_enabled', 'bool' ),
		'woopay_store_logo'                              => array( 'platform_checkout_store_logo', 'string' ),
	);

	private const ACCOUNT_SETTING_MAP = array(
		'account_statement_descriptor'       => 'statement_descriptor',
		'account_statement_descriptor_kanji' => 'statement_descriptor_kanji',
		'account_statement_descriptor_kana'  => 'statement_descriptor_kana',
		'account_business_name'              => 'business_name',
		'account_business_url'               => 'business_url',
		'account_business_support_address'   => 'business_support_address',
		'account_business_support_email'     => 'business_support_email',
		'account_business_support_phone'     => 'business_support_phone',
		'account_branding_logo'              => 'branding_logo',
		'account_branding_icon'              => 'branding_icon',
		'account_branding_primary_color'     => 'branding_primary_color',
		'account_branding_secondary_color'   => 'branding_secondary_color',
		'account_communications_email'       => 'communications_email',
		'deposit_schedule_interval'          => 'deposit_schedule_interval',
		'deposit_schedule_monthly_anchor'    => 'deposit_schedule_monthly_anchor',
		'deposit_schedule_weekly_anchor'     => 'deposit_schedule_weekly_anchor',
	);

	/**
	 * Settings fields whose platform rejections the settings UI can render inline under the field.
	 */
	private const INLINE_ERROR_SETTING_KEYS = array(
		'account_statement_descriptor',
		'account_business_support_email',
		'account_business_support_phone',
		'account_communications_email',
	);

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPay eligibility rules.
	 *
	 * @var WooPaymentsWooPayEligibility
	 */
	private WooPaymentsWooPayEligibility $woopay_eligibility;

	/**
	 * WooPay checkout appearance store.
	 *
	 * @var WooPaymentsWooPayAppearanceStore
	 */
	private WooPaymentsWooPayAppearanceStore $appearance_store;

	/**
	 * Frontend tracking controller.
	 *
	 * @var WooPaymentsFrontendTrackingController
	 */
	private WooPaymentsFrontendTrackingController $frontend_tracking_controller;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Canonical-to-split gateway settings synchronizer.
	 *
	 * @var WooPaymentsGatewaySettingsSynchronizer
	 */
	private WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer;

	/**
	 * Payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Stripe Billing module.
	 *
	 * @var WooPaymentsStripeBillingModule
	 */
	private WooPaymentsStripeBillingModule $stripe_billing_module;

	/**
	 * Payment method promotions service.
	 *
	 * @var WooPaymentsPmPromotionsService
	 */
	private WooPaymentsPmPromotionsService $pm_promotions_service;

	/**
	 * Fraud ruleset service.
	 *
	 * @var WooPaymentsFraudRulesetService
	 */
	private WooPaymentsFraudRulesetService $fraud_ruleset_service;

	/**
	 * Duplicate gateway methods service.
	 *
	 * @var WooPaymentsDuplicateGatewayMethodsService
	 */
	private WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service;

	/**
	 * Store setup report controller.
	 *
	 * @var WooPaymentsStoreSetupReportController
	 */
	private WooPaymentsStoreSetupReportController $store_setup_report_controller;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService                 $account_service                   WooPayments account service.
	 * @param WooPaymentsApiClient                      $api_client                        WooPayments API client.
	 * @param WooPaymentsPmPromotionsService            $pm_promotions_service             PM promotions service.
	 * @param WooPaymentsGatewaySettingsSynchronizer    $gateway_settings_synchronizer     Gateway settings synchronizer.
	 * @param WooPaymentsPaymentMethodRegistry          $payment_method_registry           Payment method registry.
	 * @param WooPaymentsStripeBillingModule            $stripe_billing_module             Stripe Billing module.
	 * @param WooPaymentsFraudRulesetService            $fraud_ruleset_service             Fraud ruleset service.
	 * @param WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service Duplicate gateway methods service.
	 * @param WooPaymentsWooPayEligibility              $woopay_eligibility                WooPay eligibility rules.
	 * @param WooPaymentsWooPayAppearanceStore          $appearance_store                  WooPay checkout appearance store.
	 * @param WooPaymentsFrontendTrackingController     $frontend_tracking_controller      Frontend tracking controller.
	 * @param WooPaymentsLogger                         $logger                            WooPayments logger.
	 * @param WooPaymentsStoreSetupReportController     $store_setup_report_controller     Store setup report controller.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsApiClient $api_client, WooPaymentsPmPromotionsService $pm_promotions_service, WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer, WooPaymentsPaymentMethodRegistry $payment_method_registry, WooPaymentsStripeBillingModule $stripe_billing_module, WooPaymentsFraudRulesetService $fraud_ruleset_service, WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service, WooPaymentsWooPayEligibility $woopay_eligibility, WooPaymentsWooPayAppearanceStore $appearance_store, WooPaymentsFrontendTrackingController $frontend_tracking_controller, WooPaymentsLogger $logger, WooPaymentsStoreSetupReportController $store_setup_report_controller ): void {
		$this->account_service                   = $account_service;
		$this->api_client                        = $api_client;
		$this->pm_promotions_service             = $pm_promotions_service;
		$this->gateway_settings_synchronizer     = $gateway_settings_synchronizer;
		$this->payment_method_registry           = $payment_method_registry;
		$this->stripe_billing_module             = $stripe_billing_module;
		$this->fraud_ruleset_service             = $fraud_ruleset_service;
		$this->duplicate_gateway_methods_service = $duplicate_gateway_methods_service;
		$this->woopay_eligibility                = $woopay_eligibility;
		$this->appearance_store                  = $appearance_store;
		$this->frontend_tracking_controller      = $frontend_tracking_controller;
		$this->logger                            = $logger;
		$this->store_setup_report_controller     = $store_setup_report_controller;
	}

	/**
	 * Get express checkout method IDs accepted by the settings REST contract.
	 *
	 * @return string[]
	 */
	public static function get_express_checkout_method_ids(): array {
		return self::EXPRESS_CHECKOUT_METHOD_IDS;
	}

	/**
	 * Tell whether dynamic checkout place-order controls are enabled.
	 *
	 * @since 11.0.0
	 *
	 * @return bool
	 */
	public static function is_dynamic_checkout_place_order_button_enabled(): bool {
		// Off unless the option holds '1'. WooCommerce always meets the 10.6.0 requirement client 11.1.0 adds
		// (`includes/class-wc-payments-features.php:399-411`).
		return '1' === (string) get_option( self::DYNAMIC_CHECKOUT_PLACE_ORDER_BUTTON_FLAG_OPTION, '0' );
	}

	/**
	 * Get the data the settings page shows.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		$settings = $this->account_service->get_gateway_settings();
		// The availability filter runs before any account read, as client 11.1.0 get_settings() does (class-wc-rest-payments-settings-controller.php:513-516).
		$available_payment_method_ids = $this->get_available_payment_method_ids();
		$account_fields               = $this->get_account_backed_response_fields( $settings );
		$enabled_payment_method_ids   = $this->sanitize_payment_method_ids(
			$this->get_array_setting( $settings, 'upe_enabled_payment_method_ids' ),
			$available_payment_method_ids
		);

		$contract = array(
			'enabled_payment_method_ids'                 => $enabled_payment_method_ids,
			'available_payment_method_ids'               => $available_payment_method_ids,
			'payment_method_statuses'                    => $this->get_payment_method_statuses(),
			'duplicated_payment_method_ids'              => $this->duplicate_gateway_methods_service->get_duplicated_payment_method_ids(),
			'dismissed_duplicate_payment_method_notices' => $this->duplicate_gateway_methods_service->get_dismissed_duplicate_payment_method_notices(),
			'account_fees'                               => $this->get_account_fees(),
			'pm_promotions'                              => $this->pm_promotions_service->get_visible_promotions() ?? array(),
			'is_wcpay_enabled'                           => $this->is_yes( $settings['enabled'] ),
			'is_manual_capture_enabled'                  => $this->is_yes( $settings['manual_capture'] ),
			'is_test_mode_enabled'                       => $this->account_service->is_test_mode_enabled(),
			'is_test_mode_onboarding'                    => $this->account_service->is_test_mode_onboarding_enabled(),
			'is_dev_mode_enabled'                        => $this->account_service->is_dev_mode_enabled(),
			'is_multi_currency_enabled'                  => wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->should_core_register(),
			'is_wcpay_subscriptions_enabled'             => $this->stripe_billing_module->is_bundled_subscriptions_flag_on(),
			'is_wcpay_subscriptions_eligible'            => $this->stripe_billing_module->is_bundled_subscriptions_eligible(),
			'is_subscriptions_plugin_active'             => WooPaymentsSubscriptionMethodPolicy::is_woocommerce_subscriptions_active(),
			'feature_flags'                              => $this->get_feature_flags(),
			'account_country'                            => $account_fields['account_country'],
			'account_statement_descriptor'               => $account_fields['account_statement_descriptor'],
			'account_statement_descriptor_kanji'         => $account_fields['account_statement_descriptor_kanji'],
			'account_statement_descriptor_kana'          => $account_fields['account_statement_descriptor_kana'],
			'account_business_name'                      => $account_fields['account_business_name'],
			'account_business_url'                       => $account_fields['account_business_url'],
			'account_business_support_address'           => $account_fields['account_business_support_address'],
			'account_business_support_email'             => $account_fields['account_business_support_email'],
			'account_business_support_phone'             => $account_fields['account_business_support_phone'],
			'account_branding_logo'                      => $account_fields['account_branding_logo'],
			'account_branding_icon'                      => $account_fields['account_branding_icon'],
			'account_branding_primary_color'             => $account_fields['account_branding_primary_color'],
			'account_branding_secondary_color'           => $account_fields['account_branding_secondary_color'],
			'account_domestic_currency'                  => $account_fields['account_domestic_currency'],
			'account_communications_email'               => $account_fields['account_communications_email'],
			'is_payment_request_enabled'                 => $this->account_service->is_payment_request_enabled(),
			'is_express_checkout_in_payment_methods_enabled' => $this->is_yes( $settings['express_checkout_in_payment_methods'] ?? 'no' ),
			'is_debug_log_enabled'                       => $this->is_yes( $settings['enable_logging'] ),
			'payment_request_button_size'                => $this->get_string_setting( $settings, 'payment_request_button_size' ),
			'payment_request_button_type'                => $this->get_string_setting( $settings, 'payment_request_button_type' ),
			'payment_request_button_theme'               => $this->get_string_setting( $settings, 'payment_request_button_theme' ),
			'payment_request_button_border_radius'       => $this->get_int_setting( $settings, 'payment_request_button_border_radius', 4 ),
			'is_saved_cards_enabled'                     => $this->is_yes( $settings['saved_cards'] ),
			'is_card_present_eligible'                   => $this->is_card_present_eligible(),
			'is_woopay_enabled'                          => $this->woopay_eligibility->is_woopay_eligible() && $this->is_yes( $settings['platform_checkout'] ?? 'no' ),
			'woopay_last_disable_date'                   => $this->get_string_setting( $settings, 'platform_checkout_last_disable_date' ),
			'is_woopay_global_theme_support_enabled'     => $this->is_yes( $settings['is_woopay_global_theme_support_enabled'] ?? 'no' ),
			'is_woopay_global_theme_support_eligible'    => $this->is_woopay_global_theme_support_eligible(),
			'show_woopay_incompatibility_notice'         => (bool) get_option( 'woopay_invalid_extension_found', false ),
			'woopay_custom_message'                      => $this->get_string_setting( $settings, 'platform_checkout_custom_message' ),
			'woopay_store_logo'                          => $this->get_string_setting( $settings, 'platform_checkout_store_logo' ),
			'woopay_appearance'                          => $this->appearance_store->get_woopay_appearance(),
			'woopay_font_rules'                          => $this->appearance_store->get_woopay_font_rules(),
			'store_name'                                 => get_bloginfo( 'name' ),
			'store_currency'                             => $this->get_store_currency(),
			'site_logo_url'                              => $this->get_site_logo_url(),
			'deposit_schedule_interval'                  => $account_fields['deposit_schedule_interval'],
			'deposit_schedule_monthly_anchor'            => $account_fields['deposit_schedule_monthly_anchor'],
			'deposit_schedule_weekly_anchor'             => $account_fields['deposit_schedule_weekly_anchor'],
			'deposit_delay_days'                         => $account_fields['deposit_delay_days'],
			'deposit_status'                             => $account_fields['deposit_status'],
			'deposit_restrictions'                       => $account_fields['deposit_restrictions'],
			'deposit_completed_waiting_period'           => $account_fields['deposit_completed_waiting_period'],
			'current_protection_level'                   => $this->fraud_ruleset_service->get_current_protection_level(),
			'advanced_fraud_protection_settings'         => $this->fraud_ruleset_service->get_advanced_fraud_protection_settings(),
			'fraud_protection'                           => $this->get_fraud_protection_settings(),
			'fraud_protection_allowed_countries'         => $this->get_fraud_protection_allowed_countries(),
			'is_fraud_protection_review_feature_active'  => $this->fraud_ruleset_service->is_fraud_protection_review_feature_active(),
			'express_checkout_product_methods'           => $this->sanitize_payment_method_ids( $this->get_array_setting( $settings, 'express_checkout_product_methods' ), self::EXPRESS_CHECKOUT_METHOD_IDS ),
			'express_checkout_cart_methods'              => $this->sanitize_payment_method_ids( $this->get_array_setting( $settings, 'express_checkout_cart_methods' ), self::EXPRESS_CHECKOUT_METHOD_IDS ),
			'express_checkout_checkout_methods'          => $this->sanitize_payment_method_ids( $this->get_array_setting( $settings, 'express_checkout_checkout_methods' ), self::EXPRESS_CHECKOUT_METHOD_IDS ),
			'express_checkout_preview'                   => $this->get_express_checkout_preview_settings(),
		);

		return array_merge( $contract, $this->stripe_billing_module->get_settings_fields() );
	}

	/**
	 * Get public Stripe configuration used by the express checkout settings preview.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function get_express_checkout_preview_settings(): array {
		return array(
			'stripe' => array(
				'publishableKey' => $this->account_service->get_publishable_key(),
				'accountId'      => $this->account_service->get_account_id(),
				'locale'         => WooPaymentsLocaleUtils::get_stripe_locale(),
			),
		);
	}

	/**
	 * Tell whether the connected account can use WooPay global theme support.
	 *
	 * @return bool
	 */
	private function is_woopay_global_theme_support_eligible(): bool {
		$account_data = $this->account_service->get_cached_account_data();

		return ! empty( $account_data['platform_global_theme_support_enabled'] );
	}

	/**
	 * Get the feature flags the settings page reads.
	 *
	 * @return array<string,bool>
	 */
	private function get_feature_flags(): array {
		$is_woopay_eligible = $this->woopay_eligibility->is_woopay_eligible();

		return array(
			'woopay'                                   => $is_woopay_eligible,
			'woopayExpressCheckout'                    => '1' === (string) get_option( self::WOOPAY_EXPRESS_CHECKOUT_FLAG_OPTION, '1' ),
			'isDynamicCheckoutPlaceOrderButtonEnabled' => self::is_dynamic_checkout_place_order_button_enabled(),
			'amazonPay'                                => $this->payment_method_registry->is_amazon_pay_enabled( $this->account_service ),
			'isEceUsingConfirmationTokens'             => $this->account_service->is_ece_confirmation_tokens_enabled(),
		);
	}

	/**
	 * Get the site's logo URL for the settings preview.
	 *
	 * @return string
	 */
	private function get_site_logo_url(): string {
		$logo_id = get_theme_mod( 'custom_logo' );

		if ( ! $logo_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( (int) $logo_id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Save a settings page update: capability requests for newly enabled methods, then the fraud and account settings on
	 * the platform, then the gateway settings in one write; answers the stored settings.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_settings( array $params ) {
		$multi_currency_error = $this->get_multi_currency_disable_error( $params );
		if ( null !== $multi_currency_error ) {
			return $multi_currency_error;
		}

		$settings                = $this->account_service->get_gateway_settings();
		$previous_settings       = $settings;
		$was_woopay_enabled      = $this->is_yes( $settings['platform_checkout'] ?? 'no' );
		$payment_request_enabled = array_key_exists( 'is_payment_request_enabled', $params )
			? $this->is_yes( $this->normalize_setting_value( $params['is_payment_request_enabled'], 'bool' ) )
			: null;
		$enabled_methods_update  = $this->prepare_enabled_payment_method_ids_update( $params, $settings );
		if ( is_wp_error( $enabled_methods_update ) ) {
			return $enabled_methods_update;
		}

		if ( null !== $enabled_methods_update ) {
			$capability_error = $this->request_unrequested_payment_methods( $enabled_methods_update['requested'] );
			if ( is_wp_error( $capability_error ) ) {
				return $capability_error;
			}
		}

		$error = $this->update_provider_backed_settings( $params, $settings );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		foreach ( self::LOCAL_SETTING_MAP as $request_key => $mapping ) {
			if ( ! array_key_exists( $request_key, $params ) ) {
				continue;
			}

			if ( $this->should_skip_local_setting_update_in_dev_mode( $request_key ) ) {
				continue;
			}

			list( $setting_key, $type ) = $mapping;
			$settings[ $setting_key ]   = $this->normalize_setting_value( $params[ $request_key ], $type );
		}

		if ( array_key_exists( 'is_woopay_enabled', $params ) && $was_woopay_enabled && ! $this->is_yes( $settings['platform_checkout'] ?? 'no' ) ) {
			$settings['platform_checkout_last_disable_date'] = gmdate( 'Y-m-d' );
		}

		if ( array_key_exists( 'woopay_custom_message', $params ) ) {
			$custom_message                               = is_scalar( $params['woopay_custom_message'] ) ? (string) $params['woopay_custom_message'] : '';
			$custom_message                               = str_replace( '[terms_of_service_link]', '[terms]', $custom_message );
			$custom_message                               = str_replace( '[privacy_policy_link]', '[privacy_policy]', $custom_message );
			$settings['platform_checkout_custom_message'] = wp_kses_post( $custom_message );
		}

		if ( null !== $enabled_methods_update ) {
			// The stored list as it is, whatever is available now, so a removed method is seen even when the availability
			// filter hides it (client 11.1.0 class-wc-rest-payments-settings-controller.php:738-740).
			$previous_enabled_payment_method_ids = $this->normalize_payment_method_ids(
				$this->get_array_setting( $settings, 'upe_enabled_payment_method_ids', array( 'card' ) )
			);
			$enabled_payment_method_ids          = $this->sanitize_payment_method_ids(
				$enabled_methods_update['requested'],
				$enabled_methods_update['available']
			);
			$removed_payment_method_ids          = array_diff( $previous_enabled_payment_method_ids, $enabled_payment_method_ids );
			$added_payment_method_ids            = array_diff( $enabled_payment_method_ids, $previous_enabled_payment_method_ids );
			// Client 11.1.0 records these before saving (class-wc-rest-payments-settings-controller.php:740-760).
			$tracks_properties = function ( string $payment_method_id ): array {
				$definition = $this->payment_method_registry->get( $payment_method_id );

				return array(
					'payment_method_id' => $payment_method_id,
					'capability_id'     => null !== $definition ? $definition->get_account_capability_key() : null,
				);
			};
			foreach ( $removed_payment_method_ids as $payment_method_id ) {
				WooPaymentsTracks::record_wcadmin_event_with_tracking_info( 'wcpay_payment_method_disabled', $tracks_properties( $payment_method_id ) );
			}
			foreach ( $added_payment_method_ids as $payment_method_id ) {
				WooPaymentsTracks::record_wcadmin_event_with_tracking_info( 'wcpay_payment_method_enabled', $tracks_properties( $payment_method_id ) );
				$this->pm_promotions_service->maybe_activate_promotion_for_payment_method( $payment_method_id );
			}
			// Card's gateway is the WooPayments gateway, so adding or removing card turns it on or off; persist() sets the other
			// methods' gateways (client 11.1.0 `includes/admin/class-wc-rest-payments-settings-controller.php:766-790`).
			if ( in_array( 'card', $added_payment_method_ids, true ) ) {
				$settings['enabled'] = 'yes';
			} elseif ( in_array( 'card', $removed_payment_method_ids, true ) ) {
				$settings['enabled'] = 'no';
			}
			$settings['upe_enabled_payment_method_ids'] = $enabled_payment_method_ids;
		}

		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$request_key = 'express_checkout_' . $location . '_methods';
			if ( array_key_exists( $request_key, $params ) ) {
				$settings[ $request_key ] = $this->sanitize_payment_method_ids(
					is_array( $params[ $request_key ] ) ? $params[ $request_key ] : array(),
					self::EXPRESS_CHECKOUT_METHOD_IDS
				);
			}
		}

		if ( array_key_exists( 'is_multi_currency_enabled', $params ) ) {
			// The checkbox is core Multi-Currency's switch, as on the Features page; the extension's own flag follows it, so a
			// return to the extension keeps the merchant's choice (the Features page change writes it too, but only on a change).
			wc_get_container()->get( FeaturesController::class )->change_feature_enable( MultiCurrencyFeatureController::FEATURE_ID, (bool) $params['is_multi_currency_enabled'] );
			update_option( MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, $params['is_multi_currency_enabled'] ? '1' : '0' );
		}

		if ( array_key_exists( 'is_wcpay_subscriptions_enabled', $params ) && ! $params['is_wcpay_subscriptions_enabled'] ) {
			$this->stripe_billing_module->turn_off_bundled_subscriptions();
		}

		if ( array_key_exists( 'is_stripe_billing_enabled', $params ) ) {
			$this->stripe_billing_module->set_stripe_billing_enabled( (bool) $params['is_stripe_billing_enabled'] );
		}

		foreach ( array_keys( self::ACCOUNT_SETTING_MAP ) as $request_key ) {
			if ( ! array_key_exists( $request_key, $params ) ) {
				continue;
			}

			// Store exactly what update_provider_backed_settings() sent to the platform: the local mirror is a cache-unavailable fallback for the account data, so a value that diverges from the wire (e.g. a sanitized-to-empty color) would misreport the account. Format guarantees live at the REST boundary validators; the mirror's read path, the JSON settings response, encodes at output.
			$settings[ $request_key ] = $params[ $request_key ];
		}

		// Save every setting in one write; persist() then copies each method's enabled state to its gateway.
		$result = $this->gateway_settings_synchronizer->persist( $settings, $payment_request_enabled );
		if ( ! $result['persisted'] ) {
			// Account and fraud settings in this request already reached the platform; say which local option did not save.
			$this->logger->log_always(
				'WooPayments settings could not be saved locally.',
				'error',
				array( 'failed_options' => $result['failed_option_names'] )
			);

			return new WP_Error(
				'woocommerce_woopayments_settings_persistence_failed',
				esc_html__( 'WooPayments settings could not be saved. Please try again.', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}
		$settings = $result['settings'];
		$this->record_woopay_settings_events( $params, $previous_settings, $settings );
		// The platform gets the store setup report now, inside the save (client 11.1.0
		// includes/admin/class-wc-rest-payments-settings-controller.php:642-643); the scheduled sync hook does not fire here.
		$this->store_setup_report_controller->handle_wcpay_store_setup_sync();

		return $this->get_settings();
	}

	/**
	 * Record the WooPay settings Tracks events: WooPay turned on or off, its global theme support turned on or off, and its
	 * button locations when WooPay is on and a location list was sent (client 11.1.0
	 * `includes/class-wc-payment-gateway-wcpay.php:3147-3184`, `includes/admin/class-wc-rest-payments-settings-controller.php:1187-1229`).
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param array<string,mixed> $previous Settings before the update.
	 * @param array<string,mixed> $settings Settings after the update.
	 */
	private function record_woopay_settings_events( array $params, array $previous, array $settings ): void {
		$tracking    = $this->frontend_tracking_controller;
		$properties  = array( 'test_mode' => $this->account_service->is_test_mode_enabled() ? 1 : 0 );
		$is_enabled  = $this->is_yes( $settings['platform_checkout'] ?? 'no' );
		$was_enabled = $this->is_yes( $previous['platform_checkout'] ?? 'no' );
		if ( $is_enabled && ! $was_enabled ) {
			$tracking->record_woopay_admin_event( 'woopay_enabled', $properties );
		} elseif ( ! $is_enabled && $was_enabled ) {
			$tracking->record_woopay_admin_event( 'woopay_disabled', $properties );
		}
		$theme_support     = $this->is_yes( $settings['is_woopay_global_theme_support_enabled'] ?? 'no' );
		$had_theme_support = $this->is_yes( $previous['is_woopay_global_theme_support_enabled'] ?? 'no' );
		if ( $theme_support && ! $had_theme_support ) {
			$tracking->record_woopay_admin_event( 'woopay_global_theme_support_enabled', $properties );
		} elseif ( ! $theme_support && $had_theme_support ) {
			$tracking->record_woopay_admin_event( 'woopay_global_theme_support_disabled', $properties );
		}
		$locations = array();
		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$locations[ $location . '_enabled' ] = in_array( 'woopay', $this->get_array_setting( $settings, 'express_checkout_' . $location . '_methods' ), true );
		}
		if ( $is_enabled && array() !== array_intersect_key( $params, array_flip( array( 'express_checkout_product_methods', 'express_checkout_cart_methods', 'express_checkout_checkout_methods' ) ) ) ) {
			$tracking->record_woopay_admin_event( 'woopay_express_button_locations_updated', $locations );
		}
	}

	/**
	 * Update an allowlisted WordPress option used by the settings page.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @return true|WP_Error
	 */
	public function update_option( string $option_name, $value ) {
		$expected_type = self::ALLOWED_OPTIONS[ $option_name ] ?? null;
		if ( null === $expected_type ) {
			return new WP_Error(
				'woocommerce_woopayments_invalid_settings_option',
				esc_html__( 'Invalid WooPayments settings option.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->is_valid_option_value_type( $value, $expected_type ) ) {
			return new WP_Error(
				'woocommerce_woopayments_invalid_settings_option_value',
				sprintf(
					/* translators: %s: expected option value type. */
					esc_html__( 'Invalid WooPayments settings option value. Expected %s.', 'woocommerce' ),
					$expected_type
				),
				array( 'status' => 400 )
			);
		}

		update_option( $option_name, $value );

		return true;
	}

	/**
	 * Validate a requested enabled-payment-methods update against the account's live availability.
	 *
	 * Runs before any platform mutation so a rejected save is fully atomic. An unknown availability (no fee-backed methods, e.g. a cold or errored account cache) rejects every enabled-methods save — including an empty list, which a settings-screen round-trip would otherwise persist, silently wiping the store's enabled methods without self-healing when the cache returns.
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param array<string,mixed> $settings Current gateway settings.
	 * @return array{requested:string[],available:string[]}|WP_Error|null Prepared update, an error, or null when the request does not touch enabled methods.
	 */
	private function prepare_enabled_payment_method_ids_update( array $params, array $settings ) {
		if ( ! array_key_exists( 'enabled_payment_method_ids', $params ) ) {
			return null;
		}

		$requested_payment_method_ids = $this->sanitize_payment_method_ids(
			is_array( $params['enabled_payment_method_ids'] ) ? $params['enabled_payment_method_ids'] : array(),
			WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS
		);
		if ( $this->is_manual_capture_enabled_after_update( $params, $settings ) ) {
			$requested_payment_method_ids = $this->filter_manual_capture_payment_method_ids( $requested_payment_method_ids );
		}

		$available_payment_method_ids = $this->get_available_payment_method_ids();
		if ( empty( $available_payment_method_ids ) ) {
			return $this->enabled_payment_method_ids_error(
				__( 'The payment methods available to the account cannot be determined right now. Please try again.', 'woocommerce' )
			);
		}

		$unavailable_payment_method_ids = array_values( array_diff( $requested_payment_method_ids, $available_payment_method_ids ) );
		if ( ! empty( $unavailable_payment_method_ids ) ) {
			// An unavailable method fails the save with a 400 instead of being dropped.
			return $this->enabled_payment_method_ids_error(
				sprintf(
					/* translators: %s: comma-separated payment method IDs. */
					__( 'These payment methods are not available to the account: %s', 'woocommerce' ),
					implode( ', ', $unavailable_payment_method_ids )
				)
			);
		}

		return array(
			'requested' => $requested_payment_method_ids,
			'available' => $available_payment_method_ids,
		);
	}

	/**
	 * Refuse a Multi-Currency untick while the Features page protects the disable choice (additional enabled currencies or
	 * foreign-currency orders), with that page's sentence and its confirmation URL, before anything is saved.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return WP_Error|null
	 */
	private function get_multi_currency_disable_error( array $params ): ?WP_Error {
		if ( ! array_key_exists( 'is_multi_currency_enabled', $params ) || $params['is_multi_currency_enabled'] ) {
			return null;
		}

		$feature_controller = wc_get_container()->get( MultiCurrencyFeatureController::class );
		if ( ! $feature_controller->is_disable_protected() ) {
			return null;
		}

		return new WP_Error(
			'woocommerce_multi_currency_disable_protected',
			$feature_controller->get_disable_protected_message(),
			array(
				'status'      => 400,
				'disable_url' => $feature_controller->get_disable_anyway_url(),
			)
		);
	}

	/**
	 * Build the rest_invalid_param error envelope for a rejected enabled-methods update.
	 *
	 * @param string $message Field error message.
	 * @return WP_Error
	 */
	private function enabled_payment_method_ids_error( string $message ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			sprintf(
				/* translators: %s: settings field key. */
				__( 'Invalid parameter(s): %s', 'woocommerce' ),
				'enabled_payment_method_ids'
			),
			array(
				'status'  => 400,
				'params'  => array( 'enabled_payment_method_ids' => $message ),
				'details' => array(
					'enabled_payment_method_ids' => array(
						'code'    => 'rest_not_in_enum',
						'message' => $message,
						'data'    => null,
					),
				),
			)
		);
	}

	/**
	 * Get payment method IDs available to the connected account: the registry's available methods with account fees, of
	 * which a method not offered to new accounts stays only while the account still holds its capability.
	 *
	 * @return string[]
	 */
	private function get_available_payment_method_ids(): array {
		// Availability comes from the account's fees only: no stored list and no card fallback. No fees (no account, or a cache
		// without fees) means nothing is available.
		$available_ids = $this->payment_method_registry->get_available_payment_method_ids_for_account( $this->account_service );

		return array_values(
			array_filter(
				$this->sanitize_payment_method_ids( $available_ids, WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS ),
				fn( string $payment_method_id ): bool => $this->is_listable_payment_method( $payment_method_id )
			)
		);
	}

	/**
	 * Tell whether a payment method with account fees may be listed: a method not offered to new accounts only while its
	 * capability is active.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @return bool
	 */
	private function is_listable_payment_method( string $payment_method_id ): bool {
		$definition = $this->payment_method_registry->get( $payment_method_id );

		return null === $definition
			|| $definition->is_offered_to_new_accounts()
			|| $this->account_service->is_capability_active( $definition->get_account_capability_key() );
	}

	/**
	 * Get account fee structures from cached account data.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_account_fees(): array {
		$account_data = $this->account_service->get_cached_account_data();
		$fees         = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();
		$account_fees = array();

		foreach ( $fees as $payment_method_id => $fee_structure ) {
			if ( ! is_string( $payment_method_id ) || ! in_array( $payment_method_id, WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS, true ) || ! is_array( $fee_structure ) ) {
				continue;
			}

			$account_fees[ $payment_method_id ] = $fee_structure;
		}

		return $account_fees;
	}

	/**
	 * Tell whether the account is card-present eligible and Cash on delivery is an available gateway.
	 *
	 * The account check runs first so the available-gateway lookup only happens for eligible accounts.
	 *
	 * @return bool
	 */
	private function is_card_present_eligible(): bool {
		if ( ! $this->account_service->is_card_present_eligible() ) {
			return false;
		}

		return isset( WC()->payment_gateways()->get_available_payment_gateways()['cod'] );
	}

	/**
	 * Tell whether manual capture will be enabled after this update.
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param array<string,mixed> $settings Current gateway settings.
	 * @return bool
	 */
	private function is_manual_capture_enabled_after_update( array $params, array $settings ): bool {
		if ( array_key_exists( 'is_manual_capture_enabled', $params ) ) {
			return (bool) $params['is_manual_capture_enabled'];
		}

		return $this->is_yes( $settings['manual_capture'] ?? 'no' );
	}

	/**
	 * Filter payment methods to those compatible with manual capture.
	 *
	 * @param string[] $payment_method_ids Payment method IDs.
	 * @return string[]
	 */
	private function filter_manual_capture_payment_method_ids( array $payment_method_ids ): array {
		return array_values(
			array_filter(
				$payment_method_ids,
				static fn( string $payment_method_id ): bool => in_array( $payment_method_id, self::MANUAL_CAPTURE_PAYMENT_METHOD_IDS, true )
			)
		);
	}

	/**
	 * Save the fraud settings, then the changed account settings, on the platform; a failure stops before the next step.
	 *
	 * @param array<string,mixed> $params   Request parameters.
	 * @param array<string,mixed> $settings Current gateway settings.
	 * @return WP_Error|null
	 */
	private function update_provider_backed_settings( array $params, array $settings ): ?WP_Error {
		try {
			$this->fraud_ruleset_service->save_fraud_settings( $params );
		} catch ( WooPaymentsApiException $e ) {
			return $this->api_exception_to_wp_error( $e );
		}

		$account_response_fields = $this->get_account_backed_response_fields( $settings );
		$account_settings        = array();
		foreach ( self::ACCOUNT_SETTING_MAP as $request_key => $account_key ) {
			if ( array_key_exists( $request_key, $params ) && ( $account_response_fields[ $request_key ] ?? null ) !== $params[ $request_key ] ) {
				$account_settings[ $account_key ] = $params[ $request_key ];
			}
		}

		if (
			! isset( $account_settings['deposit_schedule_interval'] )
			&& (
				isset( $account_settings['deposit_schedule_weekly_anchor'] )
				|| isset( $account_settings['deposit_schedule_monthly_anchor'] )
			)
		) {
			$account_settings['deposit_schedule_interval'] = array_key_exists( 'deposit_schedule_interval', $params )
				? $params['deposit_schedule_interval']
				: ( $settings['deposit_schedule_interval'] ?? '' );
		}

		// A payout schedule change clears the next-payout notice's dismissal, so the Payouts page shows the notice again.
		if ( preg_grep( '/^deposit_schedule_/', array_keys( $account_settings ) ) ) {
			delete_option( 'wcpay_next_deposit_notice_dismissed' );
		}

		try {
			if ( ! empty( $account_settings ) ) {
				$this->api_client->update_account( $account_settings );
				$this->account_service->refresh_account_data();
			}
		} catch ( WooPaymentsApiException $e ) {
			return $this->account_update_exception_to_wp_error( $e );
		}

		return null;
	}

	/**
	 * Get payment method statuses.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_payment_method_statuses(): array {
		$account_data = $this->account_service->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();
		$requirements = is_array( $account_data['capability_requirements'] ?? null ) ? $account_data['capability_requirements'] : array();
		$statuses     = array();

		foreach ( $capabilities as $capability_id => $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$capability_id              = (string) $capability_id;
			$statuses[ $capability_id ] = array(
				'status'       => (string) $status,
				'requirements' => is_array( $requirements[ $capability_id ] ?? null ) ? $requirements[ $capability_id ] : array(),
			);
		}

		if ( ! empty( $statuses ) ) {
			return $statuses;
		}

		return array(
			'card_payments' => array(
				'status'       => 'active',
				'requirements' => array(),
			),
		);
	}

	/**
	 * Request any unrequested capabilities for newly enabled methods.
	 *
	 * @param string[] $payment_method_ids Enabled payment method IDs.
	 * @return WP_Error|null
	 */
	private function request_unrequested_payment_methods( array $payment_method_ids ): ?WP_Error {
		$payment_method_statuses = $this->get_payment_method_statuses();
		$cache_needs_refresh     = false;

		try {
			foreach ( $payment_method_ids as $payment_method_id ) {
				$definition    = $this->payment_method_registry->get( $payment_method_id );
				$capability_id = null !== $definition ? $definition->get_account_capability_key() : null;
				if ( null === $capability_id || 'unrequested' !== ( $payment_method_statuses[ $capability_id ]['status'] ?? 'unrequested' ) ) {
					continue;
				}

				$request_result      = $this->api_client->request_capability( $capability_id, true );
				$cache_needs_refresh = $cache_needs_refresh || 'unrequested' !== ( $request_result['status'] ?? 'unrequested' );
			}

			if ( $cache_needs_refresh ) {
				$this->account_service->refresh_account_data();
			}
		} catch ( WooPaymentsApiException $e ) {
			return $this->api_exception_to_wp_error( $e );
		}

		return null;
	}

	/**
	 * Get the WooCommerce store currency for fraud threshold rules.
	 *
	 * @return string
	 */
	private function get_store_currency(): string {
		$currency = get_woocommerce_currency();

		return is_scalar( $currency ) ? strtoupper( (string) $currency ) : 'USD';
	}

	/**
	 * Get the AVS and CVC checks from the cached account, on when missing, and whether the fraud welcome tour was dismissed.
	 *
	 * @return array<string,bool>
	 */
	private function get_fraud_protection_settings(): array {
		$account_data = $this->account_service->get_cached_account_data();

		return array(
			'decline_on_avs_failure'    => $this->get_account_bool_setting( $account_data, array( 'fraud_mitigation_settings', 'avs_check_enabled' ), true ),
			'decline_on_cvc_failure'    => $this->get_account_bool_setting( $account_data, array( 'fraud_mitigation_settings', 'cvc_check_enabled' ), true ),
			'is_welcome_tour_dismissed' => (bool) get_option( 'wcpay_fraud_protection_welcome_tour_dismissed', false ),
		);
	}

	/**
	 * Get the WooCommerce selling-location settings used by fraud filters.
	 *
	 * @return array{type:string,countries:string[]}
	 */
	private function get_fraud_protection_allowed_countries(): array {
		$selling_locations_type = get_option( 'woocommerce_allowed_countries', 'all' );
		$selling_locations_type = is_scalar( $selling_locations_type ) ? (string) $selling_locations_type : 'all';

		if ( 'specific' === $selling_locations_type ) {
			$countries = get_option( 'woocommerce_specific_allowed_countries', array() );
		} elseif ( 'all_except' === $selling_locations_type ) {
			$countries = get_option( 'woocommerce_all_except_countries', array() );
		} else {
			$countries = array();
		}

		return array(
			'type'      => in_array( $selling_locations_type, array( 'all', 'specific', 'all_except' ), true ) ? $selling_locations_type : 'all',
			'countries' => is_array( $countries ) ? array_values( array_filter( $countries, 'is_string' ) ) : array(),
		);
	}

	/**
	 * Convert a platform error from the fraud ruleset save or a capability request to a REST error with the platform's
	 * code, message and HTTP status.
	 *
	 * @param WooPaymentsApiException $exception API exception.
	 * @return WP_Error
	 */
	private function api_exception_to_wp_error( WooPaymentsApiException $exception ): WP_Error {
		return new WP_Error(
			$exception->get_error_code(),
			$exception->getMessage(),
			array( 'status' => $exception->get_http_code() )
		);
	}

	/**
	 * Convert a platform account-update rejection into the error shape the settings UI consumes.
	 *
	 * The platform names the rejected account field in the error's `param`. A field the settings page shows errors under
	 * gets the `wcpay_server_error` error with its message in `details`; any other field gets a
	 * `woocommerce_woopayments_account_update_rejected` error (status 400) with the platform's message, which the merchant
	 * controller answers as the `server_error` body the page shows as a notice.
	 *
	 * @param WooPaymentsApiException $exception API exception from the account update.
	 * @return WP_Error
	 */
	private function account_update_exception_to_wp_error( WooPaymentsApiException $exception ): WP_Error {
		$param       = $exception->get_error_data()['param'] ?? null;
		$setting_key = is_string( $param ) && '' !== $param
			? array_search( $param, self::ACCOUNT_SETTING_MAP, true )
			: false;

		if ( false === $setting_key || ! in_array( $setting_key, self::INLINE_ERROR_SETTING_KEYS, true ) ) {
			return new WP_Error(
				'woocommerce_woopayments_account_update_rejected',
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'wcpay_server_error',
			sprintf(
				/* translators: %s: settings field key, e.g. account_business_support_phone. */
				__( 'Invalid parameter(s): %s', 'woocommerce' ),
				$setting_key
			),
			array(
				'status'  => 400,
				'params'  => array( $setting_key => $exception->getMessage() ),
				'details' => array(
					$setting_key => array(
						'code'    => $exception->get_error_code(),
						'message' => $exception->getMessage(),
						'data'    => null,
					),
				),
			)
		);
	}

	/**
	 * Normalize a local gateway setting value.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Setting type.
	 * @return mixed
	 */
	private function normalize_setting_value( $value, string $type ) {
		if ( 'bool' === $type ) {
			return $value ? 'yes' : 'no';
		}

		if ( 'int' === $type ) {
			return (int) $value;
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Tell whether an option value matches the expected type.
	 *
	 * @param mixed  $value         Option value.
	 * @param string $expected_type Expected type.
	 * @return bool
	 */
	private function is_valid_option_value_type( $value, string $expected_type ): bool {
		if ( 'bool' === $expected_type ) {
			return is_bool( $value );
		}

		if ( 'array' === $expected_type ) {
			return is_array( $value );
		}

		return 'string' === $expected_type && is_string( $value );
	}

	/**
	 * Normalize a yes/no value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_yes( $value ): bool {
		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Get the account fields of the settings: the cached account's values, or, for the keys a settings save stores, the
	 * stored settings where the account has none.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return array<string,mixed>
	 */
	private function get_account_backed_response_fields( array $settings ): array {
		$account_data = $this->account_service->get_cached_account_data();

		return array(
			'account_country'                    => $this->get_account_string_setting( $account_data, array( 'country' ) ),
			'account_statement_descriptor'       => $this->get_account_string_setting( $account_data, array( 'statement_descriptor' ), $this->get_string_setting( $settings, 'account_statement_descriptor' ) ),
			'account_statement_descriptor_kanji' => $this->get_account_string_setting( $account_data, array( 'statement_descriptor_kanji' ), $this->get_string_setting( $settings, 'account_statement_descriptor_kanji' ) ),
			'account_statement_descriptor_kana'  => $this->get_account_string_setting( $account_data, array( 'statement_descriptor_kana' ), $this->get_string_setting( $settings, 'account_statement_descriptor_kana' ) ),
			'account_business_name'              => $this->get_account_string_setting( $account_data, array( 'business_profile', 'name' ), $this->get_string_setting( $settings, 'account_business_name' ) ),
			'account_business_url'               => $this->get_account_string_setting( $account_data, array( 'business_profile', 'url' ), $this->get_string_setting( $settings, 'account_business_url' ) ),
			'account_business_support_address'   => $this->get_account_array_setting( $account_data, array( 'business_profile', 'support_address' ), $this->get_array_setting( $settings, 'account_business_support_address' ) ),
			'account_business_support_email'     => $this->get_account_string_setting( $account_data, array( 'business_profile', 'support_email' ), $this->get_string_setting( $settings, 'account_business_support_email' ) ),
			'account_business_support_phone'     => $this->get_account_string_setting( $account_data, array( 'business_profile', 'support_phone' ), $this->get_string_setting( $settings, 'account_business_support_phone' ) ),
			'account_branding_logo'              => $this->get_account_string_setting( $account_data, array( 'branding', 'logo' ), $this->get_string_setting( $settings, 'account_branding_logo' ) ),
			'account_branding_icon'              => $this->get_account_string_setting( $account_data, array( 'branding', 'icon' ), $this->get_string_setting( $settings, 'account_branding_icon' ) ),
			'account_branding_primary_color'     => $this->get_account_string_setting( $account_data, array( 'branding', 'primary_color' ), $this->get_string_setting( $settings, 'account_branding_primary_color' ) ),
			'account_branding_secondary_color'   => $this->get_account_string_setting( $account_data, array( 'branding', 'secondary_color' ), $this->get_string_setting( $settings, 'account_branding_secondary_color' ) ),
			'account_domestic_currency'          => $this->account_service->get_account_domestic_currency(),
			'account_communications_email'       => $this->get_account_string_setting( $account_data, array( 'communications_email' ), $this->get_string_setting( $settings, 'account_communications_email' ) ),
			'deposit_schedule_interval'          => $this->get_account_string_setting( $account_data, array( 'deposits', 'interval' ), $this->get_string_setting( $settings, 'deposit_schedule_interval' ) ),
			'deposit_schedule_monthly_anchor'    => $this->get_account_nullable_int_setting( $account_data, array( 'deposits', 'monthly_anchor' ), $this->get_nullable_int_setting( $settings, 'deposit_schedule_monthly_anchor' ) ),
			'deposit_schedule_weekly_anchor'     => $this->get_account_string_setting( $account_data, array( 'deposits', 'weekly_anchor' ), $this->get_string_setting( $settings, 'deposit_schedule_weekly_anchor' ) ),
			'deposit_delay_days'                 => $this->get_account_nullable_int_setting( $account_data, array( 'deposits', 'delay_days' ) ),
			'deposit_status'                     => $this->get_account_string_setting( $account_data, array( 'deposits', 'status' ) ),
			'deposit_restrictions'               => $this->get_account_string_setting( $account_data, array( 'deposits', 'restrictions' ) ),
			'deposit_completed_waiting_period'   => $this->get_account_bool_setting( $account_data, array( 'deposits', 'completed_waiting_period' ) ),
		);
	}

	/**
	 * Get a nested scalar account value as a string.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 * @param string[]            $path         Nested path.
	 * @param string              $fallback     Fallback value.
	 * @return string
	 */
	private function get_account_string_setting( array $account_data, array $path, string $fallback = '' ): string {
		$value = $this->get_account_path_value( $account_data, $path, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Get a nested account value as an array.
	 *
	 * @param array<string,mixed>     $account_data Account data.
	 * @param string[]                $path         Nested path.
	 * @param array<int|string,mixed> $fallback     Fallback value.
	 * @return array<int|string,mixed>
	 */
	private function get_account_array_setting( array $account_data, array $path, array $fallback = array() ): array {
		$value = $this->get_account_path_value( $account_data, $path, $fallback );

		return is_array( $value ) ? $value : $fallback;
	}

	/**
	 * Get a nested account value as an integer or null.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 * @param string[]            $path         Nested path.
	 * @param int|null            $fallback     Fallback value.
	 * @return int|null
	 */
	private function get_account_nullable_int_setting( array $account_data, array $path, ?int $fallback = null ): ?int {
		$value = $this->get_account_path_value( $account_data, $path, $fallback );

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Get a nested account value as a boolean.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 * @param string[]            $path         Nested path.
	 * @param bool                $fallback     Fallback value.
	 * @return bool
	 */
	private function get_account_bool_setting( array $account_data, array $path, bool $fallback = false ): bool {
		$value = $this->get_account_path_value( $account_data, $path, $fallback );

		return is_bool( $value ) ? $value : $this->is_yes( $value );
	}

	/**
	 * Get a nested value from account data.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 * @param string[]            $path         Nested path.
	 * @param mixed               $fallback     Fallback value.
	 * @return mixed
	 */
	private function get_account_path_value( array $account_data, array $path, $fallback ) {
		$value = $account_data;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $fallback;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Get a scalar setting as a string.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Setting key.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private function get_string_setting( array $settings, string $key, string $fallback = '' ): string {
		return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : $fallback;
	}

	/**
	 * Get an array setting.
	 *
	 * @param array<string,mixed>     $settings Settings.
	 * @param string                  $key      Setting key.
	 * @param array<int|string,mixed> $fallback Fallback value.
	 * @return array<int|string,mixed>
	 */
	private function get_array_setting( array $settings, string $key, array $fallback = array() ): array {
		return isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Get a nullable integer setting.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Setting key.
	 * @return int|null
	 */
	private function get_nullable_int_setting( array $settings, string $key ): ?int {
		return isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ? (int) $settings[ $key ] : null;
	}

	/**
	 * Get an integer setting.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Setting key.
	 * @param int                 $fallback Fallback value.
	 * @return int
	 */
	private function get_int_setting( array $settings, string $key, int $fallback = 0 ): int {
		return isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ? (int) $settings[ $key ] : $fallback;
	}

	/**
	 * Tell whether a local setting update should be ignored in dev mode.
	 *
	 * @param string $request_key Request key.
	 * @return bool
	 */
	private function should_skip_local_setting_update_in_dev_mode( string $request_key ): bool {
		return $this->account_service->is_dev_mode_enabled()
			&& in_array( $request_key, array( 'is_test_mode_enabled', 'is_debug_log_enabled' ), true );
	}

	/**
	 * Read payment method IDs as a list of unique, non-empty strings.
	 *
	 * @param array<int|string,mixed> $payment_method_ids Raw payment method IDs.
	 * @return string[]
	 */
	private function normalize_payment_method_ids( array $payment_method_ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $payment_method_id ): string => is_scalar( $payment_method_id ) ? (string) $payment_method_id : '',
						$payment_method_ids
					),
					static fn( string $payment_method_id ): bool => '' !== $payment_method_id
				)
			)
		);
	}

	/**
	 * Keep only supported payment method IDs.
	 *
	 * @param array<int|string,mixed> $payment_method_ids Raw payment method IDs.
	 * @param string[]                $allowed_ids        Allowed payment method IDs.
	 * @return string[]
	 */
	private function sanitize_payment_method_ids( array $payment_method_ids, array $allowed_ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $payment_method_id ): string => is_scalar( $payment_method_id ) ? (string) $payment_method_id : '',
						$payment_method_ids
					),
					static fn( string $payment_method_id ): bool => in_array( $payment_method_id, $allowed_ids, true )
				)
			)
		);
	}
}
