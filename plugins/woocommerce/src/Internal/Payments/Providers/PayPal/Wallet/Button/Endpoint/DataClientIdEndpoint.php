<?php
/**
 * The Data Client ID endpoint.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\IdentityToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;

/**
 * Class DataClientIdEndpoint
 */
class DataClientIdEndpoint implements EndpointInterface {


	const ENDPOINT = 'ppc-data-client-id';

	/**
	 * The Request Data Helper.
	 *
	 * @var RequestData
	 */
	private $request_data;

	/**
	 * The Identity Token.
	 *
	 * @var IdentityToken
	 */
	private $identity_token;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * DataClientIdEndpoint constructor.
	 *
	 * @param RequestData     $request_data The Request Data Helper.
	 * @param IdentityToken   $identity_token The Identity Token.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		RequestData $request_data,
		IdentityToken $identity_token,
		LoggerInterface $logger
	) {

		$this->request_data   = $request_data;
		$this->identity_token = $identity_token;
		$this->logger         = $logger;
	}

	/**
	 * Returns the nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 */
	public function handle_request(): void {
		try {
			$this->request_data->read_request( $this->nonce() );
			$user_id = get_current_user_id();
			$token   = $this->identity_token->generate_for_user( $user_id );
			wp_send_json(
				array(
					'token'      => $token->token(),
					'expiration' => $token->expiration_timestamp(),
					'user'       => $user_id,
				)
			);
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( Exception $error ) {
			$this->logger->error( 'Client ID retrieval failed: ' . $error->getMessage() );

			wp_send_json_error(
				array(
					'name'    => $error instanceof PayPalApiException ? $error->name() : '',
					'message' => $error->getMessage(),
					'code'    => $error->getCode(),
					'details' => $error instanceof PayPalApiException ? $error->details() : array(),
				)
			);
		}
	}
}
