<?php
/**
 * WooPaymentsDepositsListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WP_REST_Request;

/**
 * Compatibility request object for the preserved WooPayments deposits list filter.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDepositsListRequest extends WooPaymentsPaginatedListRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_list_deposits_request';

	/**
	 * Register the legacy request FQCN as an alias when the WooPayments extension is absent.
	 */
	public static function register_legacy_alias(): void {
		self::register_legacy_base_aliases();

		$legacy_classes = array(
			'WCPay\Core\Server\Request\List_Deposits',
		);

		foreach ( $legacy_classes as $legacy_class ) {
			if ( ! class_exists( $legacy_class, false ) ) {
				class_alias( self::class, $legacy_class );
			}
		}
	}

	/**
	 * Create a request from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return static
	 */
	public static function from_rest_request( WP_REST_Request $request ) {
		$deposits_request = parent::from_rest_request( $request );
		$date_between     = $request->get_param( 'date_between' );
		$deposits_request->set_filters(
			array(
				'match'             => $request->get_param( 'match' ),
				'store_currency_is' => $request->get_param( 'store_currency_is' ),
				'date_before'       => $request->get_param( 'date_before' ),
				'date_after'        => $request->get_param( 'date_after' ),
				'date_between'      => null === $date_between ? null : (array) $date_between,
				'status_is'         => $request->get_param( 'status_is' ),
				'status_is_not'     => $request->get_param( 'status_is_not' ),
			)
		);

		return $deposits_request;
	}

	/**
	 * Set store currency filter.
	 *
	 * @param string $store_currency Store currency.
	 */
	public function set_store_currency_is( string $store_currency ): void {
		$this->set_param( 'store_currency_is', $store_currency );
	}

	/**
	 * Set status filter.
	 *
	 * @param string $status Status.
	 */
	public function set_status_is( string $status ): void {
		$this->set_param( 'status_is', $status );
	}

	/**
	 * Set excluded status filter.
	 *
	 * @param string $status Status.
	 */
	public function set_status_is_not( string $status ): void {
		$this->set_param( 'status_is_not', $status );
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return 'deposits';
	}
}
