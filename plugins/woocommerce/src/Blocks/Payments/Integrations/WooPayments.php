<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

use Automattic\WooCommerce\Blocks\Assets\Api;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;

/**
 * WooPayments payment method integration: one Blocks payment method type per WooPayments gateway, which
 * WooPaymentsBlocksPaymentMethodsController registers.
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
	 * Asset API.
	 *
	 * @var Api
	 */
	private Api $asset_api;

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
	 * @param WooPaymentsProvider               $provider                 Native WooPayments provider.
	 * @param WooPaymentsCheckoutAssets         $checkout_assets          Checkout scripts and their config.
	 * @param Eligibility                       $woopay_eligibility       WooPay eligibility rules.
	 * @param WooPaymentsExpressCheckoutService $express_checkout_service Express checkout service.
	 * @param WooPaymentsFraudPreventionService $fraud_prevention_service Fraud-prevention token service.
	 * @param WooPaymentsFrontendAssets         $frontend_assets          Registrar of the shared shopper script handles.
	 */
	public function __construct( Api $asset_api, WooPaymentsGateway $payment_gateway, WooPaymentsProvider $provider, WooPaymentsCheckoutAssets $checkout_assets, Eligibility $woopay_eligibility, WooPaymentsExpressCheckoutService $express_checkout_service, WooPaymentsFraudPreventionService $fraud_prevention_service, WooPaymentsFrontendAssets $frontend_assets ) {
		$this->asset_api                = $asset_api;
		$this->payment_gateway          = $payment_gateway;
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
	 * The gateway's availability, as in client 11.2.0 (class-wc-payments-blocks-payment-method.php:45-47). It already
	 * requires the active tier, which only a store the built-in WooPayments owns reaches, and a provider that can take
	 * payments, whose account check is the checkout assets' one.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->payment_gateway->is_available();
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

			$handles[] = $this->register_payment_method_assets(
				self::PAYMENT_METHOD_SCRIPT_HANDLE,
				array_merge( array( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts() )
			);
		}

		if ( $this->is_card_gateway() && $this->should_enqueue_woopay_assets() ) {
			$handles[] = $this->register_payment_method_assets(
				self::WOOPAY_SCRIPT_HANDLE,
				array_merge( array( self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts() )
			);
		}

		if ( $this->is_card_gateway() && $this->should_enqueue_express_checkout_assets() ) {
			$this->frontend_assets->register_stripe_script();
			$handles[] = $this->register_payment_method_assets(
				self::EXPRESS_CHECKOUT_SCRIPT_HANDLE,
				array_merge( array( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, self::CHECKOUT_BLOCKS_SCRIPT_HANDLE ), $this->register_common_scripts() )
			);
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

		if ( $this->is_card_gateway() && $this->should_enqueue_express_checkout_assets() ) {
			$data['expressCheckoutParams'] = $this->express_checkout_service->get_express_checkout_params( $this->get_button_context() );
		}

		if ( $this->is_card_gateway() ) {
			$this->maybe_remove_woopay_protocol_dependency( $data );
		}

		return $data;
	}

	/**
	 * Take the WooPay protocol off the card script's dependencies when the request's first card data read turns the WooPay
	 * email check off, so a checkout without it loads no protocol entry.
	 *
	 * The card script calls the protocol only to wire that check, when both flags are truthy in the data Blocks publishes,
	 * which is the request's first read (Payments\Api::add_payment_method_script_data()). So that read decides, and later
	 * reads apply its decision whatever their flags. The WooPay scripts keep their own dependency on the protocol.
	 *
	 * @param array<string,mixed> $data The card's Blocks payment method data.
	 */
	private function maybe_remove_woopay_protocol_dependency( array $data ): void {
		$email_check_on = self::is_truthy_in_json( $data['isWooPayEnabled'] ?? null ) && self::is_truthy_in_json( $data['isWooPayEmailInputEnabled'] ?? null );
		if ( $this->checkout_assets->decide_blocks_card_woopay_protocol( $email_check_on ) ) {
			return;
		}

		$script = wp_scripts()->query( self::PAYMENT_METHOD_SCRIPT_HANDLE, 'registered' );
		if ( $script instanceof \_WP_Dependency ) {
			$script->deps = array_values( array_diff( $script->deps, array( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE ) ) );
		}
	}

	/**
	 * Tell whether a payment data value is truthy in the browser once it is printed as JSON: false, null, 0 and an
	 * empty string are falsy there, and every other value, the string '0' and an empty array included, is truthy.
	 *
	 * @param mixed $value Payment data value.
	 */
	private static function is_truthy_in_json( $value ): bool {
		return ! in_array( $value, array( null, false, 0, 0.0, '' ), true );
	}

	/**
	 * Get the card gateway's support features, which every WooPayments Blocks method shares, as in client 11.1.0.
	 *
	 * The card gateway exists even when card is not offered, as the client's does.
	 *
	 * @return string[]
	 */
	private function get_card_gateway_supports(): array {
		if ( $this->is_card_gateway() ) {
			return $this->payment_gateway->supports;
		}

		$card_gateway = $this->provider->get_gateway_for_method( 'card' );

		return null === $card_gateway ? array() : $card_gateway->supports;
	}

	/**
	 * Register the shared chunk a WooPayments Blocks script needs; each script waits for it before it runs.
	 *
	 * @return string[] The shared chunk handle to list as the script's dependency.
	 */
	private function register_common_scripts(): array {
		if ( ! wp_script_is( self::COMMON_SCRIPT_HANDLE, 'registered' ) ) {
			$this->asset_api->register_script( self::COMMON_SCRIPT_HANDLE, 'assets/client/blocks/wc-payment-method-woopayments-common.js', array(), false );
		}

		return array( self::COMMON_SCRIPT_HANDLE );
	}

	/**
	 * Register a WooPayments Blocks payment method script and its style, both named for the handle.
	 *
	 * The style is enqueued here only in admin, where the block editor previews the cart and checkout without rendering them;
	 * on the front end it is enqueued when the Cart or Checkout block renders (enqueue_styles_for_rendered_block()).
	 *
	 * @param string   $handle Script and style handle, also the file name of both under assets/client/blocks/.
	 * @param string[] $deps   Script dependencies.
	 * @return string The handle, to list as a payment method script.
	 */
	private function register_payment_method_assets( string $handle, array $deps ): string {
		// Each script's asset file lists these handles: the appearance utility and the WooPay protocol are build externals.
		$this->frontend_assets->register_appearance_script();
		$this->frontend_assets->register_woopay_common_script();
		$this->asset_api->register_script( $handle, 'assets/client/blocks/' . $handle . '.js', $deps );
		$this->asset_api->register_style( $handle, 'assets/client/blocks/' . $handle . '.css', array(), 'all', true );
		if ( is_admin() ) {
			wp_enqueue_style( $handle );
		}

		return $handle;
	}

	/**
	 * Tell whether this payment method stands for the WooPayments card gateway, which also carries WooPay and express checkout.
	 *
	 * @return bool
	 */
	private function is_card_gateway(): bool {
		return WooPaymentsPersistenceVocabulary::GATEWAY_ID === $this->payment_gateway->id;
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
