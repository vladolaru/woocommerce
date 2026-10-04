<?php
/**
 * Provides gateway redirect handling logic.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;

/**
 * GatewayRedirectService class. Redirects the wallet gateway's legacy settings section and the extension's individual
 * gateway settings sections to the wallet's route in the Payments settings app.
 */
class GatewayRedirectService {

	/**
	 * The Payments settings route that serves the wallet's settings app.
	 */
	private const ROUTE_PATH = '/paypal-wallet';

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
			GatewayIds::AXO,
			GatewayIds::GOOGLE_PAY,
			GatewayIds::APPLE_PAY,
			GatewayIds::CREDIT_CARD,
			GatewayIds::CARD_BUTTON,
			GatewayIds::BANCONTACT,
			GatewayIds::BLIK,
			GatewayIds::EPS,
			GatewayIds::IDEAL,
			GatewayIds::MYBANK,
			GatewayIds::P24,
			GatewayIds::TRUSTLY,
			GatewayIds::MULTIBANCO,
			GatewayIds::OXXO,
			GatewayIds::PAY_UPON_INVOICE,
			GatewayIds::PWC,
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

		$is_wallet_section = PayPalGateway::ID === $section;
		if ( ! $is_wallet_section && ! in_array( $section, $this->gateways, true ) ) {
			return;
		}

		// Keep what the old URL carried (the app's panel and highlight, and the arguments PayPal appends when it returns a merchant from onboarding).
		$carried = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( wp_unslash( $_GET ) as $name => $value ) {
			if ( is_string( $value ) && ! in_array( $name, array( 'page', 'tab', 'section', 'path' ), true ) ) {
				$carried[ $name ] = wc_clean( $value );
			}
		}

		if ( ! $is_wallet_section ) {
			$carried['panel']     = 'payment-methods';
			$carried['highlight'] = $section;
		}

		$redirect_url = 'admin.php?page=wc-settings&tab=checkout&path=' . self::ROUTE_PATH;
		if ( $carried ) {
			$redirect_url .= '&' . http_build_query( $carried, '', '&', PHP_QUERY_RFC3986 );
		}

		wp_safe_redirect( admin_url( $redirect_url ), 302 );
		exit;
	}
}
