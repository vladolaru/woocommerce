<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AssertedRefundSigner;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;

/**
 * Tests for reading PayPal's answer to a Pay with Crypto order creation, and for the crypto creation match the signer
 * shares with the check and the checkout re-check.
 *
 * @group paypal-wallet
 */
class CryptoCreateAnswerTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * @testdox Should read the outcome of an answer: $description.
	 * @testWith ["200 awaiting the payer", 200, "{\"status\":\"PAYER_ACTION_REQUIRED\"}", "accepted"]
	 *           ["201 awaiting the payer", 201, "{\"status\":\"PAYER_ACTION_REQUIRED\"}", "accepted"]
	 *           ["200 created", 200, "{\"status\":\"CREATED\"}", "unknown"]
	 *           ["202 awaiting the payer", 202, "{\"status\":\"PAYER_ACTION_REQUIRED\"}", "unknown"]
	 *           ["422 for the payment source", 422, "{\"details\":[{\"issue\":\"NOT_ENABLED_FOR_PAYMENT_SOURCE\"}]}", "refused"]
	 *           ["422 with the issue second", 422, "{\"details\":[{\"issue\":\"OTHER\"},{\"issue\":\"NOT_ENABLED_FOR_PAYMENT_SOURCE\"}]}", "refused"]
	 *           ["422 for another issue", 422, "{\"details\":[{\"issue\":\"COUNTRY_NOT_SUPPORTED_BY_PAYMENT_SOURCE\"}]}", "unknown"]
	 *           ["400 with the issue", 400, "{\"details\":[{\"issue\":\"NOT_ENABLED_FOR_PAYMENT_SOURCE\"}]}", "unknown"]
	 *           ["500 with the issue", 500, "{\"details\":[{\"issue\":\"NOT_ENABLED_FOR_PAYMENT_SOURCE\"}]}", "unknown"]
	 *           ["422 with details not a list", 422, "{\"details\":\"NOT_ENABLED_FOR_PAYMENT_SOURCE\"}", "unknown"]
	 *           ["422 without JSON", 422, "NOT_ENABLED_FOR_PAYMENT_SOURCE", "unknown"]
	 *           ["200 without JSON", 200, "PAYER_ACTION_REQUIRED", "unknown"]
	 *
	 * @param string $description The case.
	 * @param int    $code        The HTTP status.
	 * @param string $body        The response body.
	 * @param string $expected    The outcome.
	 */
	public function test_outcome( string $description, int $code, string $body, string $expected ): void {
		unset( $description );

		$this->assertSame( $expected, CryptoCreateAnswer::outcome( $this->http_response( $code, $body ) ) );
	}

	/**
	 * @testdox Should read no outcome from a WP_Error or no response.
	 */
	public function test_no_response_is_unknown(): void {
		$this->assertSame( CryptoCreateAnswer::UNKNOWN, CryptoCreateAnswer::outcome( new \WP_Error( 'failed', 'Failed' ) ) );
		$this->assertSame( CryptoCreateAnswer::UNKNOWN, CryptoCreateAnswer::outcome( null ) );
		$this->assertSame( 0, CryptoCreateAnswer::status_code( null ) );
		$this->assertSame( '', CryptoCreateAnswer::debug_id( null ) );
	}

	/**
	 * Crypto creation request arguments for a merchant.
	 *
	 * @param string $payee     The payee merchant ID, or an empty string for none.
	 * @param bool   $assertion Whether the request carries an assertion.
	 * @return array
	 */
	private function args( string $payee = 'M2', bool $assertion = true ): array {
		$unit = array( 'amount' => array( 'value' => '1.00' ) );
		if ( '' !== $payee ) {
			$unit['payee'] = array( 'merchant_id' => $payee );
		}

		return array(
			'method'  => 'POST',
			'headers' => $assertion ? array( 'paypal-auth-assertion' => 'a' ) : array(),
			'body'    => (string) wp_json_encode(
				array(
					'purchase_units' => array( $unit ),
					'payment_source' => array( 'crypto' => array() ),
				)
			),
		);
	}

	/**
	 * @testdox Should tell an order made for the merchant, with its payee and the assertion, from any other.
	 */
	public function test_is_for_merchant(): void {
		$this->assertTrue( CryptoCreateAnswer::is_for_merchant( $this->args(), 'M2' ), 'The payee and an assertion, whatever its case' );
		$this->assertFalse( CryptoCreateAnswer::is_for_merchant( $this->args( 'OTHER' ), 'M2' ), 'Another payee' );
		$this->assertFalse( CryptoCreateAnswer::is_for_merchant( $this->args( '' ), 'M2' ), 'No payee' );
		$this->assertFalse( CryptoCreateAnswer::is_for_merchant( $this->args( 'M2', false ), 'M2' ), 'No assertion' );
		$this->assertFalse( CryptoCreateAnswer::is_for_merchant( $this->args( '' ), '' ), 'No platform merchant' );
	}

	/**
	 * @testdox Should match exactly the crypto creations the signer signs: $description.
	 * @testWith ["a crypto creation", "POST", "https://api.platform.fake.test/v2/checkout/orders", true, true]
	 *           ["the merchant app's host", "POST", "https://api.merchant-app.fake.test/v2/checkout/orders", true, true]
	 *           ["a query string", "post", "https://api.platform.fake.test/v2/checkout/orders?x=1", true, true]
	 *           ["a trailing slash", "POST", "https://api.platform.fake.test/v2/checkout/orders/", true, true]
	 *           ["a regular creation", "POST", "https://api.platform.fake.test/v2/checkout/orders", false, false]
	 *           ["another host", "POST", "https://api.example.test/v2/checkout/orders", true, false]
	 *           ["a sub-resource", "POST", "https://api.platform.fake.test/v2/checkout/orders/ID/capture", true, false]
	 *           ["a patch", "PATCH", "https://api.platform.fake.test/v2/checkout/orders", true, false]
	 *
	 * @param string $description The case.
	 * @param string $method      The HTTP method.
	 * @param string $url         The URL.
	 * @param bool   $crypto      Whether the body has the crypto payment source.
	 * @param bool   $expected    Whether it is a crypto creation.
	 */
	public function test_the_signer_signs_exactly_the_matched_creations( string $description, string $method, string $url, bool $crypto, bool $expected ): void {
		unset( $description );
		$this->set_platform_connected();
		$transport = new FakePlatformTransport();
		$args      = array(
			'method'  => $method,
			'headers' => array( 'Authorization' => 'Bearer token-merchant_app' ),
			'body'    => (string) wp_json_encode( $crypto ? array( 'payment_source' => array( 'crypto' => array() ) ) : array( 'intent' => 'CAPTURE' ) ),
		);
		$signer    = new AssertedRefundSigner( new ConnectionState(), $transport, new OrderAppContext(), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), new NullLogger() );

		$signed = $signer->handle_ppcp_request_args( $args, $url );

		$this->assertSame( $expected, CryptoCreateAnswer::is_crypto_create( $url, $args, $transport ) );
		$this->assertSame( $expected, AssertedRefundSigner::has_assertion( $signed['headers'] ), 'The signer agrees' );
	}
}
