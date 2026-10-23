<?php
/**
 * Tests for the free trial vault redirect.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\FreeTrialVaultReturnEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\FreeTrialVaultRedirect;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WC_Order;
use WP_Error;

/**
 * A $0 free-trial subscription order with no saved PayPal account is sent to PayPal to approve a vault setup token.
 * The order is real and the setup token request goes through the real endpoint over a stubbed HTTP layer, so the
 * request that reaches PayPal and the meta written to the order are both read back.
 *
 * @group paypal-wallet
 */
class FreeTrialVaultRedirectTest extends WalletTestCase {

	private const SETUP_TOKENS_URL = 'https://api.sandbox.paypal.com/v3/vault/setup-tokens';

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The redirect under test.
	 *
	 * @var FreeTrialVaultRedirect
	 */
	private FreeTrialVaultRedirect $sut;

	/**
	 * Build the redirect over the real setup token endpoint.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->logger = $this->mock( LoggerInterface::class );
		$this->logger->shouldIgnoreMissing();

		$this->sut = new FreeTrialVaultRedirect(
			new PaymentMethodTokensEndpoint( 'https://api.sandbox.paypal.com', $this->make_bearer( false ), $this->logger ),
			$this->logger
		);
	}

	/**
	 * A saved WooCommerce order.
	 *
	 * @return WC_Order
	 */
	private function create_wc_order(): WC_Order {
		$order = new WC_Order();
		$order->save();

		return $order;
	}

	/**
	 * Answer the setup token request with a created token that has the given links.
	 *
	 * @param array  $links The links of the response.
	 * @param string $id    The ID of the token, empty for a response without one.
	 */
	private function stub_setup_token( array $links, string $id = 'SETUP-TOKEN-1' ): void {
		$body = array( 'links' => $links );
		if ( '' !== $id ) {
			$body['id'] = $id;
		}

		$this->stub_http( $this->http_response( 201, (string) wp_json_encode( $body ) ) );
	}

	/**
	 * The decoded body of the one setup token request that was sent.
	 *
	 * @return array
	 */
	private function sent_body(): array {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );
		$this->assertSame( self::SETUP_TOKENS_URL, $this->http_requests[0]['url'] );
		$this->assertSame( 'POST', $this->http_requests[0]['request']['method'] );

