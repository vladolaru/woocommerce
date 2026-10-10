<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

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
		$this->sut->init( $this->api_client, $this->account_service );

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
	 * @testdox Should promote only payment methods the store offers: $scenario (client 11.1.0 `get_valid_payment_method_ids()` is `get_upe_available_payment_methods()`, `class-wc-payments-pm-promotions-service.php:686-697`).
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
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json `account.fees`.
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

		$result     = $this->sut->dismiss_promotion( 'klarna-promo__spotlight' );
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals' );

		$this->assertTrue( $result );
		$this->assertIsArray( $dismissals );
		$this->assertGreaterThanOrEqual( $before, $dismissals['klarna-promo__spotlight'] );
		$this->assertLessThanOrEqual( time(), $dismissals['klarna-promo__spotlight'] );
		$this->assertNull( $this->sut->get_visible_promotions() );
	}

	/**
	 * @testdox Should activate through the platform, dismiss, enable the method, and clear caches.
	 */
	public function test_activate_promotion_calls_platform_marks_dismissed_enables_method_and_clears_cache(): void {
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
		set_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY, array( 'stale' => true ), DAY_IN_SECONDS );
		$this->api_client->promotions_response = array(
			$this->promotion_fixture( 'klarna-promo__spotlight', 'klarna-promo', 'klarna', 'spotlight' ),
		);

		$result     = $this->sut->activate_promotion( 'klarna-promo__spotlight' );
		$settings   = get_option( 'woocommerce_woocommerce_payments_settings' );
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals' );

		$this->assertTrue( $result );
		$this->assertSame( array( 'klarna-promo__spotlight' ), $this->api_client->activated_promotion_ids );
		$this->assertIsArray( $dismissals );
		$this->assertArrayHasKey( 'klarna-promo__spotlight', $dismissals );
		$this->assertIsArray( $settings );
		$this->assertSame( array( 'card', 'klarna' ), $settings['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_transient( WooPaymentsPmPromotionsService::PROMOTIONS_CACHE_KEY ) );
		$this->assertSame( 1, $this->account_service->clear_cache_calls );
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

		$context = $this->get_logged_context( $logger, 'Unable to fetch payment method promotions.' );
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

		$this->assertFalse( $this->sut->activate_promotion( 'klarna-promo__spotlight' ) );

		$context = $this->get_logged_context( $logger, 'Failed to activate promotion for payment method klarna: Platform error.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
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

		$result     = $this->sut->maybe_activate_promotion_for_payment_method( 'klarna' );
		$settings   = get_option( 'woocommerce_woocommerce_payments_settings' );
		$dismissals = get_option( '_wcpay_pm_promotion_dismissals', array() );

		$this->assertTrue( $result );
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
	 * Build a minimal valid promotion fixture.
	 *
	 * It carries the string fields client 11.1.0 requires of a platform promotion, with a spotlight or badge type
	 * (`validate_promotion()`, class-wc-payments-pm-promotions-service.php:643-655).
	 *
	 * @param string $id                Promotion ID.
	 * @param string $promo_id          Promotion group ID.
	 * @param string $payment_method_id Payment method ID.
	 * @param string $type              Promotion type.
	 * @return array<string,mixed>
	 */
	private function promotion_fixture( string $id, string $promo_id, string $payment_method_id, string $type ): array {
		return array(
			'id'             => $id,
			'promo_id'       => $promo_id,
			'payment_method' => $payment_method_id,
			'type'           => $type,
			'title'          => 'Activate ' . $payment_method_id,
			'description'    => 'Offer flexible payments.',
			'tc_url'         => 'https://example.com/terms',
		);
	}
}
