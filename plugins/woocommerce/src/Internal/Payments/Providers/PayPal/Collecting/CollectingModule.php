<?php
/**
 * CollectingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedGates;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderListeners;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\PayeeFilters;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\BearerRetryFilter;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The collecting module: the services and extensions of the collecting state, added to the wallet's module list.
 *
 * The shell appends it after the wallet's own modules, so its extensions wrap theirs. It is built only for a store the
 * platform serves, so a dormant store never boots it and the shell owns anything that has to exist before a boot.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectingModule implements ServiceModule, ExtendingModule, ExecutableModule {
	use ModuleClassNameIdTrait;

	/**
	 * The priority of the gate filters: late, so they have the last word over the wallet's own callbacks at 10.
	 */
	private const GATE_PRIORITY = 100;

	/**
	 * The priority of the retry filter: after the wallet's own at 10, which drops only its first-party token.
	 */
	private const RETRY_PRIORITY = 20;

	/**
	 * The priority that leaves the order context after the order processor: after the wallet's own callbacks at 10, which
	 * may still call PayPal for the order.
	 */
	private const LEAVE_CONTEXT_PRIORITY = 1000;

	/**
	 * {@inheritDoc}
	 */
	public function services(): array {
		return require __DIR__ . '/services.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extensions(): array {
		return require __DIR__ . '/extensions.php';
	}

	/**
	 * Add the filters that keep authorize-only and saved PayPal and Venmo off, after the wallet's own callbacks, and,
	 * while the platform serves the store, the one that re-signs a retried request with the call's app, the listeners
	 * that pin each order to its app and enter it for the order's calls, the ones that record a held capture and claim the first
	 * order, and the filters that name the payee.
	 *
	 * @param ContainerInterface $container The service container.
	 */
	public function run( ContainerInterface $container ): bool {
		$connection_state = $container->get( 'collecting.connection-state' );

		$gates = new PlatformServedGates( $connection_state );
		add_filter( 'woocommerce_paypal_payments_order_intent', array( $gates, 'handle_woocommerce_paypal_payments_order_intent' ), self::GATE_PRIORITY );
		add_filter( 'woocommerce_paypal_payments_rest_common_merchant_features', array( $gates, 'handle_woocommerce_paypal_payments_rest_common_merchant_features' ), self::GATE_PRIORITY );

		if ( $connection_state->is_served_by_platform() ) {
			$transport = $container->get( 'collecting.transport' );
			$context   = $container->get( 'collecting.order-app-context' );
			$state     = $container->get( 'collecting.state' );
			$logger    = $container->get( 'woocommerce.logger.woocommerce' );

			$retry = new BearerRetryFilter( $connection_state, $transport, $context, $state, $logger );
			add_filter( 'ppcp_retry_request_args', array( $retry, 'handle_ppcp_retry_request_args' ), self::RETRY_PRIORITY, 2 );

			$listeners = new OrderListeners( $connection_state, $context, $transport, $state, $logger );
			add_action( 'woocommerce_paypal_wallet_order_context', array( $listeners, 'handle_woocommerce_paypal_wallet_order_context' ) );
			add_action( 'woocommerce_paypal_wallet_paypal_order_created', array( $listeners, 'handle_woocommerce_paypal_wallet_paypal_order_created' ) );
			add_action( 'woocommerce_paypal_payments_after_order_processor', array( $listeners, 'handle_woocommerce_paypal_payments_after_order_processor' ), self::LEAVE_CONTEXT_PRIORITY, 0 );

			$held = new HeldCapture( $state, $logger );
			add_action( 'woocommerce_paypal_wallet_capture_pending', array( $held, 'handle_woocommerce_paypal_wallet_capture_pending' ), 10, 2 );
			add_action( 'woocommerce_payment_complete', array( $held, 'handle_woocommerce_payment_complete' ) );

			$payee = new PayeeFilters( $connection_state, $state );
			add_filter( 'ppcp_create_order_request_body_data', array( $payee, 'handle_ppcp_create_order_request_body_data' ), self::GATE_PRIORITY );
			add_filter( 'ppcp_patch_order_request_body_data', array( $payee, 'handle_ppcp_patch_order_request_body_data' ), self::GATE_PRIORITY );
			add_filter( 'woocommerce_paypal_payments_localized_script_data', array( $payee, 'handle_woocommerce_paypal_payments_localized_script_data' ), self::GATE_PRIORITY );
		}

		return true;
	}
}
