<?php
/**
 * Processes orders for the gateways.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\OrderHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\PayPalOrderMissingException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Class OrderProcessor.
 */
class OrderProcessor {

	use OrderMetaTrait;
	use PaymentsStatusHandlingTrait;
	use TransactionIdHandlingTrait;

	/**
	 * The environment.
	 *
	 * @var Environment
	 */
	protected Environment $environment;
	/**
	 * The session handler.
	 *
	 * @var SessionHandler
	 */
	private SessionHandler $session_handler;
	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint
	 */
	private OrderEndpoint $order_endpoint;
	/**
	 * The order factory.
	 *
	 * @var OrderFactory
	 */
	private OrderFactory $order_factory;
	/**
	 * The authorized payments processor.
	 *
	 * @var AuthorizedPaymentsProcessor
	 */
	private AuthorizedPaymentsProcessor $authorized_payments_processor;
	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;
	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;
	/**
	 * The subscription helper.
	 *
	 * @var SubscriptionHelper
	 */
	private SubscriptionHelper $subscription_helper;
	/**
	 * The order helper.
	 *
	 * @var OrderHelper
	 */
	private OrderHelper $order_helper;
	/**
	 * The purchase unit factory.
	 *
	 * @var PurchaseUnitFactory
	 */
	private PurchaseUnitFactory $purchase_unit_factory;
	/**
	 * The payer factory.
	 *
	 * @var PayerFactory
	 */
	private PayerFactory $payer_factory;
	/**
	 * The shipping preference factory.
	 *
	 * @var ShippingPreferenceFactory
	 */
	private ShippingPreferenceFactory $shipping_preference_factory;

	/**
	 * Temporary order data changes to restore after processing.
	 *
	 * @var array
	 */
	private array $restore_order_data = array();
	/**
	 * The experience context builder.
	 *
	 * @var ExperienceContextBuilder
	 */
	private ExperienceContextBuilder $experience_context_builder;

	/**
	 * OrderProcessor constructor.
	 *
	 * @param SessionHandler              $session_handler               The session handler.
	 * @param OrderEndpoint               $order_endpoint                The order endpoint.
	 * @param OrderFactory                $order_factory                 The order factory.
	 * @param AuthorizedPaymentsProcessor $authorized_payments_processor The authorized payments processor.
	 * @param SettingsProvider            $settings_provider             The settings provider.
	 * @param LoggerInterface             $logger                        The logger.
	 * @param Environment                 $environment                   The environment.
	 * @param SubscriptionHelper          $subscription_helper           The subscription helper.
	 * @param OrderHelper                 $order_helper                  The order helper.
	 * @param PurchaseUnitFactory         $purchase_unit_factory         The purchase unit factory.
	 * @param PayerFactory                $payer_factory                 The payer factory.
	 * @param ShippingPreferenceFactory   $shipping_preference_factory   The shipping preference factory.
	 * @param ExperienceContextBuilder    $experience_context_builder    The experience context builder.
	 */
	public function __construct(
		SessionHandler $session_handler,
		OrderEndpoint $order_endpoint,
		OrderFactory $order_factory,
		AuthorizedPaymentsProcessor $authorized_payments_processor,
		SettingsProvider $settings_provider,
		LoggerInterface $logger,
		Environment $environment,
		SubscriptionHelper $subscription_helper,
		OrderHelper $order_helper,
		PurchaseUnitFactory $purchase_unit_factory,
		PayerFactory $payer_factory,
		ShippingPreferenceFactory $shipping_preference_factory,
		ExperienceContextBuilder $experience_context_builder
	) {

		$this->session_handler               = $session_handler;
		$this->order_endpoint                = $order_endpoint;
		$this->order_factory                 = $order_factory;
		$this->authorized_payments_processor = $authorized_payments_processor;
		$this->settings_provider             = $settings_provider;
		$this->environment                   = $environment;
		$this->logger                        = $logger;
		$this->subscription_helper           = $subscription_helper;
		$this->order_helper                  = $order_helper;
		$this->purchase_unit_factory         = $purchase_unit_factory;
		$this->payer_factory                 = $payer_factory;
		$this->shipping_preference_factory   = $shipping_preference_factory;
		$this->experience_context_builder    = $experience_context_builder;
	}

