<?php
/**
 * WooPaymentsDisputesListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use WP_REST_Request;

/**
 * The disputes list request, filtered by `wcpay_list_disputes_request`.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsDisputesListRequest extends WooPaymentsPaginatedListRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_list_disputes_request';

	/**
	 * Create a request from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return static
	 */
	public static function from_rest_request( WP_REST_Request $request ) {
		$disputes_request = parent::from_rest_request( $request );
		$date_between     = $request->get_param( 'date_between' );

		$disputes_request->set_filters(
			array(
				'match'           => $request->get_param( 'match' ),
				'currency_is'     => $request->get_param( 'store_currency_is' ),
				'created_before'  => $request->get_param( 'date_before' ),
				'created_after'   => $request->get_param( 'date_after' ),
				'created_between' => null === $date_between ? null : (array) $date_between,
				'search'          => $request->get_param( 'search' ),
				'status_is'       => $request->get_param( 'status_is' ),
				'status_is_not'   => $request->get_param( 'status_is_not' ),
			)
		);

		return $disputes_request;
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return 'disputes';
	}

	/**
	 * Add each dispute's order details and wrap the answer in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		$order_service = wc_get_container()->get( WooPaymentsOrderDetailsService::class );

		return $this->format_default_response( $order_service->enrich_disputes_list_response( $response ) );
	}
}
