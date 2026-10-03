<?php
/**
 * StripeBillingPluginsScreenNotice class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

defined( 'ABSPATH' ) || exit;

/**
 * Warns on the Plugins screen that Stripe-billed subscriptions keep renewing after WooCommerce Subscriptions is deactivated.
 *
 * Client 11.1.0 stops the deactivation with a confirmation modal (`includes/subscriptions/class-wc-payments-subscriptions-plugin-notice-manager.php`,
 * `templates/html-subscriptions-plugin-notice.php`). Matching that modal needs the plugin's own stylesheet, so native shows the
 * same copy as a warning notice on the same screen instead (spec section 6, monitor 2026-10-02 Q-13).
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingPluginsScreenNotice {

	/**
	 * Subscription service.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private StripeBillingSubscriptionService $subscription_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingSubscriptionService $subscription_service Subscription service.
	 */
	final public function init( StripeBillingSubscriptionService $subscription_service ): void {
		$this->subscription_service = $subscription_service;
	}

	/**
	 * Show the warning on the Plugins screen to users who can deactivate plugins, while an active subscription is Stripe-billed.
	 *
	 * @internal
	 */
	public function maybe_show_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'deactivate_plugins' ) ) {
			return;
		}

		if ( ! $this->subscription_service->has_active_stripe_billed_subscriptions() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					// translators: %1$s and %2$s: opening and closing link tags, %3$s and %4$s: opening and closing strong tags, %5$s: WooPayments, %6$s: Woo Subscriptions.
					esc_html__( 'Your store has subscriptions using %5$s Stripe Billing functionality for payment processing. Due to the %1$soff-site billing engine%2$s these subscriptions use,%3$s they will continue to renew even after you deactivate %6$s%4$s.', 'woocommerce' ),
					'<a href="https://woocommerce.com/document/woopayments/subscriptions/stripe-billing/#faq" target="_blank">',
					'</a>',
					'<strong>',
					'</strong>',
					'WooPayments',
					'Woo Subscriptions'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					// translators: %1$s and %2$s: opening and closing link tags, %3$s: Woo Subscriptions.
					esc_html__( 'If you do not want these subscriptions to continue to be billed, you should %1$scancel these subscriptions%2$s prior to deactivating %3$s.', 'woocommerce' ),
					'<a href="https://woocommerce.com/document/subscriptions/customers-view/suspend-cancel-or-remove-an-item/#how-to-cancel-a-subscription-as-a-store-manager" target="_blank" rel="noreferrer noopener">',
					'</a>',
					'Woo Subscriptions'
				);
				?>
			</p>
		</div>
		<?php
	}
}
