<?php
/**
 * Tests for the places that still recognise the extension's Fastlane gateway ID (core-only characterization: the extension
 * has no test for them).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The Fastlane gateway is not registered by the wallet, but its ID is shared state: the gateways list page recognises it
 * as one of ours, and the gateway list that decides which orders count as the extension's contains it. The IDs are
 * literals here, so the cases hold when the code reads them from `GatewayIds::AXO` (Fastlane cut).
 *
 * Case kinds: all cases are "wallet" (they hold across the cut).
 *
 * @group paypal-wallet
 */
class ForeignGatewayIdsTest extends WalletTestCase {

	/**
	 * The services of the given wallet module.
	 *
	 * @param string $module The module directory under Wallet/.
	 * @return array<string, callable>
	 */
	private function module_services( string $module ): array {
		return require WC_ABSPATH . "src/Internal/Payments/Providers/PayPal/Wallet/$module/services.php";
	}

	/**
	 * @testdox Should list the Fastlane gateway among the gateway IDs the gateways list page and the order checks recognise (wallet).
	 */
	public function test_the_gateway_id_lists_contain_the_fastlane_gateway(): void {
		$all_ids = $this->module_services( 'Settings' )['settings.config.all-gateway-ids']();
		$this->assertContains( 'ppcp-axo-gateway', $all_ids );
		$this->assertContains( 'ppcp-gateway', $all_ids );

		$ppcp_gateways = $this->module_services( 'WcGateway' )['wcgateway.ppcp-gateways']( $this->mock( ContainerInterface::class ) );
		$this->assertContains( 'ppcp-axo-gateway', $ppcp_gateways );
		$this->assertContains( 'ppcp-gateway', $ppcp_gateways );
	}

	/**
	 * @testdox Should list the Pay with Crypto gateway among the gateway IDs the gateways list page recognises (wallet).
	 */
	public function test_the_gateways_list_page_recognises_the_pay_with_crypto_gateway(): void {
		$all_ids = $this->module_services( 'Settings' )['settings.config.all-gateway-ids']();

		$this->assertContains( 'ppcp-pwc', $all_ids );
	}
}