	/**
	 * Processes a given WooCommerce order and captured/authorizes the connected PayPal orders.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 *
	 * @throws PayPalOrderMissingException If no PayPal order.
	 * @throws Exception If processing fails.
	 */
	public function process( WC_Order $wc_order ): void {
		/**
		 * Fires before a processor makes its first PayPal call for a WooCommerce order.
		 *
		 * @since 11.3.0
		 *
		 * @param \WC_Order $wc_order The WooCommerce order.
		 */
		do_action( 'woocommerce_paypal_wallet_order_context', $wc_order );
		if ( ! $this->verify_order_can_be_processed( $wc_order ) ) {
			return;
		}

		if ( ! $this->acquire_processing_lock( $wc_order ) ) {
			return;
		}

		try {
			$order = $this->session_handler->order();
			if ( ! $order ) {
				$order_id = $wc_order->get_meta( PayPalGateway::ORDER_ID_META_KEY );
				if ( ! $order_id ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Not every caller carries a form nonce. The posted ID only selects which PayPal order to fetch, and is read only when the order meta and the session hold none. Guards by caller: classic checkout and pay-for-order by the WooCommerce form nonce (WC_Checkout::process_checkout(), WC_Form_Handler::pay_action()), the approve-order endpoint by its request nonce (RequestData::read_request()), the free-trial vault return by its one-time return nonce; the return-URL endpoint, the block checkout and the webhook carry no form nonce.
					$order_id = wc_clean( wp_unslash( $_POST['paypal_order_id'] ?? '' ) );
				}
				if ( is_string( $order_id ) && $order_id ) {
					try {
						$order = $this->order_endpoint->order( $order_id );
					} catch ( RuntimeException $exception ) {
						throw new Exception( __( 'Could not retrieve PayPal order.', 'woocommerce' ) );
					}
				} else {
					$is_paypal_return = isset( $_GET['wc-ajax'] ) && wc_clean( wp_unslash( $_GET['wc-ajax'] ) ) === 'ppc-return-url'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

					if ( $is_paypal_return ) {
						$this->logger->warning(
							sprintf(
								'No PayPal order ID found for WooCommerce order #%d.',
								$wc_order->get_id()
							)
						);
					}

					throw new PayPalOrderMissingException(
						esc_attr__(
							'There was an error processing your order. Please check for any charges in your payment method and review your order history before placing the order again.',
							'woocommerce'
						)
					);
				}
			}

			// Do not continue if PayPal order status is completed.
			$order = $this->order_endpoint->order( $order->id() );
			if ( $order->status()->is( OrderStatus::COMPLETED ) ) {
				$this->logger->warning( 'Could not process PayPal completed order #' . $order->id() . ', Status: ' . $order->status()->name() );
				return;
			}

			$this->add_paypal_meta( $wc_order, $order, $this->environment );

			if ( $this->order_helper->contains_physical_goods( $order ) && ! $this->order_is_ready_for_process( $order ) ) {
				throw new Exception(
					__(
						'The payment is not ready for processing yet.',
						'woocommerce'
					)
				);
			}

			$order = $this->patch_order( $wc_order, $order );

			if ( $order->intent() === 'CAPTURE' ) {
				$order = $this->order_endpoint->capture( $order );
			}

			if ( $order->intent() === 'AUTHORIZE' ) {
				$order = $this->order_endpoint->authorize( $order );

				$wc_order->update_meta_data( AuthorizedPaymentsProcessor::CAPTURED_META_KEY, 'false' );

				if ( $this->subscription_helper->has_subscription( $wc_order->get_id() ) ) {
					$wc_order->update_meta_data( '_ppcp_captured_vault_webhook', 'false' );
				}
			}

			$transaction_id = $this->get_paypal_order_transaction_id( $order );

			if ( $transaction_id ) {
				$this->update_transaction_id( $transaction_id, $wc_order );
			}

			$this->handle_new_order_status( $order, $wc_order );

			if ( $this->capture_authorized_downloads( $order ) ) {
				$this->authorized_payments_processor->capture_authorized_payment( $wc_order );
			}

			/**
			 * Fires after the PayPal order has been processed for a WooCommerce order.
			 *
			 * @since 11.3.0
			 *
			 * @param \WC_Order $wc_order The WooCommerce order.
			 * @param Order     $order    The PayPal order.
			 */
			do_action( 'woocommerce_paypal_payments_after_order_processor', $wc_order, $order );
		} finally {
			$this->release_processing_lock( $wc_order );
		}
	}

