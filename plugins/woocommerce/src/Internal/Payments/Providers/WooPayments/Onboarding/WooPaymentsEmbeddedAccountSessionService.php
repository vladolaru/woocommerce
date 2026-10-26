<?php
/**
 * WooPaymentsEmbeddedAccountSessionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Creates the platform session the embedded account components on the WooPayments admin pages load with, passing on
 * only the fields they need.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsEmbeddedAccountSessionService {

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param Client $api_client WooPayments API client.
	 */
	final public function init( Client $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Create a sanitized embedded account session.
	 *
	 * @return array<string,mixed>
	 */
	public function create_session(): array {
		// A failure answers an empty session (client 11.1.0 `includes/class-wc-payments-account.php:3248-3258`) and is logged,
		// since the merchant only sees the embedded component fail to load.
		try {
			$session = $this->api_client->create_embedded_account_session();
		} catch ( ApiException $exception ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable_always( 'WooPayments could not create an embedded account session.', $exception );
			return array();
		}

		if ( ! $this->is_valid_session( $session ) ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_always( 'WooPayments received an embedded account session without the fields the page needs.', 'error' );
			return array();
		}

		return array(
			'clientSecret'   => $session['client_secret'],
			'expiresAt'      => $session['expires_at'],
			'accountId'      => $session['account_id'],
			'isLive'         => $session['is_live'],
			'publishableKey' => $session['publishable_key'],
			'locale'         => get_user_locale(),
		);
	}

	/**
	 * Tell whether the platform session payload has the exact safe fields the frontend needs.
	 *
	 * @param array<string,mixed> $session Platform session payload.
	 * @return bool
	 */
	private function is_valid_session( array $session ): bool {
		return isset( $session['client_secret'], $session['expires_at'], $session['account_id'], $session['is_live'], $session['publishable_key'] )
			&& is_string( $session['client_secret'] )
			&& '' !== $session['client_secret']
			&& is_int( $session['expires_at'] )
			&& is_string( $session['account_id'] )
			&& '' !== $session['account_id']
			&& is_bool( $session['is_live'] )
			&& is_string( $session['publishable_key'] )
			&& '' !== $session['publishable_key'];
	}
}
