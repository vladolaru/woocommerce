<?php
/**
 * SetUpPayPalWalletTask class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use DateTimeZone;
use WC_DateTime;

/**
 * The Home task, in "Things to do next", that asks the merchant to set up PayPal Wallet once an order was paid with it.
 *
 * It reads the connection state, the first-order marker and the held orders, so it works whoever owns the wallet. The
 * held-orders answers are kept for the life of the task object, because the task list asks for them several times in
 * one request.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class SetUpPayPalWalletTask extends Task {

	/**
	 * The task ID.
	 *
	 * @since 11.3.0
	 */
	public const ID = 'wc-paypal-wallet-setup';

	/**
	 * The held-orders query.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection;

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The number of held orders, once asked.
	 *
	 * @var int|null
	 */
	private ?int $held_count = null;

	/**
	 * Whether the earliest deadline was asked.
	 *
	 * @var bool
	 */
	private bool $deadline_read = false;

	/**
	 * The earliest deadline across the held orders, once asked.
	 *
	 * @var int|null
	 */
	private ?int $earliest_deadline = null;

	/**
	 * Constructor.
	 *
	 * @param mixed                $task_list   The parent task list.
	 * @param HeldOrders|null      $held_orders The held-orders query; the stored orders by default.
	 * @param ConnectionState|null $connection  The connection state reader; the stored options by default.
	 */
	public function __construct( $task_list = null, ?HeldOrders $held_orders = null, ?ConnectionState $connection = null ) {
		parent::__construct( $task_list );
		$this->held_orders = $held_orders ?? new HeldOrders();
		$this->options     = new Options();
		$this->connection  = $connection ?? new ConnectionState( $this->options );
	}

	/**
	 * The title, shared with the Inbox note.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public static function title_text(): string {
		return __( 'Set up PayPal Wallet', 'woocommerce' );
	}

	/**
	 * The content for one waiting order, shared with the Inbox note.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public static function content_text(): string {
		return __( 'You received an order paid with PayPal Wallet. Connect PayPal Wallet to receive the payment.', 'woocommerce' );
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return self::title_text();
	}

	/**
	 * Content: what to do, or, with two or more orders held, how many wait and when the first is returned.
	 *
	 * @return string
	 */
	public function get_content() {
		// Core builds the content of every task it lists; a task nobody sees does not count held orders.
		if ( ! $this->can_view() || $this->held_count() < 2 ) {
			return self::content_text();
		}
		$count    = $this->held_count();
		$deadline = $this->earliest_deadline();
		if ( null === $deadline ) {
			return self::content_text();
		}

		$date = new WC_DateTime( '@' . $deadline );
		$date->setTimezone( new DateTimeZone( wc_timezone_string() ) );

		return sprintf(
			/* translators: 1: the number of orders waiting for setup, 2: the date the first one is returned to the customer. */
			_n(
				'%1$d order is waiting; the first is returned to the customer on %2$s if setup is not completed',
				'%1$d orders are waiting; the first is returned to the customer on %2$s if setup is not completed',
				$count,
				'woocommerce'
			),
			$count,
			wc_format_datetime( $date )
		);
	}

	/**
	 * Time.
	 *
	 * @return string
	 */
	public function get_time() {
		return __( '2 minutes', 'woocommerce' );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.WrongStyle -- PHPStan reads its ignore from the line above the signature.
	/**
	 * Level: first among the tasks that are not complete.
	 *
	 * @return int
	 */
	// @phpstan-ignore method.childReturnType (The deprecated parent documents a string; the plan names the number.)
	public function get_level() {
		return 1;
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.WrongStyle

	/**
	 * Action URL: the wallet's settings, or the Plugins page while the extension owns the wallet, where that route does
	 * not exist and the Plugins page notice explains what waits.
	 *
	 * @return string
	 */
	public function get_action_url() {
		return RuntimeServices::extension_owns_wallet() ? admin_url( 'plugins.php' ) : PayPalWalletBootstrap::get_settings_url();
	}

	/**
	 * Check if a task is dismissable.
	 *
	 * @return bool
	 */
	public function is_dismissable() {
		return true;
	}

	/**
	 * Whether the merchant has something to set up: a wallet order exists and the store is not first-party connected.
	 *
	 * @return bool
	 */
	public function can_view() {
		return $this->options->first_order_id() > 0 && ConnectionState::CONNECTED !== $this->connection->resolve();
	}

	/**
	 * Whether setup is done: the store is connected through the platform and no order is held.
	 *
	 * A task the merchant cannot see is never complete. Core marks a complete task as done and records a Tracks event for
	 * it, which must not happen for a first-party connected store that never saw the task, and its held-order count
	 * would be a wasted query.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return $this->can_view()
			&& ConnectionState::PLATFORM_CONNECTED === $this->connection->resolve()
			&& 0 === $this->held_count();
	}

	/**
	 * The number of held orders, asked once.
	 *
	 * @return int
	 */
	private function held_count(): int {
		if ( null === $this->held_count ) {
			$this->held_count = $this->held_orders->count();
		}

		return $this->held_count;
	}

	/**
	 * The earliest deadline across the held orders, asked once.
	 *
	 * @return int|null
	 */
	private function earliest_deadline(): ?int {
		if ( ! $this->deadline_read ) {
			$this->earliest_deadline = $this->held_orders->earliest_deadline();
			$this->deadline_read     = true;
		}

		return $this->earliest_deadline;
	}
}
