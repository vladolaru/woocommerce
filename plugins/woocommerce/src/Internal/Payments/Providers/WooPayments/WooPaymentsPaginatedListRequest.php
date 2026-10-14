<?php
/**
 * WooPaymentsPaginatedListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\AbstractWooPaymentsApiRequest;
use WP_REST_Request;

/**
 * A paginated platform list request: page, page size, sort and the date and match filters the list routes share.
 *
 * @since 11.0.0
 * @internal
 */
abstract class WooPaymentsPaginatedListRequest extends AbstractWooPaymentsApiRequest {

	protected const DEFAULT_PARAMS = array(
		'page'      => 0,
		'pagesize'  => 25,
		'sort'      => 'created',
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
		$list_request = new static();
		$list_request->set_page( (int) $request->get_param( 'page' ) );
		$list_request->set_page_size( (int) ( $request->get_param( 'pagesize' ) ?? 25 ) );

		$sort = $request->get_param( 'sort' );
		if ( null !== $sort ) {
			$list_request->set_sort_by( (string) $sort );
		}

		$direction = $request->get_param( 'direction' );
		if ( null !== $direction ) {
			$list_request->set_sort_direction( (string) $direction );
		}

		return $list_request;
	}

	/**
	 * Set filters.
	 *
	 * @param array<string,mixed> $filters Filters to set.
	 */
	public function set_filters( array $filters ): void {
		foreach ( $filters as $key => $value ) {
			if ( null === $value ) {
				continue;
			}

			$setter = 'set_' . $key;
			if ( method_exists( $this, $setter ) ) {
				$this->$setter( $value );
			} else {
				$this->set_param( $key, $value );
			}
		}
	}

	/**
	 * Set page.
	 *
	 * @param int $page Page.
	 */
	public function set_page( int $page ): void {
		$this->set_param( 'page', $page );
	}

	/**
	 * Set page size.
	 *
	 * @param int $page_size Page size.
	 */
	public function set_page_size( int $page_size ): void {
		$this->set_param( 'pagesize', $page_size );
	}

	/**
	 * Set sort field.
	 *
	 * @param string $sort Sort field.
	 */
	public function set_sort_by( string $sort ): void {
		$this->set_param( 'sort', $sort );
	}

	/**
	 * Set sort direction.
	 *
	 * @param string $direction Sort direction.
	 */
	public function set_sort_direction( string $direction ): void {
		$this->set_param( 'direction', $direction );
	}

	/**
	 * Set match filter.
	 *
	 * @param string $match_type Match type.
	 */
	public function set_match( string $match_type ): void {
		$this->set_param( 'match', $match_type );
	}

	/**
	 * Set date after filter.
	 *
	 * @param string $date_after Date after.
	 */
	public function set_date_after( string $date_after ): void {
		$this->set_param( 'date_after', $date_after );
	}

	/**
	 * Set date before filter.
	 *
	 * @param string $date_before Date before.
	 */
	public function set_date_before( string $date_before ): void {
		$this->set_param( 'date_before', $date_before );
	}

	/**
	 * Set date between filter.
	 *
	 * @param array<int,string> $date_between Date range.
	 */
	public function set_date_between( array $date_between ): void {
		if ( ! empty( $date_between ) ) {
			$this->set_param( 'date_between', $date_between );
		}
	}
}
