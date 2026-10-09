<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AuthAssertion;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the unsigned PayPal-Auth-Assertion header.
 *
 * @group paypal-wallet
 */
class AuthAssertionTest extends WalletTestCase {

	/**
	 * Base64url-encode a string without padding.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds the expected JWT segments.
	}

	/**
	 * @testdox Should build an unsigned JWT of the algorithm, the issuing client and the merchant, with an empty signature.
	 */
	public function test_header_is_an_unsigned_jwt(): void {
		$expected = $this->base64url( '{"alg":"none"}' ) . '.' . $this->base64url( '{"iss":"client-1","payer_id":"MERCHANT1"}' ) . '.';

		$this->assertSame( array( 'PayPal-Auth-Assertion' => $expected ), AuthAssertion::header( 'client-1', 'MERCHANT1' ) );
	}

	/**
	 * @testdox Should use url-safe base64 without padding.
	 */
	public function test_segments_are_url_safe_and_unpadded(): void {
		$value = AuthAssertion::header( 'client?>~~~', 'MERCHANT>?>?' )['PayPal-Auth-Assertion'];

		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $value );
		$this->assertSame( 3, count( explode( '.', $value ) ) );
		$this->assertStringEndsWith( '.', $value );
	}

	/**
	 * @testdox Should carry the client in iss and the merchant in payer_id.
	 */
	public function test_claims(): void {
		$segments = explode( '.', AuthAssertion::header( 'client-9', 'MERCHANT9' )['PayPal-Auth-Assertion'] );
		$claims   = json_decode( base64_decode( strtr( $segments[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the header under test.

		$this->assertSame(
			array(
				'iss'      => 'client-9',
				'payer_id' => 'MERCHANT9',
			),
			$claims
		);
	}
}
