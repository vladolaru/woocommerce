<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin\Features\OnboardingTasks\Tasks;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\Payments as SettingsPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Payments onboarding task test.
 *
 * Covers how the task treats the WooPayments plugin and the built-in gateway.
 */
class PaymentsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var Payments
	 */
	private $sut;

	/**
	 * The gateways registered before the test, restored on tear down.
	 *
	 * @var array
	 */
	private $gateways_before_test;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->gateways_before_test = WC()->payment_gateways()->payment_gateways;
		$this->sut                  = new Payments();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_admin_woopayments_onboarding_task_badge' );
		remove_all_filters( 'woocommerce_admin_woopayments_onboarding_task_additional_data' );
		WC()->payment_gateways()->payment_gateways = $this->gateways_before_test;
		wc_get_container()->reset_all_replacements();
		TaskLists::clear_lists();
		TaskLists::init_default_lists();
		parent::tearDown();
	}

	/**
	 * @testdox Should fire neither retired WooPayments task filter with the WooPayments feature $feature.
	 *
	 * @testWith ["off"]
	 *           ["on"]
	 *
	 * @param string $feature The WooPayments feature state.
	 */
	public function test_fires_no_retired_woopayments_task_filter( string $feature ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'on' === $feature ? 'yes' : 'no' );
		// Incidental: no assertion reads this answer, it only keeps the incentives request offline. The empty list is decoded
		// and kept as "no incentive" (src/Internal/Admin/Suggestions/Incentives/WooPayments.php:246-249).
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				unset( $parsed_args );

				if ( str_contains( $url, 'https://public-api.wordpress.com/wpcom/v2/wcpay/incentives' ) ) {
					return array(
						'success'  => true,
						'body'     => wp_json_encode( array() ),
						'response' => array(
							'code' => 200,
						),
					);
				}

				return $preempt;
			},
			10,
			3
		);
		$fired = array();
		foreach ( array( 'woocommerce_admin_woopayments_onboarding_task_badge', 'woocommerce_admin_woopayments_onboarding_task_additional_data' ) as $hook ) {
			add_filter(
				$hook,
				function ( $value ) use ( $hook, &$fired ) {
					$fired[] = $hook;
					return $value;
				}
			);
		}

		$this->sut->get_badge();
		$this->sut->get_additional_data();

		$this->assertSame( array(), $fired, 'Only the deprecated WooPayments task fires these filters.' );
	}

	/**
	 * @testdox Should list the Payments task and no WooPayments task with the WooPayments feature $feature.
	 *
	 * @testWith ["off"]
	 *           ["on"]
	 *
	 * @param string $feature The WooPayments feature state.
	 */
	public function test_task_lists_offer_payments_and_no_woopayments_task( string $feature ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'on' === $feature ? 'yes' : 'no' );
		TaskLists::clear_lists();
		TaskLists::init_default_lists();

		$this->assertNotNull( TaskLists::get_task( 'payments', 'setup' ), 'The setup list offers the Payments task.' );
		$this->assertNull( TaskLists::get_task( 'woocommerce-payments' ), 'No list offers the deprecated WooPayments task.' );
	}

	/**
	 * @testdox Should return the task's additional data.
	 */
	public function test_get_additional_data_returns_its_array_without_filter(): void {
		$result = $this->sut->get_additional_data();

		$this->assertIsArray( $result, 'Additional data should be an array.' );
		$this->assertArrayHasKey( 'wooPaymentsIsActive', $result, 'Native keys must be present.' );
		$this->assertArrayHasKey( 'wooPaymentsIsInstalled', $result, 'Native keys must be present.' );
	}

	/**
	 * @testdox Should keep the task open for a built-in test-drive account.
	 */
	public function test_builtin_test_drive_account_keeps_task_open(): void {
		$this->arrange( false, 'yes', $this->woopayments_provider( 'woocommerce', true, true, true, true ) );

		$data = $this->sut->get_additional_data();

		$this->assertFalse( $this->sut->is_complete(), 'A test-drive account must not complete the task even with an enabled gateway.' );
		$this->assertTrue( $data['wooPaymentsIsActive'], 'The built-in gateway with a connected account counts as active WooPayments.' );
		$this->assertTrue( $data['wooPaymentsIsInstalled'], 'Active WooPayments is also installed.' );
		$this->assertTrue( $data['wooPaymentsIsOnboarded'], 'The test-drive account is onboarded.' );
		$this->assertTrue( $data['wooPaymentsHasTestAccount'], 'The test-drive account must be reported.' );
		$this->assertTrue( $this->sut->is_in_progress(), 'A test-drive account keeps the task in progress.' );
		$this->assertSame( 'Test account', $this->sut->in_progress_label(), 'A test-drive account shows the test account label.' );
	}

	/**
	 * @testdox Should complete the task for a built-in live account that finished onboarding.
	 */
	public function test_builtin_live_onboarded_account_completes_task(): void {
		$this->arrange( false, 'yes', $this->woopayments_provider( 'woocommerce', true, true, true, false ) );

		$data = $this->sut->get_additional_data();

		$this->assertTrue( $this->sut->is_complete(), 'An onboarded live account completes the task.' );
		$this->assertTrue( $data['wooPaymentsIsActive'], 'The built-in gateway with a connected account counts as active WooPayments.' );
		$this->assertTrue( $data['wooPaymentsIsOnboarded'], 'The live account is onboarded.' );
		$this->assertFalse( $data['wooPaymentsHasTestAccount'], 'A live account is not a test account.' );
	}

	/**
	 * @testdox Should complete the task for a built-in live account even when no gateway is enabled.
	 */
	public function test_builtin_live_onboarded_account_completes_task_without_enabled_gateways(): void {
		$this->arrange( false, 'no', $this->woopayments_provider( 'woocommerce', true, true, true, false ) );

		$this->assertTrue( $this->sut->is_complete(), 'Completion follows onboarding, not enabled gateways, once WooPayments is active.' );
	}

	/**
	 * @testdox Should show the action needed label while built-in live onboarding is started but not finished.
	 */
	public function test_builtin_started_live_onboarding_shows_action_needed(): void {
		$this->arrange( false, 'yes', $this->woopayments_provider( 'woocommerce', false, true, false, false ) );

		$data = $this->sut->get_additional_data();

		$this->assertFalse( $this->sut->is_complete(), 'Unfinished WooPayments onboarding keeps the task open even with an enabled gateway.' );
		$this->assertTrue( $data['wooPaymentsIsActive'], 'Started onboarding on the built-in gateway counts as active WooPayments.' );
		$this->assertFalse( $data['wooPaymentsIsOnboarded'], 'The account is not onboarded yet.' );
		$this->assertTrue( $this->sut->is_in_progress(), 'Started live onboarding keeps the task in progress.' );
		$this->assertSame( 'Action needed', $this->sut->in_progress_label(), 'Started live onboarding shows the action needed label.' );
	}

	/**
	 * @testdox Should treat a built-in store that never started WooPayments like a store without WooPayments.
	 */
	public function test_builtin_never_started_follows_enabled_gateways(): void {
		$this->arrange( false, 'yes', $this->woopayments_provider( 'woocommerce', false, false, false, false ) );

		$data = $this->sut->get_additional_data();

		$this->assertTrue( $this->sut->is_complete(), 'Without WooPayments engagement, any enabled gateway completes the task.' );
		$this->assertFalse( $data['wooPaymentsIsActive'], 'The built-in gateway alone does not count as active WooPayments.' );
		$this->assertFalse( $data['wooPaymentsIsOnboarded'], 'Inactive WooPayments is never reported as onboarded.' );
		$this->assertFalse( $data['wooPaymentsHasTestAccount'], 'Inactive WooPayments never reports a test account.' );
	}

	/**
	 * @testdox Should not treat a WooPayments entry from another plugin as the built-in gateway.
	 */
	public function test_non_builtin_woopayments_entry_is_not_active_without_the_woopayments_extension_runtime(): void {
		$this->arrange( false, 'yes', $this->woopayments_provider( 'woocommerce-payments', true, true, true, true ) );

		$data = $this->sut->get_additional_data();

		$this->assertFalse( $data['wooPaymentsIsActive'], 'Only the built-in gateway or the loaded plugin runtime counts as active.' );
		$this->assertTrue( $this->sut->is_complete(), 'An inactive WooPayments leaves completion to the enabled gateways.' );
	}

	/**
	 * @testdox Should not build the providers list for completion when the WooPayments gateway is not registered.
	 */
	public function test_is_complete_skips_providers_list_without_woopayments_gateway(): void {
		WC()->payment_gateways()->payment_gateways = array( $this->gateway( 'bacs', 'yes' ) );

		$settings = $this->createMock( SettingsPaymentsService::class );
		$settings->expects( $this->never() )->method( 'get_payment_providers' );
		wc_get_container()->replace( SettingsPaymentsService::class, $settings );
		wc_get_container()->replace( WooPaymentsExtensionRuntime::class, $this->extension_runtime( false ) );

		$this->assertTrue( $this->sut->is_complete(), 'Completion falls back to the enabled gateways.' );
	}

	/**
	 * @testdox Should keep the task open for a test-drive account when the WooPayments extension runtime is loaded.
	 */
	public function test_extension_runtime_test_drive_account_keeps_task_open(): void {
		$this->arrange( true, 'yes', $this->woopayments_provider( 'woocommerce-payments', true, true, true, true ) );

		$data = $this->sut->get_additional_data();

		$this->assertFalse( $this->sut->is_complete(), 'A test-drive account must not complete the task.' );
		$this->assertTrue( $data['wooPaymentsIsActive'], 'The loaded plugin runtime counts as active WooPayments.' );
		$this->assertTrue( $data['wooPaymentsHasTestAccount'], 'The test-drive account must be reported.' );
	}

	/**
	 * @testdox Should count WooPayments as active whenever the WooPayments extension runtime is loaded, even before onboarding.
	 */
	public function test_extension_runtime_is_active_before_onboarding(): void {
		$this->arrange( true, 'yes', $this->woopayments_provider( 'woocommerce-payments', false, false, false, false ) );

		$data = $this->sut->get_additional_data();

		$this->assertTrue( $data['wooPaymentsIsActive'], 'The loaded plugin runtime counts as active WooPayments without engagement.' );
		$this->assertFalse( $this->sut->is_complete(), 'Unfinished WooPayments onboarding keeps the task open.' );
	}

	/**
	 * Register the WooPayments gateway and replace the services the task reads.
	 *
	 * @param bool   $extension_runtime_loaded Whether the WooPayments extension runtime is loaded.
	 * @param string $gateway_enabled          The WooPayments gateway enabled setting ('yes' or 'no').
	 * @param array  $woopayments              The WooPayments entry in the providers list.
	 */
	private function arrange( bool $extension_runtime_loaded, string $gateway_enabled, array $woopayments ): void {
		WC()->payment_gateways()->payment_gateways = array( $this->gateway( 'woocommerce_payments', $gateway_enabled ) );

		$woopayments['state']['enabled'] = 'yes' === $gateway_enabled;

		$settings = $this->createMock( SettingsPaymentsService::class );
		$settings->method( 'get_country' )->willReturn( 'US' );
		$settings->method( 'get_payment_providers' )->willReturn( array( $woopayments ) );
		$settings->method( 'get_payment_extension_suggestions' )->willReturn( array() );

		wc_get_container()->replace( SettingsPaymentsService::class, $settings );
		wc_get_container()->replace( WooPaymentsExtensionRuntime::class, $this->extension_runtime( $extension_runtime_loaded ) );
	}

	/**
	 * Build a WooPayments entry shaped like the Payments settings providers list.
	 *
	 * @param string $plugin_slug        The slug of the plugin that registers the gateway.
	 * @param bool   $account_connected  Whether an account is connected.
	 * @param bool   $started            Whether onboarding started.
	 * @param bool   $completed          Whether onboarding completed.
	 * @param bool   $test_drive_account Whether the account is a test-drive account.
	 *
	 * @return array
	 */
	private function woopayments_provider( string $plugin_slug, bool $account_connected, bool $started, bool $completed, bool $test_drive_account ): array {
		return array(
			'id'         => 'woocommerce_payments',
			'_type'      => PaymentsProviders::TYPE_GATEWAY,
			'state'      => array(
				'enabled'           => false,
				'account_connected' => $account_connected,
				'needs_setup'       => ! $completed,
			),
			'onboarding' => array(
				'state' => array(
					'started'            => $started,
					'completed'          => $completed,
					'test_mode'          => $test_drive_account,
					'test_drive_account' => $test_drive_account,
				),
			),
			'plugin'     => array(
				'_type'  => PaymentsProviders::EXTENSION_TYPE_WPORG,
				'slug'   => $plugin_slug,
				'status' => PaymentsProviders::EXTENSION_ACTIVE,
			),
		);
	}

	/**
	 * Build an extension runtime double.
	 *
	 * @param bool $loaded Whether the WooPayments plugin runtime is loaded.
	 *
	 * @return WooPaymentsExtensionRuntime|MockObject
	 */
	private function extension_runtime( bool $loaded ) {
		$runtime = $this->createMock( WooPaymentsExtensionRuntime::class );
		$runtime->method( 'is_loaded' )->willReturn( $loaded );
		$runtime->method( 'get_supported_countries' )->willReturn( null );

		return $runtime;
	}

	/**
	 * Build a bare payment gateway.
	 *
	 * @param string $id      The gateway ID.
	 * @param string $enabled The gateway enabled setting ('yes' or 'no').
	 *
	 * @return WC_Payment_Gateway
	 */
	private function gateway( string $id, string $enabled ): WC_Payment_Gateway {
		$gateway          = new class() extends WC_Payment_Gateway {};
		$gateway->id      = $id;
		$gateway->enabled = $enabled;

		return $gateway;
	}
}
