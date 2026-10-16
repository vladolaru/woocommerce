<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminNoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLocaleUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\RecordingWooPaySessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\RecordingWooPayAppearanceStore;

/**
 * Tests for the WooPaymentsSettingsService class.
 */
class WooPaymentsSettingsServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSettingsService
	 */
	private $sut;

	/**
	 * Recording native API client.
	 *
	 * @var RecordingSettingsApiClient
	 */
	private RecordingSettingsApiClient $api_client;

	/**
	 * Mock WooPay session service.
	 *
	 * @var RecordingWooPaySessionService
	 */
	private RecordingWooPaySessionService $woopay_session_service;

	/**
	 * Recording PM promotions service.
	 *
	 * @var RecordingPmPromotionsService
	 */
	private RecordingPmPromotionsService $pm_promotions_service;

	/**
	 * Original WooCommerce options mutated by focused settings-contract tests.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_woocommerce_options = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( $this->get_mutated_woocommerce_options() as $option_name ) {
			$this->original_woocommerce_options[ $option_name ] = get_option( $option_name, null );
		}

		$this->api_client             = new RecordingSettingsApiClient();
		$this->woopay_session_service = new RecordingWooPaySessionService();
		$this->pm_promotions_service  = new RecordingPmPromotionsService();
		$account_service              = $this->create_account_service();
		$this->sut                    = new WooPaymentsSettingsService();
		$this->sut->init( $account_service, $this->api_client, $this->pm_promotions_service, wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class ), wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class ), wc_get_container()->get( WooPaymentsStripeBillingModule::class ), $this->create_fraud_ruleset_service( $account_service ), $this->create_duplicate_gateway_methods_service( $account_service ), $this->create_woopay_eligibility( $account_service ), new RecordingWooPayAppearanceStore( $this->woopay_session_service ), wc_get_container()->get( WooPaymentsFrontendTrackingController::class ), wc_get_container()->get( WooPaymentsLogger::class ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woopay_invalid_extension_found' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( '_wcpay_feature_subscriptions' );
		delete_option( '_wcpay_feature_stripe_billing' );
		delete_option( '_wcpay_feature_woopay_express_checkout' );
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		delete_option( '_wcpay_feature_amazon_pay' );
		delete_option( '_wcpay_pm_promotion_dismissals' );
		delete_option( 'wcpay_duplicate_payment_method_notices_dismissed' );
		delete_option( 'wcpay_fraud_protection_welcome_tour_dismissed' );
		delete_option( 'wcpay_frt_review_feature_active' );
		delete_option( 'wcpay_next_deposit_notice_dismissed' );
		delete_option( 'current_protection_level' );
		delete_option( 'woocommerce_woocommerce_payments_ideal_settings' );
		delete_option( 'woocommerce_woocommerce_payments_apple_pay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_google_pay_settings' );
		delete_transient( 'wcpay_fraud_protection_settings' );
		foreach ( $this->get_mutated_woocommerce_options() as $option_name ) {
			if ( array_key_exists( $option_name, $this->original_woocommerce_options ) && null !== $this->original_woocommerce_options[ $option_name ] ) {
				update_option( $option_name, $this->original_woocommerce_options[ $option_name ] );
			} else {
				delete_option( $option_name );
			}
		}
		remove_all_filters( 'wcpay_dev_mode' );
		remove_all_filters( 'wcpay_test_mode' );
		remove_all_filters( 'wcpay_test_mode_onboarding' );
		remove_all_filters( 'wcpay_upe_available_payment_methods' );
		remove_all_actions( 'wc_payment_gateways_initialized' );
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should return the WooPayments settings contract, with the Stripe Billing fields as the WooPayments extension reports them without WooCommerce Subscriptions (the WooPayments extension `class-wc-rest-payments-settings-controller.php:530-600`).
	 */
	public function test_get_settings_returns_reference_shaped_contract(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => 'yes' ) );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                                => 'yes',
				'manual_capture'                         => 'yes',
				'test_mode'                              => 'yes',
				'enable_logging'                         => 'yes',
				'saved_cards'                            => 'no',
				'express_checkout_in_payment_methods'    => 'yes',
				'payment_request_button_size'            => 'large',
				'payment_request_button_type'            => 'buy',
				'payment_request_button_theme'           => 'dark',
				'payment_request_button_border_radius'   => 12,
				'platform_checkout'                      => 'yes',
				'platform_checkout_last_disable_date'    => '2026-06-13',
				'is_woopay_global_theme_support_enabled' => 'yes',
				'platform_checkout_custom_message'       => 'Custom WooPay message.',
				'platform_checkout_store_logo'           => 'file_logo',
				'upe_enabled_payment_method_ids'         => array( 'card', 'link' ),
				'account_country'                        => 'US',
				'account_statement_descriptor'           => 'NATIVE STORE',
				'account_business_name'                  => 'Native Store',
				'account_business_url'                   => 'https://example.test',
				'account_business_support_address'       => array( 'city' => 'San Francisco' ),
				'account_business_support_email'         => 'support@example.test',
				'account_business_support_phone'         => '+15555550123',
				'account_branding_logo'                  => 'file_logo',
				'account_branding_icon'                  => 'file_icon',
				'account_branding_primary_color'         => '#111111',
				'account_branding_secondary_color'       => '#eeeeee',
				'account_domestic_currency'              => 'usd',
				'account_communications_email'           => 'owner@example.test',
				'deposit_schedule_interval'              => 'weekly',
				'deposit_schedule_weekly_anchor'         => 'monday',
				'deposit_schedule_monthly_anchor'        => 15,
				'deposit_delay_days'                     => 2,
				'deposit_status'                         => 'enabled',
				'deposit_restrictions'                   => array( 'minimum_balance' => 1000 ),
				'deposit_completed_waiting_period'       => true,
				'current_protection_level'               => 'advanced',
				'advanced_fraud_protection_settings'     => array( array( 'key' => 'avs_check' ) ),
				'express_checkout_product_methods'       => array( 'payment_request' ),
				'express_checkout_cart_methods'          => array( 'amazon_pay' ),
				'express_checkout_checkout_methods'      => array( 'woopay' ),
				'is_stripe_billing_enabled'              => false,
			)
		);
		update_option( '_wcpay_feature_stripe_billing', '1' );
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( 'woopay_invalid_extension_found', true );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                 => 'acct_native_test',
					'is_live'                    => true,
					'platform_checkout_eligible' => true,
					'test_publishable_key'       => 'pk_test_native',
					'live_publishable_key'       => 'pk_live_native',
					'platform_global_theme_support_enabled' => true,
					'capabilities'               => array(
						'card_payments' => 'active',
						'link_payments' => 'unrequested',
					),
					'capability_requirements'    => array(
						'link_payments' => array( 'currently_due' => array( 'tos_acceptance.date' ) ),
					),
					'fees'                       => array(
						'card'           => array(
							'base'       => array(
								'percentage_rate' => 0.029,
								'fixed_rate'      => 30,
								'currency'        => 'USD',
							),
							'additional' => array(
								'percentage_rate' => 0.01,
								'fixed_rate'      => 0,
								'currency'        => 'USD',
							),
							'fx'         => array(
								'percentage_rate' => 0.01,
								'fixed_rate'      => 0,
								'currency'        => 'USD',
							),
							'discount'   => array(
								array(
									'percentage_rate' => 0.0261,
									'fixed_rate'      => 27,
									'currency'        => 'USD',
									'discount'        => 0.1,
								),
							),
						),
						'link'           => array(),
						'affirm'         => array(),
						'ideal'          => array(),
						'invalid_method' => array(
							'base' => array(
								'percentage_rate' => 0.099,
								'fixed_rate'      => 99,
								'currency'        => 'USD',
							),
						),
					),
					'store_currencies'           => array(
						'default'   => 'usd',
						'supported' => array( 'usd' ),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		update_option(
			'wcpay_duplicate_payment_method_notices_dismissed',
			array(
				'card' => array( 'legacy-gateway' ),
			)
		);
		$this->woopay_session_service->appearance        = array(
			'variables' => array(
				'colorBackground' => '#ffffff',
			),
		);
		$this->woopay_session_service->font_rules        = array(
			array(
				'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
			),
		);
		$this->pm_promotions_service->visible_promotions = array(
			array(
				'id'                   => 'klarna-promo__badge',
				'promo_id'             => 'klarna-promo',
				'payment_method'       => 'klarna',
				'payment_method_title' => 'Klarna',
				'type'                 => 'badge',
				'title'                => 'Lower fees',
				'description'          => 'Save on Klarna processing fees.',
				'cta_label'            => 'Enable Klarna',
				'tc_url'               => 'https://example.com/terms',
				'tc_label'             => 'See terms',
				'badge_type'           => 'success',
			),
		);

		$settings = $this->sut->get_settings();

		foreach ( $this->get_required_settings_keys() as $key ) {
			$this->assertArrayHasKey( $key, $settings, "Expected {$key} to be present in the settings contract." );
		}

		$this->assertSame( array( 'card', 'link' ), $settings['enabled_payment_method_ids'] );
		$this->assertContains( 'card', $settings['available_payment_method_ids'] );
		$this->assertContains( 'affirm', $settings['available_payment_method_ids'] );
		$this->assertContains( 'ideal', $settings['available_payment_method_ids'] );
		$this->assertNotContains( 'woopay', $settings['available_payment_method_ids'] );
		$this->assertTrue( $settings['is_wcpay_enabled'] );
		$this->assertTrue( $settings['is_manual_capture_enabled'] );
		$this->assertTrue( $settings['is_test_mode_enabled'] );
		$this->assertTrue( $settings['is_multi_currency_enabled'] );
		$this->assertTrue( $settings['is_wcpay_subscriptions_enabled'] );
		$this->assertFalse( $settings['is_saved_cards_enabled'] );
		$this->assertTrue( $settings['is_woopay_enabled'] );
		$this->assertSame( '2026-06-13', $settings['woopay_last_disable_date'] );
		$this->assertTrue( $settings['is_woopay_global_theme_support_enabled'] );
		$this->assertTrue( $settings['is_woopay_global_theme_support_eligible'] );
		$this->assertSame(
			array(
				'woopay'                                   => true,
				'woopayExpressCheckout'                    => true,
				'isDynamicCheckoutPlaceOrderButtonEnabled' => true,
				'amazonPay'                                => true,
				'isEceUsingConfirmationTokens'             => true,
			),
			$settings['feature_flags']
		);
		$this->assertTrue( $settings['show_woopay_incompatibility_notice'] );
		$this->assertSame( 'Custom WooPay message.', $settings['woopay_custom_message'] );
		$this->assertSame( 'file_logo', $settings['woopay_store_logo'] );
		$this->assertSame( array( 'variables' => array( 'colorBackground' => '#ffffff' ) ), $settings['woopay_appearance'] );
		$this->assertSame( array( array( 'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter' ) ), $settings['woopay_font_rules'] );
		$this->assertSame( get_bloginfo( 'name' ), $settings['store_name'] );
		$this->assertSame( '', $settings['site_logo_url'] );
		$this->assertSame( array( 'payment_request' ), $settings['express_checkout_product_methods'] );
		$this->assertSame(
			array(
				'stripe' => array(
					'publishableKey' => 'pk_test_native',
					'accountId'      => 'acct_native_test',
					'locale'         => WooPaymentsLocaleUtils::convert_to_stripe_locale( get_locale() ),
				),
			),
			$settings['express_checkout_preview']
		);
		$this->assertSame( 'active', $settings['payment_method_statuses']['card_payments']['status'] );
		$this->assertSame( 'unrequested', $settings['payment_method_statuses']['link_payments']['status'] );
		$this->assertSame( array( 'currently_due' => array( 'tos_acceptance.date' ) ), $settings['payment_method_statuses']['link_payments']['requirements'] );
		$this->assertSame( array( 'legacy-gateway' ), $settings['dismissed_duplicate_payment_method_notices']['card'] );
		$this->assertSame( $this->pm_promotions_service->visible_promotions, $settings['pm_promotions'] );
		$this->assertSame(
			array(
				'base'       => array(
					'percentage_rate' => 0.029,
					'fixed_rate'      => 30,
					'currency'        => 'USD',
				),
				'additional' => array(
					'percentage_rate' => 0.01,
					'fixed_rate'      => 0,
					'currency'        => 'USD',
				),
				'fx'         => array(
					'percentage_rate' => 0.01,
					'fixed_rate'      => 0,
					'currency'        => 'USD',
				),
				'discount'   => array(
					array(
						'percentage_rate' => 0.0261,
						'fixed_rate'      => 27,
						'currency'        => 'USD',
						'discount'        => 0.1,
					),
				),
			),
			$settings['account_fees']['card']
		);
		$this->assertArrayNotHasKey( 'invalid_method', $settings['account_fees'] );
		$this->assertTrue( $settings['is_stripe_billing_enabled'], 'The toggle is reported as stored, from its option, even without WooCommerce Subscriptions.' );
		$this->assertFalse( $settings['is_migrating_stripe_billing'] );
		$this->assertSame( 0, $settings['stripe_billing_subscription_count'] );
		$this->assertSame( 0, $settings['stripe_billing_migrated_count'] );
	}

	/**
	 * @testdox Should default Multi-Currency to enabled when the feature option is absent.
	 */
	public function test_get_settings_defaults_multi_currency_to_enabled_when_option_is_absent(): void {
		delete_option( '_wcpay_feature_customer_multi_currency' );

		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['is_multi_currency_enabled'] );
	}

	/**
	 * @testdox Should give the express checkout preview the site locale, not the admin user's.
	 */
	public function test_get_settings_express_checkout_preview_uses_the_site_locale(): void {
		$settings = $this->in_admin_with_french_user_locale( fn() => $this->sut->get_settings() );

		// Client 11.1.0 sends the site locale, get_locale(), not the user's (includes/admin/class-wc-payments-admin.php:697).
		$this->assertSame( 'en', $settings['express_checkout_preview']['stripe']['locale'] );
	}

	/**
	 * @testdox Should preserve an explicit Multi-Currency disable.
	 */
	public function test_get_settings_preserves_explicit_multi_currency_disable(): void {
		update_option( '_wcpay_feature_customer_multi_currency', '0' );

		$settings = $this->sut->get_settings();

		$this->assertFalse( $settings['is_multi_currency_enabled'] );
	}

	/**
	 * @testdox Should persist and read back an explicit Multi-Currency disable.
	 */
	public function test_update_settings_persists_and_reads_multi_currency_disable(): void {
		delete_option( '_wcpay_feature_customer_multi_currency' );

		$settings = $this->sut->update_settings( array( 'is_multi_currency_enabled' => false ) );

		$this->assertSame( '0', get_option( '_wcpay_feature_customer_multi_currency' ) );
		$this->assertFalse( $settings['is_multi_currency_enabled'] );
	}

	/**
	 * @testdox Should expose and persist form-field defaults when settings have not been saved.
	 */
	public function test_get_settings_round_trip_persists_form_field_defaults_when_settings_are_absent(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );

		$read_settings = $this->sut->get_settings();
		$updated       = $this->sut->update_settings( array() );
		$stored        = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertTrue( $read_settings['is_saved_cards_enabled'] );
		$this->assertSame( 'By placing this order, you agree to our [terms] and understand our [privacy_policy].', $read_settings['woopay_custom_message'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $read_settings['express_checkout_product_methods'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $read_settings['express_checkout_cart_methods'] );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $read_settings['express_checkout_checkout_methods'] );
		$this->assertIsArray( $updated );
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( 'yes', $stored['saved_cards'] );
	}

	/**
	 * @testdox Should expose and persist localized form-field defaults when settings have not been saved.
	 */
	public function test_get_settings_and_update_settings_use_localized_form_field_defaults_when_settings_are_absent(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		$translations       = array(
			'Buy now' => 'Jetzt kaufen',
			'By placing this order, you agree to our [terms] and understand our [privacy_policy].' => 'Mit der Bestellung stimmen Sie unseren [terms] zu und verstehen unsere [privacy_policy].',
		);
		$translation_filter = static function ( string $translation, string $text, string $domain ) use ( $translations ): string {
			return 'woocommerce' === $domain ? ( $translations[ $text ] ?? $translation ) : $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );

		try {
			$read_settings = $this->sut->get_settings();
			$this->sut->update_settings( array() );
			$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
		}

		$this->assertSame( 'Mit der Bestellung stimmen Sie unseren [terms] zu und verstehen unsere [privacy_policy].', $read_settings['woopay_custom_message'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'Jetzt kaufen', $stored['payment_request_button_label'] );
		$this->assertSame( 'Mit der Bestellung stimmen Sie unseren [terms] zu und verstehen unsere [privacy_policy].', $stored['platform_checkout_custom_message'] );
	}

	/**
	 * @testdox Public settings availability honors the registry-owned compatibility filter exactly once.
	 */
	public function test_get_settings_filters_available_payment_method_ids_once(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'bancontact' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id' => 'acct_native_test',
					'is_live'    => true,
					'fees'       => array(
						'card'       => array(),
						'bancontact' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		$filter_calls = 0;
		$filter_args  = array();
		add_filter(
			'wcpay_upe_available_payment_methods',
			static function ( array $payment_method_ids ) use ( &$filter_calls, &$filter_args ): array {
				++$filter_calls;
				$filter_args = func_get_args();

				return array_values( array_diff( $payment_method_ids, array( 'bancontact' ) ) );
			},
			10,
			99
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( array( 'card', 'apple_pay', 'google_pay' ), $settings['available_payment_method_ids'] );
		$this->assertSame( array( 'card' ), $settings['enabled_payment_method_ids'] );
		$this->assertSame( 1, $filter_calls, 'One public settings availability computation should dispatch the filter once.' );
		$this->assertCount( 1, $filter_args, 'The pinned oracle passes no gateway or context argument.' );
		$this->assertSame(
			array(
				'card',
				'affirm',
				'afterpay_clearpay',
				'alipay',
				'bancontact',
				'au_becs_debit',
				'eps',
				'grabpay',
				'ideal',
				'link',
				'multibanco',
				'klarna',
				'p24',
				'sepa_debit',
				'wechat_pay',
				'apple_pay',
				'google_pay',
				'amazon_pay',
			),
			$filter_args[0],
			'The settings path should filter the complete registry catalog before intersecting account availability.'
		);
	}

	/**
	 * P24 is retired: the platform no longer offers it to new accounts. Fees and capability statuses use the platform's
	 * account shape (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:4696-4717`; fee keys are payment method IDs).
	 *
	 * @testdox Should list a retired payment method only while its capability is active, and others by their fees alone.
	 * @testWith ["active", true]
	 *           ["unrequested", false]
	 *           ["inactive", false]
	 *
	 * @param string $p24_status Platform status of the p24_payments capability.
	 * @param bool   $listed     Whether P24 should be listed.
	 */
	public function test_get_settings_lists_a_retired_method_only_while_its_capability_is_active( string $p24_status, bool $listed ): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'       => 'active',
						'p24_payments'        => $p24_status,
						'sepa_debit_payments' => 'unrequested',
					),
					'fees'         => array(
						'card'       => array(),
						'p24'        => array(),
						'sepa_debit' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$available = $this->sut->get_settings()['available_payment_method_ids'];

		$this->assertSame( $listed, in_array( 'p24', $available, true ) );
		$this->assertContains( 'sepa_debit', $available, 'A method that is not retired is listed by its fee, whatever its status.' );
		$this->assertContains( 'card', $available );
	}

	/**
	 * @testdox Should report ECE confirmation tokens and Amazon Pay as off while no account is cached (the WooPayments extension `WC_Payments_Features::is_ece_confirmation_tokens_enabled()`, `class-wc-payments-features.php:438-442`).
	 */
	public function test_get_settings_reports_ece_confirmation_tokens_off_without_a_cached_account(): void {
		delete_option( 'wcpay_account_data' );

		$feature_flags = $this->sut->get_settings()['feature_flags'];

		$this->assertFalse( $feature_flags['isEceUsingConfirmationTokens'] );
		$this->assertFalse( $feature_flags['amazonPay'], 'Amazon Pay needs ECE confirmation tokens.' );
	}

	/**
	 * @testdox Should list Amazon Pay as available on the settings screen only while its feature is on (the WooPayments extension `PaymentMethodDefinitionRegistry.php:102-104`, read by the settings controller's `get_settings()` at `class-wc-rest-payments-settings-controller.php:516`).
	 * @testWith ["1", true]
	 *           ["0", false]
	 *
	 * @param string $flag   The Amazon Pay feature flag option value.
	 * @param bool   $listed Whether Amazon Pay should be listed.
	 */
	public function test_get_settings_lists_amazon_pay_only_while_its_feature_is_on( string $flag, bool $listed ): void {
		update_option( '_wcpay_feature_amazon_pay', $flag );
		$this->store_card_and_amazon_pay_account_data();

		$available = $this->sut->get_settings()['available_payment_method_ids'];

		$this->assertSame( $listed, in_array( 'amazon_pay', $available, true ) );
		$this->assertContains( 'card', $available );
	}

	/**
	 * @testdox Should leave Amazon Pay out of the catalog the availability filter receives while its feature is off (the WooPayments extension `PaymentMethodDefinitionRegistry.php:97-105` registers it only while the feature is on, and `class-wc-payment-gateway-wcpay.php:4851-4864` filters that catalog).
	 */
	public function test_availability_filter_receives_no_amazon_pay_while_its_feature_is_off(): void {
		update_option( '_wcpay_feature_amazon_pay', '0' );
		$this->store_card_and_amazon_pay_account_data();
		$received = array();
		$record   = static function ( array $payment_method_ids ) use ( &$received ): array {
			$received = $payment_method_ids;

			return $payment_method_ids;
		};
		add_filter( 'wcpay_upe_available_payment_methods', $record );

		try {
			$this->sut->get_settings();
		} finally {
			remove_filter( 'wcpay_upe_available_payment_methods', $record );
		}

		$this->assertContains( 'card', $received );
		$this->assertNotContains( 'amazon_pay', $received );
	}

	/**
	 * @testdox Should keep Amazon Pay available when an availability filter callback adds it while its feature is off (the WooPayments extension `class-wc-payment-gateway-wcpay.php:4864-4879` keeps what the filter returns, then intersects with the fees).
	 */
	public function test_availability_filter_can_add_amazon_pay_while_its_feature_is_off(): void {
		update_option( '_wcpay_feature_amazon_pay', '0' );
		$this->store_card_and_amazon_pay_account_data();
		$add_amazon_pay = static fn( array $payment_method_ids ): array => array_values( array_unique( array_merge( $payment_method_ids, array( 'amazon_pay' ) ) ) );
		add_filter( 'wcpay_upe_available_payment_methods', $add_amazon_pay );

		try {
			$available = $this->sut->get_settings()['available_payment_method_ids'];
		} finally {
			remove_filter( 'wcpay_upe_available_payment_methods', $add_amazon_pay );
		}

		$this->assertContains( 'amazon_pay', $available );
	}

	/**
	 * @testdox Should list the fee-backed payment methods on the settings screen when an availability filter callback returns null.
	 */
	public function test_get_settings_ignores_an_availability_filter_that_returns_null(): void {
		$this->set_card_and_ideal_account_data();
		$return_null = static fn() => null;
		add_filter( 'wcpay_upe_available_payment_methods', $return_null );

		try {
			$available = $this->sut->get_settings()['available_payment_method_ids'];
		} finally {
			remove_filter( 'wcpay_upe_available_payment_methods', $return_null );
		}

		$this->assertSame( array( 'card', 'ideal', 'apple_pay', 'google_pay' ), $available );
	}

	/**
	 * @testdox Public settings filters the registry catalog before deriving fee-backed availability.
	 */
	public function test_get_settings_filters_catalog_before_reading_fee_backed_availability(): void {
		$events          = array();
		$account_service = new class( $events ) extends WooPaymentsAccountService {
			/**
			 * Recorded availability events.
			 *
			 * @var string[]
			 */
			private array $events;

			/**
			 * Create the recording account service.
			 *
			 * @param string[] $events Recorded availability events.
			 */
			public function __construct( array &$events ) {
				$this->events = &$events;
			}

			/**
			 * Record and return fee-backed account availability.
			 *
			 * @param bool $force_refresh Whether to force an account refresh.
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				unset( $force_refresh );
				$this->events[] = 'account_fees';

				return array(
					'account_id' => 'acct_ordering_test',
					'fees'       => array(
						'card'       => array(),
						'bancontact' => array(),
					),
				);
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );
		add_filter(
			'wcpay_upe_available_payment_methods',
			static function ( array $payment_method_ids ) use ( &$events ): array {
				$events[] = 'availability_filter';

				return array_values( array_diff( $payment_method_ids, array( 'bancontact' ) ) );
			}
		);
		$service = new WooPaymentsSettingsService();
		$service->init(
			$account_service,
			$this->api_client,
			$this->pm_promotions_service,
			wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class ),
			new WooPaymentsPaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsStripeBillingModule::class ),
			$this->create_fraud_ruleset_service( $account_service ),
			$this->create_duplicate_gateway_methods_service( $account_service ),
			$this->create_woopay_eligibility( $account_service ),
			new RecordingWooPayAppearanceStore( $this->woopay_session_service ),
			wc_get_container()->get( WooPaymentsFrontendTrackingController::class ),
			wc_get_container()->get( WooPaymentsLogger::class )
		);

		$settings = $service->get_settings();

		// The Amazon Pay feature reads the account first, as the client's registry does before any filter runs
		// (client 11.1.0 PaymentMethodDefinitionRegistry.php:97-105); the fees are read after the one filter run.
		$this->assertSame( array( 'account_fees', 'availability_filter', 'account_fees' ), array_slice( $events, 0, 3 ) );
		$this->assertSame( 1, count( array_keys( $events, 'availability_filter', true ) ) );
		$this->assertSame( array( 'card', 'apple_pay', 'google_pay' ), $settings['available_payment_method_ids'] );
	}

	/**
	 * @testdox Should default the dynamic place-order feature flag off
	 */
	public function test_dynamic_checkout_place_order_button_flag_defaults_off(): void {
		delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );

		$this->assertFalse( WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled() );

		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );

		try {
			$this->assertTrue( WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled() );
		} finally {
			delete_option( '_wcpay_feature_dynamic_checkout_place_order_button' );
		}
	}

	/**
	 * @testdox Should expose disabled express checkout feature flags without mutating saved settings.
	 */
	public function test_get_settings_exposes_disabled_express_checkout_feature_flags(): void {
		update_option( '_wcpay_feature_woopay_express_checkout', '0' );
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '0' );
		update_option( '_wcpay_feature_amazon_pay', '0' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout'              => 'yes',
				'upe_available_payment_methods'  => array( 'card', 'amazon_pay' ),
				'upe_enabled_payment_method_ids' => array( 'card', 'amazon_pay' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                 => 'acct_native_test',
					'is_live'                    => true,
					'platform_checkout_eligible' => true,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['is_woopay_enabled'], 'The saved WooPay setting should remain independent of express feature flags.' );
		$this->assertNotContains( 'amazon_pay', $settings['available_payment_method_ids'] );
		$this->assertNotContains( 'amazon_pay', $settings['enabled_payment_method_ids'] );
		$this->assertSame(
			array(
				'woopay'                                   => true,
				'woopayExpressCheckout'                    => false,
				'isDynamicCheckoutPlaceOrderButtonEnabled' => false,
				'amazonPay'                                => false,
				'isEceUsingConfirmationTokens'             => true,
			),
			$settings['feature_flags']
		);
	}

	/**
	 * @testdox Should report the bundled subscriptions off and not eligible on a store without them ($description).
	 * @testWith ["flag never stored", null]
	 *           ["flag stored off", "0"]
	 *
	 * @param string      $description Case.
	 * @param string|null $flag        Stored bundled subscriptions flag, or null when never stored.
	 */
	public function test_get_settings_reports_bundled_subscriptions_off( string $description, ?string $flag ): void {
		unset( $description );
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_subscriptions', $flag );
		}

		$settings = $this->sut->get_settings();

		$this->assertFalse( $settings['is_wcpay_subscriptions_enabled'] );
		$this->assertFalse( $settings['is_wcpay_subscriptions_eligible'], 'No subscription or product carries Stripe Billing data.' );
	}

	/**
	 * Client 11.1.0 only turns the bundled subscriptions off from the settings save (class-wc-rest-payments-settings-controller.php:904-916).
	 *
	 * @testdox Should never turn the bundled subscriptions on from the settings save ($description).
	 * @testWith ["flag never stored", null]
	 *           ["flag stored on", "1"]
	 *
	 * @param string      $description Case.
	 * @param string|null $flag        Stored bundled subscriptions flag, or null when never stored.
	 */
	public function test_update_settings_never_turns_bundled_subscriptions_on( string $description, ?string $flag ): void {
		unset( $description );
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_subscriptions', $flag );
		}

		$this->sut->update_settings( array( 'is_wcpay_subscriptions_enabled' => true ) );

		$this->assertSame( $flag ?? false, get_option( '_wcpay_feature_subscriptions', false ) );
	}

	/**
	 * @testdox Should report WooCommerce Subscriptions inactive while its class is not loaded.
	 */
	public function test_get_settings_reports_woocommerce_subscriptions_inactive(): void {
		$this->assertFalse( $this->sut->get_settings()['is_subscriptions_plugin_active'] );
	}

	/**
	 * @testdox Should report WooCommerce Subscriptions active once its class is loaded.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_settings_reports_woocommerce_subscriptions_active(): void {
		// The global WC_Subscriptions class cannot be unloaded, so this test runs in its own process.
		require_once __DIR__ . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( Fixtures\LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$this->assertTrue( $this->sut->get_settings()['is_subscriptions_plugin_active'] );
	}

	/**
	 * @testdox Should derive WooPayments Subscriptions eligibility from Stripe Billing product metadata.
	 */
	public function test_get_settings_detects_stripe_billing_subscription_product(): void {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		update_post_meta( $product_id, '_wcpay_product_hash', 'product_hash' );

		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['is_wcpay_subscriptions_eligible'] );
	}

	/**
	 * @testdox Should report WooPay as off for an ineligible account whatever the saved setting.
	 *
	 * Client 11.1.0 reports `is_woo_pay_eligible() && platform_checkout` (includes/admin/class-wc-rest-payments-settings-controller.php:585).
	 */
	public function test_get_settings_reports_woopay_off_for_an_ineligible_account(): void {
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout' => 'yes',
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                 => 'acct_native_test',
					'is_live'                    => true,
					'platform_checkout_eligible' => false,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertFalse( $settings['is_woopay_enabled'], 'An account that cannot use WooPay does not report it enabled.' );
		$this->assertSame(
			array(
				'woopay'                                   => false,
				'woopayExpressCheckout'                    => true,
				'isDynamicCheckoutPlaceOrderButtonEnabled' => true,
				'amazonPay'                                => true,
				'isEceUsingConfirmationTokens'             => true,
			),
			$settings['feature_flags']
		);
	}

	/**
	 * @testdox Should expose the fraud-protection environment fields that core's settings surfaces read.
	 */
	public function test_get_settings_exposes_fraud_protection_environment_fields(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'US', 'CA' ) );
		update_option( 'wcpay_frt_review_feature_active', '1' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                => 'acct_native_test',
					'is_live'                   => true,
					'fraud_mitigation_settings' => array(
						'avs_check_enabled' => false,
						'cvc_check_enabled' => true,
					),
					'store_currencies'          => array(
						'default'   => 'usd',
						'supported' => array( 'usd', 'eur' ),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( 'EUR', $settings['store_currency'] );
		$this->assertTrue( $settings['is_fraud_protection_review_feature_active'] );
		$this->assertSame(
			array(
				'type'      => 'specific',
				'countries' => array( 'US', 'CA' ),
			),
			$settings['fraud_protection_allowed_countries']
		);
		$this->assertSame(
			array(
				'decline_on_avs_failure'    => false,
				'decline_on_cvc_failure'    => true,
				'is_welcome_tour_dismissed' => false,
			),
			$settings['fraud_protection']
		);
	}

	/**
	 * @testdox Should expose fraud-protection welcome tour dismissed state.
	 */
	public function test_get_settings_exposes_fraud_protection_welcome_tour_dismissed_state(): void {
		$settings = $this->sut->get_settings();

		$this->assertArrayHasKey( 'is_welcome_tour_dismissed', $settings['fraud_protection'] );
		$this->assertFalse( $settings['fraud_protection']['is_welcome_tour_dismissed'] );

		update_option( 'wcpay_fraud_protection_welcome_tour_dismissed', true );
		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['fraud_protection']['is_welcome_tour_dismissed'] );
	}

	/**
	 * @testdox Should expose duplicate payment method clusters from enabled WooPayments and third-party gateways.
	 */
	public function test_get_settings_exposes_duplicate_payment_method_clusters(): void {
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'legacy_card_gateway', 'yes' ),
				$this->create_gateway( 'disabled_card_gateway', 'no' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame(
			array( 'woocommerce_payments', 'legacy_card_gateway' ),
			$settings['duplicated_payment_method_ids']['card']
		);
	}

	/**
	 * @testdox Should expose Apple Pay and Google Pay duplicate clusters when payment request is enabled.
	 */
	public function test_get_settings_exposes_apple_pay_google_pay_duplicate_cluster_when_payment_request_is_enabled(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'payment_request' => 'yes',
			)
		);
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'legacy_applepay_gateway', 'yes' ),
				$this->create_gateway( 'stripe', 'yes', array( 'payment_request' => 'yes' ) ),
				// A third-party gateway offering wallets through the express_checkout_enabled option the client checks
				// (client 11.1.0 `includes/class-duplicates-detection-service.php:141`).
				$this->create_gateway( 'acme_wallets', 'yes', array( 'express_checkout_enabled' => 'yes' ) ),
				$this->create_gateway( 'acme_cards', 'yes', array( 'express_checkout_enabled' => 'no' ) ),
				$this->create_gateway( 'disabled_googlepay_gateway', 'no' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertArrayHasKey(
			'apple_pay_google_pay',
			$settings['duplicated_payment_method_ids'],
			'Native payment request support should expose the Apple Pay and Google Pay duplicate cluster.'
		);
		$this->assertEqualsCanonicalizing(
			array(
				'woocommerce_payments',
				'legacy_applepay_gateway',
				'stripe',
				'acme_wallets',
			),
			$settings['duplicated_payment_method_ids']['apple_pay_google_pay'],
			'Native payment request support should cluster with enabled Apple Pay, Google Pay, or Stripe payment-request gateways.'
		);
	}

	/**
	 * @testdox Should omit wallet duplicate clusters until a split wallet gateway is enabled.
	 */
	public function test_get_settings_omits_apple_pay_google_pay_duplicate_cluster_when_split_settings_are_missing(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'legacy_googlepay_gateway', 'yes' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertArrayNotHasKey(
			'apple_pay_google_pay',
			$settings['duplicated_payment_method_ids'],
			'Unconfigured split wallet gateways are disabled.'
		);
	}

	/**
	 * @testdox Should omit Apple Pay and Google Pay duplicate clusters when payment request is disabled.
	 */
	public function test_get_settings_omits_apple_pay_google_pay_duplicate_cluster_when_payment_request_is_disabled(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'payment_request' => 'no',
			)
		);
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'legacy_google_pay_gateway', 'yes' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertArrayNotHasKey(
			'apple_pay_google_pay',
			$settings['duplicated_payment_method_ids'],
			'Apple Pay and Google Pay duplicates require native WooPayments payment request support.'
		);
	}

	/**
	 * @testdox Should omit Apple Pay and Google Pay duplicate clusters when third-party option-gated wallets are disabled.
	 */
	public function test_get_settings_omits_apple_pay_google_pay_duplicate_cluster_for_disabled_option_gated_wallets(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'payment_request' => 'yes',
			)
		);
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'stripe', 'yes', array( 'payment_request' => 'no' ) ),
				$this->create_gateway( 'wallet_without_express_option', 'yes' ),
				$this->create_gateway(
					'wallet_with_disabled_express_option',
					'yes',
					array( 'express_checkout_enabled' => 'no' )
				),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertArrayNotHasKey(
			'apple_pay_google_pay',
			$settings['duplicated_payment_method_ids'],
			'Apple Pay and Google Pay duplicates should not include third-party gateways with disabled Stripe payment request or generic express options.'
		);
	}

	/**
	 * @testdox Should classify split WooPayments duplicate gateway IDs by payment method suffix.
	 */
	public function test_get_settings_classifies_split_woopayments_duplicate_gateways_by_suffix(): void {
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'woocommerce_payments_klarna', 'yes' ),
				$this->create_gateway( 'legacy_klarna_gateway', 'yes' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame(
			array( 'woocommerce_payments_klarna', 'legacy_klarna_gateway' ),
			$settings['duplicated_payment_method_ids']['klarna']
		);
		$this->assertArrayNotHasKey( 'card', $settings['duplicated_payment_method_ids'] );
	}

	/**
	 * @testdox Should omit duplicate payment method clusters without an enabled WooPayments gateway.
	 */
	public function test_get_settings_omits_duplicate_clusters_without_woopayments_gateway(): void {
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'legacy_card_gateway', 'yes' ),
				$this->create_gateway( 'another_card_gateway', 'yes' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( array(), $settings['duplicated_payment_method_ids'] );
	}

	/**
	 * @testdox Should fail closed when duplicate payment method gateway inspection fails.
	 */
	public function test_get_settings_fails_closed_when_duplicate_gateway_inspection_fails(): void {
		$this->mock_payment_gateways(
			array(
				new class() {
					/**
					 * Tell whether a property exists.
					 *
					 * @param string $name Property name.
					 * @return bool
					 *
					 * @throws \RuntimeException When gateway inspection is not available.
					 */
					public function __isset( string $name ): bool {
						if ( 'enabled' === $name ) {
							throw new \RuntimeException( 'Gateway inspection unavailable' );
						}

						return false;
					}
				},
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( array(), $settings['duplicated_payment_method_ids'] );
	}

	/**
	 * @testdox Should cluster the Stripe gateway's express checkout option with Apple Pay and Google Pay only when the wallets are on.
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $payment_request_setting WooPayments payment request setting.
	 * @param bool   $expect_cluster          Whether the Apple Pay and Google Pay cluster is expected.
	 */
	public function test_get_settings_clusters_stripe_express_checkout_option_with_the_payment_request_setting( string $payment_request_setting, bool $expect_cluster ): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'payment_request' => $payment_request_setting,
			)
		);
		// WooCommerce Stripe Gateway 11 stores its Apple Pay / Google Pay switch as `express_checkout`, not `payment_request`.
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'stripe', 'yes', array( 'express_checkout' => 'yes' ) ),
			)
		);

		$duplicates = $this->sut->get_settings()['duplicated_payment_method_ids'];

		if ( ! $expect_cluster ) {
			$this->assertArrayNotHasKey(
				'apple_pay_google_pay',
				$duplicates,
				'Without native Apple Pay / Google Pay there is no wallet duplicate to report.'
			);
			return;
		}

		$this->assertArrayHasKey(
			'apple_pay_google_pay',
			$duplicates,
			'Stripe express checkout should be detected as an Apple Pay / Google Pay duplicate.'
		);
		$this->assertEqualsCanonicalizing(
			array( 'woocommerce_payments', 'stripe' ),
			$duplicates['apple_pay_google_pay'],
			'The wallet cluster should hold native WooPayments and the Stripe gateway.'
		);
	}

	/**
	 * @testdox Should classify Stripe sub-gateways by their payment method and match only the exact Stripe and WooPayments IDs as card.
	 */
	public function test_get_settings_classifies_stripe_sub_gateways_by_payment_method(): void {
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'woocommerce_payments_alipay', 'yes' ),
				$this->create_gateway( 'woocommerce_payments_amazon_pay', 'yes' ),
				$this->create_gateway( 'stripe', 'yes' ),
				$this->create_gateway( 'stripe_alipay', 'yes' ),
				$this->create_gateway( 'stripe_amazon_pay', 'yes' ),
				$this->create_gateway( 'stripe_link', 'yes' ),
				$this->create_gateway( 'stripe_boleto', 'yes' ),
			)
		);

		$duplicates = $this->sut->get_settings()['duplicated_payment_method_ids'];

		// WooPayments registers no separate Link gateway, so stripe_link has no WooPayments partner to cluster with.
		$this->assertEqualsCanonicalizing(
			array( 'card', 'alipay', 'amazon_pay' ),
			array_keys( $duplicates ),
			'Each Stripe sub-gateway should form a cluster with its WooPayments method.'
		);
		$this->assertEqualsCanonicalizing(
			array( 'woocommerce_payments', 'stripe' ),
			$duplicates['card'],
			'Only the exact stripe and woocommerce_payments IDs belong to the card cluster.'
		);
		$this->assertEqualsCanonicalizing( array( 'woocommerce_payments_alipay', 'stripe_alipay' ), $duplicates['alipay'] );
		$this->assertEqualsCanonicalizing( array( 'woocommerce_payments_amazon_pay', 'stripe_amazon_pay' ), $duplicates['amazon_pay'] );
	}

	/**
	 * @testdox Should source account and deposit response fields from cached account data.
	 */
	public function test_get_settings_sources_account_and_deposit_fields_from_cached_account_data(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'account_country'                  => 'GB',
				'account_statement_descriptor'     => 'STALE STORE',
				'account_business_name'            => 'Stale Store',
				'deposit_schedule_interval'        => 'daily',
				'deposit_schedule_weekly_anchor'   => 'monday',
				'deposit_schedule_monthly_anchor'  => 1,
				'deposit_delay_days'               => 7,
				'deposit_status'                   => 'stale',
				'deposit_restrictions'             => array( 'stale' => true ),
				'deposit_completed_waiting_period' => false,
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                 => 'acct_native_test',
					'is_live'                    => true,
					'country'                    => 'US',
					'statement_descriptor'       => 'ACCOUNT STORE',
					'statement_descriptor_kanji' => 'アカウント',
					'statement_descriptor_kana'  => 'アカウント',
					'business_profile'           => array(
						'name'            => 'Account Store',
						'url'             => 'https://account.example',
						'support_address' => array( 'city' => 'San Francisco' ),
						'support_email'   => 'support@account.example',
						'support_phone'   => '+15555550123',
					),
					'branding'                   => array(
						'logo'            => 'file_account_logo',
						'icon'            => 'file_account_icon',
						'primary_color'   => '#123456',
						'secondary_color' => '#abcdef',
					),
					'communications_email'       => 'owner@account.example',
					'store_currencies'           => array(
						'default' => 'usd',
					),
					'deposits'                   => array(
						'interval'                 => 'weekly',
						'weekly_anchor'            => 'friday',
						'monthly_anchor'           => 15,
						'delay_days'               => 2,
						'status'                   => 'restricted',
						'restrictions'             => 'schedule_locked',
						'completed_waiting_period' => true,
					),
					'capabilities'               => array(
						'card_payments' => 'active',
					),
					'fees'                       => array(
						'card' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		update_option(
			'wcpay_duplicate_payment_method_notices_dismissed',
			array(
				'card' => array( 'legacy-gateway' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( 'US', $settings['account_country'] );
		$this->assertSame( 'ACCOUNT STORE', $settings['account_statement_descriptor'] );
		$this->assertSame( 'アカウント', $settings['account_statement_descriptor_kanji'] );
		$this->assertSame( 'アカウント', $settings['account_statement_descriptor_kana'] );
		$this->assertSame( 'Account Store', $settings['account_business_name'] );
		$this->assertSame( 'https://account.example', $settings['account_business_url'] );
		$this->assertSame( array( 'city' => 'San Francisco' ), $settings['account_business_support_address'] );
		$this->assertSame( 'support@account.example', $settings['account_business_support_email'] );
		$this->assertSame( '+15555550123', $settings['account_business_support_phone'] );
		$this->assertSame( 'file_account_logo', $settings['account_branding_logo'] );
		$this->assertSame( 'file_account_icon', $settings['account_branding_icon'] );
		$this->assertSame( '#123456', $settings['account_branding_primary_color'] );
		$this->assertSame( '#abcdef', $settings['account_branding_secondary_color'] );
		$this->assertSame( 'usd', $settings['account_domestic_currency'] );
		$this->assertSame( 'owner@account.example', $settings['account_communications_email'] );
		$this->assertSame( 'weekly', $settings['deposit_schedule_interval'] );
		$this->assertSame( 'friday', $settings['deposit_schedule_weekly_anchor'] );
		$this->assertSame( 15, $settings['deposit_schedule_monthly_anchor'] );
		$this->assertSame( 2, $settings['deposit_delay_days'] );
		$this->assertSame( 'restricted', $settings['deposit_status'] );
		$this->assertSame( 'schedule_locked', $settings['deposit_restrictions'] );
		$this->assertTrue( $settings['deposit_completed_waiting_period'] );
	}

	/**
	 * Client 11.1.0 always computes this field as the account's domestic currency (`class-wc-rest-payments-settings-controller.php:574`
	 * through the gateway's `get_option()` override, `class-wc-payment-gateway-wcpay.php:3090-3091`).
	 *
	 * @testdox Should report the account country's currency as the domestic currency, not the account's default currency or a stored value ($_dataName).
	 * @dataProvider provide_stored_domestic_currency_settings
	 *
	 * @param array<string,string> $stored_settings Stored gateway settings.
	 */
	public function test_get_settings_reports_the_account_country_currency_as_the_domestic_currency( array $stored_settings ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', $stored_settings );
		// A CA account whose default currency is USD. The account fields follow the recorded
		// Fixtures/platform-test-drive-account.json `account` (`account_id`, `country`, `store_currencies.default`).
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'       => 'acct_native_test',
					'is_live'          => true,
					'country'          => 'CA',
					'store_currencies' => array(
						'default' => 'usd',
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$this->assertSame( 'cad', $this->sut->get_settings()['account_domestic_currency'] );
	}

	/**
	 * Stored gateway settings with and without an account_domestic_currency value.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provide_stored_domestic_currency_settings(): array {
		return array(
			'no stored value'  => array( array() ),
			'stored usd value' => array( array( 'account_domestic_currency' => 'usd' ) ),
		);
	}

	/**
	 * @testdox Should report card-present eligibility only for eligible accounts with Cash on delivery available, looking up available gateways only for eligible accounts.
	 *
	 * @testWith [true, true, true, 1]
	 *           [true, false, false, 1]
	 *           [false, true, false, 0]
	 *
	 * @param bool $account_eligible Whether the cached account is card-present eligible.
	 * @param bool $cod_available    Whether Cash on delivery is an available gateway.
	 * @param bool $expected         Expected is_card_present_eligible value.
	 * @param int  $expected_lookups Expected available-gateway lookups.
	 */
	public function test_get_settings_derives_card_present_eligibility_from_account_and_cod( bool $account_eligible, bool $cod_available, bool $expected, int $expected_lookups ): void {
		$this->set_connected_account_data();
		$account_data                                  = get_option( 'wcpay_account_data' );
		$account_data['data']['card_present_eligible'] = $account_eligible;
		update_option( 'wcpay_account_data', $account_data );

		$lookups = 0;
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( $gateways ) use ( &$lookups, $cod_available ) {
				++$lookups;
				if ( $cod_available ) {
					$gateways['cod'] = new \WC_Gateway_COD();
				} else {
					unset( $gateways['cod'] );
				}

				return $gateways;
			}
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( $expected, $settings['is_card_present_eligible'] );
		$this->assertSame( $expected_lookups, $lookups, 'The available-gateway lookup must run only for card-present eligible accounts.' );
	}

	/**
	 * @testdox Should persist active gateway settings and refresh the settings response.
	 */
	public function test_update_settings_persists_active_gateway_settings_and_returns_refreshed_contract(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'no',
				'test_mode'                      => 'no',
				'manual_capture'                 => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option( '_wcpay_feature_subscriptions', '1' );
		update_option( '_wcpay_feature_stripe_billing', '1' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'   => 'active',
						'link_payments'   => 'active',
						'affirm_payments' => 'active',
					),
					'fees'         => array(
						'card'   => array(),
						'link'   => array(),
						'affirm' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		$store_setup_sync_count = did_action( 'wcpay_store_setup_sync' );

		$result = $this->sut->update_settings(
			array(
				'is_wcpay_enabled'                       => true,
				'is_manual_capture_enabled'              => true,
				'is_test_mode_enabled'                   => true,
				'is_debug_log_enabled'                   => true,
				'is_saved_cards_enabled'                 => true,
				'is_payment_request_enabled'             => false,
				'is_express_checkout_in_payment_methods_enabled' => true,
				'payment_request_button_size'            => 'medium',
				'payment_request_button_type'            => 'default',
				'payment_request_button_theme'           => 'light',
				'payment_request_button_border_radius'   => 8,
				'is_woopay_enabled'                      => true,
				'is_woopay_global_theme_support_enabled' => true,
				'woopay_custom_message'                  => 'Pay faster with [terms_of_service_link] and [privacy_policy_link].',
				'woopay_store_logo'                      => 'file_new',
				'enabled_payment_method_ids'             => array( 'card', 'link', 'affirm', 'woopay', 'invalid_method' ),
				'express_checkout_product_methods'       => array( 'payment_request', 'link', 'invalid_method' ),
				'express_checkout_cart_methods'          => array( 'amazon_pay' ),
				'express_checkout_checkout_methods'      => array( 'woopay' ),
				'is_multi_currency_enabled'              => true,
				'is_wcpay_subscriptions_enabled'         => false,
				'is_stripe_billing_enabled'              => false,
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['enabled'] );
		$this->assertSame( 'yes', $stored['manual_capture'] );
		$this->assertSame( 'yes', $stored['test_mode'] );
		$this->assertSame( 'yes', $stored['enable_logging'] );
		$this->assertSame( 'yes', $stored['saved_cards'] );
		$this->assertArrayNotHasKey( 'payment_request', $stored );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', $stored['express_checkout_in_payment_methods'] );
		$this->assertSame( 'medium', $stored['payment_request_button_size'] );
		$this->assertSame( 'default', $stored['payment_request_button_type'] );
		$this->assertSame( 'light', $stored['payment_request_button_theme'] );
		$this->assertSame( 8, $stored['payment_request_button_border_radius'] );
		$this->assertSame( 'yes', $stored['platform_checkout'] );
		$this->assertSame( 'yes', $stored['is_woopay_global_theme_support_enabled'] );
		$this->assertSame( 'Pay faster with [terms] and [privacy_policy].', $stored['platform_checkout_custom_message'] );
		$this->assertSame( 'file_new', $stored['platform_checkout_store_logo'] );
		$this->assertSame( array( 'card', 'link' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertSame( array( 'payment_request', 'link' ), $stored['express_checkout_product_methods'] );
		$this->assertSame( array( 'amazon_pay' ), $stored['express_checkout_cart_methods'] );
		$this->assertSame( array( 'woopay' ), $stored['express_checkout_checkout_methods'] );
		$this->assertSame( '1', get_option( '_wcpay_feature_customer_multi_currency' ) );
		$this->assertSame( '0', get_option( '_wcpay_feature_subscriptions' ) );
		$this->assertSame( '0', get_option( '_wcpay_feature_stripe_billing' ), 'Turning Stripe Billing off is saved, as the plugin does.' );
		$this->assertSame( $stored['upe_enabled_payment_method_ids'], $result['enabled_payment_method_ids'] );
		$this->assertSame( $store_setup_sync_count + 1, did_action( 'wcpay_store_setup_sync' ) );
	}

	/**
	 * @testdox Should return an error and skip post-save synchronization when gateway settings do not persist.
	 */
	public function test_update_settings_returns_error_when_gateway_settings_do_not_persist(): void {
		$option_name       = 'woocommerce_woocommerce_payments_settings';
		$original_settings = array(
			'enabled'                        => 'no',
			'upe_enabled_payment_method_ids' => array( 'card' ),
		);
		update_option( $option_name, $original_settings );
		$store_setup_sync_count = did_action( 'wcpay_store_setup_sync' );
		$reject_update          = static fn( $value, $old_value ) => $old_value;
		$logger                 = RecordingWcLogger::install();
		add_filter( 'pre_update_option_' . $option_name, $reject_update, 10, 2 );

		try {
			$result = $this->sut->update_settings(
				array(
					'is_wcpay_enabled'     => true,
					'merchant@example.com' => 'a key an integration sent by mistake',
				)
			);
		} finally {
			remove_filter( 'pre_update_option_' . $option_name, $reject_update, 10 );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_woopayments_settings_persistence_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertCount( 1, $logger->get_errors(), 'The failed local save is logged.' );
		$this->assertSame( array( $option_name ), $logger->contexts[0]['failed_options'] );
		$this->assertStringNotContainsString( 'merchant@example.com', (string) wp_json_encode( $logger->contexts ), 'Request keys stay out of the always-on line.' );
		$this->assertSame( $original_settings, get_option( $option_name ) );
		$this->assertSame( $store_setup_sync_count, did_action( 'wcpay_store_setup_sync' ) );
	}

	/**
	 * @testdox Should record the WooPay last disable date when WooPay is turned off.
	 */
	public function test_update_settings_records_woopay_last_disable_date_when_turning_woopay_off(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout' => 'yes',
			)
		);

		$expected_dates = array( gmdate( 'Y-m-d' ) );

		$result = $this->sut->update_settings(
			array(
				'is_woopay_enabled' => false,
			)
		);

		$stored           = get_option( 'woocommerce_woocommerce_payments_settings' );
		$expected_dates[] = gmdate( 'Y-m-d' );
		$expected_dates   = array_values( array_unique( $expected_dates ) );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'no', $stored['platform_checkout'] );
		$this->assertContains( $stored['platform_checkout_last_disable_date'], $expected_dates );
		$this->assertSame( $stored['platform_checkout_last_disable_date'], $result['woopay_last_disable_date'] );
	}

	/**
	 * @testdox Should record the WooPayments extension's WooPay admin Tracks events when a save changes WooPay settings.
	 *
	 * Expected names and properties: plugin 11.1.0 `WC_Payment_Gateway_WCPay::update_is_woopay_enabled()`,
	 * `update_is_woopay_global_theme_support_enabled()` and `WooPay_Tracker::woopay_locations_updated()`.
	 */
	public function test_update_settings_records_woopay_admin_tracks_events(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'platform_checkout' => 'no' ) );
		$recorded = array();
		$capture  = function ( $properties, $event_name ) use ( &$recorded ) {
			$recorded[ $event_name ] = $properties;
			return $properties;
		};
		add_filter( 'wcpay_tracks_event_properties', $capture, 10, 2 );

		$this->sut->update_settings(
			array(
				'is_woopay_enabled'                      => true,
				'is_woopay_global_theme_support_enabled' => true,
				'express_checkout_product_methods'       => array(),
				'express_checkout_cart_methods'          => array( 'woopay' ),
			)
		);
		remove_filter( 'wcpay_tracks_event_properties', $capture, 10 );

		$this->assertSame( array( 'wcadmin_woopay_enabled', 'wcadmin_woopay_global_theme_support_enabled', 'wcadmin_woopay_express_button_locations_updated' ), array_keys( $recorded ) );
		$this->assertArrayHasKey( 'test_mode', $recorded['wcadmin_woopay_enabled'] );
		$this->assertTrue( $recorded['wcadmin_woopay_express_button_locations_updated']['cart_enabled'] );
		$this->assertFalse( $recorded['wcadmin_woopay_express_button_locations_updated']['product_enabled'] );
	}

	/**
	 * @testdox Should record the WooPayments extension's per-method Tracks events when a save enables one method and disables another.
	 *
	 * Expected names and properties: plugin 11.1.0 `class-wc-rest-payments-settings-controller.php:740-760`.
	 */
	public function test_update_settings_records_payment_method_toggle_tracks_events(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) ) );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'   => 'active',
						'link_payments'   => 'active',
						'affirm_payments' => 'active',
					),
					'fees'         => array(
						'card'   => array(),
						'link'   => array(),
						'affirm' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$recorded = array();
		$capture  = static function ( $preempt, $parsed_args, $url ) use ( &$recorded ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $pixel );
			if ( 0 === strpos( (string) ( $pixel['_en'] ?? '' ), 'wcadmin_wcpay_payment_method_' ) ) {
				$recorded[] = array( $pixel['_en'], $pixel['payment_method_id'], $pixel['capability_id'] );
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		$this->sut->update_settings( array( 'enabled_payment_method_ids' => array( 'card', 'affirm' ) ) );
		remove_filter( 'pre_http_request', $capture, 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		delete_option( 'woocommerce_allow_tracking' );

		$this->assertSame(
			array(
				array( 'wcadmin_wcpay_payment_method_disabled', 'link', 'link_payments' ),
				array( 'wcadmin_wcpay_payment_method_enabled', 'affirm', 'affirm_payments' ),
			),
			$recorded
		);
	}

	/**
	 * @testdox Should keep the WooPay last disable date when WooPay is already off.
	 */
	public function test_update_settings_keeps_woopay_last_disable_date_when_woopay_was_already_off(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout'                   => 'no',
				'platform_checkout_last_disable_date' => '2026-06-01',
			)
		);

		$result = $this->sut->update_settings(
			array(
				'is_woopay_enabled' => false,
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'no', $stored['platform_checkout'] );
		$this->assertSame( '2026-06-01', $stored['platform_checkout_last_disable_date'] );
		$this->assertSame( '2026-06-01', $result['woopay_last_disable_date'] );
	}

	/**
	 * @testdox Should not record the WooPay last disable date when WooPay is omitted from the update.
	 */
	public function test_update_settings_does_not_record_woopay_last_disable_date_when_woopay_is_omitted(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout'                   => 'yes',
				'platform_checkout_last_disable_date' => '2026-06-01',
			)
		);

		$result = $this->sut->update_settings(
			array(
				'is_manual_capture_enabled' => true,
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['platform_checkout'] );
		$this->assertSame( '2026-06-01', $stored['platform_checkout_last_disable_date'] );
		$this->assertSame( '2026-06-01', $result['woopay_last_disable_date'] );
	}

	/**
	 * @testdox Should preserve test mode and debug log settings while WooPayments dev mode is active.
	 */
	public function test_update_settings_preserves_test_mode_and_debug_log_settings_in_dev_mode(): void {
		add_filter( 'wcpay_dev_mode', '__return_true' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'        => 'no',
				'test_mode'      => 'no',
				'enable_logging' => 'no',
			)
		);

		$result = $this->sut->update_settings(
			array(
				'is_wcpay_enabled'     => true,
				'is_test_mode_enabled' => true,
				'is_debug_log_enabled' => true,
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['enabled'] );
		$this->assertSame( 'no', $stored['test_mode'] );
		$this->assertSame( 'no', $stored['enable_logging'] );
		$this->assertTrue( $result['is_dev_mode_enabled'] );
		$this->assertTrue( $result['is_test_mode_enabled'] );
	}

	/**
	 * @testdox Should propagate provider-backed account settings through the API client.
	 */
	public function test_update_settings_persists_provider_backed_account_settings_through_api_client(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'account_statement_descriptor'       => 'OLD STORE',
				'account_business_support_email'     => 'old@example.test',
				'deposit_schedule_interval'          => 'daily',
				'deposit_schedule_weekly_anchor'     => 'monday',
				'deposit_schedule_monthly_anchor'    => 1,
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$result = $this->sut->update_settings(
			array(
				'account_statement_descriptor'       => 'NATIVE STORE',
				'account_statement_descriptor_kanji' => 'ネイティブ',
				'account_statement_descriptor_kana'  => 'ネイティブ',
				'account_business_support_email'     => 'support@example.test',
				'deposit_schedule_interval'          => 'weekly',
				'deposit_schedule_weekly_anchor'     => 'friday',
				'deposit_schedule_monthly_anchor'    => 15,
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => array(
					array(
						'key'     => 'avs_verification',
						'outcome' => 'block',
						'check'   => array(
							'key'      => 'cvc_check',
							'operator' => 'equals',
							'value'    => 'fail',
						),
					),
				),
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'statement_descriptor'            => 'NATIVE STORE',
				'statement_descriptor_kanji'      => 'ネイティブ',
				'statement_descriptor_kana'       => 'ネイティブ',
				'business_support_email'          => 'support@example.test',
				'deposit_schedule_interval'       => 'weekly',
				'deposit_schedule_monthly_anchor' => 15,
				'deposit_schedule_weekly_anchor'  => 'friday',
			),
			$this->api_client->last_account_settings
		);
		$this->assertSame(
			array(
				array(
					'key'     => 'avs_verification',
					'outcome' => 'block',
					'check'   => array(
						'key'      => 'cvc_check',
						'operator' => 'equals',
						'value'    => 'fail',
					),
				),
			),
			$this->api_client->last_fraud_ruleset
		);
		$this->assertIsArray( $stored );
		$this->assertSame( 'NATIVE STORE', $stored['account_statement_descriptor'] );
		$this->assertSame( 'support@example.test', $stored['account_business_support_email'] );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->api_client->last_fraud_ruleset, get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should surface the platform-rejected field as an inline error envelope for inline-capable settings.
	 */
	public function test_update_settings_surfaces_platform_param_for_inline_error_fields(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'account_statement_descriptor' => 'OLD STORE' ) );
		$this->api_client->update_account_exception = new WooPaymentsApiException(
			'Error: Invalid statement descriptor.',
			'invalid_request_error',
			400,
			'',
			'',
			array( 'param' => 'statement_descriptor' )
		);

		$result = $this->sut->update_settings( array( 'account_statement_descriptor' => 'NATIVE STORE' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpay_server_error', $result->get_error_code() );
		$this->assertSame( 'Invalid parameter(s): account_statement_descriptor', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 'Error: Invalid statement descriptor.', $data['params']['account_statement_descriptor'] );
		$this->assertSame( 'invalid_request_error', $data['details']['account_statement_descriptor']['code'] );
		$this->assertSame( 'Error: Invalid statement descriptor.', $data['details']['account_statement_descriptor']['message'] );
	}

	/**
	 * @testdox Should mark a platform rejection without an inline-capable field for the WooPayments extension's server_error shape.
	 * @dataProvider provider_unmapped_platform_rejections
	 *
	 * @param array<string,mixed> $error_data Platform error data carried by the exception.
	 */
	public function test_update_settings_marks_unmapped_platform_rejection_for_server_error_shape( array $error_data ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'account_business_name' => 'Old Name' ) );
		$this->api_client->update_account_exception = new WooPaymentsApiException(
			'Error: The account update was rejected.',
			'invalid_request_error',
			400,
			'',
			'',
			$error_data
		);

		$result = $this->sut->update_settings( array( 'account_business_name' => 'New Name' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_woopayments_account_update_rejected', $result->get_error_code() );
		$this->assertSame( 'Error: The account update was rejected.', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Platform rejections that cannot be attributed to an inline-capable settings field.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function provider_unmapped_platform_rejections(): array {
		return array(
			'param without an inline slot' => array( array( 'param' => 'business_name' ) ),
			'no param at all'              => array( array() ),
		);
	}

	/**
	 * @testdox Should store account fields exactly as sent to the platform.
	 */
	public function test_update_settings_stores_account_fields_exactly_as_sent(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array() );

		$params = array(
			'account_business_name'            => '  Native Store  ',
			'account_statement_descriptor'     => 'NATIVE STORE',
			'account_business_support_phone'   => '+1 555 0123',
			'account_business_url'             => 'example.test/store',
			'account_business_support_email'   => 'support@example.test',
			'account_communications_email'     => 'merchant@example.test',
			'account_branding_primary_color'   => '#123ABC',
			'account_branding_secondary_color' => '#eeeeee',
			'account_business_support_address' => array( 'city' => 'San Francisco' ),
		);

		$result = $this->sut->update_settings( $params );
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		foreach ( $params as $key => $value ) {
			$this->assertSame( $value, $stored[ $key ], "The stored mirror of {$key} must be byte-identical to the request value." );
		}
		$this->assertSame( '  Native Store  ', $this->api_client->last_account_settings['business_name'], 'The wire payload must carry the same raw value as the stored mirror.' );
		$this->assertSame( 'example.test/store', $this->api_client->last_account_settings['business_url'], 'The wire payload must not be mutated by URL sanitization.' );
	}

	/**
	 * @testdox Should sanitize the WooPay custom message with wp_kses_post before storing it.
	 */
	public function test_update_settings_sanitizes_woopay_custom_message_with_kses(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array() );

		$result = $this->sut->update_settings(
			array(
				'woopay_custom_message' => 'Pay <strong>faster</strong><script>alert(1)</script> with [terms_of_service_link].',
			)
		);

		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( '<script>', $stored['platform_checkout_custom_message'], 'Disallowed <script> markup should be stripped.' );
		$this->assertStringContainsString( '<strong>faster</strong>', $stored['platform_checkout_custom_message'], 'Allowed inline markup should be preserved.' );
		$this->assertStringContainsString( '[terms]', $stored['platform_checkout_custom_message'], 'Shortcode swaps should run before kses sanitization.' );
	}

	/**
	 * @testdox Should keep cached account fraud flags in sync after saving advanced fraud settings.
	 */
	public function test_update_settings_patches_cached_advanced_fraud_flags_after_ruleset_save(): void {
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                => 'acct_native_test',
					'is_live'                   => true,
					'capabilities'              => array(
						'card_payments' => 'active',
					),
					'fees'                      => array(
						'card' => array(),
					),
					'fraud_mitigation_settings' => array(
						'avs_check_enabled' => true,
						'cvc_check_enabled' => true,
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		set_transient(
			'wcpay_fraud_protection_settings',
			array(
				array(
					'key'     => 'avs_verification',
					'outcome' => 'block',
					'check'   => array(
						'key'      => 'avs_mismatch',
						'operator' => 'equals',
						'value'    => true,
					),
				),
			),
			DAY_IN_SECONDS
		);

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => array(
					array(
						'key'     => 'address_mismatch',
						'outcome' => 'block',
						'check'   => array(
							'key'      => 'billing_shipping_address_same',
							'operator' => 'equals',
							'value'    => false,
						),
					),
				),
			)
		);

		$cached_account = get_option( 'wcpay_account_data' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $cached_account );
		$this->assertFalse( $cached_account['data']['fraud_mitigation_settings']['avs_check_enabled'] );
		$this->assertTrue( $cached_account['data']['fraud_mitigation_settings']['cvc_check_enabled'] );
		$this->assertFalse( $result['fraud_protection']['decline_on_avs_failure'] );
		$this->assertTrue( $result['fraud_protection']['decline_on_cvc_failure'] );
	}

	/**
	 * @testdox Should clear the cached AVS flag when stepping down from an AVS-bearing advanced ruleset to a built-in level.
	 */
	public function test_update_settings_patches_cached_avs_flag_when_stepping_down_from_advanced(): void {
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                => 'acct_native_test',
					'is_live'                   => true,
					'capabilities'              => array(
						'card_payments' => 'active',
					),
					'fees'                      => array(
						'card' => array(),
					),
					'fraud_mitigation_settings' => array(
						'avs_check_enabled' => true,
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		set_transient(
			'wcpay_fraud_protection_settings',
			array(
				array(
					'key'     => 'avs_verification',
					'outcome' => 'block',
					'check'   => array(
						'key'      => 'avs_mismatch',
						'operator' => 'equals',
						'value'    => true,
					),
				),
			),
			DAY_IN_SECONDS
		);

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$cached_account = get_option( 'wcpay_account_data' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $cached_account );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertFalse( $cached_account['data']['fraud_mitigation_settings']['avs_check_enabled'], 'Stepping down from an AVS-bearing advanced ruleset must overwrite the cached true.' );
	}

	/**
	 * Client 11.1.0 saves the ruleset to the platform before any local write
	 * (includes/admin/class-wc-rest-payments-settings-controller.php:1294-1302).
	 *
	 * @testdox Should keep the local fraud state when the platform rejects the new ruleset.
	 */
	public function test_update_settings_keeps_local_fraud_state_when_the_platform_rejects_the_ruleset(): void {
		// The AVS rule as client 11.1.0's tests store it (tests/unit/test-class-wc-payment-gateway-wcpay.php:4374-4386).
		$ruleset = array(
			array(
				'key'     => 'avs_verification',
				'outcome' => 'block',
				'check'   => array(
					'key'      => 'avs_mismatch',
					'operator' => 'equals',
					'value'    => true,
				),
			),
		);
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'                => 'acct_fraud_test',
					'is_live'                   => true,
					'fraud_mitigation_settings' => array(
						'avs_check_enabled' => true,
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		set_transient( 'wcpay_fraud_protection_settings', $ruleset, DAY_IN_SECONDS );
		$this->api_client->save_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$cached_account = get_option( 'wcpay_account_data' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		$this->assertSame( $ruleset, get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertTrue( $cached_account['data']['fraud_mitigation_settings']['avs_check_enabled'] );
	}

	/**
	 * @testdox Should save canonical fraud presets instead of stale advanced rules.
	 */
	public function test_update_settings_saves_canonical_fraud_presets(): void {
		update_option( 'current_protection_level', 'advanced' );
		set_transient(
			'wcpay_fraud_protection_settings',
			array(
				array(
					'key'     => 'custom_rule',
					'outcome' => 'allow',
					'check'   => array(
						'key'      => 'item_count',
						'operator' => 'greater_than',
						'value'    => 1,
					),
				),
			),
			DAY_IN_SECONDS
		);

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => array(
					array(
						'key'     => 'custom_rule',
						'outcome' => 'allow',
						'check'   => array(
							'key'      => 'item_count',
							'operator' => 'greater_than',
							'value'    => 1,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertCount( 4, $this->api_client->last_fraud_ruleset );
		$this->assertNotContains( 'custom_rule', wp_list_pluck( $this->api_client->last_fraud_ruleset, 'key' ) );
	}

	/**
	 * @testdox Should refresh missing fraud rulesets from the platform.
	 */
	public function test_get_settings_refreshes_missing_fraud_ruleset_from_platform(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'advanced_fraud_protection_settings' => array(
					array(
						'key'     => 'stale_local_rule',
						'outcome' => 'allow',
						'check'   => array(
							'key'      => 'item_count',
							'operator' => 'greater_than',
							'value'    => 1,
						),
					),
				),
			)
		);
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_standard_fraud_ruleset_fixture(),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'standard', $result['current_protection_level'] );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->get_standard_fraud_ruleset_fixture(), $result['advanced_fraud_protection_settings'] );
		$this->assertSame( $this->get_standard_fraud_ruleset_fixture(), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should initialize Basic fraud ruleset when the platform has no ruleset.
	 */
	public function test_get_settings_initializes_basic_fraud_ruleset_when_platform_ruleset_is_missing(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException(
			'Ruleset not found.',
			'wcpay_fraud_ruleset_not_found',
			404
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( array(), $this->api_client->last_fraud_ruleset );
		$this->assertSame( array(), $result['advanced_fraud_protection_settings'] );
		$this->assertSame( array(), get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertSame( 'basic', $result['current_protection_level'] );
		$this->assertSame( 'basic', get_option( 'current_protection_level' ) );
	}

	/**
	 * @testdox Should return fraud settings error when platform refresh fails.
	 */
	public function test_get_settings_returns_fraud_error_when_platform_refresh_fails(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'advanced_fraud_protection_settings' => array(
					array(
						'key'     => 'stale_local_rule',
						'outcome' => 'allow',
						'check'   => array(
							'key'      => 'item_count',
							'operator' => 'greater_than',
							'value'    => 1,
						),
					),
				),
			)
		);
		$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException(
			'Platform unavailable.',
			'wcpay_server_error',
			500
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'error', $result['advanced_fraud_protection_settings'] );
		$this->assertFalse( get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertNull( $this->api_client->last_fraud_ruleset );
	}

	/**
	 * @testdox A failed fraud ruleset $step logs a listed platform code, and any other code as unknown_error.
	 * @testWith ["refresh"]
	 *           ["Basic initialization"]
	 *
	 * @param string $step Platform call that fails.
	 */
	public function test_fraud_ruleset_failure_logs_only_a_listed_code( string $step ): void {
		$this->set_connected_account_data();
		self::enable_woopayments_debug_logging();
		update_option( 'current_protection_level', 'advanced' );
		$free_text_code = new WooPaymentsApiException( 'Error: x', 'https://pay.example.test/r?key=sk_test_leak123', 500 );
		if ( 'refresh' === $step ) {
			$this->api_client->latest_fraud_ruleset_exception = $free_text_code;
		} else {
			$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException( 'Ruleset not found.', 'wcpay_fraud_ruleset_not_found', 404 );
			$this->api_client->save_fraud_ruleset_exception   = $free_text_code;
		}
		$logger = RecordingWcLogger::install();

		$this->sut->get_settings();

		$context = $this->get_logged_context_at_level( $logger, 'warning' );
		$this->assertSame( array( 'unknown_error', 500 ), array( $context['error_code'], $context['http_status'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Should return fraud settings error when platform ruleset response is malformed.
	 */
	public function test_get_settings_returns_fraud_error_when_platform_ruleset_response_is_malformed(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => array(
				array(
					'key'     => 'malformed_rule',
					'outcome' => 'review',
				),
			),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'advanced', $result['current_protection_level'] );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		$this->assertSame( 'error', $result['advanced_fraud_protection_settings'] );
		$this->assertFalse( get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * Client 11.1.0 saves the requested ruleset on every settings save that sends both fraud fields, without reading the
	 * current one first, then caches it, so the response read finds the cache
	 * (includes/admin/class-wc-rest-payments-settings-controller.php:645, :1257-1306).
	 *
	 * @testdox Saving the $level level with $label sends that ruleset to the platform once and never reads the platform ruleset.
	 * @dataProvider provide_fraud_ruleset_saves
	 *
	 * @param string      $label        Case.
	 * @param string|null $cached       Cached ruleset (a level name), or null for none.
	 * @param string|null $platform     Platform ruleset (a level name), or a failure code.
	 * @param string      $level        Requested protection level.
	 * @param string      $submitted    Submitted advanced ruleset: "error", "avs", or "none" for an empty array.
	 * @param string      $saved        Expected saved ruleset: a level name or "avs".
	 */
	public function test_update_settings_saves_the_requested_fraud_ruleset_without_reading_the_platform_first( string $label, ?string $cached, ?string $platform, string $level, string $submitted, string $saved ): void {
		unset( $label );
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'standard' );
		if ( null !== $cached ) {
			set_transient( 'wcpay_fraud_protection_settings', $this->get_fraud_ruleset_case_fixture( $cached ), DAY_IN_SECONDS );
		}
		// The platform's ruleset read answers a ruleset_config envelope, or the wcpay_fraud_ruleset_not_found error when the
		// account has none (the WooPayments extension includes/class-wc-payment-gateway-wcpay.php:3782-3795).
		if ( in_array( $platform, array( 'wcpay_fraud_ruleset_not_found', 'wcpay_server_error' ), true ) ) {
			$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform answer.', $platform, 'wcpay_server_error' === $platform ? 500 : 404 );
		} elseif ( null !== $platform ) {
			$this->api_client->latest_fraud_ruleset_response = array( 'ruleset_config' => $this->get_fraud_ruleset_case_fixture( $platform ) );
		}
		$submitted_ruleset = 'error' === $submitted ? 'error' : $this->get_fraud_ruleset_case_fixture( $submitted );

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => $level,
				'advanced_fraud_protection_settings' => $submitted_ruleset,
			)
		);

		$expected       = $this->get_fraud_ruleset_case_fixture( $saved );
		$cached_account = get_option( 'wcpay_account_data' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $this->api_client->latest_fraud_ruleset_requests, 'Neither the save nor the response read asks the platform for its ruleset.' );
		$this->assertSame( 1, $this->api_client->fraud_ruleset_saves, 'The requested ruleset is saved once, with no Basic ruleset before it.' );
		$this->assertSame( $expected, $this->api_client->last_fraud_ruleset );
		$this->assertSame( $expected, get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertSame( $level, get_option( 'current_protection_level' ) );
		$this->assertSame( 'avs' === $saved, $cached_account['data']['fraud_mitigation_settings']['avs_check_enabled'] );
		$this->assertSame( $level, $result['current_protection_level'] );
	}

	/**
	 * Fraud ruleset saves: cached ruleset, platform ruleset, request and the ruleset the platform must receive.
	 *
	 * @return array<string,array{0:string,1:string|null,2:string|null,3:string,4:string,5:string}>
	 */
	public function provide_fraud_ruleset_saves(): array {
		return array(
			'platform holds another'          => array( 'no cached ruleset and another one on the platform', null, 'standard', 'high', 'none', 'high' ),
			'platform holds the requested'    => array( 'no cached ruleset and the requested one on the platform', null, 'standard', 'standard', 'none', 'standard' ),
			'platform has none'               => array( 'no cached ruleset and none on the platform', null, 'wcpay_fraud_ruleset_not_found', 'standard', 'none', 'standard' ),
			'platform read fails'             => array( 'no cached ruleset and a failing platform read', null, 'wcpay_server_error', 'standard', 'none', 'standard' ),
			'advanced with AVS'               => array( 'no cached ruleset and an advanced ruleset holding the AVS rule', null, 'standard', 'advanced', 'avs', 'avs' ),
			'cache holds the requested'       => array( 'the requested ruleset already cached', 'standard', null, 'standard', 'none', 'standard' ),
			'error sentinel, unchanged level' => array( 'the error sentinel and the stored level', null, 'wcpay_server_error', 'standard', 'error', 'standard' ),
		);
	}

	/**
	 * An advanced level sent with the error sentinel (the ruleset read failed when the page loaded) saves no ruleset;
	 * client 11.1.0 throws there (includes/admin/class-wc-rest-payments-settings-controller.php:1281-1284).
	 *
	 * @testdox Should save the other settings and no fraud ruleset for an advanced level sent with the error sentinel.
	 */
	public function test_update_settings_saves_no_fraud_ruleset_for_advanced_level_with_error_sentinel(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enable_logging' => 'no',
			)
		);
		$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );

		$result = $this->sut->update_settings(
			array(
				'is_debug_log_enabled'               => true,
				'current_protection_level'           => 'advanced',
				'advanced_fraud_protection_settings' => 'error',
			)
		);
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['enable_logging'] );
		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		$this->assertSame( 'error', $result['advanced_fraud_protection_settings'] );
	}

	/**
	 * @testdox The settings route answers 200 and saves the other settings and no fraud ruleset for an advanced level sent with the error sentinel.
	 */
	public function test_settings_route_saves_no_fraud_ruleset_for_advanced_level_with_error_sentinel(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enable_logging' => 'no',
			)
		);
		$this->api_client->latest_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );

		try {
			$response = $this->post_settings(
				array(
					'is_debug_log_enabled'               => true,
					'current_protection_level'           => 'advanced',
					'advanced_fraud_protection_settings' => 'error',
				)
			);

			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
			$this->assertIsArray( $stored );
			$this->assertSame( 'yes', $stored['enable_logging'] );
			$this->assertSame( 0, $this->api_client->fraud_ruleset_saves );
			$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		} finally {
			$this->clear_builtin_active();
		}
	}

	/**
	 * @testdox Should save canonical fraud presets when the fraud error sentinel is round-tripped.
	 */
	public function test_update_settings_saves_canonical_fraud_preset_with_error_sentinel(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );

		$result = $this->sut->update_settings(
			array(
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => 'error',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->get_standard_fraud_ruleset_fixture(), $this->api_client->last_fraud_ruleset );
		$this->assertSame( $this->get_standard_fraud_ruleset_fixture(), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should not fetch fraud rulesets when no WooPayments account is connected.
	 */
	public function test_get_settings_does_not_fetch_fraud_ruleset_without_connected_account(): void {
		update_option( 'current_protection_level', 'advanced' );
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_standard_fraud_ruleset_fixture(),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 0, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'advanced', $result['current_protection_level'] );
		$this->assertSame( array(), $result['advanced_fraud_protection_settings'] );
		$this->assertFalse( get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should refresh fraud rulesets only once per settings response.
	 */
	public function test_get_settings_refreshes_fraud_ruleset_once_per_settings_response(): void {
		$this->set_connected_account_data();
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => array(
				array(
					'key'     => 'custom_rule',
					'outcome' => 'review',
					'check'   => array(
						'key'      => 'item_count',
						'operator' => 'greater_than',
						'value'    => 3,
					),
				),
			),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'advanced', $result['current_protection_level'] );
		$this->assertSame( 'advanced', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->api_client->latest_fraud_ruleset_response['ruleset_config'], $result['advanced_fraud_protection_settings'] );
	}

	/**
	 * @testdox Should match review-enabled standard fraud rulesets fetched from the platform.
	 */
	public function test_get_settings_matches_review_enabled_standard_fraud_ruleset_from_platform(): void {
		$this->set_connected_account_data();
		update_option( 'wcpay_frt_review_feature_active', '1' );
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_standard_fraud_ruleset_fixture( 'review' ),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'standard', $result['current_protection_level'] );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->get_standard_fraud_ruleset_fixture( 'review' ), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should match review-enabled high fraud rulesets fetched from the platform.
	 */
	public function test_get_settings_matches_review_enabled_high_fraud_ruleset_from_platform(): void {
		$this->set_connected_account_data();
		update_option( 'wcpay_frt_review_feature_active', '1' );
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_high_fraud_ruleset_fixture( 'review' ),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'high', $result['current_protection_level'] );
		$this->assertSame( 'high', get_option( 'current_protection_level' ) );
		$this->assertSame( $this->get_high_fraud_ruleset_fixture( 'review' ), get_transient( 'wcpay_fraud_protection_settings' ) );
	}

	/**
	 * @testdox Should use cached fraud transient without fetching platform rulesets.
	 */
	public function test_get_settings_uses_cached_fraud_transient_without_platform_fetch(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'advanced' );
		set_transient(
			'wcpay_fraud_protection_settings',
			array(
				array(
					'key'     => 'cached_rule',
					'outcome' => 'review',
					'check'   => array(
						'key'      => 'item_count',
						'operator' => 'greater_than',
						'value'    => 3,
					),
				),
			),
			DAY_IN_SECONDS
		);
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_standard_fraud_ruleset_fixture(),
		);

		$result = $this->sut->get_settings();

		$this->assertSame( 0, $this->api_client->latest_fraud_ruleset_requests );
		$this->assertSame( 'advanced', $result['current_protection_level'] );
		$this->assertSame( 'cached_rule', $result['advanced_fraud_protection_settings'][0]['key'] );
	}

	/**
	 * @testdox Should include the current payout interval when only a payout anchor changes.
	 */
	public function test_update_settings_includes_payout_interval_for_anchor_updates(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'deposit_schedule_interval'       => 'weekly',
				'deposit_schedule_weekly_anchor'  => 'monday',
				'deposit_schedule_monthly_anchor' => 1,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'deposit_schedule_weekly_anchor' => 'friday',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'deposit_schedule_weekly_anchor' => 'friday',
				'deposit_schedule_interval'      => 'weekly',
			),
			$this->api_client->last_account_settings
		);
	}

	/**
	 * Client 11.1.0 saves the fraud ruleset before the account update, and a failing platform call ends the save
	 * (includes/admin/class-wc-rest-payments-settings-controller.php:633-640, :923-960).
	 *
	 * @testdox Should save the fraud ruleset before the account update when $label.
	 * @dataProvider provide_platform_failures_around_the_fraud_save
	 *
	 * @param string $label          Case.
	 * @param string $failing_call   The platform call that fails: "account" or "fraud".
	 * @param string $account_key    The account setting the request changes.
	 * @param string $stored_value   The account setting's stored value.
	 * @param string $new_value      The account setting's requested value.
	 * @param int    $fraud_saves    Expected fraud ruleset saves.
	 * @param string $level          Expected stored protection level.
	 * @param bool   $account_update Whether the account update reaches the platform.
	 */
	public function test_update_settings_saves_fraud_before_the_account_update( string $label, string $failing_call, string $account_key, string $stored_value, string $new_value, int $fraud_saves, string $level, bool $account_update ): void {
		unset( $label );
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'standard' );
		set_transient( 'wcpay_fraud_protection_settings', $this->get_standard_fraud_ruleset_fixture(), DAY_IN_SECONDS );
		update_option( 'woocommerce_woocommerce_payments_settings', array( $account_key => $stored_value ) );
		update_option( 'wcpay_next_deposit_notice_dismissed', true );
		if ( 'account' === $failing_call ) {
			$this->api_client->update_account_exception = new WooPaymentsApiException( 'Error: The account update was rejected.', 'invalid_request_error', 400 );
		} else {
			$this->api_client->save_fraud_ruleset_exception = new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );
		}

		$result = $this->sut->update_settings(
			array(
				$account_key                         => $new_value,
				'current_protection_level'           => 'high',
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$expected_ruleset = 'high' === $level ? $this->get_high_fraud_ruleset_fixture() : $this->get_standard_fraud_ruleset_fixture();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $fraud_saves, $this->api_client->fraud_ruleset_saves );
		$this->assertSame( $level, get_option( 'current_protection_level' ) );
		$this->assertSame( $expected_ruleset, get_transient( 'wcpay_fraud_protection_settings' ) );
		$this->assertSame( $account_update, null !== $this->api_client->last_account_settings, 'The account update runs only after a successful fraud save.' );
		$this->assertTrue( (bool) get_option( 'wcpay_next_deposit_notice_dismissed' ), 'A failed save keeps the next-deposit notice dismissal.' );
	}

	/**
	 * Platform failures around the fraud save: the failing call, the account change, and what the platform and the store keep.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:int,6:string,7:bool}>
	 */
	public function provide_platform_failures_around_the_fraud_save(): array {
		return array(
			'the account update is rejected' => array( 'the platform rejects the account update', 'account', 'account_business_name', 'Old Name', 'New Name', 1, 'high', true ),
			'the fraud save fails'           => array( 'the platform fraud save fails', 'fraud', 'account_business_name', 'Old Name', 'New Name', 0, 'standard', false ),
			'the fraud save fails with a payout schedule change' => array( 'the platform fraud save fails while the payout schedule changes', 'fraud', 'deposit_schedule_interval', 'daily', 'weekly', 0, 'standard', false ),
		);
	}

	/**
	 * Client 11.1.0 prices the Standard and High purchase-price rule at $1,000 in the store currency, converted at the
	 * Multi-Currency USD rate when USD is an enabled currency, and in USD while its Multi-Currency flag is off
	 * (includes/fraud-prevention/class-fraud-risk-tools.php:167-261, :396-445; automatic rates from the rates cache,
	 * includes/multi-currency/MultiCurrency.php:1707-1731); the platform converts the order total into the rule's currency.
	 * The "USD listed without a rate" row is the decided 100000|usd answer, where client 11.1.0 writes 100000|pln at its 1.0 rate.
	 *
	 * @testdox Should build the preset purchase-price threshold as $expected when $label.
	 * @dataProvider provide_preset_threshold_cases
	 *
	 * @param string      $label             Case.
	 * @param string      $store_currency    Store currency.
	 * @param string|null $flag              The WooPayments Multi-Currency flag, or null for never saved.
	 * @param string[]    $enabled           Multi-Currency enabled currencies.
	 * @param string|null $usd_rate          The USD rate as "manual:<rate>", "stored:<rate>:<store currency the cache was fetched
	 *                                       for>" (rates cache only) or "provider:<rate>" (a running rate provider), or null for none.
	 * @param string      $account_currency  The account's default currency.
	 * @param string|null $filtered_currency A currency a woocommerce_currency filter answers, or null for none.
	 * @param string      $expected          Expected threshold value.
	 */
	public function test_update_settings_builds_the_preset_threshold_in_the_store_currency( string $label, string $store_currency, ?string $flag, array $enabled, ?string $usd_rate, string $account_currency, ?string $filtered_currency, string $expected ): void {
		unset( $label );
		$this->set_connected_account_data();
		$account_data                                        = get_option( 'wcpay_account_data' );
		$account_data['data']['store_currencies']['default'] = $account_currency;
		update_option( 'wcpay_account_data', $account_data );
		update_option( 'woocommerce_currency', $store_currency );
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_customer_multi_currency', $flag );
		}
		if ( null !== $filtered_currency ) {
			add_filter( 'woocommerce_currency', static fn() => $filtered_currency );
		}
		update_option( 'wcpay_multi_currency_enabled_currencies', $enabled );
		list( $rate_source, $rate, $cache_currency ) = array_pad( null === $usd_rate ? array() : explode( ':', $usd_rate ), 3, null );
		if ( 'manual' === $rate_source ) {
			update_option( 'wcpay_multi_currency_exchange_rate_usd', 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_usd', $rate );
		} elseif ( 'stored' === $rate_source ) {
			// The rates cache, fetched long ago (with Multi-Currency off nothing refreshes it), shaped as in client 11.1.0: the rates
			// payload of includes/multi-currency/MultiCurrency.php:500-508 inside the includes/class-database-cache.php:364-382
			// wrapper, with the store currency it was fetched for (MultiCurrency.php:1832-1844).
			update_option( 'wcpay_multi_currency_store_currency', $cache_currency );
			$rates_cache = array(
				'data'               => array(
					'currencies' => array( 'USD' => (float) $rate ),
					'updated'    => time() - WEEK_IN_SECONDS,
				),
				'fetched'            => time() - WEEK_IN_SECONDS,
				'errored'            => false,
				'consecutive_errors' => 0,
			);
			update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $rates_cache, false );
		}
		$registry_factory = wc_get_container()->get( CurrencyRateProviderRegistryFactory::class );
		$registrars       = $registry_factory->get_provider_registrars();
		if ( 'provider' === $rate_source ) {
			$registry_factory->set_provider_registrars( array( $this->create_usd_rate_provider_registrar( (float) $rate ) ) );
		}
		$expected_standard                      = $this->get_standard_fraud_ruleset_fixture();
		$expected_standard[2]['check']['value'] = $expected;

		try {
			$result = $this->sut->update_settings(
				array(
					'current_protection_level'           => 'standard',
					'advanced_fraud_protection_settings' => array(),
				)
			);
			delete_transient( 'wcpay_fraud_protection_settings' );
			// The settings read then fetches the latest ruleset and matches its ruleset_config to a level (the WooPayments
			// extension includes/class-wc-payment-gateway-wcpay.php:3782-3787).
			$this->api_client->latest_fraud_ruleset_response = array( 'ruleset_config' => $expected_standard );

			$this->assertIsArray( $result );
			$this->assertSame( $expected_standard, $this->api_client->last_fraud_ruleset );
			$this->assertSame( 'standard', $this->sut->get_settings()['current_protection_level'], 'A ruleset the extension built for this store reads back as Standard.' );
		} finally {
			$registry_factory->set_provider_registrars( $registrars );
			// Through delete_option(), so the shared Multi-Currency state sees the change.
			delete_option( 'wcpay_multi_currency_enabled_currencies' );
			delete_option( 'wcpay_multi_currency_exchange_rate_usd' );
			delete_option( 'wcpay_multi_currency_manual_rate_usd' );
			delete_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );
			delete_option( 'wcpay_multi_currency_store_currency' );
		}
	}

	/**
	 * Preset threshold cases: the store, its Multi-Currency setup, the account currency and the expected rule value.
	 *
	 * @return array<string,array{0:string,1:string,2:string|null,3:string[],4:string|null,5:string,6:string|null,7:string}>
	 */
	public function provide_preset_threshold_cases(): array {
		return array(
			'Multi-Currency flag off'                => array( 'the WooPayments Multi-Currency flag is off', 'EUR', '0', array(), null, 'pln', null, '100000|usd' ),
			'USD enabled at a manual rate'           => array( 'USD is an enabled currency at a manual rate', 'PLN', null, array( 'PLN', 'USD' ), 'manual:0.23', 'pln', null, '434783|pln' ),
			'USD at an automatic rate, rates stored' => array( 'USD has an automatic rate only in the rates cache (Multi-Currency off)', 'PLN', null, array( 'PLN', 'USD' ), 'stored:0.25:PLN', 'pln', null, '400000|pln' ),
			'rates stored for another currency'      => array( 'the rates cache was fetched for the previous store currency (decided answer)', 'EUR', null, array( 'EUR', 'USD' ), 'stored:0.25:PLN', 'pln', null, '100000|usd' ),
			'USD at an automatic rate, provider'     => array( 'USD has an automatic rate from a running rate provider', 'PLN', null, array( 'PLN', 'USD' ), 'provider:0.23', 'pln', null, '434783|pln' ),
			'USD listed without a rate'              => array( 'USD is enabled but no rate is stored (decided answer)', 'PLN', '1', array( 'PLN', 'USD' ), null, 'pln', null, '100000|usd' ),
			'USD not enabled'                        => array( 'the store currency differs from the account currency', 'EUR', null, array(), null, 'pln', null, '100000|eur' ),
			'store currency is the account currency' => array( 'the store currency is the account currency', 'PLN', null, array(), null, 'pln', null, '100000|pln' ),
			'filtered store currency'                => array( 'a filter answers another currency than the stored one', 'PLN', null, array(), null, 'pln', 'GBP', '100000|pln' ),
			'USD store'                              => array( 'the store sells in USD', 'USD', null, array( 'USD', 'EUR' ), null, 'cad', null, '100000|usd' ),
		);
	}

	/**
	 * A rate provider registrar whose provider is available and answers one USD rate, as a running Multi-Currency has.
	 *
	 * @param float $rate USD rate against the store currency.
	 * @return CurrencyRateProviderRegistrarInterface
	 */
	private function create_usd_rate_provider_registrar( float $rate ): CurrencyRateProviderRegistrarInterface {
		$provider = $this->createMock( CurrencyRateProvider::class );
		$provider->method( 'get_id' )->willReturn( 'test-rates' );
		$provider->method( 'is_available' )->willReturn( true );
		$provider->method( 'get_supported_currencies' )->willReturn( array( 'USD' ) );
		$provider->method( 'get_currency_rates' )->willReturn( array( 'USD' => $rate ) );
		$registrar = $this->createMock( CurrencyRateProviderRegistrarInterface::class );
		$registrar->method( 'register' )->willReturnCallback(
			static function ( CurrencyRateProviderRegistry $registry ) use ( $provider ): void {
				$registry->register( $provider );
			}
		);

		return $registrar;
	}

	/**
	 * @testdox Should clear the next-deposit notice dismissal when the payout schedule changes.
	 */
	public function test_update_settings_clears_next_deposit_notice_dismissal_on_schedule_change(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'deposit_schedule_interval' => 'daily' ) );
		update_option( 'wcpay_next_deposit_notice_dismissed', true );

		$result = $this->sut->update_settings( array( 'deposit_schedule_interval' => 'weekly' ) );

		$this->assertIsArray( $result );
		$this->assertFalse( get_option( 'wcpay_next_deposit_notice_dismissed' ) );
	}

	/**
	 * @testdox Should keep the next-deposit notice dismissal when the save does not touch the payout schedule.
	 */
	public function test_update_settings_keeps_next_deposit_notice_dismissal_without_schedule_change(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'account_business_name' => 'Old Name' ) );
		update_option( 'wcpay_next_deposit_notice_dismissed', true );

		$result = $this->sut->update_settings( array( 'account_business_name' => 'New Name' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) get_option( 'wcpay_next_deposit_notice_dismissed' ) );
	}

	/**
	 * @testdox Should reject enabling payment methods the account has no fees for instead of dropping them silently.
	 */
	public function test_update_settings_rejects_enabled_methods_the_account_has_no_fees_for(): void {
		$this->set_connected_account_data();
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'upe_enabled_payment_method_ids' => array( 'card' ) )
		);

		$result = $this->sut->update_settings(
			array( 'enabled_payment_method_ids' => array( 'card', 'ideal' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'enabled_payment_method_ids', $result->get_error_data()['params'] );
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertSame( array( 'card' ), $stored['upe_enabled_payment_method_ids'], 'A rejected save must not touch the enabled methods.' );
	}

	/**
	 * @testdox Should reject any enabled-methods save while the account's availability cannot be determined.
	 */
	public function test_update_settings_rejects_enabled_methods_save_when_availability_is_unknown(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) )
		);

		$result = $this->sut->update_settings( array( 'enabled_payment_method_ids' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertSame( array( 'card', 'ideal' ), $stored['upe_enabled_payment_method_ids'], 'A settings round-trip during an account-cache outage must not wipe the enabled methods.' );
	}

	/**
	 * @testdox Should reject unavailable enabled methods before any platform mutation lands.
	 */
	public function test_update_settings_rejects_unavailable_methods_before_any_platform_mutation(): void {
		$this->set_connected_account_data();
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
				'account_statement_descriptor'   => 'OLD STORE',
				'deposit_schedule_interval'      => 'daily',
			)
		);
		update_option( 'wcpay_next_deposit_notice_dismissed', true );

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids'   => array( 'card', 'ideal' ),
				'account_statement_descriptor' => 'NEW STORE',
				'deposit_schedule_interval'    => 'weekly',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertNull( $this->api_client->last_account_settings, 'A rejected save must not reach the platform accounts endpoint.' );
		$this->assertTrue( (bool) get_option( 'wcpay_next_deposit_notice_dismissed' ), 'A rejected save must not re-arm the next-deposit notice.' );
	}

	/**
	 * @testdox Should report no available payment methods when the account has no fees.
	 */
	public function test_get_settings_reports_no_available_methods_without_account_fees(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_available_payment_methods'  => array( 'card', 'ideal' ),
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertSame( array(), $settings['available_payment_method_ids'], 'Empty account fees must not fall back to the legacy stored list or the enabled set.' );
		$this->assertSame( array(), $settings['enabled_payment_method_ids'] );
	}

	/**
	 * @testdox Should request unrequested payment method capabilities when enabling methods.
	 */
	public function test_update_settings_requests_unrequested_payment_method_capabilities(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments' => 'active',
						'link_payments' => 'unrequested',
					),
					'fees'         => array(
						'card' => array(),
						'link' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids' => array( 'card', 'link' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array(
					'capability_id' => 'link_payments',
					'requested'     => true,
				),
			),
			$this->api_client->capability_requests
		);
	}

	/**
	 * Client 11.1.0 requests the capabilities of newly enabled methods before the fraud save and the account update, so a
	 * failed request ends the save before either (includes/admin/class-wc-rest-payments-settings-controller.php:615, :736,
	 * :805-827, then :633 and :636).
	 *
	 * @testdox Should make no fraud save and no account update when a capability request fails.
	 */
	public function test_update_settings_requests_capabilities_before_the_fraud_and_account_calls(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
				'account_business_name'          => 'Old Name',
			)
		);
		// The account cache in the client's wrapper (includes/class-database-cache.php:364-382), with the capabilities the
		// client reads for the request (includes/class-wc-payments-account.php:2441-2462; gateway
		// includes/class-wc-payment-gateway-wcpay.php:4696-4707).
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_capability_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments' => 'active',
						'link_payments' => 'unrequested',
					),
					'fees'         => array(
						'card' => array(),
						'link' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		update_option( 'current_protection_level', 'standard' );
		$this->api_client->request_capability_exception = new WooPaymentsApiException( 'Platform unavailable.', 'wcpay_server_error', 500 );

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids'         => array( 'card', 'link' ),
				'account_business_name'              => 'New Name',
				'current_protection_level'           => 'high',
				'advanced_fraud_protection_settings' => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->api_client->capability_requests );
		$this->assertSame( 0, $this->api_client->fraud_ruleset_saves, 'A failed capability request saves no fraud ruleset.' );
		$this->assertSame( 'standard', get_option( 'current_protection_level' ) );
		$this->assertNull( $this->api_client->last_account_settings, 'A failed capability request makes no account update.' );
	}

	/**
	 * @testdox Should request missing payment method capabilities when enabling methods.
	 */
	public function test_update_settings_requests_missing_payment_method_capabilities(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments' => 'active',
					),
					'fees'         => array(
						'card'    => array(),
						'grabpay' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids' => array( 'card', 'grabpay' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array(
					'capability_id' => 'grabpay_payments',
					'requested'     => true,
				),
			),
			$this->api_client->capability_requests
		);
	}

	/**
	 * @testdox Should request missing payment method capabilities before filtering by fee-backed availability.
	 */
	public function test_update_settings_rejects_methods_without_fee_rows_before_capability_requests(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments' => 'active',
					),
					'fees'         => array(
						'card' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids' => array( 'card', 'grabpay' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( array(), $this->api_client->capability_requests, 'A rejected save must not request capabilities as a side effect.' );
	}

	/**
	 * @testdox Should activate visible promotions before enabling newly selected payment methods.
	 */
	public function test_update_settings_activates_visible_promotions_before_enabling_methods(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'   => 'active',
						'klarna_payments' => 'active',
					),
					'fees'         => array(
						'card'   => array(),
						'klarna' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		$this->pm_promotions_service->visible_promotions = array(
			array(
				'id'             => 'klarna-promo__spotlight',
				'promo_id'       => 'klarna-promo',
				'payment_method' => 'klarna',
				'type'           => 'spotlight',
				'title'          => 'Activate Klarna',
				'description'    => 'Offer flexible payments.',
				'cta_label'      => 'Enable Klarna',
				'tc_url'         => 'https://example.com/terms',
				'tc_label'       => 'See terms',
			),
		);

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids' => array( 'card', 'klarna' ),
			)
		);
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'klarna' ), $this->pm_promotions_service->maybe_activated_payment_methods );
		$this->assertSame(
			array( 'card' ),
			$this->pm_promotions_service->enabled_payment_methods_at_activation['klarna'],
			'Promotion activation must run while the PM is still visible, before settings persist it as enabled.'
		);
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'card', 'klarna' ), $stored['upe_enabled_payment_method_ids'] );
	}

	/**
	 * @testdox Should deduplicate payment methods before requesting capabilities.
	 */
	public function test_update_settings_deduplicates_payment_methods_before_requesting_capabilities(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments' => 'active',
						'link_payments' => 'unrequested',
					),
					'fees'         => array(
						'card' => array(),
						'link' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'enabled_payment_method_ids' => array( 'card', 'link', 'link', 'link' ),
			)
		);
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'card', 'link' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertSame(
			array(
				array(
					'capability_id' => 'link_payments',
					'requested'     => true,
				),
			),
			$this->api_client->capability_requests
		);
	}

	/**
	 * @testdox Removing card through the settings route disables the WooPayments gateway (the WooPayments extension `class-wc-rest-payments-settings-controller.php:781-790`).
	 */
	public function test_settings_route_disables_the_gateway_when_card_is_removed(): void {
		$this->store_card_and_ideal_enabled();

		try {
			$response = $this->post_settings( array( 'enabled_payment_method_ids' => array( 'ideal' ) ) );

			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
			$this->assertSame( array( 'ideal' ), $stored['upe_enabled_payment_method_ids'] );
			$this->assertSame( 'no', $stored['enabled'] );
		} finally {
			$this->clear_builtin_active();
		}
	}

	/**
	 * @testdox Removing card disables the WooPayments gateway even while card is filtered out of the available methods (the WooPayments extension `class-wc-rest-payments-settings-controller.php:738-740,782-789` compares with the stored list).
	 */
	public function test_settings_route_disables_the_gateway_when_an_unavailable_card_is_removed(): void {
		$this->store_card_and_ideal_enabled();
		$without_card = static fn( array $payment_method_ids ): array => array_values( array_diff( $payment_method_ids, array( 'card' ) ) );
		add_filter( 'wcpay_upe_available_payment_methods', $without_card );

		try {
			$response = $this->post_settings( array( 'enabled_payment_method_ids' => array( 'ideal' ) ) );

			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
			$this->assertSame( array( 'ideal' ), $stored['upe_enabled_payment_method_ids'] );
			$this->assertSame( 'no', $stored['enabled'] );
		} finally {
			remove_filter( 'wcpay_upe_available_payment_methods', $without_card );
			$this->clear_builtin_active();
		}
	}

	/**
	 * @testdox Card is no longer offered at checkout once the settings route removes it (the WooPayments extension `class-wc-payment-gateway-wcpay.php:897-906`).
	 */
	public function test_card_is_not_offered_after_the_settings_route_removes_it(): void {
		$this->store_card_and_ideal_enabled();

		try {
			$this->assertTrue( $this->create_card_gateway()->is_available(), 'Card is offered before the save.' );

			$response = $this->post_settings( array( 'enabled_payment_method_ids' => array( 'ideal' ) ) );

			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$this->assertFalse( $this->create_card_gateway()->is_available() );
		} finally {
			$this->clear_builtin_active();
		}
	}

	/**
	 * @testdox Adding card back through the settings route enables the WooPayments gateway (the WooPayments extension `class-wc-rest-payments-settings-controller.php:766-780`).
	 */
	public function test_settings_route_enables_the_gateway_when_card_is_added(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'no',
				'test_mode'                      => 'yes',
				'upe_enabled_payment_method_ids' => array( 'ideal' ),
			)
		);
		$this->set_card_and_ideal_account_data();
		$this->make_builtin_active();

		try {
			$response = $this->post_settings( array( 'enabled_payment_method_ids' => array( 'card', 'ideal' ) ) );

			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$stored = get_option( 'woocommerce_woocommerce_payments_settings' );
			$this->assertSame( array( 'card', 'ideal' ), $stored['upe_enabled_payment_method_ids'] );
			$this->assertSame( 'yes', $stored['enabled'] );
		} finally {
			$this->clear_builtin_active();
		}
	}

	/**
	 * @testdox Should keep split gateway availability aligned with canonical enabled methods.
	 */
	public function test_update_settings_projects_enabled_methods_to_split_gateways(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'upe_enabled_payment_method_ids' => array( 'card' ) )
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'  => 'active',
						'ideal_payments' => 'active',
					),
					'fees'         => array(
						'card'  => array(),
						'ideal' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$enable_result = $this->sut->update_settings(
			array( 'enabled_payment_method_ids' => array( 'card', 'ideal' ) )
		);

		$this->assertIsArray( $enable_result );
		$this->assertSame( array( 'card', 'ideal' ), $enable_result['enabled_payment_method_ids'] );
		$this->assertSame(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			),
			get_option( 'woocommerce_woocommerce_payments_ideal_settings' )
		);

		$disable_result = $this->sut->update_settings(
			array( 'enabled_payment_method_ids' => array( 'card' ) )
		);

		$this->assertIsArray( $disable_result );
		$this->assertSame(
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			),
			get_option( 'woocommerce_woocommerce_payments_ideal_settings' )
		);
	}

	/**
	 * @testdox A merchant can turn on Apple Pay, Google Pay and Amazon Pay, and the saved settings read back with nothing held back.
	 */
	public function test_update_settings_round_trips_apple_pay_google_pay_and_amazon_pay(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array( 'upe_enabled_payment_method_ids' => array( 'card' ) )
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'       => 'active',
						'amazon_pay_payments' => 'active',
					),
					'fees'         => array(
						'card'       => array(),
						'amazon_pay' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$saved = $this->sut->update_settings(
			array(
				'is_payment_request_enabled'        => true,
				'enabled_payment_method_ids'        => array( 'card', 'amazon_pay' ),
				'express_checkout_checkout_methods' => array( 'payment_request', 'amazon_pay' ),
			)
		);
		$this->assertIsArray( $saved );
		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['is_payment_request_enabled'], 'Apple Pay and Google Pay must save as enabled.' );
		$this->assertSame( array( 'card', 'amazon_pay' ), $settings['enabled_payment_method_ids'] );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $settings['express_checkout_checkout_methods'] );
		foreach ( array( 'apple_pay', 'google_pay', 'amazon_pay' ) as $payment_method_id ) {
			$this->assertContains( $payment_method_id, $settings['available_payment_method_ids'], "{$payment_method_id} must be offered to the merchant." );
		}
		$this->assertArrayNotHasKey( 'natively_chargeable_payment_method_ids', $settings, 'The settings page must not receive a list that holds wallets back.' );
	}

	/**
	 * @testdox Should not write account settings when posted account fields match the cached account.
	 */
	public function test_update_settings_diffs_account_fields_against_cached_account_data(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'saved_cards'                  => 'no',
				'account_statement_descriptor' => 'STALE LOCAL',
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'           => 'acct_native_test',
					'is_live'              => true,
					'statement_descriptor' => 'NATIVE STORE',
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$result = $this->sut->update_settings(
			array(
				'is_saved_cards_enabled'       => true,
				'account_statement_descriptor' => 'NATIVE STORE',
			)
		);
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['saved_cards'] );
		$this->assertNull( $this->api_client->last_account_settings );
	}

	/**
	 * @testdox Should reject disallowed option updates and validate allowed option value types.
	 */
	public function test_update_option_rejects_disallowed_options_and_invalid_value_types(): void {
		$invalid_name_result = $this->sut->update_option( 'not_allowed', true );
		$invalid_type_result = $this->sut->update_option( 'wcpay_fraud_protection_welcome_tour_dismissed', 'yes' );
		$valid_bool_result   = $this->sut->update_option( 'wcpay_fraud_protection_welcome_tour_dismissed', true );
		$valid_array_result  = $this->sut->update_option( 'wcpay_duplicate_payment_method_notices_dismissed', array( 'card' ) );

		$this->assertInstanceOf( WP_Error::class, $invalid_name_result );
		$this->assertSame( 400, $invalid_name_result->get_error_data()['status'] );
		$this->assertInstanceOf( WP_Error::class, $invalid_type_result );
		$this->assertSame( 400, $invalid_type_result->get_error_data()['status'] );
		$this->assertTrue( $valid_bool_result );
		$this->assertTrue( $valid_array_result );
		$this->assertTrue( get_option( 'wcpay_fraud_protection_welcome_tour_dismissed' ) );
		$this->assertSame( array( 'card' ), get_option( 'wcpay_duplicate_payment_method_notices_dismissed' ) );
	}

	/**
	 * Create a native account service for a connected store whose account fetches fail, so reads serve the seeded cache.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		$connected_api_client->method( 'get_account' )->willThrowException( new WooPaymentsApiException( 'Unavailable.', 'wcpay_test_unavailable', 500 ) );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );

		$account_service = new WooPaymentsAccountService();
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );

		return $account_service;
	}

	/**
	 * Create the fraud ruleset service the settings service reads, over the recording API client.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsFraudRulesetService
	 */
	private function create_fraud_ruleset_service( WooPaymentsAccountService $account_service ): WooPaymentsFraudRulesetService {
		$fraud_ruleset_service = new WooPaymentsFraudRulesetService();
		$fraud_ruleset_service->init( $account_service, $this->api_client, wc_get_container()->get( WooPaymentsLogger::class ) );

		return $fraud_ruleset_service;
	}

	/**
	 * Create the duplicate gateway methods service the settings service reads.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsDuplicateGatewayMethodsService
	 */
	private function create_duplicate_gateway_methods_service( WooPaymentsAccountService $account_service ): WooPaymentsDuplicateGatewayMethodsService {
		$duplicate_gateway_methods_service = new WooPaymentsDuplicateGatewayMethodsService();
		$duplicate_gateway_methods_service->init( $account_service, wc_get_container()->get( WooPaymentsLogger::class ) );

		return $duplicate_gateway_methods_service;
	}

	/**
	 * Mock WooCommerce payment gateways.
	 *
	 * @param array<int,object> $gateways Gateways.
	 */
	private function mock_payment_gateways( array $gateways ): void {
		add_action(
			'wc_payment_gateways_initialized',
			static function ( \WC_Payment_Gateways $wc_payment_gateways ) use ( $gateways ): void {
				$wc_payment_gateways->payment_gateways = array();
				$order                                 = 1000;
				foreach ( $gateways as $gateway ) {
					$wc_payment_gateways->payment_gateways[ $order++ ] = $gateway;
				}
			},
			100
		);

		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Create a lightweight gateway fixture.
	 *
	 * @param string               $id      Gateway ID.
	 * @param string               $enabled Gateway enabled state.
	 * @param array<string,string> $options Gateway options.
	 * @return object
	 */
	private function create_gateway( string $id, string $enabled, array $options = array() ): object {
		return new class( $id, $enabled, $options ) {
			/**
			 * Gateway ID.
			 *
			 * @var string
			 */
			public string $id;

			/**
			 * Gateway enabled state.
			 *
			 * @var string
			 */
			public string $enabled;

			/**
			 * Gateway options.
			 *
			 * @var array<string,string>
			 */
			private array $options;

			/**
			 * Initialize gateway fixture.
			 *
			 * @param string               $id      Gateway ID.
			 * @param string               $enabled Gateway enabled state.
			 * @param array<string,string> $options Gateway options.
			 */
			public function __construct(
				string $id,
				string $enabled,
				array $options
			) {
				$this->id      = $id;
				$this->enabled = $enabled;
				$this->options = $options;
			}

			/**
			 * Get a gateway option.
			 *
			 * @param string $key Option key.
			 * @return string
			 */
			public function get_option( string $key ): string {
				return $this->options[ $key ] ?? 'no';
			}
		};
	}

	/**
	 * Set connected account data for settings tests.
	 */
	private function set_connected_account_data(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'           => 'acct_native_test',
					'is_live'              => true,
					'test_publishable_key' => 'pk_test_native',
					'live_publishable_key' => 'pk_live_native',
					'capabilities'         => array(
						'card_payments' => 'active',
					),
					'fees'                 => array(
						'card' => array(),
					),
					'store_currencies'     => array(
						'default'   => 'usd',
						'supported' => array( 'usd' ),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
	}

	/**
	 * Store a connected account with active card and Amazon Pay capabilities and fees for both.
	 */
	private function store_card_and_amazon_pay_account_data(): void {
		// The option envelope client 11.1.0 Database_Cache writes (class-database-cache.php:377-382; an entry without
		// consecutive_errors reads as zero, :367), around account fields as recorded in Fixtures/platform-test-drive-account.json
		// `account`: capability statuses keyed by capability and fees keyed by payment method ID.
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'   => 'acct_native_test',
					'is_live'      => true,
					'capabilities' => array(
						'card_payments'       => 'active',
						'amazon_pay_payments' => 'active',
					),
					'fees'         => array(
						'card'       => array(),
						'amazon_pay' => array(),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
	}

	/**
	 * Store a connected account with active card and iDEAL capabilities and fees for both.
	 */
	private function set_card_and_ideal_account_data(): void {
		// The option envelope client 11.1.0 Database_Cache writes (class-database-cache.php:377-382; an entry without
		// consecutive_errors reads as zero, :367), around account fields as recorded in Fixtures/platform-test-drive-account.json
		// `account`: capability statuses keyed by capability and fees keyed by payment method ID.
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'       => 'acct_native_test',
					'is_live'          => true,
					'country'          => 'US',
					'capabilities'     => array(
						'card_payments'  => 'active',
						'ideal_payments' => 'active',
					),
					'fees'             => array(
						'card'  => array(),
						'ideal' => array(),
					),
					'store_currencies' => array(
						'default'   => 'usd',
						'supported' => array( 'usd', 'eur' ),
					),
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
	}

	/**
	 * Store an enabled gateway with card and iDEAL on, for an account that has both, with the built-in WooPayments active.
	 */
	private function store_card_and_ideal_enabled(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'yes',
				'test_mode'                      => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			)
		);
		$this->set_card_and_ideal_account_data();
		$this->make_builtin_active();
	}

	/**
	 * Make the built-in WooPayments the payments owner in the active tier, where the gateway is offered at checkout.
	 */
	private function make_builtin_active(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE, true );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Undo make_builtin_active() and the REST server the settings route was served from.
	 */
	private function clear_builtin_active(): void {
		$this->clear_rest_server();
		remove_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Save settings through the WooPayments settings REST route, served by this test's settings service.
	 *
	 * @param array<string,mixed> $params Request body.
	 * @return \WP_REST_Response
	 */
	private function post_settings( array $params ): \WP_REST_Response {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );
		$controller = new WooPaymentsMerchantRestController();
		$controller->init(
			$this->sut,
			$arbiter,
			$this->createMock( WooPaymentsPmPromotionsService::class ),
			$this->createMock( WooPaymentsOverviewService::class ),
			$this->createMock( WooPaymentsAccountService::class ),
			$this->createMock( WooPaymentsAdminNoticeService::class ),
			$this->createMock( WooPaymentsApplePayDomainService::class ),
			$this->createMock( WooPaymentsApiClient::class )
		);
		$server = $this->create_rest_server_with_routes(
			array(
				static function () use ( $controller ): void {
					$controller->register_routes( true );
				},
			),
			true
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/settings' );
		$request->set_body_params( $params );

		return $server->dispatch( $request );
	}

	/**
	 * Build the card gateway over a processing-ready provider and an account with an active card capability.
	 *
	 * @return WooPaymentsGateway
	 */
	private function create_card_gateway(): WooPaymentsGateway {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$gateway = new WooPaymentsGateway( ( new WooPaymentsPaymentMethodRegistry() )->get( 'card' ) );
		$gateway->init( new RecordingPaymentProcessingService(), $provider, null, null, $this->create_account_service() );

		return $gateway;
	}

	/**
	 * Get the standard fraud ruleset fixture.
	 *
	 * @param string $reviewable_outcome Outcome for review-capable standard preset rules.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_standard_fraud_ruleset_fixture( string $reviewable_outcome = 'block' ): array {
		return array(
			$this->get_fraud_rule_fixture(
				'international_ip_address',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'ip_country', 'in', '' )
			),
			$this->get_fraud_rule_fixture(
				'order_items_threshold',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'item_count', 'greater_than', 10 )
			),
			$this->get_fraud_rule_fixture(
				'purchase_price_threshold',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'order_total', 'greater_than', '100000|usd' )
			),
			$this->get_fraud_rule_fixture(
				'ip_address_mismatch',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'ip_billing_country_same', 'equals', false )
			),
		);
	}

	/**
	 * Get the high fraud ruleset fixture.
	 *
	 * @param string $reviewable_outcome Outcome for review-capable high preset rules.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_high_fraud_ruleset_fixture( string $reviewable_outcome = 'block' ): array {
		return array(
			$this->get_fraud_rule_fixture(
				'international_ip_address',
				'block',
				$this->get_fraud_check_fixture( 'ip_country', 'in', '' )
			),
			$this->get_fraud_rule_fixture(
				'purchase_price_threshold',
				'block',
				$this->get_fraud_check_fixture( 'order_total', 'greater_than', '100000|usd' )
			),
			$this->get_fraud_rule_fixture(
				'order_items_threshold',
				$reviewable_outcome,
				array(
					'operator' => 'or',
					'checks'   => array(
						$this->get_fraud_check_fixture( 'item_count', 'less_than', 2 ),
						$this->get_fraud_check_fixture( 'item_count', 'greater_than', 10 ),
					),
				)
			),
			$this->get_fraud_rule_fixture(
				'address_mismatch',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'billing_shipping_address_same', 'equals', false )
			),
			$this->get_fraud_rule_fixture(
				'ip_address_mismatch',
				$reviewable_outcome,
				$this->get_fraud_check_fixture( 'ip_billing_country_same', 'equals', false )
			),
		);
	}

	/**
	 * Get a fraud ruleset fixture by name: standard, high, "avs" for an advanced ruleset holding the AVS rule as
	 * client 11.1.0's tests store it (tests/unit/test-class-wc-payment-gateway-wcpay.php:4374-4386), or an empty array.
	 *
	 * @param string $name Ruleset name.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_fraud_ruleset_case_fixture( string $name ): array {
		switch ( $name ) {
			case 'standard':
				return $this->get_standard_fraud_ruleset_fixture();
			case 'high':
				return $this->get_high_fraud_ruleset_fixture();
			case 'avs':
				return array( $this->get_fraud_rule_fixture( 'avs_verification', 'block', $this->get_fraud_check_fixture( 'avs_mismatch', 'equals', true ) ) );
		}

		return array();
	}

	/**
	 * Get a fraud rule fixture.
	 *
	 * @param string              $key     Rule key.
	 * @param string              $outcome Rule outcome.
	 * @param array<string,mixed> $check   Rule check.
	 * @return array<string,mixed>
	 */
	private function get_fraud_rule_fixture( string $key, string $outcome, array $check ): array {
		return array(
			'key'     => $key,
			'outcome' => $outcome,
			'check'   => $check,
		);
	}

	/**
	 * Get a fraud check fixture.
	 *
	 * @param string $key      Check key.
	 * @param string $operator Check operator.
	 * @param mixed  $value    Check value.
	 * @return array<string,mixed>
	 */
	private function get_fraud_check_fixture( string $key, string $operator, $value ): array {
		return array(
			'key'      => $key,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Get WooCommerce settings options mutated by focused contract tests.
	 *
	 * @return string[]
	 */
	private function get_mutated_woocommerce_options(): array {
		return array(
			'woocommerce_currency',
			'woocommerce_allowed_countries',
			'woocommerce_specific_allowed_countries',
			'woocommerce_all_except_countries',
		);
	}

	/**
	 * Get the settings keys required by the hoisted settings store.
	 *
	 * @return string[]
	 */
	private function get_required_settings_keys(): array {
		return array(
			'enabled_payment_method_ids',
			'available_payment_method_ids',
			'payment_method_statuses',
			'duplicated_payment_method_ids',
			'dismissed_duplicate_payment_method_notices',
			'account_fees',
			'pm_promotions',
			'is_wcpay_enabled',
			'is_manual_capture_enabled',
			'is_test_mode_enabled',
			'is_test_mode_onboarding',
			'is_dev_mode_enabled',
			'is_multi_currency_enabled',
			'is_wcpay_subscriptions_enabled',
			'is_wcpay_subscriptions_eligible',
			'is_subscriptions_plugin_active',
			'is_stripe_billing_enabled',
			'is_migrating_stripe_billing',
			'stripe_billing_subscription_count',
			'stripe_billing_migrated_count',
			'account_country',
			'account_statement_descriptor',
			'account_statement_descriptor_kanji',
			'account_statement_descriptor_kana',
			'account_business_name',
			'account_business_url',
			'account_business_support_address',
			'account_business_support_email',
			'account_business_support_phone',
			'account_branding_logo',
			'account_branding_icon',
			'account_branding_primary_color',
			'account_branding_secondary_color',
			'account_domestic_currency',
			'account_communications_email',
			'is_payment_request_enabled',
			'is_express_checkout_in_payment_methods_enabled',
			'is_debug_log_enabled',
			'payment_request_button_size',
			'payment_request_button_type',
			'payment_request_button_theme',
			'payment_request_button_border_radius',
			'is_saved_cards_enabled',
			'is_card_present_eligible',
			'is_woopay_enabled',
			'is_woopay_global_theme_support_enabled',
			'is_woopay_global_theme_support_eligible',
			'show_woopay_incompatibility_notice',
			'woopay_custom_message',
			'woopay_store_logo',
			'woopay_appearance',
			'woopay_font_rules',
			'store_name',
			'store_currency',
			'site_logo_url',
			'deposit_schedule_interval',
			'deposit_schedule_monthly_anchor',
			'deposit_schedule_weekly_anchor',
			'deposit_delay_days',
			'deposit_status',
			'deposit_restrictions',
			'deposit_completed_waiting_period',
			'current_protection_level',
			'advanced_fraud_protection_settings',
			'fraud_protection',
			'fraud_protection_allowed_countries',
			'is_fraud_protection_review_feature_active',
			'feature_flags',
			'express_checkout_product_methods',
			'express_checkout_cart_methods',
			'express_checkout_checkout_methods',
			'express_checkout_preview',
		);
	}

	/**
	 * Run a callback in wp-admin for a user whose locale is French while the site stays English, so determine_locale()
	 * gives fr_FR and get_locale() gives en_US.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's result.
	 */
	private function in_admin_with_french_user_locale( callable $callback ) {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'   => 'administrator',
					'locale' => 'fr_FR',
				)
			)
		);
		set_current_screen( 'dashboard' );

		try {
			return $callback();
		} finally {
			set_current_screen( 'front' );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Create the WooPay eligibility rules over an account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsWooPayEligibility
	 */
	private function create_woopay_eligibility( WooPaymentsAccountService $account_service ): WooPaymentsWooPayEligibility {
		$woopay_eligibility = new WooPaymentsWooPayEligibility();
		$woopay_eligibility->init( $account_service );

		return $woopay_eligibility;
	}
}
