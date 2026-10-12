<?php
/**
 * PlatformServedAuthenticationRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\AuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\AuthenticationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SettingsDataManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The settings app's authentication route for a store the platform serves: the disconnect also leaves the served state.
 *
 * While served, the wallet's disconnect runs as for a first-party store (its connection data is empty here; "Start
 * over" still resets the wallet's settings models, as it does first-party), and the store then leaves the platform as
 * after a first-party connect (R201): the platform and collecting options, the seller status, the Pay with Crypto check
 * answer, the owner row and the apps' tokens go; held orders keep their status, notes and locked Refund. The store is
 * dormant afterwards.
 * The card status the wallet's disconnect refilled from the platform merchant is cleared too, without a request.
 * The transport's webhook subscriptions stay at PayPal: nothing here calls the transport, and the served registrar never
 * unsubscribes. A delivery still boots the wallet on its `/paypal/v1` route, without the collecting module, and the
 * wallet's webhook endpoint rejects it: a dormant store stores no webhook to verify it against.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedAuthenticationRestEndpoint extends AuthenticationRestEndpoint {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $collecting_state;

	/**
	 * The wallet's card (DCC) product status.
	 *
	 * @var DCCProductStatus
	 */
	private DCCProductStatus $dcc_status;

	/**
	 * Constructor.
	 *
	 * @param AuthenticationManager $authentication_manager The authentication manager.
	 * @param SettingsDataManager   $data_manager           The settings data manager.
	 * @param ConnectionState       $connection_state       The connection state.
	 * @param CollectingState       $collecting_state       The collecting state.
	 * @param DCCProductStatus      $dcc_status             The wallet's card (DCC) product status.
	 * @param LoggerInterface|null  $logger                 The logger; a null logger when omitted.
	 */
	public function __construct( AuthenticationManager $authentication_manager, SettingsDataManager $data_manager, ConnectionState $connection_state, CollectingState $collecting_state, DCCProductStatus $dcc_status, ?LoggerInterface $logger = null ) {
		parent::__construct( $authentication_manager, $data_manager, $logger );
		$this->connection_state = $connection_state;
		$this->collecting_state = $collecting_state;
		$this->dcc_status       = $dcc_status;
	}

	/**
	 * Disconnect the merchant as the wallet does and, while served, leave the served state afterwards.
	 *
	 * The wallet's disconnect runs first, so its listeners read a whole served state, never a half-deleted one; leaving
	 * deletes options only and fires nothing. One of those listeners refills the card status from the platform merchant's
	 * seller status and caches it for up to a month, so the status is cleared last, through its own clear(), which makes
	 * no request. Firing the wallet's clear action again would refill it.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response {
		$served   = $this->connection_state->is_served_by_platform();
		$response = parent::disconnect( $request );
		if ( $served ) {
			$this->collecting_state->abandon( CollectingState::ABANDON_FIRST_PARTY );
			$this->dcc_status->clear();
		}

		return $response;
	}
}
