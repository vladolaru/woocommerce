<?php
/**
 * PlatformServedSettingsRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\SettingsRestEndpoint;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The settings app's settings route for a store the platform serves: it reports the capture intent the store uses, and
 * a save leaves the merchant's stored intent settings as they are.
 *
 * The settings provider reads authorize-only as off while served, so the app shows that; the Order Intent block is
 * hidden too, and the off value it would send back is not saved over the merchant's own.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedSettingsRestEndpoint extends SettingsRestEndpoint {

	/**
	 * The settings app's names of the intent settings.
	 */
	private const INTENT_FIELDS = array( 'authorizeOnly', 'captureVirtualOrders' );

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * Constructor.
	 *
	 * @param SettingsModel   $settings         The settings model.
	 * @param ConnectionState $connection_state The connection state.
	 */
	public function __construct( SettingsModel $settings, ConnectionState $connection_state ) {
		parent::__construct( $settings );
		$this->connection_state = $connection_state;
	}

	/**
	 * The settings, with the intent settings off while served.
	 *
	 * @since 11.3.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_details(): WP_REST_Response {
		$response = parent::get_details();
		$body     = $response->get_data();
		if ( ! $this->connection_state->is_served_by_platform() || ! is_array( $body ) || ! is_array( $body['data'] ?? null ) ) {
			return $response;
		}

		foreach ( self::INTENT_FIELDS as $field ) {
			$body['data'][ $field ] = false;
		}
		$response->set_data( $body );

		return $response;
	}

	/**
	 * Save the settings, without the intent settings while served.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function update_details( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->connection_state->is_served_by_platform() ) {
			foreach ( self::INTENT_FIELDS as $field ) {
				unset( $request[ $field ] );
			}
		}

		return parent::update_details( $request );
	}
}
