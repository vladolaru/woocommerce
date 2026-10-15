<?php
/**
 * WooPaymentsTransactionsListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use DateTime;
use DateTimeZone;
use Exception;
use WP_REST_Request;

/**
 * The transactions list request, filtered by `wcpay_list_transactions_request`; its date filters are shifted to the
 * merchant's time zone.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsTransactionsListRequest extends WooPaymentsPaginatedListRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_list_transactions_request';

	protected const DEFAULT_PARAMS = array(
		'page'      => 0,
		'pagesize'  => 25,
		'sort'      => 'date',
		'direction' => 'desc',
		'limit'     => 100,
	);

	/**
	 * Create a request from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return static
	 */
	public static function from_rest_request( WP_REST_Request $request ) {
		$transactions_request = parent::from_rest_request( $request );
		$date_between         = $request->get_param( 'date_between' );
		$user_timezone        = $request->get_param( 'user_timezone' );

		if ( null !== $date_between ) {
			$date_between = array_map(
				static function ( $transaction_date ) use ( $user_timezone ): ?string {
					return self::format_transaction_date_by_timezone( is_scalar( $transaction_date ) ? (string) $transaction_date : null, is_scalar( $user_timezone ) ? (string) $user_timezone : null );
				},
				(array) $date_between
			);
		}

		$transactions_request->set_filters(
			array(
				'match'                    => $request->get_param( 'match' ),
				'date_before'              => self::format_transaction_date_by_timezone( self::get_scalar_param( $request, 'date_before' ), self::get_scalar_param( $request, 'user_timezone' ) ),
				'date_after'               => self::format_transaction_date_by_timezone( self::get_scalar_param( $request, 'date_after' ), self::get_scalar_param( $request, 'user_timezone' ) ),
				'date_between'             => $date_between,
				'type_is'                  => $request->get_param( 'type_is' ),
				'type_is_not'              => $request->get_param( 'type_is_not' ),
				'type_is_in'               => null === $request->get_param( 'type_is_in' ) ? null : (array) $request->get_param( 'type_is_in' ),
				'source_device_is'         => $request->get_param( 'source_device_is' ),
				'source_device_is_not'     => $request->get_param( 'source_device_is_not' ),
				'channel_is'               => $request->get_param( 'channel_is' ),
				'channel_is_not'           => $request->get_param( 'channel_is_not' ),
				'customer_country_is'      => $request->get_param( 'customer_country_is' ),
				'customer_country_is_not'  => $request->get_param( 'customer_country_is_not' ),
				'risk_level_is'            => $request->get_param( 'risk_level_is' ),
				'risk_level_is_not'        => $request->get_param( 'risk_level_is_not' ),
				'store_currency_is'        => $request->get_param( 'store_currency_is' ),
				'customer_currency_is'     => $request->get_param( 'customer_currency_is' ),
				'customer_currency_is_not' => $request->get_param( 'customer_currency_is_not' ),
				'source_is'                => $request->get_param( 'source_is' ),
				'source_is_not'            => $request->get_param( 'source_is_not' ),
				'loan_id_is'               => $request->get_param( 'loan_id_is' ),
				'search'                   => null === $request->get_param( 'search' ) ? null : (array) $request->get_param( 'search' ),
				'deposit_id'               => $request->get_param( 'deposit_id' ),
			)
		);

		return $transactions_request;
	}

	/**
	 * Get the summary and export filters from REST request data: the list's filters without `deposit_id`, shifted to the
	 * merchant's time zone, with no paging, sorting or list request filter.
	 *
	 * Client 11.1.0 class-wc-rest-payments-transactions-controller.php:224-266.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	public static function filters_from_rest_request( WP_REST_Request $request ): array {
		$user_timezone = self::get_scalar_param( $request, 'user_timezone' );
		$date_between  = $request->get_param( 'date_between' );
		if ( null !== $date_between ) {
			$date_between = array_map(
				static function ( $transaction_date ) use ( $user_timezone ): ?string {
					return self::format_transaction_date_by_timezone( is_scalar( $transaction_date ) ? (string) $transaction_date : null, $user_timezone );
				},
				(array) $date_between
			);
		}

		$filters = array(
			'match'        => $request->get_param( 'match' ),
			'date_before'  => self::format_transaction_date_by_timezone( self::get_scalar_param( $request, 'date_before' ), $user_timezone ),
			'date_after'   => self::format_transaction_date_by_timezone( self::get_scalar_param( $request, 'date_after' ), $user_timezone ),
			'date_between' => $date_between,
			// `type_is_in` is kept too, so the totals cover the same transaction types as the list.
			'type_is_in'   => null === $request->get_param( 'type_is_in' ) ? null : (array) $request->get_param( 'type_is_in' ),
		);
		foreach ( array( 'type_is', 'type_is_not', 'source_device_is', 'source_device_is_not', 'channel_is', 'channel_is_not', 'customer_country_is', 'customer_country_is_not', 'risk_level_is', 'risk_level_is_not', 'store_currency_is', 'customer_currency_is', 'customer_currency_is_not', 'source_is', 'source_is_not', 'loan_id_is', 'search' ) as $name ) {
			$filters[ $name ] = $request->get_param( $name );
		}

		return array_filter(
			$filters,
			static function ( $filter ): bool {
				return null !== $filter;
			}
		);
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return 'transactions';
	}

	/**
	 * Set a filter.
	 *
	 * @param mixed $value Filter value.
	 */
	public function set_deposit_id( $value ): void {
		$this->set_param( 'deposit_id', $value );
	}

	/**
	 * Set a filter.
	 *
	 * @param mixed $value Filter value.
	 */
	public function set_store_currency_is( $value ): void {
		$this->set_param( 'store_currency_is', $value );
	}

	/**
	 * Set a filter.
	 *
	 * @param mixed $value Filter value.
	 */
	public function set_search( $value ): void {
		if ( ! empty( $value ) ) {
			$this->set_param( 'search', $value );
		}
	}

	/**
	 * Add each transaction's order details and wrap the answer in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		$order_service = wc_get_container()->get( WooPaymentsOrderDetailsService::class );

		return $this->format_default_response( $order_service->enrich_transactions_list_response( $response ) );
	}

	/**
	 * Get a scalar param from a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return string|null
	 */
	private static function get_scalar_param( WP_REST_Request $request, string $param ): ?string {
		$value = $request->get_param( $param );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Shift a transaction date filter by the difference between the store's and the merchant's time zones.
	 *
	 * Client 11.1.0 Request_Utils::format_transaction_date_by_timezone() (includes/core/server/request/class-request-utils.php
	 * :27-48). A date or time zone PHP cannot parse is sent unchanged instead of failing the request.
	 *
	 * @param string|null $transaction_date Transaction date.
	 * @param string|null $user_timezone    The merchant's time zone, as the browser reports it.
	 * @return string|null
	 */
	public static function format_transaction_date_by_timezone( ?string $transaction_date, ?string $user_timezone ): ?string {
		if ( null === $transaction_date || null === $user_timezone || '' === $transaction_date || '' === $user_timezone ) {
			return $transaction_date;
		}

		try {
			$blog_time = new DateTime( $transaction_date );
			$blog_time->setTimezone( new DateTimeZone( wp_timezone_string() ) );
			$local_time = new DateTime( $transaction_date );
			$local_time->setTimezone( new DateTimeZone( $user_timezone ) );

			$time_difference = ( strtotime( $local_time->format( 'Y-m-d H:i:s' ) ) - strtotime( $blog_time->format( 'Y-m-d H:i:s' ) ) ) / 60;
			$formatted_date  = new DateTime( $transaction_date );
			date_modify( $formatted_date, $time_difference . 'minutes' );

			return $formatted_date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return $transaction_date;
		}
	}
}
