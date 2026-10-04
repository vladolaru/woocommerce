<?php
/**
 * Tests for the reference transaction status helper.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ReferenceTransactionStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Whether the merchant can use reference transactions (the PAYPAL_WALLET_VAULTING_ADVANCED capability), which saved
 * PayPal payment methods need.
 *
 * @group paypal-wallet
 */
class ReferenceTransactionStatusTest extends WalletTestCase {

	/**
	 * A helper over a seller status that holds one capability with the given status.
	 *
	 * @param string $status The status of the PAYPAL_WALLET_VAULTING_ADVANCED capability.
	 * @return ReferenceTransactionStatus
	 */
	private function make_testee( string $status ): ReferenceTransactionStatus {
		$capability = new class( $status ) {

			/**
			 * The capability status.
			 *
			 * @var string
			 */
			private string $status;

			/**
			 * Hold the status.
			 *
			 * @param string $status The capability status.
			 */
			public function __construct( string $status ) {
				$this->status = $status;
			}

			/**
			 * The capability name.
			 *
			 * @return string
			 */
			public function name(): string {
				return 'PAYPAL_WALLET_VAULTING_ADVANCED';
			}

			/**
			 * The capability status.
			 *
			 * @return string
			 */
			public function status(): string {
				return $this->status;
			}
		};

		$seller_status = $this->mock( SellerStatus::class );
		$seller_status->shouldReceive( 'capabilities' )->once()->andReturn( array( $capability ) );

		$partners_endpoint = $this->mock( PartnersEndpoint::class );
		$partners_endpoint->shouldReceive( 'seller_status' )->once()->andReturn( $seller_status );

		return new ReferenceTransactionStatus( $partners_endpoint );
	}

	/**
	 * @testdox Should be enabled when the capability is active.
	 */
	public function test_reference_transaction_enabled_returns_true_when_capability_is_active(): void {
		$this->assertTrue( $this->make_testee( 'ACTIVE' )->reference_transaction_enabled() );
	}

	/**
	 * @testdox Should not be enabled when the capability is not active.
	 */
	public function test_reference_transaction_enabled_returns_false_when_capability_is_missing(): void {
		$this->assertFalse( $this->make_testee( 'INACTIVE' )->reference_transaction_enabled() );
	}

	/**
	 * @testdox Should not be enabled when PayPal cannot be asked for the seller status.
	 */
	public function test_reference_transaction_enabled_returns_false_when_seller_status_fails(): void {
		$partners_endpoint = $this->mock( PartnersEndpoint::class );
		$partners_endpoint->shouldReceive( 'seller_status' )->once()->andThrow( new RuntimeException( 'failed' ) );

		$testee = new ReferenceTransactionStatus( $partners_endpoint );

		$this->assertFalse( $testee->reference_transaction_enabled() );
	}
}
