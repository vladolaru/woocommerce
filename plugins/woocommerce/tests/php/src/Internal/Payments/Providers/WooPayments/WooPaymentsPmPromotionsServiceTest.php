<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPmPromotionsService class.
 */
class WooPaymentsPmPromotionsServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * System under test.
	 *
	 * @var WooPaymentsPmPromotionsService
	 */
	private WooPaymentsPmPromotionsService $sut;

	/**
	 * Recording native API client.
	 *
	 * @var RecordingPmPromotionsApiClient
	 */
	private RecordingPmPromotionsApiClient $api_client;

	/**
	 * Recording native account service.
	 *
	 * @var RecordingPmPromotionsAccountService
	 */
	private RecordingPmPromotionsAccountService $account_service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client      = new RecordingPmPromotionsApiClient();
		$this->account_service = new RecordingPmPromotionsAccountService();
		$this->sut             = new WooPaymentsPmPromotionsService();
		$this->sut->init(
			$this->api_client,
			$this->account_service,
			wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class ),
			wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class )
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( '_wcpay_pm_promotion_dismissals' );
		delete_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY );

		parent::tearDown();
	}

	/**
	 * @testdox Should filter invalid, enabled, dismissed, discounted, and duplicate promotion groups.
	 */
	public function test_get_visible_promotions_filters_invalid_enabled_dismissed_discounted_and_duplicate_promo_ids(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		update_option(
			'_wcpay_pm_promotion_dismissals',
			array(
				'klarna-dismissed__spotlight' => time() - HOUR_IN_SECONDS,
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'              => array(),
				'klarna'            => array(),
				'afterpay_clearpay' => array(),
				'affirm'            => array(
					'discount' => array(
						array( 'discount' => array( 'percentage' => 50 ) ),
					),
				),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'card-promo__spotlight', 'card-promo', 'card', 'spotlight' ),
			$this->promotion_fixture( 'unknown-promo__spotlight', 'unknown-promo', 'unknown_method', 'spotlight' ),
			$this->promotion_fixture( 'klarna-dismissed__spotlight', 'klarna-dismissed', 'klarna', 'spotlight' ),
			$this->promotion_fixture( 'affirm-discount__spotlight', 'affirm-discount', 'affirm', 'spotlight' ),
			$this->promotion_fixture( 'afterpay-first__spotlight', 'afterpay-first', 'afterpay_clearpay', 'spotlight' ),
			$this->promotion_fixture( 'afterpay-second__badge', 'afterpay-second', 'afterpay_clearpay', 'badge' ),
			$this->promotion_fixture( 'afterpay-first__badge', 'afterpay-first', 'afterpay_clearpay', 'badge' ),
		);

		$promotions = $this->sut->get_visible_promotions();

		$this->assertIsArray( $promotions );
		$this->assertSame(
			array( 'afterpay-first__spotlight', 'afterpay-first__badge' ),
			array_column( $promotions, 'id' )
		);
	}

	/**
	 * @testdox Should promote only payment methods the store offers: $scenario (the WooPayments extension `get_valid_payment_method_ids()` is `get_upe_available_payment_methods()`, `class-wc-payments-pm-promotions-service.php:686-697`).
	 * @testWith ["a method the availability filter removes", "klarna", "1", true]
	 *           ["Amazon Pay while its feature is off", "amazon_pay", "0", false]
	 *           ["JCB, which is not a registered payment method", "jcb", "1", false]
	 *
	 * @param string $scenario          Scenario description.
	 * @param string $payment_method_id Payment method the promotion is for, which has account fees.
	 * @param string $amazon_pay_flag   Amazon Pay feature flag option value.
	 * @param bool   $filter_it_out     Whether the availability filter removes the method.
	 */
	public function test_get_visible_promotions_skips_methods_the_store_does_not_offer( string $scenario, string $payment_method_id, string $amazon_pay_flag, bool $filter_it_out ): void {
		unset( $scenario );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		update_option( '_wcpay_feature_amazon_pay', $amazon_pay_flag );
		$filter = static fn( array $ids ): array => array_values( array_diff( $ids, array( $payment_method_id ) ) );
		if ( $filter_it_out ) {
			add_filter( 'wcpay_upe_available_payment_methods', $filter );
		}
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'             => array(),
				'bancontact'       => array(),
				$payment_method_id => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'bancontact-promo__spotlight', 'bancontact-promo', 'bancontact', 'spotlight' ),
			$this->promotion_fixture( 'other-promo__spotlight', 'other-promo', $payment_method_id, 'spotlight' ),
		);

		try {
			$promotions = $this->sut->get_visible_promotions();
		} finally {
			remove_filter( 'wcpay_upe_available_payment_methods', $filter );
			delete_option( '_wcpay_feature_amazon_pay' );
		}

		$this->assertSame( array( 'bancontact-promo__spotlight' ), array_column( (array) $promotions, 'id' ) );
	}

	/**
	 * The platform's cache-for header sets the promotions cache lifetime, and 0 drops the cache, as in client 11.1.0
	 * (includes/class-wc-payments-pm-promotions-service.php:224-254).
	 *
	 * @testdox Should cache promotions for the platform's cache-for lifetime and drop the cache when it is 0.
	 * @testWith ["0", null]
	 *           ["600", 600]
	 *           ["", 86400]
	 *
	 * @param string   $cache_for Platform cache-for header.
	 * @param int|null $lifetime  Expected transient lifetime in seconds, or null when nothing is cached.
	 */
	public function test_promotions_cache_follows_the_cache_for_header( string $cache_for, ?int $lifetime ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		$this->account_service->cached_account_data = array( 'fees' => array( 'klarna' => array() ) );
		set_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY, array( 'context_hash' => 'stale' ), DAY_IN_SECONDS );
		$this->api_client->promotions_response = array( $this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ) );
		$this->api_client->cache_for           = $cache_for;
		$before                                = time();

		$promotions = $this->sut->get_visible_promotions();

		$this->assertSame( array( 'klarna-promo__spotlight' ), array_column( (array) $promotions, 'id' ) );
		$timeout = (int) get_option( '_transient_timeout_' . WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY, 0 );
		if ( null === $lifetime ) {
			$this->assertFalse( get_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY ), 'A 0 lifetime leaves nothing cached, not even the older entry.' );
		} else {
			$this->assertGreaterThanOrEqual( $before + $lifetime, $timeout );
			$this->assertLessThanOrEqual( time() + $lifetime, $timeout );
		}
	}

	/**
	 * @testdox Should normalize titles, CTA labels, terms labels, badge type, URLs, and light HTML.
	 */
	public function test_get_visible_promotions_normalizes_titles_cta_terms_badge_type_and_html(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			array(
				'id'             => 'klarna-promo__spotlight',
				'promo_id'       => 'klarna-promo',
				'payment_method' => 'klarna',
				'type'           => 'spotlight',
				'title'          => 'Activate <Klarna>',
				'description'    => '<strong>Flexible payments</strong><script>bad()</script>',
				'tc_url'         => 'https://example.com/terms',
				'image'          => 'https://example.com/image.png',
				'footnote'       => '<em>Limited time</em><script>bad()</script>',
				'badge_type'     => 'not-real',
			),
		);

		$promotions = $this->sut->get_visible_promotions();

		$this->assertIsArray( $promotions );
		$this->assertSame( 'Klarna', $promotions[0]['payment_method_title'] );
		$this->assertSame( 'Enable Klarna', $promotions[0]['cta_label'] );
		$this->assertSame( 'See terms', $promotions[0]['tc_label'] );
		$this->assertSame( 'success', $promotions[0]['badge_type'] );
		$this->assertSame( 'https://example.com/terms', $promotions[0]['tc_url'] );
		$this->assertSame( 'https://example.com/image.png', $promotions[0]['image'] );
		$this->assertStringContainsString( '<strong>Flexible payments</strong>', $promotions[0]['description'] );
		$this->assertStringNotContainsString( '<script>', $promotions[0]['description'] );
		$this->assertStringContainsString( '<em>Limited time</em>', $promotions[0]['footnote'] );
		$this->assertStringNotContainsString( '<script>', $promotions[0]['footnote'] );
	}

	/**
	 * @testdox Should store dismissals and hide dismissed promotions.
	 */
	public function test_dismiss_promotion_stores_timestamp_and_hides_promotion(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);
		$before                                     = time();

		$result     = false;
		$recorded   = $this->record_events_with_tracking_info(
			function () use ( &$result ): void {
				$result = $this->sut->dismiss_promotion( 'klarna-promo__spotlight' );
			}
		);
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals' );

		$this->assertTrue( $result );
		// Client 11.1.0 records the dismissal through its tracks_event() (pm-promotions-service.php:570, :1015).
		$this->assertSame( 'fixture-host', $recorded['wcadmin_wcpay_payment_method_promotion_dismissed']['hosting_provider'] ?? null );
		$this->assertIsArray( $dismissals );
		$this->assertGreaterThanOrEqual( $before, $dismissals['klarna-promo__spotlight'] );
		$this->assertLessThanOrEqual( time(), $dismissals['klarna-promo__spotlight'] );
		$this->assertNull( $this->sut->get_visible_promotions() );
	}

	/**
	 * Client 11.1.0's promotions tracks_event() merges get_tracking_info() last into every promotion event
	 * (includes/class-wc-payments-pm-promotions-service.php:1015-1025).
	 *
	 * @testdox Should send the platform tracking info with the promotion events.
	 */
	public function test_promotion_events_carry_the_platform_tracking_info(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		// The platform's account answer (wpcom class-accounts-controller.php:1129-1166), reduced to the fees the promotion code reads:
		// an entry per method, empty because no method carries a discount here.
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$recorded = $this->record_events_with_tracking_info(
			function (): void {
				$this->sut->activate_promotion( 'klarna-promo__spotlight' );
			}
		);

		$this->assertSame( 'fixture-host', $recorded['wcadmin_wcpay_payment_method_promotion_activated']['hosting_provider'] ?? null );
		$this->assertSame( 'fixture-host', $recorded['wcadmin_wcpay_payment_method_enabled']['hosting_provider'] ?? null );
	}

	/**
	 * Activation enables the method's own gateway, as client 11.1.0 `activate_promotion()` and
	 * `enable_payment_method_gateway()` do (includes/class-wc-payments-pm-promotions-service.php:272-320,384-462).
	 *
	 * @testdox Should activate through the platform, dismiss, enable the method's gateway, and clear caches.
	 */
	public function test_activate_promotion_calls_platform_marks_dismissed_enables_method_and_clears_cache(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		update_option(
			'woocommerce_woocommerce_payments_affirm_settings',
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		set_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY, array( 'stale' => true ), DAY_IN_SECONDS );
		$this->api_client->promotions_response = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$result     = false;
		$events     = $this->capture_tracks_events(
			function () use ( &$result ): void {
				$result = $this->sut->activate_promotion( 'klarna-promo__spotlight' );
			}
		);
		$settings   = get_option( 'woocommerce_woocommerce_payments_settings' );
		$klarna     = get_option( 'woocommerce_woocommerce_payments_klarna_settings' );
		$affirm     = get_option( 'woocommerce_woocommerce_payments_affirm_settings' );
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals' );

		$this->assertTrue( $result );
		$this->assertSame( array( 'klarna-promo__spotlight' ), $this->api_client->activated_promotion_ids );
		$this->assertIsArray( $dismissals );
		$this->assertArrayHasKey( 'klarna-promo__spotlight', $dismissals );
		$this->assertIsArray( $settings );
		$this->assertSame( array( 'card', 'klarna' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertIsArray( $klarna );
		$this->assertSame( 'yes', $klarna['enabled'], 'Checkout offers Klarna only when its own gateway row is enabled.' );
		$this->assertSame( array( 'card', 'klarna' ), $klarna['upe_enabled_payment_method_ids'] );
		$this->assertIsArray( $affirm );
		$this->assertSame( array( 'card', 'klarna' ), $affirm['upe_enabled_payment_method_ids'], 'Every gateway row lists the enabled method.' );
		$this->assertSame(
			array(
				array(
					'payment_method_id' => 'klarna',
					'capability_id'     => 'klarna_payments',
					'promo_id'          => 'klarna-promo',
				),
			),
			$events['wcadmin_wcpay_payment_method_enabled'] ?? array()
		);
		$this->assertFalse( get_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY ) );
		$this->assertSame( 1, $this->account_service->clear_cache_calls );
	}

	/**
	 * @testdox Should list only the payment methods the settings accept when activation adds the promoted method.
	 */
	public function test_activate_promotion_keeps_only_settings_payment_methods_in_the_list(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'not_a_payment_method' ) ) );
		// Reduced from Fixtures/platform-test-drive-account.json account.fees (:196; card :203, klarna :311): only the fee
		// keys are read, so the fee objects are empty.
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$this->assertTrue( $this->sut->activate_promotion( 'klarna-promo__spotlight' ) );

		$this->assertSame( array( 'card', 'klarna' ), get_option( 'woocommerce_woocommerce_payments_settings' )['upe_enabled_payment_method_ids'] );
	}

	/**
	 * Client 11.1.0 checks the gateway reads enabled after `enable()` and logs a warning when it does not
	 * (includes/class-wc-payments-pm-promotions-service.php:398-402,426-433).
	 *
	 * @testdox Should fail activation, warn, and record no enabled event when the method's gateway row does not persist.
	 */
	public function test_activate_promotion_fails_when_the_method_gateway_row_does_not_persist(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);
		add_filter( 'pre_update_option_woocommerce_woocommerce_payments_klarna_settings', static fn( $value, $old_value ) => $old_value, 10, 2 );
		$logger = RecordingWcLogger::install();

		$result     = true;
		$events     = $this->capture_tracks_events(
			function () use ( &$result ): void {
				$result = $this->sut->activate_promotion( 'klarna-promo__spotlight' );
			}
		);
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals' );

		$this->assertFalse( $result );
		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 'woopayments' === $line[2] ) );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'Failed to enable payment method klarna', $warnings[0][1] );
		$this->assertArrayNotHasKey( 'wcadmin_wcpay_payment_method_enabled', $events );
		$this->assertIsArray( $dismissals );
		$this->assertArrayHasKey( 'klarna-promo__spotlight', $dismissals, 'The promotion is dismissed before its gateway is enabled.' );
		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );
		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'], 'A refused gateway row leaves the shared list as it was.' );
	}

	/**
	 * Client 11.1.0 enables and checks the method's gateway first, then syncs the lists and only logs a list write that
	 * fails (class-wc-payments-pm-promotions-service.php:384-418, :440-462).
	 *
	 * @testdox Activation succeeds with the method's gateway enabled and logs a shared list write that fails.
	 */
	public function test_activate_promotion_logs_a_failed_list_sync_and_succeeds(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);
		add_filter( 'pre_update_option_woocommerce_woocommerce_payments_settings', static fn( $value, $old_value ) => $old_value, 10, 2 );
		$logger = RecordingWcLogger::install();

		$result = false;
		$events = $this->capture_tracks_events(
			function () use ( &$result ): void {
				$result = $this->sut->activate_promotion( 'klarna-promo__spotlight' );
			}
		);

		$this->assertTrue( $result );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_klarna_settings' )['enabled'] ?? null );
		$this->assertArrayHasKey( 'wcadmin_wcpay_payment_method_enabled', $events );
		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 'woopayments' === $line[2] ) );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'Failed to sync payment method klarna to the gateway settings: woocommerce_woocommerce_payments_settings', $warnings[0][1] );
	}

	/**
	 * @testdox A platform error fetching promotions is logged with its status and code, never its message.
	 */
	public function test_promotions_fetch_failure_log_leaves_out_platform_text(): void {
		$this->account_service->cached_account_data = array( 'fees' => array( 'klarna' => array() ) );
		$this->api_client->promotions_exception     = self::make_provider_error();
		$logger                                     = RecordingWcLogger::install();
		self::enable_woopayments_debug_logging();

		$this->sut->get_visible_promotions();

		$context = $this->get_logged_context_at_level( $logger, 'warning' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error activating a promotion is logged with its status and code, never its message.
	 */
	public function test_promotion_activation_failure_log_leaves_out_platform_text(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);
		$this->api_client->activation_exception     = self::make_provider_error();
		$logger                                     = RecordingWcLogger::install();
		self::enable_woopayments_debug_logging();

		$result   = true;
		$recorded = $this->record_events_with_tracking_info(
			function () use ( &$result ): void {
				$result = $this->sut->activate_promotion( 'klarna-promo__spotlight' );
			}
		);

		$this->assertFalse( $result );
		// Client 11.1.0 records the failure through its tracks_event(), which merges the tracking info (pm-promotions-service.php:540, :1015).
		$this->assertSame( 'fixture-host', $recorded['wcadmin_wcpay_payment_method_promotion_activation_failed']['hosting_provider'] ?? null );
		$context = $this->get_logged_context_at_level( $logger, 'warning' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$warnings = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && 'woopayments' === $line[2] ) );
		$this->assertStringContainsString( 'klarna', $warnings[0][1], 'The line names the payment method whose promotion failed.' );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Should activate settings-save promotions before the method is enabled.
	 */
	public function test_maybe_activate_promotion_for_payment_method_runs_before_settings_enable(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$result     = false;
		$recorded   = $this->record_events_with_tracking_info(
			function () use ( &$result ): void {
				$result = $this->sut->maybe_activate_promotion_for_payment_method( 'klarna' );
			}
		);
		$settings   = get_option( 'woocommerce_woocommerce_payments_settings' );
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals', array() );

		$this->assertTrue( $result );
		// Client 11.1.0 records the settings-driven activation through its tracks_event() (pm-promotions-service.php:512, :1015).
		$this->assertSame( 'fixture-host', $recorded['wcadmin_wcpay_payment_method_promotion_activated']['hosting_provider'] ?? null );
		$this->assertSame( array( 'klarna-promo__spotlight' ), $this->api_client->activated_promotion_ids );
		$this->assertIsArray( $settings );
		$this->assertSame( array( 'card' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertSame( array(), $dismissals );
		$this->assertSame( 1, $this->account_service->clear_cache_calls );
	}

	/**
	 * @testdox Should hide and refuse activation for promotions unavailable to the account.
	 */
	public function test_promotions_for_unavailable_payment_methods_are_hidden_and_not_activated(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);
		$this->account_service->cached_account_data = array(
			'fees' => array(
				'card' => array(),
			),
		);
		$this->api_client->promotions_response      = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$this->assertNull( $this->sut->get_visible_promotions() );
		$this->assertFalse( $this->sut->activate_promotion( 'klarna-promo__spotlight' ) );
		$this->assertSame( array(), $this->api_client->activated_promotion_ids );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_klarna_settings' ) );
	}

	/**
	 * @testdox Should not expose promotions to users without manage_woocommerce.
	 */
	public function test_get_visible_promotions_returns_null_without_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->api_client->promotions_response = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$this->assertNull( $this->sut->get_visible_promotions() );
		$this->assertSame( 0, $this->api_client->get_pm_promotions_calls );
	}

	/**
	 * Run an action with the fixed platform tracking info installed and return the Tracks events it recorded, by name.
	 *
	 * @param callable $act Action.
	 * @return array<string,array<string,mixed>>
	 */
	private function record_events_with_tracking_info( callable $act ): array {
		FixedTrackingInfoService::install();
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		$capture  = static function ( $properties, $event_name ) use ( &$recorded ) {
			$recorded[ $event_name ] = $properties;
			return $properties;
		};
		add_filter( 'woocommerce_tracks_event_properties', $capture, 10, 2 );

		try {
			$act();
		} finally {
			remove_filter( 'woocommerce_tracks_event_properties', $capture, 10 );
			wc_get_container()->reset_all_replacements();
		}

		return $recorded;
	}

	/**
	 * Record the Tracks events a callback sends, by event name, with the properties these tests read.
	 *
	 * @param callable $act Code under test.
	 * @return array<string,array<int,array<string,string|null>>>
	 */
	private function capture_tracks_events( callable $act ): array {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$events  = array();
		$capture = static function ( $preempt, $parsed_args, $url ) use ( &$events ) {
			unset( $preempt, $parsed_args );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $pixel );
			if ( isset( $pixel['_en'] ) ) {
				$events[ (string) $pixel['_en'] ][] = array(
					'payment_method_id' => $pixel['payment_method_id'] ?? null,
					'capability_id'     => $pixel['capability_id'] ?? null,
					'promo_id'          => $pixel['promo_id'] ?? null,
				);
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		try {
			$act();
		} finally {
			remove_filter( 'pre_http_request', $capture, 10 );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return $events;
	}

	/**
	 * Build a valid promotion fixture.
	 *
	 * Shape: the flat promotion the platform's `GET payment_method_promotions` returns, built by
	 * Payment_Method_Promotion_Service::build_flat_promotion() (the platform's class-payment-method-promotion-service.php:499-534):
	 * the required fields plus the optional footnote.
	 *
	 * @param string $id                Promotion ID.
	 * @param string $promo_id          Promotion group ID.
	 * @param string $payment_method_id Payment method ID.
	 * @param string $type              Promotion type.
	 * @return array<string,mixed>
	 */
	private function promotion_fixture( string $id, string $promo_id, string $payment_method_id, string $type ): array {
		return array(
			'id'                   => $id,
			'promo_id'             => $promo_id,
			'payment_method'       => $payment_method_id,
			'payment_method_title' => ucfirst( $payment_method_id ),
			'type'                 => $type,
			'title'                => 'Activate ' . $payment_method_id,
			'description'          => 'Offer flexible payments.',
			'cta_label'            => 'Enable ' . ucfirst( $payment_method_id ),
			'tc_url'               => 'https://example.com/terms',
			'tc_label'             => 'See terms',
			'footnote'             => 'Limited time offer.',
		);
	}
}
