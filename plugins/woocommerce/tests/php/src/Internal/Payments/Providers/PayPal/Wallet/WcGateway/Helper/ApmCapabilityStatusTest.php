<?php
/**
 * Tests for the APM seller capability status that Pay Later messaging reads (ported from the extension's ApmCapabilityStatusTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusCapability;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ApmCapabilityStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionMethod;

/**
 * Pay Later messaging follows the seller's alternative payment methods capability (service
 * wcgateway.apm-capability-status): the status is active only when that capability is active.
 *
 * @group paypal-wallet
 */
class ApmCapabilityStatusTest extends WalletTestCase {

	/**
	 * Run the protected check_api_response() over a seller status with the given capabilities.
	 *
	 * @param SellerStatusCapability[] $capabilities The seller's capabilities.
	 * @return bool
	 */
	private function check( array $capabilities ): bool {
		$sut = new ApmCapabilityStatus(
			true,
			$this->mock( PartnersEndpoint::class ),
			$this->mock( FailureRegistry::class ),
			$this->mock( ProductStatusResultCache::class )
		);

		$method = new ReflectionMethod( $sut, 'check_api_response' );
		$method->setAccessible( true );

		return $method->invoke( $sut, new SellerStatus( array(), $capabilities ) );
	}

	/**
	 * @testdox Should be active when the alternative payment methods capability is active (wallet).
	 */
	public function test_active_apm_capability_is_active(): void {
		$this->assertTrue(
			$this->check(
				array(
					new SellerStatusCapability( 'OTHER', SellerStatusCapability::STATUS_ACTIVE ),
					new SellerStatusCapability( 'PAYPAL_CHECKOUT_ALTERNATIVE_PAYMENT_METHODS', SellerStatusCapability::STATUS_ACTIVE ),
				)
			)
		);
	}

	/**
	 * @testdox Should not be active when the alternative payment methods capability is denied (wallet).
	 */
	public function test_inactive_apm_capability_is_not_active(): void {
		$this->assertFalse(
			$this->check( array( new SellerStatusCapability( 'PAYPAL_CHECKOUT_ALTERNATIVE_PAYMENT_METHODS', 'DENIED' ) ) )
		);
	}

	/**
	 * @testdox Should not be active when only another capability is active (wallet).
	 */
	public function test_other_active_capability_is_not_active(): void {
		$this->assertFalse(
			$this->check( array( new SellerStatusCapability( 'CRYPTO_PYMTS', SellerStatusCapability::STATUS_ACTIVE ) ) )
		);
	}

	/**
	 * @testdox Should not be active when the seller has no capabilities (wallet).
	 */
	public function test_no_capabilities_is_not_active(): void {
		$this->assertFalse( $this->check( array() ) );
	}

	/**
	 * @testdox Should keep the result cache key stable so answers already stored under it stay valid (wallet).
	 */
	public function test_keeps_the_stored_result_cache_key_stable(): void {
		$this->assertSame( 'products_local_apms_enabled', ApmCapabilityStatus::KEY );
	}
}
