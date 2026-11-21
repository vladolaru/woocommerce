<?php
/**
 * The save-time copy of a subscription's payment method and customer IDs, registered for a test.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;

/**
 * Registers the order update callback that writes a WooPayments order's `_payment_method_id` and `_stripe_customer_id`
 * when it is saved, as the provider registers it on a store the built-in owns, with WooCommerce Subscriptions reported
 * available and Sift tracking off. A test then sees a subscription's IDs as a store does after each save.
 */
final class SubscriptionPaymentIdsOnSave {

	/**
	 * Register the callback for the rest of the test; the test's hook snapshot removes it when the test ends.
	 *
	 * @param \WC_Unit_Test_Case $test The running test, whose legacy proxy reports WooCommerce Subscriptions available.
	 * @return WooPaymentsFraudOrderTrackingService The registered copy, for a test that removes it itself.
	 */
	public static function register( \WC_Unit_Test_Case $test ): WooPaymentsFraudOrderTrackingService {
		$test->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		$fraud_service = new class() extends WooPaymentsFraudService {
			/**
			 * Report no fraud services, so nothing is scheduled for Sift.
			 *
			 * @return array<string,mixed>
			 */
			public function get_fraud_services_config(): array {
				return array();
			}
		};
		$tracking      = new WooPaymentsFraudOrderTrackingService();
		$tracking->init(
			wc_get_container()->get( WooPaymentsActionSchedulerService::class ),
			wc_get_container()->get( Client::class ),
			wc_get_container()->get( WooPaymentsAccountService::class ),
			$fraud_service
		);
		add_action( 'woocommerce_update_order', array( $tracking, 'handle_woocommerce_update_order' ), 10, 2 );

		return $tracking;
	}
}
