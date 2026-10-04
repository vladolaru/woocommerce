<?php
/**
 * Tests for the wallet's detection of its settings page.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The wallet's settings app is served on a route of the Payments settings app, and the legacy section URL still counts
 * as its settings page until the redirect has run.
 *
 * Case kinds: all cases are "wallet" (they hold across the cut).
 *
 * @group paypal-wallet
 */
class SettingsPageDetectionTest extends WalletTestCase {

	/**
	 * @testdox Should report $description as the wallet's settings page: $expected.
	 * @testWith ["the Payments settings route", {"page": "wc-settings", "tab": "checkout", "path": "/paypal-wallet"}, true]
	 *           ["a panel of the route", {"page": "wc-settings", "tab": "checkout", "path": "/paypal-wallet/payment-methods"}, true]
	 *           ["the legacy gateway section", {"page": "wc-settings", "tab": "checkout", "section": "ppcp-gateway"}, true]
	 *           ["the Payments settings list", {"page": "wc-settings", "tab": "checkout"}, false]
	 *           ["another route of the Payments settings app", {"page": "wc-settings", "tab": "checkout", "path": "/offline"}, false]
	 *           ["the route path on another admin page", {"page": "wc-status", "path": "/paypal-wallet"}, false]
	 *
	 * @param string $description What the request is.
	 * @param array  $query       The query arguments of the request.
	 * @param bool   $expected    Whether the request is the wallet's settings page.
	 */
	public function test_detects_the_settings_page( string $description, array $query, bool $expected ): void {
		$this->simulate_admin_request( $query );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/WcGateway/services.php';

		$this->assertSame( $expected, $services['wcgateway.is-plugin-settings-page'](), $description );
	}
}
