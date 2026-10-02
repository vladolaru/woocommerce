<?php
/**
 * WooPaymentsStripeBillingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Root of the Stripe Billing module: decides whether it loads, and is the only class the rest of the provider names.
 *
 * Stripe Billing bills WooCommerce Subscriptions renewals at Stripe. As in client 11.1.0, the module loads whenever
 * WooCommerce Subscriptions is active, because subscriptions already billed at Stripe must keep being served after
 * the toggle is turned off; the toggle only decides whether new subscriptions go to Stripe Billing.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsStripeBillingModule implements RegisterHooksInterface {

	/**
	 * Option that turns Stripe Billing on for new subscriptions (client 11.1.0 `WC_Payments_Features::STRIPE_BILLING_FLAG_NAME`).
	 */
	public const TOGGLE_OPTION = '_wcpay_feature_stripe_billing';

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Whether WooCommerce Subscriptions was active when the module decided to load.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime ownership arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Decide on `plugins_loaded` priority 11 when native owns payments, after WooCommerce Subscriptions has loaded.
	 */
	public function register(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( did_action( 'plugins_loaded' ) ) {
			$this->handle_plugins_loaded();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'handle_plugins_loaded' ), 11 );
	}

	/**
	 * Load the module when WooCommerce Subscriptions is active.
	 *
	 * @internal
	 */
	public function handle_plugins_loaded(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		$this->loaded = true;
	}

	/**
	 * Tell whether the module loaded in this request (native owns payments and WooCommerce Subscriptions is active).
	 *
	 * @return bool
	 */
	public function is_loaded(): bool {
		return $this->loaded;
	}

	/**
	 * Tell whether new subscriptions go to Stripe Billing: the toggle is on and WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public function is_stripe_billing_enabled(): bool {
		return $this->loaded && '1' === get_option( self::TOGGLE_OPTION, '0' );
	}
}