	/**
	 * Processes a given WooCommerce order and captured/authorizes the connected PayPal orders.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param Order    $order The PayPal order.
	 *
	 * @throws Exception If processing fails.
	 */
	public function process_captured_and_authorized( WC_Order $wc_order, Order $order ): void {
		$this->add_paypal_meta( $wc_order, $order, $this->environment );

		if ( $order->intent() === 'AUTHORIZE' ) {
			$wc_order->update_meta_data( AuthorizedPaymentsProcessor::CAPTURED_META_KEY, 'false' );

			if ( $this->subscription_helper->has_subscription( $wc_order->get_id() ) ) {
				$wc_order->update_meta_data( '_ppcp_captured_vault_webhook', 'false' );
			}
		}

		$transaction_id = $this->get_paypal_order_transaction_id( $order );

		if ( $transaction_id ) {
			$this->update_transaction_id( $transaction_id, $wc_order );
		}

		$this->handle_new_order_status( $order, $wc_order );

		if ( $this->capture_authorized_downloads( $order ) ) {
			$this->authorized_payments_processor->capture_authorized_payment( $wc_order );
		}

		/**
		 * Fires after the PayPal order has been processed for a WooCommerce order.
		 *
		 * @since 11.3.0
		 *
		 * @param \WC_Order $wc_order The WooCommerce order.
		 * @param Order     $order    The PayPal order.
		 */
		do_action( 'woocommerce_paypal_payments_after_order_processor', $wc_order, $order );
	}

	/**
	 * Creates a PayPal order for the given WC order.
	 *
	 * @param WC_Order    $wc_order The WC order.
	 * @param string      $funding_source The funding source (e.g. 'paypal', 'venmo').
	 * @param string|null $landing_page An ExperienceContext::LANDING_PAGE_* value to
	 *                                  use instead of the merchant's setting.
	 * @return Order
	 * @throws RuntimeException If order creation fails.
	 */
	public function create_order( WC_Order $wc_order, string $funding_source = 'paypal', ?string $landing_page = null ): Order {
		$pu                  = $this->purchase_unit_factory->from_wc_order( $wc_order );
		$shipping_preference = $this->shipping_preference_factory->from_state( $pu, 'checkout' );

		$experience_context = $this->experience_context_builder
			->with_default_paypal_config( $shipping_preference, ExperienceContext::USER_ACTION_PAY_NOW );

		if ( $landing_page ) {
			$experience_context = $experience_context->with_landing_page( $landing_page );
		}

		$order = $this->order_endpoint->create(
			array( $pu ),
			$shipping_preference,
			$this->payer_factory->from_wc_order( $wc_order ),
			$wc_order->get_payment_method(),
			array( 'funding_source' => $funding_source ),
			new PaymentSource(
				$funding_source,
				(object) array(
					'experience_context' => $experience_context->build()->to_array(),
				)
			)
		);

		return $order;
	}

