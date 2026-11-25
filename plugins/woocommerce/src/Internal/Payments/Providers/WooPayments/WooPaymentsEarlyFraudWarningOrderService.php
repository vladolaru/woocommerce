<?php
/**
 * WooPaymentsEarlyFraudWarningOrderService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EarlyFraudWarningEventHandler;
use WC_Order;

/**
 * Lists the orders whose early fraud warning still needs action, and drops the cached lists when such an order is
 * refunded, as client 11.3.0 does (includes/class-wc-payments-order-service.php:1714-1777).
 *
 * WooPaymentsAdminRestRouteRegistrar hears the refunds in every request and resolves this service only when one happens.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsEarlyFraudWarningOrderService {

	/**
	 * The most orders with an actionable warning the list inspects, newest warning first.
	 */
	private const ORDER_LIMIT = 100;

	/**
	 * Cache holding the lists.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsDatabaseCache $database_cache Cache holding the lists.
	 */
	final public function init( WooPaymentsDatabaseCache $database_cache ): void {
		$this->database_cache = $database_cache;
	}

	/**
	 * Get the orders of one mode whose stored warning is actionable and that are not fully refunded.
	 *
	 * The order query reads the index the warning handler keeps, so only the newest ORDER_LIMIT actionable warnings are
	 * inspected, and a warning stored before that index existed is not listed.
	 *
	 * @since 11.4.0
	 *
	 * @param bool $is_test_mode Whether to list test-mode orders; the caller's one read of the account's mode.
	 * @return array<int,array{order_id:int,order_number:string,charge_id:string,created:int}> Newest warning first.
	 */
	public function get_actionable_orders( bool $is_test_mode ): array {
		$orders = wc_get_orders(
			array(
				'limit'    => self::ORDER_LIMIT,
				'orderby'  => 'meta_value_num',
				'order'    => 'DESC',
				'meta_key' => WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_ACTIONABLE_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$rows = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			// A warning stored in the other mode never clears, because its updates are dropped as a mode mismatch; an order
			// without a mode reads as live.
			$is_test_order = WooPaymentsOrderMode::TEST === $order->get_meta( WooPaymentsPersistenceVocabulary::MODE_META_KEY, true );
			if ( $is_test_order !== $is_test_mode ) {
				continue;
			}

			$warning = EarlyFraudWarningEventHandler::get_existing_warning( $order );
			if ( null === $warning || ! $warning['efw_actionable'] ) {
				continue;
			}

			// A refund is the action the warning asks for, so a fully refunded order counts as resolved.
			$total = (float) $order->get_total();
			if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) {
				continue;
			}

			$rows[] = array(
				'order_id'     => $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'charge_id'    => (string) $order->get_meta( WooPaymentsPersistenceVocabulary::CHARGE_ID_META_KEY, true ),
				'created'      => (int) $order->get_meta( WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_ACTIONABLE_META_KEY, true ),
			);
		}

		return $rows;
	}

	/**
	 * Drop the cached lists when an order holding a warning is refunded, so a cached list does not keep listing it.
	 *
	 * @internal
	 *
	 * @param mixed $order_id The refunded order's ID.
	 */
	public function handle_order_refunded( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof WC_Order || ! $order->get_meta( WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_META_KEY, true ) ) {
			return;
		}

		$this->database_cache->delete_early_fraud_warning_caches();
	}
}
