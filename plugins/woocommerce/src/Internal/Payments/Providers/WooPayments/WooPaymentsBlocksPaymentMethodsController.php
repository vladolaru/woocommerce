<?php
/**
 * WooPaymentsBlocksPaymentMethodsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers one Blocks payment method type per WooPayments gateway while the setup tier is active.
 *
 * Blocks fires the registration action once per request, at `init` priority 5, on the registry its cart and checkout read.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsBlocksPaymentMethodsController implements RegisterHooksInterface {

	/**
	 * Setup tier store.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Native WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Checkout scripts and their config.
	 *
	 * @var WooPaymentsCheckoutAssets
	 */
	private WooPaymentsCheckoutAssets $checkout_assets;

	/**
	 * WooPay eligibility rules.
	 *
	 * @var Eligibility
	 */
	private Eligibility $woopay_eligibility;

	/**
	 * Express checkout service.
	 *
	 * @var WooPaymentsExpressCheckoutService
	 */
	private WooPaymentsExpressCheckoutService $express_checkout_service;

	/**
	 * Fraud-prevention token service.
	 *
	 * @var WooPaymentsFraudPreventionService
	 */
	private WooPaymentsFraudPreventionService $fraud_prevention_service;

	/**
	 * Registrar of the script handles the WooPayments shopper scripts share.
	 *
	 * @var WooPaymentsFrontendAssets
	 */
	private WooPaymentsFrontendAssets $frontend_assets;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsSetupTier              $setup_tier               Setup tier store.
	 * @param WooPaymentsProvider               $provider                 Native WooPayments provider.
	 * @param WooPaymentsRuntimeArbiter         $arbiter                  Runtime owner arbiter.
	 * @param WooPaymentsCheckoutAssets         $checkout_assets          Checkout scripts and their config.
	 * @param Eligibility                       $woopay_eligibility       WooPay eligibility rules.
	 * @param WooPaymentsExpressCheckoutService $express_checkout_service Express checkout service.
	 * @param WooPaymentsFraudPreventionService $fraud_prevention_service Fraud-prevention token service.
	 * @param WooPaymentsFrontendAssets         $frontend_assets          Registrar of the shared shopper script handles.
	 */
	final public function init( // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		WooPaymentsSetupTier $setup_tier,
		WooPaymentsProvider $provider,
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsCheckoutAssets $checkout_assets,
		Eligibility $woopay_eligibility,
		WooPaymentsExpressCheckoutService $express_checkout_service,
		WooPaymentsFraudPreventionService $fraud_prevention_service,
		WooPaymentsFrontendAssets $frontend_assets
	): void {
		$this->setup_tier               = $setup_tier;
		$this->provider                 = $provider;
		$this->arbiter                  = $arbiter;
		$this->checkout_assets          = $checkout_assets;
		$this->woopay_eligibility       = $woopay_eligibility;
		$this->express_checkout_service = $express_checkout_service;
		$this->fraud_prevention_service = $fraud_prevention_service;
		$this->frontend_assets          = $frontend_assets;
	}

	/**
	 * Hook the Blocks payment method registration once Blocks has loaded.
	 *
	 * Blocks adds its own registration callback while it loads, and the WooPayments methods register right after the
	 * bundled ones, ahead of callbacks other plugins add later.
	 *
	 * @since 11.2.0
	 */
	public function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->handle_woocommerce_blocks_loaded();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'handle_woocommerce_blocks_loaded' ), 0 );
	}

	/**
	 * Handle the woocommerce_blocks_loaded action: hook the payment method registration.
	 *
	 * @internal
	 */
	public function handle_woocommerce_blocks_loaded(): void {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'handle_woocommerce_blocks_payment_method_type_registration' ) );
	}

	/**
	 * Handle the woocommerce_blocks_payment_method_type_registration action: register one Blocks payment method type per
	 * WooPayments gateway while the setup tier is active.
	 *
	 * Each call is a registration pass, so it clears the checkout config memos first: its gateways then share one fresh
	 * build of the config base.
	 *
	 * @internal
	 *
	 * @param mixed $payment_method_registry Blocks payment method registry.
	 */
	public function handle_woocommerce_blocks_payment_method_type_registration( $payment_method_registry ): void {
		if ( ! $payment_method_registry instanceof PaymentMethodRegistry || WooPaymentsSetupTier::ACTIVE !== $this->setup_tier->get_effective_tier() ) {
			return;
		}

		$this->checkout_assets->reset_config_memos();
		$asset_api = Package::container()->get( AssetApi::class );
		foreach ( $this->provider->get_payment_gateways() as $gateway ) {
			$payment_method_registry->register(
				new WooPayments(
					$asset_api,
					$gateway,
					$this->arbiter,
					$this->provider,
					$this->checkout_assets,
					$this->woopay_eligibility,
					$this->express_checkout_service,
					$this->fraud_prevention_service,
					$this->frontend_assets
				)
			);
		}
	}
}
