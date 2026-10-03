<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use RuntimeException;
use WP_REST_Request;

/**
 * WooPay session service double that throws when assembling session data.
 */
class ThrowingWooPaySessionService extends RecordingWooPaySessionService {

	// phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
	/**
	 * Throw when assembling session data.
	 *
	 * @param string|null          $email          Shopper email.
	 * @param WP_REST_Request|null $woopay_request WooPay REST request.
	 * @throws RuntimeException Always, to exercise the controller's failure path.
	 */
	public function get_session_data( ?string $email = null, ?WP_REST_Request $woopay_request = null ): array {
		unset( $email, $woopay_request );

		throw new RuntimeException( 'kaboom' );
	}
}
