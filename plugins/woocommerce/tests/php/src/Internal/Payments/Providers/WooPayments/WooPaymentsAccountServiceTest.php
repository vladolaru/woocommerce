<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAccountService class.
 */
class WooPaymentsAccountServiceTest extends WC_Unit_Test_Case {

	/**
	 * Multisite blogs created by tests.
	 *
	 * @var int[]
	 */
	private array $multisite_blog_ids = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}
		$multisite_blog_ids       = $this->multisite_blog_ids;
		$this->multisite_blog_ids = array();
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_onboarding_test_mode' );
		delete_option( '_wcpay_onboarding_stripe_connected' );
		delete_option( 'wcpay_connection_success_modal_dismissed' );
		delete_option( 'wcpay_onboarding_embedded_kyc_in_progress' );
		delete_option( 'wcpay_test_mode_enabled_date' );
		delete_option( 'woocommerce_woopayments_nox_profile' );
		delete_option( 'woocommerce_woopayments_nox_onboarding_locked' );
		delete_option( 'wcpay_account_deletion_pending_id' );
		delete_option( '_wcpay_feature_reports_area' );
		delete_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments' );
		delete_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version' );
		foreach ( $this->get_preserved_database_cache_keys() as $cache_key ) {
			delete_option( $cache_key );
		}
		delete_transient( 'wcpay_stripe_onboarding_state' );
		delete_transient( 'woopay_enabled_by_default' );
		delete_transient( 'wcpay_onboarding_init_in_progress' );
		delete_transient( 'wcpay_on_boarding_disabled' );
		delete_transient( 'wcpay_test_to_live_eligible' );
		delete_transient( 'wcpay_post_kyc_activation_eligible' );
		remove_all_filters( 'pre_option_wcpay_account_data' );
		remove_all_filters( 'pre_option_wcpay_account_deletion_pending_id' );
		remove_all_filters( 'wcpay_dev_mode' );
		remove_all_filters( 'wcpay_test_mode' );
		remove_all_filters( 'wcpay_test_mode_onboarding' );
		remove_all_filters( 'woocommerce_woopayments_fraud_services_config' );
		remove_all_filters( 'allowed_redirect_hosts' );
		set_current_screen( 'front' );
		parent::tearDown();

		if ( array() !== $multisite_blog_ids ) {
			foreach ( $multisite_blog_ids as $blog_id ) {
				if ( get_site( $blog_id ) ) {
					wpmu_delete_blog( $blog_id, true );
				}
			}
			wp_cache_flush();
		}
	}

	/**
	 * @testdox Should expose account ID, mode-specific publishable key, and readiness from the preserved account cache.
	 */
	public function test_exposes_account_keys_and_readiness_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'           => 'acct_123',
					'test_publishable_key' => 'pk_test_123',
					'live_publishable_key' => 'pk_live_123',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );

		$sut = $this->create_service();

		$this->assertSame( 'acct_123', $sut->get_account_id() );
		$this->assertSame( 'pk_live_123', $sut->get_publishable_key() );
		$this->assertSame( 'live', $sut->get_mode() );
		// Plugin 11.1.0 `Order_Mode::PRODUCTION` is `prod`, not the account mode `live` (class-order-mode.php:21).
		$this->assertSame( 'prod', $sut->get_order_mode() );
		$this->assertTrue( $sut->can_process_payments() );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$this->assertSame( 'pk_test_123', $sut->get_publishable_key() );
		$this->assertSame( 'test', $sut->get_mode() );
		$this->assertSame( 'test', $sut->get_order_mode() );
		$this->assertTrue( $sut->is_test_mode_enabled() );
	}

	/**
	 * @testdox Native eligibility and cohort should follow eligible, ineligible, and older-platform account payloads.
	 * @dataProvider native_payments_payload_provider
	 *
	 * @param array<string,mixed> $native_payments Native payments payload, or an empty array when the field is absent.
	 * @param bool                $include_field   Whether to include the native payments field.
	 * @param bool                $expected_eligible Expected native eligibility.
	 * @param string              $expected_cohort Expected native cohort.
	 */
	public function test_exposes_native_payments_eligibility_and_cohort( array $native_payments, bool $include_field, bool $expected_eligible, string $expected_cohort ): void {
		$account_data = array( 'is_live' => true );
		if ( $include_field ) {
			$account_data['native_payments'] = $native_payments;
		}
		update_option(
			'wcpay_account_data',
			array(
				'data'    => $account_data,
				'fetched' => time(),
				'errored' => false,
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( method_exists( $sut, 'is_native_eligible' ), 'Native eligibility should be part of the account service contract.' );
		$this->assertTrue( method_exists( $sut, 'get_native_cohort' ), 'Native cohort should be part of the account service contract.' );
		$this->assertSame( $expected_eligible, $sut->is_native_eligible() );
		$this->assertSame( $expected_cohort, $sut->get_native_cohort() );
	}

	/**
	 * Native payments account payload fixtures.
	 *
	 * @return array<string,array{array<string,mixed>,bool,bool,string}>
	 */
	public static function native_payments_payload_provider(): array {
		return array(
			'eligible cohort'            => array(
				array(
					'eligible' => true,
					'cohort'   => 'canary',
					'reason'   => '',
				),
				true,
				true,
				'canary',
			),
			'ineligible with reason'     => array(
				array(
					'eligible' => false,
					'cohort'   => 'holdback',
					'reason'   => 'manual_hold',
				),
				true,
				false,
				'holdback',
			),
			'older platform omits field' => array( array(), false, true, '' ),
		);
	}

	/**
	 * @testdox Account readiness reads each multisite blog's preserved cache after switching.
	 * @group multisite
	 */
	public function test_account_cache_is_isolated_across_multisite_blog_switches(): void {
		$this->skipWithoutMultisite();
		$this->store_account_cache_fixture( 'acct_main' );

		$sut = $this->create_service();
		$this->assertSame( 'acct_main', $sut->get_account_id() );

		$blog_id                    = self::factory()->blog->create();
		$this->multisite_blog_ids[] = $blog_id;
		switch_to_blog( $blog_id );
		$this->store_account_cache_fixture( 'acct_subsite' );
		$this->assertSame( 'acct_subsite', $sut->get_account_id() );

		restore_current_blog();
		$this->assertSame( 'acct_main', $sut->get_account_id() );
	}

	/**
	 * @testdox Reports availability reads the preserved server flag for each multisite blog.
	 * @group multisite
	 */
	public function test_reports_availability_is_isolated_across_multisite_blog_switches(): void {
		$this->skipWithoutMultisite();
		update_option( 'wcpay_account_data', array( 'data' => array( 'reports_area_enabled' => true ) ) );
		update_option( '_wcpay_feature_reports_area', '0' );

		$sut = $this->create_service();
		$this->assertTrue( $sut->is_reports_enabled() );

		$blog_id                    = self::factory()->blog->create();
		$this->multisite_blog_ids[] = $blog_id;
		switch_to_blog( $blog_id );
		update_option( 'wcpay_account_data', array( 'data' => array( 'reports_area_enabled' => false ) ) );
		update_option( '_wcpay_feature_reports_area', '1' );
		$this->assertFalse( $sut->is_reports_enabled() );

		restore_current_blog();
		$this->assertTrue( $sut->is_reports_enabled() );
	}

	/**
	 * @testdox Should expose whether the native WooPayments gateway is enabled.
	 */
	public function test_exposes_gateway_enabled_state_from_gateway_settings(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );

		$sut = $this->create_service();

		$this->assertTrue( $sut->is_gateway_enabled() );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'no' ) );

		$this->assertFalse( $sut->is_gateway_enabled() );
	}

	/**
	 * @testdox Should fail closed when the native WooPayments gateway enabled setting is absent.
	 */
	public function test_gateway_enabled_state_defaults_to_disabled_when_setting_is_absent(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_gateway_enabled() );
	}

	/**
	 * @testdox Should use form-field defaults for absent settings while preserving explicit caller fallbacks and merchant values.
	 */
	public function test_get_gateway_setting_uses_form_field_defaults_for_absent_settings(): void {
		$sut = $this->create_service();

		$this->assertSame( 'yes', $sut->get_gateway_setting( 'saved_cards' ) );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $sut->get_gateway_setting( 'express_checkout_product_methods' ) );
		$this->assertSame( 'no', $sut->get_gateway_setting( 'saved_cards', 'no' ) );
		$this->assertSame( '', $sut->get_gateway_setting( 'express_checkout_enabled' ) );
		$this->assertNull( $sut->get_gateway_setting( 'express_checkout_enabled', null ) );
		$this->assertSame( 'fallback', $sut->get_gateway_setting( 'express_checkout_enabled', 'fallback' ) );

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'saved_cards'              => 'no',
				'express_checkout_enabled' => false,
			)
		);

		$this->assertSame( 'no', $sut->get_gateway_setting( 'saved_cards' ) );
		$this->assertFalse( $sut->get_gateway_setting( 'express_checkout_enabled' ) );
		$this->assertSame( array( 'payment_request', 'woopay', 'amazon_pay' ), $sut->get_gateway_setting( 'express_checkout_cart_methods' ) );
	}

	/**
	 * @testdox Should expose whether onboarding was disabled by the WooPayments platform.
	 */
	public function test_exposes_onboarding_disabled_state_from_transient(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_onboarding_disabled() );

		set_transient( 'wcpay_on_boarding_disabled', true, 2 * HOUR_IN_SECONDS );

		$this->assertTrue( $sut->is_onboarding_disabled() );
	}

	/**
	 * @testdox Should preserve Stripe-hosted WooPayments redirects.
	 */
	public function test_registers_stripe_allowed_redirect_host(): void {
		$sut = $this->create_service();
		$sut->register();

		$this->assertNotFalse( has_filter( 'allowed_redirect_hosts', array( $sut, 'allowed_redirect_hosts' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertContains( 'connect.stripe.com', apply_filters( 'allowed_redirect_hosts', array() ) );
	}

	/**
	 * @testdox Should expose the account default currency from the preserved account cache.
	 */
	public function test_exposes_account_default_currency_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'       => 'acct_123',
					'is_live'          => true,
					'store_currencies' => array(
						'default'   => 'eur',
						'supported' => array( 'eur' ),
					),
				),
			)
		);

		$sut = $this->create_service();

		$this->assertSame( 'eur', $sut->get_account_default_currency() );
	}

	/**
	 * @testdox Should fall back to USD when the preserved account cache does not include a default currency.
	 */
	public function test_account_default_currency_falls_back_to_usd_when_account_cache_omits_currency(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
					'is_live'    => true,
				),
			)
		);

		$sut = $this->create_service();

		$this->assertSame( 'usd', $sut->get_account_default_currency() );
	}

	/**
	 * @testdox Should fail closed for invalid or incomplete account cache payloads.
	 */
	public function test_fails_closed_for_invalid_or_incomplete_account_cache_payloads(): void {
		foreach (
			array(
				false,
				'invalid',
				array(),
				array( 'data' => 'invalid' ),
				array( 'data' => array() ),
			) as $payload
		) {
			update_option( 'wcpay_account_data', $payload );

			$sut = $this->create_service();

			$this->assertSame( '', $sut->get_account_id() );
			$this->assertSame( '', $sut->get_publishable_key() );
			$this->assertFalse( $sut->can_process_payments() );
		}
	}

	/**
	 * @testdox Should expose cached account IDs and keys while failing readiness for disabled or incomplete accounts.
	 */
	public function test_exposes_cache_values_but_fails_readiness_for_disabled_or_incomplete_accounts(): void {
		foreach (
			array(
				array(
					'data' => array(
						'account_id'           => 'acct_123',
						'live_publishable_key' => 'pk_live_123',
						'is_live'              => true,
						'payments_enabled'     => false,
						'details_submitted'    => true,
					),
				),
				array(
					'data' => array(
						'account_id'           => 'acct_123',
						'live_publishable_key' => 'pk_live_123',
						'is_live'              => true,
						'payments_enabled'     => true,
						'details_submitted'    => false,
					),
				),
			) as $payload
		) {
			update_option( 'wcpay_account_data', $payload );

			$sut = $this->create_service();

			$this->assertSame( 'acct_123', $sut->get_account_id() );
			$this->assertSame( 'pk_live_123', $sut->get_publishable_key() );
			$this->assertFalse( $sut->can_process_payments() );
		}
	}

	/**
	 * @testdox Should expose live, test-drive, and sandbox account state from the preserved account cache.
	 */
	public function test_exposes_account_type_state_from_account_cache(): void {
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_123',
					'is_live'           => false,
					'is_test_drive'     => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
				),
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( $sut->has_account() );
		$this->assertTrue( $sut->has_test_account() );
		$this->assertFalse( $sut->has_sandbox_account() );
		$this->assertFalse( $sut->has_live_account() );
		$this->assertTrue( $sut->has_working_account() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_123',
					'is_live'           => false,
					'is_test_drive'     => false,
					'payments_enabled'  => true,
					'details_submitted' => true,
				),
			)
		);
		$sut = $this->create_service();

		$this->assertTrue( $sut->has_sandbox_account() );
		$this->assertFalse( $sut->has_test_account() );
		$this->assertFalse( $sut->has_live_account() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'    => 'acct_123',
					'is_live'       => true,
					'is_test_drive' => false,
				),
			)
		);
		$sut = $this->create_service();

		$this->assertTrue( $sut->has_live_account() );
		$this->assertFalse( $sut->has_test_account() );
		$this->assertFalse( $sut->has_sandbox_account() );
	}

	/**
	 * @testdox Should distinguish an indeterminate account refresh from a confirmed disconnected account.
	 */
	public function test_distinguishes_indeterminate_account_refresh_from_confirmed_disconnection(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => null,
				'fetched'            => time(),
				'errored'            => true,
				'consecutive_errors' => 1,
			)
		);

		$indeterminate_service = $this->create_service();

		$this->assertFalse( $indeterminate_service->has_account(), 'A transient refresh failure without stale data must not appear as a connected account to ordinary consumers.' );
		$this->assertTrue( $indeterminate_service->has_account_or_is_connection_indeterminate(), 'Address-token cleanup must preserve state while account connection is indeterminate.' );

		delete_option( 'wcpay_account_data' );
		$empty_response_api_client = $this->create_counting_account_api_client( array() );
		$disconnected_service      = $this->create_service_with_api_client( $empty_response_api_client );

		$this->assertFalse( $disconnected_service->has_account_or_is_connection_indeterminate(), 'A successful empty account response must remain a confirmed disconnection.' );
		$this->assertSame( 1, $empty_response_api_client->calls, 'A confirmed disconnected account should be derived from one successful account response.' );
	}

	/**
	 * @testdox Should accept only canonical no-stale errored account cache wrappers when refresh is disabled.
	 *
	 * @dataProvider indeterminate_account_cache_wrappers
	 *
	 * @param array<string,mixed>|false $cache_contents Cache contents, or false when no option exists.
	 * @param bool                      $expected       Whether the wrapper is a canonical indeterminate state.
	 */
	public function test_accepts_only_canonical_no_stale_errored_account_cache_wrappers_when_refresh_is_disabled( $cache_contents, bool $expected ): void {
		if ( false === $cache_contents ) {
			delete_option( 'wcpay_account_data' );
		} else {
			update_option( 'wcpay_account_data', $cache_contents );
		}

		$sut = $this->create_service();
		$sut->disable_refresh();

		$this->assertSame( $expected, $sut->has_account_or_is_connection_indeterminate() );
	}

	/**
	 * Provide account cache wrappers for indeterminate connection checks.
	 *
	 * @return array<string,array{0:array<string,mixed>|false,1:bool}>
	 */
	public function indeterminate_account_cache_wrappers(): array {
		return array(
			'canonical no-stale error' => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				true,
			),
			'missing data'             => array(
				array(
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'scalar data'              => array(
				array(
					'data'               => 'unexpected',
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'empty data'               => array(
				array(
					'data'               => array(),
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'missing fetched'          => array(
				array(
					'data'               => null,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'nonnumeric fetched'       => array(
				array(
					'data'               => null,
					'fetched'            => 'not-a-time',
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'zero fetched'             => array(
				array(
					'data'               => null,
					'fetched'            => 0,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'negative fetched'         => array(
				array(
					'data'               => null,
					'fetched'            => -1,
					'errored'            => true,
					'consecutive_errors' => 1,
				),
				false,
			),
			'missing error count'      => array(
				array(
					'data'    => null,
					'fetched' => 1,
					'errored' => true,
				),
				false,
			),
			'nonnumeric error count'   => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 'one',
				),
				false,
			),
			'zero error count'         => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => 0,
				),
				false,
			),
			'negative error count'     => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => true,
					'consecutive_errors' => -1,
				),
				false,
			),
			'integer errored flag'     => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => 1,
					'consecutive_errors' => 1,
				),
				false,
			),
			'string errored flag'      => array(
				array(
					'data'               => null,
					'fetched'            => 1,
					'errored'            => 'yes',
					'consecutive_errors' => 1,
				),
				false,
			),
			'no option'                => array( false, false ),
			'empty wrapper'            => array( array(), false ),
			'successful empty account' => array(
				array(
					'data'               => array(),
					'fetched'            => 1,
					'errored'            => false,
					'consecutive_errors' => 0,
				),
				false,
			),
		);
	}

	/**
	 * @testdox Account liveness should be tri-state: live, test, or unknown when undetermined.
	 */
	public function test_get_account_is_live_reports_tristate_liveness(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
					'is_live'    => true,
				),
			)
		);
		$this->assertTrue( $this->create_service()->get_account_is_live() );

		// A non-live cached account is only valid while onboarding test mode is on.
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
					'is_live'    => false,
				),
			)
		);
		$this->assertFalse( $this->create_service()->get_account_is_live() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
				),
			)
		);
		$this->assertNull( $this->create_service()->get_account_is_live(), 'A cached account without an is_live field must report unknown liveness.' );

		delete_option( 'wcpay_account_data' );
		$this->assertNull( $this->create_service()->get_account_is_live(), 'An unfetched account must report unknown liveness.' );
	}

	/**
	 * @testdox Network saved cards should be off by default and opt-in via the parity filter.
	 */
	public function test_is_network_saved_cards_enabled_follows_the_parity_filter(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_network_saved_cards_enabled() );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$this->assertTrue( $sut->is_network_saved_cards_enabled() );
	}

	/**
	 * @testdox Should autoload the onboarding test-mode option when enabling it on the dev-mode cache path, like the client.
	 */
	public function test_dev_mode_cache_path_autoloads_onboarding_test_mode_option(): void {
		// Arrange the dev-mode branch of is_valid_cached_account(): dev mode on, the
		// option absent (so it would be re-created via add_option's autoload default),
		// and a non-live cached account so the guarded write fires.
		delete_option( 'wcpay_onboarding_test_mode' );
		add_filter( 'wcpay_dev_mode', '__return_true' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_123',
					'is_live'           => false,
					'payments_enabled'  => true,
					'details_submitted' => true,
				),
			)
		);

		$sut = $this->create_service();
		$sut->disable_refresh();

		$sut->get_cached_account_data();

		$this->assertSame( 'yes', get_option( 'wcpay_onboarding_test_mode' ) );
		// Storefront renders read it through is_test_mode_enabled(); client 11.1.0 autoloads it (onboarding service :1086).
		$this->assertOptionAutoloaded( 'wcpay_onboarding_test_mode' );
	}

	/**
	 * @testdox Should expose rejected and under-review account state from the preserved account cache.
	 */
	public function test_exposes_rejected_and_under_review_account_state_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'status' => 'rejected.fraud',
					)
				),
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( method_exists( $sut, 'is_account_rejected' ), 'Account rejection state should be part of the native account service contract.' );
		$this->assertTrue( $sut->is_account_rejected() );
		$this->assertFalse( $sut->is_account_under_review() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'status' => 'under_review',
					)
				),
			)
		);
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_account_rejected() );
		$this->assertTrue( $sut->is_account_under_review() );
	}

	/**
	 * @testdox Should expose details-submitted and admin-navigation validity from the preserved account cache.
	 */
	public function test_exposes_details_submitted_and_admin_navigation_validity_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'capabilities' => array(
							'card_payments' => 'active',
						),
					)
				),
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( method_exists( $sut, 'is_details_submitted' ), 'Details-submitted state should be part of the native account service contract.' );
		$this->assertTrue( $sut->is_details_submitted() );
		$this->assertTrue( $sut->has_valid_account_for_admin_navigation() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'capabilities' => array(
							'card_payments' => 'unrequested',
						),
					)
				),
			)
		);
		$sut = $this->create_service();

		$this->assertFalse( $sut->has_valid_account_for_admin_navigation() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'details_submitted' => false,
						'capabilities'      => array(
							'card_payments' => 'active',
						),
					)
				),
			)
		);
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_details_submitted() );
		$this->assertFalse( $sut->has_valid_account_for_admin_navigation() );
	}

	/**
	 * @testdox Should expose card-reader and Capital visibility flags from the preserved account cache.
	 */
	public function test_exposes_card_reader_and_capital_visibility_flags_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'card_present_eligible'      => true,
						'has_card_readers_available' => true,
						'capital'                    => array(
							'has_previous_loans' => true,
						),
					)
				),
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( method_exists( $sut, 'is_card_present_eligible' ), 'Card-present eligibility should be part of the native account service contract.' );
		$this->assertTrue( $sut->is_card_present_eligible() );
		$this->assertTrue( $sut->has_card_readers_available() );
		$this->assertTrue( $sut->has_previous_capital_loans() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(),
			)
		);
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_card_present_eligible() );
		$this->assertFalse( $sut->has_card_readers_available() );
		$this->assertFalse( $sut->has_previous_capital_loans() );
	}

	/**
	 * @testdox Should expose Documents eligibility and VAT submission flags from the preserved account cache.
	 */
	public function test_exposes_document_flags_from_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'is_documents_enabled'   => true,
						'has_submitted_vat_data' => true,
					)
				),
			)
		);

		$sut = $this->create_service();

		$this->assertTrue( method_exists( $sut, 'is_documents_enabled' ), 'Documents eligibility should be part of the native account service contract.' );
		$this->assertTrue( method_exists( $sut, 'has_submitted_vat_data' ), 'VAT submission state should be part of the native account service contract.' );
		$this->assertTrue( $sut->is_documents_enabled() );
		$this->assertTrue( $sut->has_submitted_vat_data() );

		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(
					array(
						'is_documents_enabled'   => false,
						'has_submitted_vat_data' => false,
					)
				),
			)
		);
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_documents_enabled() );
		$this->assertFalse( $sut->has_submitted_vat_data() );
	}

	/**
	 * @testdox Should fail closed when Documents flags are missing or no account is cached.
	 */
	public function test_document_flags_fail_closed_when_missing_or_no_account_exists(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => $this->get_valid_live_account_payload(),
			)
		);

		$sut = $this->create_service();

		$this->assertFalse( $sut->is_documents_enabled() );
		$this->assertFalse( $sut->has_submitted_vat_data() );

		update_option( 'wcpay_account_data', array( 'data' => array() ) );
		$sut = $this->create_service();

		$this->assertFalse( $sut->is_documents_enabled() );
		$this->assertFalse( $sut->has_submitted_vat_data() );
	}

	/**
	 * @testdox Reports availability follows preserved server values before the local fallback without a refresh.
	 * @dataProvider reports_availability_provider
	 *
	 * @param mixed  $server_value Server Reports flag.
	 * @param bool   $include_server_value Whether to preserve the server Reports flag.
	 * @param string $local_value Local Reports fallback value.
	 * @param bool   $expected Whether Reports should be available.
	 */
	public function test_reports_availability_follows_preserved_server_values_before_local_fallback( $server_value, bool $include_server_value, string $local_value, bool $expected ): void {
		$account_data = $this->get_valid_live_account_payload();
		if ( $include_server_value ) {
			$account_data['reports_area_enabled'] = $server_value;
		}
		update_option( 'wcpay_account_data', array( 'data' => $account_data ) );
		update_option( '_wcpay_feature_reports_area', $local_value );
		$api_client = $this->create_counting_account_api_client( $this->get_valid_live_account_payload() );
		$sut        = $this->create_service_with_api_client( $api_client );

		$this->assertSame( $expected, $sut->is_reports_enabled() );
		$this->assertSame( 0, $api_client->calls, 'Resolving Reports availability should not refresh account data.' );
	}

	/**
	 * Reports availability payload fixtures.
	 *
	 * @return array<string,array{mixed,bool,string,bool}>
	 */
	public static function reports_availability_provider(): array {
		return array(
			'server true overrides local off'     => array( true, true, '0', true ),
			'server false overrides local on'     => array( false, true, '1', false ),
			'missing server value falls back on'  => array( null, false, '1', true ),
			'missing server value falls back off' => array( null, false, '0', false ),
			'null server value falls back on'     => array( null, true, '1', true ),
			'null server value falls back off'    => array( null, true, '0', false ),
			'truthy string server value enables'  => array( 'false', true, '0', true ),
			'falsey string server value disables' => array( '0', true, '1', false ),
		);
	}

	/**
	 * @testdox Reports local fallback does not require an account.
	 */
	public function test_reports_local_fallback_does_not_require_an_account(): void {
		update_option( '_wcpay_feature_reports_area', '1' );
		$api_client = $this->create_counting_account_api_client( $this->get_valid_live_account_payload() );
		$sut        = $this->create_service_with_api_client( $api_client );

		$this->assertTrue( $sut->is_reports_enabled() );
		$this->assertSame( 0, $api_client->calls, 'Reports local fallback should not refresh account data.' );
	}

	/**
	 * @testdox Reports availability does not refresh account data when no server flag is preserved.
	 */
	public function test_reports_availability_does_not_refresh_account_data_without_a_server_flag(): void {
		$api_client = $this->create_counting_account_api_client( $this->get_valid_live_account_payload() );
		$sut        = $this->create_service_with_api_client( $api_client );

		update_option( '_wcpay_feature_reports_area', '0' );

		$this->assertFalse( $sut->is_reports_enabled() );
		$this->assertSame( 0, $api_client->calls, 'Resolving Reports availability should not trigger an account refresh.' );
	}

	/**
	 * @testdox Should clear the preserved account cache so the next account read can refresh from the provider.
	 */
	public function test_clear_cache_deletes_preserved_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
				),
			)
		);

		$sut = $this->create_service();
		$sut->clear_cache();

		$this->assertFalse( get_option( 'wcpay_account_data' ) );
		$this->assertSame( '', $sut->get_account_id() );
	}

	/**
	 * @testdox Should immediately overwrite the preserved account cache with a connected-no-account payload.
	 */
	public function test_overwrite_cache_with_no_account_updates_preserved_account_cache(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'        => 'acct_123',
					'is_live'           => true,
					'payments_enabled'  => true,
					'details_submitted' => true,
				),
			)
		);

		$sut = $this->create_service();
		$sut->overwrite_cache_with_no_account();

		$cached = get_option( 'wcpay_account_data' );

		$this->assertIsArray( $cached );
		$this->assertSame( array(), $cached['data'] );
		$this->assertSame( '', $sut->get_account_id() );
		$this->assertFalse( $sut->can_process_payments() );
	}

	/**
	 * @testdox Should reset preserved gateway, onboarding, and NOX state after an account deletion.
	 */
	public function test_cleanup_after_account_reset_resets_preserved_state(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                        => 'yes',
				'test_mode'                      => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
				'payment_request_button_size'    => 'large',
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_123',
					'is_live'    => false,
				),
			)
		);
		update_option( '_wcpay_onboarding_stripe_connected', array( 'acct_123' => true ) );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option( 'wcpay_connection_success_modal_dismissed', 'yes' );
		update_option( 'wcpay_onboarding_embedded_kyc_in_progress', 'yes' );
		update_option( 'wcpay_test_mode_enabled_date', 123 );
		update_option( 'woocommerce_woopayments_nox_profile', array( 'id' => 'nox_profile' ) );
		update_option( 'woocommerce_woopayments_nox_onboarding_locked', 'yes' );
		update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments', 'yes' );
		update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version', 2 );
		set_transient( 'wcpay_stripe_onboarding_state', 'state', DAY_IN_SECONDS );
		set_transient( 'woopay_enabled_by_default', true, DAY_IN_SECONDS );
		set_transient( 'wcpay_onboarding_init_in_progress', 'yes', DAY_IN_SECONDS );
		set_transient( 'wcpay_test_to_live_eligible', true, DAY_IN_SECONDS );
		set_transient( 'wcpay_post_kyc_activation_eligible', true, DAY_IN_SECONDS );

		$sut = $this->create_service();
		$sut->cleanup_after_account_reset();

		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertIsArray( $settings );
		$this->assertSame( 'no', $settings['enabled'] );
		$this->assertSame( 'no', $settings['test_mode'] );
		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'large', $settings['payment_request_button_size'], 'Unrelated gateway settings should be preserved.' );
		$this->assertSame( array(), get_option( '_wcpay_onboarding_stripe_connected' ) );
		$this->assertSame( 'no', get_option( 'wcpay_onboarding_test_mode' ) );
		$this->assertOptionAutoloaded( 'wcpay_onboarding_test_mode' );
		$this->assertFalse( get_option( 'wcpay_account_data' ) );
		$this->assertFalse( get_option( 'wcpay_connection_success_modal_dismissed' ) );
		$this->assertFalse( get_option( 'wcpay_onboarding_embedded_kyc_in_progress' ) );
		$this->assertFalse( get_option( 'wcpay_test_mode_enabled_date' ) );
		$this->assertFalse( get_option( 'woocommerce_woopayments_nox_profile' ) );
		$this->assertFalse( get_option( 'woocommerce_woopayments_nox_onboarding_locked' ) );
		$this->assertFalse( get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments' ) );
		$this->assertFalse( get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version' ) );
		$this->assertFalse( get_transient( 'wcpay_stripe_onboarding_state' ) );
		$this->assertFalse( get_transient( 'woopay_enabled_by_default' ) );
		$this->assertFalse( get_transient( 'wcpay_onboarding_init_in_progress' ) );
		$this->assertFalse( get_transient( 'wcpay_test_to_live_eligible' ) );
		$this->assertFalse( get_transient( 'wcpay_post_kyc_activation_eligible' ) );
	}

	/**
	 * @testdox Should clear incentive usage state only for the reset account's site.
	 * @group multisite
	 */
	public function test_cleanup_after_account_reset_clears_incentive_usage_state_only_for_current_blog(): void {
		$this->skipWithoutMultisite();
		update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments', 'yes' );
		update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version', 2 );

		$blog_id                    = self::factory()->blog->create();
		$this->multisite_blog_ids[] = $blog_id;
		switch_to_blog( $blog_id );

		try {
			update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments', 'yes' );
			update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version', 2 );

			$this->create_service()->cleanup_after_account_reset();

			$this->assertFalse( get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments' ), 'The reset subsite should re-evaluate WooPayments usage.' );
			$this->assertFalse( get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version' ), 'The reset subsite should not retain the prior eligibility logic version.' );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 'yes', get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments' ), 'Resetting a subsite should preserve the main-site usage value.' );
		$this->assertSame( 2, get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version' ), 'Resetting a subsite should preserve the main-site logic version.' );
	}

	/**
	 * @testdox Should delete the incentive logic version and propagate when deleting the usage value throws.
	 */
	public function test_cleanup_after_account_reset_deletes_incentive_version_when_usage_deletion_throws(): void {
		$usage_option   = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments';
		$version_option = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version';
		update_option( $usage_option, 'yes' );
		update_option( $version_option, 2 );

		$expected_exception = new RuntimeException( 'Incentive usage deletion failed.' );
		$throw_on_delete    = static function () use ( $expected_exception ): void {
			throw $expected_exception;
		};
		add_action( "delete_option_{$usage_option}", $throw_on_delete );

		$caught_exception = null;
		try {
			$this->create_service()->cleanup_after_account_reset();
		} catch ( RuntimeException $exception ) {
			$caught_exception = $exception;
		} finally {
			remove_action( "delete_option_{$usage_option}", $throw_on_delete );
		}

		$this->assertSame( $expected_exception, $caught_exception, 'The original option-deletion exception should propagate.' );
		$this->assertFalse( get_option( $version_option ), 'The paired logic version should still be deleted.' );
	}

	/**
	 * @testdox Should clear all preserved WooPayments database cache keys after an account reset.
	 */
	public function test_cleanup_after_account_reset_clears_preserved_database_cache_keys(): void {
		foreach ( $this->get_preserved_database_cache_keys() as $cache_key ) {
			update_option( $cache_key, array( 'stale' => true ), false );
			wp_cache_set( $cache_key, array( 'stale' => true ), 'options' );
		}

		$sut = $this->create_service();
		$sut->cleanup_after_account_reset();

		foreach ( $this->get_preserved_database_cache_keys() as $cache_key ) {
			$this->assertFalse( get_option( $cache_key, false ), "Expected {$cache_key} option to be deleted." );
			$this->assertFalse( wp_cache_get( $cache_key, 'options' ), "Expected {$cache_key} object-cache entry to be deleted." );
		}
	}

	/**
	 * @testdox Should refetch the full account on the next read after onboarding caches a partial record, in $context requests.
	 *
	 * @dataProvider provide_account_read_contexts
	 *
	 * @param string $context Request context label.
	 * @param string $screen  Screen to set, so is_admin() matches the context.
	 */
	public function test_partial_onboarding_record_is_replaced_by_the_full_account_on_the_next_read( string $context, string $screen ): void {
		set_current_screen( $screen );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		$full_account = $this->load_recorded_test_drive_account();
		$api_client   = $this->create_recorded_account_api_client( $full_account, false );
		$sut          = $this->create_service_with_api_client( $api_client );

		$sut->cache_account_data_until_refreshed( $this->get_partial_onboarding_record() );
		$account = $sut->get_cached_account_data();
		$cached  = get_option( 'wcpay_account_data' );

		$this->assertSame( 1, $api_client->calls, 'The client clears its cache after test-drive init (class-wc-payments-onboarding-service.php:796), so the next read fetches the account.' );
		$this->assertSame( $full_account, $account );
		$this->assertSame( $full_account, $cached['data'] );
		$this->assertGreaterThan( 0, $cached['fetched'] );
		$this->assertFalse( $cached['errored'] );
		$this->assertSame( 'active', $account['capabilities']['card_payments'] );
	}

	/**
	 * Request contexts an account read can run in; WP-CLI and REST reads take the front-end branch of the TTL.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_account_read_contexts(): array {
		return array(
			'admin'     => array( 'admin', 'dashboard' ),
			'front-end' => array( 'front-end', 'front' ),
		);
	}

	/**
	 * @testdox Should keep the partial onboarding record, including its publishable key, when the refetch fails.
	 */
	public function test_partial_onboarding_record_keeps_the_publishable_key_when_the_refetch_fails(): void {
		set_current_screen( 'dashboard' );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		$api_client = $this->create_recorded_account_api_client( array(), true );
		$sut        = $this->create_service_with_api_client( $api_client );

		$sut->cache_account_data_until_refreshed( $this->get_partial_onboarding_record() );
		$account = $sut->get_cached_account_data();
		$cached  = get_option( 'wcpay_account_data' );

		$this->assertSame( 1, $api_client->calls );
		$this->assertSame( 'acct_1UL3bQJQsm0lol5W', $account['account_id'] );
		$this->assertSame( 'pk_test_partial', $account['test_publishable_key'], 'A failed refetch must not lose the key checkout needs (566fd534c3d).' );
		$this->assertTrue( $cached['errored'] );
	}

	/**
	 * @testdox Should refresh account data from the native API client and persist the full account payload.
	 */
	public function test_refresh_account_data_fetches_and_caches_full_account_payload(): void {
		update_option( 'woocommerce_store_id', 'store_123' );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => 'acct_stale',
				),
			)
		);

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Store ID passed to the account request.
			 *
			 * @var string
			 */
			public string $woocommerce_store_id = '';

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return a full fake account payload.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				$this->woocommerce_store_id = $woocommerce_store_id;

				return array(
					'account_id'                => 'acct_native_123',
					'email'                     => 'merchant@example.test',
					'communications_email'      => 'support@example.test',
					'test_publishable_key'      => 'pk_test_native',
					'live_publishable_key'      => 'pk_live_native',
					'is_live'                   => false,
					'is_test_drive'             => true,
					'payments_enabled'          => true,
					'details_submitted'         => true,
					'business_profile'          => array(
						'name' => 'Native Merchant',
					),
					'fraud_mitigation_settings' => array(
						'card_testing_protection' => array(
							'enabled' => true,
						),
					),
					'pre_check_save_my_info'    => true,
					'reports_area_enabled'      => true,
					'account_details'           => array(
						'business_type' => 'individual',
					),
				);
			}
		};
		$sut        = $this->create_service_with_api_client( $api_client );
		$refreshed  = array();
		$hook       = static function ( $account ) use ( &$refreshed ): void {
			$refreshed = $account;
		};

		add_action( 'woocommerce_payments_account_refreshed', $hook );

		try {
			$result = $sut->refresh_account_data();
		} finally {
			remove_action( 'woocommerce_payments_account_refreshed', $hook );
		}

		$cached = get_option( 'wcpay_account_data' );

		$this->assertSame( 'acct_native_123', $result['account_id'] );
		$this->assertSame( 'store_123', $api_client->woocommerce_store_id );
		$this->assertIsArray( $cached );
		$this->assertSame( $result, $cached['data'] );
		$this->assertSame( $result, $refreshed );
		$this->assertArrayHasKey( 'fraud_mitigation_settings', $cached['data'] );
		$this->assertArrayHasKey( 'account_details', $cached['data'] );
		$this->assertTrue( $cached['data']['reports_area_enabled'] );
		$this->assertTrue( $sut->is_reports_enabled() );
		$this->assertSame( 'acct_native_123', $sut->get_account_id() );
	}

	/**
	 * @testdox Should preserve stale account data and mark the cache errored when a forced refresh fails.
	 */
	public function test_refresh_account_data_preserves_stale_account_data_on_transport_error(): void {
		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - DAY_IN_SECONDS,
				'errored'            => true,
				'consecutive_errors' => 1,
			)
		);

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Number of account fetch attempts.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Throw a transient account-fetch failure.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @throws WooPaymentsApiException Always throws a transient account-fetch failure.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				++$this->calls;
				throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
			}
		};
		$sut        = $this->create_service_with_api_client( $api_client );
		$refreshed  = array();
		$hook       = static function ( $account ) use ( &$refreshed ): void {
			$refreshed = $account;
		};

		add_action( 'woocommerce_payments_account_refreshed', $hook );

		try {
			$result = $sut->refresh_account_data();
		} finally {
			remove_action( 'woocommerce_payments_account_refreshed', $hook );
		}

		$cached = get_option( 'wcpay_account_data' );

		$this->assertSame( $stale_account, $result );
		$this->assertSame( 1, $api_client->calls );
		$this->assertTrue( $sut->has_account_or_is_connection_indeterminate(), 'Stale valid account data should remain connected after a failed refresh.' );
		$this->assertSame( 1, $api_client->calls, 'Checking the connection state should reuse the refreshed cache.' );
		$this->assertSame( array(), $refreshed );
		$this->assertIsArray( $cached );
		$this->assertSame( $stale_account, $cached['data'] );
		$this->assertTrue( $cached['errored'] );
		$this->assertSame( 2, $cached['consecutive_errors'] );
	}

	/**
	 * @testdox Should fail closed when a strict account refresh cannot fetch fresh provider data.
	 */
	public function test_refresh_account_data_strict_fails_when_refresh_falls_back_to_stale_data(): void {
		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - DAY_IN_SECONDS,
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);

		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Throw a transient account-fetch failure.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @throws WooPaymentsApiException Always throws a transient account-fetch failure.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
			}
		};
		$sut        = $this->create_service_with_api_client( $api_client );

		$this->expectException( WooPaymentsApiException::class );

		$sut->refresh_account_data_strict();
	}

	/**
	 * @testdox Should fail closed when a strict account refresh cannot persist fresh account data.
	 */
	public function test_refresh_account_data_strict_fails_when_cache_write_does_not_stick(): void {
		$stale_cache   = array(
			'data'               => $this->get_valid_live_account_payload(
				array(
					'account_id' => 'acct_stale',
				)
			),
			'fetched'            => time() - DAY_IN_SECONDS,
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		$fresh_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_fresh',
			)
		);
		$sut           = $this->create_service_with_api_client( $this->create_counting_account_api_client( $fresh_account ) );

		add_filter(
			'pre_option_wcpay_account_data',
			static function () use ( $stale_cache ) {
				return $stale_cache;
			}
		);

		$this->expectException( WooPaymentsApiException::class );

		$sut->refresh_account_data_strict();
	}

	/**
	 * @testdox Should fail closed when the pending account-deletion marker cannot be written.
	 */
	public function test_mark_account_deletion_pending_fails_when_marker_write_does_not_stick(): void {
		$sut = $this->create_service();

		add_filter(
			'pre_option_wcpay_account_deletion_pending_id',
			static function () {
				return '';
			}
		);

		$this->expectException( RuntimeException::class );

		$sut->mark_account_deletion_pending( 'acct_123' );
	}

	/**
	 * @testdox Should fail closed when the pending account-deletion marker cannot be cleared.
	 */
	public function test_clear_pending_account_deletion_fails_when_marker_delete_does_not_stick(): void {
		update_option( 'wcpay_account_deletion_pending_id', 'acct_123', false );

		$sut = $this->create_service();

		add_filter(
			'pre_option_wcpay_account_deletion_pending_id',
			static function () {
				return 'acct_123';
			}
		);

		$this->expectException( RuntimeException::class );

		$sut->clear_pending_account_deletion();
	}

	/**
	 * @testdox Should use the non-admin account cache for twenty-four hours before refreshing.
	 */
	public function test_get_cached_account_data_uses_frontend_ttl_before_refreshing(): void {
		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		$fresh_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_fresh',
			)
		);
		$api_client    = $this->create_counting_account_api_client( $fresh_account );
		$sut           = $this->create_service_with_api_client( $api_client );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 23 * HOUR_IN_SECONDS ),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);

		$this->assertSame( $stale_account, $sut->get_cached_account_data() );
		$this->assertSame( 0, $api_client->calls );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 25 * HOUR_IN_SECONDS ),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		$sut = $this->create_service_with_api_client( $api_client );

		$this->assertSame( $fresh_account, $sut->get_cached_account_data() );
		$this->assertSame( 1, $api_client->calls );
	}

	/**
	 * @testdox Should use shorter admin TTLs and progressive backoff for errored account caches.
	 */
	public function test_get_cached_account_data_uses_admin_ttl_and_error_backoff(): void {
		set_current_screen( 'dashboard' );

		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		$fresh_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_fresh',
			)
		);
		$api_client    = $this->create_counting_account_api_client( $fresh_account );
		$sut           = $this->create_service_with_api_client( $api_client );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 4 * MINUTE_IN_SECONDS ),
				'errored'            => true,
				'consecutive_errors' => 2,
			)
		);

		$this->assertSame( $stale_account, $sut->get_cached_account_data() );
		$this->assertSame( 0, $api_client->calls );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 6 * MINUTE_IN_SECONDS ),
				'errored'            => true,
				'consecutive_errors' => 2,
			)
		);
		$sut = $this->create_service_with_api_client( $api_client );

		$this->assertSame( $fresh_account, $sut->get_cached_account_data() );
		$this->assertSame( 1, $api_client->calls );
	}

	/**
	 * @testdox Should return no account without fetching or touching the cache when the platform is not connected.
	 * @dataProvider provide_disconnected_account_caches
	 *
	 * @param array<string,mixed>|null $cached_data       Cached account data.
	 * @param bool                     $errored           Whether the cached entry is errored.
	 * @param bool                     $expected_eligible Expected native eligibility.
	 */
	public function test_get_cached_account_data_returns_no_account_without_a_platform_connection( ?array $cached_data, bool $errored, bool $expected_eligible ): void {
		set_current_screen( 'dashboard' );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $cached_data,
				'fetched'            => time() - DAY_IN_SECONDS,
				'errored'            => $errored,
				'consecutive_errors' => $errored ? 1 : 0,
			)
		);
		$cache_before = get_option( 'wcpay_account_data' );
		$api_client   = $this->create_counting_account_api_client( $this->get_valid_live_account_payload(), false );
		$sut          = $this->create_service_with_api_client( $api_client );

		$this->assertSame( array(), $sut->get_cached_account_data(), 'Client 11.1.0 returns [] before any cache read when the server is not connected (class-wc-payments-account.php:2442-2444).' );
		$this->assertSame( array(), $sut->refresh_account_data(), 'A forced refresh returns [] the same way.' );
		$this->assertSame( 0, $api_client->calls );
		$this->assertSame( $cache_before, get_option( 'wcpay_account_data' ), 'No errored entry may replace the cache.' );
		$this->assertFalse( $sut->has_account_or_is_connection_indeterminate(), 'Without a connection the state is known: not connected, like the client is_stripe_connected( true ).' );
		$this->assertSame( $expected_eligible, $sut->is_native_eligible(), 'Native eligibility keeps the cached platform decision while disconnected.' );
	}

	/**
	 * Account caches a disconnected store can hold.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:bool,2:bool}>
	 */
	public function provide_disconnected_account_caches(): array {
		return array(
			'account the platform keeps off native' => array(
				array(
					'account_id'           => 'acct_stale',
					'live_publishable_key' => 'pk_live_stale',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'native_payments'      => array( 'eligible' => false ),
				),
				false,
				false,
			),
			'errored entry from the old read'       => array( null, true, true ),
		);
	}

	/**
	 * @testdox Should drop the cached account when the site registers with or disconnects from WordPress.com, so the next connected read fetches.
	 * @testWith ["jetpack_site_registered"]
	 *           ["jetpack_site_disconnected"]
	 *
	 * @param string $hook_name Jetpack connection hook.
	 */
	public function test_connection_change_drops_the_cached_account_so_the_next_read_fetches( string $hook_name ): void {
		set_current_screen( 'dashboard' );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => null,
				'fetched'            => time(),
				'errored'            => true,
				'consecutive_errors' => 1,
			)
		);
		$fresh_account = $this->get_valid_live_account_payload( array( 'account_id' => 'acct_fresh' ) );
		$api_client    = $this->create_counting_account_api_client( $fresh_account );
		$sut           = $this->create_service_with_api_client( $api_client );
		$sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( $hook_name );

		$this->assertFalse( get_option( 'wcpay_account_data' ), 'Client 11.1.0 clears the account cache on both hooks (class-wc-payments-account.php:140-141).' );
		$this->assertSame( $fresh_account, $sut->get_cached_account_data() );
		$this->assertSame( 1, $api_client->calls, 'The errored entry left by the old disconnected read must not hold back the first read after a reconnect.' );
		$cached = get_option( 'wcpay_account_data' );
		$this->assertSame( $fresh_account, $cached['data'] );
		$this->assertFalse( $cached['errored'] );
	}

	/**
	 * @testdox Should serve the cached account without fetching when read while plugins load, before the connection state is known.
	 */
	public function test_read_while_plugins_load_serves_the_cached_account_without_fetching(): void {
		set_current_screen( 'dashboard' );
		$cached_account = $this->get_valid_live_account_payload( array( 'is_documents_enabled' => true ) );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $cached_account,
				'fetched'            => time() - DAY_IN_SECONDS,
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		$api_client = $this->create_counting_account_api_client( $this->get_valid_live_account_payload( array( 'account_id' => 'acct_fresh' ) ), false );
		$sut        = $this->create_service_while_plugins_load( $api_client );

		$this->assertSame( $cached_account, $sut->get_cached_account_data(), 'Before pluggable.php loads, the connection check reads false, so the read must not treat it as a known disconnect.' );
		$this->assertTrue( $sut->is_documents_enabled() );
		$this->assertTrue( $sut->has_account_or_is_connection_indeterminate(), 'An unknown connection state is indeterminate, not disconnected.' );
		$this->assertSame( 0, $api_client->calls, 'No fetch may run before the connection state is known.' );
	}

	/**
	 * @testdox Should leave the account cache untouched when read while plugins load, even when the entry is due for a refresh.
	 * @dataProvider provide_account_caches_due_for_refresh
	 *
	 * @param array<string,mixed>|null $cached_data Cached account data.
	 * @param bool                     $errored     Whether the cached entry is errored.
	 */
	public function test_read_while_plugins_load_writes_nothing( ?array $cached_data, bool $errored ): void {
		set_current_screen( 'dashboard' );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $cached_data,
				'fetched'            => time() - DAY_IN_SECONDS,
				'errored'            => $errored,
				'consecutive_errors' => $errored ? 1 : 0,
			)
		);
		$cache_before = get_option( 'wcpay_account_data' );
		$refreshes    = did_action( 'woocommerce_payments_account_refreshed' );
		$api_client   = $this->create_counting_account_api_client( $this->get_valid_live_account_payload(), false );
		$sut          = $this->create_service_while_plugins_load( $api_client );

		$sut->get_cached_account_data();
		$sut->refresh_account_data();

		$this->assertSame( $cache_before, get_option( 'wcpay_account_data' ), 'Before 42d357dca17 this read wrote an errored entry; the early read must write nothing.' );
		$this->assertSame( 0, $api_client->calls );
		$this->assertSame( $refreshes, did_action( 'woocommerce_payments_account_refreshed' ) );
	}

	/**
	 * Account caches that a connected read would refresh.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:bool}>
	 */
	public function provide_account_caches_due_for_refresh(): array {
		return array(
			'expired account'               => array(
				array(
					'account_id'           => 'acct_expired',
					'live_publishable_key' => 'pk_live_expired',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
				false,
			),
			'errored entry with no account' => array( null, true ),
		);
	}

	/**
	 * @testdox Should not refresh expired account data while Action Scheduler jobs are running.
	 */
	public function test_get_cached_account_data_does_not_refresh_during_action_scheduler_jobs(): void {
		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		$fresh_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_fresh',
			)
		);
		$api_client    = $this->create_counting_account_api_client( $fresh_account );
		$sut           = $this->create_service_with_api_client( $api_client );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 25 * HOUR_IN_SECONDS ),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);

		$sut->register();

		/**
		 * Fires before Action Scheduler executes an action.
		 *
		 * @since 11.0.0
		 */
		do_action( 'action_scheduler_before_execute' );

		try {
			$this->assertSame( $stale_account, $sut->get_cached_account_data() );
		} finally {
			remove_action( 'action_scheduler_before_execute', array( $sut, 'disable_refresh' ) );
		}

		$this->assertSame( 0, $api_client->calls );
	}

	/**
	 * @testdox Should expose the preserved account data snapshot without refreshing expired cache data.
	 */
	public function test_get_preserved_account_data_snapshot_does_not_refresh_expired_cache(): void {
		$stale_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_stale',
			)
		);
		$fresh_account = $this->get_valid_live_account_payload(
			array(
				'account_id' => 'acct_fresh',
			)
		);
		$api_client    = $this->create_counting_account_api_client( $fresh_account );
		$sut           = $this->create_service_with_api_client( $api_client );

		update_option(
			'wcpay_account_data',
			array(
				'data'               => $stale_account,
				'fetched'            => time() - ( 25 * HOUR_IN_SECONDS ),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);

		$this->assertSame( $stale_account, $sut->get_preserved_account_data_snapshot() );
		$this->assertSame( 0, $api_client->calls );
	}

	/**
	 * @testdox Should let onboarding test mode and the legacy test-mode filter override persisted gateway settings.
	 */
	public function test_mode_follows_onboarding_option_and_test_mode_filter(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'           => 'acct_123',
					'test_publishable_key' => 'pk_test_123',
					'live_publishable_key' => 'pk_live_123',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		update_option( 'wcpay_onboarding_test_mode', 'yes' );

		$sut = $this->create_service();

		$this->assertTrue( $sut->is_test_mode_enabled() );
		$this->assertSame( 'test', $sut->get_mode() );
		$this->assertSame( 'pk_test_123', $sut->get_publishable_key() );

		update_option( 'wcpay_onboarding_test_mode', 'no' );
		add_filter( 'wcpay_test_mode', '__return_true' );

		$this->assertTrue( $sut->is_test_mode_enabled() );
		$this->assertSame( 'test', $sut->get_mode() );
	}

	/**
	 * @testdox Should force test mode when WooPayments onboarding or dev-mode filters are enabled.
	 */
	public function test_mode_follows_onboarding_and_dev_mode_filters(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'           => 'acct_123',
					'test_publishable_key' => 'pk_test_123',
					'live_publishable_key' => 'pk_live_123',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
				),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		add_filter( 'wcpay_test_mode_onboarding', '__return_true' );

		$sut = $this->create_service();

		$this->assertTrue( $sut->is_test_mode_enabled() );
		$this->assertSame( 'test', $sut->get_mode() );
		$this->assertSame( 'pk_test_123', $sut->get_publishable_key() );

		remove_all_filters( 'wcpay_test_mode_onboarding' );
		add_filter( 'wcpay_dev_mode', '__return_true' );

		$this->assertTrue( $sut->is_test_mode_enabled() );
		$this->assertSame( 'test', $sut->get_mode() );
		$this->assertSame( 'pk_test_123', $sut->get_publishable_key() );
	}

	/**
	 * @testdox Should fetch the onboarding fields once and store them in the client's database cache shape, locale marker included.
	 */
	public function test_onboarding_fields_data_is_fetched_once_and_stored_in_the_client_cache_shape(): void {
		$api_client = $this->create_counting_fields_api_client( $this->get_onboarding_fields_payload() );
		$expected   = array_merge( $this->get_onboarding_fields_payload(), array( '__locale' => 'en_US' ) );

		$first  = $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );
		$stored = get_option( 'wcpay_onboarding_fields_data' );
		$second = $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );

		$this->assertSame( 1, $api_client->calls, 'The second read, in a new request, must come from the cache.' );
		$this->assertSame( $expected, $first );
		$this->assertSame( $expected, $second );
		$this->assertIsArray( $stored );
		$this->assertEqualsWithDelta( time(), $stored['fetched'], 5 );
		$this->assertSame(
			array(
				'data'               => $expected,
				'fetched'            => $stored['fetched'],
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			$stored,
			'Client 11.1.0 Database_Cache::write_to_cache() writes exactly these keys, in this order (class-database-cache.php:378-382), with __locale inside data (class-wc-payments-onboarding-service.php:161).'
		);
		$this->assertOptionNotAutoloaded( 'wcpay_onboarding_fields_data' );
	}

	/**
	 * @testdox Should keep cached onboarding fields for a week and refetch them after that.
	 * @testWith [6, 0]
	 *           [8, 1]
	 *
	 * @param int $age_in_days    Age of the cached entry in days.
	 * @param int $expected_calls Expected platform fetches.
	 */
	public function test_onboarding_fields_data_is_cached_for_a_week( int $age_in_days, int $expected_calls ): void {
		$this->store_onboarding_fields_cache( 'en_US', time() - ( $age_in_days * DAY_IN_SECONDS ) );
		$api_client = $this->create_counting_fields_api_client( array( 'business_types' => array() ) );

		$this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );

		$this->assertSame( $expected_calls, $api_client->calls );
	}

	/**
	 * @testdox Should refetch the onboarding fields when a different locale is requested.
	 */
	public function test_onboarding_fields_data_refetches_for_a_different_locale(): void {
		$this->store_onboarding_fields_cache( 'en_US', time() );
		$api_client = $this->create_counting_fields_api_client( $this->get_onboarding_fields_payload() );

		$result = $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'fr_FR' );

		$this->assertSame( 1, $api_client->calls );
		$this->assertSame( 'fr_FR', $result['__locale'] );
		$this->assertSame( 'fr_FR', get_option( 'wcpay_onboarding_fields_data' )['data']['__locale'] );
	}

	/**
	 * @testdox Should cache an empty onboarding fields response as valid data.
	 */
	public function test_onboarding_fields_data_caches_an_empty_response(): void {
		$api_client = $this->create_counting_fields_api_client( array() );

		$first  = $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );
		$second = $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );

		$this->assertSame( array( '__locale' => 'en_US' ), $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $api_client->calls, 'Client 11.1.0 treats only null or false as an error (class-database-cache.php:169).' );
		$this->assertFalse( get_option( 'wcpay_onboarding_fields_data' )['errored'] );
	}

	/**
	 * @testdox Should keep the old onboarding fields on a failed refetch and back off before trying again.
	 */
	public function test_onboarding_fields_data_failure_keeps_old_data_and_backs_off(): void {
		$cached     = $this->store_onboarding_fields_cache( 'en_US', time() - ( 8 * DAY_IN_SECONDS ) );
		$api_client = $this->create_counting_fields_api_client( null );

		$this->assertSame( $cached, $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' ) );
		$stored = get_option( 'wcpay_onboarding_fields_data' );
		$this->assertSame( $cached, $stored['data'] );
		$this->assertTrue( $stored['errored'] );
		$this->assertSame( 1, $stored['consecutive_errors'] );

		$this->assertSame( $cached, $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' ) );
		$this->assertSame( 1, $api_client->calls, 'The first error backs off for 2 minutes (class-database-cache.php:466-468, 520-531).' );

		$stored['fetched'] = time() - ( 3 * MINUTE_IN_SECONDS );
		update_option( 'wcpay_onboarding_fields_data', $stored );
		$this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'en_US' );

		$this->assertSame( 2, $api_client->calls );
		$this->assertSame( 2, get_option( 'wcpay_onboarding_fields_data' )['consecutive_errors'] );
	}

	/**
	 * @testdox Should serve cached onboarding fields without fetching during ajax requests and Action Scheduler jobs.
	 * @testWith ["ajax"]
	 *           ["action_scheduler"]
	 *
	 * @param string $context Request context that must not refresh.
	 */
	public function test_onboarding_fields_data_is_not_refreshed_during_ajax_or_action_scheduler_jobs( string $context ): void {
		$cached     = $this->store_onboarding_fields_cache( 'en_US', time() - ( 8 * DAY_IN_SECONDS ) );
		$api_client = $this->create_counting_fields_api_client( $this->get_onboarding_fields_payload() );
		$sut        = $this->create_service_with_api_client( $api_client );
		if ( 'ajax' === $context ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		} else {
			$sut->disable_refresh();
		}

		$this->assertSame( $cached, $sut->get_onboarding_fields_data( 'en_US' ) );
		$this->assertSame( 0, $api_client->calls );
	}

	/**
	 * @testdox Should serve whatever onboarding fields are cached, regardless of expiry or locale, without a platform connection.
	 */
	public function test_onboarding_fields_data_serves_the_cache_without_a_platform_connection(): void {
		$cached       = $this->store_onboarding_fields_cache( 'en_US', time() - ( 30 * DAY_IN_SECONDS ) );
		$cache_before = get_option( 'wcpay_onboarding_fields_data' );
		$api_client   = $this->create_counting_fields_api_client( $this->get_onboarding_fields_payload(), false );

		$this->assertSame( $cached, $this->create_service_with_api_client( $api_client )->get_onboarding_fields_data( 'fr_FR' ), 'Client 11.1.0 reads Database_Cache::get( key, true ) when not connected (class-wc-payments-onboarding-service.php:145-147).' );
		$this->assertSame( 0, $api_client->calls );
		$this->assertSame( $cache_before, get_option( 'wcpay_onboarding_fields_data' ) );
	}

	/**
	 * @testdox Should drop the cached onboarding fields when WooCommerce updates, so the next read fetches.
	 */
	public function test_woocommerce_update_drops_the_onboarding_fields_cache(): void {
		$this->store_onboarding_fields_cache( 'en_US', time() );
		$api_client = $this->create_counting_fields_api_client( $this->get_onboarding_fields_payload() );
		$sut        = $this->create_service_with_api_client( $api_client );
		$sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'woocommerce_updated' );

		$this->assertFalse( get_option( 'wcpay_onboarding_fields_data' ), 'Client 11.1.0 clears this cache on its own update (class-wc-payments-onboarding-service.php:127, 967-970).' );
		$sut->get_onboarding_fields_data( 'en_US' );
		$this->assertSame( 1, $api_client->calls );
	}

	/**
	 * Create the service under test for a connected store whose account fetches fail, so reads serve the seeded cache.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_service(): WooPaymentsAccountService {
		return $this->create_service_with_api_client( $this->create_recorded_account_api_client( array(), true ) );
	}

	/**
	 * Load the recorded full account of a new test-drive account (REC-T60-2).
	 *
	 * @return array<string,mixed>
	 */
	private function load_recorded_test_drive_account(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/rec-t60-test-drive-account.json' ), true );

		return $fixture['account'];
	}

	/**
	 * The partial record native onboarding writes from a test-drive init response.
	 *
	 * @return array<string,mixed>
	 */
	private function get_partial_onboarding_record(): array {
		return array(
			'account_id'           => 'acct_1UL3bQJQsm0lol5W',
			'test_publishable_key' => 'pk_test_partial',
			'is_live'              => false,
			'is_test_drive'        => true,
			'payments_enabled'     => true,
			'details_submitted'    => true,
		);
	}

	/**
	 * Create a transport double that returns a recorded account or fails, and counts fetches.
	 *
	 * @param array<string,mixed> $account Account to return.
	 * @param bool                $fail    Whether every fetch fails.
	 * @return WooPaymentsApiClient
	 */
	private function create_recorded_account_api_client( array $account, bool $fail ): WooPaymentsApiClient {
		return new class( $account, $fail ) extends WooPaymentsApiClient {
			/**
			 * Number of account fetch attempts.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Account to return.
			 *
			 * @var array<string,mixed>
			 */
			private array $account;

			/**
			 * Whether every fetch fails.
			 *
			 * @var bool
			 */
			private bool $fail;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account Account to return.
			 * @param bool                $fail    Whether every fetch fails.
			 */
			public function __construct( array $account, bool $fail ) {
				$this->account = $account;
				$this->fail    = $fail;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the recorded account or fail.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When set to fail.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				++$this->calls;
				if ( $this->fail ) {
					throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
				}

				return $this->account;
			}
		};
	}

	/**
	 * Create the service under test with a fake native API client.
	 *
	 * @param WooPaymentsApiClient $api_client Fake API client.
	 * @return WooPaymentsAccountService
	 */
	private function create_service_with_api_client( WooPaymentsApiClient $api_client ): WooPaymentsAccountService {
		$sut = new class( $api_client ) extends WooPaymentsAccountService {
			/**
			 * Fake native API client.
			 *
			 * @var WooPaymentsApiClient
			 */
			private WooPaymentsApiClient $api_client;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiClient $api_client Fake API client.
			 */
			public function __construct( WooPaymentsApiClient $api_client ) {
				$this->api_client = $api_client;
			}

			/**
			 * Get the fake native API client.
			 *
			 * @return WooPaymentsApiClient|null
			 */
			protected function get_api_client(): ?WooPaymentsApiClient {
				return $this->api_client;
			}
		};
		$sut->init( new LegacyProxy() );

		return $sut;
	}

	/**
	 * Create a fake account API client that counts account fetches.
	 *
	 * @param array<string,mixed> $account_data Account payload.
	 * @param bool                $available    Whether the site has a platform connection.
	 * @return WooPaymentsApiClient
	 */
	private function create_counting_account_api_client( array $account_data, bool $available = true ): WooPaymentsApiClient {
		return new class( $account_data, $available ) extends WooPaymentsApiClient {
			/**
			 * Account payload.
			 *
			 * @var array<string,mixed>
			 */
			private array $account_data;

			/**
			 * Whether the site has a platform connection.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Number of account fetches.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account_data Account payload.
			 * @param bool                $available    Whether the site has a platform connection.
			 */
			public function __construct( array $account_data, bool $available ) {
				$this->account_data = $account_data;
				$this->available    = $available;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Return the fake account payload.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				++$this->calls;

				return $this->account_data;
			}
		};
	}

	/**
	 * Create the service under test as WooCommerce builds it while plugins load.
	 *
	 * WordPress defines get_userdata() in pluggable.php only after plugins load. Until then Jetpack's connection-owner
	 * check throws, so the API client reads as unavailable.
	 *
	 * @param WooPaymentsApiClient $api_client Fake API client that reads as unavailable.
	 * @return WooPaymentsAccountService
	 */
	private function create_service_while_plugins_load( WooPaymentsApiClient $api_client ): WooPaymentsAccountService {
		$this->register_legacy_proxy_function_mocks(
			array(
				'function_exists' => static fn( string $name ): bool => 'get_userdata' !== $name && function_exists( $name ),
			)
		);
		$sut = $this->create_service_with_api_client( $api_client );
		$sut->init( wc_get_container()->get( LegacyProxy::class ) );

		return $sut;
	}

	/**
	 * Get a valid live account payload for cache tests.
	 *
	 * @param array<string,mixed> $overrides Account overrides.
	 * @return array<string,mixed>
	 */
	private function get_valid_live_account_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'account_id'           => 'acct_123',
				'live_publishable_key' => 'pk_live_123',
				'is_live'              => true,
				'payments_enabled'     => true,
				'details_submitted'    => true,
			),
			$overrides
		);
	}

	/**
	 * Store a valid account cache fixture for the current blog.
	 *
	 * @param string $account_id Account ID.
	 */
	private function store_account_cache_fixture( string $account_id ): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $this->get_valid_live_account_payload( array( 'account_id' => $account_id ) ),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
	}

	/**
	 * Get preserved WooPayments database cache keys cleared on account reset.
	 *
	 * @return string[]
	 */
	private function get_preserved_database_cache_keys(): array {
		return array(
			'wcpay_account_data',
			'wcpay_address_autocomplete_jwt',
			'wcpay_onboarding_fields_data',
			'wcpay_business_types_data',
			'wcpay_fraud_services_data',
			'wcpay_recommended_payment_methods',
			'wcpay_dispute_status_counts_cache',
			'wcpay_test_dispute_status_counts_cache',
			'wcpay_active_dispute_cache',
			'wcpay_authorization_summary_cache',
			'wcpay_test_authorization_summary_cache',
			'wcpay_connect_incentive',
			'wcpay_tracking_info_cache',
		);
	}

	/**
	 * Assert that a WordPress option is flagged for autoload.
	 *
	 * Reads the raw autoload column to stay robust across WordPress versions:
	 * pre-6.6 stores 'yes' while 6.6+ stores 'on' for explicitly autoloaded options.
	 *
	 * @param string $option_name The option name to inspect.
	 * @return void
	 */
	private function assertOptionAutoloaded( string $option_name ): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		$this->assertContains( $autoload, array( 'yes', 'on' ), sprintf( 'Option %s should be autoloaded, got autoload value "%s".', $option_name, (string) $autoload ) );
	}

	/**
	 * Get an onboarding fields payload as the platform returns it.
	 *
	 * @return array<string,mixed>
	 */
	private function get_onboarding_fields_payload(): array {
		return array(
			'business_types'    => array(
				array(
					'key'   => 'NZ',
					'name'  => 'New Zealand',
					'types' => array(
						array(
							'key'        => 'company',
							'name'       => 'Company',
							'structures' => array(),
						),
					),
				),
			),
			'mccs_display_tree' => array(),
		);
	}

	/**
	 * Store a successful onboarding fields cache entry in the client's shape.
	 *
	 * @param string $locale  Locale stored with the data.
	 * @param int    $fetched Fetch timestamp.
	 * @return array<string,mixed> The cached data.
	 */
	private function store_onboarding_fields_cache( string $locale, int $fetched ): array {
		$data = array(
			'business_types' => array( array( 'key' => 'cached' ) ),
			'__locale'       => $locale,
		);
		update_option(
			'wcpay_onboarding_fields_data',
			array(
				'data'               => $data,
				'fetched'            => $fetched,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);

		return $data;
	}

	/**
	 * Create a fake API client that counts onboarding fields fetches.
	 *
	 * @param array<string,mixed>|null $fields_data Fields payload, or null to fail every fetch.
	 * @param bool                     $available   Whether the site has a platform connection.
	 * @return WooPaymentsApiClient
	 */
	private function create_counting_fields_api_client( ?array $fields_data, bool $available = true ): WooPaymentsApiClient {
		return new class( $fields_data, $available ) extends WooPaymentsApiClient {
			/**
			 * Fields payload, or null to fail.
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $fields_data;

			/**
			 * Whether the site has a platform connection.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Number of onboarding fields fetches.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|null $fields_data Fields payload, or null to fail.
			 * @param bool                     $available   Whether the site has a platform connection.
			 */
			public function __construct( ?array $fields_data, bool $available ) {
				$this->fields_data = $fields_data;
				$this->available   = $available;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Return the fields payload or fail.
			 *
			 * @param string $locale User locale.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When set to fail.
			 */
			public function get_onboarding_fields_data( string $locale = '' ): array {
				unset( $locale );
				++$this->calls;
				if ( null === $this->fields_data ) {
					throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
				}

				return $this->fields_data;
			}
		};
	}

	/**
	 * Assert that a WordPress option is not flagged for autoload.
	 *
	 * @param string $option_name The option name to inspect.
	 * @return void
	 */
	private function assertOptionNotAutoloaded( string $option_name ): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), sprintf( 'Option %s should not be autoloaded, got autoload value "%s".', $option_name, (string) $autoload ) );
	}
}