		return (array) json_decode( $this->http_requests[0]['request']['body'], true );
	}

	/**
	 * The meta of the order as it is stored.
	 *
	 * @param WC_Order $order The order.
	 * @return array{token: mixed, nonce: mixed}
	 */
	private function stored_meta( WC_Order $order ): array {
		$stored = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stored );

		return array(
			'token' => $stored->get_meta( FreeTrialVaultReturnEndpoint::SETUP_TOKEN_META ),
			'nonce' => $stored->get_meta( FreeTrialVaultReturnEndpoint::RETURN_NONCE_META ),
		);
	}

	/**
	 * GIVEN a $0 free-trial order with no saved PayPal account
	 * WHEN a vault-approval setup token is successfully created for it
	 * THEN the approval URL from the "approve" link is returned
	 * AND the setup token id and one-time nonce are persisted on the order
	 *
	 * @testdox Should return the approve URL and persist the setup token and the nonce on the order.
	 */
	public function test_returns_approve_url_and_persists_setup_token_on_order(): void {
		$order = $this->create_wc_order();
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'approve',
					'href' => 'https://www.sandbox.paypal.com/agreements/approve?ba_token=XYZ',
				),
			)
		);

		$result = $this->sut->create_redirect_url( $order );

		$this->assertSame( 'https://www.sandbox.paypal.com/agreements/approve?ba_token=XYZ', $result );
		$meta = $this->stored_meta( $order );
		$this->assertSame( 'SETUP-TOKEN-1', $meta['token'] );
		$this->assertSame( 32, strlen( (string) $meta['nonce'] ), 'The nonce should be the 32 characters that were generated' );
	}

	/**
	 * @testdox Should ask PayPal for a merchant-usage setup token that returns to the vault return endpoint with the order and the stored nonce.
	 */
	public function test_requests_a_setup_token_that_returns_with_the_order_and_the_stored_nonce(): void {
		$order = $this->create_wc_order();
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'approve',
					'href' => 'https://www.sandbox.paypal.com/agreements/approve?ba_token=XYZ',
				),
			)
		);

		$this->sut->create_redirect_url( $order );

		$body = $this->sent_body();
		$this->assertArrayNotHasKey( 'customer', $body, 'A guest has no PayPal customer ID' );
		$paypal = $body['payment_source']['paypal'];
		$this->assertSame( 'MERCHANT', $paypal['usage_type'] );
		$this->assertSame( 'CONTINUE', $paypal['experience_context']['user_action'] );
		$this->assertSame( wc_get_checkout_url(), $paypal['experience_context']['cancel_url'] );

		$return_url = $paypal['experience_context']['return_url'];
		$this->assertStringStartsWith( home_url( '/' ), $return_url );
		$this->assertStringContainsString( FreeTrialVaultReturnEndpoint::ENDPOINT, $return_url );
		parse_str( (string) wp_parse_url( $return_url, PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $order->get_id(), $query['ppcp_vault_wc_order'] );
		$this->assertSame( $this->stored_meta( $order )['nonce'], $query['ppcp_vault_nonce'], 'The nonce PayPal sends back should be the one stored on the order' );
	}

	/**
	 * @testdox Should send the PayPal customer ID of a logged-in shopper with the setup token request.
	 */
	public function test_sends_the_paypal_customer_id_of_a_logged_in_shopper(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_ppcp_target_customer_id', 'CUSTOMER-1' );
		wp_set_current_user( $user_id );
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'approve',
					'href' => 'https://www.sandbox.paypal.com/agreements/approve?ba_token=XYZ',
				),
			)
		);

		$this->sut->create_redirect_url( $this->create_wc_order() );

		$this->assertSame( array( 'id' => 'CUSTOMER-1' ), $this->sent_body()['customer'] );
	}

	/**
	 * @testdox Should accept the "payer-action" link as the approval link.
	 */
	public function test_accepts_the_payer_action_link(): void {
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'payer-action',
					'href' => 'https://www.sandbox.paypal.com/agreements/approve?ba_token=ABC',
				),
			)
		);

		$result = $this->sut->create_redirect_url( $this->create_wc_order() );

		$this->assertSame( 'https://www.sandbox.paypal.com/agreements/approve?ba_token=ABC', $result );
	}

	/**
	 * GIVEN a setup token response that contains no "approve" or "payer-action" link
	 * WHEN create_redirect_url() processes that response
	 * THEN an empty string is returned instead of a broken redirect
	 * AND the order is never persisted with a setup token
	 *
	 * @testdox Should return an empty string and leave the order alone when the response has no approve link.
	 */
	public function test_returns_empty_string_when_response_has_no_approve_link(): void {
		$order = $this->create_wc_order();
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'self',
					'href' => 'https://api.paypal.com/v3/vault/setup-tokens/SETUP-TOKEN-1',
				),
			)
		);

		$result = $this->sut->create_redirect_url( $order );

		$this->assertSame( '', $result );
		$this->assertSame(
			array(
				'token' => '',
				'nonce' => '',
			),
			$this->stored_meta( $order )
		);
	}

	/**
	 * @testdox Should return an empty string and leave the order alone when the response has no setup token ID.
	 */
	public function test_returns_empty_string_when_response_has_no_setup_token_id(): void {
		$order = $this->create_wc_order();
		$this->stub_setup_token(
			array(
				array(
					'rel'  => 'approve',
					'href' => 'https://www.sandbox.paypal.com/agreements/approve?ba_token=XYZ',
				),
			),
			''
		);

		$result = $this->sut->create_redirect_url( $order );

		$this->assertSame( '', $result );
		$this->assertSame(
			array(
				'token' => '',
				'nonce' => '',
			),
			$this->stored_meta( $order )
		);
	}

	/**
	 * GIVEN PayPal's setup-token API call fails
	 * WHEN create_redirect_url() attempts to create the vault-approval setup token
	 * THEN an empty string is returned so the caller can fall back to its existing
	 * "no saved PayPal account" failure handling
	 * AND the order is never persisted with a setup token
	 *
	 * @testdox Should return an empty string, log the failure and leave the order alone when the setup token call fails.
	 *
	 * @dataProvider data_failed_setup_token_call
	 *
	 * @param array|WP_Error $response The answer of the stubbed HTTP layer.
	 */
	public function test_returns_empty_string_when_setup_tokens_throws( $response ): void {
		$order = $this->create_wc_order();
		$this->stub_http( $response );
		$this->logger->shouldReceive( 'error' )->once()->with( \Mockery::pattern( '/could not create setup token for WC order ' . $order->get_id() . '/' ) );

		$result = $this->sut->create_redirect_url( $order );

		$this->assertSame( '', $result );
		$this->assertSame(
			array(
				'token' => '',
				'nonce' => '',
			),
			$this->stored_meta( $order )
		);
	}

	/**
	 * Ways the setup token call can fail.
	 *
	 * @return array<string, array{array|WP_Error}>
	 */
	public function data_failed_setup_token_call(): array {
		return array(
			'a transport error'    => array( new WP_Error( 'http_request_failed', 'Connection timeout' ) ),
			'an error from PayPal' => array(
				$this->http_response( 400, (string) wp_json_encode( array( 'name' => 'INVALID_REQUEST' ) ) ),
			),
		);
	}
}
