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
		parent::tearDown();
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
}
