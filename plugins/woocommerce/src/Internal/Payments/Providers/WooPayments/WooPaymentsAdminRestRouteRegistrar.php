<?php
/**
 * WooPaymentsAdminRestRouteRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Defers native WooPayments REST controller resolution for admin requests until WordPress initializes its REST server.
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
		if ( false === has_action( 'rest_api_init', array( $this, 'register_rest_controllers' ) ) ) {
			add_action( 'rest_api_init', array( $this, 'register_rest_controllers' ), 0 );
		}
	}

	/**
	 * Resolve and register the route controllers for an internal REST dispatch.
	 *
	 * @since 11.2.0
	 */
	public function register_rest_controllers(): void {
		/**
		 * Effective native state store.
		 *
		 * @var NativePaymentsState $state_store
		 */
		$state_store = $this->container->get( NativePaymentsState::class );

		foreach ( self::get_controller_roots_for_state( $state_store->get_state() ) as $root ) {
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
	 * Get the native route controllers available for connected and active sites.
	 *
	 * @since 11.2.0
	 *
	 * @return array<int,class-string<RegisterHooksInterface>> Route controller classes in registration order.
	 */
	public static function get_connected_controller_roots(): array {
		return array(
			WooPaymentsMerchantRestController::class,
			WooPaymentsWebhookRestController::class,
			WooPaymentsMobileRestController::class,
			WooPaymentsAccountSessionRestController::class,
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
	 * Get the route controllers for the current effective state.
	 *
	 * @param string $state Effective native state.
	 * @return array<int,class-string<RegisterHooksInterface>> Route controller classes in registration order.
	 */
	private static function get_controller_roots_for_state( string $state ): array {
		if ( ! in_array( $state, array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true ) ) {
			return array();
		}

		$roots = self::get_connected_controller_roots();
		if ( NativePaymentsState::ACTIVE === $state ) {
			$roots[] = WooPaymentsWooPaySessionController::class;
		}

		return $roots;
	}
}
