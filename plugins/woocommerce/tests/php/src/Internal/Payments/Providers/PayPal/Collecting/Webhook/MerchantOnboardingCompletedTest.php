<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\Guards;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\MerchantOnboardingCompleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the handler that completes the collecting state when the store's merchant finishes onboarding.
 *
 * @group paypal-wallet
 */
class MerchantOnboardingCompletedTest extends WalletTestCase {
	use WebhookFixtures;

	/**
	 * The handler over a transport answering a seller status.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return MerchantOnboardingCompleted
	 */
	private function sut( FakePlatformTransport $transport ): MerchantOnboardingCompleted {
		return new MerchantOnboardingCompleted( new Guards(), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), $transport, new RecordingLogger() );
	}

	/**
	 * The onboarding event for a tracking ID.
	 *
	 * @param string $tracking_id The tracking ID.
	 * @return \WP_REST_Request
	 */
	private function onboarding_event( string $tracking_id ): \WP_REST_Request {
		return $this->event_request(
			'MERCHANT.ONBOARDING.COMPLETED',
			array(
				'merchant_id' => 'M-FROM-EVENT',
				'tracking_id' => $tracking_id,
			)
		);
	}

	/**
	 * @testdox Should complete the collecting state with the seller's merchant ID when the seller status is complete.
	 */
	public function test_complete_seller_completes_the_state(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'M-SELLER', true, true, true ) ) );
		$sut       = $this->sut( $transport );
		$request   = $this->onboarding_event( 'TRACK-OURS' );

		$this->assertTrue( $sut->responsible_for_request( $request ) );
		$response = $sut->handle_request( $request );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( array( array( 'TRACK-OURS' ) ), $transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'M-SELLER', get_option( Options::PLATFORM )['merchant_id'] ?? null );
		$this->assertFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should leave the state collecting while the seller status is not complete, such as an unconfirmed email.
	 */
	public function test_incomplete_seller_changes_nothing(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'M-SELLER', true, false, true ) ) );

		$response = $this->sut( $transport )->handle_request( $this->onboarding_event( 'TRACK-OURS' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $transport->calls_to( 'seller_status' ) );
		$this->assertFalse( get_option( Options::PLATFORM ) );
		$this->assertNotFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should answer another store's onboarding event with success, without reading any seller status.
	 */
	public function test_other_tracking_id_changes_nothing(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'M-SELLER', true, true, true ) ) );

		$response = $this->sut( $transport )->handle_request( $this->onboarding_event( 'TRACK-THEIRS' ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( array(), $transport->calls_to( 'seller_status' ) );
		$this->assertFalse( get_option( Options::PLATFORM ) );
	}

	/**
	 * @testdox Should answer the event with success and change nothing on a store that no longer collects.
	 */
	public function test_store_not_collecting_changes_nothing(): void {
		$this->set_platform_connected();
		$transport = new FakePlatformTransport( array( 'seller_status' => new SellerStatus( 'M-OTHER', true, true, true ) ) );

		$response = $this->sut( $transport )->handle_request( $this->onboarding_event( 'TRACK-OURS' ) );

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( array(), $transport->calls_to( 'seller_status' ) );
		$this->assertSame( 'M-CONNECTED', get_option( Options::PLATFORM )['merchant_id'] );
	}

	/**
	 * @testdox Should answer without changing anything when the seller status cannot be read.
	 */
	public function test_seller_status_failure_changes_nothing(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'seller_status' => new RuntimeException( 'PayPal is down' ) ) );

		$response = $this->sut( $transport )->handle_request( $this->onboarding_event( 'TRACK-OURS' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertFalse( get_option( Options::PLATFORM ) );
	}

	/**
	 * @testdox Should handle only the onboarding-completed event.
	 */
	public function test_responsible_for_onboarding_completed_only(): void {
		$sut = $this->sut( new FakePlatformTransport() );

		$this->assertSame( array( 'MERCHANT.ONBOARDING.COMPLETED' ), $sut->event_types() );
		$this->assertFalse( $sut->responsible_for_request( $this->event_request( 'MERCHANT.PARTNER-CONSENT.REVOKED', array( 'tracking_id' => 'TRACK-OURS' ) ) ) );
	}
}
