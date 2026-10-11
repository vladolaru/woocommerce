<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingSource;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOnboardingSource class.
 */
class WooPaymentsOnboardingSourceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_REFERER'], $_GET['source'], $_GET['from'] );

		parent::tearDown();
	}

	/**
	 * @testdox Should resolve the previous onboarding step like client 11.1.0 get_from().
	 * @dataProvider provider_client_get_from_cases
	 *
	 * @param string              $expected   Expected from value.
	 * @param string              $referer    Referer URL.
	 * @param array<string,mixed> $get_params GET params.
	 */
	public function test_get_from_matches_client( string $expected, string $referer, array $get_params ): void {
		$this->assertSame( $expected, WooPaymentsOnboardingSource::get_from( $referer, $get_params ) );
	}

	/**
	 * Client 11.1.0 `WC_Payments_Onboarding_Service_Test::data_get_from()` cases (tests/unit/test-class-wc-payments-onboarding-service.php:529-682), unchanged.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>}>
	 */
	public function provider_client_get_from_cases(): array {
		return array(
			'Unknown from'                                 => array(
				'',
				'',
				array(),
			),
			'Non-empty from GET param trumps everything'   => array(
				'WCADMIN_PAYMENT_INCENTIVE',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array(
					'source'                             => 'wcpay-connect-page',
					'wcpay-connect'                      => 'WCADMIN_PAYMENT_TASK',
					'wcpay-disable-onboarding-test-mode' => 'true',
					'from'                               => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
			'Empty from GET param is ignored'              => array(
				'WCADMIN_PAYMENT_TASK',
				'',
				array(
					'from'          => '',
					'wcpay-connect' => 'WCADMIN_PAYMENT_TASK',
				),
			),
			'Via test to live param'                       => array(
				'WCPAY_TEST_TO_LIVE',
				'any',
				array(
					'wcpay-connect'                      => '1',
					'wcpay-disable-onboarding-test-mode' => 'true',
				),
			),
			'test to live param takes precedence'          => array(
				'WCPAY_TEST_TO_LIVE',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array(
					'wcpay-connect'                      => 'WCADMIN_PAYMENT_TASK',
					'wcpay-disable-onboarding-test-mode' => 'true',
				),
			),
			'Via reset account param'                      => array(
				'WCPAY_RESET_ACCOUNT',
				'any',
				array(
					'wcpay-connect'       => '1',
					'wcpay-reset-account' => 'true',
				),
			),
			'reset account param takes precedence'         => array(
				'WCPAY_RESET_ACCOUNT',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array(
					'wcpay-connect'       => 'WCADMIN_PAYMENT_TASK',
					'wcpay-reset-account' => 'true',
				),
			),
			'Via the wcpay-connect value - takes precedence over referer' => array(
				'WCADMIN_PAYMENT_TASK',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array( 'wcpay-connect' => 'WCADMIN_PAYMENT_TASK' ),
			),
			'Via the wcpay-connect value - Payments task'  => array(
				'WCADMIN_PAYMENT_TASK',
				'any',
				array( 'wcpay-connect' => 'WCADMIN_PAYMENT_TASK' ),
			),
			'Via the wcpay-connect value - Payments Settings' => array(
				'WCADMIN_PAYMENT_SETTINGS',
				'any',
				array( 'wcpay-connect' => 'WCADMIN_PAYMENT_SETTINGS' ),
			),
			'Via the wcpay-connect value - Incentive page' => array(
				'WCADMIN_PAYMENT_INCENTIVE',
				'any',
				array( 'wcpay-connect' => 'WCADMIN_PAYMENT_INCENTIVE' ),
			),
			'Via the wcpay-connect value - Connect page'   => array(
				'WCPAY_CONNECT',
				'any',
				array( 'wcpay-connect' => 'WCPAY_CONNECT' ),
			),
			'Via the wcpay-connect value - Onboarding wizard' => array(
				'WCPAY_ONBOARDING_WIZARD',
				'any',
				array( 'wcpay-connect' => 'WCPAY_ONBOARDING_WIZARD' ),
			),
			'Via the wcpay-connect value - Test to live'   => array(
				'WCPAY_TEST_TO_LIVE',
				'any',
				array( 'wcpay-connect' => 'WCPAY_TEST_TO_LIVE' ),
			),
			'Via the wcpay-connect value - Reset account'  => array(
				'WCPAY_RESET_ACCOUNT',
				'any',
				array( 'wcpay-connect' => 'WCPAY_RESET_ACCOUNT' ),
			),
			'Via the wcpay-connect value - WPCOM'          => array(
				'WPCOM',
				'any',
				array( 'wcpay-connect' => 'WPCOM' ),
			),
			'Via the wcpay-connect value - Stripe'         => array(
				'STRIPE',
				'any',
				array( 'wcpay-connect' => 'STRIPE' ),
			),
			'Invalid wcpay-connect value is ignored'       => array(
				'',
				'any',
				array( 'wcpay-connect' => 'something' ),
			),
			'Via the referer URL - payments task'          => array(
				'WCADMIN_PAYMENT_TASK',
				'/wp-admin/admin.php?page=wc-admin&task=payments',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - settings page'          => array(
				'WCADMIN_PAYMENT_SETTINGS',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - NOX in-context'         => array(
				'WCADMIN_NOX_IN_CONTEXT',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - incentive page'         => array(
				'WCADMIN_PAYMENT_INCENTIVE',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fwc-pay-welcome-page',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - Connect page'           => array(
				'WCPAY_CONNECT',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - Onboarding wizard'      => array(
				'WCPAY_ONBOARDING_WIZARD',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fonboarding',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - WPCOM'                  => array(
				'WPCOM',
				'http://public-api.wordpress.com/something',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - Stripe'                 => array(
				'STRIPE',
				'http://something.stripe.com/something',
				array( 'wcpay-connect' => '1' ),
			),
		);
	}

	/**
	 * @testdox Should map the native Overview and Payouts referers to the client's from values.
	 */
	public function test_get_from_maps_native_referers(): void {
		$this->assertSame( WooPaymentsOnboardingSource::FROM_OVERVIEW_PAGE, WooPaymentsOnboardingSource::get_from( '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview', array() ) );
		$this->assertSame( WooPaymentsOnboardingSource::FROM_PAYOUTS, WooPaymentsOnboardingSource::get_from( '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/payouts', array() ) );
		$this->assertSame( WooPaymentsOnboardingSource::FROM_WCADMIN_PAYMENTS_SETTINGS, WooPaymentsOnboardingSource::get_from( '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings', array() ) );
	}

	/**
	 * @testdox Should resolve the onboarding source like client 11.1.0 get_source().
	 * @dataProvider provider_client_get_source_cases
	 *
	 * @param string              $expected   Expected source.
	 * @param string              $referer    Referer URL.
	 * @param array<string,mixed> $get_params GET params.
	 */
	public function test_get_source_matches_client( string $expected, string $referer, array $get_params ): void {
		$this->assertSame( $expected, WooPaymentsOnboardingSource::get_source( $referer, $get_params ) );
	}

	/**
	 * Client 11.1.0 `WC_Payments_Onboarding_Service_Test::data_get_source()` cases (tests/unit/test-class-wc-payments-onboarding-service.php:696-826).
	 *
	 * Expected values are the client's. Only the Overview and Payouts referers are adapted to native URLs; the rows
	 * prefixed "Legacy" keep the client URLs, whose branches are ported unchanged.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>}>
	 */
	public function provider_client_get_source_cases(): array {
		return array(
			'Valid source GET param trumps everything'     => array(
				'wcpay-connect-page',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array(
					'source'                             => 'wcpay-connect-page',
					'wcpay-connect'                      => 'WCADMIN_PAYMENT_TASK',
					'wcpay-disable-onboarding-test-mode' => 'true',
					'from'                               => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
			'Invalid source GET param is ignored'          => array(
				'wcadmin-payment-task',
				'',
				array(
					'source'        => 'bogus',
					'wcpay-connect' => 'WCADMIN_PAYMENT_TASK',
				),
			),
			'unknown source GET param is ignored'          => array(
				'wcadmin-payment-task',
				'',
				array(
					'source'        => 'unknown',
					'wcpay-connect' => 'WCADMIN_PAYMENT_TASK',
				),
			),
			'Unknown source'                               => array(
				'unknown',
				'',
				array(),
			),
			'Via the wcpay-connect value'                  => array(
				'wcadmin-payment-task',
				'any',
				array( 'wcpay-connect' => 'WCADMIN_PAYMENT_TASK' ),
			),
			'Via the referer URL - with valid source in it' => array(
				'wcpay-go-live-task',
				'/wp-admin/admin.php?page=wc-admin&task=payments&source=wcpay-go-live-task',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - with invalid source in it' => array(
				'wcadmin-payment-task',
				'/wp-admin/admin.php?page=wc-admin&task=payments&source=bogus',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - payments task'          => array(
				'wcadmin-payment-task',
				'/wp-admin/admin.php?page=wc-admin&task=payments',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - settings page'          => array(
				'wcadmin-settings-page',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - incentive page'         => array(
				'wcadmin-incentive-page',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fwc-pay-welcome-page',
				array( 'wcpay-connect' => '1' ),
			),
			'Legacy - Via the referer URL - Connect page'  => array(
				'wcpay-connect-page',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - Overview page'          => array(
				'wcpay-overview-page',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview',
				array( 'wcpay-connect' => '1' ),
			),
			'Legacy - Via the referer URL - Overview page' => array(
				'wcpay-overview-page',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview',
				array( 'wcpay-connect' => '1' ),
			),
			'Via the referer URL - Deposits/Payouts page'  => array(
				'wcpay-payouts-page',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts',
				array( 'wcpay-connect' => '1' ),
			),
			'Legacy - Via the referer URL - Deposits/Payouts page' => array(
				'wcpay-payouts-page',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fdeposits',
				array( 'wcpay-connect' => '1' ),
			),
			'Via test to live param'                       => array(
				'wcpay-setup-live-payments',
				'any',
				array(
					'wcpay-connect'                      => '1',
					'wcpay-disable-onboarding-test-mode' => 'true',
				),
			),
			'test to live param takes precedence'          => array(
				'wcpay-setup-live-payments',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array(
					'wcpay-connect'                      => 'WCADMIN_PAYMENT_TASK',
					'wcpay-disable-onboarding-test-mode' => 'true',
					'from'                               => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
			'Via reset account param'                      => array(
				'wcpay-reset-account',
				'any',
				array(
					'wcpay-connect'       => '1',
					'wcpay-reset-account' => 'true',
				),
			),
			'reset account param takes precedence'         => array(
				'wcpay-reset-account',
				'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fconnect',
				array(
					'wcpay-connect'       => 'WCADMIN_PAYMENT_TASK',
					'wcpay-reset-account' => 'true',
					'from'                => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
			'wcpay-connect value takes precedence over from and referer' => array(
				'wcadmin-payment-task',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array(
					'wcpay-connect' => 'WCADMIN_PAYMENT_TASK',
					'from'          => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
			'from value takes precedence over referer'     => array(
				'wcadmin-incentive-page',
				'/wp-admin/admin.php?page=wc-settings&tab=checkout',
				array(
					'wcpay-connect' => 'bogus',
					'from'          => 'WCADMIN_PAYMENT_INCENTIVE',
				),
			),
		);
	}

	/**
	 * @testdox Should resolve native WooPayments page referers the way the client resolves its matching pages.
	 * @dataProvider provider_native_referer_mappings
	 *
	 * @param string $expected Expected source, from the client's result for the matching client page.
	 * @param string $referer  Native referer URL.
	 */
	public function test_get_source_maps_native_referers( string $expected, string $referer ): void {
		$this->assertSame( $expected, WooPaymentsOnboardingSource::get_source( $referer, array() ) );
	}

	/**
	 * Native referers and the client page each one stands for.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_native_referer_mappings(): array {
		$base = '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=';

		return array(
			// Client: the `/woopayments/onboarding` branch of the wc-settings check.
			'NOX in-context onboarding'    => array( 'wcadmin-nox-in-context', $base . '%2Fwoopayments%2Fonboarding' ),
			// Client: `page=wc-settings&tab=checkout&section=woocommerce_payments`.
			'settings'                     => array( 'wcadmin-settings-page', $base . '%2Fwoopayments%2Fsettings' ),
			// Client: `page=wc-settings&tab=checkout&section=woocommerce_payments&method=payment_request`.
			'express checkout settings'    => array( 'wcadmin-settings-page', $base . '%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request' ),
			// Client: `page=wc-admin&path=/payments/fraud-protection`, which matches no branch.
			'fraud protection settings'    => array( 'unknown', $base . '%2Fwoopayments%2Fsettings%2Ffraud-protection' ),
			// Client: `page=wc-admin&path=/payments/transactions`, which matches no branch.
			'transactions'                 => array( 'unknown', $base . '%2Fwoopayments%2Ftransactions' ),
			// Client: `page=wc-admin&path=/payments/payouts/details`; the payouts branch matches the exact path only.
			'payout details'               => array( 'unknown', $base . '%2Fwoopayments%2Fpayouts%2Fdetails' ),
			// Client: an unrelated Settings > Payments path is still the settings page.
			'other payments settings path' => array( 'wcadmin-settings-page', $base . '%2Foffline%2Fbacs' ),
		);
	}

	/**
	 * @testdox Should read the referer and GET params from the request by default.
	 */
	public function test_get_source_reads_the_request_by_default(): void {
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts' );

		$this->assertSame( 'wcpay-payouts-page', WooPaymentsOnboardingSource::get_source() );

		$_GET['source'] = 'wcpay-go-live-task';

		$this->assertSame( 'wcpay-go-live-task', WooPaymentsOnboardingSource::get_source() );
	}

	/**
	 * @testdox Should return the unknown source when the request has no referer.
	 */
	public function test_get_source_is_unknown_without_a_referer(): void {
		unset( $_SERVER['HTTP_REFERER'] );

		$this->assertSame( 'unknown', WooPaymentsOnboardingSource::get_source() );
	}
}
