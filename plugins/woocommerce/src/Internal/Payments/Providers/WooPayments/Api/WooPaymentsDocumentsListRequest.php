<?php
/**
 * WooPaymentsDocumentsListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The documents list request, filtered by `wcpay_list_documents_request`; documents sort by date by default.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsDocumentsListRequest extends WooPaymentsPaginatedListRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_list_documents_request';

	protected const DEFAULT_PARAMS = array(
		'page'      => 0,
		'pagesize'  => 25,
		'sort'      => 'date',
		'direction' => 'desc',
		'limit'     => 100,
	);

	/**
	 * Create a request from normalized params.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return self
	 */
	public static function from_params( array $params ): self {
		$request = new self();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return 'documents';
	}

	/**
	 * Set document type filter.
	 *
	 * @param string $type_is Document type.
	 */
	public function set_type_is( string $type_is ): void {
		$this->set_param( 'type_is', $type_is );
	}

	/**
	 * Set excluded document type filter.
	 *
	 * @param string $type_is_not Excluded document type.
	 */
	public function set_type_is_not( string $type_is_not ): void {
		$this->set_param( 'type_is_not', $type_is_not );
	}
}