	/**
	 * Patches a given PayPal order with a WooCommerce order.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param Order    $order The PayPal order.
	 *
	 * @return Order
	 * @throws PayPalApiException When PayPal rejects the patch and it cannot be safely skipped.
	 */
	public function patch_order( WC_Order $wc_order, Order $order ): Order {
		$this->apply_outbound_order_filters( $wc_order );
		$updated_order = $this->order_factory->from_wc_order( $wc_order, $order );
		$this->restore_order_from_filters( $wc_order );

		try {
			$order = $this->order_endpoint->patch_order_with( $order, $updated_order );
		} catch ( PayPalApiException $exception ) {
			/*
			 * An order that vaults a card against an existing PayPal customer becomes
			 * immutable once its payment source is confirmed. On block checkout that
			 * confirmation happens before the WooCommerce order exists, so this patch
			 * runs afterwards and PayPal rejects it with 422 VALIDATION_ERROR. The patch
			 * here only syncs metadata (custom_id/invoice_id); the amount was already
			 * final when the order was created from the cart. When the amount is
			 * unchanged it is therefore safe to skip the patch and capture the order
			 * as-is. Any other failure (including an actual amount change) is re-thrown.
			 */
			if ( $exception->status_code() !== 422 || ! $this->order_amount_unchanged( $order, $updated_order ) ) {
				throw $exception;
			}

			$this->logger->warning(
				sprintf(
					'Skipping order patch for order #%1$d: PayPal rejected it because the order is locked after confirming a vaulted payment, and the amount is unchanged so capture can proceed. %2$s',
					$wc_order->get_id(),
					$exception->getMessage()
				)
			);
		}

		return $order;
	}

	/**
	 * Determines whether the purchase unit amounts of two orders are identical,
	 * meaning a rejected patch would only have changed metadata (not the charge).
	 *
	 * @param Order $current The order as it currently exists at PayPal.
	 * @param Order $updated The order built from the WooCommerce order.
	 * @return bool
	 */
	private function order_amount_unchanged( Order $current, Order $updated ): bool {
		$current_units = $current->purchase_units();
		$updated_units = $updated->purchase_units();

		if ( count( $current_units ) !== count( $updated_units ) ) {
			return false;
		}

		foreach ( $updated_units as $index => $updated_unit ) {
			$current_unit = $current_units[ $index ] ?? null;
			if ( ! $current_unit instanceof PurchaseUnit ) {
				return false;
			}

			$current_amount = $current_unit->amount();
			$updated_amount = $updated_unit->amount();

			if (
				$current_amount->currency_code() !== $updated_amount->currency_code()
				|| $current_amount->value_str() !== $updated_amount->value_str()
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verifies whether the order can be processed.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @return bool
	 */
	private function verify_order_can_be_processed( WC_Order $wc_order ): bool {
		if ( $wc_order->get_transaction_id() ) {
			$this->logger->info(
				sprintf(
					'Order #%d already has transaction ID "%s", skipping payment processing.',
					$wc_order->get_id(),
					$wc_order->get_transaction_id()
				)
			);

			return false;
		}
		return true;
	}

	/**
	 * Atomically acquires a processing lock for the order.
	 *
	 * Uses direct SQL to ensure atomic lock acquisition, preventing race conditions
	 * where two concurrent processes could both acquire the lock.
	 * Stores an expiration timestamp instead of a simple flag, allowing stale locks
	 * from crashed processes to automatically expire.
	 * Supports both HPOS (wc_orders_meta) and legacy (postmeta) storage.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @return bool True if lock was acquired, false if already locked.
	 */
	private function acquire_processing_lock( WC_Order $wc_order ): bool {
		global $wpdb;

		$order_id     = $wc_order->get_id();
		$current_time = time();
		$expiration   = $current_time + 5 * MINUTE_IN_SECONDS;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table     = $wpdb->prefix . 'wc_orders_meta';
			$id_column = 'order_id';
		} else {
			$table     = $wpdb->postmeta;
			$id_column = 'post_id';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET meta_value = %d
				WHERE {$id_column} = %d
				AND meta_key = '_ppcp_processing'
				AND meta_value < %d",
				$expiration,
				$order_id,
				$current_time
			)
		);

		if ( $rows_updated > 0 ) {
			return true;
		}

		$rows_inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ({$id_column}, meta_key, meta_value)
				SELECT %d, '_ppcp_processing', %d
				FROM (SELECT 1) AS dummy
				WHERE NOT EXISTS (
					SELECT 1 FROM {$table} AS t WHERE t.{$id_column} = %d AND t.meta_key = '_ppcp_processing'
				)",
				$order_id,
				$expiration,
				$order_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $rows_inserted > 0 ) {
			return true;
		}

		$this->logger->warning(
			sprintf(
				'Order #%d is already being processed (lock active), skipping payment processing.',
				$order_id
			)
		);
		return false;
	}

