<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Unit_Test_Case;

/**
 * Tests for the NativeWooPaymentsGateway class.
 */
class NativeWooPaymentsGatewayTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_default_country' );
		$this->reset_container_replacements();
		wc_get_container()->reset_all_resolved();

		parent::tearDown();
	}

	/**
	 * @testdox Should use the store base-location option when the account country is uncached.
	 */
	public function test_account_country_falls_back_to_base_location_option_when_uncached(): void {
		update_option( 'woocommerce_default_country', 'DE:BE' );
		$base_country_filter = static function (): string {
			return 'FR';
		};
		$account_service     = $this->createMock( WooPaymentsAccountService::class );
		$account_service->method( 'get_cached_account_data' )->willReturn( array() );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();
		add_filter( 'woocommerce_countries_base_country', $base_country_filter );

		try {
			$gateway = new NativeWooPaymentsGateway();
			$method  = new \ReflectionMethod( NativeWooPaymentsGateway::class, 'get_account_country' );
			$method->setAccessible( true );

			$this->assertSame( 'DE', $method->invoke( $gateway ), 'An uncached account must use the unfiltered store base country.' );
		} finally {
			remove_filter( 'woocommerce_countries_base_country', $base_country_filter );
		}
	}

	/**
	 * @testdox Should not resolve account data while constructing the gateway.
	 */
	public function test_construction_does_not_touch_wc_instance_when_country_uncached(): void {
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->never() )->method( 'get_cached_account_data' );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();

		new NativeWooPaymentsGateway();
	}

	/**
	 * @testdox Should lazily memoize account-country branding when the title is requested.
	 */
	public function test_get_title_lazily_memoizes_account_country_branding(): void {
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->once() )
			->method( 'get_cached_account_data' )
			->willReturn( array( 'country' => 'US' ) );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();

		$gateway = new NativeWooPaymentsGateway();

		$this->assertSame( 'Card', $gateway->get_title() );
		$this->assertSame( 'Card', $gateway->get_title() );
	}

	/**
	 * @testdox Should lazily memoize account-country branding when the admin title is requested.
	 */
	public function test_get_method_title_lazily_memoizes_account_country_branding(): void {
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->once() )
			->method( 'get_cached_account_data' )
			->willReturn( array( 'country' => 'US' ) );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		wc_get_container()->reset_all_resolved();

		$gateway = new NativeWooPaymentsGateway( $registry->get( 'afterpay_clearpay' ) );

		$this->assertSame( 'WooPayments (Cash App Afterpay)', $gateway->get_method_title() );
		$this->assertSame( 'WooPayments (Cash App Afterpay)', $gateway->get_method_title() );
	}

	/**
	 * @testdox Should reload settings once when the cached blog context is stale.
	 */
	public function test_site_derived_state_reloads_once_when_the_cached_blog_context_is_stale(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'     => 'yes',
				'saved_cards' => 'yes',
			)
		);
		$gateway = new NativeWooPaymentsGateway();
		$this->assertSame( 'yes', $gateway->get_option( 'saved_cards' ) );

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'     => 'no',
				'saved_cards' => 'no',
			)
		);
		$blog_id = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'settings_blog_id' );
		$blog_id->setAccessible( true );
		$blog_id->setValue( $gateway, get_current_blog_id() + 1 );

		$settings_reads = 0;
		$count_read     = static function ( $value ) use ( &$settings_reads ) {
			++$settings_reads;
			return $value;
		};
		add_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_read );

		try {
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'no', $gateway->get_option( 'saved_cards' ) );
			$this->assertSame( 'no', $gateway->enabled );
			$this->assertSame( 1, $settings_reads );
		} finally {
			remove_filter( 'pre_option_woocommerce_woocommerce_payments_settings', $count_read );
		}
	}

	/**
	 * @testdox Checkout provider data should carry the WooPay save-user opt-in and fire the save action.
	 */
	public function test_checkout_provider_data_carries_woopay_save_user_opt_in(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$sut      = new NativeWooPaymentsGateway( $registry->get( 'card' ) );

		$action_fired = 0;
		add_action(
			'woocommerce_payments_save_user_in_woopay',
			function () use ( &$action_fired ): void {
				++$action_fired;
			}
		);

		$method = new \ReflectionMethod( NativeWooPaymentsGateway::class, 'get_checkout_provider_data' );
		$method->setAccessible( true );

		try {
			$_POST['save_user_in_woopay'] = 'true';
			$provider_data                = $method->invoke( $sut );

			$this->assertTrue( $provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );

			unset( $_POST['save_user_in_woopay'] );
			$bare_provider_data = $method->invoke( $sut );

			$this->assertFalse( $bare_provider_data['save_payment_method_to_platform'] );
			$this->assertSame( 1, $action_fired );
		} finally {
			unset( $_POST['save_user_in_woopay'] );
			remove_all_actions( 'woocommerce_payments_save_user_in_woopay' );
		}
	}

	/**
	 * @testdox Should use the custom place-order button contract for payment-list wallets.
	 */
	public function test_payment_list_wallets_use_custom_place_order_button_without_ordinary_fields(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();

		foreach ( array( 'apple_pay', 'google_pay' ) as $payment_method_id ) {
			$sut = new NativeWooPaymentsGateway( $registry->get( $payment_method_id ) );

			$this->assertTrue( $sut->has_custom_place_order_button, "{$payment_method_id} should register a custom place-order button." );
			$this->assertFalse( $sut->has_fields, "{$payment_method_id} should not render ordinary payment fields." );
		}
	}

	/**
	 * @testdox Should retain ordinary fields for non-express payment methods.
	 */
	public function test_non_express_payment_methods_retain_ordinary_fields(): void {
		$registry = new WooPaymentsPaymentMethodRegistry();
		$sut      = new NativeWooPaymentsGateway( $registry->get( 'card' ) );

		$this->assertFalse( $sut->has_custom_place_order_button );
		$this->assertTrue( $sut->has_fields );
	}

	/**
	 * @testdox Should refresh subscription capabilities after third-party plugins finish loading.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_init_refreshes_late_loaded_subscription_capabilities(): void {
		$gateway = new NativeWooPaymentsGateway();

		$this->assertFalse( $gateway->supports( 'subscriptions' ), 'The fixture must construct the gateway before Subscriptions is available.' );

		require_once __DIR__ . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( Fixtures\LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$gateway->handle_init();
		$gateway->handle_init();

		$expected_features = array(
			'subscriptions',
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
		);

		foreach ( $expected_features as $feature ) {
			$this->assertTrue( $gateway->supports( $feature ), "The late-loaded gateway should support {$feature}." );
			$this->assertSame( 1, count( array_keys( $gateway->supports, $feature, true ) ), "The {$feature} capability should remain idempotent." );
		}
	}

	/**
	 * @testdox Should render country-aware definition branding for a classic split gateway.
	 */
	public function test_classic_split_gateway_icon_uses_country_aware_definition_branding(): void {
		$registry        = new WooPaymentsPaymentMethodRegistry();
		$sut             = new NativeWooPaymentsGateway( $registry->get( 'afterpay_clearpay' ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( false );

		$property = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'account_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $account_service );

		$filter_calls = 0;
		$filter       = static function ( string $icon, string $gateway_id ) use ( &$filter_calls ): string {
			++$filter_calls;
			return $icon . '<span data-gateway-id="' . esc_attr( $gateway_id ) . '"></span>';
		};
		add_filter( 'woocommerce_gateway_icon', $filter, 10, 2 );

		try {
			$icon = $sut->get_icon();
		} finally {
			remove_filter( 'woocommerce_gateway_icon', $filter, 10 );
		}

		$this->assertStringContainsString( '/assets/images/payment-methods/afterpay-cashapp-logo.svg', $icon );
		$this->assertStringContainsString( 'alt="Cash App Afterpay"', $icon );
		$this->assertStringNotContainsString( '/assets/images/payment-methods/visa.svg', $icon );
		$this->assertStringContainsString( 'data-gateway-id="woocommerce_payments_afterpay_clearpay"', $icon );
		$this->assertSame( 1, $filter_calls );
	}

	/**
	 * @testdox Should report a connected account only when account data is cached, like the client's is_connected().
	 */
	public function test_is_connected_follows_cached_account_data(): void {
		$this->assertFalse( $this->gateway_with_account( array() )->is_connected(), 'No cached account data means no connected account.' );
		$this->assertTrue( $this->gateway_with_account( $this->sandbox_account( false, false ) )->is_connected(), 'Cached account data means a connected account.' );
	}

	/**
	 * @testdox Should report a partially onboarded account while a connected account has not submitted its details.
	 */
	public function test_is_account_partially_onboarded_matches_client(): void {
		$this->assertTrue(
			$this->gateway_with_account( $this->sandbox_account( false, false ) )->is_account_partially_onboarded(),
			'A connected account without submitted details is partially onboarded.'
		);
		$this->assertFalse(
			$this->gateway_with_account( $this->sandbox_account( true, true ) )->is_account_partially_onboarded(),
			'A connected account with submitted details is fully onboarded.'
		);
		$this->assertFalse(
			$this->gateway_with_account( array() )->is_account_partially_onboarded(),
			'Without an account there is no partial onboarding.'
		);
	}

	/**
	 * @testdox Should need setup without an account, with incomplete account status, or while payments are disabled.
	 */
	public function test_needs_setup_matches_client(): void {
		$without_status = $this->sandbox_account( true, true );
		unset( $without_status['status'] );

		$this->assertTrue( $this->gateway_with_account( array() )->needs_setup(), 'No account needs setup.' );
		$this->assertTrue( $this->gateway_with_account( $this->sandbox_account( false, false ) )->needs_setup(), 'Disabled payments need setup.' );
		$this->assertTrue( $this->gateway_with_account( $without_status )->needs_setup(), 'Account data without a status needs setup.' );
		$this->assertFalse( $this->gateway_with_account( $this->sandbox_account( true, true ) )->needs_setup(), 'An account with payments enabled does not need setup.' );
	}

	/**
	 * Build a card gateway that reads the given cached account data.
	 *
	 * @param array $account_data Cached account data.
	 * @return NativeWooPaymentsGateway
	 */
	private function gateway_with_account( array $account_data ): NativeWooPaymentsGateway {
		$sut             = new NativeWooPaymentsGateway();
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );

		$property = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'account_service' );
		$property->setAccessible( true );
		$property->setValue( $sut, $account_service );

		return $sut;
	}

	/**
	 * Build cached data for a sandbox account.
	 *
	 * @param bool $details_submitted Whether the account details were submitted.
	 * @param bool $payments_enabled  Whether the account can accept payments.
	 * @return array
	 */
	private function sandbox_account( bool $details_submitted, bool $payments_enabled ): array {
		return array(
			'account_id'        => 'acct_test123',
			'status'            => $payments_enabled ? 'complete' : 'restricted',
			'is_live'           => false,
			'is_test_drive'     => false,
			'details_submitted' => $details_submitted,
			'payments_enabled'  => $payments_enabled,
		);
	}
}
