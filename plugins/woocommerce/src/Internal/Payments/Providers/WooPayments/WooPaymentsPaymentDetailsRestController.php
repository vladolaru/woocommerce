<?php
/**
 * WooPaymentsPaymentDetailsRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments payment detail REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPaymentDetailsRestController implements RegisterHooksInterface {

	private const NAMESPACE                  = 'wc/v3';
	private const EVENT_FRAUD_OUTCOME_REVIEW = 'fraud_outcome_review';
	private const EVENT_FRAUD_OUTCOME_BLOCK  = 'fraud_outcome_block';
	private const EVENTS_ORDER               = array(
		'authorized',
		'authorization_voided',
		'authorization_expired',
		self::EVENT_FRAUD_OUTCOME_REVIEW,
		self::EVENT_FRAUD_OUTCOME_BLOCK,
		'captured',
		'partial_refund',
		'full_refund',
		'refund_failed',
		'failed',
		'dispute_needs_response',
		'dispute_in_review',
		'dispute_won',
		'dispute_lost',
		'financing_paydown',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments local order context service.
	 *
	 * @var WooPaymentsMoneyMovementOrderService
	 */
	private WooPaymentsMoneyMovementOrderService $order_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter            $arbiter       Runtime owner arbiter.
	 * @param WooPaymentsApiClient                 $api_client    Native WooPayments API client.
	 * @param WooPaymentsMoneyMovementOrderService $order_service Local order context service.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsMoneyMovementOrderService $order_service ): void {
		$this->arbiter       = $arbiter;
		$this->api_client    = $api_client;
		$this->order_service = $order_service;
	}

	/**
	 * Register REST hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( false === has_action( 'rest_api_init', array( $this, 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}
	}

	/**
	 * Register WooPayments-compatible payment detail routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/payments/charges/(?P<charge_id>\w+)', $this->get_readable_route( 'get_charge' ) );
		register_rest_route( self::NAMESPACE, '/payments/charges/order/(?P<order_id>\w+)', $this->get_readable_route( 'generate_charge_from_order' ) );
		register_rest_route(
			self::NAMESPACE,
			'/payments/payment_intents',
			array_merge( $this->get_creatable_route( 'create_payment_intent' ), array( 'schema' => array( $this, 'get_payment_intent_schema' ) ) )
		);
		register_rest_route( self::NAMESPACE, '/payments/payment_intents/(?P<payment_intent_id>\w+)', $this->get_readable_route( 'get_payment_intent' ) );
		register_rest_route( self::NAMESPACE, '/payments/timeline/(?P<intention_id>\w+)', $this->get_readable_route( 'get_timeline' ) );
		register_rest_route( self::NAMESPACE, '/payments/refund', $this->get_creatable_route( 'process_refund' ) );
	}

	/**
	 * Check route permissions.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get a charge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_charge( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_charge_response(
					$this->api_client->get_charge( (string) $request->get_param( 'charge_id' ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $this->api_exception_to_wp_error( $exception );
		}
	}

	/**
	 * Generate a charge-like object from a WooCommerce order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_charge_from_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'wcpay_missing_order',
				__( 'Order not found', 'woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->order_service->build_charge_response_from_order( $order ) );
	}

	/**
	 * Get a payment intent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_payment_intent( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->order_service->enrich_payment_intent_response(
					$this->api_client->get_payment_intention( (string) $request->get_param( 'payment_intent_id' ) )
				)
			);
		} catch ( WooPaymentsApiException $exception ) {
			return $this->api_exception_to_wp_error( $exception );
		}
	}

	/**
	 * Create and confirm a payment intent from an order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_payment_intent( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'wcpay_server_error',
				__( 'Order not found', 'woocommerce' ),
				array( 'status' => 500 )
			);
		}

		try {
			$intent = $this->api_client->create_and_confirm_payment_intention(
				$this->order_service->build_create_payment_intent_request_from_order(
					$order,
					(string) $request->get_param( 'customer' ),
					(string) $request->get_param( 'payment_method' ),
					$this->is_manual_capture_enabled()
				),
				'payment_intent_order_' . $order->get_id()
			);

			return new WP_REST_Response( $this->prepare_payment_intent_for_response( $intent, $request ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->api_exception_to_wp_error( $exception );
		}
	}

	/**
	 * Get timeline events for a payment intent or order identifier.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_timeline( WP_REST_Request $request ) {
		try {
			$intention_id = (string) $request->get_param( 'intention_id' );
			$timeline     = $this->api_client->get_timeline( $intention_id );

			return new WP_REST_Response( $this->add_manual_fraud_outcome_entry( $timeline, $intention_id ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->api_exception_to_wp_error( $exception );
		}
	}

	/**
	 * Process a payment detail refund.
	 *
	 * Like client 11.1.0 `WC_REST_Payments_Refunds_Controller::process_refund()`, an order that exists
	 * is refunded through WooCommerce; a missing or unknown order refunds the charge directly.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_refund( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return $this->process_charge_refund( $request );
		}

		$charge_id       = sanitize_text_field( (string) $request->get_param( 'charge_id' ) );
		$order_charge_id = (string) $order->get_meta( '_charge_id', true );
		if ( '' === $charge_id || '' === $order_charge_id || $charge_id !== $order_charge_id ) {
			return new WP_Error(
				'wcpay_refund_charge_order_mismatch',
				__( 'The charge does not match the WooPayments charge stored on this order.', 'woocommerce' ),
				array( 'status' => 409 )
			);
		}

		$refund_amount = $this->get_refund_amount( $request, $order );
		if ( is_wp_error( $refund_amount ) ) {
			return $refund_amount;
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$refund = wc_create_refund(
			array(
				'amount'         => $refund_amount,
				'reason'         => $reason,
				'order_id'       => $order->get_id(),
				'refund_payment' => true,
				'restock_items'  => true,
			)
		);

		if ( is_wp_error( $refund ) ) {
			return new WP_Error(
				'wcpay_refund_payment_failed',
				$refund->get_error_message(),
				array( 'status' => 400 )
			);
		}

		if ( ! $refund instanceof \WC_Order_Refund ) {
			return new WP_Error(
				'wcpay_refund_payment_failed',
				__( 'Failed to create refund.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'       => $refund->get_id(),
				'order_id' => $order->get_id(),
				'amount'   => wc_format_decimal( $refund->get_amount(), wc_get_price_decimals() ),
				'reason'   => $refund->get_reason(),
				'status'   => $refund->get_status(),
			)
		);
	}

	/**
	 * Add a locally persisted manual fraud outcome event to platform review timelines.
	 *
	 * @param array<string,mixed> $timeline Timeline response.
	 * @param string              $intention_id Payment intent ID or order ID.
	 * @return array<string,mixed>
	 */
	private function add_manual_fraud_outcome_entry( array $timeline, string $intention_id ): array {
		if ( ! $this->timeline_has_fraud_outcome_event( $timeline ) ) {
			return $timeline;
		}

		$order = $this->get_order_for_timeline( $intention_id );
		if ( ! $order instanceof WC_Order ) {
			return $timeline;
		}

		$manual_entry = $order->get_meta( '_wcpay_fraud_outcome_manual_entry', true );
		if ( ! is_array( $manual_entry ) || empty( $manual_entry ) ) {
			return $timeline;
		}

		$events   = isset( $timeline['data'] ) && is_array( $timeline['data'] ) ? $timeline['data'] : array();
		$events[] = $manual_entry;
		usort( $events, array( $this, 'sort_timeline_events' ) );

		$timeline['data'] = $events;

		return $timeline;
	}

	/**
	 * Check whether platform timeline data contains fraud outcome events.
	 *
	 * @param array<string,mixed> $timeline Timeline response.
	 * @return bool
	 */
	private function timeline_has_fraud_outcome_event( array $timeline ): bool {
		if ( empty( $timeline['data'] ) || ! is_array( $timeline['data'] ) ) {
			return false;
		}

		foreach ( $timeline['data'] as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = $event['type'] ?? '';
			if ( is_string( $type ) && in_array( $type, array( self::EVENT_FRAUD_OUTCOME_REVIEW, self::EVENT_FRAUD_OUTCOME_BLOCK ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the WooCommerce order represented by a timeline ID.
	 *
	 * @param string $intention_id Payment intent ID or order ID.
	 * @return WC_Order|null
	 */
	private function get_order_for_timeline( string $intention_id ): ?WC_Order {
		if ( is_numeric( $intention_id ) ) {
			$order = wc_get_order( (int) $intention_id );
			return $order instanceof WC_Order ? $order : null;
		}

		$order = $this->get_order_by_intention_id( $intention_id );
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$order_id = $this->get_order_id_from_intention( $intention_id );
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Get an order by the persisted native payment intent meta.
	 *
	 * @param string $intention_id Payment intent ID.
	 * @return WC_Order|null
	 */
	private function get_order_by_intention_id( string $intention_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_intent_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $intention_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'return'     => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return null;
		}

		$order = reset( $orders );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Get an order ID from the platform payment intent metadata.
	 *
	 * @param string $intention_id Payment intent ID.
	 * @return int
	 */
	private function get_order_id_from_intention( string $intention_id ): int {
		try {
			$intention = $this->api_client->get_payment_intention( $intention_id );
		} catch ( WooPaymentsApiException $exception ) {
			return 0;
		}

		$metadata = isset( $intention['metadata'] ) && is_array( $intention['metadata'] ) ? $intention['metadata'] : array();
		$order_id = $metadata['order_id'] ?? 0;

		return is_numeric( $order_id ) ? (int) $order_id : 0;
	}

	/**
	 * Sort timeline events like the reference WooPayments client.
	 *
	 * @param mixed $event_a First event.
	 * @param mixed $event_b Second event.
	 * @return int
	 */
	private function sort_timeline_events( $event_a, $event_b ): int {
		$datetime_result = $this->get_timeline_event_datetime( $event_b ) <=> $this->get_timeline_event_datetime( $event_a );
		if ( 0 !== $datetime_result ) {
			return $datetime_result;
		}

		return $this->get_timeline_event_order( $event_b ) <=> $this->get_timeline_event_order( $event_a );
	}

	/**
	 * Get an event datetime.
	 *
	 * @param mixed $event Timeline event.
	 * @return int
	 */
	private function get_timeline_event_datetime( $event ): int {
		if ( ! is_array( $event ) ) {
			return 0;
		}

		$datetime = $event['datetime'] ?? 0;
		return is_numeric( $datetime ) ? (int) $datetime : 0;
	}

	/**
	 * Get an event type sort order.
	 *
	 * @param mixed $event Timeline event.
	 * @return int
	 */
	private function get_timeline_event_order( $event ): int {
		if ( ! is_array( $event ) ) {
			return -1;
		}

		$type = $event['type'] ?? '';
		if ( ! is_string( $type ) ) {
			return -1;
		}

		$order = array_search( $type, self::EVENTS_ORDER, true );
		return false === $order ? -1 : (int) $order;
	}

	/**
	 * Build a readable REST route definition.
	 *
	 * @param string $callback Callback method.
	 * @return array<string,mixed>
	 */
	private function get_readable_route( string $callback ): array {
		return array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'check_permission' ),
		);
	}

	/**
	 * Build a creatable REST route definition.
	 *
	 * @param string $callback Callback method.
	 * @return array<string,mixed>
	 */
	private function get_creatable_route( string $callback ): array {
		return array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'check_permission' ),
		);
	}

	/**
	 * Get the created payment intent the way client 11.1.0 `WC_REST_Payments_Payment_Intents_Controller::prepare_item_for_response()`
	 * shapes it: intent fields and the latest charge's summary, filtered by the route schema.
	 *
	 * @param array<string,mixed> $intent  Created payment intent.
	 * @param WP_REST_Request     $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private function prepare_payment_intent_for_response( array $intent, WP_REST_Request $request ): array {
		$charges = isset( $intent['charges']['data'] ) && is_array( $intent['charges']['data'] ) && 0 < (int) ( $intent['charges']['total_count'] ?? count( $intent['charges']['data'] ) )
			? $intent['charges']['data']
			: array();
		$charge  = empty( $charges ) ? null : end( $charges );
		$charge  = is_array( $charge ) ? $charge : null;

		$payment_method = $intent['payment_method'] ?? $intent['source'] ?? null;
		$item           = array(
			'id'             => $intent['id'] ?? null,
			'amount'         => $intent['amount'] ?? null,
			// The client's intent model uppercases the currency.
			'currency'       => isset( $intent['currency'] ) ? strtoupper( (string) $intent['currency'] ) : null,
			'created'        => gmdate( 'Y-m-d H:i:s', (int) ( $intent['created'] ?? 0 ) ),
			'customer'       => $intent['customer'] ?? $charge['customer'] ?? null,
			'payment_method' => is_array( $payment_method ) ? ( $payment_method['id'] ?? null ) : $payment_method,
			'status'         => $intent['status'] ?? null,
		);

		if ( null !== $charge ) {
			$item['charge'] = array(
				'id'                     => $charge['id'] ?? null,
				'amount'                 => $charge['amount'] ?? null,
				'application_fee_amount' => $charge['application_fee_amount'] ?? null,
				'status'                 => $charge['status'] ?? null,
			);

			$billing_details = is_array( $charge['billing_details'] ?? null ) ? $charge['billing_details'] : array();
			if ( isset( $billing_details['address'] ) ) {
				foreach ( array( 'city', 'country', 'line1', 'line2', 'postal_code', 'state' ) as $key ) {
					$item['charge']['billing_details']['address'][ $key ] = $billing_details['address'][ $key ] ?? '';
				}
			}
			foreach ( array( 'email', 'name', 'phone' ) as $key ) {
				$item['charge']['billing_details'][ $key ] = $billing_details[ $key ] ?? '';
			}

			$card = $charge['payment_method_details']['card'] ?? null;
			if ( is_array( $card ) ) {
				foreach ( array( 'amount_authorized', 'brand', 'capture_before', 'country', 'exp_month', 'exp_year', 'last4', 'three_d_secure' ) as $key ) {
					$item['charge']['payment_method_details']['card'][ $key ] = $card[ $key ] ?? '';
				}
			}
		}

		$context = is_string( $request['context'] ?? null ) ? $request['context'] : 'view';

		return (array) rest_filter_response_by_context( $item, $this->get_payment_intent_schema(), $context );
	}

	/**
	 * Get the schema of a created payment intent, as client 11.1.0 declares it.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_intent_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payment_intent',
			'type'       => 'object',
			'properties' => array(
				'id'       => array(
					'description' => __( 'ID for the payment intent.', 'woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'amount'   => array(
					'description' => __( 'The amount of the transaction.', 'woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'currency' => array(
					'description' => __( 'The currency of the transaction.', 'woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'created'  => array(
					'description' => __( 'The date when the payment intent was created.', 'woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'customer' => array(
					'description' => __( 'The customer id of the intent', 'woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'status'   => array(
					'description' => __( 'The status of the payment intent.', 'woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'charge'   => array(
					'description' => __( 'Charge object associated with this payment intention.', 'woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'properties'  => array(
						'id'                     => array(
							'description' => 'ID for the charge.',
							'type'        => 'string',
							'context'     => array( 'view' ),
						),
						'amount'                 => array(
							'description' => 'The amount of the charge.',
							'type'        => 'integer',
							'context'     => array( 'view' ),
						),
						'payment_method_details' => array(
							'description' => 'Details for the payment method used for the charge.',
							'type'        => 'object',
							'properties'  => array(
								'card' => array(
									'description' => 'Details for a card payment method.',
									'type'        => 'object',
									'properties'  => array(
										'amount_authorized' => array(
											'description' => 'The amount authorized by the card.',
											'type'        => 'integer',
										),
										'brand'          => array(
											'description' => 'The brand of the card.',
											'type'        => 'string',
										),
										'capture_before' => array(
											'description' => 'Timestamp for when the authorization must be captured.',
											'type'        => 'string',
										),
										'country'        => array(
											'description' => 'The ISO country code.',
											'type'        => 'string',
										),
										'exp_month'      => array(
											'description' => 'The expiration month of the card.',
											'type'        => 'integer',
										),
										'exp_year'       => array(
											'description' => 'The expiration year of the card.',
											'type'        => 'integer',
										),
										'last4'          => array(
											'description' => 'The last 4 digits of the card.',
											'type'        => 'string',
										),
										'three_d_secure' => array(
											'description' => 'Details for 3D Secure authentication.',
											'type'        => 'object',
										),
									),
								),
							),
						),
						'billing_details'        => array(
							'description' => __( 'Billing details for the payment method.', 'woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'properties'  => array(
								'address' => array(
									'description' => __( 'Address associated with the billing details.', 'woocommerce' ),
									'type'        => 'object',
									'context'     => array( 'view' ),
									'properties'  => array(
										'city'        => array(
											'description' => __( 'City of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
										'country'     => array(
											'description' => __( 'Country of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
										'line1'       => array(
											'description' => __( 'Line 1 of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
										'line2'       => array(
											'description' => __( 'Line 2 of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
										'postal_code' => array(
											'description' => __( 'Postal code of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
										'state'       => array(
											'description' => __( 'State of the billing address.', 'woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
										),
									),
								),
								'email'   => array(
									'description' => __( 'Email associated with the billing details.', 'woocommerce' ),
									'type'        => 'string',
									'format'      => 'email',
									'context'     => array( 'view' ),
								),
								'name'    => array(
									'description' => __( 'Name associated with the billing details.', 'woocommerce' ),
									'type'        => 'string',
									'context'     => array( 'view' ),
								),
								'phone'   => array(
									'description' => __( 'Phone number associated with the billing details.', 'woocommerce' ),
									'type'        => 'string',
									'context'     => array( 'view' ),
								),
							),
						),
						'payment_method'         => array(
							'description' => 'The payment method associated with this charge.',
							'type'        => 'string',
							'context'     => array( 'view' ),
						),
						'application_fee_amount' => array(
							'description' => 'The application fee amount.',
							'type'        => 'integer',
							'context'     => array( 'view' ),
						),
						'status'                 => array(
							'description' => 'The status of the payment intent created.',
							'type'        => 'string',
							'context'     => array( 'view' ),
						),
					),
				),

			),
		);
	}

	/**
	 * Tell whether manual capture is enabled for WooPayments.
	 *
	 * @return bool
	 */
	private function is_manual_capture_enabled(): bool {
		$settings = get_option( 'woocommerce_woocommerce_payments_settings', array() );

		return is_array( $settings ) && 'yes' === (string) ( $settings['manual_capture'] ?? 'no' );
	}

	/**
	 * Refund a charge that has no WooCommerce order.
	 *
	 * Ports client 11.1.0 `process_charge_refund()` and its `Refund_Charge` request: the charge must be a `ch_`
	 * or `py_` ID, the amount a positive integer, and nothing is written locally. The client throws on invalid
	 * input (a fatal); native refuses it with a 400 instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	private function process_charge_refund( WP_REST_Request $request ) {
		$charge_id = $request->get_param( 'charge_id' );
		if ( ! is_string( $charge_id ) || ! preg_match( '/^(ch|py)_\w{1,250}$/', $charge_id ) ) {
			return new WP_Error(
				'wcpay_core_invalid_request_parameter_stripe_id',
				__( 'The charge ID is not a valid WooPayments charge identifier.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$amount = filter_var( $request->get_param( 'amount' ), FILTER_VALIDATE_INT );
		if ( false === $amount || $amount <= 0 ) {
			return $this->get_invalid_refund_amount_error();
		}

		$reason = $request->get_param( 'reason' );
		if ( null !== $reason && ! is_string( $reason ) ) {
			return new WP_Error(
				'wcpay_refund_invalid_reason',
				__( 'The refund reason is not valid.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		try {
			// The client sets no idempotency key here, so the transport mints a fresh one per request.
			return new WP_REST_Response( $this->api_client->refund_charge( $charge_id, $amount, $reason, 'transaction_details_no_order', '' ) );
		} catch ( WooPaymentsApiException $exception ) {
			return new WP_Error(
				'wcpay_refund_payment',
				$exception->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get the decimal refund amount from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param WC_Order        $order   Order.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return float|WP_Error
	 */
	private function get_refund_amount( WP_REST_Request $request, WC_Order $order ) {
		$amount = filter_var( $request->get_param( 'amount' ), FILTER_VALIDATE_INT );
		if ( false === $amount || $amount <= 0 ) {
			return $this->get_invalid_refund_amount_error();
		}

		$refund_amount = WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $amount, (string) $order->get_currency() );
		$remaining     = (float) $order->get_remaining_refund_amount();
		if ( $refund_amount <= 0.0 || $refund_amount > $remaining ) {
			return $this->get_invalid_refund_amount_error();
		}

		return (float) wc_format_decimal( $refund_amount, wc_get_price_decimals() );
	}

	/**
	 * Get invalid refund amount error.
	 *
	 * @return WP_Error
	 */
	private function get_invalid_refund_amount_error(): WP_Error {
		return new WP_Error(
			'wcpay_refund_invalid_amount',
			__( 'The refund amount is not valid.', 'woocommerce' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Convert a WooPayments API exception to a REST error.
	 *
	 * @param WooPaymentsApiException $exception Exception.
	 * @return WP_Error
	 */
	private function api_exception_to_wp_error( WooPaymentsApiException $exception ): WP_Error {
		$error_code = $exception->get_error_code();
		if ( '' === $error_code ) {
			$error_code = 'wcpay_api_error';
		}

		$http_code = $exception->get_http_code();
		if ( ! $http_code ) {
			$http_code = 400;
		}

		return new WP_Error(
			$error_code,
			$exception->getMessage(),
			array( 'status' => $http_code )
		);
	}
}
