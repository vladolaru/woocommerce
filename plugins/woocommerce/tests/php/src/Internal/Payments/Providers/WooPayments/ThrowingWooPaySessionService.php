<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use RuntimeException;
use WP_REST_Request;

/**
 * WooPay session service double that throws when assembling session data.
 */
class ThrowingWooPaySessionService extends RecordingWooPaySessionService {

	/**
	 * What session assembly throws; a RuntimeException when null.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throwable = null;

	// phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
	/**
	 * Throw when assembling session data.
	 *
	 * @param string|null                     $email          Shopper email.
	 * @param string|null                     $user_session   WooPay user session.
	 * @param WP_REST_Request|null            $woopay_request WooPay REST request.
	 * @param int|null                        $order_id       Pay-for-order order ID.
	 * @param string|null                     $key            Pay-for-order key.
	 * @param string|null                     $billing_email  Pay-for-order billing email.
	 * @param array<string,mixed>|null        $appearance     WooPay appearance payload.
	 * @param array<int,array<string,string>> $font_rules     WooPay font rules.
	 * @throws \Throwable Always, to exercise the controller's failure path.
	 */
	public function get_init_session_request(
		?string $email = null,
		?string $user_session = null,
		?WP_REST_Request $woopay_request = null,
		?int $order_id = null,
		?string $key = null,
		?string $billing_email = null,
		?array $appearance = null,
		array $font_rules = array()
	): array {
		unset( $email, $user_session, $woopay_request, $order_id, $key, $billing_email, $appearance, $font_rules );

		throw $this->throwable ?? new RuntimeException( 'Unable to read the cart.', 7 );
	}
}
