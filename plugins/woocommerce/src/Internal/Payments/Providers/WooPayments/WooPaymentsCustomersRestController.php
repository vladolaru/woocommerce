<?php
/**
 * WooPaymentsCustomersRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves a WooPayments customer's saved payment methods of the store's enabled types, for the mobile apps.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCustomersRestController extends RestApiControllerBase {

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsCustomerService $customer_service WooPayments customer service.
	 * @param WooPaymentsAccountService  $account_service  WooPayments account service.
	 */
	final public function init( WooPaymentsCustomerService $customer_service, WooPaymentsAccountService $account_service ): void {
		$this->customer_service = $customer_service;
		$this->account_service  = $account_service;
	}

	/**
	 * Get this controller's key in WooCommerce's list of REST controllers.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'woopayments-customers';
	}

	/**
	 * Register the customer route.
	 */
	public function register_routes(): void {
		register_rest_route( $this->route_namespace, '/payments/customers/(?P<customer_id>\w+)/payment_methods', $this->get_readable_route( 'get_customer_payment_methods' ) );
	}

	/**
	 * Check route permissions.
	 *
	 * @return bool
	 */
	public function has_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get payment methods for a WooPayments customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_customer_payment_methods( WP_REST_Request $request ) {
		$customer_id     = (string) $request->get_param( 'customer_id' );
		$payment_methods = array();

		try {
			foreach ( $this->get_enabled_payment_method_types() as $type ) {
				$payment_methods = array_merge(
					$payment_methods,
					$this->customer_service->get_payment_methods_for_customer( $customer_id, $type )
				);
			}
		} catch ( ApiException $exception ) {
			// The body and status the client's wp_send_json_error( $message, 403 ) sends, without its exit (class-wc-rest-payments-customer-controller.php:78-83).
			return new WP_REST_Response(
				array(
					'success' => false,
					'data'    => wp_strip_all_tags( $exception->getMessage() ),
				),
				403
			);
		}

		return new WP_REST_Response(
			array_map(
				array( $this, 'prepare_payment_method_for_response' ),
				$payment_methods
			)
		);
	}

	/**
	 * Get enabled payment method types for the store.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_method_types(): array {
		$payment_method_types = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );
		$types                = array();

		foreach ( (array) $payment_method_types as $type ) {
			if ( ! is_scalar( $type ) ) {
				continue;
			}

			$type = trim( (string) $type );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Prepare a payment method for the REST response.
	 *
	 * @param array<string,mixed> $payment_method Payment method data.
	 * @return array<string,mixed>
	 */
	private function prepare_payment_method_for_response( array $payment_method ): array {
		$prepared = array(
			'id'              => isset( $payment_method['id'] ) ? (string) $payment_method['id'] : '',
			'type'            => isset( $payment_method['type'] ) ? (string) $payment_method['type'] : '',
			'billing_details' => isset( $payment_method['billing_details'] ) && is_array( $payment_method['billing_details'] ) ? $payment_method['billing_details'] : array(),
		);

		if ( isset( $payment_method['card'] ) && is_array( $payment_method['card'] ) ) {
			$prepared['card'] = array(
				'brand'     => isset( $payment_method['card']['brand'] ) ? (string) $payment_method['card']['brand'] : '',
				'last4'     => isset( $payment_method['card']['last4'] ) ? (string) $payment_method['card']['last4'] : '',
				'exp_month' => isset( $payment_method['card']['exp_month'] ) ? (int) $payment_method['card']['exp_month'] : 0,
				'exp_year'  => isset( $payment_method['card']['exp_year'] ) ? (int) $payment_method['card']['exp_year'] : 0,
			);
		} elseif ( isset( $payment_method['sepa_debit'] ) && is_array( $payment_method['sepa_debit'] ) ) {
			$prepared['sepa_debit'] = array(
				'last4' => isset( $payment_method['sepa_debit']['last4'] ) ? (string) $payment_method['sepa_debit']['last4'] : '',
			);
		} elseif ( isset( $payment_method['link'] ) && is_array( $payment_method['link'] ) ) {
			$prepared['link'] = array(
				'email' => isset( $payment_method['link']['email'] ) ? (string) $payment_method['link']['email'] : '',
			);
		}

		return $prepared;
	}

	/**
	 * Get a readable route definition.
	 *
	 * @param string $callback Callback method.
	 * @return array<string,mixed>
	 */
	private function get_readable_route( string $callback ): array {
		return array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'has_permission' ),
		);
	}
}
