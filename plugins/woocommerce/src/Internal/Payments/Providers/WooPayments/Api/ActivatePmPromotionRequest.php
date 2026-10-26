<?php
/**
 * ActivatePmPromotionRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The request that activates a payment method promotion on the platform, filtered by
 * `wcpay_activate_pm_promotion_request`.
 *
 * @since 11.0.0
 * @internal
 */
class ActivatePmPromotionRequest extends Request {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_activate_pm_promotion_request';

	private const API = 'payment_method_promotions';

	/**
	 * Promotion instance ID.
	 *
	 * @var string
	 */
	private string $id = '';

	/**
	 * Create a request for activating a payment method promotion.
	 *
	 * @param string $id Promotion instance ID.
	 * @return self
	 */
	public static function from_id( string $id ): self {
		$request     = new self();
		$request->id = $id;
		$request->set_api( self::API . '/' . rawurlencode( $id ) . '/activate' );
		$request->set_method( 'POST' );

		return $request;
	}

	/**
	 * Get the promotion instance ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Wrap the decoded answer in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return $this->format_default_response( $response );
	}
}
