<?php
/**
 * The order endpoints module.
 *
 * Home of the WC-AJAX endpoints shared by the v5 and v6 SDK frontends.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ApproveOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ChangeCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\CreateOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\FrontendLogEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\UpdateShippingEndpoint;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Module that registers the cart, order and shipping endpoints.
 */
class OrderEndpointsModule implements ServiceModule, ExecutableModule {
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
			'wc_ajax_' . ChangeCartEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'order-endpoints.endpoint.change-cart' );
				assert( $endpoint instanceof ChangeCartEndpoint );

				$endpoint->handle_request();
			}
		);

		add_action(
			'wc_ajax_' . ApproveOrderEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'order-endpoints.endpoint.approve-order' );
				assert( $endpoint instanceof ApproveOrderEndpoint );

				$endpoint->handle_request();
			}
		);

		add_action(
			'wc_ajax_' . CreateOrderEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'order-endpoints.endpoint.create-order' );
				assert( $endpoint instanceof CreateOrderEndpoint );

				$endpoint->handle_request();
			}
		);

		add_action(
			'wc_ajax_' . FrontendLogEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'order-endpoints.endpoint.frontend-log' );
				assert( $endpoint instanceof FrontendLogEndpoint );

				$endpoint->handle_request();
			}
		);

		add_action(
			'wc_ajax_' . UpdateShippingEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'order-endpoints.endpoint.update-shipping' );
				assert( $endpoint instanceof UpdateShippingEndpoint );

				$endpoint->handle_request();
			}
		);

		return true;
	}
}
