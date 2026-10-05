<?php
/**
 * The endpoint for muting an admin notification.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Entity\PersistentMessage;

/**
 * Permanently mutes an admin notification for the current user.
 */
class MuteMessageEndpoint {
	const ENDPOINT = 'ppc-mute-message';

	/**
	 * The request data helper.
	 *
	 * @var RequestData
	 */
	private RequestData $request_data;

	/**
	 * MuteMessageEndpoint constructor.
	 *
	 * @param RequestData $request_data The request data helper.
	 */
	public function __construct(
		RequestData $request_data
	) {
		$this->request_data = $request_data;
	}

	/**
	 * Returns the nonce action of the endpoint.
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 */
	public function handle_request(): void {
		try {
			$data = $this->request_data->read_request( $this->nonce() );
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( RuntimeException $ex ) {
			wp_send_json_error();
		}

		$id = $data['id'] ?? '';
		if ( ! $id || ! is_string( $id ) ) {
			wp_send_json_error();
		}

		/**
		 * Create a dummy message with the provided ID and mark it as muted.
		 *
		 * This helps to keep code cleaner and make the mute-endpoint more reliable,
		 * as other modules do not need to register the PersistentMessage on every
		 * ajax request.
		 */
		$message = new PersistentMessage( $id, '', '', '' );
		$message->mute();

		wp_send_json_success();
	}
}
