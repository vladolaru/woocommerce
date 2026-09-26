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
	 * Matches WCPay\Internal\Service\OrderService::get_payment_metadata() in WooPayments 11.1.0.
	 *
	 * @testdox Intent metadata preserves WooPayments 11.1 customer-name delimiter spaces for incomplete billing names.
	 *
	 * @dataProvider incomplete_billing_names_provider
	 *
	 * @param string $first_name First billing name.
	 * @param string $last_name Last billing name.
	 * @param string $expected_customer_name Expected WooPayments 11.1 metadata value.
	 */
	public function test_metadata_preserves_woopayments_customer_name_delimiter_spaces_for_incomplete_billing_names( string $first_name, string $last_name, string $expected_customer_name ): void {
		$order = wc_create_order();
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->save();

		$metadata = WooPaymentsIntentRequestBuilder::metadata_from_order( $order );

		$this->assertSame( $expected_customer_name, $metadata['customer_name'] );
	}

	/**
	 * Provide incomplete billing names and the literal WooPayments 11.1 metadata values.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function incomplete_billing_names_provider(): array {
		return array(
			'first name only'  => array( 'Ada', '', 'Ada ' ),
			'last name only'   => array( '', 'Lovelace', ' Lovelace' ),
			'both names empty' => array( '', '', ' ' ),
		);
	}

	/**
	 * @testdox Store API single-payment metadata keeps the WooPayments 11.1 order-derived request shape.
	 */
	public function test_store_api_single_payment_metadata_matches_11_1_order_shape(): void {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Wire' );
		$order->set_billing_last_name( 'Probe' );
		$order->set_billing_email( 'wire-probe@example.com' );
		$order->set_created_via( 'store-api' );
		$order->save();

		$metadata                 = WooPaymentsIntentRequestBuilder::metadata_from_order( $order );
		$metadata['payment_type'] = (string) $metadata['payment_type'];

		$this->assertSame(
			array(
				'customer_name'        => 'Wire Probe',
				'customer_email'       => 'wire-probe@example.com',
				'site_url'             => esc_url( get_site_url() ),
				'order_id'             => $order->get_id(),
				'order_number'         => $order->get_order_number(),
				'order_key'            => $order->get_order_key(),
				'payment_type'         => 'single',
				'checkout_type'        => 'store-api',
				'client_version'       => WooPaymentsClientVersion::VERSION,
				'subscription_payment' => 'no',
			),
			$metadata
		);
	}

	/**
	 * @testdox Redirect and mandate runtime values match the WooPayments 11.1 request shape.
	 */
	public function test_redirect_and_mandate_runtime_values_match_11_1_request_shape(): void {
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
						'user_agent' => 'WooCommerce Payments/11.1.0; ' . get_bloginfo( 'url' ),
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

		// A saved-token payment must not re-save to the platform even when a stale
		// session opt-in is present — the plugin derives the flag as
		// ! is_using_saved_payment_method() && opt-in.
		$token_request = $request_builder->charge_request_data(
			PaymentContext::for_checkout( $order, OrderPaymentStore::GATEWAY_ID, 'pm_saved', array( 'payment_token' => '4242' ), $provider_data ),
			'pm_saved',
			'cus_native',
			false
		);
		$this->assertArrayNotHasKey( 'save_payment_method_to_platform', $token_request );
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
	 * @testdox Charge request amount is the order total after a shipping method switch, not a stale line total.
	 */
	public function test_charge_request_amount_is_the_order_total_after_a_shipping_method_switch(): void {
		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '10.00' ) );
		$order   = wc_create_order();
		$order->set_currency( 'USD' );
		$order->add_product( $product, 1 );

		$express_shipping = new \WC_Order_Item_Shipping();
		$express_shipping->set_method_title( 'Express' );
		$express_shipping->set_total( '40.00' );
		$order->add_item( $express_shipping );
		$order->calculate_totals( false );
		$order->save();

		$order->remove_item( $express_shipping->get_id() );

		$standard_shipping = new \WC_Order_Item_Shipping();
		$standard_shipping->set_method_title( 'Standard' );
		$standard_shipping->set_total( '20.00' );
		$order->add_item( $standard_shipping );
		$order->calculate_totals( false );
		$order->save();

		$request = $this->build_charge_request_for_fingerprint( $order, array() );

		$this->assertSame( 3000, $request['amount'], 'The charge amount must be the order total (product + the currently selected shipping method) at submit time.' );
		$this->assertSame( 'usd', $request['currency'] );
	}

	/**
	 * @testdox Charge request amount rounds the tax-inclusive, coupon-discounted order total to minor units, not a partial or truncated figure.
	 */
	public function test_charge_request_amount_reflects_tax_and_a_fixed_coupon_in_minor_units(): void {
		$original_calc_taxes = get_option( 'woocommerce_calc_taxes' );
		update_option( 'woocommerce_calc_taxes', 'yes' );

		$tax_rate_id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_shipping' => '0',
			)
		);

		$product = null;
		$order   = null;

		try {
			$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '10.00' ) );
			$order   = wc_create_order();
			$order->set_currency( 'USD' );
			$order->add_product( $product, 1 );
			$order->save();

			$coupon = \WC_Helper_Coupon::create_coupon(
				'sc13_fixed_coupon',
				array(
					'discount_type' => 'fixed_cart',
					'coupon_amount' => '3.00',
				)
			);
			$this->assertTrue( true === $order->apply_coupon( $coupon ), 'The fixed-cart coupon must apply, or the rest of this test is vacuous.' );

			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_title( 'Standard' );
			$shipping->set_total( '9.95' );
			$order->add_item( $shipping );
			$order->calculate_totals( true );
			$order->save();

			$request = $this->build_charge_request_for_fingerprint( $order, array() );

			// By hand, not read back from the order or the builder: the 10.00 line discounted
			// by the 3.00 fixed-cart coupon is a 7.00 line total; 10% tax on that discounted
			// total is 0.70 (the tax rate excludes shipping); shipping itself is untaxed at
			// 9.95. 7.00 + 0.70 + 9.95 = 17.65, and the WooPayments currency helper rounds
			// (rather than truncates) 17.65 * 100 to 1765 cents.
			$this->assertSame( 1765, $request['amount'] );
			$this->assertSame( 'usd', $request['currency'] );
		} finally {
			\WC_Tax::_delete_tax_rate( $tax_rate_id );
			update_option( 'woocommerce_calc_taxes', $original_calc_taxes );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
			if ( $product instanceof \WC_Product ) {
				$product->delete( true );
			}
		}
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
