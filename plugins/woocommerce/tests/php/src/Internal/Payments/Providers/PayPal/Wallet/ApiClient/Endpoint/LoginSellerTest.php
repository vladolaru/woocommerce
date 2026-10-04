<?php
/**
 * Tests for the login seller endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\LoginSeller;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The seller login: a token for the shared ID, then the seller's credentials, over a stubbed HTTP layer.
 *
 * @group paypal-wallet
 */
class LoginSellerTest extends WalletTestCase {

	private const HOST = 'https://api.paypal.com';

	/**
	 * Given the seller nonce handed to credentials_for(), the nonce is the code_verifier in the body of the oauth2/token
	 * request, and the credentials of the second request are returned.
	 *
	 * @testdox Should use the seller nonce as the code_verifier of the oauth2/token request.
	 */
	public function test_credentials_for_uses_seller_nonce_as_code_verifier(): void {
		$seller_nonce = bin2hex( random_bytes( 32 ) );
		$this->stub_http(
			function ( $request, $url ) {
				unset( $request );
				if ( false !== strpos( $url, 'oauth2/token' ) ) {
					return $this->http_response( 200, '{"access_token":"fake-access-token"}' );
				}

				return $this->http_response( 200, '{"client_id":"fake-client-id","client_secret":"fake-client-secret"}' );
			}
		);
		$sut = new LoginSeller( self::HOST, 'partner_merchant_id', $this->mock( LoggerInterface::class )->shouldIgnoreMissing() );

		$credentials = $sut->credentials_for( 'shared_id', 'auth_code', $seller_nonce );

		$this->assertSame( 'fake-client-id', $credentials->client_id );
		$this->assertSame( 'fake-client-secret', $credentials->client_secret );
		$this->assertCount( 2, $this->http_requests );

		$token_request = $this->http_requests[0];
		$this->assertSame( self::HOST . '/v1/oauth2/token/', $token_request['url'] );
		$this->assertSame( 'POST', $token_request['request']['method'] );
		$this->assertSame( $seller_nonce, $token_request['request']['body']['code_verifier'] );
		$this->assertSame( 'auth_code', $token_request['request']['body']['code'] );
		$this->assertSame( 'authorization_code', $token_request['request']['body']['grant_type'] );

		$credentials_request = $this->http_requests[1];
		$this->assertSame( self::HOST . '/v1/customer/partners/partner_merchant_id/merchant-integrations/credentials/', $credentials_request['url'] );
		$this->assertSame( 'GET', $credentials_request['request']['method'] );
		$this->assertSame( 'Bearer fake-access-token', $credentials_request['request']['headers']['Authorization'] );
	}
}
