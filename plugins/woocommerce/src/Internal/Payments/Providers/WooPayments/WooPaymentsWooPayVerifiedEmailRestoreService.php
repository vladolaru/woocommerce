<?php
/**
 * WooPaymentsWooPayVerifiedEmailRestoreService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Restores customer IDs detached during WooPay verified-email requests.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayVerifiedEmailRestoreService implements RegisterHooksInterface {

	/** Scheduled event used to restore a detached order customer ID. */
	private const RESTORE_CUSTOMER_ID_HOOK = 'woopay_restore_order_customer_id';

	/** Hook fired once WooCommerce order CRUD is ready. */
	private const ORDER_CRUD_READY_HOOK = 'woocommerce_after_register_post_type';

	/** Order meta storing the customer ID while an order is detached. */
	private const MERCHANT_CUSTOMER_ID_META = 'woopay_merchant_customer_id';

	/** Maximum number of marked orders restored per query. */
	private const DRAIN_BATCH_SIZE = 100;

	/**
	 * Service instance currently registered to the shared hooks.
	 *
	 * @var self|null
	 */
	private static ?self $registered_instance = null;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Blog IDs waiting for the order CRUD readiness callback.
	 *
	 * @var array<int,true>
	 */
	private array $pending_drain_blog_ids = array();

	/**
	 * Initialize the service.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register verified-email restoration hooks.
	 *
	 * @since 11.2.0
	 */
	public function register(): void {
		if ( null !== self::$registered_instance && self::$registered_instance !== $this ) {
			self::$registered_instance->unregister();
		}
		self::$registered_instance = $this;

		if ( false === has_action( self::RESTORE_CUSTOMER_ID_HOOK, array( $this, 'restore_order_customer_id' ) ) ) {
			add_action( self::RESTORE_CUSTOMER_ID_HOOK, array( $this, 'restore_order_customer_id' ), 10, 1 );
		}
		foreach ( array( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION ) as $option_name ) {
			foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
				$hook_name = "{$operation}_option_{$option_name}";
				if ( false === has_action( $hook_name, array( $this, 'handle_native_runtime_option_change' ) ) ) {
					add_action( $hook_name, array( $this, 'handle_native_runtime_option_change' ), 10, 0 );
				}
			}
		}
	}

	/**
	 * Restore one detached order customer ID.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $order_id Order ID from the scheduled event.
	 */
	public function restore_order_customer_id( $order_id ): void {
		if ( self::$registered_instance !== $this ) {
			return;
		}

		if ( NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $this->arbiter->get_runtime_owner() ) {
			return;
		}

		$order_id = $this->normalize_positive_integer( $order_id );
		if ( null === $order_id ) {
			return;
		}

		$this->restore_order( $order_id );
	}

	/**
	 * Restore every detached order on the current blog.
	 *
	 * @since 11.2.0
	 */
	public function drain_current_blog(): void {
		if ( self::$registered_instance !== $this ) {
			return;
		}

		if ( ! did_action( self::ORDER_CRUD_READY_HOOK ) ) {
			$this->pending_drain_blog_ids[ get_current_blog_id() ] = true;
			if ( false === has_action( self::ORDER_CRUD_READY_HOOK, array( $this, 'drain_current_blog' ) ) ) {
				add_action( self::ORDER_CRUD_READY_HOOK, array( $this, 'drain_current_blog' ), 10, 0 );
			}
			return;
		}

		remove_action( self::ORDER_CRUD_READY_HOOK, array( $this, 'drain_current_blog' ), 10 );
		if ( ! empty( $this->pending_drain_blog_ids ) ) {
			$is_pending_origin            = isset( $this->pending_drain_blog_ids[ get_current_blog_id() ] );
			$this->pending_drain_blog_ids = array();
			if ( ! $is_pending_origin ) {
				return;
			}
		}

		if ( NativePaymentsRuntimeArbiter::OWNER_NONE !== $this->arbiter->get_runtime_owner() ) {
			return;
		}

		wp_unschedule_hook( self::RESTORE_CUSTOMER_ID_HOOK );

		do {
			try {
				$order_ids = wc_get_orders(
					array(
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The bounded current-blog scan must find the existing durable marker.
						'meta_key' => self::MERCHANT_CUSTOMER_ID_META,
						'return'   => 'ids',
						'limit'    => self::DRAIN_BATCH_SIZE,
						'offset'   => 0,
					)
				);
			} catch ( Throwable $throwable ) {
				break;
			}
			if ( ! is_array( $order_ids ) ) {
				break;
			}
			$progress = false;

			foreach ( $order_ids as $order_id ) {
				$order_id = $this->normalize_positive_integer( $order_id );
				if ( null !== $order_id && $this->restore_order( $order_id ) ) {
					$progress = true;
				}
			}
		} while ( ! empty( $order_ids ) && $progress );
	}

	/**
	 * Restore detached orders when native runtime ownership is lost.
	 *
	 * @internal
	 */
	public function handle_native_runtime_option_change(): void {
		if ( self::$registered_instance !== $this ) {
			return;
		}

		$this->arbiter->invalidate();
		if ( NativePaymentsRuntimeArbiter::OWNER_NONE !== $this->arbiter->get_runtime_owner() ) {
			return;
		}

		$this->drain_current_blog();
	}

	/**
	 * Restore one order from its durable marker.
	 *
	 * @param int $order_id Order ID.
	 * @return bool True when a marker was consumed.
	 */
	private function restore_order( int $order_id ): bool {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! $order->meta_exists( self::MERCHANT_CUSTOMER_ID_META ) ) {
				return false;
			}

			$customer_id = $this->normalize_positive_integer( $order->get_meta( self::MERCHANT_CUSTOMER_ID_META ) );
			if ( null !== $customer_id ) {
				$order->set_customer_id( $customer_id );
			}

			$order->delete_meta_data( self::MERCHANT_CUSTOMER_ID_META );
			$order->save();

			$fresh_order = $this->reread_order_authoritatively( $order );
			return ! $fresh_order->meta_exists( self::MERCHANT_CUSTOMER_ID_META );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Reload an order from its active data store without relying on object caches.
	 *
	 * @param WC_Order $order Order to reload.
	 * @return WC_Order Fresh order state.
	 */
	private function reread_order_authoritatively( WC_Order $order ): WC_Order {
		$order_id = $order->get_id();

		clean_post_cache( $order_id );
		wp_cache_delete( WC_Order::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );

		/**
		 * Active order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $order->get_data_store();
		if ( is_callable( array( $data_store, 'clear_cached_data' ) ) ) {
			call_user_func( array( $data_store, 'clear_cached_data' ), array( $order_id ) );
		}

		$fresh_order = clone $order;
		$data_store->read( $fresh_order );
		/**
		 * Freshly read order.
		 *
		 * @var WC_Order $fresh_order
		 */
		$fresh_order->read_meta_data( true );

		return $fresh_order;
	}

	/**
	 * Normalize a positive integer-like value.
	 *
	 * @param mixed $value Candidate value.
	 * @return int|null
	 */
	private function normalize_positive_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}

		if ( ! is_string( $value ) || '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}

		$value = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );

		return false === $value ? null : $value;
	}

	/**
	 * Remove this service instance from the shared hooks.
	 */
	private function unregister(): void {
		remove_action( self::RESTORE_CUSTOMER_ID_HOOK, array( $this, 'restore_order_customer_id' ), 10 );
		remove_action( self::ORDER_CRUD_READY_HOOK, array( $this, 'drain_current_blog' ), 10 );
		$this->pending_drain_blog_ids = array();
		foreach ( array( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION ) as $option_name ) {
			foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
				remove_action( "{$operation}_option_{$option_name}", array( $this, 'handle_native_runtime_option_change' ), 10 );
			}
		}
	}
}
