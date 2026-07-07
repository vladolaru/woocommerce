<?php
/**
 * WooPaymentsWooPayOrderStatusSync class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;
use WC_Webhook;

/**
 * Restores WooPay platform checkout order-status webhook compatibility.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayOrderStatusSync implements RegisterHooksInterface {

	/**
	 * Preserved WooPay order-status webhook action.
	 *
	 * @var string
	 */
	public const WEBHOOK_ACTION = 'wcpay_webhook_platform_checkout_order_status_changed';

	/**
	 * Preserved WooPay order-status webhook topic.
	 *
	 * @var string
	 */
	public const WEBHOOK_TOPIC = 'order.status_changed';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPay session service.
	 *
	 * @var WooPaymentsWooPaySessionService
	 */
	private WooPaymentsWooPaySessionService $session_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter    $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsWooPaySessionService $session_service WooPay session service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsWooPaySessionService $session_service ): void {
		$this->arbiter         = $arbiter;
		$this->session_service = $session_service;
	}

	/**
	 * Register WooPay order-status webhook compatibility hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_valid_webhook_resources', array( $this, 'add_resource' ) ) ) {
			add_filter( 'woocommerce_valid_webhook_resources', array( $this, 'add_resource' ) );
		}

		if ( false === has_filter( 'woocommerce_valid_webhook_events', array( $this, 'add_event' ) ) ) {
			add_filter( 'woocommerce_valid_webhook_events', array( $this, 'add_event' ) );
		}

		if ( false === has_filter( 'woocommerce_webhook_topic_hooks', array( $this, 'add_topics' ) ) ) {
			add_filter( 'woocommerce_webhook_topic_hooks', array( $this, 'add_topics' ), 20, 2 );
		}

		if ( false === has_filter( 'woocommerce_webhook_payload', array( $this, 'create_payload' ) ) ) {
			add_filter( 'woocommerce_webhook_payload', array( $this, 'create_payload' ), 10, 4 );
		}

		if ( false === has_action( 'woocommerce_order_status_changed', array( $this, 'send_webhook' ) ) ) {
			add_action( 'woocommerce_order_status_changed', array( $this, 'send_webhook' ), 10, 3 );
		}
	}

	/**
	 * Add the order webhook resource.
	 *
	 * The resource is a default WooCommerce resource, but the extension also
	 * registered it. Keeping the filter preserves the old extension surface.
	 *
	 * @param array $resources List of available resources.
	 * @return array
	 */
	public function add_resource( array $resources ): array {
		if ( ! in_array( 'order', $resources, true ) ) {
			$resources[] = 'order';
		}

		return $resources;
	}

	/**
	 * Add the WooPay order status changed webhook event.
	 *
	 * @param array $events List of available events.
	 * @return array
	 */
	public function add_event( array $events ): array {
		if ( ! in_array( 'status_changed', $events, true ) ) {
			$events[] = 'status_changed';
		}

		return $events;
	}

	/**
	 * Add the WooPay order-status topic hook mapping.
	 *
	 * @param array           $topic_hooks List of WooCommerce webhook topics and hooks.
	 * @param WC_Webhook|null $webhook     Webhook context.
	 * @return array
	 */
	public function add_topics( array $topic_hooks, ?WC_Webhook $webhook = null ): array {
		unset( $webhook );

		$hooks = $topic_hooks[ self::WEBHOOK_TOPIC ] ?? array();
		if ( ! in_array( self::WEBHOOK_ACTION, $hooks, true ) ) {
			$hooks[] = self::WEBHOOK_ACTION;
		}

		$topic_hooks[ self::WEBHOOK_TOPIC ] = $hooks;

		return $topic_hooks;
	}

	/**
	 * Rewrite WooPay merchant-notification webhook payloads.
	 *
	 * @param array  $payload       Data to be sent out by the webhook.
	 * @param string $resource_name Type/name of the resource.
	 * @param int    $resource_id   ID of the resource.
	 * @param int    $webhook_id    ID of the webhook.
	 * @return array
	 */
	public function create_payload( array $payload, string $resource_name, int $resource_id, int $webhook_id ): array {
		unset( $resource_name );

		$webhook = wc_get_webhook( $webhook_id );
		if ( ! $webhook instanceof WC_Webhook ) {
			return $payload;
		}

		$merchant_notification_url = $this->session_service->get_woopay_rest_url( 'merchant-notification' );
		if ( 0 !== strpos( $webhook->get_delivery_url(), $merchant_notification_url ) ) {
			return $payload;
		}

		return array(
			'blog_id'      => class_exists( '\Jetpack_Options' ) ? \Jetpack_Options::get_option( 'id' ) : false,
			'order_id'     => $resource_id,
			'order_status' => $payload['status'] ?? '',
		);
	}

	/**
	 * Trigger WooPay order-status webhook delivery for WooPay orders.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $previous_status Previous order status.
	 * @param string $next_status     New order status.
	 */
	public function send_webhook( int $order_id, string $previous_status, string $next_status ): void {
		unset( $previous_status );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( 'is_woopay' ) ) {
			return;
		}

		/**
		 * Fires when a WooPay platform checkout order status changes.
		 *
		 * @since 11.0.0
		 *
		 * @param int    $order_id    Order ID.
		 * @param string $next_status New order status.
		 */
		do_action( self::WEBHOOK_ACTION, $order_id, $next_status ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Preserved hook name is stored in a class constant for reuse.
	}
}
