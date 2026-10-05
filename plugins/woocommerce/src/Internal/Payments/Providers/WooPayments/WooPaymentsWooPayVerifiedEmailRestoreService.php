<?php
/**
 * WooPaymentsWooPayVerifiedEmailRestoreService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
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
				$this->get_logger()->log_throwable_always( 'WooPay verified-email restore could not list the detached orders.', $throwable );
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
	 * A failed restore is logged whatever the logging setting, with the IDs needed to restore the order by hand. Client 11.1.0
	 * catches nothing here (class-woopay-session.php:227-237): an Exception inside the save is caught and logged by core's
	 * WC_Abstract_Order::save() under the woocommerce source; any other failure escapes as a fatal. No retry.
	 *
	 * @param int $order_id Order ID.
	 * @return bool True when a marker was consumed.
	 */
	private function restore_order( int $order_id ): bool {
		$customer_id = null;
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

			$fresh_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
			if ( $fresh_order->meta_exists( self::MERCHANT_CUSTOMER_ID_META ) ) {
				$this->get_logger()->log_always(
					'WooPay verified-email restore did not save; the order may still be detached from its customer.',
					'error',
					array(
						'order_id'    => $order_id,
						'customer_id' => $customer_id,
					)
				);
				return false;
			}

			return true;
		} catch ( Throwable $throwable ) {
			$this->get_logger()->log_throwable_always(
				'WooPay verified-email restore failed; the order may still be detached from its customer.',
				$throwable,
				array(
					'order_id'    => $order_id,
					'customer_id' => $customer_id,
				)
			);
			return false;
		}
	}

	/**
	 * Get the WooPayments logger.
	 *
	 * @return WooPaymentsLogger
	 */
	private function get_logger(): WooPaymentsLogger {
		return wc_get_container()->get( WooPaymentsLogger::class );
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
