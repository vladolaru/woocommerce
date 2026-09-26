<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin\Features\OnboardingTasks\Tasks;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks\Payments;
use WC_Unit_Test_Case;

/**
 * Payments onboarding task test.
 *
 * Focuses on the backward-compatibility filters re-fired for the standalone
 * WooPayments onboarding task that was removed when WooPayments merged into core.
 */
class PaymentsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var Payments
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new Payments();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_admin_woopayments_onboarding_task_badge' );
		remove_all_filters( 'woocommerce_admin_woopayments_onboarding_task_additional_data' );
		parent::tearDown();
	}

	/**
	 * @testdox Should re-fire the deprecated WooPayments badge filter so existing callbacks still run.
	 */
	public function test_get_badge_fires_deprecated_woopayments_filter(): void {
		$this->setExpectedDeprecated( 'woocommerce_admin_woopayments_onboarding_task_badge' );

		add_filter(
			'woocommerce_admin_woopayments_onboarding_task_badge',
			function () {
				return 'NEW';
			}
		);

		$this->assertSame(
			'NEW',
			$this->sut->get_badge(),
			'The badge filter contribution should be returned by get_badge().'
		);
	}

	/**
	 * @testdox Should return an empty badge and emit no deprecation notice when nothing hooks the badge filter.
	 */
	public function test_get_badge_returns_empty_string_without_filter(): void {
		$this->assertSame(
			'',
			$this->sut->get_badge(),
			'The badge should default to an empty string when no callback is attached.'
		);
	}

	/**
	 * @testdox Should fold the deprecated additional-data filter into the native array while keeping native keys.
	 */
	public function test_get_additional_data_folds_in_deprecated_filter(): void {
		$this->setExpectedDeprecated( 'woocommerce_admin_woopayments_onboarding_task_additional_data' );

		add_filter(
			'woocommerce_admin_woopayments_onboarding_task_additional_data',
			function () {
				return array( 'extIncentiveId' => 'abc' );
			}
		);

		$result = $this->sut->get_additional_data();

		$this->assertIsArray( $result, 'Additional data should be an array.' );
		$this->assertArrayHasKey( 'extIncentiveId', $result, 'The deprecated filter contribution should be present.' );
		$this->assertSame( 'abc', $result['extIncentiveId'], 'The deprecated filter value should be preserved.' );
		$this->assertArrayHasKey( 'wooPaymentsIsActive', $result, 'Native keys must still be present.' );
	}

	/**
	 * @testdox Should not let the deprecated additional-data filter override native keys.
	 */
	public function test_get_additional_data_native_keys_win_on_collision(): void {
		$this->setExpectedDeprecated( 'woocommerce_admin_woopayments_onboarding_task_additional_data' );

		add_filter(
			'woocommerce_admin_woopayments_onboarding_task_additional_data',
			function () {
				return array( 'wooPaymentsIsActive' => 'HIJACKED' );
			}
		);

		$result = $this->sut->get_additional_data();

		$this->assertArrayHasKey( 'wooPaymentsIsActive', $result, 'Native key must be present.' );
		$this->assertNotSame(
			'HIJACKED',
			$result['wooPaymentsIsActive'],
			'The deprecated filter must not be able to override native flags.'
		);
		$this->assertIsBool(
			$result['wooPaymentsIsActive'],
			'The native wooPaymentsIsActive value should remain a boolean.'
		);
	}

	/**
	 * @testdox Should return the native additional data unchanged when nothing hooks the filter.
	 */
	public function test_get_additional_data_returns_native_array_without_filter(): void {
		$result = $this->sut->get_additional_data();

		$this->assertIsArray( $result, 'Additional data should be an array.' );
		$this->assertArrayHasKey( 'wooPaymentsIsActive', $result, 'Native keys must be present.' );
		$this->assertArrayHasKey( 'wooPaymentsIsInstalled', $result, 'Native keys must be present.' );
	}
}
