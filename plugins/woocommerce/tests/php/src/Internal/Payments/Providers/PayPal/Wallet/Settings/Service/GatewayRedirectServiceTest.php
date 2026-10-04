<?php
/**
 * Tests for the redirect from the legacy gateway settings sections to the wallet's Payments settings route.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\GatewayRedirectService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use RuntimeException;

/**
 * The wallet's settings app is a route of the Payments settings app, so the old section URLs (the wallet's own section
 * and the extension's gateway sections, which merchants and the extension's links still use) send the browser there.
 *
 * Case kinds: all cases are "wallet" (they hold across the cut).
 *
 * @group paypal-wallet
 */
class GatewayRedirectServiceTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var GatewayRedirectService
	 */
	private $sut;

	/**
	 * Create the System Under Test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new GatewayRedirectService();
	}

	/**
	 * Run the redirect handler for the given query arguments on an admin request.
	 *
	 * @param array $query The query arguments of the request.
	 * @return array{location: string, status: int}|null The redirect, or null when the handler did not redirect.
	 */
	private function redirect_for( array $query ): ?array {
		$this->simulate_admin_request( $query );

		// wp_safe_redirect() is followed by exit: stop it by throwing from the filter that runs first.
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RuntimeException( $status . ' ' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			},
			10,
			2
		);

		try {
			$this->sut->handle_redirects();
		} catch ( RuntimeException $redirect ) {
			list( $status, $location ) = explode( ' ', $redirect->getMessage(), 2 );

			return array(
				'location' => $location,
				'status'   => (int) $status,
			);
		}

		return null;
	}

	/**
	 * @testdox Should send the wallet's legacy settings section to the Payments settings route with a 302.
	 */
	public function test_wallet_section_redirects_to_the_route(): void {
		$redirect = $this->redirect_for(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'ppcp-gateway',
			)
		);

		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' ), $redirect['location'] ?? null );
		$this->assertSame( 302, $redirect['status'] ?? null );
	}

	/**
	 * @testdox Should keep the panel and the highlight of the wallet's legacy settings section.
	 */
	public function test_wallet_section_keeps_panel_and_highlight(): void {
		$redirect = $this->redirect_for(
			array(
				'page'      => 'wc-settings',
				'tab'       => 'checkout',
				'section'   => 'ppcp-gateway',
				'panel'     => 'payment-methods',
				'highlight' => 'ppcp-applepay',
			)
		);

		$this->assertSame(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&panel=payment-methods&highlight=ppcp-applepay' ),
			$redirect['location'] ?? null
		);
	}

	/**
	 * @testdox Should keep the arguments PayPal appends when it returns a merchant from onboarding.
	 */
	public function test_wallet_section_keeps_the_onboarding_return_arguments(): void {
		$redirect = $this->redirect_for(
			array(
				'page'                => 'wc-settings',
				'tab'                 => 'checkout',
				'section'             => 'ppcp-gateway',
				'merchantIdInPayPal'  => 'MERCHANT123',
				'merchantId'          => 'abc 123',
				'ppcp-onboarding-ref' => 'a&b',
			)
		);

		$this->assertSame(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&merchantIdInPayPal=MERCHANT123&merchantId=abc%20123&ppcp-onboarding-ref=a%26b' ),
			$redirect['location'] ?? null
		);
	}

	/**
	 * @testdox Should send the old settings URL of the extension's gateway $section to the route, highlighting it.
	 * @testWith ["ppcp-axo-gateway"]
	 *           ["ppcp-applepay"]
	 *           ["ppcp-credit-card-gateway"]
	 *           ["ppcp-pwc"]
	 *
	 * @param string $section The gateway ID in the `section` argument.
	 */
	public function test_old_gateway_settings_urls_redirect( string $section ): void {
		$redirect = $this->redirect_for(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => $section,
			)
		);

		$this->assertSame(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&panel=payment-methods&highlight=' . $section ),
			$redirect['location'] ?? null
		);
		$this->assertSame( 302, $redirect['status'] ?? null );
	}

	/**
	 * @testdox Should let the gateway's own section decide the panel and the highlight of its deep link.
	 */
	public function test_gateway_section_overrides_a_panel_and_highlight_it_carries(): void {
		$redirect = $this->redirect_for(
			array(
				'page'      => 'wc-settings',
				'tab'       => 'checkout',
				'section'   => 'ppcp-applepay',
				'panel'     => 'settings',
				'highlight' => 'ppcp-ideal',
			)
		);

		$this->assertSame(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&panel=payment-methods&highlight=ppcp-applepay' ),
			$redirect['location'] ?? null
		);
	}

	/**
	 * @testdox Should drop an argument whose value is an array.
	 */
	public function test_array_valued_arguments_are_dropped(): void {
		$redirect = $this->redirect_for(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'ppcp-gateway',
				'panel'   => array( 'payment-methods' ),
				'keep'    => 'yes',
			)
		);

		$this->assertSame(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&keep=yes' ),
			$redirect['location'] ?? null
		);
	}

	/**
	 * @testdox Should not carry an incoming path or section over to the route URL.
	 */
	public function test_incoming_path_and_section_are_not_carried(): void {
		$redirect = $this->redirect_for(
			array(
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => 'ppcp-gateway',
				'path'    => '/offline',
			)
		);

		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' ), $redirect['location'] ?? null );
	}

	/**
	 * @testdox Should keep a hostile-looking value inside the query string, on the site's admin host.
	 */
	public function test_a_hostile_value_stays_inside_the_query_string(): void {
		$redirect = $this->redirect_for(
			array(
				'page'        => 'wc-settings',
				'tab'         => 'checkout',
				'section'     => 'ppcp-gateway',
				'redirect_to' => '//evil.test',
			)
		);

		$location = $redirect['location'] ?? '';

		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet&redirect_to=%2F%2Fevil.test' ), $location );
		$this->assertSame( wp_parse_url( admin_url(), PHP_URL_HOST ), wp_parse_url( $location, PHP_URL_HOST ) );
	}

	/**
	 * @testdox Should not redirect $description.
	 * @testWith ["a settings section that is not one of the extension's gateways", {"page": "wc-settings", "tab": "checkout", "section": "bacs"}]
	 *           ["the Payments settings route itself", {"page": "wc-settings", "tab": "checkout", "path": "/paypal-wallet"}]
	 *           ["the Payments settings list", {"page": "wc-settings", "tab": "checkout"}]
	 *           ["another settings tab", {"page": "wc-settings", "tab": "advanced", "section": "ppcp-gateway"}]
	 *           ["another admin page", {"page": "wc-status", "tab": "checkout", "section": "ppcp-gateway"}]
	 *
	 * @param string $description What the request is.
	 * @param array  $query       The query arguments of the request.
	 */
	public function test_other_requests_do_not_redirect( string $description, array $query ): void {
		$this->assertNull( $this->redirect_for( $query ), $description );
	}
}
