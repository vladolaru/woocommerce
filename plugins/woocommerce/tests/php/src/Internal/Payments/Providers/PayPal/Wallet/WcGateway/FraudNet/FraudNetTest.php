<?php
/**
 * Tests for the FraudNet session ID and source website ID.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNetSourceWebsiteId;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ArrayObject;
use WC_Session;

/**
 * The FraudNet session ID is what core sends PayPal as `PayPal-Client-Metadata-Id` on every order creation, and what the
 * `ppcp-fraudnet` script hands to PayPal's device data beacon. It is created once per shopper session and stored in it.
 *
 * @group paypal-wallet
 */
class FraudNetTest extends WalletTestCase {

	/**
	 * The session WC() held before the test, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Keep the session the test replaces.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;
	}

	/**
	 * Restore the session.
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Give WC() an array-backed session.
	 *
	 * @return ArrayObject The session data, to read back what the code stored.
	 */
	private function use_array_session(): ArrayObject {
		$data    = new ArrayObject();
		$session = $this->mock( WC_Session::class );
		$session->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $key, $fallback = null ) use ( $data ) {
				return $data[ $key ] ?? $fallback;
			}
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			static function ( string $key, $value ) use ( $data ): void {
				$data[ $key ] = $value;
			}
		);
		WC()->session = $session;

		return $data;
	}

	/**
	 * @testdox Should return an empty session ID when there is no shopper session (wallet).
	 */
	public function test_session_id_is_empty_without_a_session(): void {
		WC()->session = null;

		$this->assertSame( '', ( new FraudNet( 'site' ) )->session_id() );
	}

	/**
	 * @testdox Should create a 32 character hex session ID once, store it in the shopper session and return the same one afterwards (wallet).
	 */
	public function test_session_id_is_created_once_and_stored(): void {
		$data = $this->use_array_session();
		$sut  = new FraudNet( 'site' );

		$first = $sut->session_id();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $first );
		$this->assertSame( $first, $data['ppcp_fraudnet_session_id'] );
		$this->assertSame( $first, $sut->session_id() );
	}

	/**
	 * @testdox Should return the session ID the shopper session already holds (wallet).
	 */
	public function test_session_id_comes_from_the_session_when_stored(): void {
		$data                             = $this->use_array_session();
		$data['ppcp_fraudnet_session_id'] = 'stored-id';

		$this->assertSame( 'stored-id', ( new FraudNet( 'site' ) )->session_id() );
	}

	/**
	 * @testdox Should return the source website ID it was built with (wallet).
	 */
	public function test_source_website_id_is_the_constructor_value(): void {
		$this->assertSame( 'merchant_checkout-page', ( new FraudNet( 'merchant_checkout-page' ) )->source_website_id() );
	}

	/**
	 * @testdox Should build the source website ID from the merchant ID and the checkout page suffix (wallet).
	 */
	public function test_source_website_id_names_the_merchant(): void {
		$this->assertSame( 'MERCHANT123_checkout-page', ( new FraudNetSourceWebsiteId( 'MERCHANT123' ) )() );
	}
}
