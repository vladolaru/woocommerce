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
 * On admin requests, registers the WooPayments REST controllers when WordPress starts its REST server, so a REST call
 * made inside the admin request, such as a preload, finds the routes.
 *
 * REST requests register the controllers directly; the WooPay session routes register only in the active setup tier.
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
	 * Register the deferred admin REST hook.
	 *
	 * @since 11.2.0
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_rest_controllers' ), 0 );
	}

	/**
	 * Resolve and register the route controllers for an internal REST dispatch.
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
