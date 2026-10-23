<?php
/**
 * The session module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelController;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Class SessionModule
 */
class SessionModule implements ServiceModule, ExecutableModule {
	use ModuleClassNameIdTrait;

	/**
	 * {@inheritDoc}
	 */
	public function services(): array {
		return require __DIR__ . '/services.php';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ContainerInterface $c The service container.
	 */
	public function run( ContainerInterface $c ): bool {
		add_action(
			'woocommerce_init',
			function () use ( $c ) {
				$controller = $c->get( 'session.cancellation.controller' );
				/**
				 * The Cancel controller.
				 *
				 * @var CancelController $controller
				 */
				$controller->run();
			}
		);

		add_action(
			'ppcp_session_get_order',
			static function ( $order, $session_handler ) use ( $c ): void {
				if ( ! $session_handler instanceof SessionHandler ) {
					return;
				}

				$reloader = $c->get( 'session.order-reloader' );
				assert( $reloader instanceof SessionOrderReloader );

				$reloader->maybe_reload( $order instanceof Order ? $order : null, $session_handler );
			},
			10,
			2
		);

		return true;
	}
}
