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
	 * @testdox Without a cached account, ECE confirmation tokens and Amazon Pay are off (client 11.1.0 `class-wc-payments-features.php:438-442`).
	 */
	public function test_no_cached_account_turns_confirmation_token_features_off(): void {
		update_option( '_wcpay_feature_amazon_pay', '1' );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array() );

		$this->assertFalse( WooPaymentsFeaturePolicy::is_ece_confirmation_tokens_enabled( $account_service ) );
		$this->assertFalse( WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $account_service ) );
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
}
