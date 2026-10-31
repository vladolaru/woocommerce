<?php
/**
 * GetPmPromotionsRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The payment method promotions list request, filtered by `wcpay_get_pm_promotions_request` and sent with the store
 * context.
 *
 * @since 11.0.0
 * @internal
 */
class GetPmPromotionsRequest extends Request {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_get_pm_promotions_request';

	private const API = 'payment_method_promotions';

	/**
	 * Create a request for payment method promotions.
	 *
	 * @param array<string,mixed> $store_context Store context parameters.
	 * @return self
	 */
	public static function from_store_context( array $store_context ): self {
		$request = new self();
		$request->set_api( self::API );
		$request->set_method( 'GET' );
		$request->set_store_context_params( $store_context );

		return $request;
	}

	/**
	 * Ask the API client for the raw answer, whose `cache-for` header sets how long the promotions are cached.
	 *
	 * @return bool
	 */
	public function should_return_raw_response(): bool {
		return true;
	}

	/**
	 * Wrap the raw answer in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return $this->format_default_response( $response );
	}

	/**
	 * Attach store context details to the request.
	 *
	 * @param array<string,mixed> $context Store context parameters.
	 * @return void
	 */
	public function set_store_context_params( array $context ): void {
		foreach ( $context as $key => $value ) {
			if ( ! is_string( $key ) || null === $value || '' === $value || array() === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$encoded_value = wp_json_encode( $value );
				if ( false === $encoded_value ) {
					continue;
				}

				$this->set_param( $key, $encoded_value );
				continue;
			}

			if ( is_scalar( $value ) ) {
				$this->set_param( $key, $value );
			}
		}
	}
}
