<?php
/**
 * WooCommerce Subscriptions doubles for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * Defines stand-ins for the parts of WooCommerce Subscriptions' public API the Stripe Billing module and the native WooPayments checkout call.
 *
 * WooCommerce Subscriptions is not installed in the test environment. PHPUnit includes every file under `tests/php`
 * when it builds the suite, so the doubles are only defined when a test calls `load()`. Each double answers as if
 * nothing were a subscription until a test registers it, so doubles left defined change nothing for later tests.
 *
 * Once loaded, these symbols stay defined for the rest of the PHPUnit run, since the tests that load them run in-process.
 * The full native suite is verified to pass in both default and reverse order with them loaded this way.
 *
 * Never define `WC_Subscriptions` or `WC_Subscriptions_Core_Plugin` here: other tests rely on their absence to mean that
 * WooCommerce Subscriptions is inactive.
 */
final class WooCommerceSubscriptionsDoubles {

	/**
	 * Global holding the IDs of products that `WC_Subscriptions_Product::is_subscription()` reports as subscriptions.
	 */
	public const SUBSCRIPTION_PRODUCT_IDS = 'wcpay_test_subscription_product_ids';

	/**
	 * Global holding the IDs of the orders loaded as `SubscriptionDouble`; other native tests read it in their `wcs_is_subscription()` double.
	 */
	public const SUBSCRIPTION_IDS = 'wcpay_test_subscription_ids';

	/**
	 * Global holding, per order ID, the subscription IDs `wcs_get_subscriptions_for_order()` returns for each relation (`parent`, `renewal`, ...).
	 *
	 * A `parent`, `resubscribe` or `switch` relation also makes `wcs_order_contains_subscription()` true; a `renewal` relation does
	 * not, and `wcs_order_contains_renewal()` reads its own global, so a test can relate a subscription without making the order recurring.
	 */
	public const ORDER_SUBSCRIPTIONS = 'wcpay_test_order_subscription_relationships';

	/**
	 * Global holding, per renewal order ID, the subscription IDs `wcs_get_subscriptions_for_renewal_order()` returns; the gateway tests read it too.
	 */
	public const RENEWAL_SUBSCRIPTIONS = 'wcpay_test_renewal_subscription_ids';

	/**
	 * Global that makes `WCS_Staging::is_duplicate_site()` report a staging copy when true.
	 */
	public const DUPLICATE_SITE = 'wcpay_test_duplicate_site';

	/**
	 * Global that makes `wcs_create_renewal_order()` fail with a `WP_Error` when true.
	 */
	public const RENEWAL_ORDER_ERROR = 'wcpay_test_renewal_order_error';

	/**
	 * Global that loads subscriptions as WooCommerce Subscriptions before 7.9.0 has them, without the related-order failure, when true.
	 */
	public const BEFORE_RELATED_ORDER_FAILURE = 'wcpay_test_before_related_order_failure';

	/**
	 * Global that makes `WC_Subscriptions_Cart::cart_contains_subscription()` report a subscription cart when true.
	 */
	public const CART_CONTAINS_SUBSCRIPTION = 'wcpay_test_cart_contains_subscription';

	/**
	 * Global holding what `wcs_cart_contains_renewal()` returns; `false`, meaning no renewal, when unset.
	 */
	public const CART_CONTAINS_RENEWAL = 'wcpay_test_cart_contains_renewal';

	/**
	 * Global holding what `wcs_cart_contains_resubscribe()` returns; `false`, meaning no resubscribe, when unset.
	 */
	public const CART_CONTAINS_RESUBSCRIBE = 'wcpay_test_cart_contains_resubscribe';

	/**
	 * Global holding what `wcs_cart_contains_switches()` returns; `false`, meaning no switch, when unset.
	 */
	public const CART_CONTAINS_SWITCHES = 'wcpay_test_cart_contains_switches';

	/**
	 * Global flag `wcs_is_manual_renewal_required()` returns, set when the store turns off automatic payments.
	 */
	public const MANUAL_RENEWAL_REQUIRED = 'wcpay_test_manual_renewal_required';

