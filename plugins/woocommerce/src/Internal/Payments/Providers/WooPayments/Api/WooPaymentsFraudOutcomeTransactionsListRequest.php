<?php
/**
 * WooPaymentsFraudOutcomeTransactionsListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use WC_Order;
use WP_REST_Request;

/**
 * Compatibility request object for the preserved WooPayments fraud outcome filters.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsFraudOutcomeTransactionsListRequest extends WooPaymentsPaginatedListRequest {

	protected const DEFAULT_PARAMS = array(
		'page'      => 1,
		'pagesize'  => 25,
		'sort'      => 'date',
		'direction' => 'desc',
	);

	/**
	 * Create a request from REST request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return static
	 */
	public static function from_rest_request( WP_REST_Request $request ) {
		$fraud_outcome_request = parent::from_rest_request( $request );

		$fraud_outcome_request->set_status( $request->get_param( 'status' ) );
		$fraud_outcome_request->set_search(
			null === $request->get_param( 'search' ) ? array() : (array) $request->get_param( 'search' )
		);
		$fraud_outcome_request->set_search_term( (string) ( $request->get_param( 'search_term' ) ?? '' ) );
		$fraud_outcome_request->set_additional_status( (string) ( $request->get_param( 'additional_status' ) ?? '' ) );

		return $fraud_outcome_request;
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 * @throws WooPaymentsApiException When the status is not allow, block or review.
	 */
	public function get_api(): string {
		$status = $this->get_param( 'status' );
		// The client refuses any other status before a request is built (class-list-fraud-outcome-transactions.php:40-45).
		if ( ! in_array( $status, array( 'allow', 'block', 'review' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new WooPaymentsApiException( __( 'Invalid fraud outcome status provided.', 'woocommerce' ), 'invalid_fraud_outcome_status', 400 );
		}

		return 'fraud_outcomes/status/' . $status;
	}

	/**
	 * Set the fraud outcome status.
	 *
	 * @param mixed $status Fraud outcome status.
	 */
	public function set_status( $status ): void {
		$this->set_param( 'status', $status );
	}

	/**
	 * Set the search param.
	 *
	 * @param mixed $search Search param.
	 */
	public function set_search( $search ): void {
		$this->set_param( 'search', $search );
	}

	/**
	 * Set the search term param.
	 *
	 * @param mixed $search_term Search term param.
	 */
	public function set_search_term( $search_term ): void {
		$this->set_param( 'search_term', (string) $search_term );
	}

	/**
	 * Set the additional status param.
	 *
	 * @param mixed $additional_status Additional status param.
	 */
	public function set_additional_status( $additional_status ): void {
		$this->set_param( 'additional_status', (string) $additional_status );
	}

	/**
	 * Format the platform's fraud outcome rows with their orders.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return self::format_transactions( $response, $this->get_params() );
	}

	/**
	 * Format raw fraud outcome platform rows with local WooCommerce order context.
	 *
	 * @param array<string|int,mixed> $response Platform response.
	 * @param array<string,mixed>     $params   Request params.
	 * @return array<int,array<string,mixed>>
	 */
	public static function format_transactions( array $response, array $params ): array {
		$outcomes = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
		$status   = isset( $params['status'] ) && is_scalar( $params['status'] ) ? (string) $params['status'] : '';
		$search   = isset( $params['search'] ) && is_array( $params['search'] ) ? $params['search'] : array();
		$results  = array();

		foreach ( $outcomes as $outcome ) {
			if ( ! is_array( $outcome ) ) {
				continue;
			}

			$formatted = self::build_transaction( $outcome );
			if ( empty( $formatted ) ) {
				continue;
			}

			if ( ! self::matches_status( $formatted, $status ) ) {
				continue;
			}

			if ( ! empty( $search ) && ! self::matches_search( $formatted, $search ) ) {
				continue;
			}

			unset( $formatted['manual_review'] );

			$results[] = $formatted;
		}

		self::sort_transactions( $results, $params );

		return $results;
	}

	/**
	 * Paginate formatted fraud outcome transactions.
	 *
	 * @param array<int,array<string,mixed>> $transactions Formatted transactions.
	 * @param array<string,mixed>            $params       Request params.
	 * @return array<int,array<string,mixed>>
	 */
	public static function paginate_transactions( array $transactions, array $params ): array {
		$page      = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$page_size = isset( $params['pagesize'] ) ? max( 1, (int) $params['pagesize'] ) : 25;

		return array_slice( $transactions, ( $page - 1 ) * $page_size, $page_size );
	}

	/**
	 * Summarize formatted fraud outcome transactions.
	 *
	 * @param array<int,array<string,mixed>> $transactions Formatted transactions.
	 * @return array<string,mixed>
	 */
	public static function summarize_transactions( array $transactions ): array {
		$total      = 0;
		$currencies = array();

		foreach ( $transactions as $transaction ) {
			$total += isset( $transaction['amount'] ) && is_numeric( $transaction['amount'] ) ? (int) $transaction['amount'] : 0;

			if ( isset( $transaction['currency'] ) && is_scalar( $transaction['currency'] ) ) {
				$currencies[] = strtolower( (string) $transaction['currency'] );
			}
		}

		return array(
			'count'      => count( $transactions ),
			'total'      => $total,
			'currencies' => array_values( array_unique( $currencies ) ),
		);
	}

	/**
	 * Build fraud outcome search autocomplete results.
	 *
	 * @param array<int,array<string,mixed>> $transactions Formatted transactions.
	 * @param string                         $search_term  Search term.
	 * @return array<int,array<string,string>>
	 */
	public static function get_search_autocomplete( array $transactions, string $search_term ): array {
		$results = array_values(
			array_map(
				static function ( array $transaction ): array {
					$order_id      = isset( $transaction['order_id'] ) && is_scalar( $transaction['order_id'] ) ? (string) $transaction['order_id'] : '';
					$customer_name = isset( $transaction['customer_name'] ) && is_scalar( $transaction['customer_name'] ) ? (string) $transaction['customer_name'] : '';

					return array(
						'key'   => 'customer-' . $order_id,
						'label' => $customer_name,
					);
				},
				$transactions
			)
		);

		$order = wc_get_order( $search_term );
		if ( $order instanceof WC_Order ) {
			$prefix = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ? __( 'Subscription #', 'woocommerce' ) : __( 'Order #', 'woocommerce' );
			array_unshift(
				$results,
				array(
					'key'   => 'order-' . $order->get_id(),
					'label' => $prefix . $search_term,
				)
			);
		}

		return $results;
	}

	/**
	 * Build a fraud outcome transaction from platform and order data.
	 *
	 * @param array<string,mixed> $outcome Fraud outcome row.
	 * @return array<string,mixed>|null
	 */
	private static function build_transaction( array $outcome ): ?array {
		$order_id = isset( $outcome['order_id'] ) && is_scalar( $outcome['order_id'] ) ? (string) $outcome['order_id'] : '';
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'shop_order_refund' === $order->get_type() ) {
			return null;
		}

		$payment_intent_id                   = isset( $outcome['payment_intent_id'] ) && is_scalar( $outcome['payment_intent_id'] )
			? (string) $outcome['payment_intent_id']
			: (string) $order->get_meta( '_intent_id' );
		$outcome['payment_intent']           = array();
		$outcome['payment_intent']['id']     = '' !== $payment_intent_id ? $payment_intent_id : $order->get_transaction_id();
		$outcome['payment_intent']['status'] = (string) $order->get_meta( '_intention_status' );

		$outcome['amount']              = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), (string) $order->get_currency() );
		$outcome['currency']            = $order->get_currency();
		$outcome['customer_name']       = wc_clean( $order->get_formatted_billing_full_name() );
		$outcome['manual_review']       = $order->get_meta( '_wcpay_fraud_outcome_manual_entry' );
		$outcome['fraud_meta_box_type'] = $order->get_meta( '_wcpay_fraud_meta_box_type' );

		unset( $outcome['payment_intent_id'] );

		return $outcome;
	}

	/**
	 * Tell whether a fraud outcome row matches the requested status.
	 *
	 * @param array<string,mixed> $outcome Fraud outcome row.
	 * @param string              $status  Requested fraud outcome status.
	 * @return bool
	 */
	private static function matches_status( array $outcome, string $status ): bool {
		$intent_status       = isset( $outcome['payment_intent']['status'] ) && is_scalar( $outcome['payment_intent']['status'] ) ? (string) $outcome['payment_intent']['status'] : '';
		$manual_review       = $outcome['manual_review'] ?? '';
		$fraud_meta_box_type = isset( $outcome['fraud_meta_box_type'] ) && is_scalar( $outcome['fraud_meta_box_type'] ) ? (string) $outcome['fraud_meta_box_type'] : '';

		if ( 'review' === $status ) {
			return 'requires_capture' === $intent_status && empty( $manual_review ) && 'review' === $fraud_meta_box_type;
		}

		if ( 'block' === $status ) {
			return in_array( $fraud_meta_box_type, array( 'block', 'review_blocked' ), true );
		}

		return true;
	}

	/**
	 * Tell whether a fraud outcome row matches any search term.
	 *
	 * @param array<string,mixed> $outcome Fraud outcome row.
	 * @param array<int,mixed>    $search  Search terms.
	 * @return bool
	 */
	private static function matches_search( array $outcome, array $search ): bool {
		foreach ( $search as $term ) {
			if ( self::matches_search_term( $outcome, is_scalar( $term ) ? (string) $term : '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether a fraud outcome row matches one search term.
	 *
	 * @param array<string,mixed> $outcome Fraud outcome row.
	 * @param string              $term    Search term.
	 * @return bool
	 */
	private static function matches_search_term( array $outcome, string $term ): bool {
		if ( '' === $term ) {
			return false;
		}

		if ( preg_match( '/#(\d+)/', $term, $matches ) ) {
			$order_id = isset( $outcome['order_id'] ) && is_scalar( $outcome['order_id'] ) ? (string) $outcome['order_id'] : '';

			return $matches[1] === $order_id;
		}

		$customer_name = isset( $outcome['customer_name'] ) && is_scalar( $outcome['customer_name'] ) ? (string) $outcome['customer_name'] : '';

		return 1 === preg_match( '/' . preg_quote( $term, '/' ) . '/i', $customer_name );
	}

	/**
	 * Sort fraud outcome transactions in place.
	 *
	 * @param array<int,array<string,mixed>> $transactions Fraud outcome transactions.
	 * @param array<string,mixed>            $params       Request params.
	 */
	private static function sort_transactions( array &$transactions, array $params ): void {
		$sort      = isset( $params['sort'] ) && is_scalar( $params['sort'] ) ? (string) $params['sort'] : 'date';
		$direction = isset( $params['direction'] ) && is_scalar( $params['direction'] ) ? (string) $params['direction'] : 'desc';
		$key       = 'date' === $sort ? 'created' : $sort;

		usort(
			$transactions,
			static function ( array $a, array $b ) use ( $key, $direction ): int {
				if ( ! array_key_exists( $key, $a ) || ! array_key_exists( $key, $b ) ) {
					return 0;
				}

				if ( $a[ $key ] === $b[ $key ] ) {
					return 0;
				}

				if ( 'desc' === $direction ) {
					return $a[ $key ] < $b[ $key ] ? 1 : -1;
				}

				return $a[ $key ] < $b[ $key ] ? -1 : 1;
			}
		);
	}
}
