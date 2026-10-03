<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingPaymentProcessingService;
use WC_Order;
use WC_Payment_Token;
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
		unset(
			$_POST['_wcsnonce'],
			$_POST['woocommerce_change_payment'],
			$_POST['wc-woocommerce_payments-payment-token'],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
			$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ]
		);
		$this->reset_container_replacements();
		wc_get_container()->reset_all_resolved();

		$renewal_hooks = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'attached' );
		$renewal_hooks->setAccessible( true );
		$renewal_hooks->setValue( null, false );
		$fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$fallback_hooks->setAccessible( true );
		$fallback_hooks->setValue( null, false );
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
	 * @testdox The subscription supports follow the Stripe Billing toggle: on adds gateway-scheduled payments, off keeps amount and date changes (client test_maybe_init_subscriptions, test_maybe_init_subscriptions_with_stripe_billing_enabled).
	 * @dataProvider stripe_billing_subscription_supports
	 *
	 * @param string   $toggle   Stripe Billing toggle option value.
	 * @param string[] $expected Subscription supports, in the client's order.
	 */
	public function test_subscription_supports_follow_the_stripe_billing_toggle( string $toggle, array $expected ): void {
		$this->load_stripe_billing_module( $toggle );

		$gateway = new NativeWooPaymentsGateway();

		$this->assertSame( $expected, $this->get_subscription_supports( $gateway ) );
	}

	/**
	 * @testdox A gateway built before the Stripe Billing module loads gets the toggle-on supports once init runs.
	 */
	public function test_init_replaces_supports_settled_before_the_stripe_billing_module_loaded(): void {
		$this->report_subscriptions_loaded();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, '1' );
		$gateway = new NativeWooPaymentsGateway();
		$this->assertContains( 'subscription_amount_changes', $gateway->supports, 'The fixture must build the gateway before the module loads.' );

		$this->load_stripe_billing_module( '1' );
		$gateway->handle_init();

		$this->assertSame( self::stripe_billing_subscription_supports()['toggle on'][1], $this->get_subscription_supports( $gateway ) );
	}

	/**
	 * @testdox A renewal of a Stripe-billed subscription is not charged here, toggle on or off; a tokenized subscription's renewal still is (client `trait-wc-payment-gateway-wcpay-subscriptions.php:405-407`, `:1243-1258`).
	 * @testWith ["0", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false]
	 *           ["1", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false]
	 *           ["1", "", true]
	 *
	 * @param string $toggle                Stripe Billing toggle option value.
	 * @param string $wcpay_subscription_id Stripe subscription ID of the subscription, empty when it is tokenized.
	 * @param bool   $expect_charge         Whether the renewal is charged.
	 */
	public function test_renewals_of_stripe_billed_subscriptions_are_not_charged( string $toggle, string $wcpay_subscription_id, bool $expect_charge ): void {
		$this->load_stripe_billing_module( $toggle );
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, $wcpay_subscription_id );
		$renewal      = \WC_Helper_Order::create_order( $user_id );
		$renewal->add_payment_token( $this->create_card_token( $user_id ) );
		$renewal->set_status( 'pending' );
		$renewal->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $renewal->get_id() ]['renewal'][] = $subscription->get_id();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
		$service    = new RecordingPaymentProcessingService();
		$gateway    = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, new WooPaymentsTokenService() );

		$gateway->scheduled_subscription_payment( 10.0, wc_get_order( $renewal->get_id() ) );

		if ( $expect_charge ) {
			$this->assertSame( $renewal->get_id(), $service->last_checkout_context ? $service->last_checkout_context->get_order_id() : 0, 'A tokenized renewal is charged.' );
			return;
		}

		$this->assertSame( 0, $service->checkout_attempt_count, 'A Stripe-billed renewal must not be charged.' );
		$saved = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'pending', $saved->get_status() );
		$this->assertCount( $note_count, wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) );
	}

	/**
	 * @testdox A successful change to a saved payment method notes the new method on the subscription; a failed one notes nothing (client `class-wc-payment-gateway-wcpay.php:1720-1745`).
	 * @testWith ["card", "completed", "Payment method is changed to: <strong>Credit card ending in 4242</strong>."]
	 *           ["link", "completed", "Payment method is changed to: <strong>Link ending in ***pper@example.com</strong>."]
	 *           ["card", "failed", ""]
	 *
	 * @param string $token_type     Saved payment method type: `card` or `link`.
	 * @param string $outcome_status Outcome of the change request.
	 * @param string $expected_note  Note expected on the subscription, empty for none.
	 */
	public function test_a_saved_method_change_notes_the_new_payment_method( string $token_type, string $outcome_status, string $expected_note ): void {
		WooCommerceSubscriptionsDoubles::load();
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, '' );
		$token        = 'link' === $token_type ? $this->create_link_token( $user_id ) : $this->create_card_token( $user_id );
		$note_count   = count( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );

		$this->process_saved_method_change( $subscription, $token, $outcome_status );

		$new_notes = array_slice( array_map( static fn( $note ) => $note->content, array_reverse( wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) ) ), $note_count );
		$this->assertSame( '' === $expected_note ? array() : array( $expected_note ), $new_notes );
	}

	/**
	 * @testdox A successful change to a saved payment method fires the client hook once with the subscription and the new token; a failed one fires nothing (client `class-wc-payment-gateway-wcpay.php:1747-1757`).
	 * @testWith ["completed", 1]
	 *           ["failed", 0]
	 *
	 * @param string $outcome_status Outcome of the change request.
	 * @param int    $expected_calls How often the hook fires.
	 */
	public function test_a_saved_method_change_fires_the_changed_payment_method_hook( string $outcome_status, int $expected_calls ): void {
		WooCommerceSubscriptionsDoubles::load();
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, '' );
		$token        = $this->create_card_token( $user_id );
		$calls        = array();
		add_action(
			'woocommerce_payments_changed_subscription_payment_method',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			5
		);

		$this->process_saved_method_change( $subscription, $token, $outcome_status );

		$this->assertCount( $expected_calls, $calls );
		if ( 0 === $expected_calls ) {
			return;
		}
		$this->assertCount( 2, $calls[0], 'The hook passes the subscription and the token, as the client does.' );
		$this->assertInstanceOf( WC_Order::class, $calls[0][0] );
		$this->assertSame( $subscription->get_id(), $calls[0][0]->get_id() );
		$this->assertInstanceOf( WC_Payment_Token::class, $calls[0][1] );
		$this->assertSame( $token->get_id(), $calls[0][1]->get_id() );
	}

	/**
	 * @testdox After a Stripe-billed subscription is switched to a saved card, the Stripe Billing module charges its pending invoice and completes the failed renewal (client `class-wc-payments-subscription-service.php:658-694`).
	 */
	public function test_a_saved_method_change_charges_the_pending_stripe_billing_invoice(): void {
		$this->load_stripe_billing_module( '0' );
		$http_client  = $this->use_recorded_platform( 'charge_invoice' );
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription( $user_id, 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );
		$subscription->update_meta_data( '_wcpay_pending_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$subscription->save();
		$renewal = \WC_Helper_Order::create_order( $user_id );
		$renewal->update_meta_data( '_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$renewal->set_status( 'failed' );
		$renewal->save();
		$token = $this->create_card_token( $user_id );

		$this->process_saved_method_change( $subscription, $token, 'completed' );

		$this->assertSame( array( 'POST /sites/4/wcpay/invoices/in_1UM1VrBzWlxcwgpPgrIwNSlu/pay' ), array_map( static fn( array $request ) => $request['method'] . ' ' . $request['path'], $http_client->requests ) );
		$this->assertSame( '', wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_pending_invoice_id', true ) );
		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertTrue( $renewal->is_paid(), 'The failed renewal is completed once Stripe collected the invoice.' );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $renewal->get_payment_tokens() ) );
	}

	/**
	 * Subscription supports per toggle value, from client 11.1.0 `tests/unit/test-class-wc-payment-gateway-wcpay-subscriptions-trait.php:63-110`.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public static function stripe_billing_subscription_supports(): array {
		$base = array(
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_payment_method_change_admin',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change',
			'subscription_reactivation',
			'subscription_suspension',
			'subscriptions',
		);

		return array(
			'toggle off' => array( '0', array_merge( $base, array( 'subscription_amount_changes', 'subscription_date_changes' ) ) ),
			'toggle on'  => array( '1', array_merge( $base, array( 'gateway_scheduled_payments' ) ) ),
		);
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
		$this->assertStringNotContainsString( '/assets/images/payment-methods/visa-color.svg', $icon );
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

	/**
	 * Report WooCommerce Subscriptions as loaded to the Stripe Billing module and the gateway's subscription supports, which
	 * ask LegacyProxy. The mock is reset after every test.
	 */
	private function report_subscriptions_loaded(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, array( 'WC_Subscriptions', 'WC_Subscriptions_Core_Plugin' ), true ) || class_exists( $class_name, ...$args ),
			)
		);
	}

	/**
	 * Load WooCommerce Subscriptions and the Stripe Billing module with the given toggle, while native owns payments.
	 *
	 * @param string $toggle Stripe Billing toggle option value.
	 * @return WooPaymentsStripeBillingModule
	 */
	private function load_stripe_billing_module( string $toggle ): WooPaymentsStripeBillingModule {
		$this->report_subscriptions_loaded();
		WooCommerceSubscriptionsDoubles::load();
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, $toggle );

		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$module = new WooPaymentsStripeBillingModule();
		$module->init( $arbiter );
		$module->register();
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		return $module;
	}

	/**
	 * Get the gateway's subscription supports, in the gateway's order.
	 *
	 * @param NativeWooPaymentsGateway $gateway Gateway.
	 * @return string[]
	 */
	private function get_subscription_supports( NativeWooPaymentsGateway $gateway ): array {
		$all = array_merge( self::stripe_billing_subscription_supports()['toggle off'][1], array( 'gateway_scheduled_payments' ) );

		return array_values( array_intersect( $gateway->supports, $all ) );
	}

	/**
	 * Create a WooPayments subscription for a customer, billed by Stripe Billing when it has a Stripe subscription ID.
	 *
	 * @param int    $user_id               Customer user ID.
	 * @param string $wcpay_subscription_id Stripe subscription ID, empty for a tokenized subscription.
	 * @return SubscriptionDouble
	 */
	private function create_subscription( int $user_id, string $wcpay_subscription_id ): SubscriptionDouble {
		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_customer_id( $user_id );
		if ( '' !== $wcpay_subscription_id ) {
			$subscription->update_meta_data( '_wcpay_subscription_id', $wcpay_subscription_id );
		}
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		return $subscription;
	}

	/**
	 * Create a saved card for a customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return \WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2034' );
		$token->save();

		return $token;
	}

	/**
	 * Create a saved Link payment method for a customer, with Link enabled on the store.
	 *
	 * @param int $user_id Customer user ID.
	 * @return WooPaymentsLinkToken
	 */
	private function create_link_token( int $user_id ): WooPaymentsLinkToken {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'link' ) ) );
		add_filter( 'woocommerce_payment_token_class', array( new WooPaymentsTokenClassMapController(), 'handle_woocommerce_payment_token_class' ), 10, 2 );

		$token = new WooPaymentsLinkToken();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPLinkT63' );
		$token->set_email( 'shopper@example.com' );
		$token->save();

		return $token;
	}

	/**
	 * Send a customer's request to switch a subscription to a saved payment method through the gateway.
	 *
	 * @param WC_Order         $subscription   Subscription.
	 * @param WC_Payment_Token $token          Saved payment method picked.
	 * @param string           $outcome_status Outcome the payment processing answers with.
	 */
	private function process_saved_method_change( WC_Order $subscription, WC_Payment_Token $token, string $outcome_status ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		wp_set_current_user( $subscription->get_customer_id() );
		$_POST['_wcsnonce']                             = wp_create_nonce( 'wcs_change_payment_method' );
		$_POST['woocommerce_change_payment']            = (string) $subscription->get_id();
		$_POST['wc-woocommerce_payments-payment-token'] = (string) $token->get_id();

		$service                   = new RecordingPaymentProcessingService();
		$service->checkout_outcome = new PaymentOutcome( $outcome_status, 'seti_1UM1VrBzWlxcwgpPChgT63' );
		$gateway                   = new NativeWooPaymentsGateway();
		$gateway->init( $service, new WooPaymentsProvider(), null, null, null, new WooPaymentsTokenService() );

		$gateway->process_payment( $subscription->get_id() );
	}

	/**
	 * Answer the Stripe Billing module's platform requests with a recorded response, on the account and blog it was recorded on.
	 *
	 * @param string $pair Entry name in `Fixtures/rec-t63-billing-api.json`.
	 * @return FakeWooPaymentsHttpClient
	 */
	private function use_recorded_platform( string $pair ): FakeWooPaymentsHttpClient {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/rec-t63-billing-api.json' ), true );
		$entries   = array_values( array_filter( array_merge( $recording['entries'], $recording['supporting_entries'] ?? array() ), static fn( array $entry ) => $pair === $entry['pair'] ) );
		$this->assertNotEmpty( $entries, "Fixture entry $pair is missing." );

		$http_client              = new FakeWooPaymentsHttpClient();
		$http_client->blog_id     = 4;
		$http_client->responses[] = array(
			'response' => array( 'code' => (int) $entries[0]['response']['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $entries[0]['response']['body'] ),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( 'acct_1TrY2nBzWlxcwgpP' );
		$account_service->method( 'get_gateway_setting' )->willReturn( '' );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		wc_get_container()->replace( StripeBillingApi::class, $api );

		return $http_client;
	}
}