	/**
	 * Define the doubles that are not defined yet, and load registered subscriptions as `SubscriptionDouble` until the test ends.
	 */
	public static function load(): void {
		self::load_product();

		self::load_order_subscriptions();

		if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the gateway tests define, reading the same registry.
			eval( 'namespace { function wcs_get_subscriptions_for_renewal_order( $order_id ) { $ids = $GLOBALS["' . self::RENEWAL_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); return array_map( "wc_get_order", $ids ); } }' );
		}

		self::load_subscription_detector();

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public subscription lookup.
			eval( 'namespace { function wcs_get_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : absint( $subscription_id ); return in_array( $subscription_id, $GLOBALS["' . self::SUBSCRIPTION_IDS . '"] ?? array(), true ) ? wc_get_order( $subscription_id ) : false; } }' );
		}

		if ( ! class_exists( 'WC_Subscriptions_Synchroniser' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; renewal synchronisation stays off, its default.
			eval( 'namespace { class WC_Subscriptions_Synchroniser { public static function is_syncing_enabled() { return false; } } }' );
		}

		if ( ! class_exists( 'WCS_Staging' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its staging check, off unless a test turns it on.
			eval( 'namespace { class WCS_Staging { public static function is_duplicate_site() { return ! empty( $GLOBALS["' . self::DUPLICATE_SITE . '"] ); } public static function get_site_url_from_source( $source = "current_wp_site" ) { return "subscriptions_install" === $source ? "https://live.rec-t63.test" : "https://staging.rec-t63.test"; } } }' );
		}

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its subscription query.
			eval( 'namespace { function wcs_get_subscriptions( $args ) { return \\' . self::class . '::get_subscriptions( $args ); } }' );
		}

		if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its renewal order creation.
			eval( 'namespace { function wcs_create_renewal_order( $subscription ) { return \\' . self::class . '::create_renewal_order( $subscription ); } }' );
		}

		// The test's hook snapshot removes this filter when the test ends.
		add_filter( 'woocommerce_order_class', array( self::class, 'get_subscription_order_class' ), 10, 3 );
	}

	/**
	 * Define `wcs_get_subscriptions_for_order()`, which returns the subscriptions `ORDER_SUBSCRIPTIONS` relates to an order.
	 *
	 * It stands in for WooCommerce Subscriptions 9.0.1 `includes/core/wcs-order-functions.php:33`, which reads the order's
	 * stored subscription relations. The registry may hold an `ArrayAccess` object in place of an array, so a test can see
	 * each lookup as it happens.
	 */
	public static function load_order_subscriptions(): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_get_subscriptions_for_order( $order_id, $args = array() ) { $order_id = is_object( $order_id ) && method_exists( $order_id, "get_id" ) ? $order_id->get_id() : absint( $order_id ); $order_types = $args["order_type"] ?? array( "parent", "switch" ); $order_types = is_array( $order_types ) ? $order_types : array( $order_types ); $relationships = $GLOBALS["' . self::ORDER_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); $order_types = in_array( "any", $order_types, true ) ? array_keys( $relationships ) : $order_types; $ids = array(); foreach ( $order_types as $order_type ) { $ids = array_merge( $ids, $relationships[ $order_type ] ?? array() ); } return array_values( array_filter( array_map( "wc_get_order", array_unique( array_map( "absint", $ids ) ) ) ) ); } }' );
		}
	}

	/**
	 * Define `wcs_order_contains_renewal()`, which reports only the order IDs in the `wcpay_test_renewal_order_ids` global as renewals.
	 *
	 * WooCommerce Subscriptions 9.0.1 `includes/core/wcs-renewal-functions.php:90` derives it from the order's `renewal`
	 * relation, the same relation `wcs_get_subscriptions_for_order()` reads. The two doubles keep separate registries only for
	 * test isolation: a test can make an order a renewal, or relate a renewal subscription to it, without the other.
	 */
	public static function load_renewal_detector(): void {
		if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_order_contains_renewal( $order ) { $order_id = is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : absint( $order ); return in_array( $order_id, $GLOBALS["wcpay_test_renewal_order_ids"] ?? array(), true ); } }' );
		}
	}

	/**
	 * Define the product helper, which reports only the products registered in `SUBSCRIPTION_PRODUCT_IDS` as subscriptions.
	 */
	public static function load_product(): void {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public product helper in the global namespace.
			eval( 'namespace { class WC_Subscriptions_Product { public static function is_subscription( $product ) { $product_id = $product instanceof WC_Product ? $product->get_id() : absint( $product ); return in_array( $product_id, $GLOBALS["' . self::SUBSCRIPTION_PRODUCT_IDS . '"] ?? array(), true ); } public static function get_sign_up_fee( $product ) { return $product instanceof WC_Product ? (float) $product->get_meta( "_subscription_sign_up_fee" ) : 0; } public static function needs_one_time_shipping( $product ) { return $product instanceof WC_Product && "yes" === $product->get_meta( "_subscription_one_time_shipping" ); } } }' );
		}
	}

	/**
	 * Define `wcs_is_subscription()`, which reports only the IDs registered in `SUBSCRIPTION_IDS` as subscriptions.
	 *
	 * Unlike `load()`, it leaves orders loading as ordinary orders.
	 */
	public static function load_subscription_detector(): void {
		if ( ! function_exists( 'wcs_is_subscription' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Same double as the other native tests define, reading the same registry.
			eval( 'namespace { function wcs_is_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : $subscription_id; return in_array( absint( $subscription_id ), $GLOBALS["' . self::SUBSCRIPTION_IDS . '"] ?? array(), true ); } }' );
		}
	}

	/**
	 * Define `wcs_order_contains_subscription()` and `wcs_is_manual_renewal_required()`.
	 *
	 * An order contains a subscription when `ORDER_SUBSCRIPTIONS` relates one to it as parent, resubscribe or switch (the
	 * real function's default types, WooCommerce Subscriptions includes/core/wcs-order-functions.php:421), or when its ID
	 * is in `SUBSCRIPTION_IDS`, the shortcut the gateway adapter tests use. Manual renewal follows `MANUAL_RENEWAL_REQUIRED`
	 * (the store setting the real function reads, includes/core/wcs-renewal-functions.php:233-234).
	 */
	public static function load_order_detector(): void {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public order detector.
			eval( 'namespace { function wcs_order_contains_subscription( $order, $order_type = array( "parent", "resubscribe", "switch" ) ) { $order_id = is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : absint( $order ); if ( in_array( $order_id, $GLOBALS["' . self::SUBSCRIPTION_IDS . '"] ?? array(), true ) ) { return true; } $relationships = $GLOBALS["' . self::ORDER_SUBSCRIPTIONS . '"][ $order_id ] ?? array(); $order_types = in_array( "any", (array) $order_type, true ) ? array_keys( $relationships ) : (array) $order_type; foreach ( $order_types as $type ) { if ( ! empty( $relationships[ $type ] ) ) { return true; } } return false; } }' );
		}

		if ( ! function_exists( 'wcs_is_manual_renewal_required' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its store renewal setting.
			eval( 'namespace { function wcs_is_manual_renewal_required() { return ! empty( $GLOBALS["' . self::MANUAL_RENEWAL_REQUIRED . '"] ); } }' );
		}
	}

	/**
	 * Define the cart detectors, which report an ordinary cart until a test sets their `CART_CONTAINS_*` globals.
	 */
	public static function load_cart(): void {
		if ( ! class_exists( 'WC_Subscriptions_Cart' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public cart contract.
			eval( 'namespace { class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return (bool) ( $GLOBALS["' . self::CART_CONTAINS_SUBSCRIPTION . '"] ?? false ); } } }' );
		}

		$detectors = array(
			'wcs_cart_contains_renewal'     => self::CART_CONTAINS_RENEWAL,
			'wcs_cart_contains_resubscribe' => self::CART_CONTAINS_RESUBSCRIBE,
			'wcs_cart_contains_switches'    => self::CART_CONTAINS_SWITCHES,
		);
		foreach ( $detectors as $function_name => $global_name ) {
			if ( ! function_exists( $function_name ) ) {
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public cart detectors.
				eval( 'namespace { function ' . $function_name . '() { return $GLOBALS["' . $global_name . '"] ?? false; } }' );
			}
		}
	}

	/**
	 * Define the payment method change handler, with the request flag the Stripe Billing module saves and restores.
	 *
	 * The other members match the doubles the gateway and checkout tests define, so whichever is defined first serves all of them.
	 */
	public static function load_change_payment_gateway(): void {
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::reset();
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need a process-local stand-in.
		eval(
			<<<'PHP'
			namespace {
			class WC_Subscriptions_Change_Payment_Gateway {
				public static $is_request_to_change_payment = false;
				public static $updated_payment_methods = array();
				public static $updated_all_payment_methods = array();
				public static $will_update_all_payment_methods = true;

				public static function reset() {
					self::$is_request_to_change_payment = false;
					self::$updated_payment_methods = array();
					self::$updated_all_payment_methods = array();
					self::$will_update_all_payment_methods = true;
				}

				public static function update_payment_method( $order, $gateway_id ) {
					self::$updated_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
				}

				public static function will_subscription_update_all_payment_methods( $order ) {
					unset( $order );
					return self::$will_update_all_payment_methods;
				}

				public static function update_all_payment_methods_from_subscription( $order, $gateway_id ) {
					self::$updated_all_payment_methods[] = array(
						"order_id" => is_object( $order ) && method_exists( $order, "get_id" ) ? $order->get_id() : 0,
						"gateway_id" => $gateway_id,
					);
					return true;
				}
			}
			}
			PHP
		);
	}

	/**
	 * Define WooCommerce Subscriptions' background repairer classes, with their WooCommerce Subscriptions 9.0.1 logic, and its order query.
	 *
	 * Defining them makes the Stripe Billing module build its migrator, so only tests that run in a separate process call this.
	 */
	public static function load_background_repairer(): void {
		if ( ! defined( 'WCS_INIT_TIMESTAMP' ) ) {
			define( 'WCS_INIT_TIMESTAMP', time() );
		}

		if ( ! function_exists( 'wcs_get_orders_with_meta_query' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its order query.
			eval( 'namespace { function wcs_get_orders_with_meta_query( $args ) { return \\' . self::class . '::get_orders_with_meta_query( $args ); } }' );
		}

		if ( class_exists( 'WCS_Background_Repairer', false ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need a process-local stand-in.
		eval(
			<<<'PHP'
			namespace {
			abstract class WCS_Background_Updater {
				protected $time_limit;
				protected $scheduled_hook;

				public function init() {
					if ( is_null( $this->scheduled_hook ) ) {
						throw new RuntimeException( __CLASS__ . ' must assign a hook to $this->scheduled_hook' );
					}
					if ( is_null( $this->time_limit ) ) {
						$this->time_limit = 60;
					}
					$this->time_limit = apply_filters( 'wcs_debug_tools_time_limit', $this->time_limit, $this );
					add_action( $this->scheduled_hook, array( $this, 'run_update' ) );
				}

				abstract protected function get_items_to_update();

				abstract protected function update_item( $item );

				public function run_update() {
					$this->schedule_background_update();
					$start_time = $this->is_wp_cli_request() ? (int) gmdate( 'U' ) : WCS_INIT_TIMESTAMP;
					do {
						$items = $this->get_items_to_update();
						foreach ( $items as $item ) {
							$this->update_item( $item );
							if ( (int) gmdate( 'U' ) - $start_time >= $this->time_limit ) {
								break 2;
							}
						}
					} while ( ! empty( $items ) );
					if ( empty( $items ) ) {
						$this->unschedule_background_updates();
					}
				}

				protected function schedule_background_update() {
					if ( ! is_numeric( as_next_scheduled_action( $this->scheduled_hook ) ) ) {
						as_schedule_single_action( (int) gmdate( 'U' ) + $this->time_limit, $this->scheduled_hook );
					}
				}

				protected function unschedule_background_updates() {
					as_unschedule_action( $this->scheduled_hook );
				}

				protected function is_wp_cli_request() {
					return ( defined( 'WP_CLI' ) && WP_CLI );
				}
			}

			abstract class WCS_Background_Upgrader extends WCS_Background_Updater {
				protected $logger;
				protected $log_handle;

				public function schedule_repair() {
					$this->schedule_background_update();
				}

				protected function log( $message ) {
					$this->logger->add( $this->log_handle, $message );
				}
			}

			abstract class WCS_Background_Repairer extends WCS_Background_Upgrader {
				protected $repair_hook;
				protected $items_to_repair = array();

				public function init() {
					parent::init();
					add_action( $this->repair_hook, array( $this, 'repair_item' ) );
				}

				public function schedule_repair() {
					$this->set_page( 1 );
					parent::schedule_repair();
				}

				protected function get_items_to_update() {
					$items_to_repair   = array();
					$unprocessed_items = $this->get_unprocessed_items();
					if ( ! empty( $unprocessed_items ) ) {
						$items_to_repair = $unprocessed_items;
						$this->clear_unprocessed_items_cache();
					} elseif ( $page = $this->get_page() ) {
						$items_to_repair = $this->get_items_to_repair( $page );
						$this->set_page( $page + 1 );
					}
					$this->items_to_repair = array_flip( $items_to_repair );
					return $items_to_repair;
				}

				public function run_update() {
					parent::run_update();
					$this->save_unprocessed_items();
				}

				protected function update_item( $item ) {
					as_schedule_single_action( (int) gmdate( 'U' ) + HOUR_IN_SECONDS, $this->repair_hook, array( 'repair_object' => $item ) );
					unset( $this->items_to_repair[ $item ] );
				}

				protected function get_page() {
					return absint( get_option( "{$this->repair_hook}_page", 0 ) );
				}

				protected function set_page( $page ) {
					update_option( "{$this->repair_hook}_page", (string) $page );
				}

				protected function get_unprocessed_items() {
					return get_option( "{$this->repair_hook}_unprocessed", array() );
				}

				protected function save_unprocessed_items() {
					if ( ! empty( $this->items_to_repair ) ) {
						update_option( "{$this->repair_hook}_unprocessed", array_flip( $this->items_to_repair ) );
					}
				}

				protected function clear_unprocessed_items_cache() {
					delete_option( "{$this->repair_hook}_unprocessed" );
				}

				protected function unschedule_background_updates() {
					parent::unschedule_background_updates();
					delete_option( "{$this->repair_hook}_page" );
				}

				abstract protected function repair_item( $item );

				abstract protected function get_items_to_repair( $page );
			}
			}
			PHP
		);
	}

	/**
	 * Find registered subscription IDs matching a `meta_query` of `EXISTS` and `=` clauses, paged and in ascending ID order, as `wcs_get_orders_with_meta_query()` does with `return` set to `ids`.
	 *
	 * @param array<string,mixed> $args Query arguments: `meta_query`, `limit` and `paged`.
	 * @return int[]
	 */
	public static function get_orders_with_meta_query( array $args ): array {
		$meta_query = $args['meta_query'] ?? array();
		$relation   = $meta_query['relation'] ?? 'AND';
		unset( $meta_query['relation'] );

		$ids = array();
		foreach ( $GLOBALS[ self::SUBSCRIPTION_IDS ] ?? array() as $subscription_id ) {
			$subscription = wc_get_order( $subscription_id );
			if ( ! $subscription instanceof SubscriptionDouble ) {
				continue;
			}

			$matches = array_map(
				static fn( array $clause ) => 'EXISTS' === $clause['compare'] ? $subscription->meta_exists( $clause['key'] ) : (string) $subscription->get_meta( $clause['key'], true ) === (string) $clause['value'],
				$meta_query
			);
			if ( 'OR' === $relation ? in_array( true, $matches, true ) : ! in_array( false, $matches, true ) ) {
				$ids[] = absint( $subscription_id );
			}
		}
		sort( $ids );

		$limit = (int) ( $args['limit'] ?? -1 );

		return $limit > 0 ? array_slice( $ids, ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $limit, $limit ) : $ids;
	}

	/**
	 * Make paying a renewal order activate its subscriptions that are not active, as WooCommerce Subscriptions does.
	 *
	 * The test's hook snapshot removes the callback when the test ends.
	 */
	public static function activate_subscriptions_on_renewal_payment(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'maybe_activate_renewal_subscriptions' ), 10, 3 );
	}

	/**
	 * Activate the subscriptions of a renewal order that moved from unpaid to paid, noting it on each.
	 *
	 * @param mixed $order_id   Order ID.
	 * @param mixed $old_status Previous status.
	 * @param mixed $new_status New status.
	 */
	public static function maybe_activate_renewal_subscriptions( $order_id, $old_status, $new_status ): void {
		if ( ! in_array( $old_status, array( 'pending', 'on-hold', 'failed' ), true ) || ! in_array( $new_status, wc_get_is_paid_statuses(), true ) ) {
			return;
		}

		foreach ( $GLOBALS[ self::RENEWAL_SUBSCRIPTIONS ][ absint( $order_id ) ] ?? array() as $subscription_id ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof SubscriptionDouble && ! $subscription->has_status( 'active' ) ) {
				$subscription->add_order_note( 'Payment status marked complete.' );
				$subscription->update_status( 'active' );
			}
		}
	}

	/**
	 * Find registered subscriptions with the `subscription_status` asked for whose meta matches every clause of `meta_query`, `=` or `EXISTS`, as `wcs_get_subscriptions()` does.
	 *
	 * @param array<string,mixed> $args Query arguments: `subscription_status`, `meta_query` and `subscriptions_per_page`.
	 * @return array<int,SubscriptionDouble> Subscriptions by ID.
	 */
	public static function get_subscriptions( array $args ): array {
		$status        = $args['subscription_status'] ?? 'any';
		$subscriptions = array();
		foreach ( $GLOBALS[ self::SUBSCRIPTION_IDS ] ?? array() as $subscription_id ) {
			$subscription = wc_get_order( $subscription_id );
			if ( ! $subscription instanceof SubscriptionDouble || ( 'any' !== $status && ! $subscription->has_status( $status ) ) ) {
				continue;
			}

			foreach ( $args['meta_query'] ?? array() as $clause ) {
				$matches = 'EXISTS' === ( $clause['compare'] ?? '=' )
					? $subscription->meta_exists( $clause['key'] )
					: (string) $subscription->get_meta( $clause['key'], true ) === (string) $clause['value'];
				if ( ! $matches ) {
					continue 2;
				}
			}

			$subscriptions[ $subscription->get_id() ] = $subscription;
		}

		$per_page = (int) ( $args['subscriptions_per_page'] ?? -1 );

		return $per_page > 0 ? array_slice( $subscriptions, 0, $per_page, true ) : $subscriptions;
	}

	/**
	 * Create a pending renewal order with the subscription's customer, billing address, currency, line items and total, as `wcs_create_renewal_order()` does.
	 *
	 * @param \WC_Order $subscription Subscription.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create_renewal_order( \WC_Order $subscription ) {
		if ( ! empty( $GLOBALS[ self::RENEWAL_ORDER_ERROR ] ) ) {
			return new \WP_Error( 'renewal_order_error', 'Renewal order could not be created.' );
		}

		$order = new \WC_Order();
		$order->set_customer_id( $subscription->get_customer_id() );
		$order->set_currency( $subscription->get_currency() );
		$order->set_address( $subscription->get_address( 'billing' ), 'billing' );
		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$copy = new \WC_Order_Item_Product();
				$copy->set_product_id( $item->get_product_id() );
				$copy->set_quantity( $item->get_quantity() );
				$copy->set_subtotal( $item->get_subtotal() );
				$copy->set_total( $item->get_total() );
				$order->add_item( $copy );
			}
		}
		$order->set_total( $subscription->get_total() );
		$order->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		$order->save();

		$GLOBALS[ self::RENEWAL_SUBSCRIPTIONS ][ $order->get_id() ][]          = $subscription->get_id();
		$GLOBALS[ self::ORDER_SUBSCRIPTIONS ][ $order->get_id() ]['renewal'][] = $subscription->get_id();

		return $order;
	}

	/**
	 * Define WooCommerce Subscriptions' renewal-order listener, which fails a subscription when its last renewal fails.
	 *
	 * WooCommerce Subscriptions 7.8.2 `WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment()`
	 * (includes/core/class-wc-subscriptions-renewal-order.php:86-128): a renewal moving to failed calls the subscription's
	 * `payment_failed()`. The double treats every registered renewal as the last one. The test hooks it.
	 */
	public static function load_renewal_order_listener(): void {
		if ( class_exists( 'WC_Subscriptions_Renewal_Order' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; the test needs its renewal-order listener.
		eval( 'class WC_Subscriptions_Renewal_Order { public static function maybe_record_subscription_payment( $order_id, $old_status, $new_status ) { if ( "failed" !== $new_status ) { return; } foreach ( $GLOBALS["' . self::RENEWAL_SUBSCRIPTIONS . '"][ absint( $order_id ) ] ?? array() as $subscription_id ) { $subscription = wcs_get_subscription( $subscription_id ); if ( $subscription && method_exists( $subscription, "payment_failed" ) ) { $subscription->payment_failed(); } } } }' );
	}

	/**
	 * Load the orders registered as subscriptions as `SubscriptionDouble`.
	 *
	 * @param mixed $class_name Order class name.
	 * @param mixed $order_type Order type.
	 * @param mixed $order_id   Order ID.
	 * @return mixed
	 */
	public static function get_subscription_order_class( $class_name, $order_type, $order_id ) {
		unset( $order_type );

		if ( ! in_array( absint( $order_id ), $GLOBALS[ self::SUBSCRIPTION_IDS ] ?? array(), true ) ) {
			return $class_name;
		}

		return empty( $GLOBALS[ self::BEFORE_RELATED_ORDER_FAILURE ] ) ? RelatedOrderFailureSubscriptionDouble::class : SubscriptionDouble::class;
	}
}
