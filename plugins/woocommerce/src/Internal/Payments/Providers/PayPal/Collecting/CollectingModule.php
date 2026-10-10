<?php
/**
 * CollectingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\ReconcileCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\EmailTriggers;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedGates;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderListeners;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\PayeeFilters;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Rest\CollectingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\SettingsAppData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AssertedRefundSigner;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\BearerRetryFilter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WP_CLI;

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
	 * The priority of the refund signer: after the retry filter at 20, which re-signs with the order's app.
	 */
	private const REFUND_SIGNER_PRIORITY = 30;

	/**
	 * The priority that leaves the order context after the order processor: after the wallet's own callbacks at 10, which
	 * may still call PayPal for the order.
	 */
	private const LEAVE_CONTEXT_PRIORITY = 1000;

	/**
	 * The priority that enters the edited order's app on the order screen: before the wallet's void button fetches the
	 * PayPal order at 10.
	 */
	private const ORDER_SCREEN_CONTEXT_PRIORITY = 9;

	/**
	 * The priority that checks the reconcile schedule when a capture is held: after the held capture is recorded at 10.
	 */
	private const SCHEDULE_PRIORITY = 20;

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
	 * while the platform serves the store, the one that re-signs a retried request with the call's app, the one that
	 * signs a capture refund for the connected seller, the listeners
	 * that pin each order to its app and enter it for the order's calls, the ones that record a held capture and claim the first
	 * order, the reconcile, the admin emails, the filters that name the payee and the settings app's collecting data.
	 *
	 * @param ContainerInterface $container The service container.
	 */
	public function run( ContainerInterface $container ): bool {
		$connection_state = $container->get( 'collecting.connection-state' );

		$gates = new PlatformServedGates( $connection_state );
		add_filter( 'woocommerce_paypal_payments_order_intent', array( $gates, 'handle_woocommerce_paypal_payments_order_intent' ), self::GATE_PRIORITY );
		add_filter( 'woocommerce_paypal_payments_rest_common_merchant_features', array( $gates, 'handle_woocommerce_paypal_payments_rest_common_merchant_features' ), self::GATE_PRIORITY );
		add_filter( 'woocommerce_paypal_payments_features_list', array( $gates, 'handle_woocommerce_paypal_payments_features_list' ), self::GATE_PRIORITY );
		add_filter( 'woocommerce_paypal_payments_todos_list', array( $gates, 'handle_woocommerce_paypal_payments_todos_list' ), self::GATE_PRIORITY );

		// The Refund button's lock is the shell's (OwnerIndependent), so it holds whoever owns the wallet; the server-side
		// refusal is the LockingRefundProcessor extension.

		if ( $connection_state->is_served_by_platform() ) {
			$transport = $container->get( 'collecting.transport' );
			$context   = $container->get( 'collecting.order-app-context' );
			$state     = $container->get( 'collecting.state' );
			$logger    = $container->get( 'woocommerce.logger.woocommerce' );

			$retry = new BearerRetryFilter( $connection_state, $transport, $context, $state, $logger );
			add_filter( 'ppcp_retry_request_args', array( $retry, 'handle_ppcp_retry_request_args' ), self::RETRY_PRIORITY, 2 );

			// Once platform connected, a capture refund goes through the platform app for the seller; it is the one call
			// that leaves the order's pin. Last on both filters, so the retry is not re-signed with the order's app after it.
			$refund_signer = new AssertedRefundSigner( $connection_state, $transport, $logger );
			add_filter( 'ppcp_request_args', array( $refund_signer, 'handle_ppcp_request_args' ), self::REFUND_SIGNER_PRIORITY, 2 );
			add_filter( 'ppcp_retry_request_args', array( $refund_signer, 'handle_ppcp_retry_request_args' ), self::REFUND_SIGNER_PRIORITY, 2 );

			$listeners = new OrderListeners( $connection_state, $context, $transport, $state, $logger );
			add_action( 'woocommerce_paypal_wallet_order_context', array( $listeners, 'handle_woocommerce_paypal_wallet_order_context' ) );
			add_action( 'woocommerce_paypal_wallet_paypal_order_created', array( $listeners, 'handle_woocommerce_paypal_wallet_paypal_order_created' ) );
			add_action( 'woocommerce_paypal_payments_after_order_processor', array( $listeners, 'handle_woocommerce_paypal_payments_after_order_processor' ), self::LEAVE_CONTEXT_PRIORITY, 0 );

			// The order screen's void button reads the PayPal order, which only the order's app can do.
			add_action( 'admin_enqueue_scripts', array( $listeners, 'handle_admin_enqueue_scripts' ), self::ORDER_SCREEN_CONTEXT_PRIORITY, 0 );

			// The wallet's capture-completed handler reads the PayPal order, which only the order's app can do.
			add_action( 'woocommerce_paypal_payments_payment_capture_completed_webhook_handler', array( $listeners, 'handle_woocommerce_paypal_wallet_order_context' ) );

			$held = new HeldCapture( $state, $logger );
			add_action( 'woocommerce_paypal_wallet_capture_pending', array( $held, 'handle_woocommerce_paypal_wallet_capture_pending' ), 10, 2 );
			add_action( 'woocommerce_payment_complete', array( $held, 'handle_woocommerce_payment_complete' ) );

			$this->register_reconcile( $container );

			// The surfaces that read only options and orders are registered by the shell, whoever owns the wallet; the emails need the module's actions, so they belong here.
			$emails = new EmailTriggers( $logger );
			add_action( 'woocommerce_paypal_wallet_first_order', array( $emails, 'handle_woocommerce_paypal_wallet_first_order' ), 10, 2 );
			add_action( 'woocommerce_paypal_wallet_held_payment_returned', array( $emails, 'handle_woocommerce_paypal_wallet_held_payment_returned' ), 10, 2 );

			$payee = new PayeeFilters( $connection_state, $state );
			add_filter( 'ppcp_create_order_request_body_data', array( $payee, 'handle_ppcp_create_order_request_body_data' ), self::GATE_PRIORITY );
			add_filter( 'ppcp_patch_order_request_body_data', array( $payee, 'handle_ppcp_patch_order_request_body_data' ), self::GATE_PRIORITY );
			add_filter( 'woocommerce_paypal_payments_localized_script_data', array( $payee, 'handle_woocommerce_paypal_payments_localized_script_data' ), self::GATE_PRIORITY );

			// Built only on the settings page, where the wallet fires this action.
			add_action(
				'woocommerce_paypal_payments_settings_scripts_enqueued',
				static function () use ( $container ): void {
					self::settings_app_data( $container )->handle_woocommerce_paypal_payments_settings_scripts_enqueued();
				}
			);
		}

		return true;
	}

	/**
	 * The settings app's collecting data, over the container's collecting services.
	 *
	 * @param ContainerInterface $container The service container.
	 * @return SettingsAppData
	 */
	private static function settings_app_data( ContainerInterface $container ): SettingsAppData {
		$panel = new CollectingRestEndpoint(
			$container->get( 'collecting.state' ),
			$container->get( 'collecting.connection-state' ),
			$container->get( 'collecting.held-orders' ),
			$container->get( 'collecting.options' ),
			new Dismissals(),
			static function () use ( $container ): PlatformTransport {
				return $container->get( 'collecting.transport' );
			},
			static function () use ( $container ): Reconciler {
				return $container->get( 'collecting.reconciler' );
			}
		);

		return new SettingsAppData( $container->get( 'collecting.connection-state' ), $panel );
	}

	/**
	 * Run the reconcile from Action Scheduler and WP-CLI, and keep it scheduled while orders are held: checked on admin
	 * screens (at most hourly) and whenever a capture is held. The reconciler is built only when one of them runs.
	 *
	 * @param ContainerInterface $container The service container.
	 */
	private function register_reconcile( ContainerInterface $container ): void {
		$reconciler = static function () use ( $container ): Reconciler {
			return $container->get( 'collecting.reconciler' );
		};

		add_action(
			Reconciler::HOOK,
			static function ( $offset = 0, $continuation = false ) use ( $reconciler ): void {
				$reconciler()->handle_woocommerce_paypal_wallet_reconcile( $offset, $continuation );
			},
			10,
			2
		);
		add_action(
			'admin_init',
			static function () use ( $reconciler ): void {
				$reconciler()->handle_admin_init();
			}
		);
		add_action(
			'woocommerce_paypal_wallet_capture_pending',
			static function () use ( $reconciler ): void {
				$reconciler()->maintain_schedule();
			},
			self::SCHEDULE_PRIORITY,
			0
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
				'wc paypal-wallet reconcile',
				static function ( array $args, array $assoc_args ) use ( $reconciler ): void {
					( new ReconcileCommand( $reconciler() ) )->reconcile( $args, $assoc_args );
				},
				array( 'shortdesc' => 'Read each held PayPal wallet order\'s capture from PayPal and settle it, then complete onboarding when PayPal reports it complete.' )
			);
		}
	}
}
