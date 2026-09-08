<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLocaleUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsSettingsService class.
 */
class WooPaymentsSettingsServiceTest extends WC_Unit_Test_Case {

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
		$this->sut                    = new WooPaymentsSettingsService();
		$this->sut->init( $this->create_account_service(), $this->api_client, $this->pm_promotions_service, $this->woopay_session_service );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
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
		remove_all_filters( 'woocommerce_woopayments_gateway_duplicate_payment_method_ids' );
		remove_all_actions( 'wc_payment_gateways_initialized' );
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should return the native WooPayments settings contract without Stripe Billing fields.
	 */
	public function test_get_settings_returns_reference_shaped_contract_without_stripe_billing_fields(): void {
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
				'is_stripe_billing_enabled'              => true,
			)
		);
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
		$this->assertSame(
			array(
				'card',
				'link',
				'sepa_debit',
				'ideal',
				'bancontact',
				'klarna',
				'affirm',
				'afterpay_clearpay',
				'eps',
				'p24',
				'multibanco',
				'au_becs_debit',
				'grabpay',
				'wechat_pay',
				'alipay',
			),
			$settings['natively_chargeable_payment_method_ids']
		);
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
		$this->assertTrue( $settings['is_express_checkout_in_payment_methods_list_supported'] );
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
					'locale'         => WooPaymentsLocaleUtils::convert_to_stripe_locale( determine_locale() ),
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
		$this->assertArrayNotHasKey( 'is_stripe_billing_enabled', $settings );
		$this->assertArrayNotHasKey( 'is_migrating_stripe_billing', $settings );
		$this->assertArrayNotHasKey( 'stripe_billing_subscription_count', $settings );
		$this->assertArrayNotHasKey( 'stripe_billing_migrated_count', $settings );
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
		$account_service->init( new LegacyProxy() );
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
			$this->woopay_session_service,
			null,
			new WooPaymentsPaymentMethodRegistry()
		);

		$settings = $service->get_settings();

		$this->assertSame( 'availability_filter', $events[0] );
		$this->assertSame( 'account_fees', $events[1] );
		$this->assertSame( 1, count( array_keys( $events, 'availability_filter', true ) ) );
		$this->assertSame( array( 'card', 'apple_pay', 'google_pay' ), $settings['available_payment_method_ids'] );
	}

	/**
	 * @testdox Should default the dynamic place-order feature flag off like the reference client
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
	 * @testdox Should expose WooPay eligibility separately from the saved WooPay setting.
	 */
	public function test_get_settings_exposes_woopay_feature_eligibility_separately_from_saved_setting(): void {
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

		$this->assertTrue( $settings['is_woopay_enabled'], 'The saved WooPay setting should remain visible even when the account is not eligible.' );
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
	 * @testdox Should expose native fraud-protection environment fields consumed by Core-owned settings surfaces.
	 */
	public function test_get_settings_exposes_native_fraud_protection_environment_fields(): void {
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
	 * @testdox Should expose Apple Pay and Google Pay duplicate clusters when native payment request is enabled.
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
			),
			$settings['duplicated_payment_method_ids']['apple_pay_google_pay'],
			'Native payment request support should cluster with enabled Apple Pay, Google Pay, or Stripe payment-request gateways.'
		);
	}

	/**
	 * @testdox Should expose Apple Pay and Google Pay duplicate clusters from explicit gateway declarations.
	 */
	public function test_get_settings_exposes_apple_pay_google_pay_duplicate_cluster_from_gateway_declarations(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'payment_request' => 'yes',
			)
		);
		add_filter(
			'woocommerce_woopayments_gateway_duplicate_payment_method_ids',
			static function ( array $payment_method_ids, string $gateway_id ): array {
				if ( 'declared_wallet_gateway' !== $gateway_id ) {
					return $payment_method_ids;
				}

				return array_merge(
					$payment_method_ids,
					array(
						'apple_pay_google_pay',
						'invalid_payment_method',
					)
				);
			},
			10,
			2
		);
		$this->mock_payment_gateways(
			array(
				$this->create_gateway( 'woocommerce_payments', 'yes' ),
				$this->create_gateway( 'declared_wallet_gateway', 'yes' ),
			)
		);

		$settings = $this->sut->get_settings();

		$this->assertEqualsCanonicalizing(
			array(
				'woocommerce_payments',
				'declared_wallet_gateway',
			),
			$settings['duplicated_payment_method_ids']['apple_pay_google_pay'],
			'Gateway declarations should expose only supported duplicate payment method IDs.'
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
	 * @testdox Should omit Apple Pay and Google Pay duplicate clusters when native payment request is disabled.
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
		$this->assertSame( '1', get_option( '_wcpay_feature_stripe_billing' ), 'Native settings must not mutate the Stripe Billing flag.' );
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
		add_filter( 'pre_update_option_' . $option_name, $reject_update, 10, 2 );

		try {
			$result = $this->sut->update_settings( array( 'is_wcpay_enabled' => true ) );
		} finally {
			remove_filter( 'pre_update_option_' . $option_name, $reject_update, 10 );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_woopayments_settings_persistence_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
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
	 * @testdox Should propagate provider-backed account settings through the native API client.
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
	 * @testdox Should mark a platform rejection without an inline-capable field for the legacy server_error shape.
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
	 * @testdox Should answer fraud-rule activity from the cached ruleset and refresh a cold cache first.
	 */
	public function test_is_fraud_rule_active_reads_cached_ruleset_and_refreshes_cold_cache(): void {
		$this->set_connected_account_data();
		set_transient(
			'wcpay_fraud_protection_settings',
			array(
				array( 'key' => 'avs_verification' ),
			),
			DAY_IN_SECONDS
		);

		$this->assertTrue( $this->sut->is_fraud_rule_active( 'avs_verification' ) );
		$this->assertFalse( $this->sut->is_fraud_rule_active( 'purchase_price_threshold' ) );
		$this->assertSame( 0, $this->api_client->latest_fraud_ruleset_requests, 'A warm cache must not trigger a platform refresh.' );

		delete_transient( 'wcpay_fraud_protection_settings' );
		$this->api_client->latest_fraud_ruleset_response = array(
			'ruleset_config' => $this->get_standard_fraud_ruleset_fixture(),
		);

		$standard_has_avs = false;
		foreach ( $this->get_standard_fraud_ruleset_fixture() as $rule ) {
			if ( 'avs_verification' === ( $rule['key'] ?? null ) ) {
				$standard_has_avs = true;
			}
		}

		$this->assertSame( $standard_has_avs, $this->sut->is_fraud_rule_active( 'avs_verification' ), 'A cold cache must be refreshed from the platform before answering, so an AVS block is not misread as an ordinary decline.' );
		$this->assertSame( 1, $this->api_client->latest_fraud_ruleset_requests );
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
	 * @testdox Should ignore the fraud error sentinel when saving unrelated settings.
	 */
	public function test_update_settings_ignores_fraud_error_sentinel_on_unrelated_save(): void {
		$this->set_connected_account_data();
		update_option( 'current_protection_level', 'standard' );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enable_logging' => 'no',
			)
		);

		$result = $this->sut->update_settings(
			array(
				'is_debug_log_enabled'               => true,
				'current_protection_level'           => 'standard',
				'advanced_fraud_protection_settings' => 'error',
			)
		);
		$stored = get_option( 'woocommerce_woocommerce_payments_settings' );

		$this->assertIsArray( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['enable_logging'] );
		$this->assertNull( $this->api_client->last_fraud_ruleset );
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
	 * @testdox Should report no available payment methods when the account has no fees, like the plugin.
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
	 * @testdox Should delegate file upload to the native API client.
	 */
	public function test_upload_file_delegates_to_native_api_client(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/file' );
		$request->set_param( 'purpose', 'business_logo' );

		$result = $this->sut->upload_file( $request );

		$this->assertSame( array( 'id' => 'file_test_logo' ), $result );
		$this->assertSame( $request, $this->api_client->last_upload_request );
	}

	/**
	 * @testdox Should delegate file details and contents to the native API client.
	 */
	public function test_get_file_details_and_contents_delegate_to_native_api_client(): void {
		$file     = $this->sut->get_file( 'file_test_logo', false );
		$contents = $this->sut->get_file_contents( 'file_test_logo', false );

		$this->assertSame( $this->api_client->file_response, $file );
		$this->assertSame( 'file_test_logo', $this->api_client->last_file_id );
		$this->assertFalse( $this->api_client->last_file_as_account );
		$this->assertSame( $this->api_client->file_contents_response, $contents );
		$this->assertSame( 'file_test_logo', $this->api_client->last_file_contents_id );
		$this->assertFalse( $this->api_client->last_file_contents_as_account );
	}

	/**
	 * Create a native account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );

		return $account_service;
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
	 * @testdox Should return the injected PM promotions service instead of constructing a fallback.
	 */
	public function test_get_pm_promotions_service_returns_injected_instance(): void {
		$reflection = new \ReflectionMethod( $this->sut, 'get_pm_promotions_service' );
		$reflection->setAccessible( true );

		$resolved = $reflection->invoke( $this->sut );

		$this->assertSame( $this->pm_promotions_service, $resolved );
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
			'natively_chargeable_payment_method_ids',
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
			'is_express_checkout_in_payment_methods_list_supported',
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
}
