<?php
/**
 * Manages the SDK v6 assets for the My Account › Add Payment Method page.
 *
 * Renders the v6 "save for later" surface (the PayPal wallet save button) that
 * replaces the v5 add-payment-method.js. v6 owns this page fully when it loads:
 * the v5 script and smart button are suppressed elsewhere (see
 * SavePaymentMethodsModule + extensions.php), so this bootstrap also ships the
 * small rule that hides the native submit button while the PayPal method is
 * selected.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreateSetupToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\ClientTokenEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;

/**
 * Enqueues the SDK v6 assets of the Add Payment Method page.
 */
class AddPaymentMethodManager {

	public const WRAPPER_ID = 'ppc-button-ppcp-gateway-save-payment-method';

	/**
	 * The asset getter.
	 *
	 * @var AssetGetter
	 */
	private AssetGetter $asset_getter;
	/**
	 * The version.
	 *
	 * @var string
	 */
	private string $version;
	/**
	 * The environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;
	/**
	 * The context.
	 *
	 * @var Context
	 */
	private Context $context;
	/**
	 * Whether PayPal vaulting is enabled.
	 *
	 * @var bool
	 */
	private bool $paypal_vaulting_enabled;

	/**
	 * AddPaymentMethodManager constructor.
	 *
	 * @param AssetGetter $asset_getter            The asset getter.
	 * @param string      $version                 The version.
	 * @param Environment $environment             The environment.
	 * @param Context     $context                 The context.
	 * @param bool        $paypal_vaulting_enabled Whether PayPal vaulting is enabled.
	 */
	public function __construct(
		AssetGetter $asset_getter,
		string $version,
		Environment $environment,
		Context $context,
		bool $paypal_vaulting_enabled
	) {
		$this->asset_getter            = $asset_getter;
		$this->version                 = $version;
		$this->environment             = $environment;
		$this->context                 = $context;
		$this->paypal_vaulting_enabled = $paypal_vaulting_enabled;
	}

	/**
	 * Enqueues the add-payment-method bootstrap script.
	 */
	public function enqueue(): void {
		if ( ! $this->should_load_on_current_page() ) {
			return;
		}

		$script_url = $this->asset_getter->get_asset_url( 'boot-add-payment-method.js' );
		if ( ! $script_url ) {
			return;
		}

		$asset = $this->asset_getter->get_asset_data(
			'boot-add-payment-method.js',
			$this->version
		);

		wp_register_script(
			'wc-ppcp-sdk-v6-add-payment-method',
			$script_url,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'wc-ppcp-sdk-v6-add-payment-method',
			'wc_ppcp_sdk_v6_save',
			$this->script_data()
		);

		wp_enqueue_script( 'wc-ppcp-sdk-v6-add-payment-method' );

		// v5's smart-button stylesheet (which carries this rule) is suppressed
		// on this page, so the v6 boot's method-visibility toggling of
		// #place_order needs the rule shipped here.
		wp_register_style( 'wc-ppcp-sdk-v6-add-payment-method', false, array(), $this->version );
		wp_enqueue_style( 'wc-ppcp-sdk-v6-add-payment-method' );
		wp_add_inline_style(
			'wc-ppcp-sdk-v6-add-payment-method',
			'#place_order.ppcp-hidden{display:none !important;}'
		);
	}

	/**
	 * Whether the v6 save surfaces load on the current page.
	 */
	public function should_load_on_current_page(): bool {
		return is_user_logged_in()
			&& $this->paypal_vaulting_enabled
			&& $this->context->is_add_payment_method_page();
	}

	/**
	 * The configuration data for the add-payment-method bootstrap script.
	 */
	private function script_data(): array {
		$base_url = $this->environment->is_sandbox()
			? 'https://www.sandbox.paypal.com'
			: 'https://www.paypal.com';

		return array(
			'sdk_url'              => $base_url . '/web-sdk/v6/core',
			'currency'             => get_woocommerce_currency(),
			'locale'               => str_replace( '_', '-', get_locale() ),
			'payment_methods_page' => wc_get_account_endpoint_url( 'payment-methods' ),
			'button'               => array(
				'wrapper'     => '#' . self::WRAPPER_ID,
				'color_class' => 'paypal-gold',
			),
			'ajax'                 => array(
				'client_token'         => array(
					'endpoint' => \WC_AJAX::get_endpoint( ClientTokenEndpoint::ENDPOINT ),
					'nonce'    => wp_create_nonce( ClientTokenEndpoint::nonce() ),
				),
				'create_setup_token'   => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreateSetupToken::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreateSetupToken::nonce() ),
				),
				'create_payment_token' => array(
					'endpoint' => \WC_AJAX::get_endpoint( CreatePaymentToken::ENDPOINT ),
					'nonce'    => wp_create_nonce( CreatePaymentToken::nonce() ),
				),
			),
			'labels'               => array(
				'generic_error' => __(
					'Something went wrong. Please try again or choose another payment source.',
					'woocommerce'
				),
			),
		);
	}
}
