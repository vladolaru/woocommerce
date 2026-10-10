<?php
/**
 * PlatformServedOnboardingRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\OnboardingRestEndpoint;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The settings app's onboarding route for a store the platform serves: onboarding reads as completed and the gateways
 * as synced, so the app opens on its settings and the Overview, and a save writes nothing.
 *
 * The platform serves the store without the wallet's own onboarding wizard, so its stored onboarding profile is only
 * read, never changed: the app's gateway sync, which would otherwise save the profile, is answered without a write.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedOnboardingRestEndpoint extends OnboardingRestEndpoint {

	/**
	 * The settings app's onboarding fields that read as done while served.
	 */
	private const DONE_FIELDS = array( 'completed', 'gatewaysSynced' );

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * Constructor.
	 *
	 * @param OnboardingProfile $profile          The onboarding profile.
	 * @param ConnectionState   $connection_state The connection state.
	 */
	public function __construct( OnboardingProfile $profile, ConnectionState $connection_state ) {
		parent::__construct( $profile );
		$this->connection_state = $connection_state;
	}

	/**
	 * The onboarding details, completed and synced while served.
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

		foreach ( self::DONE_FIELDS as $field ) {
			$body['data'][ $field ] = true;
		}
		$response->set_data( $body );

		return $response;
	}

	/**
	 * Save the onboarding details, except while served: then nothing is written and the details are answered.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function update_details( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->connection_state->is_served_by_platform() ) {
			return $this->get_details();
		}

		return parent::update_details( $request );
	}
}
