<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments settings controller.
 */
class WooPaymentsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $_GET['woopayments-ref'] );
		delete_transient( 'woopayments_referral_code' );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	/**
	 * @testdox A `woopayments-ref` link from a store manager stores the sanitized code and redirects to the plugin's onboarding URL.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` (the `woopayments-ref` argument and
	 * `sanitize_text_field`) continuing with `WC_Payments_Redirect_Service::redirect_to_nox_flow( 'REFERRAL' )`.
	 */
	public function test_referral_link_redirects_store_managers_to_onboarding_with_the_referral_source(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['woopayments-ref'] = ' partner-<b>abc</b> ';
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
			}
		);
		// The URL `redirect_to_nox_flow( 'REFERRAL' )` builds in plugin 11.1.0.
		$expected = admin_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/onboarding',
					'from' => 'REFERRAL',
				),
				'admin.php'
			)
		);

		try {
			$this->create_controller( $this->create_native_onboarding_service() )->handle_referral_link();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: ' . $expected, $exception->getMessage() );
		}

		$this->assertSame( 'partner-abc', get_transient( 'woopayments_referral_code' ) );
	}

	/**
	 * @testdox A `woopayments-ref` link is ignored for users who cannot manage WooCommerce.
	 */
	public function test_referral_link_is_ignored_without_the_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$_GET['woopayments-ref'] = 'partner-abc';
		$service                 = $this->createMock( WooPaymentsService::class );
		$service->expects( $this->never() )->method( 'handle_onboarding_referral' );

		$this->create_controller( $service )->handle_referral_link();
	}

	/**
	 * Create a real WooPayments service for a store without an account, while native owns onboarding.
	 *
	 * @return WooPaymentsService
	 */
	private function create_native_onboarding_service(): WooPaymentsService {
		$legacy_runtime = $this->createMock( WooPaymentsLegacyRuntime::class );
		$legacy_runtime->method( 'is_loaded' )->willReturn( false );
		$onboarding_adapter = $this->createMock( WooPaymentsOnboardingAdapter::class );
		$onboarding_adapter->method( 'has_valid_account' )->willReturn( false );

		$service = new WooPaymentsService();
		$service->init(
			$this->createMock( PaymentsProviders::class ),
			$this->createMock( LegacyProxy::class ),
			$onboarding_adapter,
			$legacy_runtime,
			$this->createMock( WooPaymentsApiClient::class ),
			$this->createMock( WooPaymentsAccountService::class )
		);

		return $service;
	}

	/**
	 * Create the controller with a service double.
	 *
	 * @param WooPaymentsService $service WooPayments service double.
	 * @return WooPaymentsController
	 */
	private function create_controller( WooPaymentsService $service ): WooPaymentsController {
		$controller = new WooPaymentsController();
		$controller->init( $this->createMock( Payments::class ), $service );

		return $controller;
	}
}
