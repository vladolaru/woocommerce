<?php
/**
 * WooPaymentsAdminRestRouteRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\MerchantRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\AccountSessionRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers the WooPayments REST controllers when WordPress starts its REST server, in every request of a connected or
 * active store, so a REST request and an internal REST dispatch (an admin preload, WP-CLI, cron) find the routes.
 *
 * A request that starts no REST server resolves no controller. The WooPay session routes register only in the active
 * setup tier.
 *
 * It also hands every refund to the early fraud warning service, which a refund on any request type can make stale, as
 * client 11.3.0 hooks it on every request (includes/class-wc-payments-order-service.php:342). The service is resolved
 * only when a refund happens, so a shopper's page pays for one hook registration.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsAdminRestRouteRegistrar implements RegisterHooksInterface {

	/**
	 * WooCommerce dependency container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Initialize the registrar.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 *
	 * @param Container $container WooCommerce dependency container.
	 */
	final public function init( Container $container ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->container = $container;
	}

	/**
	 * Register the deferred REST and refund hooks.
	 *
	 * @since 11.2.0
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_rest_controllers' ), 0 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ) );
	}

	/**
	 * Hand a refund to the early fraud warning service, which drops the cached lists when the order holds a warning.
	 *
	 * @internal
	 *
	 * @param mixed $order_id The refunded order's ID.
	 */
	public function handle_order_refunded( $order_id ): void {
		/**
		 * Early fraud warning service.
		 *
		 * @var WooPaymentsEarlyFraudWarningOrderService $service
		 */
		$service = $this->container->get( WooPaymentsEarlyFraudWarningOrderService::class );
		$service->handle_order_refunded( $order_id );
	}

	/**
	 * Resolve and register the route controllers once WordPress starts its REST server.
	 *
	 * Each controller registers its routes later on this hook, at priority 10: through WooCommerce's REST server, or on its own
	 * callback for the webhook and WooPay session controllers.
	 *
	 * @since 11.2.0
	 */
	public function register_rest_controllers(): void {
		/**
		 * Setup tier store.
		 *
		 * @var WooPaymentsSetupTier $setup_tier
		 */
		$setup_tier = $this->container->get( WooPaymentsSetupTier::class );

		foreach ( self::get_controller_roots_for_state( $setup_tier->get_effective_tier() ) as $root ) {
			/**
			 * Deferred route controller.
			 *
			 * @var RegisterHooksInterface $controller
			 */
			$controller = $this->container->get( $root );
			$controller->register();
		}
	}

	/**
	 * Get the REST controllers of a connected or active setup tier.
	 *
	 * @since 11.2.0
	 *
	 * @return array<int,class-string<RegisterHooksInterface>> Route controller classes in registration order.
	 */
	public static function get_connected_controller_roots(): array {
		return array(
			MerchantRestController::class,
			WebhookRestController::class,
			WooPaymentsTerminalPaymentsRestController::class,
			WooPaymentsCardReadersRestController::class,
			WooPaymentsTerminalLocationsRestController::class,
			AccountSessionRestController::class,
			WooPaymentsCustomersRestController::class,
			WooPaymentsDepositsRestController::class,
			WooPaymentsPaymentDetailsRestController::class,
			WooPaymentsAuthorizationsRestController::class,
			WooPaymentsTransactionsRestController::class,
			WooPaymentsDisputesRestController::class,
			WooPaymentsEarlyFraudWarningsRestController::class,
			WooPaymentsDisputeReadinessRestController::class,
			WooPaymentsCapitalRestController::class,
			WooPaymentsDocumentsRestController::class,
			WooPaymentsReportsRestController::class,
			WooPaymentsTosRestController::class,
		);
	}

	/**
	 * Get the route controllers for the setup tier.
	 *
	 * @param string $state Effective setup tier.
	 * @return array<int,class-string<RegisterHooksInterface>> Route controller classes in registration order.
	 */
	private static function get_controller_roots_for_state( string $state ): array {
		if ( ! in_array( $state, array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true ) ) {
			return array();
		}

		$roots = self::get_connected_controller_roots();
		if ( WooPaymentsSetupTier::ACTIVE === $state ) {
			$roots[] = SessionController::class;
		}

		return $roots;
	}
}
