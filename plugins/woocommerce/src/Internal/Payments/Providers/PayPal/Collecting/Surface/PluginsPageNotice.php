<?php
/**
 * PluginsPageNotice class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;

defined( 'ABSPATH' ) || exit;

/**
 * The notice on the Plugins page while the PayPal Payments extension owns the wallet and orders paid with PayPal Wallet
 * are still held for the payee.
 *
 * With the extension in charge the wallet's own setup surfaces do not boot, so the merchant would not otherwise see that
 * a payment waits. The held-orders query runs only on the Plugins page.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PluginsPageNotice {

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The held-orders query.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The runtime arbiter, or null for the container's.
	 *
	 * @var PayPalWalletRuntimeArbiter|null
	 */
	private ?PayPalWalletRuntimeArbiter $arbiter;

	/**
	 * Constructor.
	 *
	 * @param Options|null                    $options     The option reader; the stored options by default.
	 * @param HeldOrders|null                 $held_orders The held-orders query; the stored orders by default.
	 * @param PayPalWalletRuntimeArbiter|null $arbiter     Tells who owns the wallet; the container's by default.
	 */
	public function __construct( ?Options $options = null, ?HeldOrders $held_orders = null, ?PayPalWalletRuntimeArbiter $arbiter = null ) {
		$this->options     = $options ?? new Options();
		$this->held_orders = $held_orders ?? new HeldOrders();
		$this->arbiter     = $arbiter;
	}

	/**
	 * Hook the Plugins page load, which is where the notice gets attached.
	 *
	 * @since 11.3.0
	 */
	public function register(): void {
		add_action( 'load-plugins.php', array( $this, 'handle_load_plugins_php' ) );
	}

	/**
	 * Attach the notice once the Plugins page loads.
	 *
	 * Hooked to `load-plugins.php`.
	 *
	 * @since 11.3.0
	 */
	public function handle_load_plugins_php(): void {
		add_action( 'admin_notices', array( $this, 'handle_admin_notices' ) );
	}

	/**
	 * Print the notice when the extension owns the wallet and orders are held.
	 *
	 * Hooked to `admin_notices` by handle_load_plugins_php().
	 *
	 * @since 11.3.0
	 */
	public function handle_admin_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$arbiter = $this->arbiter ?? wc_get_container()->get( PayPalWalletRuntimeArbiter::class );
		if ( PayPalWalletRuntimeArbiter::OWNER_EXTENSION !== $arbiter->get_runtime_owner() ) {
			return;
		}

		$payee = $this->options->payee_email();
		$count = $this->held_orders->count();
		if ( '' === $payee || $count <= 0 ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: the number of orders paid with PayPal Wallet that wait for setup, 2: the email address of the PayPal account the payments wait for. */
			_n(
				'%1$d order paid with PayPal Wallet is waiting for the PayPal account %2$s to be set up and confirmed',
				'%1$d orders paid with PayPal Wallet are waiting for the PayPal account %2$s to be set up and confirmed',
				$count,
				'woocommerce'
			),
			$count,
			$payee
		);

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
