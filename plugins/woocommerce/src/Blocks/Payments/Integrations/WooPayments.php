<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

use Automattic\WooCommerce\Blocks\Assets\Api;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;

/**
 * WooPayments payment method integration: one Blocks payment method type per WooPayments gateway.
 *
 * @since 11.0.0
 */
final class WooPayments extends AbstractPaymentMethodType {

	/**
	 * Checkout Blocks script handle.
	 */
	private const CHECKOUT_BLOCKS_SCRIPT_HANDLE = 'wc-blocks-checkout';

	/**
	 * Blocks card payment method script handle.
	 */
	private const PAYMENT_METHOD_SCRIPT_HANDLE = 'wc-payment-method-woopayments';

	/**
	 * Blocks anti-fraud loader script handle (Sift and Stripe fraud signals).
	 */
	private const FRAUD_SCRIPTS_SCRIPT_HANDLE = 'wc-payment-method-woopayments-fraud-scripts';

	/**
	 * Blocks WooPay express payment method script handle.
	 */
	private const WOOPAY_SCRIPT_HANDLE = 'wc-payment-method-woopayments-woopay';

	/**
	 * Blocks Apple Pay, Google Pay and Amazon Pay express payment method script handle.
	 */
	private const EXPRESS_CHECKOUT_SCRIPT_HANDLE = 'wc-payment-method-woopayments-express-checkout';

	/**
	 * Code the card, WooPay and express scripts share, built as one chunk (client/blocks/bin/webpack-configs.js).
	 */
	private const COMMON_SCRIPT_HANDLE = 'wc-payment-method-woopayments-common';

	/**
	 * The WooPay email check the card and WooPay scripts share, built as one chunk.
	 */
	private const WOOPAY_COMMON_SCRIPT_HANDLE = 'wc-payment-method-woopayments-woopay-common';

	/**
	 * Asset API.
	 *
	 * @var Api
	 */
	private Api $asset_api;

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
	 * Native WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

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
	 * The WooPayments gateway this Blocks payment method stands for.
	 *
	 * @var WooPaymentsGateway
	 */
	private WooPaymentsGateway $payment_gateway;

	/**
	 * Constructor.
	 *
	 * @param Api                               $asset_api                Asset API.
	 * @param WooPaymentsGateway                $payment_gateway          The WooPayments gateway this payment method stands for.
	 * @param WooPaymentsRuntimeArbiter         $arbiter                  Runtime owner arbiter.
	 * @param WooPaymentsProvider               $provider                 Native WooPayments provider.
	 * @param WooPaymentsCheckoutAssets         $checkout_assets          Checkout scripts and their config.
	 * @param Eligibility                       $woopay_eligibility       WooPay eligibility rules.
	 * @param WooPaymentsExpressCheckoutService $express_checkout_service Express checkout service.
	 * @param WooPaymentsFraudPreventionService $fraud_prevention_service Fraud-prevention token service.
	 * @param WooPaymentsFrontendAssets         $frontend_assets          Registrar of the shared shopper script handles.
	 */
	public function __construct( Api $asset_api, WooPaymentsGateway $payment_gateway, WooPaymentsRuntimeArbiter $arbiter, WooPaymentsProvider $provider, WooPaymentsCheckoutAssets $checkout_assets, Eligibility $woopay_eligibility, WooPaymentsExpressCheckoutService $express_checkout_service, WooPaymentsFraudPreventionService $fraud_prevention_service, WooPaymentsFrontendAssets $frontend_assets ) {
		$this->asset_api                = $asset_api;
		$this->payment_gateway          = $payment_gateway;
		$this->arbiter                  = $arbiter;
		$this->provider                 = $provider;
		$this->checkout_assets          = $checkout_assets;
		$this->woopay_eligibility       = $woopay_eligibility;
		$this->express_checkout_service = $express_checkout_service;
		$this->fraud_prevention_service = $fraud_prevention_service;
		$this->frontend_assets          = $frontend_assets;
		$this->name                     = $payment_gateway->id;
	}

