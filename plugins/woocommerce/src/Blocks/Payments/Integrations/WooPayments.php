<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

use Automattic\WooCommerce\Blocks\Assets\Api;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;

/**
 * WooPayments payment method integration.
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
	 * Blocks Apple Pay and Google Pay express payment method script handle.
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
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = OrderPaymentStore::GATEWAY_ID;

	/**
	 * Asset API.
	 *
	 * @var Api
	 */
	private Api $asset_api;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Checkout bridge.
	 *
	 * @var WooPaymentsCheckoutBridge
	 */
	private WooPaymentsCheckoutBridge $checkout_bridge;

	/**
	 * Native WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

	/**
	 * WooPay session service.
	 *
	 * @var WooPaymentsWooPaySessionService
	 */
	private WooPaymentsWooPaySessionService $woopay_session_service;

	/**
	 * Express checkout service.
	 *
	 * @var WooPaymentsExpressCheckoutService
	 */
	private WooPaymentsExpressCheckoutService $express_checkout_service;

	/**
	 * Native WooPayments gateway for this Blocks payment method instance.
	 *
	 * @var NativeWooPaymentsGateway|null
	 */
	private ?NativeWooPaymentsGateway $payment_gateway;

	/**
	 * Config base shared by the split-gateway integrations of one registration.
	 *
	 * @var \ArrayObject<string,mixed>|null
	 */
	private ?\ArrayObject $shared_config = null;

	/**
	 * Constructor.
	 *
	 * @param Api                               $asset_api                 Asset API.
	 * @param NativePaymentsRuntimeArbiter      $arbiter                   Runtime owner arbiter.
	 * @param WooPaymentsCheckoutBridge         $checkout_bridge           Checkout bridge.
	 * @param WooPaymentsProvider               $provider                  Native WooPayments provider.
	 * @param WooPaymentsWooPaySessionService   $woopay_session_service    WooPay session service.
	 * @param WooPaymentsExpressCheckoutService $express_checkout_service  Express checkout service.
	 * @param NativeWooPaymentsGateway|null     $payment_gateway           Optional payment gateway instance.
	 */
	public function __construct( Api $asset_api, NativePaymentsRuntimeArbiter $arbiter, WooPaymentsCheckoutBridge $checkout_bridge, WooPaymentsProvider $provider, WooPaymentsWooPaySessionService $woopay_session_service, WooPaymentsExpressCheckoutService $express_checkout_service, ?NativeWooPaymentsGateway $payment_gateway = null ) {
		$this->asset_api                = $asset_api;
		$this->arbiter                  = $arbiter;
		$this->checkout_bridge          = $checkout_bridge;
		$this->provider                 = $provider;
		$this->woopay_session_service   = $woopay_session_service;
		$this->express_checkout_service = $express_checkout_service;
		$this->payment_gateway          = $payment_gateway;
		$this->name                     = null === $payment_gateway ? OrderPaymentStore::GATEWAY_ID : $payment_gateway->id;
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize(): void {}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->arbiter->should_native_register() &&
			$this->provider->can_process_payments() &&
			$this->checkout_bridge->should_expose_checkout_surface() &&
			( null === $this->payment_gateway || $this->payment_gateway->is_available() );
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

		$handles = array();
		if ( ! $this->is_blocks_cart_only_surface() ) {
			WooPaymentsFrontendAssets::register_stripe_script();
			// The card script's asset file lists this handle: FingerprintJS is a build external.
			$this->checkout_bridge->register_fingerprint_script();

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
			WooPaymentsFrontendAssets::register_stripe_script();
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
		$data       = $this->checkout_bridge->get_blocks_payment_method_data( $this->get_card_gateway_supports(), $this->payment_gateway ? $this->payment_gateway->get_payment_method_definition() : null, $this->shared_config );
		$gateway_id = null === $this->payment_gateway ? OrderPaymentStore::GATEWAY_ID : $this->payment_gateway->id;
		$data       = array_merge(
			$data,
			array(
				'gatewayId' => $gateway_id,
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
	 * The card gateway exists even when card is not offered, as the client's does; with no gateways at all, nothing is supported.
	 *
	 * @return string[]
	 */
	private function get_card_gateway_supports(): array {
		if ( null !== $this->payment_gateway && OrderPaymentStore::GATEWAY_ID === $this->payment_gateway->id ) {
			return $this->payment_gateway->supports;
		}

		$card_gateway = $this->provider->get_gateway_for_method( 'card' );

		return null === $card_gateway ? array() : $card_gateway->supports;
	}

	/**
	 * Get the Blocks payment method instances for native WooPayments gateways.
	 *
	 * @return WooPayments[]
	 */
	public function get_payment_method_integrations(): array {
		if ( null !== $this->payment_gateway ) {
			return array( $this );
		}

		$gateways = $this->provider->get_payment_gateways();
		if ( empty( $gateways ) ) {
			return array( $this );
		}

		$shared_config = new \ArrayObject();

		return array_map(
			function ( NativeWooPaymentsGateway $gateway ) use ( $shared_config ): WooPayments {
				$integration                = new self(
					$this->asset_api,
					$this->arbiter,
					$this->checkout_bridge,
					$this->provider,
					$this->woopay_session_service,
					$this->express_checkout_service,
					$gateway
				);
				$integration->shared_config = $shared_config;
				return $integration;
			},
			$gateways
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
		return OrderPaymentStore::GATEWAY_ID === $this->name;
	}

	/**
	 * Enqueue a Blocks payment style only while rendering a Blocks cart/checkout surface.
	 *
	 * @param string $handle Style handle.
	 */
	private function maybe_enqueue_blocks_payment_style( string $handle ): void {
		if ( $this->is_blocks_cart_or_checkout_surface() ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Tell whether the current request renders the Blocks cart or checkout, or is an admin request.
	 *
	 * Admin requests count, because the block editor previews the cart and checkout with every payment method, so it
	 * loads the full Blocks payment stack.
	 *
	 * @return bool
	 */
	private function is_blocks_cart_or_checkout_surface(): bool {
		if ( is_admin() ) {
			return true;
		}

		return WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/cart' ) || WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' );
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

		$is_cart     = is_cart() || WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/cart' );
		$is_checkout = is_checkout() || WooPaymentsFrontendAssets::current_post_has_block( 'woocommerce/checkout' );

		return $is_cart && ! $is_checkout;
	}

	/**
	 * Tell whether WooPay-specific Blocks assets should be loaded.
	 *
	 * @return bool
	 */
	private function should_enqueue_woopay_assets(): bool {
		return $this->is_active() &&
			$this->is_blocks_cart_or_checkout_surface() &&
			$this->woopay_session_service->should_show_woopay_button( $this->get_button_context() );
	}

	/**
	 * Tell whether Apple Pay and Google Pay Blocks assets should be loaded.
	 *
	 * @return bool
	 */
	private function should_enqueue_express_checkout_assets(): bool {
		return $this->is_active() &&
			$this->is_blocks_cart_or_checkout_surface() &&
			$this->express_checkout_service->should_show_payment_request_button( $this->get_button_context() );
	}

	/**
	 * Get the WooPay and express checkout button context of the current Blocks page.
	 *
	 * @return string
	 */
	private function get_button_context(): string {
		return is_cart() ? 'cart' : 'checkout';
	}
}
