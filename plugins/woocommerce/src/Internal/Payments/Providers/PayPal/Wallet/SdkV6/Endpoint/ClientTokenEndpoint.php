<?php
/**
 * Handles the request for the SDK v6 browser-safe client token.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\SdkClientToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\EndpointInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;

class ClientTokenEndpoint implements EndpointInterface {

	public const ENDPOINT = 'ppc-sdk-v6-client-token';

	private RequestData $request_data;
	private LoggerInterface $logger;
	private SdkClientToken $sdk_client_token;

	public function __construct(
		RequestData $request_data,
		LoggerInterface $logger,
		SdkClientToken $sdk_client_token
	) {
		$this->request_data     = $request_data;
		$this->logger           = $logger;
		$this->sdk_client_token = $sdk_client_token;
	}

	public function handle_request(): void {
		try {
			$this->request_data->read_request( self::nonce() );
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}

		try {
			$token = $this->sdk_client_token->sdk_client_token();

			wp_send_json_success(
				array(
					'client_token' => $token,
				)
			);
		} catch ( PayPalApiException $exception ) {
			$this->logger->error( 'SDK v6 client token PayPal API error: ' . $exception->getMessage() );
			wp_send_json_error( array( 'message' => 'Failed to generate client token.' ), 500 );
		} catch ( RuntimeException $exception ) {
			$this->logger->error( 'SDK v6 client token runtime error: ' . $exception->getMessage() );
			wp_send_json_error( array( 'message' => 'Failed to generate client token.' ), 500 );
		}
	}

	public static function nonce(): string {
		return self::ENDPOINT;
	}
}
