<?php
/**
 * PlatformServedAuthenticationRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\AuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\AuthenticationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SettingsDataManager;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The settings app's authentication route for a store the platform serves: the disconnect writes nothing.
 *
 * The wallet's disconnect clears its first-party connection and, with a reset, deletes every `woocommerce-ppcp-*`
 * setting. A store the platform serves has no first-party connection to clear, and the settings app shows the
 * Disconnect button whatever the data says, so the route refuses the disconnect while served.
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
	 * Constructor.
	 *
	 * @param AuthenticationManager $authentication_manager The authentication manager.
	 * @param SettingsDataManager   $data_manager           The settings data manager.
	 * @param ConnectionState       $connection_state       The connection state.
	 * @param LoggerInterface|null  $logger                 The logger; a null logger when omitted.
	 */
	public function __construct( AuthenticationManager $authentication_manager, SettingsDataManager $data_manager, ConnectionState $connection_state, ?LoggerInterface $logger = null ) {
		parent::__construct( $authentication_manager, $data_manager, $logger );
		$this->connection_state = $connection_state;
	}

	/**
	 * Disconnect the merchant, except while served: then nothing is written and the answer says why.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->connection_state->is_served_by_platform() ) {
			return $this->return_error( __( 'WooCommerce manages this PayPal connection, so it cannot be disconnected here.', 'woocommerce' ) );
		}

		return parent::disconnect( $request );
	}
}
