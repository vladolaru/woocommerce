<?php
/**
 * WooPaymentsWooPayOrderStatusSync class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
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
	 * Transient key of the lock that lets one request at a time create the webhook.
	 *
	 * @var string
	 */
	private const CLAIM_KEY = 'woocommerce_woopayments_woopay_webhook_claim';

	/**
	 * Lifetime of the creation lock, longer than the platform call it covers (70 seconds at most) plus the save.
	 *
	 * @var int
	 */
	private const CLAIM_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * WooCommerce logger source.
	 *
	 * @var string
	 */
	private const LOGGER_SOURCE = 'woocommerce-woopayments';

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
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Lock held in the database rows of a transient.
	 *
	 * @var TransientRowLock
	 */
	private TransientRowLock $row_lock;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter    $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsWooPaySessionService $session_service WooPay session service.
	 * @param WooPaymentsApiClient            $api_client      WooPayments API client.
	 * @param TransientRowLock                $row_lock        Lock held in the database rows of a transient.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsWooPaySessionService $session_service, WooPaymentsApiClient $api_client, TransientRowLock $row_lock ): void {
		$this->arbiter         = $arbiter;
		$this->session_service = $session_service;
		$this->api_client      = $api_client;
		$this->row_lock        = $row_lock;
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

		if ( false === has_action( 'admin_init', array( $this, 'maybe_create_woopay_order_webhook' ) ) ) {
			add_action( 'admin_init', array( $this, 'maybe_create_woopay_order_webhook' ) );
		}

		// Client 11.1.0 removes the webhook on account refresh and on the WooPay-disable settings change, not per admin page.
		if ( false === has_action( 'woocommerce_payments_account_refreshed', array( $this, 'reconcile_webhook' ) ) ) {
			add_action( 'woocommerce_payments_account_refreshed', array( $this, 'reconcile_webhook' ) );
		}

		if ( false === has_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $this, 'handle_settings_update' ) ) ) {
			add_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $this, 'handle_settings_update' ), 10, 2 );
		}
	}

	/**
	 * Reconcile the WooPay order-status webhook with the account and the WooPay setting.
	 *
	 * @since 11.0.0
	 */
	public function reconcile_webhook(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( ! $this->session_service->is_woopay_enabled() ) {
			$this->remove_woopay_webhooks();
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->maybe_create_webhook();
	}

	/**
	 * Create the webhook on admin pages only while WooPay is on, so admin pages read nothing while it is off.
	 *
	 * @since 11.2.0
	 */
	public function maybe_create_woopay_order_webhook(): void {
		if ( $this->arbiter->should_native_register() && $this->session_service->is_woopay_enabled() ) {
			$this->reconcile_webhook();
		}
	}

	/**
	 * Remove the WooPay webhooks when a settings save turns WooPay off.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $old_value Previous gateway settings.
	 * @param mixed $value     New gateway settings.
	 */
	public function handle_settings_update( $old_value, $value ): void {
		$was_enabled = is_array( $old_value ) && 'yes' === ( $old_value['platform_checkout'] ?? null );
		$is_enabled  = is_array( $value ) && 'yes' === ( $value['platform_checkout'] ?? null );
		if ( $was_enabled && ! $is_enabled && $this->arbiter->should_native_register() ) {
			$this->remove_woopay_webhooks();
		}
	}

	/**
	 * Add the order webhook resource.
	 *
	 * The resource is a default WooCommerce resource, but the extension also
	 * registered it. Keeping the filter preserves the old extension surface.
	 *
	 * @param mixed $resources List of available resources.
	 * @return mixed
	 */
	public function add_resource( $resources ) {
		if ( ! is_array( $resources ) ) {
			return $resources;
		}

		if ( ! in_array( 'order', $resources, true ) ) {
			$resources[] = 'order';
		}

		return $resources;
	}

	/**
	 * Add the WooPay order status changed webhook event.
	 *
	 * @param mixed $events List of available events.
	 * @return mixed
	 */
	public function add_event( $events ) {
		if ( ! is_array( $events ) ) {
			return $events;
		}

		if ( ! in_array( 'status_changed', $events, true ) ) {
			$events[] = 'status_changed';
		}

		return $events;
	}

	/**
	 * Add the WooPay order-status topic hook mapping.
	 *
	 * @param mixed $topic_hooks List of WooCommerce webhook topics and hooks.
	 * @param mixed $webhook     Webhook context.
	 * @return mixed
	 */
	public function add_topics( $topic_hooks, $webhook = null ) {
		if ( ! is_array( $topic_hooks ) ) {
			return $topic_hooks;
		}

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
	 * @param mixed $payload       Data to be sent out by the webhook.
	 * @param mixed $resource_name Type/name of the resource.
	 * @param mixed $resource_id   ID of the resource.
	 * @param mixed $webhook_id    ID of the webhook.
	 * @return mixed
	 */
	public function create_payload( $payload, $resource_name, $resource_id, $webhook_id ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

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
	 * @param mixed $order_id        Order ID.
	 * @param mixed $previous_status Previous order status.
	 * @param mixed $next_status     New order status.
	 */
	public function send_webhook( $order_id, $previous_status, $next_status ): void {
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

	/**
	 * Create the WooPay webhook unless exactly one already exists.
	 *
	 * The row is the state the plugin and native share, so it is identified by what it is, not by who wrote it: a
	 * store switching from the plugin keeps the plugin's row, whose secret WooPay already holds. Several rows are
	 * replaced by one, since WooPay holds at most one of their secrets and nothing tells which.
	 */
	private function maybe_create_webhook(): void {
		if ( 1 === count( $this->find_woopay_webhook_ids() ) ) {
			return;
		}

		$token = wp_generate_uuid4();
		if ( ! $this->row_lock->claim( self::CLAIM_KEY, $token, self::CLAIM_TTL ) ) {
			// Another request is creating the webhook; the next admin page checks again.
			return;
		}

		try {
			$webhook_ids = $this->find_woopay_webhook_ids();
			if ( 1 !== count( $webhook_ids ) && $this->delete_webhooks( $webhook_ids ) ) {
				$this->create_webhook();
			}
		} finally {
			$this->row_lock->release( self::CLAIM_KEY, $token );
		}
	}

	/**
	 * Create the WooPay webhook row and register its secret with the platform, as client 11.1.0 does.
	 *
	 * A failed registration deletes the row, so the next admin page retries the whole pair.
	 */
	private function create_webhook(): void {
		$webhook = new WC_Webhook();
		$webhook->set_name( $this->get_webhook_name() );
		$webhook->set_user_id( get_current_user_id() );
		$webhook->set_topic( self::WEBHOOK_TOPIC );
		$webhook->set_secret( wp_generate_password( 50, false ) );
		$webhook->set_delivery_url( $this->session_service->get_woopay_rest_url( 'merchant-notification' ) );
		$webhook->set_status( 'active' );
		$webhook->set_api_version( 'wp_api_v3' );

		if ( 0 >= $webhook->save() ) {
			$this->log_reconciliation_error( 'Unable to create the WooPay order-status webhook.' );
			return;
		}

		try {
			$this->api_client->update_woopay( array( 'webhook_secret' => $webhook->get_secret() ) );
		} catch ( WooPaymentsApiException $api_exception ) {
			unset( $api_exception );
			$this->delete_webhooks( array( $webhook->get_id() ) );
			$this->log_reconciliation_error( 'Unable to register the WooPay order-status webhook secret with the platform.' );
		}
	}

	/**
	 * Get the webhook name: the plugin's own, in the plugin's text domain.
	 *
	 * The plugin finds its row by this translated name (client 11.1.0 class-woopay-order-status-sync.php:69-71, :102-113), and
	 * WordPress loads an inactive plugin's installed language pack just in time, so a reactivated plugin finds the row
	 * native wrote in every locale instead of creating a second one.
	 *
	 * @return string
	 */
	private function get_webhook_name(): string {
		return __( 'WooPayments woopay order status sync', 'woocommerce-payments' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Backward compatibility: a reactivated WooPayments plugin finds this row by its own translated name, loaded just in time from its language pack.
	}

	/**
	 * Find the active WooPay order-status webhooks, whoever created them.
	 *
	 * A webhook is WooPay's when it is active, on the order-status topic and delivers to WooPay's merchant-notification
	 * endpoint, the same delivery URL rule create_payload() uses. The query skips WooCommerce's webhook caches, so a
	 * re-check under the creation lock sees rows other requests just wrote.
	 *
	 * @return int[] Webhook IDs, oldest first.
	 */
	private function find_woopay_webhook_ids(): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The re-check under the creation lock must see rows other requests just wrote.
				$wpdb->prepare(
					"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE status = 'active' AND topic = %s AND delivery_url LIKE %s ORDER BY webhook_id",
					self::WEBHOOK_TOPIC,
					$wpdb->esc_like( $this->session_service->get_woopay_rest_url( 'merchant-notification' ) ) . '%'
				)
			)
		);
	}

	/**
	 * Remove every active WooPay order-status webhook, the plugin's included.
	 */
	private function remove_woopay_webhooks(): void {
		$this->delete_webhooks( $this->find_woopay_webhook_ids() );
	}

	/**
	 * Delete webhook rows and log a deletion a filter vetoed.
	 *
	 * @param int[] $webhook_ids Webhook IDs.
	 * @return bool Whether every row is gone.
	 */
	private function delete_webhooks( array $webhook_ids ): bool {
		$deleted = true;
		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook instanceof WC_Webhook && true !== $webhook->delete( true ) ) {
				$this->log_reconciliation_error( 'Unable to remove a WooPay order-status webhook.' );
				$deleted = false;
			}
		}

		return $deleted;
	}

	/**
	 * Log a safe WooPay webhook reconciliation error.
	 *
	 * @param string $message Error message.
	 */
	private function log_reconciliation_error( string $message ): void {
		wc_get_logger()->error(
			$message,
			array( 'source' => self::LOGGER_SOURCE )
		);
	}
}