	/**
	 * Releases the processing lock for the order.
	 *
	 * Supports both HPOS (wc_orders_meta) and legacy (postmeta) storage.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @return void
	 */
	private function release_processing_lock( WC_Order $wc_order ): void {
		global $wpdb;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table     = $wpdb->prefix . 'wc_orders_meta';
			$id_column = 'order_id';
		} else {
			$table     = $wpdb->postmeta;
			$id_column = 'post_id';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				$id_column => $wc_order->get_id(),
				'meta_key' => '_ppcp_processing', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Returns if an order should be captured immediately.
	 *
	 * @param Order $order The PayPal order.
	 *
	 * @return bool
	 */
	private function capture_authorized_downloads( Order $order ): bool {
		if ( ! $this->settings_provider->capture_virtual_orders() ) {
			return false;
		}

		if ( $order->intent() === 'CAPTURE' ) {
			return false;
		}

		/**
		 * We fetch the order again as the authorize endpoint (from which the Order derives)
		 * drops the item's category, making it impossible to check, if purchase units contain
		 * physical goods.
		 */
		$order = $this->order_endpoint->order( $order->id() );

		foreach ( $order->purchase_units() as $unit ) {
			if ( $unit->contains_physical_goods() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a given order is ready for processing.
	 *
	 * @param Order $order The order.
	 *
	 * @return bool
	 */
	private function order_is_ready_for_process( Order $order ): bool {
		return $order->status()->is( OrderStatus::APPROVED ) || $order->status()->is( OrderStatus::CREATED );
	}

	/**
	 * Applies filters to the WC_Order, so they are reflected only on PayPal Order.
	 *
	 * @param WC_Order $wc_order The WoocOmmerce Order.
	 * @return void
	 */
	private function apply_outbound_order_filters( WC_Order $wc_order ): void {
		$items = $wc_order->get_items();

		$this->restore_order_data['names'] = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WC_Order_Item ) {
				continue;
			}

			$original_name = $item->get_name();
			/**
			 * Filters the name of an order line item sent to PayPal.
			 *
			 * @since 11.3.0
			 *
			 * @param string $name     The line item name.
			 * @param int    $item_id  The order item ID.
			 * @param int    $order_id The WooCommerce order ID.
			 */
			$new_name = apply_filters( 'woocommerce_paypal_payments_order_line_item_name', $original_name, $item->get_id(), $wc_order->get_id() );

			if ( $new_name !== $original_name ) {
				$this->restore_order_data['names'][ $item->get_id() ] = $original_name;
				$item->set_name( $new_name );
			}
		}
	}

	/**
	 * Restores the WC_Order to it's state before filters.
	 *
	 * @param WC_Order $wc_order The WooCommerce Order.
	 * @return void
	 */
	private function restore_order_from_filters( WC_Order $wc_order ): void {
		if ( is_array( $this->restore_order_data['names'] ?? null ) ) {
			foreach ( $this->restore_order_data['names'] as $wc_item_id => $original_name ) {
				$wc_item = $wc_order->get_item( $wc_item_id, false );

				if ( $wc_item ) {
					$wc_item->set_name( $original_name );
				}
			}
		}
	}
}
