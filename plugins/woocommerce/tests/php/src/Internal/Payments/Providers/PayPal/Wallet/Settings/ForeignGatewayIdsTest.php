<?php
/**
 * Tests for the places that still recognise the extension's Fastlane gateway ID (core-only characterization: the extension
 * has no test for them).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\GatewayRedirectService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The Fastlane gateway is not registered by the wallet, but its ID is shared state: the old settings URL of the gateway
 * redirects to the settings app, the gateways list page recognises it as one of ours, and the gateway list that decides
 * which orders count as the extension's contains it. The IDs are literals here, so the cases hold when the code reads
 * them from `GatewayIds::AXO` (Fastlane cut).
 *
 * Case kinds: all cases are "wallet" (they hold across the cut).
 *
 * @group paypal-wallet
 */
class ForeignGatewayIdsTest extends WalletTestCase {

	/**
	 * The screen before the test.
	 *
	 * @var mixed
	 */
	private $original_screen;

	/**
	 * The query arguments before the test.
	 *
	 * @var array
	 */
	private $original_get;

	/**
	 * Remember the screen and the query arguments the test replaces.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_screen = $GLOBALS['current_screen'] ?? null;
		$this->original_get    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Put the screen and the query arguments back.
	 */
	public function tearDown(): void {
		try {
			if ( null === $this->original_screen ) {
				unset( $GLOBALS['current_screen'] );
			} else {
				set_current_screen( $this->original_screen );
			}
			$_GET = $this->original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} finally {
			parent::tearDown();
		}
	}

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
	 * Run the redirect handler on the WooCommerce checkout settings tab, with the given section.
	 *
	 * @param string $section The `section` query argument.
	 * @return string The redirect location, or an empty string when the handler did not redirect.
	 */
	private function redirect_location_for( string $section ): string {
		set_current_screen( 'dashboard' );
		$_GET = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => $section,
		);

		// wp_safe_redirect() is followed by exit: stop it by throwing from the filter that runs first.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);

		try {
			( new GatewayRedirectService() )->handle_redirects();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * @testdox Should send the old settings URL of the extension's gateway $section to the settings app, highlighting it (wallet).
	 * @testWith ["ppcp-axo-gateway"]
	 *           ["ppcp-applepay"]
	 *           ["ppcp-credit-card-gateway"]
	 *           ["ppcp-pwc"]
	 *
	 * @param string $section The gateway ID in the `section` argument.
	 */
	public function test_old_gateway_settings_urls_redirect( string $section ): void {
		$location = $this->redirect_location_for( $section );

		$this->assertStringContainsString( 'section=ppcp-gateway&panel=payment-methods&highlight=' . $section, $location );
	}

	/**
	 * @testdox Should not redirect a settings section that is not one of the extension's gateways (wallet).
	 */
	public function test_other_sections_do_not_redirect(): void {
		$this->assertSame( '', $this->redirect_location_for( 'bacs' ) );
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
