<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeaturePolicy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFeaturePolicy class.
 */
class WooPaymentsFeaturePolicyTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( '_wcpay_feature_amazon_pay' );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_feature_policy_unrelated' );
		WooPaymentsFeaturePolicy::reset_cache();
		parent::tearDown();
	}

	/** @testdox Amazon Pay policy reads account data once per request for the same account service. */
	public function test_amazon_pay_policy_is_memoized_for_the_same_account_service(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->expects( $this->once() )->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Resetting the policy cache reevaluates the same account service. */
	public function test_reset_cache_reevaluates_the_same_account_service(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->expects( $this->exactly( 2 ) )->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		WooPaymentsFeaturePolicy::reset_cache();
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox A released account service cannot share its policy decision with a new service. */
	public function test_amazon_pay_policy_does_not_reuse_a_released_account_service_decision(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$disabled_account_service = $this->create_ephemeral_account_service( true );

		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $disabled_account_service ) );
		unset( $disabled_account_service );
		gc_collect_cycles();

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->create_ephemeral_account_service( false ) ) );
	}

	/** @testdox Account refresh invalidates the policy decision for the same service. */
	public function test_account_refresh_invalidates_the_policy_decision_for_the_same_service(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturnOnConsecutiveCalls(
			array( 'ece_confirmation_tokens_disabled' => true ),
			array( 'ece_confirmation_tokens_disabled' => false )
		);

		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		do_action( 'woocommerce_payments_account_refreshed', array() );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Adding the account cache invalidates the policy decision for the same service. */
	public function test_account_cache_addition_invalidates_the_policy_decision_for_the_same_service(): void {
		delete_option( 'wcpay_account_data' );
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->create_switching_account_service();

		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$this->assertTrue( add_option( 'wcpay_account_data', array( 'data' => array() ) ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Updating the account cache invalidates the policy decision for the same service. */
	public function test_account_cache_update_invalidates_the_policy_decision_for_the_same_service(): void {
		update_option( 'wcpay_account_data', array( 'data' => array( 'version' => 1 ) ) );
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->create_switching_account_service();

		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$this->assertTrue( update_option( 'wcpay_account_data', array( 'data' => array( 'version' => 2 ) ) ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Deleting the account cache invalidates the policy decision for the same service. */
	public function test_account_cache_deletion_invalidates_the_policy_decision_for_the_same_service(): void {
		update_option( 'wcpay_account_data', array( 'data' => array() ) );
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->create_switching_account_service();

		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$this->assertTrue( delete_option( 'wcpay_account_data' ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox An unrelated option change preserves the policy decision for the same service. */
	public function test_unrelated_option_change_does_not_invalidate_the_policy_decision(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->expects( $this->once() )->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$this->assertTrue( update_option( 'woocommerce_feature_policy_unrelated', 'changed' ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Rollout option changes invalidate the policy decision for the same service. */
	public function test_amazon_pay_option_change_invalidates_the_policy_decision_for_the_same_service(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->create_account_service( false );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		update_option( '_wcpay_feature_amazon_pay', '0' );
		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/** @testdox Caching a second service evicts the first service decision. */
	public function test_amazon_pay_policy_keeps_only_the_last_service_decision(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$first_account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$first_account_service->expects( $this->exactly( 2 ) )->method( 'get_cached_account_data' )->willReturn( array() );
		$second_account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$second_account_service->expects( $this->once() )->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $first_account_service ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $second_account_service ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $first_account_service ) );
	}

	/** @testdox Resetting the policy cache removes its account-refresh invalidation hook. */
	public function test_reset_cache_removes_the_account_refresh_invalidation_hook(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );

		WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->create_account_service( false ) );

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( WooPaymentsFeaturePolicy::class, 'handle_account_refresh' ) ) );
		$this->assertSame( 10, has_action( 'added_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
		$this->assertSame( 10, has_action( 'updated_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
		$this->assertSame( 10, has_action( 'deleted_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
		WooPaymentsFeaturePolicy::reset_cache();
		$this->assertFalse( has_action( 'woocommerce_payments_account_refreshed', array( WooPaymentsFeaturePolicy::class, 'handle_account_refresh' ) ) );
		$this->assertFalse( has_action( 'added_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
		$this->assertFalse( has_action( 'updated_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
		$this->assertFalse( has_action( 'deleted_option', array( WooPaymentsFeaturePolicy::class, 'handle_account_cache_option_change' ) ) );
	}

	/**
	 * @testdox The same service has independent decisions across multisite blogs.
	 * @group multisite
	 */
	public function test_amazon_pay_policy_is_separated_by_blog_for_the_same_service(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This assertion requires the multisite test configuration.' );
		}

		$main_blog_id    = get_current_blog_id();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( $main_blog_id ): array {
				return array( 'ece_confirmation_tokens_disabled' => get_current_blog_id() === $main_blog_id );
			}
		);
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		try {
			update_option( '_wcpay_feature_amazon_pay', '1' );
			$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * @testdox Amazon Pay requires both its rollout flag and ECE confirmation tokens.
	 */
	public function test_amazon_pay_requires_rollout_flag_and_confirmation_tokens(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->create_account_service( true ) ) );

		update_option( '_wcpay_feature_amazon_pay', '0' );
		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->create_account_service( false ) ) );

		update_option( '_wcpay_feature_amazon_pay', '1' );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->create_account_service( false ) ) );
	}

	/**
	 * @testdox Missing account data preserves feature identity until readiness is evaluated separately.
	 */
	public function test_empty_account_data_does_not_disable_confirmation_token_features(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertTrue( WooPaymentsFeaturePolicy::is_ece_confirmation_tokens_enabled( $account_service ) );
		$this->assertTrue( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
	}

	/**
	 * Create an account-service fixture with a confirmation-token policy.
	 *
	 * @param bool $disabled Whether ECE confirmation tokens are disabled.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $disabled ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array( 'ece_confirmation_tokens_disabled' => $disabled )
		);

		return $account_service;
	}

	/**
	 * Create an account-service fixture that changes after a cache mutation.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_switching_account_service(): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturnOnConsecutiveCalls(
			array( 'ece_confirmation_tokens_disabled' => true ),
			array( 'ece_confirmation_tokens_disabled' => false )
		);

		return $account_service;
	}

	/**
	 * Create an account-service fixture that can be released between decisions.
	 *
	 * @param bool $disabled Whether ECE confirmation tokens are disabled.
	 * @return WooPaymentsAccountService
	 */
	private function create_ephemeral_account_service( bool $disabled ): WooPaymentsAccountService {
		return new class( $disabled ) extends WooPaymentsAccountService {
			/** @var bool */
			private $disabled;

			/**
			 * Initialize the confirmation-token policy.
			 *
			 * @param bool $disabled Whether ECE confirmation tokens are disabled.
			 */
			public function __construct( bool $disabled ) {
				$this->disabled = $disabled;
			}

			/**
			 * Get the configured account data.
			 *
			 * @param bool $force_refresh Whether to force a live account refresh.
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				return array( 'ece_confirmation_tokens_disabled' => $this->disabled );
			}
		};
	}
}
