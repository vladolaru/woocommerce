<?php
/**
 * OwnerIndependent class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\FirstOrderEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\HeldPaymentReturnedEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Rest\CollectingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WC_Order;

/**
 * Registers the surfaces that concern held orders, which must work whoever owns the wallet and whether or not the
 * wallet booted: the Home task, the Inbox note, the two admin emails and the notices on the Payments row, the order
 * screen and the Plugins page. It also registers, on every store, the collecting panel's REST routes and the core
 * profiler's PayPal Wallet card, which a store with no history needs to start collecting.
 *
 * The shell calls register() before any ownership check. It needs no container: it reads the autoloaded options
 * directly and builds the held-orders query only when the task is read. On a store that has no collecting or platform
 * option and no recorded first order, register() attaches only the routes and the card, on admin and REST requests,
 * and runs no query.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class OwnerIndependent {

	/**
	 * The priority that runs after the collecting module records a held capture (10) and claims the first order.
	 */
	private const AFTER_CAPTURE_RECORDED = 30;

	/**
	 * The shared settings option the wallet's first-party connection is saved in.
	 */
	private const FIRST_PARTY_OPTION = 'woocommerce-ppcp-data-common';

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The logger, or null for WooCommerce's own.
	 *
	 * @var LoggerInterface|null
	 */
	private ?LoggerInterface $logger;

	/**
	 * The Payments row notice, built once so that a repeated register() attaches nothing new.
	 *
	 * @var ProviderRow|null
	 */
	private ?ProviderRow $provider_row = null;

	/**
	 * The order screen notice, built once.
	 *
	 * @var OrderScreen|null
	 */
	private ?OrderScreen $order_screen = null;

	/**
	 * The Plugins page notice, built once.
	 *
	 * @var PluginsPageNotice|null
	 */
	private ?PluginsPageNotice $plugins_page_notice = null;

	/**
	 * The core profiler card, built once.
	 *
	 * @var ProfilerCard|null
	 */
	private ?ProfilerCard $profiler_card = null;

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options The option reader; the stored options by default.
	 * @param LoggerInterface|null $logger  Receives a failure of a surface; WooCommerce's logger when null.
	 */
	public function __construct( ?Options $options = null, ?LoggerInterface $logger = null ) {
		$this->options = $options ?? new Options();
		$this->logger  = $logger;
	}

	/**
	 * Hook the panel's routes and the profiler card on admin and REST requests, then the surfaces, unless the store never
	 * had a wallet order or a collecting or platform state.
	 *
	 * On such a store only the routes and the card are attached, on admin and REST requests, and no query runs: the three
	 * options are autoloaded and answered from the autoloaded set. The callbacks check again, because the state can change
	 * during the request.
	 *
	 * The task is added on `init`: core builds the task lists on `init` at priority 4, so they exist by the default priority.
	 *
	 * @since 11.3.0
	 */
	public function register(): void {
		// Every store, on admin and REST requests only: a store with no history starts collecting through the profiler card
		// or the panel's routes. Hooking reads nothing, and both callbacks return at once, with no query, on any request
		// that is not theirs. A front-end request attaches nothing.
		if ( $this->is_admin_or_rest_request() ) {
			add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
			$this->profiler_card()->register();
		}

		if ( ! $this->has_wallet_history() ) {
			return;
		}

		add_action( 'init', array( $this, 'register_task' ) );
		add_action( 'admin_init', array( $this, 'sync_note' ) );
		add_action( 'woocommerce_paypal_wallet_first_order', array( $this, 'handle_woocommerce_paypal_wallet_first_order' ), 10, 2 );
		add_action( 'woocommerce_paypal_wallet_capture_pending', array( $this, 'handle_woocommerce_paypal_wallet_capture_pending' ), self::AFTER_CAPTURE_RECORDED, 2 );
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );

		// The notices on the Payments row, the order screen and the Plugins page read only the options and the orders.
		// The same three objects every time: a repeated register() must hit WordPress's duplicate check.
		$this->provider_row        = $this->provider_row ?? new ProviderRow( $this->options );
		$this->order_screen        = $this->order_screen ?? new OrderScreen( $this->options );
		$this->plugins_page_notice = $this->plugins_page_notice ?? new PluginsPageNotice( $this->options );
		$this->provider_row->register();
		add_action( 'woocommerce_admin_order_data_after_payment_info', array( $this->order_screen, 'handle_woocommerce_admin_order_data_after_payment_info' ) );
		$this->plugins_page_notice->register();

		// Action the note from the connection change itself: the platform option is written when setup completes, and the
		// shared settings option when a merchant connects first-party. admin_init stays as a marker-gated fallback.
		foreach ( array( Options::PLATFORM, self::FIRST_PARTY_OPTION ) as $option ) {
			add_action( 'add_option_' . $option, array( $this, 'handle_connection_change' ), 10, 0 );
			add_action( 'update_option_' . $option, array( $this, 'handle_connection_change' ), 10, 0 );
		}
	}

	/**
	 * Register the collecting panel's REST routes. Hooked to `rest_api_init`; builds the endpoint and reads nothing.
	 *
	 * @since 11.3.0
	 */
	public function register_rest_routes(): void {
		( new CollectingRestEndpoint(
			new CollectingState( $this->options, new HeldOrders() ),
			new ConnectionState( $this->options ),
			new HeldOrders(),
			$this->options,
			new Dismissals(),
			array( RuntimeServices::class, 'transport' ),
			array( RuntimeServices::class, 'reconciler' )
		) )->register_routes();
	}

	/**
	 * The core profiler card, built once so that a repeated register() attaches nothing new.
	 *
	 * @since 11.3.0
	 *
	 * @return ProfilerCard
	 */
	public function profiler_card(): ProfilerCard {
		$this->profiler_card = $this->profiler_card ?? new ProfilerCard( $this->options );

		return $this->profiler_card;
	}

	/**
	 * Put the setup task first on the "Things to do next" list.
	 *
	 * Core never sorts the list in PHP and the client shows tasks in list order, so the task is moved to the front
	 * rather than left to the level it reports. Does nothing where core has not built the task lists, which is any request
	 * that loads no admin features, and nothing on a store with no wallet history.
	 *
	 * @since 11.3.0
	 */
	public function register_task(): void {
		$list = TaskLists::get_list( 'extended' );
		if ( null === $list || ! $this->has_wallet_history() || null !== TaskLists::get_task( SetUpPayPalWalletTask::ID, 'extended' ) ) {
			return;
		}

		$task = new SetUpPayPalWalletTask( $list );
		TaskLists::add_task( 'extended', $task );
		$list->tasks = array_values(
			array_merge(
				array( $task ),
				array_filter(
					$list->tasks,
					static function ( $other ) use ( $task ): bool {
						return $other !== $task;
					}
				)
			)
		);
	}

	/**
	 * Keep the Inbox note in step with the store, as a fallback to the hooks that act on the change itself.
	 *
	 * Hooked to `admin_init`. It skips AJAX requests (Heartbeat among them) and returns at once, with no query, once the
	 * note is actioned. Before that it adds the note once for a store that has a wallet order and is not connected, and
	 * actions it when the store is connected.
	 *
	 * @since 11.3.0
	 */
	public function sync_note(): void {
		if ( wp_doing_ajax() || Options::NOTE_ACTIONED === $this->options->note_state() || $this->options->first_order_id() <= 0 ) {
			return;
		}

		$this->guarded(
			static function (): void {
				InboxNote::possibly_add();
				InboxNote::possibly_action();
			}
		);
	}

	/**
	 * Add the Inbox note when the first wallet order arrives.
	 *
	 * Hooked to `woocommerce_paypal_wallet_first_order`, which fires once per store, so a second held order adds nothing.
	 * A failure is logged and never reaches the code that fired the action, so the email listener after it still runs.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order The first order.
	 * @param mixed $state The collecting state.
	 */
	public function handle_woocommerce_paypal_wallet_first_order( $order = null, $state = null ): void {
		unset( $order, $state );
		$this->guarded(
			static function (): void {
				InboxNote::possibly_add();
			}
		);
	}

	/**
	 * Action the Inbox note when a connection option is written.
	 *
	 * Hooked to the add and update hooks of the platform option and of the shared settings option. Cheap when the note is
	 * not waiting: the note-state option is read from the autoloaded set.
	 *
	 * @since 11.3.0
	 */
	public function handle_connection_change(): void {
		$this->guarded(
			static function (): void {
				InboxNote::possibly_action();
			}
		);
	}

	/**
	 * Show the Home task again when PayPal holds a new order's payment while setup is open.
	 *
	 * Core's task dismissal is permanent, and a dismissed surface returns on the next new order. The task is shown again
	 * through core's own undo, only when it was dismissed, the store is not connected and the order is held.
	 *
	 * Hooked to `woocommerce_paypal_wallet_capture_pending` after the collecting module recorded the held capture.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $order   The order.
	 * @param mixed $capture The pending capture.
	 */
	public function handle_woocommerce_paypal_wallet_capture_pending( $order = null, $capture = null ): void {
		unset( $capture );
		if ( ! $order instanceof WC_Order || empty( $order->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) ) ) {
			return;
		}

		$this->guarded(
			function (): void {
				$task = new SetUpPayPalWalletTask( TaskLists::get_list( 'extended' ) );
				if ( $task->can_view() && $task->is_dismissed() ) {
					$task->undo_dismiss();
				}
			}
		);
	}

	/**
	 * Add the two PayPal Wallet emails to the email list.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $emails The email classes by key.
	 * @return mixed The list with the two emails added, or the value unchanged when it is not an array or the store has no wallet history.
	 */
	public function register_emails( $emails ) {
		if ( ! is_array( $emails ) || ! $this->has_wallet_history() ) {
			return $emails;
		}

		$emails[ FirstOrderEmail::class ]          = new FirstOrderEmail();
		$emails[ HeldPaymentReturnedEmail::class ] = new HeldPaymentReturnedEmail();

		return $emails;
	}

	/**
	 * Whether the store ever sold with the wallet through the collecting state: a first order, a collecting option or a
	 * platform option. All three are written autoloaded and read from the autoloaded set, so an absent row costs no query.
	 *
	 * @return bool
	 */
	private function has_wallet_history(): bool {
		return $this->options->has_autoloaded( Options::FIRST_ORDER )
			|| $this->options->has_autoloaded( Options::COLLECTING )
			|| $this->options->has_autoloaded( Options::PLATFORM );
	}

	/**
	 * Whether this is an admin or REST request, the only ones the panel's routes and the profiler card serve.
	 *
	 * WooCommerce's REST check reads the request URI as a string, so any other value counts as not REST.
	 *
	 * @return bool
	 */
	private function is_admin_or_rest_request(): bool {
		if ( is_admin() ) {
			return true;
		}

		return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) && function_exists( 'WC' ) && WC()->is_rest_api_request(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only its type is checked.
	}

	/**
	 * Run a surface and log a failure instead of letting it reach the request.
	 *
	 * @param callable $surface The work.
	 */
	private function guarded( callable $surface ): void {
		try {
			$surface();
		} catch ( Throwable $throwable ) {
			$message = sprintf( 'PayPal wallet setup surface failed: %s: %s', get_class( $throwable ), $throwable->getMessage() );
			if ( null !== $this->logger ) {
				$this->logger->warning( $message );
			} else {
				wc_get_logger()->warning( $message, array( 'source' => 'woocommerce-paypal-wallet' ) );
			}
		}
	}
}
