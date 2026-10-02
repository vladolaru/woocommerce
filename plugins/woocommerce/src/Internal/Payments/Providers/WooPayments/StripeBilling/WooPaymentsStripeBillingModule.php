<?php
/**
 * WooPaymentsStripeBillingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

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
	 * Load the module when WooCommerce Subscriptions is active, and attach its hooks unless the site is a staging copy.
	 *
	 * @internal
	 */
	public function handle_plugins_loaded(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		$this->loaded = true;

		// A staging copy must never change anything at Stripe for the live store.
		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			return;
		}

		$this->attach_maintenance_hooks();
		if ( $this->is_stripe_billing_enabled() ) {
			$this->attach_engaged_hooks();
		}
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

	/**
	 * Tell whether a subscription is billed by Stripe Billing; always false when the module is not loaded.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	public function is_stripe_billed_subscription( WC_Order $subscription ): bool {
		return $this->loaded && $this->get_subscription_service()->is_wcpay_subscription( $subscription );
	}

	/**
	 * Tell whether any subscription related to an order is billed by Stripe Billing; always false when the module is not loaded.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_stripe_billed_order( WC_Order $order ): bool {
		return $this->loaded && $this->get_subscription_service()->is_wcpay_subscription_order( $order );
	}

	/**
	 * Attach the hooks that serve existing Stripe Billing data whatever the toggle.
	 */
	private function attach_maintenance_hooks(): void {
		$this->attach( 'woocommerce_duplicate_product_exclude_meta', StripeBillingProductService::class, 'exclude_meta_wcpay_product' );

		$this->attach( 'woocommerce_payment_token_added_to_order', StripeBillingSubscriptionService::class, 'update_wcpay_subscription_payment_method', 10, 3 );
		$this->attach( 'woocommerce_subscription_status_cancelled', StripeBillingSubscriptionService::class, 'cancel_subscription' );
		$this->attach( 'woocommerce_subscription_status_expired', StripeBillingSubscriptionService::class, 'cancel_subscription' );
		$this->attach( 'woocommerce_subscription_status_on-hold', StripeBillingSubscriptionService::class, 'handle_subscription_status_on_hold' );
		$this->attach( 'woocommerce_subscription_status_pending-cancel', StripeBillingSubscriptionService::class, 'set_pending_cancel_for_subscription' );
		$this->attach( 'woocommerce_subscription_status_pending-cancel_to_active', StripeBillingSubscriptionService::class, 'reactivate_subscription' );
		$this->attach( 'woocommerce_subscription_status_on-hold_to_active', StripeBillingSubscriptionService::class, 'reactivate_subscription' );
		$this->attach( 'woocommerce_subscription_payment_gateway_supports', StripeBillingSubscriptionService::class, 'prevent_wcpay_subscription_changes', 10, 3 );
		$this->attach( 'woocommerce_order_actions', StripeBillingSubscriptionService::class, 'prevent_wcpay_manual_renewal', 11 );
		$this->attach( 'woocommerce_payments_changed_subscription_payment_method', StripeBillingSubscriptionService::class, 'maybe_attempt_payment_for_subscription', 10, 2 );
		$this->attach( 'woocommerce_admin_order_data_after_billing_address', StripeBillingSubscriptionService::class, 'show_wcpay_subscription_id' );
		$this->attach( 'woocommerce_subscription_payment_method_updated_from_' . WooPaymentsPersistenceProfile::GATEWAY_ID, StripeBillingSubscriptionService::class, 'maybe_cancel_subscription', 10, 2 );
		// The client sets this context before the filter runs, so it goes first here.
		$this->attach( 'wcpay_metadata_from_order', StripeBillingSubscriptionService::class, 'set_stripe_billing_payment_context', 0, 2 );
	}

	/**
	 * Attach the hooks that only run while the toggle is on.
	 */
	private function attach_engaged_hooks(): void {
		$this->attach( 'shutdown', StripeBillingProductService::class, 'create_or_update_products' );
		$this->attach( 'untrashed_post', StripeBillingProductService::class, 'maybe_unarchive_product' );
		$this->attach( 'wp_trash_post', StripeBillingProductService::class, 'maybe_archive_product' );
		$this->attach( 'save_post_product', StripeBillingProductService::class, 'maybe_schedule_product_create_or_update', 12 );
		$this->attach( 'woocommerce_save_product_variation', StripeBillingProductService::class, 'maybe_schedule_product_create_or_update', 30 );
		$this->attach( 'woocommerce_order_payment_status_changed', StripeBillingInvoiceService::class, 'maybe_record_invoice_payment' );
		$this->attach( 'woocommerce_renewal_order_payment_complete', StripeBillingInvoiceService::class, 'maybe_record_invoice_payment', 11 );

		$this->attach( 'woocommerce_checkout_subscription_created', StripeBillingSubscriptionService::class, 'create_subscription' );
		$this->attach( 'woocommerce_renewal_order_payment_complete', StripeBillingSubscriptionService::class, 'create_subscription_for_manual_renewal' );
		$this->attach( 'woocommerce_subscription_payment_method_updated', StripeBillingSubscriptionService::class, 'maybe_create_subscription_from_update_payment_method', 10, 2 );
	}

	/**
	 * Get the subscription service, which holds the one rule for what is Stripe-billed.
	 *
	 * @return StripeBillingSubscriptionService
	 */
	private function get_subscription_service(): StripeBillingSubscriptionService {
		return wc_get_container()->get( StripeBillingSubscriptionService::class );
	}

	/**
	 * Attach a hook whose callback resolves the service from the container only when the hook fires.
	 *
	 * Works for actions and filters, since an action is a filter whose return value is ignored.
	 *
	 * @param string $hook          Hook name.
	 * @param string $service_class Service class name.
	 * @param string $method        Service method name.
	 * @param int    $priority      Priority.
	 * @param int    $accepted_args Number of arguments the method takes.
	 *
	 * @phpstan-param class-string<object> $service_class
	 */
	private function attach( string $hook, string $service_class, string $method, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter(
			$hook,
			static function ( ...$args ) use ( $service_class, $method ) {
				return wc_get_container()->get( $service_class )->$method( ...$args );
			},
			$priority,
			$accepted_args
		);
	}
}
