<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
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
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	/**
	 * @testdox A `woopayments-ref` link from a store manager hands the sanitized code to the service and redirects to its URL.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` (the `woopayments-ref` argument and `sanitize_text_field`).
	 */
	public function test_referral_link_redirects_store_managers_to_the_service_url(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['woopayments-ref'] = ' partner-<b>abc</b> ';
		$service                 = $this->createMock( WooPaymentsService::class );
		$service->expects( $this->once() )
			->method( 'handle_onboarding_referral' )
			->with( 'partner-abc' )
			->willReturn( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding&from=REFERRAL' ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
			}
		);

		try {
			$this->create_controller( $service )->handle_referral_link();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'path=/woopayments/onboarding&from=REFERRAL', $exception->getMessage() );
		}
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
