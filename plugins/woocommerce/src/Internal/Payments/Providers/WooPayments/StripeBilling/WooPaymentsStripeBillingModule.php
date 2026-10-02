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
use WP_REST_Response;
use WP_REST_Server;

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
	 * Migrator off Stripe Billing, built when WooCommerce Subscriptions' background repairer exists and the site is not a staging copy.
	 *
	 * @var StripeBillingMigrator|null
	 */
	private ?StripeBillingMigrator $migrator = null;

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

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

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

		// The settings page shows the Stripe Billing section on a staging copy too, as the plugin does.
		add_filter( 'woocommerce_admin_shared_settings', array( $this, 'add_admin_settings' ), 20 );

		// A staging copy must never change anything at Stripe for the live store.
		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			return;
		}

		$this->attach_maintenance_hooks();
		if ( $this->is_stripe_billing_enabled() ) {
			$this->attach_engaged_hooks();
		}

		// The migrator extends a WooCommerce Subscriptions class, so its file loads only once that class is known to exist.
		if ( class_exists( 'WCS_Background_Repairer' ) && function_exists( 'wcs_get_orders_with_meta_query' ) ) {
			$this->migrator = new StripeBillingMigrator();
			$this->migrator->init_hooks();
		}
	}

	/**
	 * Tell the settings page whether the store may use Stripe Billing, as the plugin's admin settings do.
	 *
	 * Stripe Billing is available to US stores only (client 11.1.0 `class-wc-payments-features.php:290-297`, `class-wc-payments-admin.php:1057-1058`).
	 *
	 * @internal
	 *
	 * @param mixed $settings Admin shared settings.
	 * @return mixed
	 */
	public function add_admin_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$base_location = wc_get_base_location();
		$woopayments   = isset( $settings['woopaymentsSettings'] ) && is_array( $settings['woopaymentsSettings'] ) ? $settings['woopaymentsSettings'] : array();

		$woopayments['isStripeBillingEligible'] = 'US' === ( $base_location['country'] ?? '' );
		$settings['woopaymentsSettings']        = $woopayments;

		return $settings;
	}

	/**
	 * Register the route that starts the migration off Stripe Billing, as the plugin's settings controller does.
	 *
	 * @internal
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/v3',
			'/payments/settings/schedule-stripe-billing-migration',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function () {
					$this->schedule_migration();

					return new WP_REST_Response( array(), 200 );
				},
				'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
			)
		);
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
		return $this->loaded && $this->is_toggle_on();
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
	 * Get the Stripe Billing fields of the WooPayments settings, as the plugin's settings controller reports them.
	 *
	 * The toggle is reported as stored, whether or not WooCommerce Subscriptions is active.
	 *
	 * @return array{is_stripe_billing_enabled:bool,is_migrating_stripe_billing:bool,stripe_billing_subscription_count:int,stripe_billing_migrated_count:int}
	 */
	public function get_settings_fields(): array {
		return array(
			'is_stripe_billing_enabled'         => $this->is_toggle_on(),
			'is_migrating_stripe_billing'       => $this->is_migrating(),
			'stripe_billing_subscription_count' => $this->get_stripe_billing_subscription_count(),
			'stripe_billing_migrated_count'     => $this->get_migrated_subscription_count(),
		);
	}

	/**
	 * Turn the toggle on or off; turning it off starts the migration of the remaining Stripe-billed subscriptions.
	 *
	 * @param bool $enabled Whether new subscriptions go to Stripe Billing.
	 */
	public function set_stripe_billing_enabled( bool $enabled ): void {
		update_option( self::TOGGLE_OPTION, $enabled ? '1' : '0' );

		if ( ! $enabled ) {
			$this->schedule_migration();
		}
	}

	/**
	 * Count the subscriptions still billed by Stripe Billing; 0 when the module is not loaded.
	 *
	 * @return int
	 */
	public function get_stripe_billing_subscription_count(): int {
		return $this->loaded ? $this->get_subscription_service()->get_stripe_billing_subscription_count() : 0;
	}

	/**
	 * Count the subscriptions migrated off Stripe Billing; 0 when the module is not loaded.
	 *
	 * @return int
	 */
	public function get_migrated_subscription_count(): int {
		return $this->loaded ? $this->get_subscription_service()->get_migrated_subscription_count() : 0;
	}

	/**
	 * Tell whether a migration off Stripe Billing is running; false when there is no migrator.
	 *
	 * @return bool
	 */
	public function is_migrating(): bool {
		return $this->migrator ? $this->migrator->is_migrating() : false;
	}

	/**
	 * Start the migration off Stripe Billing when Stripe-billed subscriptions remain and none is running; nothing without a migrator.
	 */
	public function schedule_migration(): void {
		if ( $this->migrator && ! $this->migrator->is_migrating() && $this->get_stripe_billing_subscription_count() > 0 ) {
			$this->migrator->schedule_migrate_wcpay_subscriptions_action();
		}
	}

	/**
	 * Handle one Stripe Billing invoice event: `invoice.upcoming`, `invoice.paid` or `invoice.payment_failed`.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws \InvalidArgumentException When the event has missing data or names no subscription of this store; it is not retried.
	 * @throws \Throwable When processing fails for another reason, so the event is retried.
	 */
	public function handle_invoice_event( array $event ): void {
		wc_get_container()->get( StripeBillingEventHandler::class )->handle_event( $event );
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
		$this->attach( 'admin_notices', StripeBillingPluginsScreenNotice::class, 'maybe_show_notice' );
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

		$this->attach( 'woocommerce_subscriptions_minimum_processable_recurring_amount', StripeBillingMinimumAmountHandler::class, 'get_minimum_recurring_amount', 10, 2 );

		$this->attach( 'wcs_view_subscription_actions', StripeBillingChangePaymentMethodHandler::class, 'update_subscription_change_payment_button', 15, 2 );
		$this->attach( 'woocommerce_can_subscription_be_updated_to_new-payment-method', StripeBillingChangePaymentMethodHandler::class, 'can_update_payment_method', 15, 2 );
		$this->attach( 'woocommerce_my_account_my_orders_actions', StripeBillingChangePaymentMethodHandler::class, 'update_order_pay_button', 15, 2 );
		$this->attach( 'woocommerce_subscriptions_change_payment_method_page_title', StripeBillingChangePaymentMethodHandler::class, 'change_payment_method_page_title', 10, 2 );
		$this->attach( 'woocommerce_subscriptions_change_payment_method_page_notice_message', StripeBillingChangePaymentMethodHandler::class, 'change_payment_method_page_notice', 10, 2 );
		$this->attach( 'template_redirect', StripeBillingChangePaymentMethodHandler::class, 'redirect_pay_for_order_to_update_payment_method' );
		$this->attach( 'woocommerce_change_payment_button_text', StripeBillingChangePaymentMethodHandler::class, 'change_payment_method_form_submit_text' );
	}

	/**
	 * Tell whether the toggle is stored as on, whether or not WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	private function is_toggle_on(): bool {
		return '1' === get_option( self::TOGGLE_OPTION, '0' );
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
