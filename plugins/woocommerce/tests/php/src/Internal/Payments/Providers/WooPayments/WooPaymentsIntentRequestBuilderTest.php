<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentRequestBuilder class.
 */
class WooPaymentsIntentRequestBuilderTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_metadata_from_order' );
		parent::tearDown();
	}

	/**
	 * @testdox Metadata construction preserves the legacy payment type and three-argument filter shape.
	 */
	public function test_metadata_hook_preserves_legacy_payment_type_and_three_argument_shape(): void {
		$order           = wc_create_order();
		$captured_args   = array();
		$metadata_filter = static function ( array $metadata, WC_Order $filtered_order, $payment_type ) use ( &$captured_args ): array {
			$captured_args = array( $metadata, $filtered_order, $payment_type );

			return $metadata;
		};

		add_filter( 'wcpay_metadata_from_order', $metadata_filter, 10, 3 );

		$metadata = WooPaymentsIntentRequestBuilder::metadata_from_order( $order, 'recurring', 'renewal' );

		$this->assertCount( 3, $captured_args, 'The compatibility filter should receive three arguments.' );
		$this->assertSame( $order, $captured_args[1] );
		$this->assertInstanceOf( WooPaymentsPaymentType::class, $captured_args[2] );
		$this->assertTrue( is_a( $captured_args[2], 'WCPay\\Constants\\Payment_Type' ), 'Payment type should satisfy the legacy WooPayments class name.' );
		$this->assertSame( 'recurring', (string) $captured_args[2] );
		$this->assertSame( $captured_args[2], $metadata['payment_type'] );
	}

	/**
	 * @testdox Intent metadata reports the WooPayments-compatible client capability version.
	 */
	public function test_metadata_client_version_matches_woopayments_transport_capability(): void {
		$order    = wc_create_order();
		$metadata = WooPaymentsIntentRequestBuilder::metadata_from_order( $order );

		$this->assertSame( WooPaymentsClientVersion::VERSION, $metadata['client_version'] );
	}

	/**
	 * @testdox Redirect and mandate runtime values match the WooPayments 10.8 request shape.
	 */
	public function test_redirect_and_mandate_runtime_values_match_10_8_request_shape(): void {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '25.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit', 'pm_sepa' ),
			'pm_sepa',
			'cus_native',
			false
		);

		$this->assertSame( array( 'sepa_debit' ), $request['payment_method_types'] );
		$this->assertSame(
			array(
				'customer_acceptance' => array(
					'type'   => 'online',
					'online' => array(
						'ip_address' => \WC_Geolocation::get_ip_address(),
						'user_agent' => 'WooCommerce Payments/10.8.0; ' . get_bloginfo( 'url' ),
					),
				),
			),
			$request['mandate_data']
		);
		$this->assertStringStartsWith( $order->get_checkout_order_received_url(), $request['return_url'] );

		$query_args = array();
		parse_str( (string) wp_parse_url( (string) $request['return_url'], PHP_URL_QUERY ), $query_args );

		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $query_args['wc_payment_method'] ?? '' );
		$this->assertSame( 1, wp_verify_nonce( $query_args['_wpnonce'] ?? '', 'wcpay_process_redirect_order_nonce' ) );
		$this->assertArrayNotHasKey( 'save_payment_method', $query_args );
	}

	/**
	 * @testdox Redirect return URL preserves an explicit payment-method save request.
	 */
	public function test_redirect_return_url_preserves_explicit_save_request(): void {
		$request = $this->build_redirect_request( array( 'save_payment_method' => true ), false );

		$query_args = array();
		parse_str( (string) wp_parse_url( (string) $request['return_url'], PHP_URL_QUERY ), $query_args );

		$this->assertSame( 'yes', $query_args['save_payment_method'] ?? '' );
	}

	/**
	 * @testdox Redirect return URL preserves recurring payment-method save semantics.
	 */
	public function test_redirect_return_url_preserves_recurring_save_semantics(): void {
		$request = $this->build_redirect_request( array(), true );

		$query_args = array();
		parse_str( (string) wp_parse_url( (string) $request['return_url'], PHP_URL_QUERY ), $query_args );

		$this->assertSame( 'yes', $query_args['save_payment_method'] ?? '' );
	}

	/**
	 * Build a redirect-capable charge request.
	 *
	 * @param array<string,mixed> $payment_data Payment context data.
	 * @param bool                $is_recurring Whether recurring semantics are required.
	 * @return array<string,mixed>
	 */
	private function build_redirect_request( array $payment_data, bool $is_recurring ): array {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '25.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		return $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit',
				'pm_sepa',
				$payment_data
			),
			'pm_sepa',
			'cus_native',
			$is_recurring
		);
	}

	/**
	 * @testdox Charge requests attach the buyer-fingerprinting risk metadata the platform scores on.
	 */
	public function test_charge_request_attaches_buyer_fingerprint_metadata(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		$request = $this->build_charge_request_for_fingerprint(
			$order,
			array( 'fingerprint' => 'device_fp_123' )
		);

		$metadata = $request['metadata'];
		$this->assertSame(
			hash( 'sha512', \WC_Geolocation::get_ip_address() ),
			$metadata['fraud_prevention_data_shopper_ip_hash']
		);
		$this->assertSame( 'device_fp_123', $metadata['fraud_prevention_data_shopper_ua_hash'] );
		$this->assertTrue( $metadata['fraud_prevention_data_available'] );
		$this->assertSame( 0, $metadata['fraud_prevention_data_cart_contents'] );
	}

	/**
	 * @testdox Charge requests omit the device-fingerprint hash when the browser supplied none, but stay flagged available.
	 */
	public function test_charge_request_omits_ua_hash_without_fingerprint(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		$request = $this->build_charge_request_for_fingerprint( $order, array() );

		$metadata = $request['metadata'];
		$this->assertArrayNotHasKey( 'fraud_prevention_data_shopper_ua_hash', $metadata );
		$this->assertArrayHasKey( 'fraud_prevention_data_shopper_ip_hash', $metadata );
		$this->assertTrue( $metadata['fraud_prevention_data_available'] );
	}

	/**
	 * @testdox Charge requests carry Level 3 data for US accounts and omit it when the service returns none.
	 */
	public function test_charge_request_attaches_level3_data_for_us_accounts(): void {
		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '12.00' ) );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$level3_service = new WooPaymentsLevel3Service();
		$level3_service->init( $account_service, new WooPaymentsOrderDataService() );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry(),
			$level3_service
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card' ),
			'pm_card',
			'cus_native',
			false
		);

		$this->assertArrayHasKey( 'level3', $request );
		$this->assertSame( (string) $order->get_id(), $request['level3']['merchant_reference'] );
		$this->assertSame( 1200, $request['level3']['line_items'][0]->unit_cost );

		$domain = str_replace( array( 'https://', 'http://' ), '', get_site_url() );
		$this->assertStringStartsWith(
			sprintf( 'Online Payment for Order #%s for %s', $order->get_order_number(), $domain ),
			$request['description']
		);
	}

	/**
	 * @testdox setup_future_usage is requested only for reusable payment method types.
	 */
	public function test_setup_future_usage_respects_payment_method_reusability(): void {
		$reusable_request = $this->build_save_requested_charge( OrderPaymentStore::GATEWAY_ID_PREFIX . 'card', 'pm_card' );
		$this->assertSame( 'off_session', $reusable_request['setup_future_usage'] ?? null );

		$bnpl_request = $this->build_save_requested_charge( OrderPaymentStore::GATEWAY_ID_PREFIX . 'klarna', 'pm_klarna' );
		$this->assertArrayNotHasKey( 'setup_future_usage', $bnpl_request );
	}

	/**
	 * @testdox New card payments declare link in payment_method_types whenever the account folds Link into card.
	 */
	public function test_card_payment_method_types_include_link_when_folded(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting', 'get_cached_account_data', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				return 'upe_enabled_payment_method_ids' === $key ? array( 'card', 'link' ) : $fallback;
			}
		);
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'capabilities' => array( 'link_payments' => 'active' ),
				'fees'         => array( 'link' => array() ),
			)
		);
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card' ),
			'pm_card',
			'cus_native',
			false
		);

		$this->assertSame( array( 'card', 'link' ), $request['payment_method_types'] );
		// Link in the types arms the online mandate for customer-present payments — oracle behavior.
		$this->assertArrayHasKey( 'mandate_data', $request );

		// The setup-intent path (free trials, zero-total subscriptions) must
		// pair the same mandate with link and carry the dashboard description.
		$setup_request = $request_builder->setup_intent_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card' ),
			'pm_card',
			'cus_native',
			true
		);
		$this->assertSame( array( 'card', 'link' ), $setup_request['payment_method_types'] );
		$this->assertArrayHasKey( 'mandate_data', $setup_request );
		$this->assertStringStartsWith( 'Online Payment for Order #', $setup_request['description'] );
	}

	/**
	 * @testdox save_payment_method_to_platform rides both the charge and setup-intent requests when the shopper opted in.
	 */
	public function test_save_payment_method_to_platform_rides_charge_and_setup_requests(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );
		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$provider_data = array( 'save_payment_method_to_platform' => true );

		$charge_request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card', array(), $provider_data ),
			'pm_card',
			'cus_native',
			false
		);
		$this->assertTrue( $charge_request['save_payment_method_to_platform'] );

		$setup_request = $request_builder->setup_intent_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card', array(), $provider_data ),
			'pm_card',
			'cus_native',
			false
		);
		$this->assertTrue( $setup_request['save_payment_method_to_platform'] );

		$bare_request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_card' ),
			'pm_card',
			'cus_native',
			false
		);
		$this->assertArrayNotHasKey( 'save_payment_method_to_platform', $bare_request );
	}

	/**
	 * @testdox Link-token renewals declare card and link with no return_url, even when the checkout fold is off.
	 */
	public function test_link_token_renewals_declare_card_and_link_without_return_url(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		// The fold is OFF (no link setting, capability, or fee): the token was valid
		// when saved and must keep renewing regardless of current checkout state.
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting', 'get_cached_account_data', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => $fallback
		);
		$account_service->method( 'get_cached_account_data' )->willReturn( array() );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_link',
				array(),
				array(
					'scheduled_subscription_payment' => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE => 'link',
				)
			),
			'pm_link',
			'cus_native',
			true
		);

		$this->assertSame( array( 'card', 'link' ), $request['payment_method_types'], 'A Link credential rides the card rails; a lone link type makes the platform refuse the charge.' );
		$this->assertArrayNotHasKey( 'return_url', $request, 'An off-session renewal has no shopper present to redirect.' );
	}

	/**
	 * @testdox Saved-card-token charges follow the card/link fold like fresh card payments.
	 */
	public function test_saved_card_token_charges_follow_the_card_link_fold(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting', 'get_cached_account_data', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				return 'upe_enabled_payment_method_ids' === $key ? array( 'card', 'link' ) : $fallback;
			}
		);
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'capabilities' => array( 'link_payments' => 'active' ),
				'fees'         => array( 'link' => array() ),
			)
		);
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_card',
				array(),
				array(
					'scheduled_subscription_payment' => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE => 'card',
				)
			),
			'pm_card',
			'cus_native',
			true
		);

		$this->assertSame( array( 'card', 'link' ), $request['payment_method_types'] );
		$this->assertArrayNotHasKey( 'return_url', $request );
	}

	/**
	 * @testdox Saved single-method redirect tokens keep their lone type and return_url.
	 */
	public function test_saved_sepa_token_keeps_single_type_and_return_url(): void {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '25.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$request = $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit',
				'pm_sepa',
				array(),
				array(
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE => 'sepa_debit',
				)
			),
			'pm_sepa',
			'cus_native',
			false
		);

		$this->assertSame( array( 'sepa_debit' ), $request['payment_method_types'] );
		$this->assertArrayHasKey( 'return_url', $request );
	}

	/**
	 * @testdox Merchant-initiated renewals never fabricate mandate acceptance; customer-present payments source the IP from the order.
	 */
	public function test_mandate_data_is_suppressed_for_renewals_and_sourced_from_order(): void {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '25.00' );
		$order->set_customer_ip_address( '203.0.113.7' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		$renewal_request = $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_link',
				array(),
				array(
					'scheduled_subscription_payment' => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE => 'link',
				)
			),
			'pm_link',
			'cus_native',
			true
		);
		$this->assertArrayNotHasKey( 'mandate_data', $renewal_request, 'Merchant-initiated renewals must not fabricate a mandate acceptance.' );

		$checkout_request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit', 'pm_sepa' ),
			'pm_sepa',
			'cus_native',
			false
		);
		$this->assertSame( '203.0.113.7', $checkout_request['mandate_data']['customer_acceptance']['online']['ip_address'] );
	}

	/**
	 * Build a charge request with a payment-method save requested.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param string $credential Payment credential.
	 * @return array<string,mixed>
	 */
	private function build_save_requested_charge( string $gateway_id, string $credential ): array {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$order->set_total( '25.00' );
		$order->save();

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		return $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, $gateway_id, $credential, array( 'save_payment_method' => true ) ),
			$credential,
			'cus_native',
			false
		);
	}

	/**
	 * Build a charge request with the given provider data.
	 *
	 * @param WC_Order            $order         Order being charged.
	 * @param array<string,mixed> $provider_data Provider data for the payment context.
	 * @return array<string,mixed>
	 */
	private function build_charge_request_for_fingerprint( WC_Order $order, array $provider_data ): array {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_gateway_setting' )->willReturn( 'no' );

		$request_builder = new WooPaymentsIntentRequestBuilder();
		$request_builder->init(
			$account_service,
			new WooPaymentsOrderDataService(),
			$this->createStub( WooPaymentsTokenService::class ),
			new WooPaymentsPaymentMethodRegistry()
		);

		return $request_builder->charge_request_data(
			PaymentContext::for_checkout(
				$order,
				OrderPaymentStore::GATEWAY_ID,
				'pm_card',
				array(),
				$provider_data
			),
			'pm_card',
			'cus_native',
			false
		);
	}
}
