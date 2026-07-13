<?php
/**
 * WooPaymentsWooPayOrderStatusSync class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
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
	 * Option storing the native-owned WooPay webhook ID.
	 *
	 * @var string
	 */
	private const WEBHOOK_ID_OPTION = 'woocommerce_native_woopayments_woopay_webhook_id';

	/**
	 * Option used to serialize native WooPay webhook creation.
	 *
	 * @var string
	 */
	private const WEBHOOK_LOCK_OPTION = 'woocommerce_native_woopayments_woopay_webhook_lock';

	/**
	 * Maximum age of a WooPay webhook creation lock.
	 *
	 * @var int
	 */
	private const WEBHOOK_LOCK_TTL = MINUTE_IN_SECONDS;

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

		if ( false === has_action( 'admin_init', array( $this, 'reconcile_webhook' ) ) ) {
			add_action( 'admin_init', array( $this, 'reconcile_webhook' ) );
		}

		if ( false === has_action( 'wcpay_store_setup_sync', array( $this, 'reconcile_webhook' ) ) ) {
			add_action( 'wcpay_store_setup_sync', array( $this, 'reconcile_webhook' ) );
		}
	}

	/**
	 * Reconcile the native-owned WooPay order-status webhook.
	 *
	 * @since 11.0.0
	 */
	public function reconcile_webhook(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( ! $this->session_service->is_woopay_enabled() ) {
			$this->remove_owned_webhook();
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->maybe_create_webhook();
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

	/**
	 * Create the native WooPay webhook when no valid owned row exists.
	 */
	private function maybe_create_webhook(): void {
		if ( $this->has_active_owned_webhook() ) {
			return;
		}

		$lock_token = $this->acquire_creation_lock();
		if ( null === $lock_token ) {
			return;
		}

		try {
			for ( $attempt = 0; $attempt < 2; $attempt++ ) {
				// @phpstan-ignore if.alwaysFalse (a reentrant publication seam can remove ownership before the bounded retry)
				if ( $this->has_active_owned_webhook() ) {
					return;
				}

				if ( ! $this->owns_creation_lock( $lock_token ) ) {
					return;
				}

				if ( ! $this->remove_owned_webhook() ) {
					return;
				}

				$webhook = new WC_Webhook();
				$webhook->set_name( __( 'WooPayments woopay order status sync', 'woocommerce' ) );
				$webhook->set_user_id( get_current_user_id() );
				$webhook->set_topic( self::WEBHOOK_TOPIC );
				$webhook->set_secret( wp_generate_password( 50, false ) );
				$webhook->set_delivery_url( $this->session_service->get_woopay_rest_url( 'merchant-notification' ) );
				$webhook->set_status( 'active' );
				$webhook->set_api_version( 'wp_api_v3' );

				$webhook_id = $this->save_webhook( $webhook );
				if ( $webhook_id <= 0 ) {
					$this->delete_unowned_webhook( $webhook );
					$this->log_reconciliation_error( 'Unable to create the WooPay order-status webhook.' );
					return;
				}

				// @phpstan-ignore booleanNot.alwaysFalse (the overridable save seam can transfer lock ownership)
				if ( ! $this->owns_creation_lock( $lock_token ) ) {
					$this->delete_unowned_webhook( $webhook );
					return;
				}

				$previous_webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
				if ( $previous_webhook_id && $previous_webhook_id !== $webhook_id ) {
					// @phpstan-ignore booleanNot.alwaysFalse (WC_Data deletion filters can veto persistence removal)
					if ( ! $this->remove_owned_webhook() ) {
						$this->delete_unowned_webhook( $webhook );
						return;
					}
				}

				// @phpstan-ignore booleanNot.alwaysFalse (deletion hooks can transfer lock ownership)
				if ( ! $this->owns_creation_lock( $lock_token ) ) {
					$this->delete_unowned_webhook( $webhook );
					return;
				}

				if ( ! $this->publish_webhook_ownership( $webhook_id ) ) {
					$this->delete_unowned_webhook( $webhook );
					$this->log_reconciliation_error( 'Unable to store ownership of the WooPay order-status webhook.' );
					return;
				}

				// @phpstan-ignore booleanNot.alwaysFalse (publication is an overridable persistence seam that may re-enter reconciliation)
				if ( ! $this->owns_creation_lock( $lock_token ) ) {
					return;
				}

				if ( ! $this->session_service->is_woopay_enabled() ) {
					$this->remove_owned_webhook();
					return;
				}

				if ( $this->has_active_owned_webhook( $webhook_id ) ) {
					return;
				}
			}

			$this->log_reconciliation_error( 'Unable to create the WooPay order-status webhook.' );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			if ( isset( $webhook ) ) {
				try {
					$this->delete_unowned_webhook( $webhook );
				} catch ( Throwable $cleanup_error ) {
					unset( $cleanup_error );
				}
			}
			$this->log_reconciliation_error( 'Unable to create the WooPay order-status webhook.' );
		} finally {
			$this->release_creation_lock( $lock_token );
		}
	}

	/**
	 * Check whether the native ownership option identifies an active webhook.
	 *
	 * @param int $expected_webhook_id Optional expected webhook ID.
	 * @return bool Whether an active owned webhook exists.
	 */
	private function has_active_owned_webhook( int $expected_webhook_id = 0 ): bool {
		$webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		if ( ! $webhook_id || ( $expected_webhook_id && $expected_webhook_id !== $webhook_id ) ) {
			return false;
		}

		$webhook = wc_get_webhook( $webhook_id );

		return $webhook instanceof WC_Webhook && 'active' === $webhook->get_status();
	}

	/**
	 * Remove only the webhook row identified by the native ownership option.
	 */
	private function remove_owned_webhook(): bool {
		$webhook_id = absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) );
		if ( ! $webhook_id ) {
			delete_option( self::WEBHOOK_ID_OPTION );
			return true;
		}

		try {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook instanceof WC_Webhook && ! $this->delete_webhook( $webhook ) ) {
				$this->log_reconciliation_error( 'Unable to remove the WooPay order-status webhook.' );
				return false;
			}

			if ( ! delete_option( self::WEBHOOK_ID_OPTION ) && false !== get_option( self::WEBHOOK_ID_OPTION, false ) ) {
				$this->log_reconciliation_error( 'Unable to remove ownership of the WooPay order-status webhook.' );
				return false;
			}

			return true;
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$this->log_reconciliation_error( 'Unable to remove the WooPay order-status webhook.' );
			return false;
		}
	}

	/**
	 * Atomically acquire the WooPay webhook creation lock.
	 *
	 * @since 11.0.0
	 *
	 * @return string|null Creation lock token, or null when another request owns the lock.
	 */
	protected function acquire_creation_lock(): ?string {
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'      => $token,
			'created_at' => time(),
		);
		if ( add_option( self::WEBHOOK_LOCK_OPTION, $lock, '', false ) ) {
			return $token;
		}

		$current_lock    = get_option( self::WEBHOOK_LOCK_OPTION, array() );
		$lock_created_at = is_array( $current_lock ) ? absint( $current_lock['created_at'] ?? 0 ) : absint( $current_lock );
		if ( ! $lock_created_at || $lock_created_at > time() - self::WEBHOOK_LOCK_TTL ) {
			return null;
		}

		return $this->replace_stale_creation_lock( $current_lock, $lock ) ? $token : null;
	}

	/**
	 * Replace a stale WooPay webhook creation lock.
	 *
	 * @since 11.0.0
	 *
	 * @param array $observed_lock Lock value observed as stale.
	 * @param array $replacement   Replacement lock value.
	 * @return bool Whether the replacement lock was acquired.
	 */
	protected function replace_stale_creation_lock( array $observed_lock, array $replacement ): bool {
		if ( get_option( self::WEBHOOK_LOCK_OPTION, array() ) !== $observed_lock ) {
			return false;
		}

		delete_option( self::WEBHOOK_LOCK_OPTION );

		return add_option( self::WEBHOOK_LOCK_OPTION, $replacement, '', false );
	}

	/**
	 * Release a WooPay webhook creation lock.
	 *
	 * @since 11.0.0
	 *
	 * @param string $token Creation lock token.
	 */
	protected function release_creation_lock( string $token ): void {
		if ( ! $this->owns_creation_lock( $token ) ) {
			return;
		}

		delete_option( self::WEBHOOK_LOCK_OPTION );
	}

	/**
	 * Check whether a token still owns the WooPay webhook creation lock.
	 *
	 * @param string $token Creation lock token.
	 * @return bool Whether the token is the current lock owner.
	 */
	private function owns_creation_lock( string $token ): bool {
		$current_lock = get_option( self::WEBHOOK_LOCK_OPTION, array() );

		return is_array( $current_lock ) && is_string( $current_lock['token'] ?? null ) && hash_equals( $current_lock['token'], $token );
	}

	/**
	 * Persist a WooCommerce webhook.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Webhook $webhook Webhook to persist.
	 * @return int Webhook ID.
	 */
	protected function save_webhook( WC_Webhook $webhook ): int {
		return (int) $webhook->save();
	}

	/**
	 * Publish ownership of a persisted WooPay webhook.
	 *
	 * @since 11.0.0
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return bool Whether ownership was published.
	 */
	protected function publish_webhook_ownership( int $webhook_id ): bool {
		return update_option( self::WEBHOOK_ID_OPTION, $webhook_id, false ) || absint( get_option( self::WEBHOOK_ID_OPTION, 0 ) ) === $webhook_id;
	}

	/**
	 * Delete a persisted webhook and return the actual deletion result.
	 *
	 * @param WC_Webhook $webhook Webhook to delete.
	 * @return bool Whether no persisted webhook remains.
	 */
	private function delete_webhook( WC_Webhook $webhook ): bool {
		if ( ! $webhook->get_id() ) {
			return true;
		}

		return true === $webhook->delete( true );
	}

	/**
	 * Delete an unowned webhook and log a vetoed cleanup.
	 *
	 * @param WC_Webhook $webhook Webhook to delete.
	 * @return bool Whether no persisted webhook remains.
	 */
	private function delete_unowned_webhook( WC_Webhook $webhook ): bool {
		if ( $this->delete_webhook( $webhook ) ) {
			return true;
		}

		$this->log_reconciliation_error( 'Unable to clean up an unowned WooPay order-status webhook.' );
		return false;
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
