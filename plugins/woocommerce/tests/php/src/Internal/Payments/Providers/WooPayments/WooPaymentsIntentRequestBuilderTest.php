<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
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
	}
}