	/**
	 * Initializes the payment method type: the Blocks payment styles follow the Cart and Checkout block renders.
	 */
	public function initialize(): void {
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( self::class, 'enqueue_styles_for_rendered_block' ) );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( self::class, 'enqueue_styles_for_rendered_block' ) );
	}

	/**
	 * Enqueue the WooPayments Blocks styles registered for the Cart or Checkout block being rendered.
	 *
	 * The block registers them just before, when it asks for the payment method script handles. Following the render
	 * covers a block from a page template, and keeps the styles off classic checkout, cart and product pages, where client
	 * 11.2.0 loads them (class-wc-payments-blocks-payment-method.php:55).
	 *
	 * @internal
	 */
	public static function enqueue_styles_for_rendered_block(): void {
		foreach ( array( self::PAYMENT_METHOD_SCRIPT_HANDLE, self::WOOPAY_SCRIPT_HANDLE, self::EXPRESS_CHECKOUT_SCRIPT_HANDLE ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->arbiter->is_builtin_owner() &&
			$this->provider->can_process_payments() &&
			$this->checkout_assets->should_expose_checkout_surface() &&
			$this->payment_gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		// Client 11.1.0 runs its fraud scripts on every Blocks cart and checkout; this small loader keeps that without the card stack.
		$this->asset_api->register_script(
			self::FRAUD_SCRIPTS_SCRIPT_HANDLE,
			'assets/client/blocks/wc-payment-method-woopayments-fraud-scripts.js',
			array(),
			false
		);
		// The Blocks express script reads the token only from the page, as client 11.2.0 provides it (class-wc-payments-blocks-payment-method.php:77).
		$this->fraud_prevention_service->maybe_enqueue_token_script();

		$handles = array();
		if ( ! $this->is_blocks_cart_only_surface() ) {
			$this->frontend_assets->register_stripe_script();
			// The card script's asset file lists this handle: FingerprintJS is a build external.
			$this->frontend_assets->register_fingerprint_script();

			$this->asset_api->register_script(
				self::PAYMENT_METHOD_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments.js',
				array_merge( array( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts( true ) )
			);
			$this->asset_api->register_style(
				self::PAYMENT_METHOD_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments.css',
				array(),
				'all',
				true
			);
			$this->maybe_enqueue_blocks_payment_style( self::PAYMENT_METHOD_SCRIPT_HANDLE );
			$handles[] = self::PAYMENT_METHOD_SCRIPT_HANDLE;
		}

		if ( $this->is_base_gateway_integration() && $this->should_enqueue_woopay_assets() ) {
			$this->asset_api->register_script(
				self::WOOPAY_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments-woopay.js',
				array_merge( array( self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts( true ) )
			);
			$this->asset_api->register_style(
				self::WOOPAY_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments-woopay.css',
				array(),
				'all',
				true
			);
			$this->maybe_enqueue_blocks_payment_style( self::WOOPAY_SCRIPT_HANDLE );
			$handles[] = self::WOOPAY_SCRIPT_HANDLE;
		}

		if ( $this->is_base_gateway_integration() && $this->should_enqueue_express_checkout_assets() ) {
			$this->frontend_assets->register_stripe_script();
			$this->asset_api->register_script(
				self::EXPRESS_CHECKOUT_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments-express-checkout.js',
				array_merge( array( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts( false ) )
			);
			$this->asset_api->register_style(
				self::EXPRESS_CHECKOUT_SCRIPT_HANDLE,
				'assets/client/blocks/wc-payment-method-woopayments-express-checkout.css',
				array(),
				'all',
				true
			);
			$this->maybe_enqueue_blocks_payment_style( self::EXPRESS_CHECKOUT_SCRIPT_HANDLE );
			$handles[] = self::EXPRESS_CHECKOUT_SCRIPT_HANDLE;
		}

		$handles[] = self::FRAUD_SCRIPTS_SCRIPT_HANDLE;

		return $handles;
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$data = $this->checkout_assets->get_blocks_payment_method_data( $this->get_card_gateway_supports(), $this->payment_gateway->get_payment_method_definition(), $this->get_button_context() );
		$data = array_merge(
			$data,
			array(
				'gatewayId' => $this->payment_gateway->id,
			)
		);

		if ( $this->is_base_gateway_integration() && $this->should_enqueue_express_checkout_assets() ) {
			$data['expressCheckoutParams'] = $this->express_checkout_service->get_express_checkout_params( $this->get_button_context() );
		}

		return $data;
	}

	/**
	 * Get the card gateway's support features, which every WooPayments Blocks method shares, as in client 11.1.0.
	 *
	 * The card gateway exists even when card is not offered, as the client's does.
	 *
	 * @return string[]
	 */
	private function get_card_gateway_supports(): array {
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $this->payment_gateway->id ) {
			return $this->payment_gateway->supports;
		}

		$card_gateway = $this->provider->get_gateway_for_method( 'card' );

		return null === $card_gateway ? array() : $card_gateway->supports;
	}

	/**
	 * Get the Blocks payment method instances for native WooPayments gateways, one per gateway.
	 *
	 * Each call starts a registration pass, so its split gateways share one fresh build of the config base.
	 *
	 * @return WooPayments[]
	 */
	public function get_payment_method_integrations(): array {
		$this->checkout_assets->reset_config_memos();

		return array_map(
			fn( WooPaymentsGateway $gateway ): WooPayments => new self(
				$this->asset_api,
				$gateway,
				$this->arbiter,
				$this->provider,
				$this->checkout_assets,
				$this->woopay_eligibility,
				$this->express_checkout_service,
				$this->fraud_prevention_service,
				$this->frontend_assets
			),
			$this->provider->get_payment_gateways()
		);
	}

	/**
	 * Register the shared chunks a WooPayments Blocks script needs; each script waits for them before it runs.
	 *
	 * @param bool $with_woopay_email_check Whether the script also needs the WooPay email check chunk.
	 * @return string[] The shared chunk handles to list as the script's dependencies.
	 */
	private function register_common_scripts( bool $with_woopay_email_check ): array {
		$handles = array( self::COMMON_SCRIPT_HANDLE => 'assets/client/blocks/wc-payment-method-woopayments-common.js' );
		if ( $with_woopay_email_check ) {
			$handles[ self::WOOPAY_COMMON_SCRIPT_HANDLE ] = 'assets/client/blocks/wc-payment-method-woopayments-woopay-common.js';
		}

		foreach ( $handles as $handle => $path ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				$this->asset_api->register_script( $handle, $path, array(), false );
			}
		}

		return array_keys( $handles );
	}

	/**
	 * Tell whether this integration represents the base WooPayments card gateway.
	 *
	 * @return bool
	 */
	private function is_base_gateway_integration(): bool {
		return WooPaymentsPersistenceVocabulary::GATEWAY_ID === $this->name;
	}

	/**
	 * Enqueue a Blocks payment style in the block editor, which previews the cart and checkout without rendering them.
	 *
	 * On the front end the style follows the block render instead (enqueue_styles_for_rendered_block()).
	 *
	 * @param string $handle Style handle.
	 */
	private function maybe_enqueue_blocks_payment_style( string $handle ): void {
		if ( is_admin() ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Tell whether the request is a cart or checkout page, or an admin request, for the WooPay and express assets.
	 *
	 * These are client 11.2.0's page checks (class-wc-payments-express-checkout-button-helper.php:275-286), so a cart or
	 * checkout page counts by its page ID, also when a page template renders its block. Admin requests count, because the
	 * block editor previews the cart and checkout with every payment method.
	 *
	 * @return bool
	 */
	private function is_cart_checkout_or_admin(): bool {
		if ( is_admin() ) {
			return true;
		}

		return is_cart() || has_block( 'woocommerce/cart' ) || is_checkout() || has_block( 'woocommerce/checkout' );
	}

	/**
	 * Tell whether the current request renders the Blocks cart without the Blocks checkout.
	 *
	 * The cart renders no regular payment method, so it needs no card script, card style, Stripe.js or FingerprintJS of its own.
	 * Express, WooPay and BNPL messaging declare what they need themselves. Requests that cannot be identified keep the card stack.
	 * Admin requests answer no for the same reason the surface check above answers yes: the block editor keeps the full stack.
	 *
	 * @return bool
	 */
	private function is_blocks_cart_only_surface(): bool {
		if ( is_admin() ) {
			return false;
		}

		$is_cart     = is_cart() || has_block( 'woocommerce/cart' );
		$is_checkout = is_checkout() || has_block( 'woocommerce/checkout' );

		return $is_cart && ! $is_checkout;
	}

	/**
	 * Tell whether WooPay-specific Blocks assets should be loaded.
	 *
	 * @return bool
	 */
	private function should_enqueue_woopay_assets(): bool {
		return $this->is_active() &&
			$this->is_cart_checkout_or_admin() &&
			$this->woopay_eligibility->should_show_woopay_button( $this->get_button_context() );
	}

	/**
	 * Tell whether the Apple Pay, Google Pay and Amazon Pay Blocks assets should be loaded.
	 *
	 * @return bool
	 */
	private function should_enqueue_express_checkout_assets(): bool {
		return $this->is_active() &&
			$this->is_cart_checkout_or_admin() &&
			$this->express_checkout_service->should_show_payment_request_button( $this->get_button_context() );
	}

	/**
	 * Get the WooPay and express checkout button context of the current Blocks page.
	 *
	 * A page holding the Cart block is a cart page even when it is not the store's cart page, as in client 11.1.0
	 * get_button_context() (class-wc-payments-express-checkout-button-helper.php:275-277, :450-468), so the cart's
	 * button locations apply there.
	 *
	 * @return string
	 */
	private function get_button_context(): string {
		return is_cart() || has_block( 'woocommerce/cart' ) ? 'cart' : 'checkout';
	}
}
