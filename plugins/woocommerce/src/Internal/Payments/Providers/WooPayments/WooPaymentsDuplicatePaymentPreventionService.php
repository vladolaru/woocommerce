<?php
/**
 * WooPaymentsDuplicatePaymentPreventionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Throwable;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

/**
 * Prevents duplicate WooPayments charges for the same checkout session.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDuplicatePaymentPreventionService {

	/**
	 * Session key used by the standalone WooPayments extension for the currently processing order.
	 */
	public const SESSION_KEY_PROCESSING_ORDER = 'wcpay_processing_order';

	/**
	 * Return URL flag for redirecting to a previous paid order.
	 */
	public const FLAG_PREVIOUS_ORDER_PAID = 'wcpay_paid_for_previous_order';

	/**
	 * Return URL flag for redirecting after a successful attached intent.
	 */
	public const FLAG_PREVIOUS_SUCCESSFUL_INTENT = 'wcpay_previous_successful_intent';

	/**
	 * WooCommerce session.
	 *
	 * @var \WC_Session|null
	 */
	private ?\WC_Session $session;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * Constructor.
	 *
	 * @param \WC_Session|null $session Optional WooCommerce session.
	 */
	public function __construct( ?\WC_Session $session = null ) {
		$this->session = $session;
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient         $api_client         Native WooPayments API client.
	 * @param OrderPaymentLifecycleService $lifecycle_service Native order payment lifecycle service.
	 * @param WooPaymentsOrderDataService  $order_data_service WooPayments order data service.
	 */
	final public function init( WooPaymentsApiClient $api_client, OrderPaymentLifecycleService $lifecycle_service, WooPaymentsOrderDataService $order_data_service ): void {
		$this->api_client         = $api_client;
		$this->lifecycle_service  = $lifecycle_service;
		$this->order_data_service = $order_data_service;
	}

	/**
	 * Redirect to a paid session order when the current order duplicates the same cart content.
	 *
	 * @param WC_Order           $current_order Current order.
	 * @param WC_Payment_Gateway $gateway       Gateway used to build the return URL.
	 * @return array<string,string>|null
	 */
	public function check_against_session_processing_order( WC_Order $current_order, WC_Payment_Gateway $gateway ): ?array {
		$session_order_id = $this->get_session_processing_order();
		if ( null === $session_order_id ) {
			return null;
		}

		$session_order = wc_get_order( $session_order_id );
		if ( ! $session_order instanceof WC_Order ) {
			return null;
		}

		if ( $current_order->get_cart_hash() !== $session_order->get_cart_hash() ) {
			return null;
		}

		if ( ! $session_order->has_status( wc_get_is_paid_statuses() ) ) {
			return null;
		}

		if ( ! $current_order->has_status( wc_get_is_pending_statuses() ) ) {
			return null;
		}

		if ( $session_order->get_id() === $current_order->get_id() ) {
			return null;
		}

		if ( $session_order->get_customer_id() !== $current_order->get_customer_id() ) {
			return null;
		}

		$session_order->add_order_note(
			sprintf(
				/* translators: %d: Order ID. */
				__( 'WooCommerce Payments: detected and deleted order ID %d, which has duplicate cart content with this order.', 'woocommerce' ),
				$current_order->get_id()
			)
		);
		$current_order->delete();

		$this->remove_session_processing_order( $session_order_id );

		return $this->success_redirect( $gateway, $session_order, self::FLAG_PREVIOUS_ORDER_PAID );
	}

	/**
	 * Store the current processing order ID in the session.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function maybe_update_session_processing_order( int $order_id ): void {
		$session = $this->get_session();
		if ( $session instanceof \WC_Session ) {
			$session->set( self::SESSION_KEY_PROCESSING_ORDER, $order_id );
		}
	}

	/**
	 * Remove the processing order ID when it matches the supplied order ID.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function remove_session_processing_order( int $order_id ): void {
		$session_order_id = $this->get_session_processing_order();
		$session          = $this->get_session();
		if ( $order_id === $session_order_id && $session instanceof \WC_Session ) {
			$session->set( self::SESSION_KEY_PROCESSING_ORDER, null );
		}
	}

	/**
	 * Redirect when the current order already has an authorized PaymentIntent attached.
	 *
	 * @param WC_Order           $order   Current order.
	 * @param WC_Payment_Gateway $gateway Gateway used to build the return URL.
	 * @return array<string,string>|WP_Error|null
	 */
	public function check_payment_intent_attached_to_order_succeeded( WC_Order $order, WC_Payment_Gateway $gateway ) {
		$intent_id = (string) $order->get_meta( '_intent_id', true );
		if ( '' === $intent_id || 0 !== strpos( $intent_id, 'pi_' ) ) {
			return null;
		}

		try {
			$intent = $this->get_api_client()->get_payment_intention( $intent_id );
		} catch ( Throwable $exception ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to fetch attached native WooPayments payment intent: ' . $exception->getMessage(),
					array(
						'source'    => 'native-payments',
						'order_id'  => $order->get_id(),
						'intent_id' => $intent_id,
					)
				);
			}
			return null;
		}

		$status = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		if ( ! WooPaymentsIntentCodec::is_authorized_native_intent_status( $status ) ) {
			return null;
		}

		if ( ! $this->intent_belongs_to_order( $intent, $order ) ) {
			return null;
		}

		if ( 'succeeded' === $status ) {
			$this->remove_session_processing_order( $order->get_id() );
		}

		$amount_error = $this->get_amount_mismatch_error( $intent, $order );
		if ( $amount_error instanceof WP_Error ) {
			return $amount_error;
		}

		$this->apply_attached_intent_lifecycle( $intent, $order );

		return $this->success_redirect( $gateway, $order, self::FLAG_PREVIOUS_SUCCESSFUL_INTENT );
	}

	/**
	 * Tell whether intent metadata points to the supplied order.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @param WC_Order            $order  Order.
	 * @return bool
	 */
	private function intent_belongs_to_order( array $intent, WC_Order $order ): bool {
		$metadata                     = is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : array();
		$intent_meta_order_id_raw     = $metadata['order_id'] ?? '';
		$intent_meta_order_id         = is_numeric( $intent_meta_order_id_raw ) ? (int) $intent_meta_order_id_raw : 0;
		$intent_meta_order_number_raw = $metadata['order_number'] ?? '';
		$intent_meta_order_number     = is_numeric( $intent_meta_order_number_raw ) ? (int) $intent_meta_order_number_raw : 0;
		$paid_on_woopay               = filter_var( $metadata['paid_on_woopay'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$is_woopay_order              = $order->get_id() === $intent_meta_order_number;

		return ( $paid_on_woopay && $is_woopay_order ) || $intent_meta_order_id === $order->get_id();
	}

	/**
	 * Get an amount mismatch error when the intent amount differs from the order total.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @param WC_Order            $order  Order.
	 * @return WP_Error|null
	 */
	private function get_amount_mismatch_error( array $intent, WC_Order $order ): ?WP_Error {
		$charged_amount       = isset( $intent['amount'] ) && is_numeric( $intent['amount'] ) ? (int) $intent['amount'] : 0;
		$order_total_in_cents = $this->get_order_data_service()->prepare_amount( (float) $order->get_total(), (string) $order->get_currency() );

		if ( $order_total_in_cents === $charged_amount ) {
			return null;
		}

		return new WP_Error(
			'duplicate_payment_amount_mismatch',
			sprintf(
				/* translators: 1: charged amount, 2: current order total. */
				__( 'This order was already paid for %1$s, but the order total has since changed to %2$s, so we prevented an overpayment. Please create a new order for any additional items.', 'woocommerce' ),
				wc_price( $this->interpret_minor_unit_amount( $charged_amount, (string) $order->get_currency() ), array( 'currency' => $order->get_currency() ) ),
				wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) )
			)
		);
	}

	/**
	 * Apply the attached intent to the order lifecycle.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @param WC_Order            $order  Order.
	 * @return void
	 */
	private function apply_attached_intent_lifecycle( array $intent, WC_Order $order ): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention( $intent, $order );
		$data    = $outcome->get_data();
		$note    = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] ) && '' !== $data[ PaymentOutcome::DATA_NOTE ]
			? $data[ PaymentOutcome::DATA_NOTE ]
			: null;
		$profile = new WooPaymentsPersistenceProfile();

		$this->get_lifecycle_service()->apply(
			$order,
			new PaymentLifecycleEvent(
				self::get_lifecycle_status( $outcome ),
				'' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : null,
				$profile->get_outcome_meta( $outcome ),
				array(),
				$note
			)
		);
	}

	/**
	 * Map a provider outcome status to an order lifecycle status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private static function get_lifecycle_status( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return PaymentLifecycleEvent::STATUS_COMPLETED;

			case PaymentOutcome::STATUS_AUTHORIZED:
				return PaymentLifecycleEvent::STATUS_AUTHORIZED;

			case PaymentOutcome::STATUS_FAILED:
				return PaymentLifecycleEvent::STATUS_FAILED;

			case PaymentOutcome::STATUS_CANCELED:
				return PaymentLifecycleEvent::STATUS_CANCELED;

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return PaymentLifecycleEvent::STATUS_STARTED;
		}

		return PaymentLifecycleEvent::STATUS_FAILED;
	}

	/**
	 * Build a successful checkout redirect result.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway used to build the return URL.
	 * @param WC_Order           $order   Order.
	 * @param string             $flag    Query-string flag.
	 * @return array<string,string>
	 */
	private function success_redirect( WC_Payment_Gateway $gateway, WC_Order $order, string $flag ): array {
		return array(
			'result'   => 'success',
			'redirect' => add_query_arg( $flag, 'yes', $gateway->get_return_url( $order ) ),
		);
	}

	/**
	 * Convert provider minor-unit amount to a decimal amount.
	 *
	 * @param int    $amount   Minor-unit amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	private function interpret_minor_unit_amount( int $amount, string $currency ): float {
		$zero_decimal_currencies = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );

		return in_array( strtolower( $currency ), $zero_decimal_currencies, true )
			? (float) $amount
			: (float) $amount / 100;
	}

	/**
	 * Get the processing order ID for the current session.
	 *
	 * @return int|null
	 */
	private function get_session_processing_order(): ?int {
		$session = $this->get_session();
		if ( ! $session instanceof \WC_Session ) {
			return null;
		}

		$value = $session->get( self::SESSION_KEY_PROCESSING_ORDER );

		return null === $value ? null : absint( $value );
	}

	/**
	 * Get the native WooPayments API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		if ( ! isset( $this->api_client ) ) {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		}

		return $this->api_client;
	}

	/**
	 * Get the native order payment lifecycle service.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function get_lifecycle_service(): OrderPaymentLifecycleService {
		if ( ! isset( $this->lifecycle_service ) ) {
			$this->lifecycle_service = wc_get_container()->get( OrderPaymentLifecycleService::class );
		}

		return $this->lifecycle_service;
	}

	/**
	 * Get the WooPayments order data service.
	 *
	 * @return WooPaymentsOrderDataService
	 */
	private function get_order_data_service(): WooPaymentsOrderDataService {
		if ( ! isset( $this->order_data_service ) ) {
			$this->order_data_service = wc_get_container()->get( WooPaymentsOrderDataService::class );
		}

		return $this->order_data_service;
	}

	/**
	 * Get the current WooCommerce session.
	 *
	 * @return \WC_Session|null
	 */
	private function get_session(): ?\WC_Session {
		if ( $this->session instanceof \WC_Session ) {
			return $this->session;
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && $woocommerce->session instanceof \WC_Session ) {
			$this->session = $woocommerce->session;
		}

		return $this->session instanceof \WC_Session ? $this->session : null;
	}
}
