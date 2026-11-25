<?php
/**
 * RenewalGatewayHandover class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the WooPayments gateways for a renewal of a WooPayments subscription that falls due while the setup tier is the
 * lowest, so it is charged at its due time as on a connected store, and as the extension's always-registered gateway does.
 *
 * WooPaymentsStatusChangeRetryListener calls it from WooCommerce Subscriptions' `is_manual()`, only on scheduled and
 * command-line requests of a stored `available` tier the built-in owns. The first call in a request stops account
 * refreshes, registers what the charge needs (WooPaymentsProvider::get_renewal_handover_classes()), and appends the
 * gateways to the list WooCommerce built. Nothing is stored: the next request decides again.
 *
 * @since 11.4.0
 * @internal
 */
class RenewalGatewayHandover {

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Database cache the account is read through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Whether this request already handed the gateways over.
	 *
	 * @var bool
	 */
	private bool $handed_over = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $runtime_arbiter Runtime ownership arbiter.
	 * @param WooPaymentsDatabaseCache  $database_cache  Database cache.
	 */
	final public function init( WooPaymentsRuntimeArbiter $runtime_arbiter, WooPaymentsDatabaseCache $database_cache ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->runtime_arbiter = $runtime_arbiter;
		$this->database_cache  = $database_cache;
	}

	/**
	 * Hand the WooPayments gateways over for a due renewal and tell whether the subscription still renews manually.
	 *
	 * It stays manual, with nothing registered, when the built-in does not own payments, when the subscription is set to
	 * renew manually, or on a staging copy. Otherwise the answer is WooCommerce Subscriptions' own test, asked again with
	 * the gateways listed.
	 *
	 * @param WC_Order $subscription The subscription, as WooCommerce Subscriptions passes it.
	 * @return bool Whether the subscription renews manually.
	 */
	public function handle( WC_Order $subscription ): bool {
		if ( ! $this->runtime_arbiter->is_builtin_owner() ) {
			return true;
		}

		$requires_manual_renewal = is_callable( array( $subscription, 'get_requires_manual_renewal' ) ) && true === $subscription->get_requires_manual_renewal();
		if ( $requires_manual_renewal || SubscriptionMethodPolicy::is_duplicate_site() ) {
			return true;
		}

		if ( ! $this->handed_over ) {
			$this->handed_over = true;
			$this->hand_over();
		}

		return false === wc_get_payment_gateway_by_order( $subscription );
	}

	/**
	 * Register what the renewal's charge needs and list the gateways in the gateway list WooCommerce built.
	 *
	 * Refreshes stop first, so neither listing the gateways nor the charge makes an account request on a command-line run.
	 * The Stripe Billing module registers before the gateways are listed and before this call returns, so both of its
	 * guards are on when the renewal is created and charged.
	 */
	private function hand_over(): void {
		$this->database_cache->disable_refresh();

		$container = wc_get_container();
		foreach ( WooPaymentsProvider::get_renewal_handover_classes() as $class_name ) {
			/**
			 * Registrar.
			 *
			 * @var RegisterHooksInterface $registrar
			 */
			$registrar = $container->get( $class_name );
			$registrar->register();
		}

		/**
		 * WooPayments provider.
		 *
		 * @var WooPaymentsProvider $provider
		 */
		$provider = $container->get( WooPaymentsProvider::class );
		$provider->list_amazon_pay_from_stored_row();

		/**
		 * Provider gateways controller.
		 *
		 * @var ProviderGatewaysController $gateways_controller
		 */
		$gateways_controller = $container->get( ProviderGatewaysController::class );
		$gateways_controller->set_provider(
			static fn(): WooPaymentsProvider => $provider,
			fn(): bool => $this->runtime_arbiter->is_builtin_owner()
		);
		$gateways_controller->register();
		$container->get( RefundRowCapture::class )->register();

		$payment_gateways                   = WC()->payment_gateways();
		$payment_gateways->payment_gateways = $gateways_controller->add_provider_gateways( $payment_gateways->payment_gateways );

		// A mailer built earlier in the request missed the subscriptions controller's email filter.
		if ( did_action( 'woocommerce_email' ) ) {
			$mailer         = WC()->mailer();
			$mailer->emails = SubscriptionsController::add_subscription_emails( $mailer->emails );
		}
	}
}
