<?php
/**
 * Endpoint to verify if an order has been approved. An approved order
 * will be stored in the current session.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\OrderHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\WooCommerceOrderCreator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\CustomIds;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\EndpointInterface;

/**
 * Class ApproveOrderEndpoint
 */
class ApproveOrderEndpoint implements EndpointInterface {

	const ENDPOINT = 'ppc-approve-order';

	/**
	 * A helper providing information on the current page, is this a continuation mode, etc.
	 *
	 * @var Context $context
	 */
	protected Context $context;

	/**
	 * The request data helper.
	 *
	 * @var RequestData
	 */
	private $request_data;

	/**
	 * The session handler.
	 *
	 * @var SessionHandler
	 */
	private $session_handler;

	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint
	 */
	private $api_endpoint;

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;

	/**
	 * The settings model.
	 *
	 * @var SettingsModel
	 */
	private SettingsModel $settings_model;

	/**
	 * The order helper.
	 *
	 * @var OrderHelper
	 */
	protected $order_helper;

	/**
	 * Whether the final review is enabled.
	 *
	 * @var bool
	 */
	protected $final_review_enabled;

	/**
	 * The WC gateway.
	 *
	 * @var PayPalGateway
	 */
	protected $gateway;

	/**
	 * The WooCommerce order creator.
	 *
	 * @var WooCommerceOrderCreator
	 */
	protected $wc_order_creator;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * ApproveOrderEndpoint constructor.
	 *
	 * @param RequestData             $request_data         The request data helper.
	 * @param OrderEndpoint           $order_endpoint       The order endpoint.
	 * @param SessionHandler          $session_handler      The session handler.
	 * @param SettingsProvider        $settings_provider    The settings provider.
	 * @param SettingsModel           $settings_model       The settings model.
	 * @param OrderHelper             $order_helper         The order helper.
	 * @param bool                    $final_review_enabled Whether the final review is enabled.
	 * @param PayPalGateway           $gateway              The WC gateway.
	 * @param WooCommerceOrderCreator $wc_order_creator     The WooCommerce order creator.
	 * @param LoggerInterface         $logger               The logger.
	 * @param Context                 $context              The context.
	 */
	public function __construct(
		RequestData $request_data,
		OrderEndpoint $order_endpoint,
		SessionHandler $session_handler,
		SettingsProvider $settings_provider,
		SettingsModel $settings_model,
		OrderHelper $order_helper,
		bool $final_review_enabled,
		PayPalGateway $gateway,
		WooCommerceOrderCreator $wc_order_creator,
		LoggerInterface $logger,
		Context $context
	) {

		$this->request_data         = $request_data;
		$this->api_endpoint         = $order_endpoint;
		$this->session_handler      = $session_handler;
		$this->settings_provider    = $settings_provider;
		$this->settings_model       = $settings_model;
		$this->order_helper         = $order_helper;
		$this->final_review_enabled = $final_review_enabled;
		$this->gateway              = $gateway;
		$this->wc_order_creator     = $wc_order_creator;
		$this->logger               = $logger;
		$this->context              = $context;
	}

	/**
	 * The nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 *
	 * @throws RuntimeException When order not found or handling failed.
	 */
	public function handle_request(): void {
		try {
			$data = $this->request_data->read_request( self::nonce() );
			if ( ! isset( $data['order_id'] ) ) {
				throw new RuntimeException( 'No order id given' );
			}

			/**
			 * Fires when a request to approve a PayPal order starts.
			 *
			 * @since 11.3.0
			 *
			 * @param array $data The request data.
			 */
			do_action( 'woocommerce_paypal_payments_approve_order_request_started', $data );

			$order = $this->api_endpoint->order( $data['order_id'] );

			$purchase_units = $order->purchase_units();
			if ( ! empty( $purchase_units ) ) {
				$custom_id  = $purchase_units[0]->custom_id();
				$prefix_len = strlen( CustomIds::CUSTOMER_ID_PREFIX );
				if ( strpos( $custom_id, CustomIds::CUSTOMER_ID_PREFIX ) === 0 ) {
					$order_session_id = substr( $custom_id, $prefix_len );
					$wc_session       = WC()->session;
					if ( $wc_session instanceof \WC_Session_Handler ) {
						$current_session_id = (string) $wc_session->get_customer_unique_id();
						if ( $order_session_id !== $current_session_id ) {
							throw new RuntimeException(
								__( 'Order validation failed.', 'woocommerce' )
							);
						}
					}
				}
			}

			$is_ready = $order->status()->is( OrderStatus::APPROVED )
				|| $order->status()->is( OrderStatus::CREATED );

			if ( ! $is_ready && $this->order_helper->contains_physical_goods( $order ) ) {
				$message = sprintf(
				// translators: %s is the id of the order.
					__( 'Order %s is not ready for processing yet.', 'woocommerce' ),
					$data['order_id']
				);

				$this->logger->log( 'error', $message );
				throw new RuntimeException( $message );
			}

			$funding_source = $data['funding_source'] ?? null;
			$this->session_handler->replace_funding_source( $funding_source );

			$this->session_handler->replace_order( $order );

			// Pin chosen_payment_method to PayPal now so concurrent Store API cart requests can't reset it.
			if ( WC()->session ) {
				WC()->session->set( 'chosen_payment_method', PayPalGateway::ID );
			}

			/**
			 * Filters whether the final review setting is toggled after the order is approved.
			 *
			 * @since 11.3.0
			 *
			 * @param bool $toggle Whether to toggle the setting; false by default.
			 */
			if ( apply_filters( 'woocommerce_paypal_payments_toggle_final_review_checkbox', false ) ) {
				$this->toggle_final_review_enabled_setting();
			}

			$should_create_wc_order = $data['should_create_wc_order'] ?? false;
			if ( ! $this->final_review_enabled && ! $this->context->is_checkout() && $should_create_wc_order ) {
				$wc_order = $this->wc_order_creator->create_from_paypal_order( $order, WC()->cart, $data );
				$this->gateway->process_payment( $wc_order->get_id() );
				$order_received_url = $wc_order->get_checkout_order_received_url();

				wp_send_json_success( array( 'order_received_url' => $order_received_url ) );
			}
			wp_send_json_success();

		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( Exception $error ) {
			$this->logger->error( 'Order approve failed: ' . $error->getMessage() );

			wp_send_json_error(
				array(
					'name'    => $error instanceof PayPalApiException ? $error->name() : '',
					'message' => $error->getMessage(),
					'code'    => $error->getCode(),
					'details' => $error instanceof PayPalApiException ? $error->details() : array(),
				)
			);

		}
	}

	/**
	 * Will toggle the "final confirmation" checkbox.
	 *
	 * @return void
	 */
	protected function toggle_final_review_enabled_setting(): void {
		$enable_pay_now = $this->settings_provider->enable_pay_now();
		$this->settings_model->set_enable_pay_now( ! $enable_pay_now );
		$this->settings_model->save();
	}
}
