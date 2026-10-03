<?php
/**
 * The admin notice module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Notes\MexicoInstallmentsNote;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\Repository;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint\MuteMessageEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer\RendererInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Entity\PersistentMessage;

/**
 * Class AdminNotices
 */
class AdminNotices implements ServiceModule, ExecutableModule {
	use ModuleClassNameIdTrait;

	/**
	 * {@inheritDoc}
	 */
	public function services(): array {
		return require __DIR__ . '/services.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( ContainerInterface $c ): bool {
		$renderer = $c->get( 'admin-notices.renderer' );
		assert( $renderer instanceof RendererInterface );

		add_action(
			'admin_notices',
			function () use ( $renderer ) {
				$renderer->render();
			}
		);

		add_action(
			Repository::NOTICES_FILTER,
			/**
			 * Adds persisted notices to the notices array.
			 *
			 * @param array $notices The notices.
			 * @return array
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $notices ) use ( $c ) {
				if ( ! is_array( $notices ) ) {
					return $notices;
				}

				$admin_notices = $c->get( 'admin-notices.repository' );
				assert( $admin_notices instanceof Repository );

				$persisted_notices = $admin_notices->get_persisted_and_clear();

				if ( $persisted_notices ) {
					$notices = array_merge( $notices, $persisted_notices );
				}

				return $notices;
			}
		);

		/**
		 * Since admin notices are rendered after the initial `admin_enqueue_scripts`
		 * action fires, we use the `admin_footer` hook to enqueue the optional assets
		 * for admin-notices in the page footer.
		 */
		add_action(
			'admin_footer',
			static function () use ( $renderer ) {
				$renderer->enqueue_admin();
			}
		);

		add_action(
			'wp_ajax_' . MuteMessageEndpoint::ENDPOINT,
			static function () use ( $c ) {
				$endpoint = $c->get( 'admin-notices.mute-message-endpoint' );
				assert( $endpoint instanceof MuteMessageEndpoint );

				$endpoint->handle_request();
			}
		);

		add_action(
			'woocommerce_paypal_payments_uninstall',
			static function () {
				PersistentMessage::clear_all();
			}
		);

		add_action(
			'woocommerce_init',
			function () {
				if ( is_admin() && is_callable( array( WC(), 'is_wc_admin_active' ) ) && WC()->is_wc_admin_active() && class_exists( 'Automattic\WooCommerce\Admin\Notes\Notes' ) ) {
					MexicoInstallmentsNote::init();
				}
			}
		);

		return true;
	}
}
