<?php
/**
 * Provides gateway redirect handling logic.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Applepay\ApplePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Googlepay\GooglePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BancontactGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BlikGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\EPSGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\IDealGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MultibancoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MyBankGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\P24Gateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\TrustlyGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\CardButtonGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\CreditCardGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;

/**
 * GatewayRedirectService class. Handles redirects from individual gateway
 * settings URLs to the new Settings UI page.
 */
class GatewayRedirectService {

	/**
	 * List of gateways to redirect.
	 *
	 * @var string[]
	 */
	private array $gateways;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->gateways = array(
			AxoGateway::ID,
			GooglePayGateway::ID,
			ApplePayGateway::ID,
			CreditCardGateway::ID,
			CardButtonGateway::ID,
			BancontactGateway::ID,
			BlikGateway::ID,
			EPSGateway::ID,
			IDealGateway::ID,
			MyBankGateway::ID,
			P24Gateway::ID,
			TrustlyGateway::ID,
			MultibancoGateway::ID,
			OXXOGateway::ID,
			PayUponInvoiceGateway::ID,
			PWCGateway::ID,
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'admin_init',
			array( $this, 'handle_redirects' )
		);
	}

	/**
	 * Handle redirects for gateway settings pages.
	 *
	 * @return void
	 */
	public function handle_redirects(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Get current URL parameters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['page'] ) ? wc_clean( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? wc_clean( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? wc_clean( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Check if we're on a WooCommerce settings page and checkout tab.
		if ( $page !== 'wc-settings' || $tab !== 'checkout' ) {
			return;
		}

		// Check if we're on one of the gateway settings pages we want to redirect.
		if ( in_array( $section, $this->gateways, true ) ) {
			$redirect_url = admin_url(
				sprintf(
					'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway&panel=payment-methods&highlight=%s',
					$section
				)
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}
